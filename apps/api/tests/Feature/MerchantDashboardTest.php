<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use App\Support\SchemaIntegrity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CustomsTestCase;

final class MerchantDashboardTest extends CustomsTestCase
{
    use DatabaseMigrations;

    private function row(string $merchant, string $actor, string $at): string
    {
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $merchant, $actor, $at) {
            DB::table('shipments')->insert(['id' => $id, 'merchant_id' => $merchant, 'created_at' => $at]);
            DB::table('shipment_revisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'shipment_id' => $id, 'revision' => 1, 'payload' => Crypt::encryptString('{}'), 'input_hash' => str_repeat('a', 64), 'pricing_hash' => str_repeat('b', 64), 'actor_id' => $actor]);
            DB::table('shipment_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'shipment_id' => $id, 'kind' => 'draft_saved', 'created_at' => $at]);
        });

        return $id;
    }

    public function test_empty_dashboard_is_truthful_and_does_not_create_money_or_shipments(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        $this->bearer($token, 'GET', '/api/v1/dashboard')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.widgets.shipping.total', 0)->assertJsonPath('data.widgets.shipping.carrier_accepted', 0)->assertJsonPath('data.widgets.wallet.balances', [])->assertJsonPath('data.widgets.pricing.availability', 'requires_route_inputs')->assertJsonPath('data.widgets.customs.locally_complete', 0)->assertJsonPath('data.live_shipping_enabled', false)->assertJsonPath('data.payments_enabled', false)->assertJsonCount(30, 'data.widgets.shipping.daily');
        foreach (['shipments', 'wallets', 'ledger_transactions', 'carrier_operations', 'customs_readiness'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_current_tenant_date_cohorts_and_utc_midnight_boundaries(): void
    {
        $u = $this->user();
        $a = $this->organization($u);
        $b = $this->organization($u);
        $t = $this->token($u, $a);
        $today = now('UTC')->startOfDay();
        $id = $this->row($a, $u->id, $today->toIso8601String());
        $this->row($a, $u->id, $today->copy()->endOfDay()->toIso8601String());
        $this->row($a, $u->id, $today->copy()->subSecond()->toIso8601String());
        $this->row($a, $u->id, $today->copy()->addDay()->toIso8601String());
        $foreign = $this->row($b, $u->id, $today->toIso8601String());
        $r = $this->bearer($t, 'GET', '/api/v1/dashboard?range=today')->assertOk()->assertJsonPath('data.merchant.id', $a)->assertJsonPath('data.widgets.shipping.total', 2)->assertJsonPath('data.widgets.shipping.drafts', 2)->assertJsonPath('data.widgets.shipping.submitted', 0)->assertJsonPath('data.widgets.shipping.daily.0.count', 2);
        $this->assertStringNotContainsString($foreign, $r->getContent());
        $this->assertStringContainsString($id, $r->getContent());
        $this->bearer($t, 'GET', '/api/v1/dashboard?range=7d')->assertOk()->assertJsonPath('data.widgets.shipping.total', 3)->assertJsonCount(7, 'data.widgets.shipping.daily');
        $date = $today->toDateString();
        $this->bearer($t, 'GET', "/api/v1/dashboard?range=custom&from=$date&to=$date")->assertOk()->assertJsonPath('data.widgets.shipping.total', 2);
        $this->bearer($t, 'GET', '/api/v1/dashboard?range=90d')->assertOk()->assertJsonCount(90, 'data.widgets.shipping.daily');
    }

    public function test_permission_intersection_omits_sources_and_data_queries(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m, ['merchant.view', 'wallet.read']);
        $this->row($m, $u->id, now()->toIso8601String());
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $r = $this->bearer($t, 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity', []);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(['wallet'], array_keys($r->json('data.widgets')));
        foreach ($queries as $q) {
            $this->assertFalse((bool) preg_match('/from "(?:shipments|shipping_quotes|customs_readiness|bulk_batches|tracking_bindings)"/', $q['query']));
        }
        $this->bearer($this->token($u, $m, ['shipments.read']), 'GET', '/api/v1/dashboard')->assertForbidden();
    }

    public function test_unauthorized_spoofed_and_revoked_credentials_never_reuse_data(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $u = $this->user();
        $m = $this->organization($u);
        $other = $this->organization($this->user());
        $t = $this->token($u, $m);
        $this->bearer($t, 'GET', '/api/v1/dashboard')->assertOk();
        $this->bearer($t, 'GET', '/api/v1/dashboard', [], ['X-Merchant-ID' => $other])->assertStatus(409);
        $this->bearer($t, 'GET', "/api/v1/dashboard?merchant_id=$other")->assertUnprocessable();
        $u->tokens()->delete();
        $this->bearer($t, 'GET', '/api/v1/dashboard')->assertUnauthorized();
        $t = $this->token($u, $m);
        DB::table('merchant_users')->where('merchant_id', $m)->where('user_id', $u->id)->update(['status' => 'suspended']);
        $this->bearer($t, 'GET', '/api/v1/dashboard')->assertForbidden();
    }

    public function test_session_switching_reads_the_new_merchant_and_invalidates_revoked_session(): void
    {
        $u = $this->user();
        $a = $this->organization($u);
        $b = $this->organization($u);
        $this->row($a, $u->id, now()->toIso8601String());
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD])->assertOk();
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $a])->assertOk();
        $this->browser('GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.shipping.total', 1);
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $b])->assertOk();
        $this->browser('GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.merchant.id', $b)->assertJsonPath('data.widgets.shipping.total', 0);
        DB::table('auth_sessions')->where('user_id', $u->id)->update(['revoked_at' => now()]);
        $this->browser('GET', '/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_activity_has_stable_bounded_keyset_pages_and_identifier_search(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $ids = [];
        for ($n = 0; $n < 23; $n++) {
            $ids[] = $this->row($m, $u->id, now()->startOfDay()->toIso8601String());
        }
        $a = $this->bearer($t, 'GET', '/api/v1/dashboard/activity')->assertOk()->assertJsonCount(20, 'data.activity')->json('data');
        $b = $this->bearer($t, 'GET', '/api/v1/dashboard/activity?cursor='.urlencode($a['next_cursor']))->assertOk()->assertJsonCount(3, 'data.activity')->assertJsonPath('data.next_cursor', null)->json('data');
        $this->assertCount(23, array_unique(array_merge(array_column($a['activity'], 'id'), array_column($b['activity'], 'id'))));
        $this->bearer($t, 'GET', '/api/v1/dashboard/activity?search='.$ids[0])->assertOk()->assertJsonCount(1, 'data.activity')->assertJsonPath('data.activity.0.subject_id', $ids[0]);
        $this->bearer($t, 'GET', '/api/v1/dashboard/activity?kind=customs')->assertOk()->assertJsonPath('data.activity', []);
        $this->bearer($t, 'GET', '/api/v1/dashboard/activity?cursor=malformed')->assertUnprocessable();
        $this->bearer($t, 'GET', '/api/v1/dashboard/activity?search=%27%20OR%201=1')->assertUnprocessable();
    }

    public function test_date_filters_reject_unbounded_future_or_malformed_input(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        foreach (['range=all', 'range=custom', 'range=custom&from=2024-01-01&to=2026-01-01', 'range=custom&from=2026-01-02&to=2026-01-01', 'range=custom&from=2026-02-30&to=2026-03-01', 'range=custom&from=2099-01-01&to=2099-01-02', 'timezone=Asia/Riyadh'] as $query) {
            $this->bearer($t, 'GET', '/api/v1/dashboard?'.$query)->assertUnprocessable();
        }
        $this->bearer($t, 'GET', '/api/v1/dashboard?range=custom&from='.now('UTC')->subDays(365)->toDateString().'&to='.now('UTC')->toDateString())->assertOk()->assertJsonCount(366, 'data.widgets.shipping.daily');
    }

    public function test_balances_are_current_exact_strings_and_never_combined(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $ledger = app(Ledger::class);
        $w = $ledger->provision($m);
        $ledger->command($m, $w, 'synthetic', '999999999999', 'large-synthetic', 'large-synthetic', 'test');
        DB::table('financial_currencies')->insert(['code' => 'USD', 'exponent' => 2, 'enabled' => true]);
        $other = $ledger->provision($m, 'USD');
        $this->bearer($t, 'GET', '/api/v1/dashboard?range=today')->assertOk()->assertJsonPath('data.widgets.wallet.balances.0.currency', 'SAR')->assertJsonPath('data.widgets.wallet.balances.0.posted', '999999999999')->assertJsonPath('data.widgets.wallet.balances.0.available', '999999999999')->assertJsonPath('data.widgets.wallet.balances.1.currency', 'USD')->assertJsonPath('data.widgets.wallet.balances.1.posted', '0');
        $this->assertNotSame($w, $other);
    }

    public function test_latest_customs_readiness_uses_current_revision_and_has_no_clearance_claim(): void
    {
        $f = $this->customsFixture();
        $saved = $this->saveCustoms($f)->assertOk()->json('data');
        $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.customs.not_assessed', 1);
        DB::table('customs_readiness')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $f['m'], 'shipment_id' => $f['s']['id'], 'revision' => $saved['shipment']['revision'], 'report' => json_encode(['local_valid' => true])]);
        $r = $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.customs.locally_complete', 1)->assertJsonPath('data.widgets.customs.clearance_enabled', false);
        foreach (['private_snapshot', 'unit_cost', 'min_margin', 'payload_ciphertext', 'Synthetic contact'] as $value) {
            $this->assertStringNotContainsString($value, $r->getContent());
        }
        $c = $f['customs'];
        $c['notes'] = 'Updated synthetic source';
        $this->saveCustoms($f, ['version' => $saved['shipment']['version'], 'declaration_version' => 1, 'declaration' => $c, 'idempotency_key' => 'updated-source-revision'])->assertOk();
        $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.customs.locally_complete', 0)->assertJsonPath('data.widgets.customs.not_assessed', 1);
    }

    public function test_constant_query_count_under_growing_tenant_and_index_rollback_preserves_data(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $this->row($m, $u->id, now()->toIso8601String());
        DB::enableQueryLog();
        $this->bearer($t, 'GET', '/api/v1/dashboard')->assertOk();
        $first = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($n = 0; $n < 60; $n++) {
            $this->row($m, $u->id, now()->toIso8601String());
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->bearer($t, 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.shipping.total', 61);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($first + 1, $second);
        $this->assertLessThan(35, $second);
        $migration = require database_path('migrations/2026_10_25_080000_add_dashboard_read_indexes.php');
        $migration->down();
        $this->assertDatabaseCount('shipments', 61);
        $migration->up();
        $this->assertTrue((bool) DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname='dashboard_quotes'"));
    }

    public function test_bulk_phase_progress_counts_real_rows_instead_of_cursor_positions(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $id = (string) Str::uuid();
        DB::table('bulk_batches')->insert(['id' => $id, 'merchant_id' => $m, 'actor_id' => $u->id, 'auth_version' => $u->auth_version, 'state' => 'validating', 'format' => 'csv', 'delimiter' => ',', 'row_count' => 2]);
        foreach ([2, 7] as $n) {
            DB::table('bulk_rows')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'batch_id' => $id, 'source_row' => $n, 'raw' => Crypt::encryptString('private synthetic row'), 'raw_hash' => str_repeat('a', 64)]);
        }
        DB::table('bulk_work')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'batch_id' => $id, 'phase' => 'validating', 'mapping_version' => 0, 'cursor' => 3]);
        $r = $this->bearer($t, 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.bulk.batches.0.processed_rows', 1)->assertJsonPath('data.widgets.bulk.batches.0.total_rows', 2)->assertJsonPath('data.widgets.bulk.batches.0.prepared_rows', 0)->assertJsonPath('data.widgets.bulk.batches.0.phase', 'validating')->assertJsonPath('data.widgets.bulk.states.0.count', 1);
        $this->assertStringNotContainsString('private synthetic row', $r->getContent());
    }

    public function test_quotes_are_estimates_and_exceptions_are_counted_from_source(): void
    {
        $f = $this->setupShipment(false);
        $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.pricing.quotes', 1)->assertJsonPath('data.widgets.pricing.unexpired', 1)->assertJsonPath('data.widgets.pricing.availability', 'requires_route_inputs');
        DB::table('shipments')->where('id', $f['s']['id'])->update(['state' => 'queued']);
        DB::table('shipments')->where('id', $f['s']['id'])->update(['state' => 'blocked']);
        $this->bearer($f['token'], 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.widgets.shipping.exceptions', 1)->assertJsonPath('data.widgets.shipping.carrier_accepted', 0);
    }

    public function test_recorded_index_migration_cannot_mask_missing_read_index(): void
    {
        DB::beginTransaction();
        try {
            DB::statement('DROP INDEX dashboard_customs_latest');
            $this->assertContains('index.dashboard_customs_latest', app(SchemaIntegrity::class)->failures());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_base_permission_only_returns_an_empty_object_without_operational_sources(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m, ['merchant.view']);
        $r = $this->bearer($token, 'GET', '/api/v1/dashboard')->assertOk()->assertJsonPath('data.activity', []);
        $this->assertStringContainsString('"widgets":{}', $r->getContent());
    }
}
