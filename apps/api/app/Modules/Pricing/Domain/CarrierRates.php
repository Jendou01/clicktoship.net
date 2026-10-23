<?php

namespace App\Modules\Pricing\Domain;

/** Future carrier adapters supply verified costs, never merchant selling prices. */
interface CarrierRates
{
    public function configurations(): array;
}
