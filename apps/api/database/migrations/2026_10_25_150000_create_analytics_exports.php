<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE report_exports (
 id uuid PRIMARY KEY, scope text NOT NULL CHECK(scope IN ('merchant','platform')), merchant_id uuid REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id), auth_version integer NOT NULL,
 origin_session_id uuid, origin_token_id uuid, report text NOT NULL, filters jsonb NOT NULL, format text NOT NULL CHECK(format IN ('csv','xlsx')), locale text NOT NULL CHECK(locale IN ('en','ar')),
 key_hash char(64) NOT NULL, digest char(64) NOT NULL, state text NOT NULL CHECK(state IN ('requested','authorized','queued','processing','ready','expired','failed','revoked')),
 attempt integer NOT NULL DEFAULT 0 CHECK(attempt BETWEEN 0 AND 3), lease uuid, lease_until timestamptz, next_at timestamptz NOT NULL DEFAULT now(), artifact text, artifact_hash char(64), rows integer NOT NULL DEFAULT 0 CHECK(rows BETWEEN 0 AND 1000), bytes integer NOT NULL DEFAULT 0 CHECK(bytes BETWEEN 0 AND 2097152), error_code text,
 expires_at timestamptz NOT NULL DEFAULT now()+interval '15 minutes', created_at timestamptz NOT NULL DEFAULT now(), generated_at timestamptz, last_cleanup_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(actor_id,key_hash), UNIQUE(merchant_id,id), CHECK((scope='merchant')=(merchant_id IS NOT NULL)), CHECK((origin_session_id IS NULL)<>(origin_token_id IS NULL)), CHECK(scope<>'platform' OR origin_token_id IS NULL)
);
CREATE INDEX report_export_history ON report_exports(actor_id,merchant_id,id DESC);
CREATE UNIQUE INDEX report_export_active_digest ON report_exports(actor_id,digest) WHERE state IN ('queued','processing');
CREATE INDEX report_export_recovery ON report_exports(next_at) WHERE state IN ('queued','processing');
CREATE INDEX report_export_expiry ON report_exports(expires_at) WHERE state IN ('queued','processing','ready');
CREATE TABLE report_export_events(id uuid PRIMARY KEY,export_id uuid NOT NULL REFERENCES report_exports(id),kind text NOT NULL CHECK(kind IN ('requested','authorized','queued','processing','ready','expired','failed','revoked','downloaded','recovered')),artifact text,created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX report_export_event_history ON report_export_events(export_id,created_at);
CREATE OR REPLACE FUNCTION report_export_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN RAISE EXCEPTION 'Report audit is immutable'; END $$;
CREATE TRIGGER report_export_history BEFORE UPDATE OR DELETE OR TRUNCATE ON report_export_events FOR EACH STATEMENT EXECUTE FUNCTION report_export_immutable();
CREATE OR REPLACE FUNCTION report_export_identity() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN IF (NEW.id,NEW.scope,NEW.merchant_id,NEW.actor_id,NEW.auth_version,NEW.origin_session_id,NEW.origin_token_id,NEW.report,NEW.filters,NEW.format,NEW.locale,NEW.key_hash,NEW.digest,NEW.created_at,NEW.expires_at) IS DISTINCT FROM (OLD.id,OLD.scope,OLD.merchant_id,OLD.actor_id,OLD.auth_version,OLD.origin_session_id,OLD.origin_token_id,OLD.report,OLD.filters,OLD.format,OLD.locale,OLD.key_hash,OLD.digest,OLD.created_at,OLD.expires_at) THEN RAISE EXCEPTION 'Export authority and request identity are immutable'; END IF; RETURN NEW; END $$;
CREATE TRIGGER report_export_identity_guard BEFORE UPDATE ON report_exports FOR EACH ROW EXECUTE FUNCTION report_export_identity();
CREATE INDEX analytics_hold_events ON balance_hold_events(merchant_id,created_at,id);
CREATE INDEX analytics_wallet_movements ON wallet_transactions(merchant_id,created_at,id);
CREATE INDEX analytics_commerce_orders ON commerce_orders(merchant_id,created_at,id);
SQL);
        foreach (['analytics.read', 'analytics.export', 'platform.analytics.read', 'platform.analytics.export'] as $code) {
            DB::table('permissions')->insertOrIgnore(['id' => (string) Str::uuid(), 'code' => $code, 'scope' => str_starts_with($code, 'platform.') ? 'platform' : 'merchant']);
            foreach (DB::table('roles')->get() as $role) {
                $allowed = str_starts_with($code, 'platform.') ? in_array($role->code, ['platform.super_admin', 'platform.operations', 'platform.finance', 'platform.support'], true) : in_array($role->code, ['merchant.owner', 'merchant.admin', 'merchant.shipping_manager', 'merchant.shipping_operator', 'merchant.finance_manager', 'merchant.viewer'], true);
                if (str_ends_with($code, '.export')) {
                    $allowed = $allowed && ! in_array($role->code, ['merchant.viewer', 'merchant.shipping_operator', 'platform.support'], true);
                }
                if ($allowed) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id'), 'scope' => $role->scope]);
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::table('report_exports')->exists()) {
            throw new RuntimeException('Retained reporting audit prevents downgrade.');
        }
        DB::unprepared('DROP INDEX analytics_hold_events,analytics_wallet_movements,analytics_commerce_orders; DROP TABLE report_export_events,report_exports; DROP FUNCTION report_export_identity(); DROP FUNCTION report_export_immutable();');
        $ids = DB::table('permissions')->whereIn('code', ['analytics.read', 'analytics.export', 'platform.analytics.read', 'platform.analytics.export'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
