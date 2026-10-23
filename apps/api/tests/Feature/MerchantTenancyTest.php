<?php

namespace Tests\Feature;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IdentityTestCase;

class MerchantTenancyTest extends IdentityTestCase
{
    use RefreshDatabase;

    public function test_protected_endpoints_reject_anonymous_requests(): void
    {
        foreach (['/auth/me', '/auth/memberships', '/merchants', '/merchants/current', '/roles', '/permissions', '/team', '/tokens'] as $path) {
            $this->bearer('', 'GET', '/api/v1'.$path)->assertUnauthorized();
        }
    }

    public function test_merchant_creation_and_switching_are_verified_session_operations(): void
    {
        $user = $this->user();
        $foreign = $this->organization($this->user());
        $this->login($user);
        $first = $this->browser('POST', '/api/v1/merchants', ['name' => 'First'])->assertCreated()->json('data.merchant.id');
        $second = $this->browser('POST', '/api/v1/merchants', ['name' => 'Second'])->assertCreated()->json('data.merchant.id');
        $this->select($first);
        $this->browser('GET', '/api/v1/merchants/current')->assertJsonPath('data.merchant.id', $first);
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $foreign])->assertNotFound();
        $this->browser('GET', '/api/v1/merchants/current')->assertJsonPath('data.merchant.id', $first);
        $this->select($second);
        $this->browser('GET', '/api/v1/merchants/current')->assertJsonPath('data.merchant.id', $second);
        $this->browser('GET', '/api/v1/merchants')->assertJsonCount(2, 'data.merchants');
    }

    public function test_active_merchant_is_isolated_between_browser_sessions(): void
    {
        $user = $this->user();
        $a = $this->organization($user, 'A');
        $b = $this->organization($user, 'B');
        $this->login($user);
        $this->select($a);
        $one = $this->jar;
        $this->jar = [];
        $this->login($user);
        $this->select($b);
        $this->browser('GET', '/api/v1/merchants/current')->assertJsonPath('data.merchant.id', $b);
        $this->jar = $one;
        $this->browser('GET', '/api/v1/merchants/current')->assertJsonPath('data.merchant.id', $a);
    }

    public function test_no_selected_merchant_fails_closed_and_mass_assignment_is_rejected(): void
    {
        $this->login($this->user());
        $this->browser('GET', '/api/v1/merchants/current')->assertConflict();
        $this->browser('POST', '/api/v1/merchants', ['name' => 'Evil', 'status' => 'active', 'role' => 'platform.super_admin'])->assertUnprocessable();
    }

    public function test_foreign_membership_ids_are_hidden_from_all_operations(): void
    {
        $user = $this->user();
        $a = $this->organization($user);
        $b = $this->organization($this->user());
        $foreign = DB::table('merchant_users')->where('merchant_id', $b)->value('id');
        $token = $this->token($user, $a);
        $this->bearer($token, 'GET', '/api/v1/team/'.$foreign)->assertNotFound();
        $this->bearer($token, 'PATCH', '/api/v1/team/'.$foreign, ['version' => 1, 'status' => 'removed'])->assertNotFound();
        $this->bearer($token, 'DELETE', '/api/v1/team/'.$foreign, ['version' => 1])->assertNotFound();
        $this->bearer($token, 'GET', '/api/v1/team')->assertJsonCount(1, 'data.memberships');
    }

    public function test_token_context_cannot_be_switched_or_forged(): void
    {
        $user = $this->user();
        $a = $this->organization($user);
        $b = $this->organization($user);
        $token = $this->token($user, $a);
        $this->bearer($token, 'GET', '/api/v1/merchants')->assertJsonCount(1, 'data.merchants')->assertJsonPath('data.merchants.0.id', $a);
        $this->bearer($token, 'GET', '/api/v1/merchants/current', [], ['X-Merchant-ID' => $b])->assertConflict();
        $this->bearer($token, 'POST', '/api/v1/merchants/select', ['merchant_id' => $b])->assertStatus(400);
        $this->bearer($token, 'POST', '/api/v1/team', ['email' => 'a@example.test', 'role' => 'merchant.viewer', 'merchant_id' => $b])->assertUnprocessable();
    }

    public function test_platform_super_admin_has_no_implicit_merchant_bypass(): void
    {
        $user = $this->user();
        $foreign = $this->organization($this->user());
        DB::table('platform_role_assignments')->insert(['id' => Str::uuid(), 'user_id' => $user->id, 'role_id' => DB::table('roles')->where('code', 'platform.super_admin')->value('id'), 'role_scope' => 'platform', 'created_at' => now()]);
        $this->login($user);
        $this->browser('POST', '/api/v1/merchants/select', ['merchant_id' => $foreign])->assertNotFound();
        $this->bearer($this->token($user, $foreign), 'GET', '/api/v1/merchants/current')->assertForbidden();
    }

    public function test_role_catalogue_is_read_only_and_contains_only_merchant_roles(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $this->bearer($token, 'GET', '/api/v1/roles')->assertOk()->assertJsonCount(6, 'data.roles')->assertDontSee('platform.super_admin');
        $this->bearer($token, 'POST', '/api/v1/roles', ['code' => 'merchant.god'])->assertStatus(405);
        $this->assertDatabaseCount('roles', 10);
    }

    public function test_read_only_role_and_token_abilities_both_restrict_actions(): void
    {
        $owner = $this->user();
        $viewer = $this->user();
        $merchant = $this->organization($owner);
        $ownerToken = $this->token($owner, $merchant);
        $this->bearer($ownerToken, 'POST', '/api/v1/team', ['email' => $viewer->email, 'role' => 'merchant.viewer'])->assertCreated();
        $viewerToken = $this->token($viewer, $merchant);
        $this->bearer($viewerToken, 'GET', '/api/v1/team')->assertOk();
        $this->bearer($viewerToken, 'POST', '/api/v1/team', ['email' => 'other@example.test', 'role' => 'merchant.admin'])->assertForbidden();
        $narrow = $this->token($owner, $merchant, ['merchant.view']);
        $this->bearer($narrow, 'GET', '/api/v1/merchants/current')->assertOk();
        $this->bearer($narrow, 'GET', '/api/v1/team')->assertForbidden();
    }

    public function test_platform_role_and_owner_promotion_cannot_be_granted_by_admin(): void
    {
        $owner = $this->user();
        $admin = $this->user();
        $viewer = $this->user();
        $merchant = $this->organization($owner);
        $ownerToken = $this->token($owner, $merchant);
        $adminId = $this->bearer($ownerToken, 'POST', '/api/v1/team', ['email' => $admin->email, 'role' => 'merchant.admin'])->assertCreated()->json('data.membership.id');
        $adminToken = $this->token($admin, $merchant);
        $this->bearer($adminToken, 'POST', '/api/v1/team', ['email' => $viewer->email, 'role' => 'merchant.owner'])->assertForbidden();
        $this->bearer($adminToken, 'POST', '/api/v1/team', ['email' => $viewer->email, 'role' => 'platform.super_admin'])->assertUnprocessable();
        $ownerId = DB::table('merchant_users')->where('merchant_id', $merchant)->where('user_id', $owner->id)->value('id');
        $this->bearer($adminToken, 'PATCH', '/api/v1/team/'.$ownerId, ['version' => 1, 'role' => 'merchant.viewer'])->assertForbidden();
        $this->bearer($adminToken, 'PATCH', '/api/v1/team/'.$adminId, ['version' => 1, 'role' => 'merchant.owner'])->assertForbidden();
    }

    public function test_last_owner_cannot_be_removed_demoted_or_suspended(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $id = DB::table('merchant_users')->where('merchant_id', $merchant)->value('id');
        foreach ([['status' => 'removed'], ['status' => 'suspended'], ['role' => 'merchant.viewer']] as $change) {
            $this->bearer($token, 'PATCH', '/api/v1/team/'.$id, ['version' => 1, ...$change])->assertConflict();
        }
        $this->assertDatabaseHas('merchant_users', ['id' => $id, 'version' => 1, 'status' => 'active']);
    }

    public function test_membership_version_conflicts_and_removal_revoke_existing_tokens(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $merchant = $this->organization($owner);
        $ownerToken = $this->token($owner, $merchant);
        $id = $this->bearer($ownerToken, 'POST', '/api/v1/team', ['email' => $member->email, 'role' => 'merchant.viewer'])->assertCreated()->json('data.membership.id');
        $memberToken = $this->token($member, $merchant);
        $this->bearer($ownerToken, 'PATCH', '/api/v1/team/'.$id, ['version' => 1, 'status' => 'suspended'])->assertOk()->assertJsonPath('data.membership.version', 2);
        $this->bearer($memberToken, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
        $this->bearer($ownerToken, 'PATCH', '/api/v1/team/'.$id, ['version' => 2, 'status' => 'active'])->assertOk();
        $this->bearer($memberToken, 'GET', '/api/v1/merchants/current')->assertUnauthorized();
        $this->bearer($ownerToken, 'PATCH', '/api/v1/team/'.$id, ['version' => 1, 'status' => 'active'])->assertConflict();
    }

    public function test_merchant_suspension_blocks_existing_session_and_token_context(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $token = $this->token($user, $merchant);
        $this->login($user);
        $this->select($merchant);
        DB::table('merchants')->where('id', $merchant)->update(['status' => 'suspended']);
        $this->browser('GET', '/api/v1/merchants/current')->assertNotFound();
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertForbidden();
    }

    public function test_scoped_queries_and_request_context_fail_closed(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $this->assertSame(0, Membership::count());
        $this->bearer($this->token($user, $merchant), 'GET', '/api/v1/team')->assertOk();
        $this->assertNull(app(MerchantContext::class)->id());
        $this->assertSame(0, Membership::count());
    }

    public function test_unscoped_tenant_model_creation_is_rejected(): void
    {
        $user = $this->user();
        $this->expectException(\LogicException::class);
        Membership::create(['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_team_pagination_is_bounded_and_merchant_scoped(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $this->organization($this->user());
        $token = $this->token($user, $merchant);
        $this->bearer($token, 'GET', '/api/v1/team?per_page=1')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->bearer($token, 'GET', '/api/v1/team?per_page=10000')->assertUnprocessable();
    }
}
