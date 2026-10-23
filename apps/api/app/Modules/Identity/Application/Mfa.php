<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;

final class Mfa
{
    public function password(Request $r): void
    {
        $data = $r->validate(['current_password' => 'required|string|max:128']);
        abort_unless(Hash::check($data['current_password'], $r->user()->password), 422);
    }

    private function lockedIdentity(Request $r): void
    {
        $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
        abort_if($user->suspended_at || ! $user->hasVerifiedEmail(), 403);
        app(Sessions::class)->validate($r, $user);
        abort_unless(Hash::check($r->input('current_password'), $user->password), 422);
    }

    public function status(Request $r): array
    {
        $row = DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->first();
        $session = DB::table('auth_sessions')->where('id', $r->session()->get('auth_session_id'))->first();

        return ['enabled' => (bool) $row?->confirmed_at, 'fresh' => (bool) ($row?->confirmed_at && $session && $session->mfa_version === $row->version && $session->mfa_verified_at && now()->subMinutes(15)->lt($session->mfa_verified_at)), 'platform_permissions' => PlatformSecurity::permissions($r->user()->id)];
    }

    public function enroll(Request $r): array
    {
        $this->password($r);

        return DB::transaction(function () use ($r): array {
            PlatformSecurity::lock();
            $this->lockedIdentity($r);
            $row = DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->lockForUpdate()->first();
            if ($row?->confirmed_at) {
                PlatformSecurity::fresh($r);
            }
            $totp = TOTP::generate();
            $totp->setLabel($r->user()->email);
            $totp->setIssuer('ClickToShip');
            DB::table('user_mfa_credentials')->updateOrInsert(['user_id' => $r->user()->id], ['pending_secret' => Crypt::encryptString($totp->getSecret()), 'pending_expires_at' => now()->addMinutes(10)]);
            Audit::record('security.mfa_enrollment_started', $r->user()->id, subject: $r->user()->id);

            return ['secret' => $totp->getSecret(), 'provisioning_uri' => $totp->getProvisioningUri()];
        });
    }

    public function confirm(Request $r): array
    {
        $this->password($r);
        $data = $r->validate(['code' => 'required|digits:6']);

        return DB::transaction(function () use ($r, $data): array {
            PlatformSecurity::lock();
            $this->lockedIdentity($r);
            $row = DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->lockForUpdate()->first();
            abort_unless($row?->pending_secret && now()->lt($row->pending_expires_at), 422);
            if ($row->confirmed_at) {
                PlatformSecurity::fresh($r);
            }
            $secret = Crypt::decryptString($row->pending_secret);
            $step = $this->step($secret, $data['code'], -1);
            DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->update(['secret' => Crypt::encryptString($secret), 'pending_secret' => null, 'pending_expires_at' => null, 'confirmed_at' => now(), 'last_step' => $step, 'version' => $row->version + 1]);
            $this->invalidateOtherSessions($r);
            $this->mark($r, $row->version + 1);
            Audit::record('security.mfa_confirmed', $r->user()->id, subject: $r->user()->id);

            return ['recovery_codes' => $this->newCodes($r)];
        });
    }

    private function step(string $secret, string $code, int $last): int
    {
        $totp = TOTP::createFromSecret($secret);
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($step > $last && hash_equals($totp->at($step * 30), $code)) {
                return $step;
            }
        }
        abort(422);
    }

    public function challenge(Request $r): array
    {
        $this->password($r);
        $data = $r->validate(['code' => 'required|string|max:80']);

        return DB::transaction(function () use ($r, $data): array {
            PlatformSecurity::lock();
            $this->lockedIdentity($r);
            $row = DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->lockForUpdate()->first();
            abort_unless($row?->confirmed_at, 422);
            if (preg_match('/^[0-9]{6}$/D', $data['code'])) {
                $step = $this->step(Crypt::decryptString($row->secret), $data['code'], $row->last_step);
                DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->update(['last_step' => $step]);
            } else {
                $used = DB::table('mfa_recovery_codes')->where('user_id', $r->user()->id)->where('code_hash', hash('sha256', $data['code']))->whereNull('used_at')->update(['used_at' => now()]);
                abort_unless($used === 1, 422);
            }
            $this->mark($r, $row->version);
            Audit::record('security.mfa_challenged', $r->user()->id, subject: $r->user()->id);

            return ['verified' => true];
        });
    }

    public function recovery(Request $r): array
    {
        $this->password($r);

        return DB::transaction(function () use ($r): array {
            PlatformSecurity::lock();
            $this->lockedIdentity($r);
            PlatformSecurity::fresh($r);
            Audit::record('security.mfa_recovery_rotated', $r->user()->id, subject: $r->user()->id);

            return ['recovery_codes' => $this->newCodes($r)];
        });
    }

    public function disable(Request $r): array
    {
        $this->password($r);

        return DB::transaction(function () use ($r): array {
            PlatformSecurity::lock();
            $this->lockedIdentity($r);
            PlatformSecurity::fresh($r);
            // Remove platform roles through audited administration first. No MFA-less admin can persist.
            abort_if(DB::table('platform_role_assignments')->where('user_id', $r->user()->id)->exists(), 409);
            DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->delete();
            DB::table('mfa_recovery_codes')->where('user_id', $r->user()->id)->delete();
            $this->invalidateOtherSessions($r);
            DB::table('auth_sessions')->where('id', $r->session()->get('auth_session_id'))->update(['mfa_verified_at' => null, 'mfa_version' => null]);
            Audit::record('security.mfa_disabled', $r->user()->id, subject: $r->user()->id);

            return ['disabled' => true];
        });
    }

    private function mark(Request $r, int $version): void
    {
        DB::table('auth_sessions')->where('id', $r->session()->get('auth_session_id'))->where('user_id', $r->user()->id)->whereNull('revoked_at')->update(['mfa_verified_at' => now(), 'mfa_version' => $version]);
    }

    private function invalidateOtherSessions(Request $r): void
    {
        DB::table('auth_sessions')->where('user_id', $r->user()->id)->where('id', '<>', $r->session()->get('auth_session_id'))->update(['revoked_at' => now()]);
        $r->user()->tokens()->delete();
        DB::table('platform_access_grants')->where(fn ($q) => $q->where('actor_id', $r->user()->id)->orWhere('approved_by', $r->user()->id))->update(['revoked_at' => now()]);
    }

    private function newCodes(Request $r): array
    {
        DB::table('mfa_recovery_codes')->where('user_id', $r->user()->id)->delete();
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $code = bin2hex(random_bytes(16));
            $codes[] = $code;
            DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'code_hash' => hash('sha256', $code)]);
        }

        return $codes;
    }
}
