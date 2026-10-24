<?php

namespace App\Modules\Carriers\Application;

use App\Modules\Carriers\Domain\ShipmentOutcome;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Owns operation writes; never changes Phase 6 terminal evidence. Caller holds merchant lock. */
final class ShipmentCarrierOperations
{
    public function row(string $merchant, string $id): object
    {
        $op = DB::table('carrier_operations')->where('merchant_id', $merchant)->where('id', $id)->lockForUpdate()->first();
        abort_unless($op, 404);

        return $op;
    }

    public function begin(string $merchant, string $id): object
    {
        $op = $this->row($merchant, $id);
        abort_unless($op->state === 'pending', 409);
        $a = DB::table('carrier_accounts')->where('id', $op->account_id)->first();
        abort_unless($a && $a->active_version === $op->account_version && $a->environment === 'sandbox', 409);
        DB::table('carrier_operations')->where('id', $id)->update(['state' => 'sending', 'updated_at' => now()]);
        $this->attempt($op, 'started');

        return $this->row($merchant, $id);
    }

    public function finish(string $merchant, string $id, ShipmentOutcome $outcome): void
    {
        $op = $this->row($merchant, $id);
        abort_unless($op->state === 'sending', 409);
        $state = $outcome->outcome === 'accepted' ? 'succeeded' : $outcome->outcome;
        DB::table('carrier_operations')->where('id', $id)->update(['state' => $state, 'result_ciphertext' => Crypt::encryptString(json_encode(['reference' => $outcome->reference, 'evidence_hash' => $outcome->evidenceHash])), 'updated_at' => now()]);
        $this->attempt($op, $state);
    }

    public function block(string $merchant, string $id): void
    {
        $op = $this->row($merchant, $id);
        abort_unless($op->state === 'pending', 409);
        DB::table('carrier_operations')->where('id', $id)->update(['state' => 'blocked', 'error_code' => 'PRE_DISPATCH_BLOCKED', 'updated_at' => now()]);
        $this->attempt($op, 'blocked');
    }

    private function attempt(object $op, string $outcome): void
    {
        DB::table('carrier_attempts')->insert(['id' => (string) Str::uuid(), 'operation_id' => $op->id, 'number' => 1, 'outcome' => $outcome, 'correlation_id' => $op->correlation_id]);
    }
}
