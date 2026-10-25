<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ManagementTestCase;

class Phase3MigrationTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_phase3_rollback_reapply_preserves_phase2_users_memberships_tokens_and_audits(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $token = $this->token($u, $m);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 15 export ownership first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 14 identity before older rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive OAuth cancellation guard before prior rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Commerce before exercising prior rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 12 overview before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 11 indexes before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 10 before the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 9 before the existing preservation assertions.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 8 before exercising the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Additive Phase 7 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Additive Phase 6 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true])); // Additive Phase 5 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true])); // Additive Phase 4 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true]));
        $this->assertFalse(Schema::hasTable('merchant_profiles'));
        $this->assertFalse(Schema::hasColumn('merchants', 'verification_status'));
        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertDatabaseHas('merchant_users', ['merchant_id' => $m, 'user_id' => $u->id]);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('platform_access_grants'));
        $this->assertDatabaseHas('merchants', ['id' => $m, 'status' => 'active', 'verification_status' => 'unverified']);
        $this->bearer($token, 'GET', '/api/v1/merchants/current')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['merchant_id' => $m, 'action' => 'merchant.created']);
    }
}
