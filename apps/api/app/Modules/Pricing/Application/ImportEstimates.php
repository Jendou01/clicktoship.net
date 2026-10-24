<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\Calculator;
use App\Modules\Pricing\Domain\CarrierRates;
use App\Modules\Shipping\Domain\ShipmentInput;

/** Informational public estimates, never accepted shipment quotes or financial effects. */
final class ImportEstimates
{
    public function preview(string $merchant, array $draft): array
    {
        $isCost = fn ($v) => $v['kind'] === 'tariff' || ($v['kind'] === 'fee' && $v['config']['side'] === 'carrier');
        $local = array_values(array_filter(app(Catalog::class)->active(), fn ($v) => ! $isCost($v)));
        $costs = app(CarrierRates::class)->configurations();
        abort_if(count(array_filter($costs, $isCost)) !== count($costs), 503);
        $public = app(Calculator::class)->calculate(ShipmentInput::pricing($draft), $merchant, array_merge($local, $costs))['public'];

        // An allowlist prevents accidental exposure if the pricing engine grows additional fields.
        return array_intersect_key($public, array_flip(['service_id', 'service_name', 'currency', 'total_minor', 'selling_minor', 'discount_minor', 'fees', 'tax', 'billable_weight_grams', 'expires_at', 'carrier_availability', 'shipping_enabled']));
    }
}
