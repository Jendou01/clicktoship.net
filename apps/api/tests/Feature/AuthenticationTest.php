<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\ResetPassword;
use App\Modules\Identity\Infrastructure\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\Support\IdentityTestCase;

class AuthenticationTest extends IdentityTestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_creates_an_unverified_owner(): void
    {
        Notification::fake();
        $this->csrf();
        $response = $this->browser('POST', '/api/v1/auth/register', [
            'name' => 'Merchant', 'email' => 'New.User@Example.test', 'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD, 'locale' => 'ar', 'merchant_name' => 'New organization',
        ])->assertCreated()->assertJsonPath('data.user.email_verified', false);
        $user = User::findOrFail($response->json('data.user.id'));
        $this->assertSame('new.user@example.test', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseCount('merchants', 1);
        $this->assertDatabaseCount('merchant_users', 1);
        $this->browser('GET', '/api/v1/merchants')->assertForbidden();
        $this->browser('GET', '/api/v1/auth/me')->assertOk()->assertDontSee(self::PASSWORD);
    }

    public function test_csrf_missing_or_invalid_is_rejected_even_in_phpunit(): void
    {
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD], false)
            ->assertStatus(419)->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_foreign_origin_cannot_use_cookie_authentication(): void
    {
        $user = $this->user();
        $this->login($user);
        $this->resetHttpState();
        $this->call('POST', '/api/v1/auth/logout', [], $this->jar, [], [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'https://evil.example',
        ])->assertUnauthorized();
    }

    public function test_duplicate_email_case_and_privilege_fields_are_rejected(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->csrf();
        $base = ['name' => 'Test', 'email' => strtoupper($user->email), 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->browser('POST', '/api/v1/auth/register', $base)->assertUnprocessable();
        $this->browser('POST', '/api/v1/auth/register', [...$base, 'email' => 'other@example.test', 'role' => 'platform.super_admin'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('platform_role_assignments', 0);
    }

    public function test_weak_password_and_unknown_credentials_are_rejected(): void
    {
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/register', ['name' => 'Weak', 'email' => 'weak@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'unknown@example.test', 'password' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_login_rotates_session_and_logout_invalidates_it(): void
    {
        $user = $this->user();
        $this->csrf();
        $old = $this->jar[config('session.cookie')];
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertNotSame($old, $this->jar[config('session.cookie')]);
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $oldJar = $this->jar;
        $this->browser('POST', '/api/v1/auth/logout')->assertOk();
        $this->jar = $oldJar;
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_sessions_are_listed_without_session_secrets_and_can_be_revoked(): void
    {
        $user = $this->user();
        $this->login($user);
        $firstJar = $this->jar;
        $firstId = $this->browser('GET', '/api/v1/auth/sessions')->json('data.sessions.0.id');
        $this->jar = [];
        $this->login($user);
        $list = $this->browser('GET', '/api/v1/auth/sessions')->assertOk();
        $this->assertCount(2, $list->json('data.sessions'));
        $list->assertDontSee('session_hash');
        $this->browser('DELETE', '/api/v1/auth/sessions/'.$firstId)->assertOk();
        $this->jar = $firstJar;
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $first = $this->user();
        $second = $this->user();
        $this->login($first);
        $id = $this->browser('GET', '/api/v1/auth/sessions')->json('data.sessions.0.id');
        $this->jar = [];
        $this->login($second);
        $this->browser('DELETE', '/api/v1/auth/sessions/'.$id)->assertNotFound();
    }

    public function test_email_verification_requires_own_valid_unexpired_signature(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $this->login($user);
        $valid = URL::temporarySignedRoute('auth.email.verify', now()->addMinutes(10), ['id' => $user->id, 'hash' => sha1($user->email)], false);
        $this->browser('POST', '/api/v1/auth/email/verify', ['verification' => $valid.'tamper'])->assertForbidden();
        $expired = URL::temporarySignedRoute('auth.email.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)], false);
        $this->browser('POST', '/api/v1/auth/email/verify', ['verification' => $expired])->assertForbidden();
        $other = $this->user(['email_verified_at' => null]);
        $foreign = URL::temporarySignedRoute('auth.email.verify', now()->addMinute(), ['id' => $other->id, 'hash' => sha1($other->email)], false);
        $this->browser('POST', '/api/v1/auth/email/verify', ['verification' => $foreign])->assertForbidden();
        $this->browser('POST', '/api/v1/auth/email/verify', ['verification' => $valid])->assertOk();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_reset_is_generic_hashes_tokens_and_revokes_all_sessions_and_tokens(): void
    {
        Notification::fake();
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $this->login($user);
        $oldJar = $this->jar;
        $this->browser('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $reset = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$reset) {
            $reset = $notification->token;

            return true;
        });
        $this->assertNotSame($reset, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $this->browser('POST', '/api/v1/auth/password/reset', ['email' => $user->email, 'token' => $reset, 'password' => 'Changed-Password-12345', 'password_confirmation' => 'Changed-Password-12345'])->assertOk();
        $this->jar = $oldJar;
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
        $this->assertTrue(Hash::check('Changed-Password-12345', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->jar = [];
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/password/reset', ['email' => $user->email, 'token' => $reset, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])->assertUnprocessable();
    }

    public function test_invalid_expired_and_wrong_email_reset_tokens_fail(): void
    {
        $user = $this->user();
        $other = $this->user();
        $token = Password::createToken($user);
        $this->csrf();
        $payload = ['email' => $other->email, 'token' => $token, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
        $this->browser('POST', '/api/v1/auth/password/reset', $payload)->assertUnprocessable();
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subHours(2)]);
        $this->browser('POST', '/api/v1/auth/password/reset', [...$payload, 'email' => $user->email])->assertUnprocessable();
    }

    public function test_unknown_and_suspended_reset_requests_have_same_public_response(): void
    {
        Notification::fake();
        $user = $this->user(['suspended_at' => now()]);
        $this->csrf();
        $unknown = $this->browser('POST', '/api/v1/auth/password/forgot', ['email' => 'unknown@example.test'])->assertOk();
        $suspended = $this->browser('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->assertSame($unknown->json('data'), $suspended->json('data'));
        Notification::assertNothingSent();
    }

    public function test_suspension_blocks_login_existing_sessions_and_tokens(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $this->login($user);
        $user->forceFill(['suspended_at' => now()])->save();
        $this->browser('GET', '/api/v1/auth/me')->assertForbidden();
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertForbidden();
        $this->jar = [];
        $this->csrf();
        $this->browser('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertForbidden();
    }

    public function test_authentication_rate_limiting_uses_real_redis(): void
    {
        $this->csrf();
        for ($i = 0; $i < 5; $i++) {
            $this->browser('POST', '/api/v1/auth/login', ['email' => 'limited-'.md5($this->ip).'@example.test', 'password' => self::PASSWORD])->assertUnprocessable();
        }
        $this->browser('POST', '/api/v1/auth/login', ['email' => 'limited-'.md5($this->ip).'@example.test', 'password' => self::PASSWORD])->assertStatus(429)->assertHeader('Retry-After');
    }
}
