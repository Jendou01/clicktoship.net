<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public static function present(object $row): object
    {
        $metadata = json_decode($row->metadata ?? '{}', true, flags: JSON_THROW_ON_ERROR);
        $row->metadata = array_intersect_key($metadata, array_flip(['from', 'to', 'reason', 'outcome', 'role', 'assigned', 'suspended', 'capabilities']));
        if (isset($row->metadata['reason'])) {
            $row->metadata['reason'] = Crypt::decryptString($row->metadata['reason']);
        }

        return $row;
    }

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
