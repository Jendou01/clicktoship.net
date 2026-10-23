<?php

namespace App\Modules\Carriers\Application;

final class CarrierTrackingStatusMapper
{
    public function map(string $rawCode): string
    {
        // No current Aramex status catalogue was supplied. Unknown is never inferred as delivered.
        return 'unknown';
    }
}
