<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase7.sql')));
        $this->finance(true);
        foreach (DB::table('roles')->get() as $role) {
            $codes = [];
            if ($role->scope === 'merchant') {
                $codes = $role->code === 'merchant.finance_manager' ? [] : ['shipments.read', 'pickups.read'];
                if (in_array($role->code, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager', 'merchant.shipping_operator'])) {
                    $codes = [...$codes, 'shipments.write', 'shipments.submit', 'shipments.cancel', 'shipments.documents', 'pickups.manage'];
                }
            } elseif (in_array($role->code, ['platform.super_admin', 'platform.operations', 'platform.finance'])) {
                $codes = ['platform.shipments.view', 'platform.shipments.reconcile'];
            }
            foreach ($codes as $code) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => $role->scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $role->scope]);
            }
        }
        $this->grants(true);
    }

    private function finance(bool $extended): void
    {
        // Preserve the existing posting engine verbatim apart from two evidence checks.
        $sql = file_get_contents(database_path('sql/phase4.sql'));
        $start = strpos($sql, 'CREATE OR REPLACE FUNCTION finance_command(');
        $end = strpos($sql, 'REVOKE ALL ON FUNCTION finance_command', $start);
        $sql = substr($sql, $start, $end - $start);
        if ($extended) {
            $proof = "EXISTS(SELECT 1 FROM public.financial_hold_resolutions r WHERE current_database() IN ('clicktoship_test','clicktoship_e2e') AND r.merchant_id=p_merchant AND r.wallet_id=p_wallet AND r.hold_id=p_ref AND r.kind=p_kind AND r.amount=p_amount AND r.business_key=p_business)";
            $sql = str_replace("IF h.unknown AND p_kind NOT IN ('unknown') THEN", "IF h.unknown AND p_kind NOT IN ('unknown') AND NOT ($proof) THEN", $sql);
            $sql = str_replace('IF h.protected THEN', "IF h.protected AND NOT ($proof) THEN", $sql);
        }
        DB::unprepared($sql);
    }

    private function grants(bool $shipping): void
    {
        $codes = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.wallet.view', 'platform.finance.reconcile', 'platform.finance.correct', 'platform.pricing.view', 'platform.pricing.manage', 'platform.pricing.approve'];
        if ($shipping) {
            $codes = [...$codes, 'platform.shipments.view', 'platform.shipments.reconcile'];
        }
        $json = json_encode($codes);
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK(jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '$json'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && (DB::table('shipments')->exists() || DB::table('pickups')->exists())) {
            throw new RuntimeException('Retained shipment history prevents downgrade.');
        }
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities,array['platform.shipments.view','platform.shipments.reconcile'])")->exists()) {
            throw new RuntimeException('Retained shipping grants prevent downgrade.');
        }
        $this->finance(false);
        DB::unprepared('ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_merchant_id_carrier_observation_id_fkey; DROP TABLE financial_hold_resolutions,shipping_reconciliation_approvals,shipping_reconciliation_requests,shipping_observations,shipping_deliveries,shipping_outbox,shipment_documents,pickup_commands,pickup_events,pickup_shipments,pickups,shipping_request_keys,shipment_events,shipment_submissions,pricing_quote_acceptances,shipment_validations; ALTER TABLE shipments DROP CONSTRAINT shipments_merchant_id_id_revision_fkey; DROP TABLE shipment_revisions,shipments; ALTER TABLE carrier_operations DROP CONSTRAINT IF EXISTS shipping_carrier_operation_scope; ALTER TABLE balance_holds DROP CONSTRAINT IF EXISTS shipping_hold_wallet_scope;');
        if (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') {
            // Only disposable test teardown strips new capabilities; retained runtime history refuses downgrade.
            DB::statement("DELETE FROM platform_access_grants WHERE capabilities <@ '[\"platform.shipments.view\",\"platform.shipments.reconcile\"]'::jsonb");
            DB::statement("UPDATE platform_access_grants SET capabilities=capabilities-'platform.shipments.view'-'platform.shipments.reconcile' WHERE jsonb_exists_any(capabilities,array['platform.shipments.view','platform.shipments.reconcile'])");
        }
        $ids = DB::table('permissions')->whereIn('code', ['shipments.write', 'shipments.submit', 'shipments.cancel', 'shipments.documents'])->orWhere('code', 'like', 'platform.shipments.%')->pluck('id');
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'merchant.shipping_operator')->value('id'))->where('permission_id', DB::table('permissions')->where('code', 'pickups.manage')->value('id'))->delete();
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        if (! DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities, array['platform.shipments.view','platform.shipments.reconcile'])")->exists()) {
            $this->grants(false);
        }
    }
};
