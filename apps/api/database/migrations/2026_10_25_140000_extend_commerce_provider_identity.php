<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE commerce_connections DROP CONSTRAINT commerce_connections_provider_check;
ALTER TABLE commerce_connections ADD CONSTRAINT commerce_connections_provider_check CHECK(provider IN ('salla','shopyzee'));
ALTER TABLE commerce_connections ADD COLUMN external_platform_id text NOT NULL DEFAULT 'salla' CHECK(external_platform_id ~ '^[A-Za-z0-9_-]{1,128}$');
ALTER TABLE commerce_connections ADD COLUMN display_domain text CHECK(display_domain IS NULL OR (length(display_domain) <= 253 AND display_domain ~ '^[a-z0-9]+([.-][a-z0-9]+)*$'));
ALTER TABLE commerce_connections ADD CONSTRAINT commerce_platform_namespace CHECK(provider <> 'salla' OR external_platform_id = 'salla');
ALTER TABLE commerce_connections DROP CONSTRAINT commerce_connections_provider_external_store_id_key;
ALTER TABLE commerce_connections ADD CONSTRAINT commerce_source_identity UNIQUE(provider,external_platform_id,external_store_id);
ALTER TABLE commerce_authorizations ADD COLUMN provider text NOT NULL DEFAULT 'salla' CHECK(provider IN ('salla','shopyzee'));
CREATE INDEX commerce_authorization_provider ON commerce_authorizations(merchant_id,provider,expires_at) WHERE cancelled_at IS NULL;
CREATE OR REPLACE FUNCTION commerce_identity_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN IF (NEW.id,NEW.merchant_id,NEW.provider,NEW.external_platform_id,NEW.external_store_id) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.provider,OLD.external_platform_id,OLD.external_store_id) THEN RAISE EXCEPTION 'Store ownership cannot be reassigned'; END IF; RETURN NEW; END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::table('commerce_connections')->where('provider', 'shopyzee')->orWhereNotNull('display_domain')->exists() || DB::table('commerce_authorizations')->where('provider', 'shopyzee')->exists()) {
            throw new RuntimeException('Retained provider evidence prevents downgrade.');
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION commerce_identity_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN IF (NEW.id,NEW.merchant_id,NEW.provider,NEW.external_store_id) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.provider,OLD.external_store_id) THEN RAISE EXCEPTION 'Store ownership cannot be reassigned'; END IF; RETURN NEW; END $$;
DROP INDEX commerce_authorization_provider;
ALTER TABLE commerce_authorizations DROP COLUMN provider;
ALTER TABLE commerce_connections DROP CONSTRAINT commerce_source_identity;
ALTER TABLE commerce_connections DROP CONSTRAINT commerce_platform_namespace;
ALTER TABLE commerce_connections DROP COLUMN display_domain, DROP COLUMN external_platform_id;
ALTER TABLE commerce_connections DROP CONSTRAINT commerce_connections_provider_check;
ALTER TABLE commerce_connections ADD CONSTRAINT commerce_connections_provider_check CHECK(provider='salla');
ALTER TABLE commerce_connections ADD CONSTRAINT commerce_connections_provider_external_store_id_key UNIQUE(provider,external_store_id);
SQL);
    }
};
