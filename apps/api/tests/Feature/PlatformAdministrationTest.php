<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

class PlatformAdministrationTest extends ManagementTestCase
{
    use RefreshDatabase;

    public function test_platform_roles_cannot_bypass_target_grants_even_super_admin(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        foreach (['platform.super_admin', 'platform.operations', 'platform.finance', 'platform.support'] as $role) {
            $u = $this->platformUser($role);
            $this->adminLogin($u);
            $this->browser('GET', '/api/v1/admin/merchants')->assertOk();
            $this->browser('GET', "/api/v1/admin/merchants/$m")->assertUnprocessable();
            $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=".Str::uuid())->assertForbidden();
            $this->browser('GET', '/api/v1/merchants/current')->assertConflict();
        }
    }

    public function test_grant_requires_independent_approval_scope_and_same_session(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $m = $this->organization($this->user());
        $this->adminLogin($a);
        $jar = $this->jar;
        $id = $this->browser('POST', '/api/v1/admin/access-requests', ['merchant_id' => $m, 'capabilities' => ['platform.merchant.view'], 'reason' => 'Synthetic customer support case'])->assertCreated()->json('data.id');
        $this->browser('POST', "/api/v1/admin/access-requests/$id/approve", ['version' => 1])->assertForbidden();
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
        $this->adminLogin($b);
        $this->browser('POST', "/api/v1/admin/access-requests/$id/approve", ['version' => 1])->assertOk();
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
        $this->jar = $jar;
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertOk()->assertJsonPath('data.shipping_eligibility.eligible', false);
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 1, 'status' => 'suspended', 'reason' => 'Synthetic lifecycle review'])->assertForbidden();
        $this->jar = [];
        $this->login($a); // New session has neither step-up nor the old grant.
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
    }

    public function test_grant_revocation_wrong_target_expiry_and_approver_role_loss(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $m = $this->organization($this->user());
        $other = $this->organization($this->user());
        $id = $this->grant($a, $b, $m);
        $this->browser('GET', "/api/v1/admin/merchants/$other?access_grant=$id")->assertForbidden();
        DB::table('platform_access_grants')->where('id', $id)->update(['expires_at' => now()->addSecond()]);
        $this->travel(2)->seconds();
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
        $this->travelBack();
        DB::table('platform_access_grants')->where('id', $id)->update(['expires_at' => now()->addMinutes(10)]);
        DB::table('platform_role_assignments')->where('user_id', $b->id)->delete();
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
        $this->browser('DELETE', "/api/v1/admin/access-grants/$id", ['version' => 2])->assertOk();
        $this->browser('GET', "/api/v1/admin/merchants/$m?access_grant=$id")->assertForbidden();
    }

    public function test_closed_suspended_active_and_verification_are_independent_and_revoked_tokens_stay_revoked(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $other = $this->organization($owner);
        $token = $this->token($owner, $m);
        $otherToken = $this->token($owner, $other);
        $a = $this->platformUser();
        $b = $this->platformUser();
        $id = $this->grant($a, $b, $m);
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 1, 'status' => 'suspended', 'reason' => 'Synthetic restriction imposed'])->assertOk()->assertJsonPath('data.version', 2);
        $adminJar = $this->jar;
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
        $this->bearer($otherToken, 'GET', '/api/v1/merchants/current')->assertOk();
        $this->jar = $adminJar;
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 1, 'status' => 'active', 'reason' => 'Synthetic restriction removed'])->assertConflict();
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 2, 'status' => 'active', 'reason' => 'Synthetic restriction removed'])->assertOk();
        $this->assertSame('unverified', DB::table('merchants')->where('id', $m)->value('verification_status'));
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
        $this->jar = $adminJar;
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 3, 'status' => 'closed', 'reason' => 'Synthetic account closure'])->assertOk();
        $this->browser('PATCH', "/api/v1/admin/merchants/$m/status", ['access_grant' => $id, 'version' => 4, 'status' => 'active', 'reason' => 'Synthetic account reopening'])->assertConflict();
    }

    public function test_verification_reviews_are_immutable_and_rejection_allows_resubmission(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $this->login($owner);
        $this->select($m);
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload())->assertOk();
        $s = $this->browser('POST', '/api/v1/merchants/current/verification/submissions', ['version' => 2])->assertCreated()->json('data.submissions.0.id');
        $ownerJar = $this->jar;
        $a = $this->platformUser();
        $b = $this->platformUser();
        $id = $this->grant($a, $b, $m);
        $this->browser('GET', "/api/v1/admin/merchants/$m/verification?access_grant=$id")->assertOk()->assertJsonPath('data.submissions.0.snapshot.legal_name', 'Synthetic Company');
        $this->browser('POST', "/api/v1/admin/merchants/$m/verification/$s/decision", ['access_grant' => $id, 'version' => 3, 'outcome' => 'rejected', 'reason' => 'Please correct business details'])->assertOk();
        $this->browser('POST', "/api/v1/admin/merchants/$m/verification/$s/decision", ['access_grant' => $id, 'version' => 4, 'outcome' => 'verified', 'reason' => 'Duplicate synthetic decision'])->assertConflict();
        $this->jar = $ownerJar;
        $this->browser('GET', '/api/v1/merchants/current/verification')->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.submissions.0.outcome', 'rejected');
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload(1))->assertOk();
        $this->browser('POST', '/api/v1/merchants/current/verification/submissions', ['version' => 5])->assertCreated()->assertJsonCount(2, 'data.submissions');
        $this->assertSame(1, DB::table('merchant_verification_decisions')->count());
    }

    public function test_review_of_own_merchant_is_denied(): void
    {
        $a = $this->platformUser();
        $b = $this->platformUser();
        $m = $this->organization($a);
        $this->login($a);
        $this->select($m);
        $this->browser('PATCH', '/api/v1/merchants/current/profile', $this->profilePayload())->assertOk();
        $s = $this->browser('POST', '/api/v1/merchants/current/verification/submissions', ['version' => 2])->assertCreated()->json('data.submissions.0.id');
        $id = $this->grant($a, $b, $m);
        $this->browser('POST', "/api/v1/admin/merchants/$m/verification/$s/decision", ['access_grant' => $id, 'version' => 3, 'outcome' => 'verified', 'reason' => 'Synthetic self certification'])->assertForbidden();
    }

    public function test_last_usable_super_admin_and_owner_cannot_be_disabled(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('PATCH', "/api/v1/admin/users/$a->id/platform-roles", ['version' => 1, 'role' => 'platform.super_admin', 'assigned' => false, 'reason' => 'Synthetic administrator removal'])->assertConflict();
        $owner = $this->user();
        $this->organization($owner);
        $this->browser('PATCH', "/api/v1/admin/users/$owner->id/status", ['version' => 1, 'suspended' => true, 'reason' => 'Synthetic account suspension'])->assertConflict();
        $this->assertNull($owner->fresh()->suspended_at);
    }

    public function test_user_suspension_revokes_sessions_and_tokens_but_reactivation_does_not_restore_them(): void
    {
        $a = $this->platformUser();
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        DB::table('merchant_role_assignments')->where('merchant_id', $m)->update(['role_id' => DB::table('roles')->where('code', 'merchant.viewer')->value('id')]);
        $this->login($u);
        $oldJar = $this->jar;
        $this->adminLogin($a);
        $adminJar = $this->jar;
        $this->browser('PATCH', "/api/v1/admin/users/$u->id/status", ['version' => 1, 'suspended' => true, 'reason' => 'Synthetic abuse suspension'])->assertOk();
        $this->jar = $oldJar;
        $this->browser('GET', '/api/v1/auth/me')->assertForbidden();
        $this->jar = $adminJar;
        $this->browser('PATCH', "/api/v1/admin/users/$u->id/status", ['version' => 2, 'suspended' => false, 'reason' => 'Synthetic abuse review complete'])->assertOk();
        $this->jar = $oldJar;
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->bearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_platform_roles_require_mfa_enrolled_target_and_revoke_old_sessions(): void
    {
        $a = $this->platformUser();
        $u = $this->user();
        $this->adminLogin($a);
        $this->browser('PATCH', "/api/v1/admin/users/$u->id/platform-roles", ['version' => 1, 'role' => 'platform.support', 'assigned' => true, 'reason' => 'Synthetic new support operator'])->assertConflict();
        $b = $this->platformUser('platform.support');
        $this->browser('PATCH', "/api/v1/admin/users/$b->id/platform-roles", ['version' => 1, 'role' => 'merchant.owner', 'assigned' => true, 'reason' => 'Synthetic wrong scope attempt'])->assertUnprocessable();
        $this->browser('PATCH', "/api/v1/admin/users/$b->id/platform-roles", ['version' => 1, 'role' => 'platform.operations', 'assigned' => true, 'reason' => 'Synthetic new operations role'])->assertOk()->assertJsonPath('data.version', 2);
        $this->browser('PATCH', "/api/v1/admin/users/$b->id/platform-roles", ['version' => 1, 'role' => 'platform.finance', 'assigned' => true, 'reason' => 'Synthetic stale version grant'])->assertConflict();
    }

    public function test_platform_lists_do_not_export_user_pii_or_unscoped_merchant_audits(): void
    {
        $a = $this->platformUser();
        $this->adminLogin($a);
        $this->browser('GET', '/api/v1/admin/users')->assertOk()->assertJsonMissing(['email' => $a->email]);
        $this->browser('GET', '/api/v1/admin/audit-events')->assertOk();
        $this->browser('POST', '/api/v1/admin/access-requests', ['merchant_id' => $this->organization($this->user()), 'capabilities' => ['*'], 'reason' => 'Synthetic wildcard support request'])->assertUnprocessable();
    }

    public function test_support_access_does_not_disclose_contact_or_business_identifiers(): void
    {
        $owner = $this->user();
        $merchant = $this->organization($owner);
        $this->login($owner);
        $this->select($merchant);
        $this->browser('PATCH', '/api/v1/merchants/current/profile', [...$this->profilePayload(), 'registration_number' => 'SYNTHETIC-REGISTRATION'])->assertOk();
        $support = $this->platformUser('platform.support');
        $approver = $this->platformUser();
        $grant = $this->grant($support, $approver, $merchant, ['platform.merchant.view']);
        $this->browser('GET', "/api/v1/admin/merchants/$merchant?access_grant=$grant")->assertOk()
            ->assertJsonPath('data.profile.legal_name', 'Synthetic Company')->assertJsonMissingPath('data.profile.contact_email')->assertJsonMissingPath('data.profile.contact_phone')->assertJsonMissingPath('data.profile.registration_number');
        $this->browser('GET', "/api/v1/admin/merchants/$merchant/verification?access_grant=$grant")->assertForbidden();
    }
}
