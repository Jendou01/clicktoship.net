<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_tenant_reparenting() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.merchant_id <> OLD.merchant_id THEN
  RAISE EXCEPTION 'Tenant identity is immutable' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END;
$$;
SQL);
        foreach (['merchant_profiles', 'merchant_addresses', 'merchant_settings', 'merchant_invitations'] as $t) {
            DB::unprepared("CREATE TRIGGER immutable_{$t}_identity BEFORE UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION forbid_tenant_reparenting();");
        }
    }

    public function down(): void
    {
        foreach (['merchant_profiles', 'merchant_addresses', 'merchant_settings', 'merchant_invitations'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS immutable_{$t}_identity ON {$t}");
        }
        DB::statement('DROP FUNCTION IF EXISTS forbid_tenant_reparenting()');
    }
};
