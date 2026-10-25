CREATE TABLE commerce_connections (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), provider text NOT NULL CHECK(provider='salla'), external_store_id text NOT NULL CHECK(length(external_store_id) BETWEEN 1 AND 128),
 state text NOT NULL CHECK(state IN ('authorized','reauthorization_required','disconnected')), provenance text NOT NULL CHECK(provenance IN ('verified','synthetic')),
 generation integer NOT NULL DEFAULT 1 CHECK(generation>0), version integer NOT NULL DEFAULT 1 CHECK(version>0),
 actor_id uuid NOT NULL REFERENCES users(id), auth_version integer NOT NULL, origin_session_id uuid, origin_token_id uuid,
 checkpoint text, last_synced_at timestamptz, error_code text, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(provider,external_store_id), UNIQUE(merchant_id,id), CHECK((origin_session_id IS NULL)<>(origin_token_id IS NULL))
);
CREATE INDEX commerce_connection_directory ON commerce_connections(merchant_id,created_at DESC,id);
CREATE TABLE commerce_credentials (
 merchant_id uuid NOT NULL, connection_id uuid PRIMARY KEY, ciphertext text, version integer NOT NULL DEFAULT 1 CHECK(version>0), expires_at timestamptz NOT NULL, scopes jsonb NOT NULL CHECK(jsonb_typeof(scopes)='array'), refresh_nonce uuid, lease_until timestamptz,
 FOREIGN KEY(merchant_id,connection_id) REFERENCES commerce_connections(merchant_id,id)
);
CREATE TABLE commerce_authorizations (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL, auth_version integer NOT NULL, state_hash char(64) UNIQUE NOT NULL, redirect_uri text NOT NULL, expires_at timestamptz NOT NULL, used_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(actor_id,session_id) REFERENCES auth_sessions(user_id,id)
);
CREATE TABLE commerce_orders (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, connection_id uuid NOT NULL, external_order_id text NOT NULL CHECK(length(external_order_id) BETWEEN 1 AND 128), reference_hash char(64) NOT NULL,
 source_created_at timestamptz NOT NULL, current_snapshot uuid, version integer NOT NULL DEFAULT 1 CHECK(version>0), state text NOT NULL CHECK(state IN ('ready','cancelled','fulfilled','unknown')), review_required boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(connection_id,external_order_id), UNIQUE(merchant_id,id), UNIQUE(merchant_id,connection_id,id), FOREIGN KEY(merchant_id,connection_id) REFERENCES commerce_connections(merchant_id,id)
);
CREATE INDEX commerce_order_directory ON commerce_orders(merchant_id,source_created_at DESC,id);
CREATE INDEX commerce_order_reference ON commerce_orders(merchant_id,reference_hash);
CREATE TABLE commerce_snapshots (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, order_id uuid NOT NULL, payload text NOT NULL, digest char(64) NOT NULL, source_updated_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(order_id,digest), UNIQUE(merchant_id,order_id,id), FOREIGN KEY(merchant_id,order_id) REFERENCES commerce_orders(merchant_id,id)
);
ALTER TABLE commerce_orders ADD CONSTRAINT commerce_current_snapshot FOREIGN KEY(merchant_id,id,current_snapshot) REFERENCES commerce_snapshots(merchant_id,order_id,id) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE commerce_runs (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, connection_id uuid NOT NULL, generation integer NOT NULL, kind text NOT NULL CHECK(kind IN ('sync','prepare')),
 state text NOT NULL DEFAULT 'queued' CHECK(state IN ('queued','running','waiting','limited','succeeded','failed','blocked')),
 actor_id uuid NOT NULL REFERENCES users(id), auth_version integer NOT NULL, origin_session_id uuid, origin_token_id uuid,
 payload text, cursor text, checkpoint text, position integer NOT NULL DEFAULT 0 CHECK(position>=0), pages integer NOT NULL DEFAULT 0 CHECK(pages>=0), attempt integer NOT NULL DEFAULT 0 CHECK(attempt>=0),
 lease_token uuid, lease_until timestamptz, next_at timestamptz NOT NULL DEFAULT now(), failure_code text, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(merchant_id,connection_id,id), FOREIGN KEY(merchant_id,connection_id) REFERENCES commerce_connections(merchant_id,id), CHECK((origin_session_id IS NULL)<>(origin_token_id IS NULL))
);
CREATE UNIQUE INDEX commerce_one_active_run ON commerce_runs(connection_id,kind) WHERE state IN ('queued','running','waiting','limited');
CREATE INDEX commerce_run_recovery ON commerce_runs(next_at) WHERE state IN ('queued','running','waiting');
CREATE TABLE commerce_pages (
 merchant_id uuid NOT NULL, run_id uuid NOT NULL, cursor_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(run_id,cursor_hash), FOREIGN KEY(merchant_id,run_id) REFERENCES commerce_runs(merchant_id,id)
);
CREATE TABLE commerce_draft_links (
 merchant_id uuid NOT NULL, order_id uuid PRIMARY KEY, snapshot_id uuid NOT NULL, shipment_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), review_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,shipment_id), FOREIGN KEY(merchant_id,order_id,snapshot_id) REFERENCES commerce_snapshots(merchant_id,order_id,id), FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
CREATE TABLE commerce_results (
 merchant_id uuid NOT NULL, run_id uuid NOT NULL, order_id uuid NOT NULL, state text NOT NULL CHECK(state IN ('created','existing','failed')), shipment_id uuid, error_code text, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(run_id,order_id), FOREIGN KEY(merchant_id,run_id) REFERENCES commerce_runs(merchant_id,id), FOREIGN KEY(merchant_id,order_id) REFERENCES commerce_orders(merchant_id,id), FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
CREATE TABLE commerce_commands (
 merchant_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), operation text NOT NULL, key_hash char(64) NOT NULL, digest char(64) NOT NULL, result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(merchant_id,actor_id,operation,key_hash)
);
CREATE TABLE commerce_events (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, connection_id uuid NOT NULL, kind text NOT NULL, actor_id uuid REFERENCES users(id), metadata jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,connection_id) REFERENCES commerce_connections(merchant_id,id)
);
CREATE INDEX commerce_event_directory ON commerce_events(merchant_id,connection_id,created_at DESC,id);
CREATE TABLE commerce_receipts (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, connection_id uuid NOT NULL, event_hash char(64) NOT NULL, payload_hash char(64) NOT NULL, kind text NOT NULL CHECK(kind IN ('sync_requested','uninstalled','quarantined')), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(connection_id,event_hash), FOREIGN KEY(merchant_id,connection_id) REFERENCES commerce_connections(merchant_id,id)
);
CREATE OR REPLACE FUNCTION commerce_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN RAISE EXCEPTION 'Commerce evidence is immutable'; END $$;
DO $$ DECLARE t text; BEGIN FOREACH t IN ARRAY ARRAY['commerce_snapshots','commerce_pages','commerce_draft_links','commerce_results','commerce_commands','commerce_events','commerce_receipts'] LOOP EXECUTE format('CREATE TRIGGER commerce_history BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION commerce_immutable()',t); END LOOP; END $$;
CREATE OR REPLACE FUNCTION commerce_identity_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN IF (NEW.id,NEW.merchant_id,NEW.provider,NEW.external_store_id) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.provider,OLD.external_store_id) THEN RAISE EXCEPTION 'Store ownership cannot be reassigned'; END IF; RETURN NEW; END $$;
CREATE TRIGGER commerce_owner_guard BEFORE UPDATE ON commerce_connections FOR EACH ROW EXECUTE FUNCTION commerce_identity_guard();

CREATE OR REPLACE FUNCTION commerce_order_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN IF (NEW.id,NEW.merchant_id,NEW.connection_id,NEW.external_order_id) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.connection_id,OLD.external_order_id) THEN RAISE EXCEPTION 'Order identity cannot be reassigned'; END IF; RETURN NEW; END $$;
CREATE TRIGGER commerce_order_owner_guard BEFORE UPDATE ON commerce_orders FOR EACH ROW EXECUTE FUNCTION commerce_order_guard();
