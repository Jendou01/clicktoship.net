<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\TrackingOrder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Internal persistence boundary only. No external authenticator/route can call this in Phase 6. */
final class CarrierTrackingInbox
{
    public function retain(string $account, string $merchant, string $operation, string $eventKey, string $rawCode, string $occurredAt, array $raw): string
    {
        Validator::make(compact('eventKey', 'rawCode', 'occurredAt'), ['eventKey' => 'required|string|max:128', 'rawCode' => 'required|string|max:100', 'occurredAt' => 'required|date|before_or_equal:now'])->validate();
        abort_if(strlen(json_encode($raw, JSON_THROW_ON_ERROR)) > 65536, 422);

        $canonical = function ($v) use (&$canonical) {
            if (! is_array($v)) {
                return $v;
            }if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($canonical, $v);
        };
        $digest = hash_hmac('sha256', json_encode($canonical([$rawCode, $occurredAt, $raw])), config('app.key'));

        return DB::transaction(function () use ($account, $merchant, $operation, $eventKey, $rawCode, $occurredAt, $raw, $digest) {
            $op = DB::table('carrier_operations')->where('id', $operation)->where('account_id', $account)->where('merchant_id', $merchant)->lockForUpdate()->first();
            abort_unless($op, 404);
            $fingerprint = hash_hmac('sha256', json_encode([$account, $operation, $eventKey]), config('app.key'));
            $old = DB::table('carrier_webhook_deliveries')->where('account_id', $account)->where('fingerprint', $fingerprint)->first();
            if ($old) {
                abort_unless(hash_equals($old->payload_digest, $digest), 409);

                return $old->id;
            }
            $delivery = (string) Str::uuid();
            DB::table('carrier_webhook_deliveries')->insert(['id' => $delivery, 'account_id' => $account, 'merchant_id' => $merchant, 'operation_id' => $operation, 'fingerprint' => $fingerprint, 'payload_ciphertext' => Crypt::encryptString(json_encode($raw)), 'payload_digest' => $digest]);
            $event = (string) Str::uuid();
            $status = app(CarrierTrackingStatusMapper::class)->map($rawCode);
            DB::table('carrier_tracking_events')->insert(['id' => $event, 'delivery_id' => $delivery, 'account_id' => $account, 'merchant_id' => $merchant, 'operation_id' => $operation, 'event_key' => $eventKey, 'raw_code' => $rawCode, 'normalized_status' => $status, 'occurred_at' => $occurredAt]);
            // A later verified mapping/projector can consume retained facts. Unknown never overwrites a known projection.
            if ($status !== 'unknown') {
                $this->project($account, $merchant, $operation, $event, $status, $occurredAt);
            }

            return $delivery;
        });
    }

    private function project(string $account, string $merchant, string $operation, string $event, string $status, string $at): void
    {
        $q = DB::table('carrier_tracking_projections')->where('account_id', $account)->where('merchant_id', $merchant)->where('operation_id', $operation);
        $old = $q->lockForUpdate()->first();
        if (! TrackingOrder::accepts($old->status ?? null, $old->occurred_at ?? null, $status, $at)) {
            return;
        }
        $row = ['event_id' => $event, 'status' => $status, 'occurred_at' => $at];
        if ($old) {
            $q->update($row);
        } else {
            DB::table('carrier_tracking_projections')->insert(['account_id' => $account, 'merchant_id' => $merchant, 'operation_id' => $operation] + $row);
        }
    }
}
