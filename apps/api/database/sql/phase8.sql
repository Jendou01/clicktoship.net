CREATE TABLE bulk_batches (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id),
 auth_version integer NOT NULL, origin_token_id uuid, origin_session_id uuid,
 state text NOT NULL DEFAULT 'initialized' CHECK(state IN ('initialized','parsing','mapping','validating','review','pricing','preparing','completed','partial','cancelled','blocked','failed')),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), mapping_version integer NOT NULL DEFAULT 0 CHECK(mapping_version>=0),
 format text NOT NULL CHECK(format IN ('csv','xlsx')), delimiter text NOT NULL CHECK(delimiter IN (',',';',E'\t')),
 headers text, row_count integer NOT NULL DEFAULT 0 CHECK(row_count>=0), failure_code text,
 repeat_of uuid, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), FOREIGN KEY(merchant_id,repeat_of) REFERENCES bulk_batches(merchant_id,id)
);
CREATE INDEX bulk_batches_directory ON bulk_batches(merchant_id,created_at DESC,id);
CREATE TABLE bulk_files (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, storage_key text NOT NULL UNIQUE, filename text NOT NULL,
 sha256 char(64) NOT NULL, size_bytes bigint NOT NULL CHECK(size_bytes>0), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,batch_id), FOREIGN KEY(merchant_id,batch_id) REFERENCES bulk_batches(merchant_id,id)
);
CREATE INDEX bulk_file_duplicates ON bulk_files(merchant_id,sha256);
CREATE TABLE bulk_rows (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, source_row integer NOT NULL CHECK(source_row>=2), raw text NOT NULL, raw_hash char(64) NOT NULL,
 UNIQUE(merchant_id,id), UNIQUE(merchant_id,batch_id,source_row), UNIQUE(merchant_id,batch_id,id), FOREIGN KEY(merchant_id,batch_id) REFERENCES bulk_batches(merchant_id,id)
);
CREATE TABLE bulk_mappings (
 merchant_id uuid NOT NULL, batch_id uuid NOT NULL, version integer NOT NULL CHECK(version>0), mapping jsonb NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(merchant_id,batch_id,version), FOREIGN KEY(merchant_id,batch_id) REFERENCES bulk_batches(merchant_id,id)
);
CREATE TABLE bulk_work (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, phase text NOT NULL CHECK(phase IN ('parsing','validating','pricing','preparing')), mapping_version integer NOT NULL,
 cursor integer NOT NULL DEFAULT 0 CHECK(cursor>=0), lease_token uuid, lease_until timestamptz, completed_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(merchant_id,batch_id,id), FOREIGN KEY(merchant_id,batch_id) REFERENCES bulk_batches(merchant_id,id)
);
CREATE UNIQUE INDEX bulk_one_active_work ON bulk_work(batch_id) WHERE completed_at IS NULL;
CREATE INDEX bulk_work_recovery ON bulk_work(created_at) WHERE completed_at IS NULL;
CREATE TABLE bulk_validations (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, row_id uuid NOT NULL, mapping_version integer NOT NULL,
 draft text, errors jsonb NOT NULL, canonical_hash char(64), duplicate_of uuid, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(merchant_id,batch_id,row_id,mapping_version),
 FOREIGN KEY(merchant_id,batch_id,row_id) REFERENCES bulk_rows(merchant_id,batch_id,id),
 FOREIGN KEY(merchant_id,batch_id,mapping_version) REFERENCES bulk_mappings(merchant_id,batch_id,version), FOREIGN KEY(merchant_id,duplicate_of) REFERENCES bulk_rows(merchant_id,id)
);
CREATE INDEX bulk_canonical_duplicates ON bulk_validations(merchant_id,canonical_hash) WHERE canonical_hash IS NOT NULL;
CREATE TABLE bulk_decisions (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, row_id uuid NOT NULL, mapping_version integer NOT NULL, decision text NOT NULL CHECK(decision IN ('keep','skip')), reason text NOT NULL CHECK(length(reason) BETWEEN 3 AND 500), actor_id uuid NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,batch_id,row_id,mapping_version), FOREIGN KEY(merchant_id,batch_id,row_id,mapping_version) REFERENCES bulk_validations(merchant_id,batch_id,row_id,mapping_version)
);
CREATE TABLE bulk_prices (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, row_id uuid NOT NULL, work_id uuid NOT NULL, mapping_version integer NOT NULL,
 public_snapshot jsonb, error_code text, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id), UNIQUE(merchant_id,work_id,row_id),
 FOREIGN KEY(merchant_id,batch_id,row_id,mapping_version) REFERENCES bulk_validations(merchant_id,batch_id,row_id,mapping_version), FOREIGN KEY(merchant_id,batch_id,work_id) REFERENCES bulk_work(merchant_id,batch_id,id), CHECK((public_snapshot IS NULL)<>(error_code IS NULL))
);
CREATE TABLE bulk_approvals (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, mapping_version integer NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), include_unpriced boolean NOT NULL, row_set_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,batch_id), FOREIGN KEY(merchant_id,batch_id,mapping_version) REFERENCES bulk_mappings(merchant_id,batch_id,version)
);
CREATE TABLE bulk_drafts (
 merchant_id uuid NOT NULL, batch_id uuid NOT NULL, row_id uuid NOT NULL, shipment_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(merchant_id,batch_id,row_id), UNIQUE(merchant_id,shipment_id), FOREIGN KEY(merchant_id,batch_id,row_id) REFERENCES bulk_rows(merchant_id,batch_id,id), FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
CREATE TABLE bulk_events (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, batch_id uuid NOT NULL, actor_id uuid REFERENCES users(id), kind text NOT NULL, metadata jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,batch_id) REFERENCES bulk_batches(merchant_id,id)
);
CREATE TABLE bulk_commands (
 merchant_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), key_hash char(64) NOT NULL, operation text NOT NULL, digest char(64) NOT NULL, result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(merchant_id,actor_id,operation,key_hash), FOREIGN KEY(merchant_id) REFERENCES merchants(id)
);
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['bulk_files','bulk_rows','bulk_mappings','bulk_validations','bulk_decisions','bulk_prices','bulk_approvals','bulk_drafts','bulk_events','bulk_commands'] LOOP
 EXECUTE format('CREATE TRIGGER bulk_history_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable()',t);
 END LOOP;
END $$;
CREATE OR REPLACE FUNCTION bulk_identity_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF (NEW.id,NEW.merchant_id,NEW.created_at,NEW.format,NEW.delimiter) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.created_at,OLD.format,OLD.delimiter) THEN RAISE EXCEPTION 'immutable import identity' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM bulk_approvals a WHERE a.batch_id=OLD.id) AND NEW.mapping_version<>OLD.mapping_version THEN RAISE EXCEPTION 'immutable approved import mapping' USING ERRCODE='23514'; END IF;
 IF NEW.state<>OLD.state AND NOT (
 (OLD.state='initialized' AND NEW.state IN ('parsing','cancelled')) OR
 (OLD.state='parsing' AND NEW.state IN ('mapping','failed','blocked','cancelled')) OR
 (OLD.state='mapping' AND NEW.state IN ('validating','cancelled')) OR
 (OLD.state='validating' AND NEW.state IN ('review','failed','blocked','cancelled')) OR
 (OLD.state='review' AND NEW.state IN ('validating','pricing','preparing','cancelled')) OR
 (OLD.state='pricing' AND NEW.state IN ('review','failed','blocked','cancelled')) OR
 (OLD.state='preparing' AND NEW.state IN ('completed','partial','failed','blocked','cancelled')) OR
 (OLD.state IN ('failed','blocked') AND NEW.state='cancelled')) THEN RAISE EXCEPTION 'invalid import transition' USING ERRCODE='23514'; END IF;
 IF NEW.state='cancelled' AND EXISTS(SELECT 1 FROM bulk_drafts d WHERE d.batch_id=OLD.id) THEN RAISE EXCEPTION 'committed import cannot cancel' USING ERRCODE='23514'; END IF;
 IF OLD.state='preparing' AND (NEW.actor_id,NEW.auth_version,NEW.origin_token_id,NEW.origin_session_id) IS DISTINCT FROM (OLD.actor_id,OLD.auth_version,OLD.origin_token_id,OLD.origin_session_id) THEN RAISE EXCEPTION 'immutable preparation origin' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER bulk_batch_identity BEFORE UPDATE ON bulk_batches FOR EACH ROW EXECUTE FUNCTION bulk_identity_guard();
CREATE TRIGGER bulk_batch_delete BEFORE DELETE OR TRUNCATE ON bulk_batches FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
