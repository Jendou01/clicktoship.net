<?php

namespace App\Modules\Carriers\Application;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CarrierIntegrationHealth
{
    public function record(string $account, string $status, string $reason): void
    {
        // Identifiers/error classes only. Never carrier messages, account numbers or request bodies.
        DB::table('carrier_health_records')->insert(['id' => (string) Str::uuid(), 'account_id' => $account, 'status' => $status, 'reason' => $reason, 'created_at' => now()]);
        if (in_array($status, ['degraded', 'open'], true)) {
            $key = 'carrier-failures:'.$account;
            Cache::add($key, 0, 60);
            $n = Cache::increment($key);
            if ($n >= 5) {
                Cache::put('carrier-open:'.$account, true, 60);
            }
        }
    }

    public function open(string $account): bool
    {
        return (bool) Cache::get('carrier-open:'.$account, false);
    }
}
