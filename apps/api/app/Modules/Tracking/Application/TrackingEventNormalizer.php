<?php

namespace App\Modules\Tracking\Application;

use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use Illuminate\Support\Facades\Validator;

final class TrackingEventNormalizer
{
    public const STATUSES = ['unknown', 'carrier_accepted', 'pickup_scheduled', 'picked_up', 'in_transit', 'at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned', 'cancelled'];

    public function normalize(TrackingProviderAdapter $adapter, array $raw): array
    {
        $e = $adapter->normalize($raw);
        Validator::make($e, ['key' => 'required|string|max:128', 'code' => 'required|string|max:100', 'at' => 'required|date|before_or_equal:now', 'status' => 'required|in:'.implode(',', self::STATUSES), 'provenance' => 'required|string|min:8|max:200', 'location' => 'nullable|string|max:200'])->validate();
        // Require explicit timezone: never silently interpret a provider-local clock.
        abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $e['at']), 422);

        return array_intersect_key($e, array_flip(['key', 'code', 'at', 'status', 'provenance', 'location']));
    }
}
