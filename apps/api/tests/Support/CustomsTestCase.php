<?php

namespace Tests\Support;

use App\Modules\Customs\Application\DocumentWork;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Support\Facades\Queue;

abstract class CustomsTestCase extends ShippingTestCase
{
    protected function customsFixture(): array
    {
        Queue::fake();
        $u = $this->user();
        $m = $this->organization($u);
        $f = $this->fixture();
        $token = $this->token($u, $m);
        $d = $this->draft($f['service']);
        $d['destination']['country'] = 'AE';
        $d['destination']['city'] = 'Dubai';
        $d['items'] = [];
        $s = $this->bearer($token, 'POST', '/api/v1/shipments', ['draft' => $d, 'idempotency_key' => 'international-initial-draft'])->assertCreated()->json('data.shipment');
        $customs = ['purpose' => 'commercial', 'invoice_date' => now()->toDateString(), 'incoterm' => 'DAP', 'duty_payer' => 'recipient', 'exporter' => $d['origin'], 'importer' => $d['destination'], 'notes' => 'Synthetic draft only', 'items' => [['description_en' => 'Synthetic sample goods', 'description_ar' => 'بضاعة اختبار اصطناعية', 'quantity' => 2, 'uom' => 'piece', 'unit_value_minor' => '1234', 'currency' => 'SAR', 'origin_country' => 'SA', 'hs_code' => '010121', 'sku' => 'SYNTHETIC', 'net_grams' => 700, 'gross_grams' => 800, 'allocations' => [['package' => 1, 'quantity' => 2, 'weight_grams' => 800]]]]];

        return compact('u', 'm', 'f', 'token', 'd', 's', 'customs');
    }

    protected function saveCustoms(array $f, array $extra = [])
    {
        return $this->bearer($f['token'], 'PUT', "/api/v1/customs/shipments/{$f['s']['id']}/declaration", $extra + ['version' => $f['s']['version'], 'declaration_version' => 0, 'declaration' => $f['customs'], 'idempotency_key' => 'save-customs-first']);
    }

    protected function docRequest(array $f, array $saved, string $kind = 'invoice', string $locale = 'en')
    {
        return $this->bearer($f['token'], 'POST', "/api/v1/customs/shipments/{$f['s']['id']}/documents", ['version' => $saved['shipment']['version'], 'declaration_version' => $saved['declaration_version'], 'kind' => $kind, 'locale' => $locale, 'idempotency_key' => 'document-'.$kind.'-'.$locale.'-v'.$saved['declaration_version']]);
    }

    protected function generate(array $f, array $doc, bool $clean = true): void
    {
        app()->instance(DocumentScanner::class, new class($clean) implements DocumentScanner
        {
            public function __construct(private bool $value) {}

            public function clean(string $content): bool
            {
                return $this->value;
            }
        });
        app(DocumentWork::class)->tick($f['m'], $doc['work_id']);
    }
}
