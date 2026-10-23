<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UserAdministration
{
    public function userStatus(Request $r, string $id): array
    {
        PlatformSecurity::require($r, 'platform.users.manage');
        $d = $r->validate(['version' => 'required|integer|min:1', 'suspended' => 'required|boolean', 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $id, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.users.manage');
            // Common lock order with membership mutations: merchants, then users.
            $merchants = DB::table('merchant_users')->where('user_id', $id)->orderBy('merchant_id')->pluck('merchant_id');
            DB::table('merchants')->whereIn('id', $merchants)->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($user->admin_version === $d['version'], 409);
            if ($d['suspended']) {
                abort_if($id === $r->user()->id, 409);
                foreach ($merchants as $merchant) {
                    $owners = DB::table('merchant_users as m')->join('merchant_role_assignments as a', 'a.membership_id', '=', 'm.id')->join('roles as role', 'role.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.merchant_id', $merchant)->where('m.status', 'active')->whereNull('u.suspended_at')->whereNotNull('u.email_verified_at')->where('role.code', 'merchant.owner')->pluck('m.user_id');
                    abort_if($owners->contains($id) && $owners->count() <= 1, 409);
                }
            }
            $user->forceFill(['suspended_at' => $d['suspended'] ? now() : null, 'admin_version' => $user->admin_version + 1, 'auth_version' => $user->auth_version + 1])->save();
            abort_unless(PlatformSecurity::usableAdmins() > 0, 409);
            DB::table('auth_sessions')->where('user_id', $id)->update(['revoked_at' => now()]);
            $user->tokens()->delete();
            DB::table('platform_access_grants')->where(fn ($q) => $q->where('actor_id', $id)->orWhere('approved_by', $id))->update(['revoked_at' => now()]);
            Audit::record('platform.user_status_changed', $r->user()->id, subject: $id, metadata: ['suspended' => $d['suspended'], 'reason' => Crypt::encryptString($d['reason'])]);

            return ['version' => $user->admin_version, 'suspended' => $d['suspended']];
        });
    }

    public function role(Request $r, string $id): array
    {
        PlatformSecurity::require($r, 'platform.roles.assign');
        $d = $r->validate(['version' => 'required|integer|min:1', 'role' => 'required|string|max:80', 'assigned' => 'required|boolean', 'reason' => 'required|string|min:10|max:500']);

        return DB::transaction(function () use ($r, $id, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.roles.assign');
            $u = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($u->admin_version === $d['version'], 409);
            $role = DB::table('roles')->where('code', $d['role'])->where('scope', 'platform')->first();
            abort_unless($role, 422);
            if ($d['assigned']) {
                abort_unless(! $u->suspended_at && $u->hasVerifiedEmail() && DB::table('user_mfa_credentials')->where('user_id', $id)->whereNotNull('confirmed_at')->exists(), 409);
                abort_if(DB::table('platform_role_assignments')->where('user_id', $id)->where('role_id', $role->id)->exists(), 409);
                DB::table('platform_role_assignments')->insert(['id' => (string) Str::uuid(), 'user_id' => $id, 'role_id' => $role->id, 'role_scope' => 'platform', 'assigned_by' => $r->user()->id, 'created_at' => now()]);
            } else {
                DB::table('platform_role_assignments')->where('user_id', $id)->where('role_id', $role->id)->delete();
            }
            abort_unless(PlatformSecurity::usableAdmins() > 0, 409);
            $u->forceFill(['admin_version' => $u->admin_version + 1, 'auth_version' => $u->auth_version + 1])->save();
            DB::table('auth_sessions')->where('user_id', $id)->update(['revoked_at' => now()]);
            $u->tokens()->delete();
            DB::table('platform_access_grants')->where(fn ($q) => $q->where('actor_id', $id)->orWhere('approved_by', $id))->update(['revoked_at' => now()]);
            Audit::record('platform.role_changed', $r->user()->id, subject: $id, metadata: ['role' => $d['role'], 'assigned' => $d['assigned'], 'reason' => Crypt::encryptString($d['reason'])]);

            return ['version' => $u->admin_version];
        });
    }
}
