<?php

namespace App\Modules\Carriers\Domain;

use Illuminate\Support\Carbon;

final class TrackingOrder
{
    /** Provider-neutral ordering only; no undocumented Aramex code mapping. Equal timestamps retain prior projection. */
    public static function accepts(?string $currentStatus, ?string $currentAt, string $nextStatus, string $nextAt): bool
    {
        if ($nextStatus === 'unknown') {
            return false;
        }
        if ($currentAt !== null && Carbon::parse($nextAt)->lte($currentAt)) {
            return false;
        }
        if ($currentStatus === 'returned' && $nextStatus !== 'returned') {
            return false;
        }
        if ($currentStatus === 'delivered' && ! in_array($nextStatus, ['returning', 'returned'], true)) {
            return false;
        }

        return true;
    }
}
