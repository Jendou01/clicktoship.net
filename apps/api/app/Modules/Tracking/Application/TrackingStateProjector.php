<?php

namespace App\Modules\Tracking\Application;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TrackingStateProjector
{
    public function outcome(?object $old, string $status, string $at): string
    {
        if ($status === 'unknown') {
            return 'unknown';
        }
        if (! $old) {
            return 'projected';
        }
        $next = Carbon::parse($at);
        $prior = Carbon::parse($old->occurred_at);
        $time = $next->eq($prior) ? 0 : ($next->lt($prior) ? -1 : 1);
        if ($time < 0) {
            return 'stale';
        }
        if ($time === 0) {
            return $status === $old->status ? 'stale' : 'conflict';
        }
        if ($status === $old->status) {
            return 'projected';
        }
        $graph = [
            'carrier_accepted' => ['pickup_scheduled', 'picked_up', 'in_transit', 'at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned', 'cancelled'],
            'pickup_scheduled' => ['picked_up', 'in_transit', 'at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned', 'cancelled'],
            'picked_up' => ['in_transit', 'at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned'],
            'in_transit' => ['at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned'],
            'at_sorting_facility' => ['in_transit', 'out_for_delivery', 'delivery_attempted', 'delivered', 'exception', 'returning', 'returned'],
            'out_for_delivery' => ['delivery_attempted', 'delivered', 'exception', 'returning', 'returned'],
            'delivery_attempted' => ['out_for_delivery', 'at_sorting_facility', 'in_transit', 'delivered', 'exception', 'returning', 'returned'],
            'exception' => ['in_transit', 'at_sorting_facility', 'out_for_delivery', 'delivery_attempted', 'delivered', 'returning', 'returned'],
            'delivered' => ['returning', 'returned'], 'returning' => ['returned', 'exception'], 'returned' => [], 'cancelled' => []];

        return in_array($status, $graph[$old->status] ?? [], true) ? 'projected' : 'conflict';
    }

    public function apply(object $b, string $id, string $status, string $at): void
    {
        DB::table('carrier_tracking_projections')->updateOrInsert(['account_id' => $b->account_id, 'merchant_id' => $b->merchant_id, 'operation_id' => $b->operation_id], ['event_id' => $id, 'status' => $status, 'occurred_at' => $at]);
    }
}
