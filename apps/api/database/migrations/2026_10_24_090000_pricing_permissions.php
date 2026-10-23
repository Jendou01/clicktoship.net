<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $permissions = $role->scope === 'merchant' ? ['rates.read', 'rates.quote'] : (in_array($role->code, ['platform.super_admin', 'platform.finance'], true) ? ['platform.pricing.view', 'platform.pricing.manage', 'platform.pricing.approve'] : []);
            foreach ($permissions as $code) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => $role->scope]);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $role->scope]);
            }
        }
        $this->constraint(true);
    }

    private function constraint(bool $pricing): void
    {
        $codes = ['platform.merchant.view', 'platform.merchant.status', 'platform.verification.review', 'platform.wallet.view', 'platform.finance.reconcile', 'platform.finance.correct'];
        if ($pricing) {
            $codes = [...$codes, 'platform.pricing.view', 'platform.pricing.manage', 'platform.pricing.approve'];
        }
        $json = json_encode($codes);
        DB::statement('ALTER TABLE platform_access_grants DROP CONSTRAINT grant_capability_check');
        DB::statement("ALTER TABLE platform_access_grants ADD CONSTRAINT grant_capability_check CHECK(jsonb_typeof(capabilities)='array' AND jsonb_array_length(capabilities) BETWEEN 1 AND 3 AND capabilities <@ '$json'::jsonb AND version>0 AND expires_at>created_at AND expires_at<=created_at+interval '15 minutes')");
    }

    public function down(): void
    {
        $grants = DB::table('platform_access_grants')->whereRaw("jsonb_exists_any(capabilities, array['platform.pricing.view','platform.pricing.manage','platform.pricing.approve'])")->exists();
        if ($grants && ! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test')) {
            throw new RuntimeException('Pricing grants prevent downgrade');
        }
        $ids = DB::table('permissions')->where('code', 'like', 'platform.pricing.%')->orWhereIn('code', ['rates.read', 'rates.quote'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        if (! $grants) {
            $this->constraint(false);
        }
    }
};
