<?php

namespace App\Modules\Tracking\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrackingReconciliationService
{
    public function authorize(Request $r, string $merchant, string $permission): void
    {
        PlatformSecurity::lock();
        app(Sessions::class)->validate($r, User::findOrFail($r->user()->id));
        app(Administration::class)->authorizeTarget($r, $merchant, $permission);
    }

    public function review(Request $r, string $merchant, string $case): array
    {
        $d = $r->validate(['note' => 'required|string|min:10|max:1000', 'idempotency_key' => 'required|string|min:8|max:128', 'status' => 'prohibited', 'outcome' => 'prohibited']);

        return DB::transaction(function () use ($r, $merchant, $case, $d) {
            $this->authorize($r, $merchant, 'platform.tracking.review');
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('tracking_cases')->where('merchant_id', $merchant)->where('id', $case)->exists(), 404);
            $key = hash('sha256', $d['idempotency_key']);
            $hash = ShipmentInput::digest([$case, $d['note']]);
            $old = DB::table('tracking_reviews')->where('merchant_id', $merchant)->where('actor_id', $r->user()->id)->where('key_hash', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->digest, $hash), 409);

                return ['id' => $old->id, 'effect' => 'review_only'];
            }
            $id = (string) Str::uuid();
            DB::table('tracking_reviews')->insert(['id' => $id, 'merchant_id' => $merchant, 'case_id' => $case, 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'grant_id' => $r->input('access_grant'), 'key_hash' => $key, 'digest' => $hash, 'note_ciphertext' => Crypt::encryptString($d['note'])]);
            Audit::record('tracking.case_reviewed', $r->user()->id, $merchant, $case);

            return ['id' => $id, 'effect' => 'review_only'];
        });
    }
}
