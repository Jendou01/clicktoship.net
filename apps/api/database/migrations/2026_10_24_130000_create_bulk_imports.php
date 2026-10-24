<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/phase8.sql')));
        foreach (DB::table('roles')->where('scope', 'merchant')->get() as $role) {
            $codes = [];
            if (in_array($role->code, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager', 'merchant.shipping_operator'])) {
                $codes = ['bulk.read', 'bulk.upload', 'bulk.map', 'bulk.price', 'bulk.prepare', 'bulk.cancel', 'bulk.export'];
            } elseif ($role->code === 'merchant.viewer') {
                $codes = ['bulk.read'];
            }
            foreach ($codes as $code) {
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => 'merchant']);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => 'merchant']);
            }
        }
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('bulk_batches')->exists()) {
            throw new RuntimeException('Retained import history prevents downgrade.');
        }
        DB::unprepared('DROP TABLE bulk_drafts,bulk_approvals,bulk_prices,bulk_decisions,bulk_validations,bulk_mappings,bulk_events,bulk_commands,bulk_work,bulk_files,bulk_rows,bulk_batches; DROP FUNCTION bulk_identity_guard();');
        $ids = DB::table('permissions')->where('code', 'like', 'bulk.%')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
