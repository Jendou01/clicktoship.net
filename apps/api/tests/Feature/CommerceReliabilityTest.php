<?php

namespace Tests\Feature;

use App\Modules\Commerce\Application\Connections;
use App\Modules\Commerce\Application\Tokens;
use App\Modules\Commerce\Application\Work;
use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Infrastructure\GuardedHttp;
use App\Modules\Commerce\Jobs\ProcessStore;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommerceTestCase;

final class CommerceReliabilityTest extends CommerceTestCase
{
    use DatabaseMigrations;

    private function queueSync(array $f): object
    {
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'reliability-sync'])->assertStatus(202)->json('data.run_id');

        return DB::table('commerce_runs')->where('id', $id)->first();
    }

    public function test_refresh_rotates_once_never_exports_tokens_and_rejects_scope_identity_changes(): void
    {
        $f = $this->setupCommerce();
        $run = $this->queueSync($f);
        DB::table('commerce_credentials')->update(['expires_at' => now()->addSeconds(1)]);
        $v = DB::table('commerce_credentials')->value('version');
        app(Tokens::class)->credential($run);
        app(Tokens::class)->credential($run);
        $this->assertSame(1, $this->provider->refreshes);
        $this->assertSame($v + 1, DB::table('commerce_credentials')->value('version'));
        DB::table('commerce_credentials')->update(['expires_at' => now()->addSeconds(1)]);
        $this->provider->refreshResult = $this->provider->exchange('different-store', '');
        try {
            app(Tokens::class)->credential($run);
            $this->fail();
        } catch (IntegrationFailure $e) {
            $this->assertSame('REFRESH_REAUTHORIZATION_REQUIRED', $e->reason);
        }$this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'state' => 'reauthorization_required']);
        $this->assertNull(DB::table('commerce_credentials')->value('ciphertext'));
    }

    public function test_disconnect_during_refresh_cannot_resurrect_secrets(): void
    {
        $f = $this->setupCommerce();
        $run = $this->queueSync($f);
        DB::table('commerce_credentials')->update(['expires_at' => now()->addSeconds(1)]);
        $this->provider->onRefresh = function () use ($f) {
            $this->assertSame(0, DB::transactionLevel());
            DB::transaction(fn () => app(Connections::class)->disable(DB::table('commerce_connections')->where('id', $f['connection'])->first()));
        };
        try {
            app(Tokens::class)->credential($run);
            $this->fail();
        } catch (IntegrationFailure) {
        }$this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'state' => 'disconnected']);
        $this->assertNull(DB::table('commerce_credentials')->value('ciphertext'));
    }

    public function test_live_refresh_lease_rejects_competitors_and_expired_unknown_refresh_requires_reauth(): void
    {
        $f = $this->setupCommerce();
        $run = $this->queueSync($f);
        DB::table('commerce_credentials')->update(['expires_at' => now()->subSecond(), 'refresh_nonce' => (string) Str::uuid(), 'lease_until' => now()->addSeconds(30)]);
        try {
            app(Tokens::class)->credential($run);
            $this->fail();
        } catch (IntegrationFailure $e) {
            $this->assertSame('REFRESH_BUSY', $e->reason);
        }$this->assertSame(0, $this->provider->refreshes);
        DB::table('commerce_credentials')->update(['lease_until' => now()->subSecond()]);
        try {
            app(Tokens::class)->credential($run);
            $this->fail();
        } catch (IntegrationFailure $e) {
            $this->assertSame('REFRESH_REAUTHORIZATION_REQUIRED', $e->reason);
        }$this->assertSame(0, $this->provider->refreshes);
        $this->assertDatabaseHas('commerce_connections', ['state' => 'reauthorization_required']);
    }

    public function test_crash_rolls_back_entire_page_and_recovery_replays_it_once(): void
    {
        $f = $this->setupCommerce();
        $run = $this->queueSync($f);
        $this->provider->pages = ['first' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order()], 'next_cursor' => null, 'checkpoint' => 'done']];
        $crash = true;
        DB::listen(function ($q) use (&$crash) {
            if ($crash && str_starts_with($q->sql, 'insert into "commerce_pages"')) {
                $crash = false;
                throw new \RuntimeException('Injected crash after page insert before checkpoint');
            }
        });
        try {
            app(Work::class)->tick($f['m'], $run->id);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Injected crash', $e->getMessage());
        }$this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseCount('commerce_pages', 0);
        $this->assertNull(DB::table('commerce_connections')->value('checkpoint'));
        DB::table('commerce_runs')->where('id', $run->id)->update(['lease_until' => now()->subSecond()]);
        app(Work::class)->recover();
        Queue::assertPushed(ProcessStore::class);
        app(Work::class)->tick($f['m'], $run->id);
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('commerce_pages', 1);
        $this->assertDatabaseHas('commerce_runs', ['id' => $run->id, 'state' => 'succeeded']);
    }

    public static function revocations(): array
    {
        return [['role'], ['token'], ['merchant']];
    }

    #[DataProvider('revocations')]
    public function test_inflight_sync_after_role_token_or_merchant_revocation_discards_fetched_orders(string $kind): void
    {
        $f = $this->setupCommerce();
        $idempotency = 'race-'.$kind;
        if ($kind === 'token') {
            $t = $this->token($f['u'], $f['m'], ['integrations.sync']);
            $id = $this->bearer($t, 'POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => $idempotency])->assertStatus(202)->json('data.run_id');
        } else {
            $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => $idempotency])->assertStatus(202)->json('data.run_id');
        }
        $this->provider->pages = ['first' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order()], 'next_cursor' => null, 'checkpoint' => 'done']];
        $this->provider->onOrders = function () use ($kind, $f) {
            $this->assertSame(0, DB::transactionLevel());
            if ($kind === 'role') {
                DB::table('merchant_role_assignments')->where('merchant_id', $f['m'])->delete();
            }if ($kind === 'token') {
                $f['u']->tokens()->delete();
            }if ($kind === 'merchant') {
                DB::table('merchants')->where('id', $f['m'])->update(['status' => 'suspended']);
            }
        };
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'state' => 'blocked']);
        $this->assertSame(0, DB::table('commerce_orders')->where('merchant_id', $f['m'])->count());
        $this->assertNull(app(MerchantContext::class)->id());
    }

    public function test_pagination_cycles_and_explicit_resume_keep_bounds_and_rate_limit_delays(): void
    {
        $f = $this->setupCommerce();
        config(['commerce.max_pages' => 1]);
        $run = $this->queueSync($f);
        $this->provider->pages = ['first' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order()], 'next_cursor' => 'next', 'checkpoint' => null], 'next' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order('two')], 'next_cursor' => null, 'checkpoint' => 'done']];
        app(Work::class)->tick($f['m'], $run->id);
        $this->assertDatabaseHas('commerce_runs', ['state' => 'limited']);
        $this->browser('POST', "/api/v1/commerce/runs/$run->id/retry", ['expected_position' => 1, 'idempotency_key' => 'explicit-resume'])->assertOk();
        app(Work::class)->tick($f['m'], $run->id);
        $this->assertDatabaseCount('commerce_orders', 2);
        $this->assertDatabaseHas('commerce_runs', ['state' => 'succeeded']);
    }

    public function test_opaque_keyset_directory_is_bounded_and_private_platform_order_access_is_denied(): void
    {
        $f = $this->setupCommerce();
        $list = [];
        for ($i = 0; $i < 30; $i++) {
            $list[] = $this->order('order-'.$i);
        }$this->import($f, $list);
        $r = $this->browser('GET', '/api/v1/commerce/orders')->assertOk()->assertJsonCount(25, 'data.orders');
        $this->browser('GET', '/api/v1/commerce/orders?after='.$r->json('data.next_after'))->assertOk()->assertJsonCount(5, 'data.orders');
        $admin = $this->platformUser();
        $this->adminLogin($admin);
        $this->browser('GET', '/api/v1/admin/dashboard?section=commerce')->assertOk()->assertJsonPath('data.widgets.commerce.orders', 30)->assertJsonMissingPath('data.widgets.commerce.external_store_id');
        $this->browser('GET', '/api/v1/commerce/orders')->assertStatus(409)->assertJsonPath('data', null);
    }

    public function test_enabled_schema_trigger_checks_and_composite_foreign_keys_block_cross_tenant_links(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order()])[0];
        $other = $this->organization($this->user());
        try {
            DB::transaction(fn () => DB::table('commerce_draft_links')->insert(['merchant_id' => $other, 'order_id' => $o->id, 'snapshot_id' => $o->current_snapshot, 'shipment_id' => (string) Str::uuid(), 'actor_id' => $f['u']->id, 'review_hash' => str_repeat('0', 64)]));
            $this->fail();
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE commerce_snapshots DISABLE TRIGGER commerce_history');
            $this->assertContains('commerce_snapshots.commerce_history', app(SchemaIntegrity::class)->failures());
        } finally {
            DB::rollBack();
        }$this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_oauth_expiry_mass_assignment_and_conflicting_command_replays_fail_closed(): void
    {
        $f = $this->setupCommerce();
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
        DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $q['state']))->update(['expires_at' => now()->subSecond()]);
        $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'store-expired']))->assertForbidden();
        $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'untrusted-origin', 'actor_id' => $f['u']->id])->assertStatus(422);
        $o = $this->import($f, [$this->order()])[0];
        $d = $this->review($f, $o) + ['idempotency_key' => 'stable-draft-request'];
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk();
        $d['review']['description'] = 'Changed review must conflict';
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertStatus(409);
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_webhook_uninstall_and_replay_collision_revoke_locally_without_mutating_existing_orders(): void
    {
        $f = $this->setupCommerce();
        $this->import($f, [$this->order()]);
        $body = json_encode(['store' => 'store-A', 'receipt' => 'uninstall-proof', 'kind' => 'uninstall', 'timestamp' => now()->timestamp]);
        $headers = ['HTTP_X_SYNTHETIC_SIGNATURE' => hash_hmac('sha256', $body, 'synthetic-protocol-only'), 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip];
        $this->call('POST', '/api/v1/commerce/webhooks/salla', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/commerce/webhooks/salla', [], [], [], $headers, $body)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->assertDatabaseHas('commerce_connections', ['state' => 'disconnected']);
        $this->assertNull(DB::table('commerce_credentials')->value('ciphertext'));
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_disconnect_cancels_inflight_oauth_exchange_before_late_credentials_can_be_installed(): void
    {
        $f = $this->setupCommerce();
        $d = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($d['authorization_url'], PHP_URL_QUERY), $q);
        $this->provider->onExchange = function () use ($f) {
            $this->assertSame(0, DB::transactionLevel());
            DB::transaction(fn () => app(Connections::class)->disable(DB::table('commerce_connections')->where('id', $f['connection'])->first()));
        };
        $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'store-A']))->assertForbidden();
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'state' => 'disconnected']);
        $this->assertNull(DB::table('commerce_credentials')->value('ciphertext'));
    }

    public function test_guarded_transport_bounds_streamed_json_and_maps_network_timeouts(): void
    {
        config(['commerce.verified_api_hosts' => ['mock-commerce.example.test']]);
        $mode = 'ok';
        Http::fake(function () use (&$mode) {
            if ($mode === 'timeout') {
                throw new ConnectionException('synthetic network failure');
            }

            return Http::response($mode === 'limit' ? str_repeat('x', 524290) : json_encode(['safe' => str_repeat('a', 20000)]), 200);
        });
        $client = app(GuardedHttp::class);
        $this->assertSame(20000, strlen($client->request('GET', 'https://mock-commerce.example.test/orders')['safe']));
        foreach (['limit' => 'API_PAYLOAD_LIMIT', 'timeout' => 'API_TIMEOUT'] as $m => $code) {
            $mode = $m;
            try {
                $client->request('GET', 'https://mock-commerce.example.test/orders');
                $this->fail();
            } catch (IntegrationFailure $e) {
                $this->assertSame($code, $e->reason);
            }
        }
    }

    public function test_ambiguous_source_changes_cannot_be_overridden_by_a_browser_acknowledgement(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order()])[0];
        $d = $this->review($f, $o);
        $this->import($f, [$this->order('order-1', ['status' => 'cancelled', 'updated_at' => null])]);
        $o = DB::table('commerce_orders')->first();
        $d['version'] = $o->version;
        $d['snapshot_id'] = $o->current_snapshot;
        $d['acknowledge_changes'] = true;
        $p = $this->browser('POST', "/api/v1/commerce/orders/$o->id/preview", $d)->assertOk()->json('data');
        $this->assertFalse($p['valid']);
        $this->assertContains('SOURCE_REVIEW_REQUIRED', $p['blocks']);
        $d['preview_hash'] = $p['preview_hash'];
        $d['idempotency_key'] = 'unverified-source-override';
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertStatus(422);
        $this->assertDatabaseCount('shipments', 0);
    }
}
