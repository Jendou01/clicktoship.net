<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Sessions
{
    public function login(Request $request, User $user): void
    {
        abort_unless($request->hasSession(), 400);
        $old = $request->session()->get('auth_session_id');
        if ($old) {
            DB::table('auth_sessions')->where('id', $old)->update(['revoked_at' => now()]);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget('active_merchant_id');
        $id = (string) Str::uuid();
        DB::table('auth_sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'session_hash' => hash('sha256', $request->session()->getId()),
            'auth_version' => $user->auth_version, 'created_at' => now(), 'last_seen_at' => now(),
            'expires_at' => now()->addHours(12),
        ]);
        $request->session()->put('auth_session_id', $id);
    }

    public function validate(Request $request, User $user): void
    {
        abort_unless($request->hasSession(), 401);
        $session = DB::table('auth_sessions')->where('id', $request->session()->get('auth_session_id'))
            ->where('user_id', $user->id)->whereNull('revoked_at')->first();
        $valid = $session && $session->auth_version === $user->auth_version
            && hash_equals($session->session_hash, hash('sha256', $request->session()->getId()))
            && now()->lt($session->expires_at)
            && now()->subMinutes(config('session.lifetime'))->lt($session->last_seen_at);
        if (! $valid) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(401);
        }
        DB::table('auth_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
    }

    public function logout(Request $request): void
    {
        if ($request->hasSession()) {
            DB::table('auth_sessions')->where('id', $request->session()->get('auth_session_id'))->where('user_id', $request->user()->id)->update(['revoked_at' => now()]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
