<?php

namespace App\Modules\Tracking\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingNotificationOutbox
{
    public function stage(string $merchant, string $event): void
    {
        // Readiness only: explicit consent, active current membership, verified non-synthetic fact. No sender exists.
        $e = DB::table('tracking_event_details')->where('event_id', $event)->where('merchant_id', $merchant)->where('validation', 'verified')->where('outcome', 'projected')->first();
        if (! $e) {
            return;
        }
        foreach (DB::table('tracking_preferences as p')->join('merchant_users as m', fn ($j) => $j->on('m.merchant_id', '=', 'p.merchant_id')->on('m.user_id', '=', 'p.user_id'))->join('users as u', 'u.id', '=', 'p.user_id')->where('p.merchant_id', $merchant)->where('p.consent', true)->where('m.status', 'active')->whereNull('u.suspended_at')->limit(100)->pluck('p.user_id') as $user) {
            DB::table('tracking_notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'event_id' => $event, 'recipient_id' => $user, 'state' => 'disabled']);
        }
    }
}
