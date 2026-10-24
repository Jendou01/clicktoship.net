<?php

namespace App\Modules\Tracking\Application;

use App\Modules\Shipping\Domain\ShipmentInput;
use App\Modules\Tracking\Domain\TrackingProviderAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingEventStore
{
    /** Caller holds merchant, binding and work locks. Effects and receipt commit together. */
    public function append(object $b, object $w, array $raw, TrackingProviderAdapter $adapter): void
    {
        abort_if(count($raw) > 100 || strlen(json_encode($raw, JSON_THROW_ON_ERROR)) > 65536, 422);
        $delivery = (string) Str::uuid();
        DB::table('carrier_webhook_deliveries')->insert(['id' => $delivery, 'account_id' => $b->account_id, 'merchant_id' => $b->merchant_id, 'operation_id' => $b->operation_id, 'fingerprint' => hash('sha256', 'tracking:'.$w->id), 'payload_ciphertext' => Crypt::encryptString(json_encode($raw, JSON_THROW_ON_ERROR)), 'payload_digest' => ShipmentInput::digest($raw)]);
        foreach ($raw as $input) {
            abort_unless(is_array($input), 422);
            $e = app(TrackingEventNormalizer::class)->normalize($adapter, $input);
            $e['at'] = Carbon::parse($e['at'])->utc()->format('Y-m-d\TH:i:s.uP');
            $key = hash_hmac('sha256', $e['key'], config('app.key'));
            $hash = ShipmentInput::digest($e);
            $old = DB::table('carrier_tracking_events as e')->leftJoin('tracking_event_details as d', 'd.event_id', '=', 'e.id')->where('e.account_id', $b->account_id)->where('e.operation_id', $b->operation_id)->where('e.event_key', $key)->first(['e.id', 'd.fact_hash']);
            if ($old) {
                abort_unless($old->fact_hash && hash_equals($old->fact_hash, $hash), 409);

                continue;
            }
            $projection = DB::table('carrier_tracking_projections')->where('account_id', $b->account_id)->where('merchant_id', $b->merchant_id)->where('operation_id', $b->operation_id)->lockForUpdate()->first();
            $outcome = app(TrackingStateProjector::class)->outcome($projection, $e['status'], $e['at']);
            $id = (string) Str::uuid();
            DB::table('carrier_tracking_events')->insert(['id' => $id, 'delivery_id' => $delivery, 'account_id' => $b->account_id, 'merchant_id' => $b->merchant_id, 'operation_id' => $b->operation_id, 'event_key' => $key, 'raw_code' => $e['code'], 'normalized_status' => $e['status'], 'occurred_at' => $e['at']]);
            $validation = ($adapter->capabilities()['synthetic'] ?? false) ? 'synthetic_test_only' : ($e['status'] === 'unknown' ? 'unknown_code' : 'verified');
            DB::table('tracking_event_details')->insert(['event_id' => $id, 'merchant_id' => $b->merchant_id, 'binding_id' => $b->id, 'account_id' => $b->account_id, 'operation_id' => $b->operation_id, 'work_id' => $w->id, 'source' => $w->kind === 'webhook' ? 'webhook' : 'poll', 'validation' => $validation, 'provenance' => $e['provenance'], 'outcome' => $outcome, 'correlation_id' => $w->id, 'provider_id_ciphertext' => Crypt::encryptString($e['key']), 'location_ciphertext' => isset($e['location']) ? Crypt::encryptString($e['location']) : null, 'fact_hash' => $hash]);
            if ($outcome === 'projected') {
                app(TrackingStateProjector::class)->apply($b, $id, $e['status'], $e['at']);
            }
            if (in_array($outcome, ['conflict', 'unknown'], true)) {
                DB::table('tracking_cases')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $b->merchant_id, 'binding_id' => $b->id, 'event_id' => $id, 'reason' => $outcome === 'unknown' ? 'unknown_code' : 'conflicting_evidence']);
            }
            if ($outcome === 'projected') {
                app(TrackingNotificationOutbox::class)->stage($b->merchant_id, $id);
            }
        }
    }
}
