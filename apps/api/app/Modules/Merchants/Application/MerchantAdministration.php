<?php

namespace App\Modules\Merchants\Application;

use App\Modules\Identity\Application\Audit;
use App\Modules\Operations\Application\Administration;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class MerchantAdministration
{
    public function merchantStatus(Request $r, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'status' => ['required', Rule::in(['active', 'suspended', 'closed'])], 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $id, $d) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $id)->lockForUpdate()->first();
            $m = app(Administration::class)->authorizeTarget($r, $id, 'platform.merchant.status');
            abort_unless($m->version === $d['version'], 409);
            abort_if($m->status === 'closed' && $d['status'] !== 'closed', 409);
            abort_if($m->status === $d['status'], 409);
            if ($d['status'] === 'active') {
                $owner = DB::table('merchant_users as u')->join('merchant_role_assignments as a', 'a.membership_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as p', 'p.id', '=', 'u.user_id')->where('u.merchant_id', $id)->where('u.status', 'active')->whereNull('p.suspended_at')->whereNotNull('p.email_verified_at')->where('r.code', 'merchant.owner')->exists();
                abort_unless($owner, 409);
            }
            DB::table('merchants')->where('id', $id)->update(['status' => $d['status'], 'version' => $m->version + 1, 'updated_at' => now()]);
            if ($d['status'] !== 'active') {
                DB::table('personal_access_tokens')->where('merchant_id', $id)->delete();
                DB::table('merchant_invitations')->where('merchant_id', $id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
            }
            Audit::record('merchant.status_changed', $r->user()->id, $id, $id, ['from' => $m->status, 'to' => $d['status'], 'reason' => Crypt::encryptString($d['reason'])]);

            return ['status' => $d['status'], 'version' => $m->version + 1];
        });
    }

    public function decide(Request $r, string $merchant, string $submission): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'outcome' => ['required', Rule::in(['verified', 'rejected'])], 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $merchant, $submission, $d) {
            PlatformSecurity::lock();
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
            $m = app(Administration::class)->authorizeTarget($r, $merchant, 'platform.verification.review');
            $s = DB::table('merchant_verification_submissions')->where('merchant_id', $merchant)->where('id', $submission)->first();
            abort_unless($s, 404);
            // A merchant member or submitter cannot review their own business.
            abort_if($s->submitted_by === $r->user()->id || DB::table('merchant_users')->where('merchant_id', $merchant)->where('user_id', $r->user()->id)->where('status', 'active')->exists(), 403);
            abort_unless($m->version === $d['version'] && $m->verification_status === 'pending', 409);
            abort_if(DB::table('merchant_verification_decisions')->where('submission_id', $submission)->exists(), 409);
            $profile = DB::table('merchant_profiles')->where('merchant_id', $merchant)->first();
            abort_unless($profile && $profile->version === $s->profile_version, 409);
            DB::table('merchant_verification_decisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'submission_id' => $submission, 'reviewed_by' => $r->user()->id, 'outcome' => $d['outcome'], 'reason' => Crypt::encryptString($d['reason']), 'created_at' => now()]);
            DB::table('merchants')->where('id', $merchant)->update(['verification_status' => $d['outcome'], 'version' => $m->version + 1, 'updated_at' => now()]);
            Audit::record('verification.reviewed', $r->user()->id, $merchant, $submission, ['outcome' => $d['outcome']]);

            return ['status' => $d['outcome'], 'version' => $m->version + 1];
        });
    }
}
