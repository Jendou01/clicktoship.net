<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Domain\CarrierRates;

final class StaticCarrierRates implements CarrierRates
{
    public function configurations(): array
    {
        return array_values(array_filter(app(Catalog::class)->active(), fn ($v) => $v['kind'] === 'tariff' || ($v['kind'] === 'fee' && $v['config']['side'] === 'carrier')));
    }
}
