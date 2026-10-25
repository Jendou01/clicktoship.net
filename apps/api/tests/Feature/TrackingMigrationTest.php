<?php

namespace Tests\Feature;

use App\Modules\Tracking\Application\TrackingPollScheduler;
use App\Support\SchemaIntegrity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TrackingTestCase;

final class TrackingMigrationTest extends TrackingTestCase
{
    use DatabaseMigrations;

    public function test_additive_rollback_reapply_preserves_shipping_wallet_and_roles(): void
    {
        $f = $this->setupShipment(false);
        $role = DB::table('roles')->where('code', 'merchant.owner')->value('id');
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 14 identity before older rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive OAuth cancellation guard before prior rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Commerce before exercising prior rollback targets.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 12 overview before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 11 indexes before prior-phase rollback.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true])); // Remove additive Phase 10 before the original rollback target.
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $this->assertFalse(Schema::hasTable('tracking_work'));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($role, DB::table('roles')->where('code', 'merchant.owner')->value('id'));
        $this->assertDatabaseHas('shipments', ['id' => $f['s']['id']]);
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_drift_detection_and_runtime_history_refuses_downgrade(): void
    {
        $f = $this->trackingFixture();
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        DB::statement('ALTER TABLE tracking_bindings DISABLE TRIGGER tracking_binding_guard');
        $this->assertContains('tracking_bindings.tracking_binding_guard', app(SchemaIntegrity::class)->failures());
        DB::statement('ALTER TABLE tracking_bindings ENABLE TRIGGER tracking_binding_guard');
        $migration = require database_path('migrations/2026_10_24_150000_create_tracking.php');
        $env = app()->environment();
        app()->instance('env', 'production');
        try {
            $migration->down();
            $this->fail('History downgrade allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        } finally {
            app()->instance('env', $env);
        }
    }

    public function test_immutable_event_history_and_composite_tenant_binding(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $w = $this->inbound($ep, $this->envelope($f))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        foreach (['carrier_tracking_events', 'carrier_webhook_deliveries', 'tracking_event_details', 'tracking_bindings', 'tracking_receipts'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('Immutable history deleted');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $m = $this->organization($this->user());
        $this->expectException(QueryException::class);
        DB::table('tracking_work')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'binding_id' => $f['binding']->id, 'account_id' => $f['binding']->account_id, 'kind' => 'scheduled', 'business_key' => str_repeat('0', 64), 'digest' => str_repeat('0', 64), 'state' => 'pending']);
    }

    public function test_projection_tampering_cannot_replace_evidence_status(): void
    {
        $f = $this->trackingFixture();
        $ep = $this->endpoint($f);
        $w = $this->inbound($ep, $this->envelope($f))->assertStatus(202)->json('data.receipt');
        app(TrackingPollScheduler::class)->tick($f['m'], $w);
        $this->expectException(QueryException::class);
        DB::table('carrier_tracking_projections')->update(['status' => 'delivered']);
    }
}
