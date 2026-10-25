<?php

namespace Tests\Feature;

use App\Modules\Bulk\Domain\Columns;
use App\Modules\WalletBilling\Application\Ledger;
use App\Support\SchemaIntegrity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BulkTestCase;

final class BulkMigrationTest extends BulkTestCase
{
    use DatabaseMigrations;

    public function test_bulk_rollback_reapply_preserves_existing_shipments_wallets_and_role_ids(): void
    {
        $f = $this->bulkFixture();
        $draft = Columns::draft(array_values($this->csvRow($f['f']['service'])), array_keys($this->csvRow($f['f']['service'])));
        $s = $this->bearer($f['token'], 'POST', '/api/v1/shipments', ['draft' => $draft, 'idempotency_key' => 'prior-shipment'])->assertCreated()->json('data.shipment.id');
        $wallet = app(Ledger::class)->provision($f['m']);
        $role = DB::table('roles')->where('code', 'merchant.owner')->value('id');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 11 indexes before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 10 before the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 9 before the existing preservation assertions.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('bulk_batches'));
        $this->assertDatabaseHas('shipments', ['id' => $s]);
        $this->assertDatabaseHas('wallets', ['id' => $wallet]);
        $this->assertSame($role, DB::table('roles')->where('code', 'merchant.owner')->value('id'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('bulk_rows'));
        $this->assertDatabaseHas('permissions', ['code' => 'bulk.prepare']);
    }

    public function test_repeated_fresh_install_replaces_leftover_postgresql_functions(): void
    {
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertTrue(Schema::hasTable('bulk_batches'));
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_runtime_downgrade_refuses_retained_import_history(): void
    {
        $f = $this->bulkFixture();
        $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $migration = require database_path('migrations/2026_10_24_130000_create_bulk_imports.php');
        $env = app()->environment();
        app()->instance('env', 'production');
        try {
            $migration->down();
            $this->fail('Retained import history was downgraded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        } finally {
            app()->instance('env', $env);
        }
        $this->assertDatabaseCount('bulk_batches', 1);
    }
}
