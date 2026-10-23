<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

class ManagementConstraintsTest extends ManagementTestCase
{
    use RefreshDatabase;

    public function test_address_default_cross_merchant_relationship_rejected_by_postgres(): void
    {
        $a = $this->organization($this->user());
        $b = $this->organization($this->user());
        $id = (string) Str::uuid();
        DB::table('merchant_addresses')->insert(['id' => $id, 'merchant_id' => $a, 'payload' => Crypt::encryptString('{}'), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('merchant_settings')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $b, 'origin_address_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_address_cannot_be_reparented_even_direct_sql(): void
    {
        $a = $this->organization($this->user());
        $b = $this->organization($this->user());
        $id = (string) Str::uuid();
        DB::table('merchant_addresses')->insert(['id' => $id, 'merchant_id' => $a, 'payload' => Crypt::encryptString('{}'), 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('merchant_addresses')->where('id', $id)->update(['merchant_id' => $b]);
    }

    public function test_verification_snapshot_cannot_be_updated(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $id = (string) Str::uuid();
        DB::table('merchant_verification_submissions')->insert(['id' => $id, 'merchant_id' => $m, 'submitted_by' => $u->id, 'profile_version' => 1, 'snapshot' => Crypt::encryptString('{}'), 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('merchant_verification_submissions')->where('id', $id)->update(['snapshot' => 'altered']);
    }

    public function test_verification_decision_cannot_be_deleted(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $s = (string) Str::uuid();
        DB::table('merchant_verification_submissions')->insert(['id' => $s, 'merchant_id' => $m, 'submitted_by' => $u->id, 'profile_version' => 1, 'snapshot' => Crypt::encryptString('{}'), 'created_at' => now()]);
        DB::table('merchant_verification_decisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'submission_id' => $s, 'reviewed_by' => $u->id, 'outcome' => 'verified', 'reason' => Crypt::encryptString('test reason'), 'created_at' => now()]);
        $this->expectException(QueryException::class);
        DB::table('merchant_verification_decisions')->delete();
    }

    public function test_platform_role_scope_cannot_be_used_in_invitations(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->expectException(QueryException::class);
        DB::table('merchant_invitations')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'invited_by' => $u->id, 'role_id' => DB::table('roles')->where('code', 'platform.super_admin')->value('id'), 'role_scope' => 'platform', 'email' => 'encrypted', 'email_hash' => str_repeat('a', 64), 'token_hash' => str_repeat('b', 64), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_bootstrap_requires_two_confirmed_accounts_and_runs_only_once(): void
    {
        $a = $this->user();
        $b = $this->user();
        foreach ([$a, $b] as $u) {
            DB::table('user_mfa_credentials')->insert(['user_id' => $u->id, 'secret' => Crypt::encryptString(self::SECRET), 'confirmed_at' => now()]);
        }
        $this->artisan('platform:bootstrap', ['first' => $a->id, 'second' => $b->id])->assertSuccessful();
        $this->assertSame(2, DB::table('platform_role_assignments')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'platform.bootstrap')->count());
        $this->expectException(\RuntimeException::class);
        $this->artisan('platform:bootstrap', ['first' => $a->id, 'second' => $b->id]);
    }
}
