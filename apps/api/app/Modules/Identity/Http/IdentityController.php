<?php

namespace App\Modules\Identity\Http;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\IdentityService;
use App\Modules\Identity\Application\Sessions;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class IdentityController
{
    public static function passwordRule(): PasswordRule
    {
        return PasswordRule::min(12)->mixedCase()->numbers()->symbols();
    }

    public function register(Request $request, IdentityService $identity): mixed
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:254',
            'password' => ['required', 'string', 'max:128', 'confirmed', self::passwordRule()],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])], 'merchant_name' => 'sometimes|nullable|string|max:120',
            'role' => 'prohibited', 'role_id' => 'prohibited', 'merchant_id' => 'prohibited', 'suspended_at' => 'prohibited', 'email_verified_at' => 'prohibited',
        ]);
        $identity->register($request, $data);

        return $this->me($request, 201);
    }

    public function login(Request $request, IdentityService $identity): mixed
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:254', 'password' => 'required|string|max:128']);
        $identity->login($request, $data);

        return $this->me($request);
    }

    public function me(Request $request, int $status = 200): mixed
    {
        $user = $request->user();

        return ApiResponse::success(['user' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(), 'locale' => $user->locale,
        ], 'active_merchant_id' => $user->currentAccessToken() instanceof PersonalAccessToken
            ? $user->currentAccessToken()->merchant_id : $request->session()->get('active_merchant_id')], $status);
    }

    public function update(Request $request): mixed
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'locale' => ['required', Rule::in(['en', 'ar'])], 'email' => 'prohibited', 'suspended_at' => 'prohibited', 'role' => 'prohibited']);
        DB::transaction(function () use ($request, $data): void {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->fill($data)->save();
            Audit::record('identity.profile_updated', $user->id, subject: $user->id);
            $request->setUserResolver(fn () => $user);
        });

        return $this->me($request);
    }

    public function logout(Request $request, Sessions $sessions): mixed
    {
        $token = $request->user()->currentAccessToken();
        Audit::record('identity.logged_out', $request->user()->id, subject: $request->user()->id);
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            $sessions->logout($request);
        }

        return ApiResponse::success(['logged_out' => true]);
    }

    public function forgot(Request $request): mixed
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:254']);
        Password::sendResetLink(['email' => strtolower($data['email']), 'suspended_at' => null]);

        return ApiResponse::success(['message' => 'If this account is eligible, a password reset email will be sent.']);
    }

    public function reset(Request $request, IdentityService $identity): mixed
    {
        $data = $request->validate([
            'email' => 'required|email:rfc|max:254', 'token' => 'required|string|max:200',
            'password' => ['required', 'string', 'max:128', 'confirmed', self::passwordRule()],
        ]);
        $identity->reset($data);

        return ApiResponse::success(['password_reset' => true]);
    }

    public function verify(Request $request, IdentityService $identity): mixed
    {
        $data = $request->validate(['verification' => 'required|string|max:2048']);
        $identity->verify($request->user(), $data['verification']);

        return ApiResponse::success(['email_verified' => true]);
    }

    public function resend(Request $request): mixed
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return ApiResponse::success(['message' => 'If verification is needed, a verification email will be sent.']);
    }

    public function sessions(Request $request): mixed
    {
        $current = $request->session()->get('auth_session_id');
        $sessions = DB::table('auth_sessions')->where('user_id', $request->user()->id)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))
            ->orderByDesc('last_seen_at')->limit(100)->get(['id', 'created_at', 'last_seen_at', 'expires_at'])
            ->map(fn ($row) => ['id' => $row->id, 'created_at' => Carbon::parse($row->created_at)->toISOString(), 'last_seen_at' => Carbon::parse($row->last_seen_at)->toISOString(), 'expires_at' => Carbon::parse($row->expires_at)->toISOString(), 'current' => $row->id === $current])->all();

        return ApiResponse::success(['sessions' => $sessions]);
    }

    public function revokeSession(Request $request, string $session, Sessions $sessions): mixed
    {
        $row = DB::table('auth_sessions')->where('id', $session)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        DB::transaction(function () use ($request, $session): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            DB::table('auth_sessions')->where('id', $session)->update(['revoked_at' => now()]);
            Audit::record('identity.session_revoked', $request->user()->id, subject: $session);
        });
        if ($session === $request->session()->get('auth_session_id')) {
            $sessions->logout($request);
        }

        return ApiResponse::success(['revoked' => true]);
    }
}
