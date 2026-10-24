<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase10.sql')));
        $this->grants(true);
        foreach (DB::table('roles')->get() as $r) {
            $codes = [];
            if (in_array($r->code, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager', 'merchant.shipping_operator'])) {
                $codes = ['customs.read', 'customs.write', 'customs.documents'];
            }if (in_array($r->code, ['merchant.viewer', 'merchant.finance_manager'])) {
                $codes = ['customs.read'];
            }if (in_array($r->code, ['platform.super_admin', 'platform.operations'])) {
                $codes = ['platform.customs.view', 'platform.customs.review', 'platform.customs.rules.manage', 'platform.customs.rules.approve'];
            }if ($r->code === 'platform.support') {
                $codes = ['platform.customs.view'];
            }foreach ($codes as $code) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => $r->scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $r->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $r->scope]);
            }
        }
    }

    private function grants(bool $extra): void
    {
        $codes = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.wallet.view', 'platform.finance.reconcile', 'platform.finance.correct', 'platform.pricing.view', 'platform.pricing.manage', 'platform.pricing.approve', 'platform.shipments.view', 'platform.shipments.reconcile', 'platform.tracking.view', 'platform.tracking.review'];
        if ($extra) {
            $codes = [...$codes, 'platform.customs.view', 'platform.customs.review'];
        }$json = json_encode($codes);
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK(jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '$json'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }

    public function down(): void
    {
        $test = app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test';
        if (! $test && (DB::table('customs_declarations')->exists() || DB::table('customs_country_rules')->exists() || DB::table('customs_profiles')->exists() || DB::table('customs_commands')->exists())) {
            throw new RuntimeException('Retained customs history prevents downgrade.');
        }
        $grants = DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities,array['platform.customs.view','platform.customs.review'])")->exists();
        if ($grants && ! $test) {
            throw new RuntimeException('Retained customs grants prevent downgrade.');
        }
        DB::unprepared('DROP TABLE customs_document_revocations,customs_document_results,customs_document_work,customs_documents,customs_document_counters,customs_events,customs_commands,customs_reviews,customs_readiness,customs_rule_reviews; ALTER TABLE customs_country_rules DROP CONSTRAINT customs_country_rules_country_active_version_fkey; DROP TABLE customs_rule_versions,customs_country_rules,customs_profiles,customs_items; ALTER TABLE customs_declarations DROP CONSTRAINT customs_declarations_merchant_id_id_version_shipment_id_fkey; DROP TABLE customs_versions,customs_declarations; DROP FUNCTION customs_items_guard(); DROP FUNCTION customs_rule_review_guard(); DROP FUNCTION customs_version_guard(); DROP FUNCTION customs_work_guard();');
        if ($grants) {
            DB::statement("DELETE FROM platform_access_grants WHERE capabilities <@ '[\"platform.customs.view\",\"platform.customs.review\"]'::jsonb");
            DB::statement("UPDATE platform_access_grants SET capabilities=capabilities-'platform.customs.view'-'platform.customs.review' WHERE jsonb_exists_any(capabilities,array['platform.customs.view','platform.customs.review'])");
        }$this->grants(false);
        $ids = DB::table('permissions')->where('code', 'like', 'customs.%')->orWhere('code', 'like', 'platform.customs.%')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
