<?php

namespace Tests\Feature;

use App\Modules\Analytics\Application\Authority;
use App\Modules\Analytics\Application\Definitions;
use App\Modules\Analytics\Application\Exports;
use App\Modules\Analytics\Application\Spreadsheet;
use App\Modules\Identity\Application\Audit;
use App\Modules\Shipping\Domain\DocumentScanner;
use App\Modules\WalletBilling\Application\Ledger;
use App\Support\SchemaIntegrity;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AnalyticsTestCase;

final class AnalyticsTest extends AnalyticsTestCase
{
    use DatabaseMigrations;

    public function test_catalog_is_source_permission_filtered_and_unsafe_dimensions_are_rejected(): void
    {
        $f = $this->analytics();
        $t = $this->token($f['u'], $f['m'], ['analytics.read', 'orders.read']);
        $this->bearer($t, 'GET', '/api/v1/analytics/catalog')->assertOk()->assertJsonCount(2, 'data.reports');
        $this->bearer($t, 'GET', '/api/v1/analytics/reports/wallet_transactions')->assertForbidden();
        foreach (['range=custom&from=2024-01-01&to=2026-01-01', 'columns=private_snapshot', 'limit=999999', 'service=bad', 'provider=evil'] as $q) {
            $this->browser('GET', '/api/v1/analytics/reports/shipment_summary?'.$q)->assertStatus(422);
        }
        $this->browser('GET', '/api/v1/analytics/reports/unknown')->assertNotFound();
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
    }

    public function test_utc_boundaries_grain_and_current_balances_are_explicit(): void
    {
        $f = $this->analytics();
        $this->travelTo(Carbon::parse('2026-10-25T12:00:00Z'));
        foreach (['2026-10-24T23:59:59Z', '2026-10-25T00:00:00Z', '2026-10-26T00:00:00Z'] as $at) {
            DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $f['m'], 'actor_id' => $f['u']->id, 'action' => 'synthetic.boundary', 'created_at' => $at]);
        }
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity?range=today&status=synthetic.boundary')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.filters.period.timezone', 'UTC');
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity?range=yesterday&grain=week&status=synthetic.boundary')->assertOk()->assertJsonPath('data.total', 1);
        $this->browser('GET', '/api/v1/analytics/overview?range=90d')->assertOk()->assertJsonPath('data.widgets.current_balances.historical_balance', 'unavailable');
    }

    public function test_report_rows_are_tenant_scoped_and_private_snapshots_never_selected(): void
    {
        $f = $this->analytics();
        $other = $this->organization($this->user());
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $other, 'action' => 'secret.other_merchant', 'metadata' => '{"secret":"private"}', 'created_at' => now()]);
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity')->assertOk()->assertDontSee('secret.other_merchant')->assertDontSee('metadata');
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity?merchant_id='.$other)->assertStatus(422);
        $this->browser('GET', '/api/v1/analytics/overview')->assertOk()->assertDontSee('private_snapshot');
    }

    public function test_export_without_storage_approval_is_audited_failed_without_artifact(): void
    {
        $this->analytics();
        $e = $this->export();
        $this->assertSame('failed', $e['state']);
        $this->assertSame('EXPORT_STORAGE_NOT_APPROVED', $e['error_code']);
        $this->browser('GET', "/api/v1/analytics/exports/{$e['id']}/download")->assertStatus(503);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('report_export_events', 3);
    }

    public function test_csv_export_is_encrypted_idempotent_owned_and_expiring(): void
    {
        $f = $this->analytics(true);
        Audit::record('synthetic.report', $f['u']->id, $f['m']);
        $e = $this->export();
        $again = $this->export();
        $this->assertSame($e['id'], $again['id']);
        $this->exportConflict();
        app(Exports::class)->tick($e['id']);
        app(Exports::class)->tick($e['id']);
        $row = DB::table('report_exports')->where('id', $e['id'])->first();
        $this->assertSame('ready', $row->state);
        $this->assertSame(1, $row->attempt);
        $this->assertStringNotContainsString('synthetic.report', Storage::disk('local')->get($row->artifact));
        $this->browser('GET', "/api/v1/analytics/exports/{$e['id']}/download")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('synthetic.report');
        $u = $this->user();
        $m = $this->organization($u);
        $this->login($u);
        $this->select($m);
        $this->browser('GET', "/api/v1/analytics/exports/{$e['id']}")->assertNotFound();
        $this->travel(16)->minutes();
        app(Exports::class)->recover();
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'expired']);
        Storage::disk('local')->assertMissing($row->artifact);
    }

    private function exportConflict(): void
    {
        $this->browser('POST', '/api/v1/analytics/exports', ['report' => 'merchant_activity', 'format' => 'xlsx', 'locale' => 'en', 'idempotency_key' => 'request-analytics-1'])->assertStatus(409);
    }

    public function test_permission_revocation_before_generation_prevents_retained_access(): void
    {
        $f = $this->analytics(true);
        $e = $this->export();
        DB::table('merchant_role_assignments')->where('merchant_id', $f['m'])->delete();
        app(Exports::class)->tick($e['id']);
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'revoked']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_financial_export_requires_recent_mfa_and_rejects_bearer(): void
    {
        $f = $this->analytics(true);
        $this->browser('POST', '/api/v1/analytics/exports', ['report' => 'wallet_transactions', 'format' => 'csv', 'locale' => 'ar', 'idempotency_key' => 'financial-export-key'])->assertForbidden();
        $this->bearer($this->token($f['u'], $f['m']), 'POST', '/api/v1/analytics/exports', ['report' => 'wallet_transactions', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'financial-token-export'])->assertForbidden();
    }

    public function test_platform_counts_require_recent_mfa_and_never_private_money_or_tokens(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/analytics/overview')->assertOk()->assertJsonPath('data.financial_amounts', 'unavailable_global_disclosure_policy')->assertDontSee('private_snapshot');
        $this->browser('GET', '/api/v1/admin/analytics/reports/commerce')->assertOk()->assertJsonPath('data.widgets.commerce.external_enabled', false);
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['mfa_verified_at' => now()->subMinutes(16)]);
        $this->browser('GET', '/api/v1/admin/analytics/catalog')->assertForbidden();
    }

    public function test_formula_guards_preserve_signed_accounting_values_and_xlsx_is_inline_unicode(): void
    {
        $writer = new Spreadsheet;
        foreach (['=1', '+1', '-SUM(A1)', '@SUM(A1)', " \t=HYPERLINK(\"bad\")", "\u{FEFF}=1"] as $v) {
            $this->assertStringStartsWith("'", $writer->cell($v));
        }
        $this->assertSame('-12345678901234567890', $writer->cell('-12345678901234567890', true));
        $bytes = $writer->write(['text', 'delta'], [['text' => "=صيغ\x00", 'delta' => '-12345678901234567890']], 'xlsx', 'ar');
        $path = tempnam(sys_get_temp_dir(), 'report-test');
        file_put_contents($path, $bytes);
        $z = new \ZipArchive;
        $z->open($path);
        $xml = $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        unlink($path);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringNotContainsString("\x00", $xml);
        $this->assertStringContainsString('inlineStr', $xml);
        $this->assertStringContainsString('=صيغ', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('-12345678901234567890', $xml);
    }

    public function test_schema_and_audit_immutability_and_preserving_migration_round_trip(): void
    {
        $f = $this->analytics();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        DB::transaction(function () {
            DB::statement('DROP INDEX report_export_active_digest');
            $this->assertContains('index.report_export_active_digest', app(SchemaIntegrity::class)->failures());
            DB::statement("CREATE UNIQUE INDEX report_export_active_digest ON report_exports(actor_id,digest) WHERE state IN ('queued','processing')");
        });
        $migration = require database_path('migrations/2026_10_25_150000_create_analytics_exports.php');
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $f['u']->id]);
        $migration->up();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        $this->export();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('report_export_events')->delete());
    }

    public static function reports(): array
    {
        return array_map(fn ($id) => [$id], array_keys(Definitions::all()));
    }

    #[DataProvider('reports')]
    public function test_every_approved_source_executes_bounded_columns_without_sensitive_payloads(string $report): void
    {
        $this->analytics();
        $r = $this->browser('GET', '/api/v1/analytics/reports/'.$report)->assertOk()->assertJsonPath('data.report', $report);
        $this->assertLessThanOrEqual(25, count($r->json('data.rows')));
        $this->assertArrayNotHasKey('private_snapshot', $r->json('data'));
        $this->assertStringNotContainsString('payload_ciphertext', $r->getContent());
    }

    public function test_currency_separated_movements_are_exact_strings_and_never_revenue(): void
    {
        $f = $this->analytics();
        DB::table('financial_currencies')->insert(['code' => 'USD', 'exponent' => 2, 'enabled' => true]);
        $ledger = app(Ledger::class);
        foreach (['SAR', 'USD'] as $currency) {
            $wallet = $ledger->provision($f['m'], $currency);
            $ledger->command($f['m'], $wallet, 'synthetic', '900000000001', 'analytics-'.$currency, 'request-'.$currency, 'synthetic-test');
        }
        $r = $this->browser('GET', '/api/v1/analytics/reports/wallet_transactions')->assertOk();
        $this->assertCount(2, $r->json('data.movements'));
        foreach ($r->json('data.movements') as $row) {
            $this->assertSame('900000000001', $row['amount_minor']);
            $this->assertSame('synthetic', $row['kind']);
        }
        $this->browser('GET', '/api/v1/analytics/reports/wallet_transactions?currency=USD')->assertJsonPath('data.total', 1)->assertJsonPath('data.rows.0.currency', 'USD');
        $this->browser('GET', '/api/v1/analytics/overview')->assertJsonCount(2, 'data.widgets.current_balances.rows')->assertJsonPath('data.widgets.current_balances.rows.0.posted', '900000000001');
    }

    public function test_over_limit_export_fails_instead_of_silently_truncating(): void
    {
        $f = $this->analytics(true);
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'merchant_id' => $f['m'], 'action' => 'synthetic.large', 'created_at' => now()];
        }foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
        $e = $this->export();
        app(Exports::class)->tick($e['id']);
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'failed', 'error_code' => 'EXPORT_LIMIT_EXCEEDED']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_revocation_during_scanning_never_publishes_a_ready_artifact(): void
    {
        $f = $this->analytics(true);
        $e = $this->export();
        app()->instance(DocumentScanner::class, new class($f['u']->id) implements DocumentScanner
        {
            public function __construct(private string $user) {}

            public function clean(string $bytes): bool
            {
                DB::table('auth_sessions')->where('user_id', $this->user)->update(['revoked_at' => now()]);

                return true;
            }
        });
        app(Exports::class)->tick($e['id']);
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'revoked']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_backpressure_and_active_business_duplicates_do_not_duplicate_work(): void
    {
        $this->analytics(true);
        $this->export();
        $this->browser('POST', '/api/v1/analytics/exports', ['report' => 'merchant_activity', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'different-key-same-operation'])->assertStatus(409);
        $this->assertDatabaseCount('report_exports', 1);
        $this->export(['format' => 'xlsx', 'idempotency_key' => 'second-request-key']);
        $this->export(['locale' => 'ar', 'idempotency_key' => 'third-request-key']);
        $this->browser('POST', '/api/v1/analytics/exports', ['report' => 'shipment_summary', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'fourth-request-key'])->assertStatus(429);
    }

    public function test_expired_lease_recovery_and_known_staging_artifacts_are_cleaned(): void
    {
        $this->analytics(true);
        $e = $this->export();
        $lease = (string) Str::uuid();
        $path = 'analytics/'.$e['id'].'/'.$lease.'.enc';
        Storage::disk('local')->put($path, 'encrypted-orphan-test');
        DB::table('report_exports')->where('id', $e['id'])->update(['state' => 'processing', 'lease' => $lease, 'lease_until' => now()->subSecond(), 'artifact' => $path, 'attempt' => 1]);
        app(Exports::class)->event($e['id'], 'processing', $path);
        app(Exports::class)->tick($e['id']);
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'ready', 'attempt' => 2]);
        Storage::disk('local')->assertMissing($path);
        $this->travel(16)->minutes();
        app(Exports::class)->recover();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_platform_export_contains_counts_not_private_merchant_money(): void
    {
        $f = $this->analytics(true);
        $ledger = app(Ledger::class);
        $wallet = $ledger->provision($f['m']);
        $ledger->command($f['m'], $wallet, 'synthetic', '90981', 'synthetic-finance', 'synthetic-request', 'synthetic-test');
        $a = $this->platformUser();
        $this->adminLogin($a);
        $r = $this->browser('POST', '/api/v1/admin/analytics/exports', ['report' => 'finance', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'platform-financial-export'])->assertStatus(202);
        $id = $r->json('data.export.id');
        app(Exports::class)->tick($id);
        $this->assertDatabaseHas('report_exports', ['id' => $id, 'state' => 'ready']);
        $this->browser('GET', "/api/v1/admin/analytics/exports/$id/download")->assertOk()->assertDontSee('90981')->assertDontSee($f['m']);
    }

    public function test_current_origin_binding_prevents_new_session_reusing_an_old_download(): void
    {
        $f = $this->analytics(true);
        $e = $this->export();
        app(Exports::class)->tick($e['id']);
        $this->login($f['u']);
        $this->select($f['m']);
        $this->browser('GET', "/api/v1/analytics/exports/{$e['id']}/download")->assertForbidden();
    }

    public function test_post_without_csrf_and_tokens_without_export_ability_are_denied(): void
    {
        $f = $this->analytics(true);
        $this->browser('POST', '/api/v1/analytics/exports', [], false)->assertStatus(419);
        $token = $this->token($f['u'], $f['m'], ['analytics.read', 'merchant.view']);
        $this->bearer($token, 'POST', '/api/v1/analytics/exports', ['report' => 'merchant_activity', 'format' => 'csv', 'locale' => 'en', 'idempotency_key' => 'no-export-ability'])->assertForbidden();
    }

    public function test_platform_weekly_trend_preserves_registration_counts(): void
    {
        $this->analytics();
        $a = $this->platformUser();
        $this->adminLogin($a);
        Audit::record('merchant.status_changed', $a->id, DB::table('merchants')->value('id'), null, ['from' => 'suspended', 'to' => 'active']);
        $this->browser('GET', '/api/v1/admin/analytics/reports/merchants?range=today')->assertOk()->assertJsonPath('data.widgets.merchants.status_changes.0.status', 'active')->assertJsonPath('data.widgets.merchants.status_changes.0.count', 1);
        $daily = $this->browser('GET', '/api/v1/admin/analytics/reports/merchants?range=30d&grain=day')->assertOk()->json('data.widgets.merchants.registrations');
        $weekly = $this->browser('GET', '/api/v1/admin/analytics/reports/merchants?range=30d&grain=week')->assertOk()->json('data.widgets.merchants.registrations');
        $this->assertSame(array_sum(array_column($daily, 'count')), array_sum(array_column($weekly, 'count')));
        $this->assertLessThanOrEqual(6, count($weekly));
        foreach ($weekly as $row) {
            $this->assertSame(1, Carbon::parse($row['day'], 'UTC')->dayOfWeekIso);
        }
    }

    public static function authorityChanges(): array
    {
        return [['merchant'], ['account'], ['version'], ['session']];
    }

    #[DataProvider('authorityChanges')]
    public function test_queued_export_does_not_survive_current_authority_changes(string $change): void
    {
        $f = $this->analytics(true);
        $e = $this->export();
        match ($change) {
            'merchant' => DB::table('merchants')->where('id', $f['m'])->update(['status' => 'suspended']),
            'account' => DB::table('users')->where('id', $f['u']->id)->update(['suspended_at' => now()]),
            'version' => DB::table('users')->where('id', $f['u']->id)->increment('auth_version'),
            'session' => DB::table('auth_sessions')->where('user_id', $f['u']->id)->update(['expires_at' => now()->subSecond()]),
        };
        app(Exports::class)->tick($e['id']);
        $this->assertDatabaseHas('report_exports', ['id' => $e['id'], 'state' => 'revoked']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_platform_mfa_expiry_during_scan_blocks_publication(): void
    {
        $this->analytics(true);
        $a = $this->platformUser();
        $this->adminLogin($a);
        $id = $this->browser('POST', '/api/v1/admin/analytics/exports', ['report' => 'finance', 'format' => 'xlsx', 'locale' => 'ar', 'idempotency_key' => 'mfa-expiry-during-scan'])->assertStatus(202)->json('data.export.id');
        app()->instance(DocumentScanner::class, new class($a->id) implements DocumentScanner
        {
            public function __construct(private string $actor) {}

            public function clean(string $bytes): bool
            {
                DB::table('auth_sessions')->where('user_id', $this->actor)->update(['mfa_verified_at' => now()->subMinutes(16)]);

                return true;
            }
        });
        app(Exports::class)->tick($id);
        $this->assertDatabaseHas('report_exports', ['id' => $id, 'state' => 'revoked']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_export_read_snapshot_is_repeatable_and_statement_deadline_is_enforced(): void
    {
        $this->analytics(true);
        $e = $this->export();
        $row = DB::table('report_exports')->where('id', $e['id'])->first();
        app(Authority::class)->worker($row, function () {
            $this->assertSame('repeatable read', DB::selectOne('SHOW transaction_isolation')->transaction_isolation);
            $this->assertSame('5s', DB::selectOne('SHOW statement_timeout')->statement_timeout);
        }, true);
        $this->expectException(QueryException::class);
        app(Authority::class)->worker($row, fn () => DB::select('SELECT pg_sleep(5.1)'), true);
    }

    public function test_generation_bytes_do_not_change_after_source_read(): void
    {
        $f = $this->analytics(true);
        Audit::record('synthetic.before_generation', $f['u']->id, $f['m']);
        $e = $this->export();
        app()->instance(DocumentScanner::class, new class($f['m'], $f['u']->id) implements DocumentScanner
        {
            public function __construct(private string $merchant, private string $actor) {}

            public function clean(string $bytes): bool
            {
                Audit::record('synthetic.after_generation', $this->actor, $this->merchant);

                return true;
            }
        });
        app(Exports::class)->tick($e['id']);
        $this->browser('GET', "/api/v1/analytics/exports/{$e['id']}/download")->assertOk()->assertSee('synthetic.before_generation')->assertDontSee('synthetic.after_generation');
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity')->assertOk()->assertSee('synthetic.after_generation');
    }

    public function test_query_count_is_constant_and_keyset_pages_are_disjoint(): void
    {
        $f = $this->analytics();
        DB::enableQueryLog();
        $this->browser('GET', '/api/v1/analytics/reports/merchant_activity')->assertOk();
        $before = count(DB::getQueryLog());
        DB::disableQueryLog();
        $rows = [];
        for ($i = 0; $i < 2000; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'merchant_id' => $f['m'], 'action' => 'synthetic.scale', 'created_at' => now()];
        }foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $r = $this->browser('GET', '/api/v1/analytics/reports/merchant_activity')->assertOk()->assertJsonCount(25, 'data.rows');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($before + 1, $count);
        $this->assertLessThan(45, $count);
        $this->assertLessThan(262144, strlen($r->getContent()));
        $next = $this->browser('GET', '/api/v1/analytics/reports/merchant_activity?after='.$r->json('data.next_after'))->assertOk();
        $this->assertSame([], array_values(array_intersect(array_column($r->json('data.rows'), 'id'), array_column($next->json('data.rows'), 'id'))));
        fwrite(STDERR, json_encode(['analytics_rows' => 2000, 'queries' => $count, 'http_ms' => round((microtime(true) - $start) * 1000, 2)]));
    }
}
