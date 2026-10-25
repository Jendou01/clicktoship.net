<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CustomsTestCase;

final class PlatformOverviewTest extends CustomsTestCase
{
    use DatabaseMigrations;

    public function test_empty_authorized_platform_counts_are_truthful_and_reads_have_no_business_effects(): void
    {
        $admin = $this->platformUser();
        $this->adminLogin($admin);
        $r = $this->browser('GET', '/api/v1/admin/dashboard')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.widgets.merchants.total', 0)->assertJsonPath('data.widgets.users.total', 1)->assertJsonPath('data.widgets.shipping.verified_accepted_in_period', 0)->assertJsonPath('data.widgets.finance.currencies', [])->assertJsonPath('data.widgets.finance.amounts_visibility', 'exact_target_grant_required')->assertJsonPath('data.live_shipping_enabled', false)->assertJsonCount(30, 'data.widgets.merchants.registrations');
        $this->assertLessThan(262144, strlen($r->getContent()));
        foreach (['wallets', 'ledger_transactions', 'carrier_operations', 'shipments', 'platform_alert_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->browser('GET', '/api/v1/admin/dashboard/alerts')->assertOk()->assertJsonPath('data.alerts', []);
        $this->browser('GET', '/api/v1/admin/dashboard/approvals')->assertOk();
    }

    public function test_every_endpoint_requires_a_current_browser_and_recent_mfa_and_rejects_tokens(): void
    {
        $a = $this->platformUser();
        $merchant = $this->organization($a);
        $token = $this->token($a, $merchant, ['*', 'platform.overview']);
        foreach (['', '/health', '/settings', '/approvals', '/alerts', '/activity'] as $path) {
            $this->bearer($token, 'GET', '/api/v1/admin/dashboard'.$path)->assertStatus(400)->assertJsonPath('data', null);
        }
        $this->bearer($token, 'GET', '/api/v1/admin/dashboard', [], ['Origin' => 'http://localhost:8080'])->assertForbidden();
        $this->login($a);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertForbidden();
        $this->jar = [];
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk();
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['mfa_verified_at' => now()->subMinutes(16)]);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_source_permissions_omit_finance_and_user_data_for_operations_and_support(): void
    {
        foreach (['platform.operations', 'platform.support', 'platform.finance'] as $role) {
            $a = $this->platformUser($role);
            $this->adminLogin($a);
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $r = $this->browser('GET', '/api/v1/admin/dashboard')->assertOk();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }
            $widgets = $r->json('data.widgets');
            $this->assertArrayNotHasKey('users', $widgets);
            if ($role !== 'platform.finance') {
                $this->assertArrayNotHasKey('finance', $widgets);
                foreach ($queries as $q) {
                    $this->assertFalse((bool) preg_match('/from "(?:wallets|wallet_balances|financial_reconciliation_runs|financial_approvals)"/', $q['query']));
                }
            } else {
                $this->assertArrayHasKey('finance', $widgets);
                $this->assertArrayNotHasKey('shipping', $widgets);
            }
            $this->browser('GET', '/api/v1/admin/dashboard/settings')->assertForbidden();
        }
    }

    public function test_aggregate_read_does_not_grant_private_access_or_override_merchant_tokens(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.widgets.merchants.total', 1);
        $this->browser('GET', "/api/v1/admin/dashboard/merchants/$m?section=merchant&access_grant=".Str::uuid())->assertForbidden();
        $this->browser('GET', "/api/v1/admin/dashboard?merchant_id=$m")->assertUnprocessable();
        $this->bearer($this->token($owner, $m), 'GET', '/api/v1/admin/dashboard')->assertStatus(400)->assertJsonPath('data', null);
        $this->assertDatabaseCount('platform_access_grants', 0);
    }

    public function test_exact_target_grants_expiry_revocation_and_financial_policy_are_preserved(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $other = $this->organization($owner);
        $a = $this->platformUser();
        $b = $this->platformUser();
        $g = $this->grant($a, $b, $m, ['platform.merchant.view']);
        $url = "/api/v1/admin/dashboard/merchants/$m?section=merchant&access_grant=$g";
        $this->browser('GET', $url)->assertOk()->assertJsonPath('data.merchant.id', $m)->assertJsonPath('data.memberships.0.role', 'merchant.owner');
        $this->browser('GET', str_replace($m, $other, $url))->assertForbidden();
        $this->browser('GET', str_replace('section=merchant', 'section=finance', $url))->assertForbidden();
        DB::table('platform_access_grants')->where('id', $g)->update(['revoked_at' => now()]);
        $this->browser('GET', $url)->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.merchant_accessed', 'merchant_id' => $m]);
    }

    public function test_alert_read_state_is_actor_scoped_idempotent_immutable_and_not_resolution(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        DB::table('merchants')->where('id', $m)->update(['verification_status' => 'pending']);
        $a = $this->platformUser();
        $this->adminLogin($a);
        $alerts = $this->browser('GET', '/api/v1/admin/dashboard/alerts')->assertOk()->json('data.alerts');
        $id = $alerts[0]['id'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
        $this->assertFalse($alerts[0]['read']);
        $this->assertStringNotContainsString($m, json_encode($alerts));
        $this->browser('POST', "/api/v1/admin/dashboard/alerts/$id/read", [], false)->assertStatus(419);
        $this->browser('POST', "/api/v1/admin/dashboard/alerts/$id/read")->assertOk()->assertJsonPath('data.resolved', false);
        $this->browser('POST', "/api/v1/admin/dashboard/alerts/$id/read")->assertOk();
        $this->assertDatabaseCount('platform_alert_receipts', 1);
        $this->browser('GET', '/api/v1/admin/dashboard/alerts')->assertOk()->assertJsonPath('data.alerts.0.read', true);
        $this->assertDatabaseHas('merchants', ['id' => $m, 'verification_status' => 'pending']);
        $b = $this->platformUser();
        $this->adminLogin($b);
        $this->browser('GET', '/api/v1/admin/dashboard/alerts')->assertOk()->assertJsonPath('data.alerts.0.read', false);
        DB::beginTransaction();
        try {
            DB::table('platform_alert_receipts')->update(['alert_key' => str_repeat('a', 64)]);
            $this->fail('Receipt mutated');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->getCode());
        } finally {
            DB::rollBack();
        }
    }

    public function test_finance_currency_counts_never_disclose_global_money_or_profit(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $ledger = app(Ledger::class);
        $sar = $ledger->provision($m);
        $ledger->command($m, $sar, 'synthetic', '12345', 'scope-synthetic', 'scope-synthetic', 'test');
        DB::table('financial_currencies')->insert(['code' => 'USD', 'exponent' => 2, 'enabled' => true]);
        $ledger->provision($m, 'USD');
        $a = $this->platformUser('platform.finance');
        $this->adminLogin($a);
        $r = $this->browser('GET', '/api/v1/admin/dashboard?section=finance')->assertOk()->assertJsonPath('data.widgets.finance.currencies.0.currency', 'SAR')->assertJsonPath('data.widgets.finance.currencies.1.currency', 'USD')->assertJsonPath('data.widgets.finance.currencies.0.wallets', 1);
        foreach (['12345', 'posted', 'reserved', 'profit', 'private_snapshot', $m] as $s) {
            $this->assertStringNotContainsString($s, $r->getContent());
        }
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_activity_redacts_private_audit_and_paginates_stably(): void
    {
        $a = $this->platformUser();
        $u = $this->user();
        $m = $this->organization($u);
        $this->adminLogin($a);
        for ($n = 0; $n < 28; $n++) {
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'action' => 'platform.synthetic', 'metadata' => json_encode(['reason' => 'never disclose this private reason']), 'created_at' => now()->startOfDay()]);
        }
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'action' => 'private.merchant', 'metadata' => '{}', 'created_at' => now()]);
        $r = $this->browser('GET', '/api/v1/admin/dashboard/activity?action=platform.synthetic')->assertOk()->assertJsonCount(25, 'data.events');
        $data = $r->json('data');
        $next = $this->browser('GET', '/api/v1/admin/dashboard/activity?action=platform.synthetic&cursor='.urlencode($data['next_cursor']))->assertOk()->assertJsonCount(3, 'data.events')->assertJsonPath('data.next_cursor', null)->json('data.events');
        $this->assertCount(28, array_unique(array_merge(array_column($data['events'], 'id'), array_column($next, 'id'))));
        foreach (['metadata', 'never disclose', 'private.merchant', $m] as $s) {
            $this->assertStringNotContainsString($s, $r->getContent());
        }
        $this->browser('GET', '/api/v1/admin/dashboard/activity?cursor=invalid')->assertUnprocessable();
    }

    public function test_merchant_directory_literal_search_and_independent_status_filters(): void
    {
        $u = $this->user();
        $a = $this->organization($u, 'Literal_100% merchant');
        $this->organization($u, 'LiteralXX100AB merchant');
        $admin = $this->platformUser();
        $this->adminLogin($admin);
        $this->browser('GET', '/api/v1/admin/merchants?search=Literal_100%25')->assertOk()->assertJsonCount(1, 'data.merchants')->assertJsonPath('data.merchants.0.id', $a);
        $this->browser('GET', '/api/v1/admin/merchants?status=suspended')->assertOk()->assertJsonCount(0, 'data.merchants');
        $this->browser('GET', '/api/v1/admin/merchants?status=pending')->assertUnprocessable();
    }

    public function test_periods_and_response_bounds_do_not_invent_pending_accounts(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $date = now('UTC')->toDateString();
        $this->browser('GET', "/api/v1/admin/dashboard?range=custom&from=$date&to=$date")->assertOk()->assertJsonCount(1, 'data.widgets.merchants.registrations')->assertJsonPath('data.widgets.merchants.pending_accounts_supported', false);
        $this->browser('GET', '/api/v1/admin/dashboard?range=custom&from=2020-01-01&to=2026-01-01')->assertUnprocessable();
        $this->browser('GET', '/api/v1/admin/dashboard?section=unknown')->assertUnprocessable();
        $r = $this->browser('GET', '/api/v1/admin/dashboard?range=custom&from='.now('UTC')->subDays(365)->toDateString().'&to='.$date)->assertOk();
        $this->assertLessThan(262144, strlen($r->getContent()));
    }

    public function test_current_session_mfa_role_and_grant_changes_never_reuse_cached_data(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk();
        DB::table('platform_role_assignments')->where('user_id', $a->id)->delete();
        $this->browser('GET', '/api/v1/admin/dashboard')->assertForbidden();
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['revoked_at' => now()]);
        $this->browser('GET', '/api/v1/admin/dashboard')->assertUnauthorized();
    }

    public function test_settings_and_health_omit_connection_values_payloads_and_debugging(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $r = $this->browser('GET', '/api/v1/admin/dashboard/settings')->assertOk()->assertJsonPath('data.editable', false)->assertJsonPath('data.gates.carrier_requests', false)->assertJsonPath('data.bulk_limits.max_rows', 10000);
        $this->browser('GET', '/api/v1/admin/dashboard/health')->assertOk()->assertJsonPath('data.production_readiness', 'not_certified')->assertJsonPath('data.scheduler', 'not_independently_observable');
        foreach (['DB_PASSWORD', 'APP_KEY', 'exception', 'stack_trace', 'credentials_ciphertext'] as $s) {
            $this->assertStringNotContainsString($s, $r->getContent());
        }
        $this->browser('POST', '/api/v1/admin/dashboard/settings', ['carrier_requests' => true])->assertStatus(405);
    }

    public function test_expired_independent_grants_and_approver_credential_changes_fail_closed(): void
    {
        foreach (['expiry', 'approver_mfa', 'approver_role'] as $kind) {
            $m = $this->organization($this->user());
            $a = $this->platformUser();
            $b = $this->platformUser();
            $g = $this->grant($a, $b, $m, ['platform.merchant.view']);
            $this->browser('GET', '/api/v1/admin/dashboard/grants')->assertOk()->assertJsonCount(1, 'data.grants');
            if ($kind === 'expiry') {
                DB::table('platform_access_grants')->where('id', $g)->update(['created_at' => now()->subMinutes(10), 'expires_at' => now()->subMinute()]);
            }
            if ($kind === 'approver_mfa') {
                DB::table('user_mfa_credentials')->where('user_id', $b->id)->increment('version');
            }
            if ($kind === 'approver_role') {
                DB::table('platform_role_assignments')->where('user_id', $b->id)->delete();
            }
            $this->browser('GET', "/api/v1/admin/dashboard/merchants/$m?section=merchant&access_grant=$g")->assertForbidden();
        }
    }

    public function test_aggregate_capabilities_cannot_be_requested_as_private_grants(): void
    {
        $m = $this->organization($this->user());
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('POST', '/api/v1/admin/access-requests', ['merchant_id' => $m, 'capabilities' => ['platform.metrics.finance'], 'reason' => 'Synthetic aggregate scope cannot impersonate'])->assertUnprocessable();
        $this->assertDatabaseCount('platform_access_grants', 0);
    }

    public function test_alert_read_migration_rollback_and_schema_drift_preserve_prior_modules(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE platform_alert_receipts DISABLE TRIGGER platform_receipt_history');
            $this->assertContains('platform_alert_receipts.platform_receipt_history', app(SchemaIntegrity::class)->failures());
        } finally {
            DB::rollBack();
        }
        $migration = require database_path('migrations/2026_10_25_100000_create_platform_overview.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $a->id]);
        $this->assertDatabaseCount('shipments', 0);
        $migration->up();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }
}
