<?php

namespace App\Modules\Carriers\Infrastructure\Aramex;

use App\Modules\Carriers\Domain\CarrierAdapterInterface;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Request-shape builder only. V2 JSON responses remain deliberately unverified. */
final class AramexAdapter implements CarrierAdapterInterface
{
    public function carrier(): string
    {
        return 'aramex';
    }

    public function request(string $operation, array $input, array $service = []): array
    {
        if (! isset(CarrierCapabilityRegistry::PATHS[$operation])) {
            throw new CarrierFailure('UNSUPPORTED_CAPABILITY');
        }
        $simple = match ($operation) {
            'FetchCities' => ['country' => 'required|regex:/^[A-Z]{2}$/', 'prefix' => 'required|string|max:100', 'state' => 'nullable|string|max:100'],
            'TrackShipments' => ['numbers' => 'required|array|min:1|max:100', 'numbers.*' => 'required|string|regex:/^[a-zA-Z0-9-]{1,50}$/|distinct', 'last_only' => 'required|boolean'],
            'TrackPickup' => ['reference' => 'required|string|max:100'],
            'CancelPickup' => ['guid' => 'required|uuid', 'comments' => 'required|string|max:500'],
            'PrintLabel' => ['number' => 'required|string|regex:/^[a-zA-Z0-9-]{1,50}$/', 'origin_entity' => 'required|string|max:20', 'product_group' => 'required|in:DOM,EXP', 'report_id' => 'required|integer|min:1', 'label_type' => 'required|in:URL,RPT'],
            default => null,
        };
        if ($simple !== null) {
            Validator::make($input, $simple)->validate();
            $keys = array_filter(array_keys($simple), fn ($k) => ! str_contains($k, '.'));
            abort_if(array_diff(array_keys($input), $keys) !== [], 422);

            return match ($operation) {
                'FetchCities' => ['CountryCode' => $input['country'], 'NameStartsWith' => $input['prefix'], 'State' => $input['state'] ?? null],
                'TrackShipments' => ['Shipments' => $input['numbers'], 'GetLastTrackingUpdateOnly' => (bool) $input['last_only']],
                'TrackPickup' => ['Reference' => $input['reference']],
                'CancelPickup' => ['PickupGUID' => $input['guid'], 'Comments' => $input['comments']],
                'PrintLabel' => ['ShipmentNumber' => $input['number'], 'OriginEntity' => $input['origin_entity'], 'ProductGroup' => $input['product_group'], 'LabelInfo' => ['ReportID' => (int) $input['report_id'], 'ReportType' => $input['label_type']]],
            };
        }
        if ($operation === 'CreatePickup') {
            return $this->pickup($input, $service);
        }
        $rules = ['origin' => 'required|array:country,city,postal,state,line1', 'destination' => 'required|array:country,city,postal,state,line1', 'packages' => 'required|array|min:1|max:100', 'packages.*' => 'required|array:weight_grams,length_mm,width_mm,height_mm', 'packages.*.weight_grams' => 'required|integer|min:1|max:100000000', 'packages.*.length_mm' => 'required|integer|min:1|max:100000', 'packages.*.width_mm' => 'required|integer|min:1|max:100000', 'packages.*.height_mm' => 'required|integer|min:1|max:100000', 'currency' => 'required|regex:/^[A-Z]{3}$/', 'description' => 'required|string|max:200', 'goods_origin_country' => 'required|regex:/^[A-Z]{2}$/', 'declared_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'payment_type' => 'required|in:P,C,3'];
        if ($operation === 'CreateShipments') {
            $rules += ['shipper' => 'required|array:name,phone,email', 'consignee' => 'required|array:name,phone,email', 'shipping_at' => 'required|date', 'due_at' => 'required|date|after:shipping_at', 'reference' => 'required|string|max:100'];
        }
        Validator::make($input, $rules)->validate();
        abort_if(array_diff(array_keys($input), array_keys($rules)) !== [], 422);
        if ($service === [] || $service['origin_country'] !== $input['origin']['country'] || $service['destination_country'] !== $input['destination']['country']) {
            throw new CarrierFailure('ROUTE_UNVERIFIED');
        }
        $origin = $this->address($input['origin'], $service);
        $destination = $this->address($input['destination'], $service);
        $weight = array_sum(array_column($input['packages'], 'weight_grams'));
        $details = ['ProductGroup' => $service['product_group'], 'ProductType' => $service['product_type'], 'PaymentType' => $input['payment_type'], 'NumberOfPieces' => count($input['packages']), 'ActualWeight' => ['Unit' => 'KG', 'Value' => $this->decimal($weight, 3)], 'DescriptionOfGoods' => $input['description'], 'GoodsOriginCountry' => $input['goods_origin_country'], 'CustomsValueAmount' => ['CurrencyCode' => $input['currency'], 'Value' => $this->decimal((int) $input['declared_minor'], $this->exponent($input['currency']))]];
        // Collection represents shipment-wide dimensions. Multi-package dimensional semantics lack a verified contract.
        if (count($input['packages']) !== 1) {
            throw new CarrierFailure('PACKAGE_DIMENSION_CONTRACT_UNVERIFIED');
        }
        $p = $input['packages'][0];
        $details['Dimensions'] = ['Unit' => 'CM', 'Length' => $this->decimal($p['length_mm'], 1), 'Width' => $this->decimal($p['width_mm'], 1), 'Height' => $this->decimal($p['height_mm'], 1)];
        if ($operation === 'CalculateRate') {
            return ['OriginAddress' => $origin, 'DestinationAddress' => $destination, 'ShipmentDetails' => $details, 'PreferredCurrencyCode' => $input['currency']];
        }
        foreach (['shipper', 'consignee'] as $party) {
            Validator::make($input[$party], ['name' => 'required|string|max:100', 'phone' => 'required|regex:/^\+[1-9][0-9]{6,14}$/', 'email' => 'required|email:rfc|max:254'])->validate();
        }
        $date = fn ($v) => '/Date('.Carbon::parse($v)->getTimestampMs().')/';

        return ['Shipments' => [['Reference1' => $input['reference'], 'Shipper' => ['PartyAddress' => $origin, 'Contact' => $this->contact($input['shipper'])], 'Consignee' => ['PartyAddress' => $destination, 'Contact' => $this->contact($input['consignee'])], 'ShippingDateTime' => $date($input['shipping_at']), 'DueDate' => $date($input['due_at']), 'Details' => $details]]];
    }

    private function pickup(array $input, array $service): array
    {
        Validator::make($input, ['address' => 'required|array:country,city,postal,state,line1', 'contact' => 'required|array:name,phone,email', 'location' => 'required|string|max:100', 'ready_at' => 'required|date', 'last_at' => 'required|date|after:ready_at', 'closing_at' => 'required|date|after_or_equal:last_at', 'reference' => 'required|string|max:100', 'shipments' => 'required|integer|min:1|max:100', 'pieces' => 'required|integer|min:1|max:100', 'weight_grams' => 'required|integer|min:1|max:100000000', 'payment' => 'required|in:P,C,3', 'status' => 'required|in:Ready,Pending'])->validate();
        abort_if(array_diff(array_keys($input), ['address', 'contact', 'location', 'ready_at', 'last_at', 'closing_at', 'reference', 'shipments', 'pieces', 'weight_grams', 'payment', 'status']) !== [], 422);
        if (! $service || $input['address']['country'] !== $service['origin_country']) {
            throw new CarrierFailure('ROUTE_UNVERIFIED');
        }
        Validator::make($input['contact'], ['name' => 'required|string|max:100', 'phone' => 'required|regex:/^\+[1-9][0-9]{6,14}$/', 'email' => 'required|email:rfc|max:254'])->validate();
        $date = fn ($v) => '/Date('.Carbon::parse($v)->getTimestampMs().')/';

        return ['Pickup' => ['PickupAddress' => $this->address($input['address'], $service), 'PickupContact' => $this->contact($input['contact']), 'PickupLocation' => $input['location'], 'ReadyTime' => $date($input['ready_at']), 'LastPickupTime' => $date($input['last_at']), 'ClosingTime' => $date($input['closing_at']), 'Reference1' => $input['reference'], 'Status' => $input['status'], 'PickupItems' => [['ProductGroup' => $service['product_group'], 'ProductType' => $service['product_type'], 'NumberOfShipments' => $input['shipments'], 'NumberOfPieces' => $input['pieces'], 'Payment' => $input['payment'], 'ShipmentWeight' => ['Unit' => 'KG', 'Value' => $this->decimal($input['weight_grams'], 3)]]]]];
    }

    private function address(array $a, array $service): array
    {
        Validator::make($a, ['country' => 'required|regex:/^[A-Z]{2}$/', 'city' => 'required|string|max:100', 'line1' => 'required|string|max:200', 'postal' => ($service['postal_required'] ? 'required' : 'nullable').'|string|max:20', 'state' => ($service['state_required'] ? 'required' : 'nullable').'|string|max:100'])->validate();
        if ($a['country'] === 'SA' && ! empty($a['postal'])) {
            abort_unless((bool) preg_match('/^[0-9]{5}$/', $a['postal']), 422);
        }
        if ($a['country'] === 'US') {
            abort_unless(! empty($a['state']) && ! empty($a['postal']) && (bool) preg_match('/^[0-9]{5}(-[0-9]{4})?$/', $a['postal']), 422);
        }

        return ['CountryCode' => $a['country'], 'City' => $a['city'], 'Line1' => $a['line1'], 'PostCode' => $a['postal'] ?? null, 'StateOrProvinceCode' => $a['state'] ?? null];
    }

    private function contact(array $p): array
    {
        return ['PersonName' => $p['name'], 'PhoneNumber1' => $p['phone'], 'EmailAddress' => $p['email']];
    }

    private function decimal(int $minor, int $exponent): string
    {
        $s = str_pad((string) $minor, $exponent + 1, '0', STR_PAD_LEFT);

        return $exponent ? substr($s, 0, -$exponent).'.'.substr($s, -$exponent) : $s;
    }

    private function exponent(string $currency): int
    {
        $e = DB::table('financial_currencies')->where('code', $currency)->value('exponent');
        if ($e === null) {
            throw new CarrierFailure('CURRENCY_UNVERIFIED');
        }

        return $e;
    }

    public function normalize(string $operation, string $protocol, array $response): array
    {
        return app(CarrierResponseNormalizer::class)->normalize($operation, $protocol, $response);
    }
}
