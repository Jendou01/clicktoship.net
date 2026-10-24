<?php

namespace Tests\Feature;

use App\Modules\Customs\Application\DocumentWork;
use App\Modules\Customs\Jobs\GenerateDocument;
use App\Modules\Shipping\Domain\DocumentScanner;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CustomsTestCase;

final class CustomsIntegrityTest extends CustomsTestCase
{
    use DatabaseMigrations;

    public function test_rollback_reapply_preserves_previous_shipping_and_role_ids(): void
    {
        $f = $this->customsFixture();
        $role = DB::table('roles')->where('code', 'merchant.owner')->value('id');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id']]);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($role, DB::table('roles')->where('code', 'merchant.owner')->value('id'));
    }

    public function test_immutable_versions_and_currency_constraints(): void
    {
        $f = $this->customsFixture();
        $this->saveCustoms($f)->assertOk();
        foreach (['customs_versions', 'customs_items', 'customs_events'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('Immutable history deleted');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $m = $this->organization($this->user());
        $row = (array) DB::table('customs_items')->first();
        $row['id'] = (string) Str::uuid();
        $row['merchant_id'] = $m;
        $this->expectException(QueryException::class);
        DB::table('customs_items')->insert($row);
    }

    public function test_crash_recovery_is_durable_and_generation_deduplicates(): void
    {
        Storage::fake('local');
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        DB::table('customs_document_work')->where('id', $doc['work_id'])->update(['state' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->subSecond(), 'attempts' => 1]);
        Queue::fake();
        $this->assertSame(1, app(DocumentWork::class)->recover());
        Queue::assertPushed(GenerateDocument::class);
        $this->generate($f, $doc);
        $this->generate($f, $doc);
        $this->assertDatabaseCount('customs_document_results', 1);
        $this->assertDatabaseCount('customs_documents', 1);
    }

    public function test_tampered_ciphertext_and_scan_time_revocation_are_denied(): void
    {
        Storage::fake('local');
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $this->generate($f, $doc);
        $file = DB::table('customs_document_results')->first();
        Storage::disk('local')->put($file->object_key, 'tampered');
        $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertStatus(503);
        $pack = $this->docRequest($f, $s, 'packing_list')->assertStatus(202)->json('data');
        app()->instance(DocumentScanner::class, new class($f['u']->id) implements DocumentScanner
        {
            public function __construct(private string $user) {}

            public function clean(string $content): bool
            {
                DB::table('personal_access_tokens')->where('tokenable_id', $this->user)->delete();

                return true;
            }
        });
        app(DocumentWork::class)->tick($f['m'], $pack['work_id']);
        $this->assertDatabaseHas('customs_document_work', ['id' => $pack['work_id'], 'state' => 'blocked']);
        $this->assertDatabaseCount('customs_document_results', 1);
    }

    public function test_stale_document_job_cannot_render_a_revised_shipment(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $this->bearer($f['token'], 'PATCH', "/api/v1/shipments/{$f['s']['id']}", ['draft' => $s['draft'], 'version' => $s['shipment']['version'], 'idempotency_key' => 'revise-before-document'])->assertOk();
        $this->generate($f, $doc);
        $this->assertDatabaseHas('customs_document_work', ['id' => $doc['work_id'], 'state' => 'blocked']);
        $this->assertDatabaseCount('customs_document_results', 0);
    }

    public function test_html_and_url_text_are_not_executed_in_pdf(): void
    {
        $f = $this->customsFixture();
        $c = $f['customs'];
        $c['notes'] = '<script>alert(1)</script> http://169.254.169.254/';
        $s = $this->saveCustoms($f, ['declaration' => $c])->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $this->generate($f, $doc);
        $response = $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertOk();
        $this->assertFalse(str_contains($response->getContent(), '/JavaScript'), 'No active PDF scripts');
        $this->assertFalse(str_contains($response->getContent(), '/URI'), 'No PDF URL annotations');
        Http::assertNothingSent();
    }

    public function test_new_declaration_creates_distinct_document_version_and_old_pdf_stays_historical(): void
    {
        Storage::fake('local');
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $first = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $this->generate($f, $first);
        $c = $f['customs'];
        $c['notes'] = 'A revised synthetic declaration';
        $s2 = $this->saveCustoms($f, ['version' => $s['shipment']['version'], 'declaration_version' => 1, 'declaration' => $c, 'idempotency_key' => 'revise-declaration-v2'])->assertOk()->json('data');
        $second = $this->docRequest($f, $s2)->assertStatus(202)->json('data');
        $this->generate($f, $second);
        $this->assertNotSame($first['document_id'], $second['document_id']);
        $this->assertDatabaseCount('customs_documents', 2);
        $this->assertDatabaseCount('customs_versions', 2);
        $this->bearer($f['token'], 'GET', "/api/v1/customs/shipments/{$f['s']['id']}?declaration_version=1")->assertOk()->assertJsonPath('data.historical_declaration.notes', 'Synthetic draft only');
        $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$first['document_id']}/download")->assertOk()->assertHeader('X-Document-Historical', 'true');
        $this->assertSame(2, DB::table('customs_documents')->distinct()->count('number'));
    }

    public function test_schema_drift_and_runtime_downgrade_preserve_history(): void
    {
        $f = $this->customsFixture();
        $this->saveCustoms($f)->assertOk();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        DB::statement('ALTER TABLE customs_items DISABLE TRIGGER customs_item_totals');
        $this->assertContains('customs_items.customs_item_totals', app(SchemaIntegrity::class)->failures());
        DB::statement('ALTER TABLE customs_items ENABLE TRIGGER customs_item_totals');
        $m = require database_path('migrations/2026_10_24_170000_create_international_customs.php');
        $env = app()->environment();
        app()->instance('env', 'production');
        try {
            $m->down();
            $this->fail('History was downgraded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        } finally {
            app()->instance('env', $env);
        }
    }
}
