<?php

namespace Tests\Feature;

use App\Modules\Carriers\Application\CustomsCarrierData;
use App\Modules\Customs\Application\DocumentWork;
use App\Modules\Customs\Application\Readiness;
use App\Modules\Customs\Domain\Countries;
use App\Modules\Shipping\Domain\DocumentScanner;
use App\Modules\Shipping\Infrastructure\DisabledDocumentScanner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CustomsTestCase;

final class CustomsTest extends CustomsTestCase
{
    use DatabaseMigrations;

    public function test_exact_item_arithmetic_revision_binding_and_no_clearance_or_financial_effect(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $this->assertSame('2468', $s['customs']['declared_total_minor']);
        $this->assertSame(2, $s['shipment']['revision']);
        $this->assertSame('2468', $s['draft']['declared_value_minor']);
        $this->assertSame('010121', $s['customs']['items'][0]['hs_code']);
        $this->assertTrue($s['readiness']['local_valid']);
        $this->assertFalse($s['readiness']['customs_cleared']);
        $this->assertFalse($s['readiness']['shipping_enabled']);
        $this->assertDatabaseCount('customs_versions', 1);
        $this->assertDatabaseCount('customs_items', 1);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
        $this->saveCustoms($f)->assertOk();
        $this->assertDatabaseCount('shipment_revisions', 2);
        $this->saveCustoms($f, ['idempotency_key' => 'different-stale-command'])->assertConflict();
    }

    public function test_invalid_values_precision_country_hs_and_packages_rejected(): void
    {
        $f = $this->customsFixture();
        $cases = [['unit_value_minor', '1.23'], ['unit_value_minor', '1e3'], ['unit_value_minor', '-1'], ['quantity', 0], ['quantity', 1.5], ['currency', 'USD'], ['origin_country', 'ZZ'], ['hs_code', 'ABCD'], ['hs_code', '123'], ['gross_grams', 600]];
        foreach ($cases as [$field,$value]) {
            $c = $f['customs'];
            $c['items'][0][$field] = $value;
            $this->saveCustoms($f, ['declaration' => $c])->assertUnprocessable();
        }
        $c = $f['customs'];
        $c['items'][0]['allocations'][0]['package'] = 2;
        $this->saveCustoms($f, ['declaration' => $c])->assertUnprocessable();
        $c = $f['customs'];
        $c['items'][0]['allocations'][0]['quantity'] = 1;
        $this->saveCustoms($f, ['declaration' => $c])->assertUnprocessable();
        $this->assertDatabaseCount('customs_declarations', 0);
    }

    public function test_local_readiness_is_distinct_from_verified_country_carrier_or_ddp(): void
    {
        $f = $this->customsFixture();
        $c = $f['customs'];
        $c['incoterm'] = 'DDP';
        $c['categories'] = ['batteries'];
        $s = $this->saveCustoms($f, ['declaration' => $c])->assertOk()->json('data');
        $this->assertFalse($s['readiness']['local_valid']);
        $this->assertContains('customs.restricted_acknowledgement', array_column($s['readiness']['findings'], 'field'));
        $this->assertContains('DDP_CONTRACT_UNVERIFIED', array_column($s['readiness']['findings'], 'code'));
        $this->assertFalse($s['readiness']['duties_calculated']);
        $domestic = $f['d'];
        $domestic['destination']['country'] = 'SA';
        $report = app(Readiness::class)->report($domestic);
        $this->assertFalse($report['international']);
        $this->assertContains('DOMESTIC_USE_STANDARD_SHIPPING', array_column($report['findings'], 'code'));
        $this->assertFalse(Countries::valid('EU'));
    }

    public function test_authorization_idor_token_scope_and_suspension(): void
    {
        $f = $this->customsFixture();
        $this->saveCustoms($f)->assertOk();
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m);
        $this->bearer($t, 'GET', "/api/v1/customs/shipments/{$f['s']['id']}")->assertNotFound();
        $limited = $this->token($f['u'], $f['m'], ['shipments.read']);
        $this->bearer($limited, 'GET', "/api/v1/customs/shipments/{$f['s']['id']}")->assertForbidden();
        $this->bearer($f['token'], 'GET', '/api/v1/customs/countries/AE')->assertOk()->assertJsonPath('data.enabled', false);
        DB::table('merchants')->where('id', $f['m'])->update(['status' => 'suspended']);
        $this->bearer($f['token'], 'GET', '/api/v1/customs/declarations')->assertForbidden();
    }

    public function test_invoice_packing_versions_secure_download_and_revocation(): void
    {
        Storage::fake('local');
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $again = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        $this->assertSame($doc['document_id'], $again['document_id']);
        $this->generate($f, $doc);
        $this->assertDatabaseHas('customs_document_work', ['id' => $doc['work_id'], 'state' => 'completed']);
        $download = $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Document-Draft', 'true')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $download->getContent());
        $this->assertGreaterThan(10000, strlen($download->getContent()));
        $result = DB::table('customs_document_results')->first();
        $this->assertStringNotContainsString('%PDF-', Storage::disk('local')->get($result->object_key));
        $packing = $this->docRequest($f, $s, 'packing_list', 'ar')->assertStatus(202)->json('data');
        $this->generate($f, $packing);
        $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$packing['document_id']}/download")->assertOk();
        $u = $this->user();
        $t = $this->token($u, $this->organization($u));
        $this->bearer($t, 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertNotFound();
        $this->bearer($f['token'], 'POST', "/api/v1/customs/documents/{$doc['document_id']}/revoke", ['idempotency_key' => 'revoke-draft-doc'])->assertOk();
        $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertConflict();
        $this->assertDatabaseCount('customs_documents', 2);
    }

    public function test_default_scanner_keeps_draft_pdf_quarantined(): void
    {
        Storage::fake('local');
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        app()->instance(DocumentScanner::class, new DisabledDocumentScanner);
        app(DocumentWork::class)->tick($f['m'], $doc['work_id']);
        $this->assertDatabaseHas('customs_document_results', ['document_id' => $doc['document_id'], 'state' => 'quarantined']);
        $this->bearer($f['token'], 'GET', "/api/v1/customs/documents/{$doc['document_id']}/download")->assertConflict();
    }

    public function test_revoked_credentials_and_changed_revision_fence_document_work(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $doc = $this->docRequest($f, $s)->assertStatus(202)->json('data');
        DB::table('personal_access_tokens')->where('tokenable_id', $f['u']->id)->delete();
        $this->generate($f, $doc);
        $this->assertDatabaseHas('customs_document_work', ['id' => $doc['work_id'], 'state' => 'blocked']);
        $this->assertDatabaseCount('customs_document_results', 0);
    }

    public function test_pricing_unavailable_is_explicit_and_no_duty_fabrication(): void
    {
        $f = $this->customsFixture();
        $this->saveCustoms($f)->assertOk();
        $this->bearer($f['token'], 'GET', "/api/v1/customs/shipments/{$f['s']['id']}/pricing")->assertOk()->assertJsonPath('data.state', 'unavailable')->assertJsonPath('data.duties_calculated', false);
        $this->assertDatabaseCount('ledger_transactions', 0);
    }

    public function test_unordered_lists_and_document_number_manipulation_are_rejected(): void
    {
        $f = $this->customsFixture();
        $c = $f['customs'];
        $c['items'] = ['arbitrary-key' => $c['items'][0]];
        $this->saveCustoms($f, ['declaration' => $c])->assertUnprocessable();
        $c = $f['customs'];
        $c['invoice_number'] = 'FAKE-OFFICIAL-123';
        $this->saveCustoms($f, ['declaration' => $c])->assertUnprocessable();
        $dto = app(CustomsCarrierData::class)->prepare($f['d']);
        $this->assertFalse($dto['external_enabled']);
        $this->assertFalse($dto['mapping_verified']);
        $this->assertArrayNotHasKey('hs_code', $dto['documented_request_fields']);
    }

    public function test_generic_shipping_editor_cannot_change_or_strip_customs_without_scope(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $token = $this->token($f['u'], $f['m'], ['shipments.write']);
        $body = ['draft' => $s['draft'], 'version' => $s['shipment']['version'], 'idempotency_key' => 'attempt-customs-editor'];
        $this->bearer($token, 'PATCH', "/api/v1/shipments/{$f['s']['id']}", $body)->assertForbidden();
        unset($body['draft']['customs']);
        $body['idempotency_key'] = 'attempt-strip-customs';
        $this->bearer($token, 'PATCH', "/api/v1/shipments/{$f['s']['id']}", $body)->assertForbidden();
        $this->assertDatabaseCount('shipment_revisions', 2);
    }
}
