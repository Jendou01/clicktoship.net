<?php

namespace Tests\Feature;

use App\Support\SchemaIntegrity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SchemaIntegrityTest extends TestCase
{
    use DatabaseMigrations;

    public function test_current_migrations_provide_critical_schema(): void
    {
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
        $this->artisan('schema:verify')->assertExitCode(0);
    }

    public function test_recorded_migration_cannot_hide_missing_column_and_trigger(): void
    {
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE shipments RENAME COLUMN carrier_observation_id TO drifted_column');
            DB::statement('DROP TRIGGER submission_binding ON shipment_submissions');
            $this->assertTrue(DB::table('migrations')->where('migration', '2026_10_24_110000_create_shipment_management')->exists());
            $failures = app(SchemaIntegrity::class)->failures();
            $this->assertContains('shipments.carrier_observation_id', $failures);
            $this->assertContains('shipment_submissions.submission_binding', $failures);
            $this->artisan('schema:verify')->assertExitCode(1);
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }

    public function test_disabled_security_trigger_is_detected(): void
    {
        DB::beginTransaction();
        try {
            DB::statement('ALTER TABLE shipment_submissions DISABLE TRIGGER submission_binding');
            $this->assertContains('shipment_submissions.submission_binding', app(SchemaIntegrity::class)->failures());
        } finally {
            DB::rollBack();
        }
    }

    public function test_e2e_reset_refuses_the_regression_database(): void
    {
        $this->artisan('e2e:rebuild')->assertExitCode(1);
        $this->assertSame([], app(SchemaIntegrity::class)->failures());
    }
}
