<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION forbid_membership_reparenting() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.id <> OLD.id OR NEW.merchant_id <> OLD.merchant_id OR NEW.user_id <> OLD.user_id THEN
        RAISE EXCEPTION 'Membership identity is immutable' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER immutable_membership_identity BEFORE UPDATE ON merchant_users
FOR EACH ROW EXECUTE FUNCTION forbid_membership_reparenting();
CREATE TRIGGER immutable_audit_truncate BEFORE TRUNCATE ON audit_logs
FOR EACH STATEMENT EXECUTE FUNCTION forbid_audit_mutation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS immutable_audit_truncate ON audit_logs; DROP TRIGGER IF EXISTS immutable_membership_identity ON merchant_users; DROP FUNCTION IF EXISTS forbid_membership_reparenting();');
    }
};
