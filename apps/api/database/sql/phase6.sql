CREATE TABLE carrier_accounts (
 id uuid PRIMARY KEY, carrier text NOT NULL CHECK(carrier='aramex'), code text NOT NULL UNIQUE CHECK(length(code) BETWEEN 1 AND 80),
 environment text NOT NULL CHECK(environment IN ('sandbox','production')), version integer NOT NULL DEFAULT 1 CHECK(version>0),
 active_version integer, state_version integer NOT NULL DEFAULT 1 CHECK(state_version>0), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(id,environment)
);
CREATE TABLE carrier_account_versions (
 account_id uuid NOT NULL REFERENCES carrier_accounts(id), version integer NOT NULL CHECK(version>0),
 name_en text NOT NULL, name_ar text NOT NULL, evidence text NOT NULL CHECK(length(evidence)>=10),
 credentials_ciphertext text, services jsonb NOT NULL CHECK(jsonb_typeof(services)='array' AND jsonb_array_length(services)<=100),
 created_by uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL REFERENCES auth_sessions(id), created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(account_id,version)
);
ALTER TABLE carrier_accounts ADD FOREIGN KEY(id,active_version) REFERENCES carrier_account_versions(account_id,version) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE carrier_config_events (
 id uuid PRIMARY KEY, account_id uuid NOT NULL, version integer NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL REFERENCES auth_sessions(id),
 action text NOT NULL CHECK(action IN ('draft','approve','disable')), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(account_id,version) REFERENCES carrier_account_versions(account_id,version)
);
CREATE TABLE carrier_operations (
 id uuid PRIMARY KEY, account_id uuid NOT NULL REFERENCES carrier_accounts(id), account_version integer NOT NULL, environment text NOT NULL,
 merchant_id uuid NOT NULL REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id), origin_token_id uuid, operation text NOT NULL,
 business_key text NOT NULL CHECK(length(business_key) BETWEEN 8 AND 128), digest text NOT NULL CHECK(length(digest)=64),
 request_ciphertext text NOT NULL, correlation_id uuid NOT NULL UNIQUE,
 state text NOT NULL CHECK(state IN ('pending','sending','succeeded','rejected','unknown','blocked')),
 error_code text, result_ciphertext text, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(account_id,merchant_id,operation,business_key), UNIQUE(account_id,merchant_id,id),
 FOREIGN KEY(account_id,account_version) REFERENCES carrier_account_versions(account_id,version), FOREIGN KEY(account_id,environment) REFERENCES carrier_accounts(id,environment)
);
CREATE TABLE carrier_attempts (
 id uuid PRIMARY KEY, operation_id uuid NOT NULL REFERENCES carrier_operations(id), number integer NOT NULL CHECK(number>0),
 outcome text NOT NULL CHECK(outcome IN ('started','succeeded','rejected','unknown','retryable','blocked')), error_code text, http_status integer CHECK(http_status BETWEEN 100 AND 599),
 correlation_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(operation_id,number,outcome)
);
CREATE TABLE carrier_health_records (
 id uuid PRIMARY KEY, account_id uuid NOT NULL REFERENCES carrier_accounts(id), status text NOT NULL CHECK(status IN ('blocked','unverified','healthy','degraded','open')),
 reason text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE carrier_webhook_deliveries (
 id uuid PRIMARY KEY, account_id uuid NOT NULL, merchant_id uuid NOT NULL, operation_id uuid NOT NULL, fingerprint text NOT NULL CHECK(length(fingerprint)=64),
 payload_ciphertext text NOT NULL, payload_digest text NOT NULL CHECK(length(payload_digest)=64), received_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(account_id,merchant_id,operation_id) REFERENCES carrier_operations(account_id,merchant_id,id), UNIQUE(account_id,fingerprint)
);
CREATE TABLE carrier_tracking_events (
 id uuid PRIMARY KEY, delivery_id uuid NOT NULL REFERENCES carrier_webhook_deliveries(id), account_id uuid NOT NULL, merchant_id uuid NOT NULL, operation_id uuid NOT NULL,
 event_key text NOT NULL CHECK(length(event_key) BETWEEN 1 AND 128), raw_code text NOT NULL CHECK(length(raw_code) BETWEEN 1 AND 100),
 normalized_status text NOT NULL CHECK(normalized_status IN ('unknown','picked_up','in_transit','out_for_delivery','delivered','exception','returning','returned')),
 occurred_at timestamptz NOT NULL, received_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(account_id,merchant_id,operation_id) REFERENCES carrier_operations(account_id,merchant_id,id), UNIQUE(account_id,operation_id,event_key), UNIQUE(account_id,merchant_id,operation_id,id)
);
CREATE TABLE carrier_tracking_projections (
 account_id uuid NOT NULL, merchant_id uuid NOT NULL, operation_id uuid NOT NULL, event_id uuid NOT NULL, status text NOT NULL, occurred_at timestamptz NOT NULL,
 PRIMARY KEY(account_id,merchant_id,operation_id), FOREIGN KEY(account_id,merchant_id,operation_id,event_id) REFERENCES carrier_tracking_events(account_id,merchant_id,operation_id,id)
);
CREATE INDEX carrier_operation_states ON carrier_operations(state,updated_at);
CREATE INDEX carrier_operation_merchant ON carrier_operations(merchant_id,created_at DESC);
CREATE INDEX carrier_health_history ON carrier_health_records(account_id,created_at DESC);
CREATE OR REPLACE FUNCTION carrier_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN RAISE EXCEPTION 'Carrier evidence history is immutable'; END $$;
CREATE TRIGGER carrier_version_immutable BEFORE UPDATE OR DELETE ON carrier_account_versions FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE TRIGGER carrier_config_immutable BEFORE UPDATE OR DELETE ON carrier_config_events FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE TRIGGER carrier_attempt_immutable BEFORE UPDATE OR DELETE ON carrier_attempts FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE TRIGGER carrier_health_immutable BEFORE UPDATE OR DELETE ON carrier_health_records FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE TRIGGER carrier_delivery_immutable BEFORE UPDATE OR DELETE ON carrier_webhook_deliveries FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE TRIGGER carrier_tracking_immutable BEFORE UPDATE OR DELETE ON carrier_tracking_events FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE OR REPLACE FUNCTION carrier_operation_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF (NEW.id,NEW.account_id,NEW.account_version,NEW.environment,NEW.merchant_id,NEW.actor_id,NEW.origin_token_id,NEW.operation,NEW.business_key,NEW.digest,NEW.request_ciphertext,NEW.correlation_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.account_id,OLD.account_version,OLD.environment,OLD.merchant_id,OLD.actor_id,OLD.origin_token_id,OLD.operation,OLD.business_key,OLD.digest,OLD.request_ciphertext,OLD.correlation_id,OLD.created_at) THEN RAISE EXCEPTION 'Carrier operation identity is immutable'; END IF;
 IF NEW.state<>OLD.state AND NOT ((OLD.state='pending' AND NEW.state IN ('sending','blocked')) OR (OLD.state='sending' AND NEW.state IN ('succeeded','rejected','unknown'))) THEN RAISE EXCEPTION 'Invalid carrier operation transition'; END IF;
 IF OLD.state IN ('succeeded','rejected','unknown','blocked') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Terminal carrier operation requires a separate reconciled evidence workflow'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER carrier_operation_guard BEFORE UPDATE ON carrier_operations FOR EACH ROW EXECUTE FUNCTION carrier_operation_guard();
CREATE TRIGGER carrier_operation_delete BEFORE DELETE ON carrier_operations FOR EACH ROW EXECUTE FUNCTION carrier_immutable();
CREATE OR REPLACE FUNCTION carrier_account_identity() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF (NEW.id,NEW.carrier,NEW.code,NEW.environment,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.carrier,OLD.code,OLD.environment,OLD.created_at) THEN RAISE EXCEPTION 'Carrier account identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER carrier_account_identity BEFORE UPDATE ON carrier_accounts FOR EACH ROW EXECUTE FUNCTION carrier_account_identity();

ALTER TABLE carrier_webhook_deliveries ADD UNIQUE(account_id,merchant_id,operation_id,id);
ALTER TABLE carrier_tracking_events ADD FOREIGN KEY(account_id,merchant_id,operation_id,delivery_id) REFERENCES carrier_webhook_deliveries(account_id,merchant_id,operation_id,id);

CREATE OR REPLACE FUNCTION carrier_config_authority() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE maker uuid;
BEGIN
 SELECT created_by INTO STRICT maker FROM carrier_account_versions WHERE account_id=NEW.account_id AND version=NEW.version;
 IF (NEW.action='draft' AND maker<>NEW.actor_id) OR (NEW.action<>'draft' AND maker=NEW.actor_id) THEN RAISE EXCEPTION 'Independent carrier configuration approval required' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM auth_sessions WHERE id=NEW.session_id AND user_id=NEW.actor_id) THEN RAISE EXCEPTION 'Carrier audit session mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER carrier_config_authority BEFORE INSERT ON carrier_config_events FOR EACH ROW EXECUTE FUNCTION carrier_config_authority();
ALTER TABLE carrier_account_versions ADD FOREIGN KEY(created_by,session_id) REFERENCES auth_sessions(user_id,id);
ALTER TABLE carrier_config_events ADD FOREIGN KEY(actor_id,session_id) REFERENCES auth_sessions(user_id,id);
