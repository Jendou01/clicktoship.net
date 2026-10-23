<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase6.sql')));
        foreach (['platform.super_admin', 'platform.operations'] as $code) {
            $role = DB::table('roles')->where('code', $code)->value('id');
            foreach (['platform.carriers.view', 'platform.carriers.manage', 'platform.carriers.approve'] as $permission) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $permission, 'scope' => 'platform']);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $permission)->value('id'), 'scope' => 'platform']);
            }
        }
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && (DB::table('carrier_account_versions')->exists() || DB::table('carrier_operations')->exists())) {
            throw new RuntimeException('Retained carrier history prevents downgrade.');
        }
        DB::unprepared('DROP TABLE carrier_tracking_projections,carrier_tracking_events,carrier_webhook_deliveries,carrier_health_records,carrier_attempts,carrier_operations,carrier_config_events; ALTER TABLE carrier_accounts DROP CONSTRAINT carrier_accounts_id_active_version_fkey; DROP TABLE carrier_account_versions,carrier_accounts;');
        $ids = DB::table('permissions')->where('code', 'like', 'platform.carriers.%')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
