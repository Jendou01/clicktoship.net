<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagementTestCase;

class MerchantManagementTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function merchantLogin(): string
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->login($u);
        $this->select($m);

        return $m;
    }

    public function test_unverified_business_can_use_dashboard_but_never_shipping(): void
    {
        $m = $this->merchantLogin();
        $this->browser('GET', '/api/v1/merchants/current')->assertOk()->assertJsonPath('data.merchant.account_status', 'active')->assertJsonPath('data.merchant.verification_status', 'unverified')->assertJsonPath('data.shipping_eligibility.eligible', false);
        $this->browser('GET', '/api/v1/merchants/current/eligibility')->assertOk()->assertJsonPath('data.shipping_eligibility.eligible', false);
        DB::table('merchants')->where('id', $m)->update(['verification_status' => 'verified']);
        $this->browser('GET', '/api/v1/merchants/current/eligibility')->assertJsonPath('data.shipping_eligibility.eligible', false);
    }

    public function test_profile_is_encrypted_and_versioned_and_cannot_change_account_state(): void
    {
        $m = $this->merchantLogin();
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload())->assertOk()->assertJsonPath('data.profile.version', 1);
        $this->assertStringNotContainsString('Synthetic', DB::table('merchant_profiles')->where('merchant_id', $m)->value('payload'));
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload())->assertConflict();
        $this->browser('PATCH', '/api/v1/merchants/current/profile', [...$this->profilePayload(1), 'status' => 'active'])->assertUnprocessable();
        $this->browser('PATCH', '/api/v1/merchants/current/profile', [...$this->profilePayload(1), 'country' => 'ZZ'])->assertUnprocessable();
    }

    public function test_foreign_addresses_are_hidden_and_defaults_are_tenant_scoped(): void
    {
        $a = $this->merchantLogin();
        $id = $this->browser('POST', '/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data.address.id');
        $this->browser('GET', "/api/v1/addresses/$id")->assertOk();
        $u = $this->user();
        $b = $this->organization($u);
        $this->jar = [];
        $this->login($u);
        $this->select($b);
        $this->browser('GET', "/api/v1/addresses/$id")->assertNotFound();
        $this->browser('PATCH', "/api/v1/addresses/$id", $this->addressPayload(1))->assertNotFound();
        $this->browser('DELETE', "/api/v1/addresses/$id", ['version' => 1])->assertNotFound();
        $this->browser('PATCH', '/api/v1/merchants/current/settings', ['version' => 0, 'locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'origin_address_id' => $id])->assertNotFound();
        $this->browser('GET', '/api/v1/addresses')->assertJsonCount(0, 'data.addresses');
    }

    public function test_address_defaults_prevent_archive_until_cleared_and_reject_stale_versions(): void
    {
        $this->merchantLogin();
        $id = $this->browser('POST', '/api/v1/addresses', $this->addressPayload())->assertCreated()->json('data.address.id');
        $this->browser('PATCH', '/api/v1/merchants/current/settings', ['version' => 0, 'locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'origin_address_id' => $id, 'return_address_id' => $id])->assertOk();
        $this->browser('DELETE', "/api/v1/addresses/$id", ['version' => 1])->assertConflict();
        $this->browser('PATCH', '/api/v1/merchants/current/settings', ['version' => 0, 'locale' => 'en', 'timezone' => 'UTC'])->assertConflict();
        $this->browser('PATCH', '/api/v1/merchants/current/settings', ['version' => 1, 'locale' => 'en', 'timezone' => 'UTC', 'origin_address_id' => null, 'return_address_id' => null])->assertOk();
        $this->browser('PATCH', "/api/v1/addresses/$id", $this->addressPayload(0))->assertConflict();
        $this->browser('DELETE', "/api/v1/addresses/$id", ['version' => 1])->assertOk();
        $this->browser('GET', "/api/v1/addresses/$id")->assertNotFound();
    }

    public function test_country_phone_timezone_validation_and_csrf(): void
    {
        $this->merchantLogin();
        $this->browser('POST', '/api/v1/addresses', [...$this->addressPayload(), 'postal_code' => 'bad'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/addresses', [...$this->addressPayload(), 'country' => 'US', 'postal_code' => '12345'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/addresses', [...$this->addressPayload(), 'contact_phone' => 'not a phone'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/addresses', $this->addressPayload(), false)->assertStatus(419);
        $this->browser('PATCH', '/api/v1/merchants/current/settings', ['version' => 0, 'locale' => 'en', 'timezone' => 'invalid'])->assertUnprocessable();
    }

    public function test_six_merchant_roles_have_server_enforced_management_matrix(): void
    {
        foreach (['merchant.owner' => true, 'merchant.admin' => true, 'merchant.shipping_manager' => false, 'merchant.shipping_operator' => false, 'merchant.finance_manager' => false, 'merchant.viewer' => false] as $role => $canProfile) {
            $u = $this->user();
            $m = $this->organization($u);
            DB::table('merchant_role_assignments')->where('merchant_id', $m)->update(['role_id' => DB::table('roles')->where('code', $role)->value('id')]);
            $this->jar = [];
            $this->login($u);
            $this->select($m);
            $response = $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload());
            $response->assertStatus($canProfile ? 200 : 403);
            $response = $this->browser('POST', '/api/v1/addresses', $this->addressPayload());
            $response->assertStatus(in_array($role, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager']) ? 201 : 403);
        }
    }

    public function test_token_abilities_intersect_new_management_permissions(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m, ['addresses.view']);
        $this->bearer($token, 'GET', '/api/v1/addresses')->assertOk();
        $this->bearer($token, 'POST', '/api/v1/addresses', $this->addressPayload())->assertForbidden();
        $this->bearer($token, 'GET', '/api/v1/merchants/current/profile')->assertForbidden();
    }

    public function test_submission_history_is_separate_from_activation_and_freezes_profile(): void
    {
        $m = $this->merchantLogin();
        $this->browser('POST', '/api/v1/merchants/current/verification/submissions')->assertUnprocessable();
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload())->assertOk();
        $this->browser('POST', '/api/v1/merchants/current/verification/submissions', ['version' => 2])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->browser('POST', '/api/v1/merchants/current/verification/submissions', ['version' => 3])->assertConflict();
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload(1))->assertConflict();
        $this->browser('GET', '/api/v1/merchants/current')->assertOk()->assertJsonPath('data.merchant.account_status', 'active');
        $this->assertSame(1, DB::table('merchant_verification_submissions')->where('merchant_id', $m)->count());
    }

    public function test_unverified_browser_without_json_accept_receives_forbidden_instead_of_web_redirect(): void
    {
        $this->login($this->user(['email_verified_at' => null]));
        $this->resetHttpState();
        $this->call('GET', '/api/v1/merchants', [], $this->jar, [], ['HTTP_ORIGIN' => 'http://localhost:8080', 'HTTP_ACCEPT' => '*/*', 'REMOTE_ADDR' => $this->ip])
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
