<?php

namespace App\Modules\Operations\Application;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformSecurity
{
    public static function permissions(string $user): array
    {
        return DB::table('platform_role_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'a.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('a.user_id', $user)->whereNull('u.suspended_at')->whereNotNull('u.email_verified_at')->where('p.scope', 'platform')->distinct()->pluck('p.code')->all();
    }

    public static function require(Request $r, string $permission): void
    {
        abort_unless($r->hasSession() && in_array($permission, self::permissions($r->user()->id), true), 403);
        self::fresh($r);
    }

    public static function fresh(Request $r): void
    {
        abort_unless($r->hasSession(), 403);
        $credential = DB::table('user_mfa_credentials')->where('user_id', $r->user()->id)->whereNotNull('confirmed_at')->first();
        $session = DB::table('auth_sessions')->where('id', $r->session()->get('auth_session_id'))->where('user_id', $r->user()->id)->whereNull('revoked_at')->first();
        abort_unless($credential && $session && $session->mfa_version === $credential->version && $session->mfa_verified_at && now()->subMinutes(15)->lt($session->mfa_verified_at), 403);
    }

    public static function lock(): void
    {
        // Serialize global security decisions, including last-admin and user suspension.
        DB::select('SELECT pg_advisory_xact_lock(3042026)');
    }

    public static function usableAdmins(): int
    {
        return DB::table('platform_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('user_mfa_credentials as m', 'm.user_id', '=', 'u.id')->where('r.code', 'platform.super_admin')->whereNull('u.suspended_at')->whereNotNull('u.email_verified_at')->whereNotNull('m.confirmed_at')->distinct()->count('u.id');
    }
}
