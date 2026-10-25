<?php

namespace Tests\Feature;

use App\Modules\Commerce\Application\Connections;
use App\Modules\Commerce\Application\Orders;
use App\Modules\Commerce\Application\Work;
use App\Modules\Commerce\Domain\ShopyzeeProvider;
use App\Modules\Commerce\Infrastructure\DisabledShopyzeeProvider;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\Overview\Metrics;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShopyzeeTestCase;

final class ShopyzeeTest extends ShopyzeeTestCase
{
    use DatabaseMigrations;

    public function test_unverified_provider_is_closed_without_intents_receipts_or_http(): void
    {
        $f = $this->setupCommerce();
        $this->browser('GET', '/api/v1/commerce/providers')->assertOk()->assertJsonPath('data.providers.1.provider', 'shopyzee')->assertJsonPath('data.providers.1.available', false)->assertJsonPath('data.providers.1.authorization_method', null);
        $this->browser('POST', '/api/v1/commerce/shopyzee/authorize')->assertStatus(503);
        $this->browser('POST', '/api/v1/commerce/shopyzee/authorize', [], false)->assertStatus(419);
        $this->postJson('/api/v1/commerce/webhooks/shopyzee', [])->assertStatus(503);
        $this->assertDatabaseCount('commerce_receipts', 0);
        $this->assertDatabaseCount('commerce_authorizations', 1); // Salla fixture only, no Shopyzee intent.
    }

    public function test_instances_tenants_and_orders_have_distinct_permanent_identity(): void
    {
        $f = $this->setupShopyzee();
        $other = $this->syntheticConnection($f, 'instance-B');
        $tenant = $this->syntheticConnection($f, 'instance-A', 'tenant-B');
        foreach ([$f['connection'], $other, $tenant] as $id) {
            $c = DB::table('commerce_connections')->where('id', $id)->first();
            DB::transaction(fn () => app(Orders::class)->ingest($c, $this->order()));
            DB::transaction(fn () => app(Orders::class)->ingest($c, $this->order()));
        }
        $this->assertDatabaseCount('commerce_orders', 3);
        $this->assertDatabaseCount('commerce_snapshots', 3);
        $this->browser('GET', '/api/v1/commerce/orders')->assertJsonCount(0, 'data.orders')->assertJsonPath('data.source', 'salla');
        $this->browser('GET', '/api/v1/commerce/orders?provider=shopyzee')->assertJsonCount(3, 'data.orders')->assertJsonPath('data.orders.0.provider', 'shopyzee');
        $this->browser('GET', '/api/v1/commerce/orders?provider=all')->assertJsonCount(3, 'data.orders');
        $this->browser('GET', '/api/v1/commerce/orders?provider=unknown')->assertStatus(422);
        $this->expectException(QueryException::class);
        $this->syntheticConnection($f);
    }

    public function test_domain_changes_never_change_ownership_or_instance(): void
    {
        $f = $this->setupShopyzee();
        DB::table('commerce_connections')->where('id', $f['connection'])->update(['display_domain' => 'new.example.test']);
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'merchant_id' => $f['m'], 'external_store_id' => 'tenant-A']);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('commerce_connections')->where('id', $f['connection'])->update(['external_platform_id' => 'instance-B']));
    }

    public function test_disconnection_does_not_allow_store_reassignment(): void
    {
        $f = $this->setupShopyzee();
        $other = $this->organization($this->user());
        DB::transaction(fn () => app(Connections::class)->disable(DB::table('commerce_connections')->where('id', $f['connection'])->first()));
        $this->assertNull(DB::table('commerce_credentials')->where('connection_id', $f['connection'])->value('ciphertext'));
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('commerce_connections')->where('id', $f['connection'])->update(['merchant_id' => $other]));
    }

    private function sync(array $f, string $key = 'synthetic-sync'): string
    {
        return $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => $key])->assertStatus(202)->json('data.run_id');
    }

    private function page(array $orders = [], array $extra = []): array
    {
        return array_replace(['provider' => 'shopyzee', 'platform_id' => 'instance-A', 'store_id' => 'tenant-A', 'provenance' => 'synthetic', 'orders' => $orders, 'next_cursor' => null, 'checkpoint' => 'internal-watermark'], $extra);
    }

    public function test_provider_dispatch_pagination_deduplication_and_checkpoints(): void
    {
        $f = $this->setupShopyzee();
        $this->shopyzee->fixture->pages = ['first' => $this->page([$this->order()], ['next_cursor' => 'internal-page2', 'checkpoint' => null]), 'internal-page2' => $this->page([$this->order(), $this->order('order-2')])];
        $run = $this->sync($f);
        app(Work::class)->tick($f['m'], $run);
        app(Work::class)->tick($f['m'], $run);
        $this->assertDatabaseCount('commerce_orders', 2);
        $this->assertDatabaseHas('commerce_runs', ['id' => $run, 'state' => 'succeeded']);
        $this->assertSame(0, $this->provider->fetches);
        $this->assertSame('internal-watermark', Crypt::decryptString(DB::table('commerce_connections')->where('id', $f['connection'])->value('checkpoint')));
    }

    public function test_wrong_platform_page_is_rejected_before_any_order_write(): void
    {
        $f = $this->setupShopyzee();
        $this->shopyzee->fixture->pages = ['first' => $this->page([$this->order()], ['platform_id' => 'instance-B'])];
        $run = $this->sync($f);
        app(Work::class)->tick($f['m'], $run);
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseHas('commerce_runs', ['id' => $run, 'failure_code' => 'API_INVALID_PAYLOAD']);
    }

    public function test_no_salla_fallback_when_shopyzee_is_unavailable(): void
    {
        $f = $this->setupShopyzee();
        app()->instance(ShopyzeeProvider::class, new DisabledShopyzeeProvider);
        $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'disabled-provider'])->assertStatus(503);
        $this->assertSame(0, $this->provider->fetches);
    }

    public function test_disconnect_during_io_discards_the_response(): void
    {
        $f = $this->setupShopyzee();
        $this->shopyzee->fixture->pages = ['first' => $this->page([$this->order()])];
        $this->shopyzee->fixture->onOrders = fn () => DB::transaction(fn () => app(Connections::class)->disable(DB::table('commerce_connections')->where('id', $f['connection'])->first()));
        $run = $this->sync($f);
        app(Work::class)->tick($f['m'], $run);
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseHas('commerce_runs', ['id' => $run, 'state' => 'blocked']);
    }

    public function test_rotating_credentials_rechecks_platform_identity(): void
    {
        $f = $this->setupShopyzee();
        DB::table('commerce_credentials')->where('connection_id', $f['connection'])->update(['expires_at' => now()->subSecond()]);
        $this->shopyzee->platform = 'wrong-instance';
        $run = $this->sync($f);
        app(Work::class)->tick($f['m'], $run);
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'state' => 'reauthorization_required']);
        $this->assertDatabaseCount('commerce_orders', 0);
    }

    public function test_cross_merchant_and_token_scope_fences_remain_authoritative(): void
    {
        $f = $this->setupShopyzee();
        $o = $this->import($f, [$this->order()])[0];
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $this->bearer($t, 'GET', "/api/v1/commerce/orders/$o->id")->assertNotFound();
        $this->bearer($t, 'POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'cross-merchant'])->assertNotFound();
        $this->bearer($this->token($f['u'], $f['m'], ['orders.read']), 'POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'no-scope'])->assertForbidden();
    }

    public function test_single_draft_is_idempotent_and_source_changes_preserve_it(): void
    {
        $f = $this->setupShopyzee();
        $o = $this->import($f, [$this->order()])[0];
        $d = $this->review($f, $o) + ['idempotency_key' => 'shopyzee-single-draft'];
        $id = $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk()->json('data.shipment_id');
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertJsonPath('data.shipment_id', $id);
        $this->import($f, [$this->order(overrides: ['updated_at' => now()->addMinute()->toIso8601String(), 'status' => 'cancelled'])]);
        $this->browser('GET', "/api/v1/commerce/orders/$o->id")->assertJsonPath('data.order.provider', 'shopyzee')->assertJsonPath('data.order.review_required', true);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
    }

    public function test_currency_cod_and_incomplete_data_are_not_fabricated(): void
    {
        $f = $this->setupShopyzee();
        foreach ([['currency' => 'USD', 'cod_minor' => '500'], ['cod_minor' => null], ['recipient' => ['name' => 'Missing address']]] as $i => $bad) {
            $o = $this->import($f, [$this->order('bad-'.$i, $bad)])[$i];
            $d = ['snapshot_id' => $o->current_snapshot, 'version' => $o->version, 'review' => []];
            $this->browser('POST', "/api/v1/commerce/orders/$o->id/preview", $d)->assertJsonPath('data.valid', false);
        }
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_salla_callback_cannot_consume_a_different_provider_intent(): void
    {
        $f = $this->setupShopyzee();
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
        DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $q['state']))->update(['provider' => 'shopyzee']);
        $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'wrong']))->assertForbidden();
        $this->assertDatabaseCount('commerce_connections', 2);
    }

    public function test_schema_integrity_and_retained_history_downgrade_refusal(): void
    {
        $f = $this->setupShopyzee();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        $migration = require database_path('migrations/2026_10_25_140000_extend_commerce_provider_identity.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_bulk_preparation_keeps_partial_results_and_permanent_draft_links(): void
    {
        $f = $this->setupShopyzee();
        $rows = $this->import($f, [$this->order('first'), $this->order('second')]);
        $selections = [];
        foreach ($rows as $o) {
            $selections[] = ['order_id' => $o->id] + $this->review($f, $o);
        }$selections[1]['review']['packages'] = [];
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/prepare", ['selections' => $selections, 'idempotency_key' => 'bulk-draft-command'])->assertStatus(202)->json('data.run_id');
        app(Work::class)->tick($f['m'], $id);
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('commerce_results', ['run_id' => $id, 'state' => 'failed']);
        $this->assertDatabaseHas('commerce_results', ['run_id' => $id, 'state' => 'created']);
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('shipments', 1);
    }

    public static function revocations(): array
    {
        return [['role'], ['token'], ['merchant']];
    }

    #[DataProvider('revocations')]
    public function test_inflight_sync_after_role_token_or_merchant_revocation_discards_fetched_orders(string $kind): void
    {
        $f = $this->setupShopyzee();
        $idempotency = 'race-'.$kind;
        if ($kind === 'token') {
            $t = $this->token($f['u'], $f['m'], ['integrations.sync']);
            $id = $this->bearer($t, 'POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => $idempotency])->assertStatus(202)->json('data.run_id');
        } else {
            $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => $idempotency])->assertStatus(202)->json('data.run_id');
        }
        $this->shopyzee->fixture->pages = ['first' => $this->page([$this->order()])];
        $this->shopyzee->fixture->onOrders = function () use ($kind, $f) {
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

    public function test_shopyzee_page_commit_crash_recovers_without_duplicate_effects(): void
    {
        $f = $this->setupShopyzee();
        $this->shopyzee->fixture->pages = ['first' => $this->page([$this->order()])];
        $id = $this->sync($f);
        $crash = true;
        DB::listen(function ($q) use (&$crash) {
            if ($crash && str_starts_with($q->sql, 'insert into "commerce_pages"')) {
                $crash = false;
                throw new \RuntimeException('Injected page crash');
            }
        });
        try {
            app(Work::class)->tick($f['m'], $id);
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected page crash', $e->getMessage());
        }
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseCount('commerce_pages', 0);
        DB::table('commerce_runs')->where('id', $id)->update(['lease_until' => now()->subSecond()]);
        app(Work::class)->recover();
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('commerce_snapshots', 1);
        $this->assertDatabaseCount('commerce_pages', 1);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'state' => 'succeeded']);
    }

    public function test_platform_counts_distinguish_sources_without_private_order_data(): void
    {
        $f = $this->setupShopyzee();
        $this->import($f, [$this->order()]);
        $metrics = app(Metrics::class)->commerce([]);
        $this->assertSame(1, $metrics['providers']['shopyzee']['orders']);
        $this->assertSame(0, $metrics['providers']['salla']['orders']);
        $this->assertSame(0, $metrics['providers']['shopyzee']['verified_authorized_stores']);
        $this->assertStringNotContainsString('Synthetic Recipient', json_encode($metrics));
        $this->assertStringNotContainsString($f['m'], json_encode($metrics));
        $admin = $this->platformUser();
        $this->adminLogin($admin);
        $this->browser('GET', '/api/v1/commerce/orders?provider=shopyzee')->assertStatus(409)->assertJsonPath('data', null);
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $f['m']])->assertNotFound()->assertJsonPath('data', null);
    }

    public function test_additive_identity_rollback_reapply_preserves_salla_and_merchant_data(): void
    {
        $f = $this->setupCommerce();
        $migration = require database_path('migrations/2026_10_25_140000_extend_commerce_provider_identity.php');
        $migration->down();
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'merchant_id' => $f['m'], 'provider' => 'salla']);
        $migration->up();
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'external_platform_id' => 'salla']);
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_missing_platform_unique_constraint_cannot_silently_pass_schema_checks(): void
    {
        $f = $this->setupShopyzee();
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE commerce_connections DROP CONSTRAINT commerce_source_identity');
            $this->assertContains('commerce_source_identity', app(SchemaIntegrity::class)->failures());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_disconnect_cancels_only_its_provider_authorization_intents(): void
    {
        $f = $this->setupShopyzee();
        $intents = [];
        foreach (['salla', 'shopyzee'] as $provider) {
            $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
            parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
            $intents[$provider] = $q['state'];
            DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $q['state']))->update(['provider' => $provider]);
        }
        $this->browser('GET', '/api/v1/commerce/connections')->assertOk()->assertDontSee('synthetic-only')->assertJsonMissingPath('data.connections.0.ciphertext');
        $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/disconnect", ['version' => 1, 'idempotency_key' => 'shopyzee-disconnect'])->assertOk();
        $this->assertNull(DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $intents['salla']))->value('cancelled_at'));
        $this->assertNotNull(DB::table('commerce_authorizations')->where('state_hash', hash('sha256', $intents['shopyzee']))->value('cancelled_at'));
        $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $intents['salla'], 'code' => 'another-salla-store']))->assertOk();
    }
}
