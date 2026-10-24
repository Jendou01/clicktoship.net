<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Carriers\Domain\ShipmentOutcome;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Recovery
{
    public function run(): int
    {
        $count = 0;
        DB::table('shipping_outbox as o')->leftJoin('shipping_deliveries as d', 'd.event_id', '=', 'o.id')->whereNull('d.event_id')->select('o.*')->chunkById(100, function ($events) use (&$count) {
            foreach ($events as $e) {
                $service = app(Submission::class);
                $s = $service->row($e->merchant_id, $e->submission_id);
                $shipment = app(Shipments::class)->row($e->merchant_id, $s->shipment_id);
                if ($shipment->state === 'sending' && now()->subMinutes(5)->gt($shipment->updated_at)) {
                    $service->observe($e->merchant_id, $s->id, new ShipmentOutcome('unknown', hash('sha256', 'worker_lost:'.$s->id)));
                } else {
                    try {
                        $service->process($e->merchant_id, $s->id);
                    } catch (HttpException|CarrierFailure) {
                        $service->blockUnsent($e->merchant_id, $s->id);
                    }
                }
                $service->settle($e->merchant_id, $s->id);
                $shipment = app(Shipments::class)->row($e->merchant_id, $s->shipment_id);
                $h = DB::table('balance_holds')->where('id', $s->hold_id)->first();
                if (in_array($shipment->financial_state, ['captured', 'released']) || ($shipment->state === 'unknown' && $h->unknown)) {
                    DB::table('shipping_deliveries')->insertOrIgnore(['event_id' => $e->id]);
                }
                $count++;
            }
        }, 'o.id', 'id');

        return $count;
    }
}
