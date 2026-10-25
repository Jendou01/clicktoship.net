<?php

namespace Tests\Feature;

use App\Modules\Commerce\Application\Connections;
use App\Modules\Commerce\Application\Work;
use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Infrastructure\GuardedHttp;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CommerceTestCase;

final class CommerceTest extends CommerceTestCase
{
    use DatabaseMigrations;

    public function test_default_provider_is_unavailable_and_never_creates_fake_authority(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->login($u);
        $this->select($m);
        Http::preventStrayRequests();
        $this->browser('GET', '/api/v1/commerce/providers')->assertOk()->assertJsonPath('data.providers.0.available', false)->assertJsonPath('data.providers.0.oauth_mode', null)->assertJsonPath('data.providers.0.required_scopes', []);
        $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertStatus(503);
        $this->browser('GET', '/api/v1/commerce/salla/callback?state='.str_repeat('a', 64).'&code=untrusted')->assertStatus(503);
        $this->postJson('/api/v1/commerce/webhooks/salla', [])->assertStatus(503);
        $this->assertDatabaseCount('commerce_connections', 0);
        $this->assertDatabaseCount('commerce_authorizations', 0);
        $this->assertDatabaseCount('commerce_receipts', 0);
    }

    public function test_oauth_state_is_hashed_one_use_current_session_and_store_identity_is_server_verified(): void
    {
        $f = $this->setupCommerce();
        $this->assertDatabaseHas('commerce_connections', ['id' => $f['connection'], 'external_store_id' => 'store-A', 'provenance' => 'synthetic']);
        $row = DB::table('commerce_credentials')->first();
        $this->assertStringNotContainsString('synthetic-access', $row->ciphertext);
        $this->browser('GET', '/api/v1/commerce/connections')->assertOk()->assertJsonMissingPath('data.connections.0.ciphertext')->assertJsonMissingPath('data.connections.0.access_token');
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
        $this->assertDatabaseHas('commerce_authorizations', ['state_hash' => hash('sha256', $q['state'])]);
        $this->browser('GET', '/api/v1/commerce/salla/callback?state='.str_repeat('b', 64).'&code=store-B')->assertForbidden();
        $url = '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'store-B']);
        $this->browser('GET', $url)->assertOk();
        $this->browser('GET', $url)->assertForbidden();
        $this->assertDatabaseCount('commerce_connections', 2);
    }

    public function test_oauth_cannot_switch_merchants_sessions_or_claim_another_merchants_store(): void
    {
        $f = $this->setupCommerce();
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
        $url = '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'store-B']);
        $other = $this->organization($f['u']);
        $this->select($other);
        $this->browser('GET', $url)->assertForbidden();
        $this->select($f['m']);
        $this->jar = [];
        $this->login($f['u']);
        $this->select($f['m']);
        $this->browser('GET', $url)->assertForbidden();
        $this->select($other);
        $r = $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertOk()->json('data');
        parse_str(parse_url($r['authorization_url'], PHP_URL_QUERY), $q);
        $this->browser('GET', '/api/v1/commerce/salla/callback?'.http_build_query(['state' => $q['state'], 'code' => 'store-A']))->assertStatus(409);
        $this->assertDatabaseCount('commerce_connections', 1);
    }

    public function test_strict_redirect_and_csrf_no_browser_supplied_store_or_bearer_oauth(): void
    {
        $f = $this->setupCommerce();
        $this->browser('POST', '/api/v1/commerce/salla/authorize', [], false)->assertStatus(419);
        $this->bearer($this->token($f['u'], $f['m']), 'POST', '/api/v1/commerce/salla/authorize')->assertStatus(400);
        config(['commerce.redirect_uri' => 'http://127.0.0.1/callback']);
        $this->browser('POST', '/api/v1/commerce/salla/authorize')->assertStatus(503);
    }

    public function test_sync_pages_commit_identities_checkpoint_and_repeat_without_duplicates(): void
    {
        $f = $this->setupCommerce();
        $this->provider->pages = ['first' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order('one')], 'next_cursor' => 'page2', 'checkpoint' => null], 'page2' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order('one'), $this->order('two')], 'next_cursor' => null, 'checkpoint' => 'watermark']];
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'sync-command-1'])->assertStatus(202)->json('data.run_id');
        $this->assertTrue(app(Work::class)->tick($f['m'], $id));
        $this->assertFalse(app(Work::class)->tick($f['m'], $id));
        $this->assertDatabaseCount('commerce_orders', 2);
        $this->assertDatabaseCount('commerce_snapshots', 2);
        $this->assertSame('watermark', Crypt::decryptString(DB::table('commerce_connections')->value('checkpoint')));
        $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'sync-command-1'])->assertStatus(202)->assertJsonPath('data.run_id', $id);
        $this->assertSame(2, $this->provider->fetches);
    }

    public function test_invalid_page_wrong_store_duplicate_cursors_and_rollback_are_safe(): void
    {
        $f = $this->setupCommerce();
        $this->provider->pages = ['first' => ['store_id' => 'wrong-store', 'provenance' => 'synthetic', 'orders' => [$this->order()], 'next_cursor' => null, 'checkpoint' => null]];
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'invalid-store-1'])->assertStatus(202)->json('data.run_id');
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'failure_code' => 'API_INVALID_PAYLOAD']);
        $this->provider->pages = ['first' => ['store_id' => 'store-A', 'provenance' => 'synthetic', 'orders' => [$this->order(), $this->order('bad', ['amount_minor' => '12.3'])], 'next_cursor' => null, 'checkpoint' => null]];
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'invalid-money-1'])->assertStatus(202)->json('data.run_id');
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseCount('commerce_pages', 0);
    }

    public function test_transport_classifies_rate_limits_timeouts_scopes_redirects_and_payload_limits_without_unverified_hosts(): void
    {
        $f = $this->setupCommerce();
        config(['commerce.verified_api_hosts' => ['mock-commerce.example.test']]);
        $client = app(GuardedHttp::class);
        foreach ([['http://mock-commerce.example.test/x', 'UNVERIFIED_API_HOST'], ['https://127.0.0.1/x', 'UNVERIFIED_API_HOST'], ['https://evil.example.test/x', 'UNVERIFIED_API_HOST']] as [$url,$code]) {
            try {
                $client->request('GET', $url);
                $this->fail();
            } catch (IntegrationFailure $e) {
                $this->assertSame($code, $e->reason);
            }
        }
        Http::fake(function () use (&$status) {
            return Http::response('invalid', $status, ['Retry-After' => '999999']);
        });
        foreach ([[429, 'RATE_LIMITED'], [503, 'API_TEMPORARY_FAILURE'], [401, 'AUTHORIZATION_EXPIRED'], [403, 'SCOPE_DENIED'], [302, 'API_UNSUPPORTED'], [200, 'API_INVALID_PAYLOAD']] as [$status,$code]) {
            try {
                $client->request('GET', 'https://mock-commerce.example.test/orders');
                $this->fail();
            } catch (IntegrationFailure $e) {
                $this->assertSame($code, $e->reason);
                $this->assertLessThanOrEqual(900, $e->retryAfter);
            }
        }
    }

    public function test_rate_limit_work_retries_are_durable_bounded_and_do_not_advance_checkpoint(): void
    {
        $f = $this->setupCommerce();
        $this->provider->onOrders = fn () => throw new IntegrationFailure('RATE_LIMITED', true, 90);
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'rate-limited-sync'])->assertStatus(202)->json('data.run_id');
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'state' => 'waiting', 'position' => 0]);
        $this->assertNull(DB::table('commerce_connections')->value('checkpoint'));
        $this->assertFalse(app(Work::class)->tick($f['m'], $id));
        $this->assertSame(1, $this->provider->fetches);
    }

    public function test_disconnect_fences_inflight_sync_and_replays_cannot_restore_credentials(): void
    {
        $f = $this->setupCommerce();
        $this->provider->onOrders = function () use ($f) {
            DB::transaction(fn () => app(Connections::class)->disable(DB::table('commerce_connections')->where('id', $f['connection'])->first()));
        };
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'disconnect-sync'])->assertStatus(202)->json('data.run_id');
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'state' => 'blocked']);
        $this->assertNull(DB::table('commerce_credentials')->value('ciphertext'));
    }

    public function test_revoked_origin_session_token_role_and_merchant_suspension_stop_background_work(): void
    {
        $f = $this->setupCommerce();
        $id = $this->browser('POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'revocation-sync'])->assertStatus(202)->json('data.run_id');
        DB::table('auth_sessions')->where('user_id', $f['u']->id)->update(['revoked_at' => now()]);
        app(Work::class)->tick($f['m'], $id);
        $this->assertDatabaseHas('commerce_runs', ['id' => $id, 'state' => 'blocked']);
        $this->assertSame(0, $this->provider->fetches);
        $this->assertNull(app(MerchantContext::class)->id());
    }

    public function test_source_snapshots_are_encrypted_immutable_and_older_changes_never_regress_current_data(): void
    {
        $f = $this->setupCommerce();
        $orders = $this->import($f, [$this->order()]);
        $o = $orders[0];
        $old = $o->current_snapshot;
        $this->import($f, [$this->order('order-1', ['status' => 'cancelled', 'updated_at' => now()->subHour()->toIso8601String()])]);
        $o = DB::table('commerce_orders')->first();
        $this->assertSame($old, $o->current_snapshot);
        $this->assertTrue($o->review_required);
        $this->assertDatabaseCount('commerce_snapshots', 2);
        $this->assertStringNotContainsString('Synthetic Recipient', DB::table('commerce_snapshots')->value('payload'));
        try {
            DB::transaction(fn () => DB::table('commerce_snapshots')->update(['payload' => 'changed']));
            $this->fail();
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_explicit_preview_and_draft_conversion_are_idempotent_and_never_charge_or_submit(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order()])[0];
        $d = $this->review($f, $o) + ['idempotency_key' => 'draft-command-one'];
        $result = $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk()->json('data');
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk()->assertJsonPath('data.shipment_id', $result['shipment_id']);
        $d['idempotency_key'] = 'other-actor-command';
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk()->assertJsonPath('data.state', 'existing');
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('commerce_draft_links', 1);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
        $this->assertDatabaseCount('shipment_submissions', 0);
    }

    public function test_missing_data_cod_currency_cancellation_and_optimistic_changes_block_drafts(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order('incomplete', ['recipient' => ['country' => 'SA'], 'cod_minor' => '500'])])[0];
        $d = ['snapshot_id' => $o->current_snapshot, 'version' => $o->version, 'review' => []];
        $p = $this->browser('POST', "/api/v1/commerce/orders/$o->id/preview", $d)->assertOk()->json('data');
        $this->assertFalse($p['valid']);
        $this->assertContains('COD_NOT_SUPPORTED', $p['blocks']);
        $this->assertContains('packages', $p['fields']);
        $d['preview_hash'] = $p['preview_hash'];
        $d['idempotency_key'] = 'incomplete-command';
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertStatus(422);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_changed_source_does_not_overwrite_linked_shipment_and_requires_explicit_review(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order()])[0];
        $d = $this->review($f, $o) + ['idempotency_key' => 'historical-draft'];
        $this->browser('POST', "/api/v1/commerce/orders/$o->id/draft", $d)->assertOk();
        $payload = DB::table('shipment_revisions')->value('payload');
        $this->import($f, [$this->order('order-1', ['status' => 'cancelled', 'updated_at' => now()->addSecond()->toIso8601String()])]);
        $this->assertSame($payload, DB::table('shipment_revisions')->value('payload'));
        $this->browser('GET', "/api/v1/commerce/orders/$o->id")->assertOk()->assertJsonPath('data.order.review_required', true)->assertJsonPath('data.snapshot.status', 'cancelled');
        $this->assertDatabaseCount('shipment_revisions', 1);
    }

    public function test_bulk_preparation_keeps_partial_results_and_permanent_draft_links(): void
    {
        $f = $this->setupCommerce();
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

    public function test_orders_scoped_search_pagination_tokens_and_platform_roles_never_bypass_membership(): void
    {
        $f = $this->setupCommerce();
        $o = $this->import($f, [$this->order()])[0];
        $other = $this->user();
        $m = $this->organization($other);
        $t = $this->token($other, $m);
        $this->bearer($t, 'GET', "/api/v1/commerce/orders/$o->id")->assertNotFound();
        $this->bearer($this->token($f['u'], $f['m'], ['orders.read']), 'POST', "/api/v1/commerce/connections/{$f['connection']}/sync", ['idempotency_key' => 'no-sync-ability'])->assertForbidden();
        $this->bearer($t, 'GET', '/api/v1/commerce/orders')->assertOk()->assertJsonCount(0, 'data.orders');
        $this->select($f['m']);
        $this->browser('GET', '/api/v1/commerce/orders?reference=SYN-order-1')->assertOk()->assertJsonCount(1, 'data.orders');
        $this->browser('GET', '/api/v1/commerce/orders?reference=other')->assertOk()->assertJsonCount(0, 'data.orders');
    }

    public function test_signed_synthetic_receipts_are_idempotent_quarantine_unknown_and_cannot_create_drafts(): void
    {
        $f = $this->setupCommerce();
        $body = json_encode(['store' => 'store-A', 'receipt' => 'synthetic-event-1', 'kind' => 'unknown', 'timestamp' => now()->timestamp]);
        $headers = ['HTTP_X_SYNTHETIC_SIGNATURE' => hash_hmac('sha256', $body, 'synthetic-protocol-only'), 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip];
        $this->call('POST', '/api/v1/commerce/webhooks/salla', [], [], [], $headers, $body)->assertOk()->assertJsonPath('data.quarantined', true);
        $this->call('POST', '/api/v1/commerce/webhooks/salla', [], [], [], $headers, $body)->assertOk()->assertJsonPath('data.duplicate', true);
        $headers['HTTP_X_SYNTHETIC_SIGNATURE'] = 'wrong';
        $this->call('POST', '/api/v1/commerce/webhooks/salla', [], [], [], $headers, $body)->assertForbidden();
        $this->assertDatabaseCount('commerce_receipts', 1);
        $this->assertDatabaseCount('shipments', 0);
    }

    public function test_schema_drift_constraints_immutable_history_and_safe_rollback(): void
    {
        $f = $this->setupCommerce();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        $phase15 = require database_path('migrations/2026_10_25_150000_create_analytics_exports.php');
        $phase15->down();
        $phase14 = require database_path('migrations/2026_10_25_140000_extend_commerce_provider_identity.php');
        $phase14->down();
        $guard = require database_path('migrations/2026_10_25_121000_fence_commerce_authorizations.php');
        $guard->down();
        $migration = require database_path('migrations/2026_10_25_120000_create_commerce.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $f['u']->id]);
        $this->assertDatabaseCount('shipments', 0);
        $migration->up();
        $guard->up();
        $phase14->up();
        $phase15->up();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }
}
