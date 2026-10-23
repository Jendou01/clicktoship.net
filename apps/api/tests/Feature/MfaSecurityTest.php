<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Mfa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OTPHP\TOTP;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ManagementTestCase;

class MfaSecurityTest extends ManagementTestCase
{
    use RefreshDatabase;

    public function test_platform_admin_without_mfa_or_fresh_challenge_is_denied(): void
    {
        $u = $this->platformUser();
        $this->login($u);
        $this->browser('GET', '/api/v1/admin/merchants')->assertForbidden();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => TOTP::createFromSecret(self::SECRET)->at(now()->timestamp)])->assertOk();
        $this->browser('GET', '/api/v1/admin/merchants')->assertOk();
        DB::table('auth_sessions')->where('user_id', $u->id)->update(['mfa_verified_at' => now()->subMinutes(16)]);
        $this->browser('GET', '/api/v1/admin/merchants')->assertForbidden();
    }

    public function test_enrollment_encrypts_secret_recovery_is_single_use_and_totp_cannot_replay(): void
    {
        $u = $this->user();
        $this->login($u);
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment', ['current_password' => self::PASSWORD])->assertCreated()->json('data.secret');
        $this->assertStringNotContainsString($secret, DB::table('user_mfa_credentials')->where('user_id', $u->id)->value('pending_secret'));
        $code = TOTP::createFromSecret($secret)->at(now()->timestamp);
        $codes = $this->browser('POST', '/api/v1/auth/mfa/confirm', ['current_password' => self::PASSWORD, 'code' => $code])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotSame($codes[0], DB::table('mfa_recovery_codes')->where('user_id', $u->id)->value('code_hash'));
        $this->browser('GET', '/api/v1/auth/mfa')->assertJsonPath('data.enabled', true)->assertJsonMissing(['secret' => $secret]);
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => $code])->assertUnprocessable();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => $codes[0]])->assertOk();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => $codes[0]])->assertUnprocessable();
    }

    public function test_wrong_password_code_pending_expiry_and_mfa_rate_limit(): void
    {
        $u = $this->user();
        $this->login($u);
        $this->browser('POST', '/api/v1/auth/mfa/enrollment', ['current_password' => 'wrong'])->assertUnprocessable();
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment', ['current_password' => self::PASSWORD])->assertCreated()->json('data.secret');
        DB::table('user_mfa_credentials')->where('user_id', $u->id)->update(['pending_expires_at' => now()->subMinute()]);
        $this->browser('POST', '/api/v1/auth/mfa/confirm', ['current_password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->at(now()->timestamp)])->assertUnprocessable();
        for ($i = 0; $i < 3; $i++) {
            $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => '000000'])->assertUnprocessable();
        }
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => '000000'])->assertStatus(429);
    }

    public function test_mfa_confirmation_revokes_other_sessions_and_api_tokens(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        $this->login($u);
        $old = $this->jar;
        $this->jar = [];
        $this->login($u);
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment', ['current_password' => self::PASSWORD])->assertCreated()->json('data.secret');
        $this->browser('POST', '/api/v1/auth/mfa/confirm', ['current_password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->at(now()->timestamp)])->assertOk();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        $this->jar = $old;
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_platform_administrators_cannot_disable_mfa_and_bearer_cannot_manage_it(): void
    {
        $u = $this->platformUser();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        $this->adminLogin($u);
        $this->browser('DELETE', '/api/v1/auth/mfa', ['current_password' => self::PASSWORD])->assertConflict();
        $this->bearer($token, 'GET', '/api/v1/auth/mfa')->assertStatus(400);
        $this->bearer($token, 'GET', '/api/v1/admin/merchants')->assertStatus(400);
    }

    public function test_non_platform_user_can_rotate_codes_and_disable_with_fresh_mfa(): void
    {
        $u = $this->user();
        $this->login($u);
        $secret = $this->browser('POST', '/api/v1/auth/mfa/enrollment', ['current_password' => self::PASSWORD])->assertCreated()->json('data.secret');
        $codes = $this->browser('POST', '/api/v1/auth/mfa/confirm', ['current_password' => self::PASSWORD, 'code' => TOTP::createFromSecret($secret)->at(now()->timestamp)])->assertOk()->json('data.recovery_codes');
        $this->browser('POST', '/api/v1/auth/mfa/recovery-codes', ['current_password' => self::PASSWORD])->assertOk();
        $this->browser('POST', '/api/v1/auth/mfa/challenge', ['current_password' => self::PASSWORD, 'code' => $codes[0]])->assertUnprocessable();
        $this->browser('DELETE', '/api/v1/auth/mfa', ['current_password' => self::PASSWORD])->assertOk();
        $this->browser('GET', '/api/v1/auth/mfa')->assertJsonPath('data.enabled', false);
    }

    public function test_rfc6238_known_totp_vector(): void
    {
        $totp = TOTP::createFromSecret('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
        $totp->setDigits(8);
        $this->assertSame('94287082', $totp->at(59));
    }

    public function test_mfa_mutation_rechecks_identity_after_concurrent_password_reset(): void
    {
        $user = $this->user();
        $this->login($user);
        $session = app('session.store');
        $request = Request::create('/api/v1/auth/mfa/enrollment', 'POST', ['current_password' => self::PASSWORD]);
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($session);
        DB::table('users')->where('id', $user->id)->update(['auth_version' => $user->auth_version + 1]);
        DB::table('auth_sessions')->where('user_id', $user->id)->update(['revoked_at' => now()]);
        try {
            app(Mfa::class)->enroll($request);
            $this->fail('Stale authenticated request modified MFA');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('user_mfa_credentials', ['user_id' => $user->id]);
    }
}
