<?php

namespace Tests\Feature;

use App\Modules\Carriers\Application\CarrierRegistry;
use App\Modules\Carriers\Application\CarrierServiceMapper;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\CarrierTransport;
use App\Modules\Carriers\Infrastructure\Aramex\CarrierResponseNormalizer;
use App\Modules\Carriers\Infrastructure\HttpCarrierTransport;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CarrierTestCase;

final class CarrierContractTest extends CarrierTestCase
{
    use DatabaseMigrations;

    private function probe(): HttpCarrierTransport
    {
        return new class extends HttpCarrierTransport
        {
            protected function authorize(string $operation): void
            {
                if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'clicktoship_test') {
                    throw new CarrierFailure('TEST_ONLY');
                }
            }
        };
    }

    public function test_capabilities_never_claim_live_verification_and_default_transport_sends_nothing(): void
    {
        foreach (app(CarrierCapabilityRegistry::class)->all() as $c) {
            $this->assertFalse($c['external_enabled']);
            $this->assertFalse($c['response_contract_verified']);
        }
        Http::fake();
        try {
            app(CarrierTransport::class)->send('CalculateRate', [], (string) Str::uuid());
            $this->fail('Network enabled');
        } catch (CarrierFailure $e) {
            $this->assertSame('CONTRACT_UNVERIFIED', $e->errorCode);
        }Http::assertNothingSent();
        try {
            app(CarrierCapabilityRegistry::class)->assertExternal('CalculateRate', 'production');
            $this->fail('Production enabled');
        } catch (CarrierFailure $e) {
            $this->assertSame('PRODUCTION_DISABLED', $e->errorCode);
        }
    }

    public function test_documented_rate_and_create_shapes_preserve_exact_money_and_truthful_goods_origin(): void
    {
        $adapter = app(CarrierRegistry::class)->get('aramex');
        $input = $this->shipmentInput();
        $r = $adapter->request('CalculateRate', $input, $this->service());
        $this->assertSame('1.250', $r['ShipmentDetails']['ActualWeight']['Value']);
        $this->assertSame('123.45', $r['ShipmentDetails']['CustomsValueAmount']['Value']);
        $this->assertSame('AE', $r['ShipmentDetails']['GoodsOriginCountry']);
        $this->assertSame('10.0', $r['ShipmentDetails']['Dimensions']['Length']);
        $this->assertArrayNotHasKey('ClientInfo', $r);
        $p = ['name' => 'Synthetic Person', 'phone' => '+966501234567', 'email' => 'person@example.test'];
        $input += ['shipper' => $p, 'consignee' => $p, 'shipping_at' => now()->toIso8601String(), 'due_at' => now()->addDay()->toIso8601String(), 'reference' => 'synthetic-client-reference'];
        $s = $adapter->request('CreateShipments', $input, $this->service());
        $this->assertCount(1, $s['Shipments']);
        $this->assertSame('AE', $s['Shipments'][0]['Details']['GoodsOriginCountry']);
        $this->assertStringStartsWith('/Date(', $s['Shipments'][0]['ShippingDateTime']);
    }

    public function test_international_mapping_is_explicit_and_unknown_lanes_are_denied(): void
    {
        $s = $this->service();
        $s['code'] = 'synthetic-exp';
        $s['destination_country'] = 'AE';
        $s['product_group'] = 'EXP';
        $s['product_type'] = 'EPX';
        $mapper = app(CarrierServiceMapper::class);
        $mapper->validate([$s]);
        $this->assertSame('EPX', $mapper->resolve([$s], 'synthetic-exp', 'SA', 'AE')['product_type']);
        $this->expectException(CarrierFailure::class);
        $mapper->resolve([$s], 'synthetic-exp', 'SA', 'US');
    }

    public function test_invalid_data_and_unverified_multiple_package_semantics_are_rejected(): void
    {
        $a = app(CarrierRegistry::class)->get('aramex');
        $input = $this->shipmentInput();
        $input['packages'][] = $input['packages'][0];
        try {
            $a->request('CalculateRate', $input, $this->service());
            $this->fail('Unverified multi-package dimensions accepted');
        } catch (CarrierFailure $e) {
            $this->assertSame('PACKAGE_DIMENSION_CONTRACT_UNVERIFIED', $e->errorCode);
        }
        $input = $this->shipmentInput();
        $input['packages'][0]['weight_grams'] = 0;
        $this->expectException(ValidationException::class);
        $a->request('CalculateRate', $input, $this->service());
    }

    public function test_legacy_soap_structure_does_not_verify_json_or_leak_notifications_and_labels(): void
    {
        $n = app(CarrierResponseNormalizer::class);
        $fixture = ['HasErrors' => false, 'Notifications' => [], 'Shipments' => [['HasErrors' => false, 'Notifications' => [], 'ID' => 'SYNTHETIC-123', 'ShipmentLabel' => ['LabelURL' => 'https://private.invalid/never-fetch']]]];
        $this->assertSame(['carrier_reference' => 'SYNTHETIC-123'], $n->normalize('CreateShipments', 'legacy-soap-manual', $fixture));
        try {
            $n->normalize('CreateShipments', 'aramex-v2-json', $fixture);
            $this->fail('SOAP treated as JSON evidence');
        } catch (CarrierFailure $e) {
            $this->assertSame('RESPONSE_CONTRACT_UNVERIFIED', $e->errorCode);
            $this->assertTrue($e->ambiguous);
        }
        $fixture['Shipments'][0]['HasErrors'] = true;
        $this->expectException(CarrierFailure::class);
        $n->normalize('CreateShipments', 'legacy-soap-manual', $fixture);
    }

    public function test_malformed_rate_response_is_unverified_not_a_fabricated_success(): void
    {
        $this->expectException(CarrierFailure::class);
        app(CarrierResponseNormalizer::class)->normalize('CalculateRate', 'aramex-v2-json', ['HasErrors' => false, 'TotalAmount' => ['Value' => '14', 'CurrencyCode' => 'SAR']]);
    }

    public function test_read_retry_is_bounded_and_authentication_and_redirects_are_not_retried(): void
    {
        Sleep::fake();
        Http::fakeSequence()->push([], 429)->push([], 503)->push(['synthetic' => 'not-a-contract'], 200);
        $id = (string) Str::uuid();
        $this->assertSame(['synthetic' => 'not-a-contract'], $this->probe()->send('FetchCities', ['CountryCode' => 'SA'], $id));
        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Request-ID', $id));
        foreach ([401, 403, 302] as $status) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['message' => 'confidential-upstream-message'], $status)]);
            try {
                $this->probe()->send('FetchCities', [], (string) Str::uuid());
                $this->fail('HTTP failure accepted');
            } catch (CarrierFailure $e) {
                $this->assertStringNotContainsString('confidential', $e->getMessage());
                $this->assertFalse($e->retryable);
            }Http::assertSentCount(1);
        }
    }

    public function test_create_network_failure_and_rate_limit_are_ambiguous_and_never_retried(): void
    {
        foreach (['CreateShipments', 'CreatePickup'] as $op) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => Http::failedConnection()]);
            try {
                $this->probe()->send($op, [], (string) Str::uuid());
                $this->fail('Unknown create accepted');
            } catch (CarrierFailure $e) {
                $this->assertTrue($e->ambiguous);
                $this->assertFalse($e->retryable);
            }Http::assertSentCount(1);
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 429)]);
        try {
            $this->probe()->send('CreateShipments', [], (string) Str::uuid());
            $this->fail('Rate-limited mutation accepted');
        } catch (CarrierFailure $e) {
            $this->assertSame('RATE_LIMITED', $e->errorCode);
            $this->assertTrue($e->ambiguous);
        }Http::assertSentCount(1);
    }

    public function test_label_cancel_tracking_city_and_pickup_builders_use_only_documented_operations(): void
    {
        $a = app(CarrierRegistry::class)->get('aramex');
        $this->assertSame(['CountryCode' => 'SA', 'NameStartsWith' => 'test', 'State' => null], $a->request('FetchCities', ['country' => 'SA', 'prefix' => 'test']));
        $this->assertSame(['Reference' => 'synthetic'], $a->request('TrackPickup', ['reference' => 'synthetic']));
        $this->assertSame(['Shipments' => ['TEST-123'], 'GetLastTrackingUpdateOnly' => true], $a->request('TrackShipments', ['numbers' => ['TEST-123'], 'last_only' => true]));
        $guid = (string) Str::uuid();
        $this->assertSame($guid, $a->request('CancelPickup', ['guid' => $guid, 'comments' => 'Synthetic test'])['PickupGUID']);
        $this->assertSame('EXP', $a->request('PrintLabel', ['number' => 'TEST-123', 'origin_entity' => 'SYN', 'product_group' => 'EXP', 'report_id' => 1, 'label_type' => 'URL'])['ProductGroup']);
        $input = ['address' => $this->shipmentInput()['origin'], 'contact' => ['name' => 'Test', 'phone' => '+966501234567', 'email' => 'test@example.test'], 'location' => 'Synthetic', 'ready_at' => now()->toIso8601String(), 'last_at' => now()->addHour()->toIso8601String(), 'closing_at' => now()->addHours(2)->toIso8601String(), 'reference' => 'Synthetic', 'shipments' => 1, 'pieces' => 1, 'weight_grams' => 1000, 'payment' => 'P', 'status' => 'Ready'];
        $this->assertSame('DOM', $a->request('CreatePickup', $input, $this->service())['Pickup']['PickupItems'][0]['ProductGroup']);
        $this->expectException(CarrierFailure::class);
        $a->request('OfficeLocations', []);
    }
}
