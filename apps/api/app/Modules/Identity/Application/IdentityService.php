<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Merchants\Application\MerchantService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IdentityService
{
    public function register(Request $request, array $data): User
    {
        try {
            $user = DB::transaction(function () use ($data): User {
                $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'], 'locale' => $data['locale'] ?? 'en']);
                Audit::record('identity.registered', $user->id, subject: $user->id);
                if (! empty($data['merchant_name'])) {
                    app(MerchantService::class)->create($user, $data['merchant_name']);
                }

                return $user;
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw ValidationException::withMessages(['email' => ['This email cannot be registered.']]);
            }
            throw $error;
        }
        app(Sessions::class)->login($request, $user);
        $user->sendEmailVerificationNotification();

        return $user;
    }

    public function login(Request $request, array $data): User
    {
        return DB::transaction(function () use ($data, $request): User {
            $user = User::whereRaw('lower(email) = ?', [strtolower($data['email'])])->lockForUpdate()->first();
            // Always execute a password check, including an unknown identity.
            $hash = $user?->password ?? Hash::make(Str::random(40));
            if (! Hash::check($data['password'], $hash) || ! $user) {
                throw ValidationException::withMessages(['email' => ['The credentials are invalid.']]);
            }
            abort_if($user->suspended_at, 403);
            if (Hash::needsRehash($user->password)) {
                $user->password = $data['password'];
                $user->save();
            }
            app(Sessions::class)->login($request, $user);
            Audit::record('identity.logged_in', $user->id, subject: $user->id);

            return $user;
        });
    }

    public function verify(User $user, string $verification): void
    {
        abort_unless(str_starts_with($verification, '/api/v1/auth/email/verify?'), 403);
        $signed = Request::create($verification);
        abort_unless(URL::hasValidRelativeSignature($signed), 403);
        abort_unless($signed->query('id') === $user->id && hash_equals(sha1($user->getEmailForVerification()), (string) $signed->query('hash')), 403);
        DB::transaction(function () use ($user): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                Audit::record('identity.email_verified', $user->id, subject: $user->id);
                event(new Verified($user));
            }
        });
    }

    public function reset(array $data): void
    {
        $data['email'] = strtolower($data['email']);
        $status = DB::transaction(function () use ($data): string {
            DB::table('password_reset_tokens')->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password): void {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_if($user->suspended_at, 403);
                $user->password = $password;
                $user->remember_token = Str::random(60);
                $user->auth_version++;
                $user->save();
                DB::table('auth_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $user->tokens()->delete();
                Audit::record('identity.password_reset', $user->id, subject: $user->id);
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['The reset link is invalid or expired.']]);
        }
    }
}
