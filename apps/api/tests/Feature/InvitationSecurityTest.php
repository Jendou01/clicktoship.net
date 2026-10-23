<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Merchants\Infrastructure\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ManagementTestCase;

class InvitationSecurityTest extends ManagementTestCase
{
    use RefreshDatabase;

    private function invite(User $owner, string $merchant, string $email, string $role = 'merchant.viewer'): array
    {
        Notification::fake();
        $this->login($owner);
        $this->select($merchant);
        $id = $this->browser('POST', '/api/v1/team/invitations', ['email' => $email, 'role' => $role])->assertCreated()->json('data.id');
        $token = '';
        Notification::assertSentOnDemand(TeamInvitation::class, function ($n, $channels, $notifiable) use (&$token) {
            parse_str(parse_url($n->toMail($notifiable)->actionUrl, PHP_URL_FRAGMENT), $parts);
            $token = $parts['invitation'];

            return true;
        });

        return [$id, $token];
    }

    public function test_invitation_email_is_encrypted_token_hashed_and_acceptance_single_use(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $u = $this->user();
        [$id,$token] = $this->invite($owner, $m, $u->email);
        $row = DB::table('merchant_invitations')->where('id', $id)->first();
        $this->assertNotSame($token, $row->token_hash);
        $this->assertStringNotContainsString($u->email, $row->email);
        $this->browser('GET', '/api/v1/team/invitations')->assertOk()->assertJsonMissing(['token' => $token]);
        $this->jar = [];
        $this->login($u);
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertOk();
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertUnprocessable();
        $this->select($m);
        $this->browser('GET', '/api/v1/merchants/current')->assertOk();
        $this->browser('POST', '/api/v1/team/invitations', ['email' => 'other@example.test', 'role' => 'merchant.owner'])->assertForbidden();
    }

    public function test_wrong_email_unverified_user_and_csrf_cannot_accept(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $target = $this->user();
        [$id,$token] = $this->invite($owner, $m, $target->email);
        $this->jar = [];
        $this->login($this->user());
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertForbidden();
        $this->jar = [];
        $this->login($this->user(['email_verified_at' => null]));
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertForbidden();
        $this->jar = [];
        $this->login($target);
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token], false)->assertStatus(419);
    }

    public function test_expired_revoked_and_resend_tokens_are_rejected(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $u = $this->user();
        [$id,$old] = $this->invite($owner, $m, $u->email);
        Notification::fake();
        $this->browser('POST', "/api/v1/team/invitations/$id/resend", ['version' => 1])->assertOk();
        $new = '';
        Notification::assertSentOnDemand(TeamInvitation::class, function ($n, $channels, $who) use (&$new) {
            parse_str(parse_url($n->toMail($who)->actionUrl, PHP_URL_FRAGMENT), $p);
            $new = $p['invitation'];

            return true;
        });
        $ownerJar = $this->jar;
        $this->jar = [];
        $this->login($u);
        $targetJar = $this->jar;
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $old])->assertUnprocessable();
        DB::table('merchant_invitations')->where('id', $id)->update(['expires_at' => now()->subSecond()]);
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $new])->assertUnprocessable();
        $this->jar = $ownerJar;
        $this->browser('DELETE', "/api/v1/team/invitations/$id", ['version' => 2])->assertOk();
        $this->jar = $targetJar;
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $new])->assertUnprocessable();
    }

    public function test_inviter_demotion_and_role_scope_escalation_are_rechecked(): void
    {
        $owner = $this->user();
        $m = $this->organization($owner);
        $u = $this->user();
        [$id,$token] = $this->invite($owner, $m, $u->email);
        $this->browser('POST', '/api/v1/team/invitations', ['email' => 'new@example.test', 'role' => 'platform.super_admin'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/team/invitations', ['email' => $u->email, 'role' => 'merchant.viewer'])->assertConflict();
        DB::table('merchant_role_assignments')->where('merchant_id', $m)->update(['role_id' => DB::table('roles')->where('code', 'merchant.viewer')->value('id')]);
        $this->jar = [];
        $this->login($u);
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertForbidden();
        $this->assertNull(DB::table('merchant_invitations')->where('id', $id)->value('accepted_at'));
    }

    public function test_foreign_invitation_ids_are_hidden_and_status_is_versioned(): void
    {
        $a = $this->user();
        $m = $this->organization($a);
        [$id,$token] = $this->invite($a, $m, 'someone@example.test');
        $this->browser('DELETE', "/api/v1/team/invitations/$id", ['version' => 9])->assertConflict();
        $b = $this->user();
        $other = $this->organization($b);
        $this->jar = [];
        $this->login($b);
        $this->select($other);
        $this->browser('GET', '/api/v1/team/invitations')->assertJsonCount(0, 'data.invitations');
        $this->browser('POST', "/api/v1/team/invitations/$id/resend", ['version' => 1])->assertNotFound();
        $this->browser('DELETE', "/api/v1/team/invitations/$id", ['version' => 1])->assertNotFound();
    }

    public function test_existing_member_invitation_never_overwrites_role(): void
    {
        $a = $this->user();
        $m = $this->organization($a);
        [$id,$token] = $this->invite($a, $m, $a->email, 'merchant.viewer');
        $this->browser('POST', '/api/v1/auth/invitations/accept', ['token' => $token])->assertConflict();
        $this->assertSame('merchant.owner', DB::table('merchant_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.merchant_id', $m)->value('r.code'));
    }
}
