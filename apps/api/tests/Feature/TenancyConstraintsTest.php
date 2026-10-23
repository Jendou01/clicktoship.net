<?php

namespace Tests\Feature;

use App\Models\PersonalAccessToken;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Jobs\UseMerchantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\IdentityTestCase;

class TenancyConstraintsTest extends IdentityTestCase
{
    use RefreshDatabase;

    public function test_cross_merchant_role_assignment_is_rejected_by_postgres(): void
    {
        $a = $this->organization($this->user());
        $b = $this->organization($this->user());
        $member = DB::table('merchant_users')->where('merchant_id', $a)->value('id');
        DB::table('merchant_role_assignments')->where('membership_id', $member)->delete();
        $this->expectException(QueryException::class);
        DB::table('merchant_role_assignments')->insert(['id' => Str::uuid(), 'merchant_id' => $b, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'merchant.viewer')->value('id'), 'role_scope' => 'merchant']);
    }

    public function test_platform_role_cannot_be_assigned_to_a_merchant_member_even_directly(): void
    {
        $a = $this->organization($this->user());
        $member = DB::table('merchant_users')->where('merchant_id', $a)->value('id');
        DB::table('merchant_role_assignments')->where('membership_id', $member)->delete();
        $this->expectException(QueryException::class);
        DB::table('merchant_role_assignments')->insert(['id' => Str::uuid(), 'merchant_id' => $a, 'membership_id' => $member, 'role_id' => DB::table('roles')->where('code', 'platform.super_admin')->value('id'), 'role_scope' => 'platform']);
    }

    public function test_audit_updates_are_rejected_by_postgres(): void
    {
        $user = $this->user();
        Audit::record('test.event', $user->id);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->update(['action' => 'altered']);
    }

    public function test_audit_deletions_are_rejected_by_postgres(): void
    {
        $user = $this->user();
        Audit::record('test.event', $user->id);
        $this->expectException(QueryException::class);
        DB::table('audit_logs')->delete();
    }

    public function test_case_folded_email_uniqueness_is_a_database_constraint(): void
    {
        $user = $this->user();
        $this->expectException(QueryException::class);
        $this->user(['email' => strtoupper($user->email)]);
    }

    public function test_background_context_restores_and_clears_even_on_exception(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $middleware = new UseMerchantContext($merchant, $user->id, 'merchant.view');
        try {
            $middleware->handle(new \stdClass, function () use ($merchant): void {
                $this->assertSame($merchant, app(MerchantContext::class)->id());
                throw new \RuntimeException('Synthetic job failure');
            });
        } catch (\RuntimeException) {
        }
        $this->assertNull(app(MerchantContext::class)->id());
    }

    public function test_background_job_rechecks_revoked_membership_before_execution(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $middleware = new UseMerchantContext($merchant, $user->id, 'merchant.view');
        DB::table('merchant_users')->where('merchant_id', $merchant)->update(['status' => 'removed']);
        try {
            $middleware->handle(new \stdClass, function (): void {
                $this->fail('Revoked job executed');
            });
            $this->fail('Revoked membership was accepted');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertNull(app(MerchantContext::class)->id());
    }

    public function test_request_without_merchant_middleware_clears_stale_context(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        app(MerchantContext::class)->establish($user, $merchant);
        $this->bearer('', 'GET', '/api/v1/health/live')->assertOk();
        $this->assertNull(app(MerchantContext::class)->id());
    }

    public function test_membership_user_identity_cannot_be_reassigned_directly(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $other = $this->user();
        $this->expectException(QueryException::class);
        DB::table('merchant_users')->where('merchant_id', $merchant)->update(['user_id' => $other->id]);
    }

    public function test_token_owned_jobs_keep_the_original_scope_and_recheck_revocation(): void
    {
        $user = $this->user();
        $merchant = $this->organization($user);
        $plain = $this->token($user, $merchant, ['merchant.view']);
        $token = PersonalAccessToken::findToken($plain);
        $allowed = new UseMerchantContext($merchant, $user->id, 'merchant.view', $token->id);
        $allowed->handle(new \stdClass, fn () => $this->assertSame(['merchant.view'], app(MerchantContext::class)->permissions()));
        try {
            (new UseMerchantContext($merchant, $user->id, 'team.manage', $token->id))->handle(new \stdClass, fn () => $this->fail('Token scope expanded'));
            $this->fail('Missing scope was accepted');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $token->delete();
        try {
            $allowed->handle(new \stdClass, fn () => $this->fail('Revoked token job executed'));
            $this->fail('Revoked token was accepted');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertNull(app(MerchantContext::class)->id());
    }
}
