<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityTestCase;

class ApiTokenSecurityTest extends IdentityTestCase
{
    use RefreshDatabase;

    public function test_scoped_token_minting_requires_session_password_and_current_permissions(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $this->login($user);
        $this->select($merchant);
        $payload = ['name' => 'My integration', 'abilities' => ['merchant.view'], 'current_password' => self::PASSWORD, 'expires_in_days' => 1];
        $this->browser('POST', '/api/v1/tokens', [...$payload, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/tokens', [...$payload, 'abilities' => ['*']])->assertUnprocessable();
        $this->browser('POST', '/api/v1/tokens', [...$payload, 'abilities' => ['platform.security']])->assertUnprocessable();
        $response = $this->browser('POST', '/api/v1/tokens', $payload)->assertCreated();
        $raw = $response->json('data.token');
        $this->assertNotSame($raw, PersonalAccessToken::find($response->json('data.id'))->token);
        $this->browser('GET', '/api/v1/tokens')->assertOk()->assertDontSee($raw)->assertDontSee('current_password');
        $this->bearer($raw, 'GET', '/api/v1/merchants/current')->assertOk();
        $this->bearer($raw, 'GET', '/api/v1/tokens')->assertStatus(400);
    }

    public function test_invalid_expired_revoked_and_legacy_tokens_are_rejected(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $this->bearer('invalid-token', 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $legacy = $user->createToken('legacy', ['*']);
        $this->bearer($legacy->plainTextToken, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $expired = $this->token($user, $merchant);
        DB::table('personal_access_tokens')->where('id', explode('|', $expired)[0])->update(['expires_at' => now()->subMinute()]);
        $this->bearer($expired, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $revoked = $this->token($user, $merchant);
        DB::table('personal_access_tokens')->where('id', explode('|', $revoked)[0])->delete();
        $this->bearer($revoked, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_revocation_is_user_and_merchant_scoped(): void
    {
        $user = $this->user();
        $other = $this->user();
        $a = $this->organization($user);
        $b = $this->organization($user);
        $foreign = $this->token($other, $a);
        $foreignId = explode('|', $foreign)[0];
        $ownB = $this->token($user, $b);
        $bId = explode('|', $ownB)[0];
        $ownA = $this->token($user, $a);
        $aId = explode('|', $ownA)[0];
        $this->login($user);
        $this->select($a);
        $this->browser('DELETE', '/api/v1/tokens/'.$foreignId)->assertNotFound();
        $this->browser('DELETE', '/api/v1/tokens/'.$bId)->assertNotFound();
        $this->browser('DELETE', '/api/v1/tokens/'.$aId)->assertOk();
        $this->bearer($ownA, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
    }

    public function test_auth_version_revokes_tokens_without_relying_on_expiration(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $user->increment('auth_version');
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
    }

    public function test_invalid_token_without_json_accept_still_returns_json_401(): void
    {
        $this->get('/api/v1/merchants/current', ['Authorization' => 'Bearer invalid', 'Accept' => '*/*'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
