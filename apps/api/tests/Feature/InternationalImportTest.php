<?php

namespace Tests\Feature;

use App\Modules\Shipping\Application\Shipments;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BulkTestCase;

final class InternationalImportTest extends BulkTestCase
{
    use DatabaseMigrations;

    private function row(array $f): array
    {
        $r = $this->csvRow($f['f']['service']);
        $r['destination_country'] = 'AE';
        $r['destination_city'] = 'Dubai';
        $r['goods_origin_country'] = 'SA';
        $r['declared_value_minor'] = '500';
        $party = fn ($side) => ['name' => $r[$side.'_name'], 'phone' => $r[$side.'_phone'], 'country' => $r[$side.'_country'], 'city' => $r[$side.'_city'], 'line1' => $r[$side.'_line1'], 'postal' => $r[$side.'_postal']];
        $c = ['exporter' => $party('origin'), 'importer' => $party('destination'), 'purpose' => 'sample', 'invoice_date' => now()->toDateString(), 'incoterm' => 'DAP', 'duty_payer' => 'recipient', 'items' => [['description_en' => 'Synthetic imported item', 'description_ar' => 'بند استيراد تجريبي', 'quantity' => 1, 'uom' => 'piece', 'unit_value_minor' => '500', 'currency' => 'SAR', 'origin_country' => 'SA', 'hs_code' => '010121', 'allocations' => [['package' => 1, 'quantity' => 1]]]]];
        $r['customs_json'] = json_encode($c);

        return $r;
    }

    public function test_full_international_csv_pipeline_creates_only_normalized_drafts(): void
    {
        $f = $this->bulkFixture();
        $row = $this->row($f);
        $bad = $row;
        $c = json_decode($bad['customs_json'], true);
        $c['items'][0]['hs_code'] = 'invalid';
        $bad['customs_json'] = json_encode($c);
        $b = $this->uploadTo($f, $this->csv([$row, $bad]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $view = $this->bulkView($f, $b);
        $this->assertSame(1, $view['counts']['valid']);
        $this->assertSame(1, $view['counts']['invalid']);
        $this->bearer($f['token'], 'GET', "/api/v1/bulk/imports/$b/rows")->assertOk()->assertJsonPath('data.rows.0.customs_readiness.customs_cleared', false);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $this->drain($f['m'], $b);
        $id = DB::table('bulk_drafts')->value('shipment_id');
        $draft = app(Shipments::class)->revision(DB::table('shipments')->where('id', $id)->first());
        $this->assertSame('500', $draft['customs']['declared_total_minor']);
        $this->assertSame('AE', $draft['destination']['country']);
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('carrier_operations', 0);
    }

    public function test_bulk_scopes_do_not_grant_customs_write_implicitly(): void
    {
        $f = $this->bulkFixture();
        $f['token'] = $this->token($f['u'], $f['m'], ['bulk.read', 'bulk.upload', 'bulk.map', 'bulk.prepare', 'shipments.write']);
        $b = $this->uploadTo($f, $this->csv([$this->row($f)]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
        $this->drain($f['m'], $b);
        $this->assertDatabaseCount('bulk_drafts', 0);
        $this->assertDatabaseCount('shipments', 0);
    }
}
