<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ManagementTestCase;

class FinancialMigrationTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_upgrade_rollback_reapply_preserves_phase3_identities_profiles_and_role_ids(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $role = DB::table('roles')->where('code', 'merchant.owner')->value('id');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Additive Phase 6 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true])); // Additive Phase 5 first.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]));
        $this->assertFalse(Schema::hasTable('wallets'));
        $this->assertDatabaseHas('merchant_users', ['merchant_id' => $m, 'user_id' => $u->id]);
        $this->assertTrue(Schema::hasTable('merchant_profiles'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($role, DB::table('roles')->where('code', 'merchant.owner')->value('id'));
        $this->assertSame(0, DB::table('ledger_transactions')->count());
    }

    public function test_normal_runtime_downgrade_refuses_posted_history(): void
    {
        $m = $this->organization($this->user());
        $l = app(Ledger::class);
        $w = $l->provision($m);
        $l->command($m, $w, 'synthetic', '100', 'seed', 'seed', 'test');
        $migration = require database_path('migrations/2026_10_23_060000_create_wallet_ledger.php');
        app()->detectEnvironment(fn () => 'local');
        try {
            try {
                $migration->down();
                $this->fail('Destructive downgrade accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('history prevents downgrade', $e->getMessage());
            }
            $this->assertSame(1, DB::table('ledger_transactions')->count());
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }
}
