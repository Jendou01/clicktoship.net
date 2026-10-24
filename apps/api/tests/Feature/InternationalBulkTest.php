<?php

namespace Tests\Feature;

use App\Modules\Bulk\Domain\Columns;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\CustomsTestCase;

final class InternationalBulkTest extends CustomsTestCase
{
    use DatabaseMigrations;

    public function test_international_template_and_shared_mapping_validation_preserve_limits(): void
    {
        $f = $this->customsFixture();
        $csv = $this->bearer($f['token'], 'GET', '/api/v1/bulk/templates/ar/csv?variant=international')->assertOk();
        $this->assertStringContainsString('international', $csv->headers->get('Content-Disposition'));
        $raw = ['service_id' => $f['f']['service'], 'currency' => 'SAR', 'description' => 'Synthetic imported international goods', 'goods_origin_country' => 'SA', 'weight_grams' => '1000', 'length_mm' => '100', 'width_mm' => '100', 'height_mm' => '100', 'customs_json' => json_encode($f['customs'])];
        foreach (['origin', 'destination'] as $side) {
            foreach ($f['d'][$side] as $key => $value) {
                $raw[$side.'_'.$key] = $value;
            }
        }$draft = Columns::draft(array_values($raw), array_keys($raw));
        $this->assertSame('2468', $draft['declared_value_minor']);
        $this->assertSame(2, $draft['customs']['items'][0]['quantity']);
        $this->assertSame('AE', $draft['destination']['country']);
        $this->assertSame(10485760, config('bulk.max_bytes'));
        $this->assertSame(10000, config('bulk.max_rows'));
    }
}
