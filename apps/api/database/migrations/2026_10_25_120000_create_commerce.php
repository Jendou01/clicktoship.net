<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase13.sql')));
        $codes = ['integrations.read', 'integrations.manage', 'integrations.sync', 'orders.read', 'orders.prepare', 'platform.metrics.commerce'];
        foreach ($codes as $code) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => str_starts_with($code, 'platform.') ? 'platform' : 'merchant']);
        }
        foreach (DB::table('roles')->get() as $role) {
            $allowed = match ($role->code) {
                'merchant.owner','merchant.admin' => array_slice($codes, 0, 5),'merchant.shipping_manager' => ['integrations.read', 'integrations.sync', 'orders.read', 'orders.prepare'],'merchant.shipping_operator' => ['orders.read', 'orders.prepare'],'merchant.viewer' => ['integrations.read', 'orders.read'],'platform.super_admin','platform.operations','platform.support' => ['platform.metrics.commerce'],default => []
            };
            foreach ($allowed as $code) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $role->scope]);
            }
        }
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('commerce_connections')->exists()) {
            throw new RuntimeException('Retained store/order history prevents downgrade.');
        }
        DB::unprepared('ALTER TABLE commerce_orders DROP CONSTRAINT commerce_current_snapshot; DROP TABLE commerce_receipts,commerce_events,commerce_commands,commerce_results,commerce_draft_links,commerce_pages,commerce_runs,commerce_snapshots,commerce_orders,commerce_authorizations,commerce_credentials,commerce_connections; DROP FUNCTION commerce_immutable(); DROP FUNCTION commerce_identity_guard(); DROP FUNCTION commerce_order_guard();');
        $ids = DB::table('permissions')->whereIn('code', ['integrations.read', 'integrations.manage', 'integrations.sync', 'orders.read', 'orders.prepare', 'platform.metrics.commerce'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
