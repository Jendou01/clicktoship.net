<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\UserAdministration;
use App\Modules\Merchants\Application\Management;
use App\Modules\Merchants\Application\MerchantAdministration;
use App\Modules\Merchants\Domain\ShippingEligibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class Administration
{
    public const CAPABILITIES = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review'];

    public function grants(Request $r): array
    {
        PlatformSecurity::require($r, 'platform.access.request');
        $q = DB::table('platform_access_grants');
        if (! in_array('platform.access.approve', PlatformSecurity::permissions($r->user()->id), true)) {
            $q->where('actor_id', $r->user()->id)->where('session_id', $r->session()->get('auth_session_id'));
        }

        return ['grants' => $q->orderByDesc('created_at')->limit(100)->get()->map(function ($row) {
            $row->capabilities = json_decode($row->capabilities, true);
            $row->reason = Crypt::decryptString($row->reason);

            return $row;
        })];
    }

    public function requestGrant(Request $r): array
    {
        PlatformSecurity::require($r, 'platform.access.request');
        $d = $r->validate(['merchant_id' => 'required|uuid', 'capabilities' => 'required|array|min:1|max:3', 'capabilities.*' => ['required', 'distinct', Rule::in(self::CAPABILITIES)], 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.access.request');
            foreach ($d['capabilities'] as $p) {
                PlatformSecurity::require($r, $p);
            }
            abort_unless(DB::table('merchants')->where('id', $d['merchant_id'])->exists(), 404);
            $id = (string) Str::uuid();
            DB::table('platform_access_grants')->insert(['id' => $id, 'merchant_id' => $d['merchant_id'], 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'capabilities' => json_encode($d['capabilities']), 'reason' => Crypt::encryptString($d['reason']), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
            Audit::record('platform.access_requested', $r->user()->id, $d['merchant_id'], $id, ['capabilities' => $d['capabilities']]);

            return ['id' => $id, 'version' => 1];
        });
    }

    public function approve(Request $r, string $id): array
    {
        PlatformSecurity::require($r, 'platform.access.approve');
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.access.approve');
            $g = DB::table('platform_access_grants')->where('id', $id)->lockForUpdate()->first();
            abort_unless($g, 404);
            abort_if($g->actor_id === $r->user()->id, 403);
            abort_unless($g->version === $d['version'] && ! $g->approved_by && ! $g->revoked_at && now()->lt($g->expires_at), 409);
            $actor = User::findOrFail($g->actor_id);
            $session = DB::table('auth_sessions')->where('id', $g->session_id)->where('user_id', $g->actor_id)->whereNull('revoked_at')->first();
            abort_unless($session && now()->lt($session->expires_at) && now()->subMinutes(config('session.lifetime'))->lt($session->last_seen_at), 403);
            foreach (json_decode($g->capabilities, true) as $p) {
                abort_unless(in_array($p, PlatformSecurity::permissions($actor->id), true), 403);
            }
            DB::table('platform_access_grants')->where('id', $id)->update(['approved_by' => $r->user()->id, 'approval_session_id' => $r->session()->get('auth_session_id'), 'approved_at' => now(), 'version' => $g->version + 1]);
            Audit::record('platform.access_approved', $r->user()->id, $g->merchant_id, $id);

            return ['approved' => true];
        });
    }

    public function revoke(Request $r, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($r, $id, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.access.request');
            $g = DB::table('platform_access_grants')->where('id', $id)->lockForUpdate()->first();
            abort_unless($g, 404);
            if ($g->actor_id !== $r->user()->id) {
                PlatformSecurity::require($r, 'platform.access.approve');
            } else {
                abort_unless($g->session_id === $r->session()->get('auth_session_id'), 404);
            }
            abort_unless($g->version === $d['version'], 409);
            DB::table('platform_access_grants')->where('id', $id)->update(['revoked_at' => now(), 'version' => $g->version + 1]);
            Audit::record('platform.access_revoked', $r->user()->id, $g->merchant_id, $id);

            return ['revoked' => true];
        });
    }

    public function authorizeTarget(Request $r, string $merchant, string $capability): object
    {
        PlatformSecurity::require($r, $capability);
        $r->validate(['access_grant' => 'required|uuid']);
        $g = DB::table('platform_access_grants')->where('id', $r->input('access_grant'))->where('actor_id', $r->user()->id)->where('session_id', $r->session()->get('auth_session_id'))->where('merchant_id', $merchant)->whereNull('revoked_at')->whereNotNull('approved_at')->where('expires_at', '>', now())->first();
        abort_unless($g && in_array($capability, json_decode($g->capabilities, true), true), 403);
        abort_unless(in_array('platform.access.approve', PlatformSecurity::permissions($g->approved_by), true), 403);
        $approval = DB::table('auth_sessions')->where('id', $g->approval_session_id)->where('user_id', $g->approved_by)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->first();
        $mfa = DB::table('user_mfa_credentials')->where('user_id', $g->approved_by)->whereNotNull('confirmed_at')->first();
        abort_unless($approval && $mfa && $approval->mfa_version === $mfa->version, 403);
        $m = DB::table('merchants')->where('id', $merchant)->first();
        abort_unless($m, 404);
        Audit::record('platform.merchant_accessed', $r->user()->id, $merchant, $g->id, ['capability' => $capability]);

        return $m;
    }

    public function merchant(Request $r, string $id): array
    {
        $m = $this->authorizeTarget($r, $id, 'platform.merchant.view');

        $profile = Management::unpack(DB::table('merchant_profiles')->where('merchant_id', $id)->first());
        // Directory/support access never exports contact or business identifiers.
        // Full submitted data requires the separately granted verification-review capability.
        $profile = $profile ? array_intersect_key($profile, array_flip(['id', 'version', 'legal_name', 'country'])) : null;

        return ['merchant' => $m, 'profile' => $profile, 'shipping_eligibility' => ShippingEligibility::evaluate($m->status, $m->verification_status)];
    }

    public function merchantStatus(Request $r, string $id): array
    {
        return app(MerchantAdministration::class)->merchantStatus($r, $id);
    }

    public function verification(Request $r, string $id): array
    {
        $this->authorizeTarget($r, $id, 'platform.verification.review');
        $rows = DB::table('merchant_verification_submissions as s')->leftJoin('merchant_verification_decisions as d', 'd.submission_id', '=', 's.id')->where('s.merchant_id', $id)->orderByDesc('s.created_at')->orderByDesc('s.id')->limit(100)->get(['s.id', 's.submitted_by', 's.profile_version', 's.snapshot', 's.created_at', 'd.outcome', 'd.reason']);

        return ['submissions' => $rows->map(function ($row) {
            $row->snapshot = json_decode(Crypt::decryptString($row->snapshot), true);
            if ($row->reason) {
                $row->reason = Crypt::decryptString($row->reason);
            }

            return $row;
        })];
    }

    public function decide(Request $r, string $merchant, string $submission): array
    {
        return app(MerchantAdministration::class)->decide($r, $merchant, $submission);
    }

    public function userStatus(Request $r, string $id): array
    {
        return app(UserAdministration::class)->userStatus($r, $id);
    }

    public function role(Request $r, string $id): array
    {
        return app(UserAdministration::class)->role($r, $id);
    }
}
