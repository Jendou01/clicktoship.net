<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase9.sql')));
        $this->grants(true);
        foreach (DB::table('roles')->get() as $role) {
            $codes = [];
            if (in_array($role->code, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager', 'merchant.shipping_operator'])) {
                $codes = ['tracking.read', 'tracking.refresh'];
            }
            if ($role->code === 'merchant.viewer') {
                $codes = ['tracking.read'];
            }
            if (in_array($role->code, ['platform.super_admin', 'platform.operations'])) {
                $codes = ['platform.tracking.health', 'platform.tracking.view', 'platform.tracking.review'];
            }
            if ($role->code === 'platform.support') {
                $codes = ['platform.tracking.health', 'platform.tracking.view'];
            }
            foreach ($codes as $code) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => $role->scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $role->scope]);
            }
        }
    }

    private function grants(bool $tracking): void
    {
        $codes = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.wallet.view', 'platform.finance.reconcile', 'platform.finance.correct', 'platform.pricing.view', 'platform.pricing.manage', 'platform.pricing.approve', 'platform.shipments.view', 'platform.shipments.reconcile'];
        if ($tracking) {
            $codes = [...$codes, 'platform.tracking.view', 'platform.tracking.review'];
        }$json = json_encode($codes);
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK(jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '$json'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }

    public function down(): void
    {
        $disposable = app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test';
        if (! $disposable && (DB::table('tracking_bindings')->exists() || DB::table('tracking_work')->exists() || DB::table('tracking_endpoints')->exists())) {
            throw new RuntimeException('Retained tracking history prevents downgrade.');
        }
        $hasGrants = DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities,array['platform.tracking.view','platform.tracking.review'])")->exists();
        if ($hasGrants && ! $disposable) {
            throw new RuntimeException('Retained tracking grants prevent downgrade.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS tracking_projection_guard ON carrier_tracking_projections; DROP TRIGGER IF EXISTS tracking_carrier_history_truncate ON carrier_tracking_events; DROP TRIGGER IF EXISTS tracking_delivery_history_truncate ON carrier_webhook_deliveries; DROP TABLE IF EXISTS tracking_receipts,tracking_scan_cursors,tracking_notifications,tracking_preferences,tracking_reviews,tracking_cases,tracking_attempts,tracking_event_details,tracking_commands,tracking_work,tracking_endpoints,tracking_account_health,tracking_bindings; DROP FUNCTION IF EXISTS tracking_projection_guard(); DROP FUNCTION tracking_work_guard(); DROP FUNCTION tracking_binding_guard(); ALTER TABLE shipment_submissions DROP CONSTRAINT tracking_submission_identity;');
        if ($hasGrants) {
            // Only disposable teardown strips new capabilities after dropping their referencing test history.
            DB::statement("DELETE FROM platform_access_grants WHERE capabilities <@ '[\"platform.tracking.view\",\"platform.tracking.review\"]'::jsonb");
            DB::statement("UPDATE platform_access_grants SET capabilities=capabilities-'platform.tracking.view'-'platform.tracking.review' WHERE jsonb_exists_any(capabilities,array['platform.tracking.view','platform.tracking.review'])");
        }
        $this->grants(false);
        // Keep additive carrier/status catalog widening compatible with retained Phase 6 facts.
        $ids = DB::table('permissions')->where('code', 'like', 'tracking.%')->orWhere('code', 'like', 'platform.tracking.%')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
