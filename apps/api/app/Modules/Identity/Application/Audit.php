<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public static function record(string $action, ?string $actor, ?string $merchant = null, ?string $subject = null, array $metadata = []): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'actor_id' => $actor, 'merchant_id' => $merchant,
            'subject_id' => $subject, 'action' => $action,
            'request_id' => request()->attributes->get('request_id'),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
