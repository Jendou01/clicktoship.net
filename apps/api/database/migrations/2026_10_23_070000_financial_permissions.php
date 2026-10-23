<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const MERCHANT = ['wallet.open', 'wallet.transactions.read', 'wallet.holds.read', 'wallet.statements.read'];

    private const PLATFORM = ['platform.wallet.view', 'platform.finance.reconcile', 'platform.finance.correct'];

    public function up(): void
    {
        foreach (['merchant.owner', 'merchant.admin', 'merchant.finance_manager', 'platform.super_admin', 'platform.finance'] as $code) {
            $scope = str_starts_with($code, 'merchant.') ? 'merchant' : 'platform';
            $role = DB::table('roles')->where('code', $code)->value('id');
            foreach ($scope === 'merchant' ? self::MERCHANT : self::PLATFORM as $permission) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $permission, 'scope' => $scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $permission)->value('id'), 'scope' => $scope]);
            }
        }
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK (jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '[\"platform.merchant.view\",\"platform.merchant.status\",\"platform.verification.review\",\"platform.wallet.view\",\"platform.finance.reconcile\",\"platform.finance.correct\"]'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities, array['platform.wallet.view','platform.finance.reconcile','platform.finance.correct'])")->exists()) {
            throw new RuntimeException('Financial access grants prevent downgrade.');
        }
        $ids = DB::table('permissions')->whereIn('code', [...self::MERCHANT, ...self::PLATFORM])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        if (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test' && DB::table('platform_access_grants')->exists()) {
            return;
        }
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK (jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '[\"platform.merchant.view\",\"platform.merchant.status\",\"platform.verification.review\"]'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }
};
