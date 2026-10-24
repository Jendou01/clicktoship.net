<?php

namespace App\Modules\WalletBilling\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Existing ledger only. Verified production evidence/accounting activation is intentionally absent. */
final class ShipmentSettlement
{
    public function finalize(object $submission, object $observation, ?string $approval = null): array
    {
        abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
        abort_unless($observation->merchant_id === $submission->merchant_id && $observation->submission_id === $submission->id && in_array($observation->outcome, ['accepted', 'rejected']), 409);

        return DB::transaction(function () use ($submission, $observation, $approval) {
            $kind = $observation->outcome === 'accepted' ? 'capture' : 'release';
            $business = 'shipment:'.$submission->id.':'.$kind;
            $old = DB::table('financial_hold_resolutions')->where('merchant_id', $submission->merchant_id)->where('hold_id', $submission->hold_id)->first();
            if ($old) {
                abort_unless($old->kind === $kind && $old->observation_id === $observation->id, 409);
            } else {
                DB::table('financial_hold_resolutions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $submission->merchant_id, 'wallet_id' => $submission->wallet_id, 'hold_id' => $submission->hold_id, 'currency' => $submission->currency, 'amount' => $submission->amount, 'observation_id' => $observation->id, 'approval_id' => $approval, 'kind' => $kind, 'business_key' => $business]);
            }

            return app(Ledger::class)->command($submission->merchant_id, $submission->wallet_id, $kind, (string) $submission->amount, $business, $business, 'shipping.settlement', $submission->hold_id);
        });
    }
}
