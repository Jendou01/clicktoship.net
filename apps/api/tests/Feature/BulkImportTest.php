<?php

namespace Tests\Feature;

use App\Modules\Bulk\Application\Processor;
use App\Modules\Bulk\Domain\Columns;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BulkTestCase;

final class BulkImportTest extends BulkTestCase
{
    use DatabaseMigrations;

    public function test_mixed_rows_mapping_pricing_and_authorized_drafts_only(): void
    {
        $f = $this->bulkFixture();
        $good = $this->csvRow($f['f']['service']);
        $bad = array_replace($good, ['weight_grams' => '-1']);
        $b = $this->uploadTo($f, $this->csv([$good, $bad]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->assertSame(1, $this->bulkView($f, $b)['counts']['valid']);
        $this->assertSame(1, $this->bulkView($f, $b)['counts']['invalid']);
        $this->act($f, $b, 'pricing')->assertOk();
        $this->drain($f['m'], $b);
        $v = $this->bulkView($f, $b);
        $this->assertSame(1, $v['counts']['priced']);
        $this->assertSame('2100', $v['totals_minor']['SAR']);
        $this->assertStringNotContainsString('carrier_cost', json_encode($v));
        $this->assertStringNotContainsString('margin', json_encode($v));
        $this->act($f, $b, 'prepare')->assertOk();
        $this->drain($f['m'], $b);
        $v = $this->bulkView($f, $b);
        $this->assertSame('partial', $v['batch']['state']);
        $this->assertSame(1, $v['counts']['prepared']);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipments', ['state' => 'draft', 'financial_state' => 'none']);
        foreach (['balance_holds', 'ledger_transactions', 'shipment_submissions', 'carrier_operations', 'pickups', 'pricing_quote_acceptances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(app(Processor::class)->tick($f['m'], DB::table('bulk_work')->where('phase', 'preparing')->value('id')));
        $this->assertDatabaseCount('shipments', 1);
    }

    public function test_duplicates_require_explicit_decisions_and_keep_creates_separate_drafts(): void
    {
        $f = $this->bulkFixture();
        $row = $this->csvRow($f['f']['service']);
        $b = $this->uploadTo($f, $this->csv([$row, $row]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->assertSame(1, $this->bulkView($f, $b)['counts']['duplicates']);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertConflict();
        $rowid = DB::table('bulk_validations')->whereNotNull('duplicate_of')->value('row_id');
        $this->act($f, $b, 'rows/'.$rowid.'/decision', ['decision' => 'keep', 'reason' => 'Separate legitimate order'])->assertOk();
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $this->drain($f['m'], $b);
        $this->assertDatabaseCount('shipments', 2);
        $this->assertSame('completed', $this->bulkView($f, $b)['batch']['state']);
        $again = $this->uploadTo($f, $this->csv([$row, $row]));
        $this->drain($f['m'], $again);
        $this->mapRows($f, $again);
        $v = $this->bulkView($f, $again);
        $this->assertSame($b, $v['batch']['repeat_of']);
        $this->assertSame(2, $v['counts']['duplicates']);
    }

    public function test_arabic_aliases_unicode_digits_and_mixed_names_use_shipping_validation(): void
    {
        $f = $this->bulkFixture();
        $row = array_replace($this->csvRow($f['f']['service']), ['destination_name' => 'أحمد Smith', 'weight_grams' => '١٠٠٠']);
        $headers = array_map(fn ($k) => Columns::labels()[$k][1], array_keys($row));
        $b = $this->uploadTo($f, "\xEF\xBB\xBF".$this->csv([$row], $headers));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->assertSame(1, $this->bulkView($f, $b)['counts']['valid']);
        $draft = json_decode(Crypt::decryptString(DB::table('bulk_validations')->where('batch_id', $b)->value('draft')), true);
        $this->assertSame('أحمد Smith', $draft['destination']['name']);
        $this->assertSame(1000, $draft['packages'][0]['weight_grams']);
    }

    public function test_international_customs_multiple_packages_and_invalid_cod(): void
    {
        $f = $this->bulkFixture();
        $row = $this->csvRow($f['f']['service']);
        $row['destination_country'] = 'AE';
        $row['packages_json'] = json_encode([['weight_grams' => 100, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100], ['weight_grams' => 200, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]]);
        $row['goods_origin_country'] = 'AE';
        $row['items_json'] = json_encode([['description' => 'Synthetic item', 'quantity' => 1, 'value_minor' => '100', 'country' => 'AE', 'hs_code' => '123456']]);
        $row['cod_minor'] = '0';
        $bad = array_replace($row, ['items_json' => '[]', 'cod_minor' => '10']);
        $b = $this->uploadTo($f, $this->csv([$row, $bad]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $v = $this->bulkView($f, $b);
        $this->assertSame(1, $v['counts']['valid']);
        $this->assertSame(1, $v['counts']['invalid']);
        $this->act($f, $b, 'pricing')->assertOk();
        $this->drain($f['m'], $b);
        $this->assertSame(0, $this->bulkView($f, $b)['counts']['priced']);
        $this->assertDatabaseHas('bulk_prices', ['batch_id' => $b, 'error_code' => 'PRICING_UNAVAILABLE']);
        $this->act($f, $b, 'prepare')->assertUnprocessable();
    }

    public function test_1000_rows_resume_stale_leases_and_progress_without_loading_all_rows(): void
    {
        config(['bulk.chunk_rows' => 73]);
        $f = $this->bulkFixture();
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = array_replace($this->csvRow($f['f']['service']), ['description' => 'Synthetic '.$i]);
        }
        $b = $this->uploadTo($f, $this->csv($rows));
        $this->drain($f['m'], $b);
        $this->assertSame(1000, $this->bulkView($f, $b)['batch']['row_count']);
        $this->mapRows($f, $b);
        $this->assertSame(1000, $this->bulkView($f, $b)['counts']['valid']);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $w = DB::table('bulk_work')->where('batch_id', $b)->whereNull('completed_at')->first();
        app(Processor::class)->tick($f['m'], $w->id);
        $this->assertDatabaseCount('shipments', 73);
        DB::table('bulk_work')->where('id', $w->id)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->subMinute()]);
        $this->drain($f['m'], $b);
        $this->assertDatabaseCount('shipments', 1000);
        $this->assertSame('completed', $this->bulkView($f, $b)['batch']['state']);
    }

    public function test_cancellation_and_revoked_credentials_stop_before_creating_drafts(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $this->act($f, $b, 'cancel')->assertOk();
        $this->drain($f['m'], $b);
        $this->assertDatabaseCount('shipments', 0);
        $b = $this->uploadTo($f, $this->csv([array_replace($this->csvRow($f['f']['service']), ['description' => 'Other'])]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $f['u']->tokens()->delete();
        $this->drain($f['m'], $b);
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'blocked']);
    }

    public function test_idor_scope_changes_and_immutable_tenant_relationships(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $other = $this->user();
        $m = $this->organization($other);
        $token = $this->token($other, $m);
        foreach (['', '/rows', '/events', '/errors', '/progress'] as $suffix) {
            $this->bearer($token, 'GET', "/api/v1/bulk/imports/$b$suffix")->assertNotFound();
        }
        $this->bearer($this->token($f['u'], $f['m'], ['bulk.read']), 'POST', "/api/v1/bulk/imports/$b/prepare", ['version' => 4, 'idempotency_key' => 'scope-denied', 'include_unpriced' => true])->assertForbidden();
        $this->expectException(QueryException::class);
        DB::table('bulk_rows')->where('batch_id', $b)->update(['merchant_id' => $m]);
    }

    public function test_command_idempotency_replays_without_extra_work_and_conflicting_payload_fails(): void
    {
        $f = $this->bulkFixture();
        $body = ['format' => 'csv', 'idempotency_key' => 'stable-initialize-command'];
        $first = $this->bearer($f['token'], 'POST', '/api/v1/bulk/imports', $body)->assertCreated()->json('data.batch.id');
        $again = $this->bearer($f['token'], 'POST', '/api/v1/bulk/imports', $body)->assertCreated()->json('data.batch.id');
        $this->assertSame($first, $again);
        $this->assertDatabaseCount('bulk_batches', 1);
        $this->bearer($f['token'], 'POST', '/api/v1/bulk/imports', array_replace($body, ['format' => 'xlsx']))->assertConflict();
        $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]), 'csv', $first);
        $this->drain($f['m'], $first);
        $v = $this->bulkView($f, $first);
        $map = ['mapping' => $v['mapping'], 'version' => $v['batch']['version'], 'idempotency_key' => 'stable-map-command'];
        $this->bearer($f['token'], 'POST', "/api/v1/bulk/imports/$first/mapping", $map)->assertOk();
        $this->drain($f['m'], $first);
        $this->bearer($f['token'], 'POST', "/api/v1/bulk/imports/$first/mapping", $map)->assertOk();
        $this->assertDatabaseCount('bulk_mappings', 1);
        $this->assertSame(1, DB::table('bulk_work')->where('phase', 'validating')->count());
    }

    public function test_scoped_tokens_must_also_have_shipping_and_pricing_permissions(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $v = $this->bulkView($f, $b);
        foreach ([['bulk.prepare', 'prepare'], ['bulk.price', 'pricing']] as [$scope,$action]) {
            $token = $this->token($f['u'], $f['m'], [$scope, 'bulk.read']);
            $this->bearer($token, 'POST', "/api/v1/bulk/imports/$b/$action", ['version' => $v['batch']['version'], 'include_unpriced' => true, 'idempotency_key' => 'missing-module-scope'])->assertForbidden();
        }
        $this->assertDatabaseCount('shipments', 0);
        $this->assertDatabaseCount('bulk_approvals', 0);
    }

    public function test_partial_commit_survives_membership_suspension_and_recovery_is_bounded(): void
    {
        config(['bulk.chunk_rows' => 1]);
        $f = $this->bulkFixture();
        $rows = [$this->csvRow($f['f']['service']), array_replace($this->csvRow($f['f']['service']), ['description' => 'second legitimate parcel'])];
        $b = $this->uploadTo($f, $this->csv($rows));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $w = DB::table('bulk_work')->where('batch_id', $b)->whereNull('completed_at')->first();
        app(Processor::class)->tick($f['m'], $w->id);
        $this->assertDatabaseCount('shipments', 1);
        DB::table('merchant_users')->where('merchant_id', $f['m'])->where('user_id', $f['u']->id)->update(['status' => 'suspended']);
        $this->drain($f['m'], $b);
        $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'blocked']);
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseCount('bulk_drafts', 1);
    }

    public function test_repeated_process_failures_stop_at_recovery_limit(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $w = DB::table('bulk_work')->where('batch_id', $b)->whereNull('completed_at')->first();
        DB::unprepared("CREATE OR REPLACE FUNCTION bulk_test_crash() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic process failure'; END $$; CREATE TRIGGER bulk_test_crash BEFORE INSERT ON bulk_rows FOR EACH ROW EXECUTE FUNCTION bulk_test_crash();");
        try {
            for ($i = 0; $i < 5; $i++) {
                DB::table('bulk_work')->where('id', $w->id)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->subMinute()]);
                try {
                    app(Processor::class)->tick($f['m'], $w->id);
                    $this->fail('Synthetic database failure did not fire');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('synthetic process failure', $e->getMessage());
                }
            }
            DB::table('bulk_work')->where('id', $w->id)->update(['lease_until' => now()->subMinute()]);
            $this->assertFalse(app(Processor::class)->tick($f['m'], $w->id));
            $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'failed', 'failure_code' => 'RECOVERY_LIMIT']);
            $this->assertDatabaseCount('bulk_rows', 0);
        } finally {
            DB::unprepared('DROP TRIGGER bulk_test_crash ON bulk_rows; DROP FUNCTION bulk_test_crash();');
        }
    }

    public function test_duplicate_detection_never_reveals_matching_rows_in_another_merchant(): void
    {
        $f = $this->bulkFixture();
        $csv = $this->csv([$this->csvRow($f['f']['service'])]);
        $b = $this->uploadTo($f, $csv);
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $u = $this->user();
        $m = $this->organization($u);
        $other = ['u' => $u, 'm' => $m, 'f' => $f['f'], 'token' => $this->token($u, $m)];
        $second = $this->uploadTo($other, $csv);
        $this->drain($m, $second);
        $this->mapRows($other, $second);
        $v = $this->bulkView($other, $second);
        $this->assertNull($v['batch']['repeat_of']);
        $this->assertSame(0, $v['counts']['duplicates']);
    }

    public function test_read_only_members_and_platform_roles_do_not_gain_import_execution(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $this->drain($f['m'], $b);
        $viewer = $this->user();
        $this->bearer($f['token'], 'POST', '/api/v1/team', ['email' => $viewer->email, 'role' => 'merchant.viewer'])->assertCreated();
        $token = $this->token($viewer, $f['m']);
        $this->bearer($token, 'GET', "/api/v1/bulk/imports/$b")->assertOk();
        $this->bearer($token, 'POST', "/api/v1/bulk/imports/$b/mapping", ['version' => 3, 'mapping' => ['0' => 'currency'], 'idempotency_key' => 'viewer-no-write'])->assertForbidden();
        $admin = $this->platformUser();
        $this->bearer($this->token($admin, $f['m']), 'GET', "/api/v1/bulk/imports/$b")->assertForbidden();
        $this->adminLogin($admin);
        $this->resetHttpState();
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $f['m']])->assertNotFound();
        $this->resetHttpState();
        $this->call('GET', "/api/v1/bulk/imports/$b", [], $this->jar, [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:8080', 'HTTP_X_MERCHANT_ID' => $f['m'], 'REMOTE_ADDR' => $this->ip])->assertConflict()->assertJsonPath('data', null);
    }

    public function test_oversize_upload_and_retained_storage_quota_fail_without_file_records(): void
    {
        $f = $this->bulkFixture();
        $b = $this->bearer($f['token'], 'POST', '/api/v1/bulk/imports', ['format' => 'csv', 'idempotency_key' => 'oversize-initialize'])->assertCreated()->json('data.batch.id');
        config(['bulk.max_bytes' => 10]);
        $this->resetHttpState();
        $this->call('POST', "/api/v1/bulk/imports/$b/file", ['version' => 1, 'idempotency_key' => 'oversize-upload'], [], ['file' => UploadedFile::fake()->createWithContent('large.csv', str_repeat('x', 100))], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$f['token'], 'REMOTE_ADDR' => $this->ip])->assertUnprocessable();
        $this->assertDatabaseCount('bulk_files', 0);
        config(['bulk.max_bytes' => 10485760, 'bulk.max_retained_bytes' => 10]);
        $this->resetHttpState();
        $this->call('POST', "/api/v1/bulk/imports/$b/file", ['version' => 1, 'idempotency_key' => 'quota-upload'], [], ['file' => UploadedFile::fake()->createWithContent('large.csv', str_repeat('x', 100))], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$f['token'], 'REMOTE_ADDR' => $this->ip])->assertStatus(429);
        $this->assertDatabaseCount('bulk_files', 0);
    }

    public function test_unknown_service_is_invalid_in_both_single_and_bulk_readiness(): void
    {
        $f = $this->bulkFixture();
        $row = $this->csvRow((string) Str::uuid());
        $b = $this->uploadTo($f, $this->csv([$row]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->assertSame(1, $this->bulkView($f, $b)['counts']['invalid']);
        $this->assertStringContainsString('service_id', DB::table('bulk_validations')->where('batch_id', $b)->value('errors'));
        $draft = Columns::draft(array_values($row), array_keys($row));
        $this->bearer($f['token'], 'POST', '/api/v1/shipments', ['draft' => $draft, 'idempotency_key' => 'unknown-service-draft'])->assertCreated()->assertJsonPath('data.validation.valid', false);
        $this->assertDatabaseCount('balance_holds', 0);
    }
}
