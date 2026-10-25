<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function grants(): array
    {
        $all = ['overview', 'metrics.merchants', 'metrics.users', 'metrics.shipping', 'metrics.bulk', 'metrics.tracking', 'metrics.carriers', 'metrics.pricing', 'metrics.finance', 'metrics.customs', 'health.view', 'settings.view', 'alerts.read', 'alerts.acknowledge'];

        return ['platform.super_admin' => $all, 'platform.operations' => array_values(array_diff($all, ['metrics.users', 'metrics.finance', 'settings.view'])), 'platform.finance' => ['overview', 'metrics.merchants', 'metrics.finance', 'alerts.read', 'alerts.acknowledge'], 'platform.support' => ['overview', 'metrics.merchants', 'metrics.shipping', 'metrics.bulk', 'metrics.tracking', 'alerts.read', 'alerts.acknowledge']];
    }

    public function up(): void
    {
        DB::unprepared("CREATE TABLE platform_alert_receipts (actor_id uuid NOT NULL REFERENCES users(id), alert_key char(64) NOT NULL CHECK(alert_key ~ '^[a-f0-9]{64}$'), session_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(actor_id,alert_key), FOREIGN KEY(actor_id,session_id) REFERENCES auth_sessions(user_id,id));
        CREATE OR REPLACE FUNCTION platform_receipt_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS \$\$ BEGIN RAISE EXCEPTION 'Platform alert receipts are immutable'; END \$\$;
        CREATE TRIGGER platform_receipt_history BEFORE UPDATE OR DELETE OR TRUNCATE ON platform_alert_receipts FOR EACH STATEMENT EXECUTE FUNCTION platform_receipt_immutable();");
        foreach ($this->grants() as $role => $codes) {
            $id = DB::table('roles')->where('code', $role)->value('id');
            foreach ($codes as $code) {
                $name = 'platform.'.$code;
                DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $name, 'scope' => 'platform']);
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $id, 'permission_id' => DB::table('permissions')->where('code', $name)->value('id'), 'scope' => 'platform']);
            }
        }
    }

    public function down(): void
    {
        if (! (app()->environment('testing') && DB::connection()->getDatabaseName() === 'clicktoship_test') && DB::table('platform_alert_receipts')->exists()) {
            throw new RuntimeException('Retained platform alert history prevents downgrade.');
        }
        DB::statement('DROP TABLE platform_alert_receipts');
        DB::statement('DROP FUNCTION platform_receipt_immutable()');
        $codes = array_map(fn ($c) => 'platform.'.$c, $this->grants()['platform.super_admin']);
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
