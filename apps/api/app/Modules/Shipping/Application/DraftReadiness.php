<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Pricing\Application\AvailableServices;
use App\Modules\Shipping\Domain\ShipmentInput;

/** Shared single/bulk readiness, distinct from carrier entitlement or price availability. */
final class DraftReadiness
{
    public function report(array $draft): array
    {
        $report = ShipmentInput::report($draft);
        if (! empty($draft['service_id']) && ! app(AvailableServices::class)->contains($draft['service_id'])) {
            $report['missing'][] = 'service_id';
        }
        $report['missing'] = array_values(array_unique($report['missing']));
        $report['valid'] = $report['missing'] === [];

        return $report;
    }
}
