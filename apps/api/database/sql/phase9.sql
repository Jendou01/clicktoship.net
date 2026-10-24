
ALTER TABLE carrier_accounts DROP CONSTRAINT carrier_accounts_carrier_check;
ALTER TABLE carrier_accounts ADD CONSTRAINT carrier_accounts_carrier_check CHECK(carrier ~ '^[a-z][a-z0-9_]{1,39}$');
ALTER TABLE carrier_tracking_events DROP CONSTRAINT carrier_tracking_events_normalized_status_check;
ALTER TABLE carrier_tracking_events ADD CONSTRAINT carrier_tracking_events_normalized_status_check CHECK(normalized_status IN ('unknown','carrier_accepted','pickup_scheduled','picked_up','in_transit','at_sorting_facility','out_for_delivery','delivery_attempted','delivered','exception','returning','returned','cancelled'));
ALTER TABLE shipment_submissions ADD CONSTRAINT tracking_submission_identity UNIQUE(merchant_id,shipment_id,carrier_operation_id);
CREATE TABLE tracking_bindings (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, operation_id uuid NOT NULL, account_id uuid NOT NULL,
 observation_id uuid NOT NULL, reference_ciphertext text NOT NULL, reference_hash char(64) NOT NULL, provenance text NOT NULL CHECK(provenance IN ('verified','synthetic_test_only')),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id), UNIQUE(merchant_id,shipment_id), UNIQUE(account_id,merchant_id,reference_hash), UNIQUE(merchant_id,id,account_id), UNIQUE(merchant_id,id,account_id,operation_id),
 FOREIGN KEY(merchant_id,shipment_id,operation_id) REFERENCES shipment_submissions(merchant_id,shipment_id,carrier_operation_id),
 FOREIGN KEY(account_id,merchant_id,operation_id) REFERENCES carrier_operations(account_id,merchant_id,id),
 FOREIGN KEY(merchant_id,observation_id) REFERENCES shipping_observations(merchant_id,id)
);
CREATE TABLE tracking_endpoints (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), account_id uuid NOT NULL REFERENCES carrier_accounts(id),
 enabled boolean NOT NULL DEFAULT false, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,account_id,id)
);
CREATE TABLE tracking_work (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), binding_id uuid, account_id uuid NOT NULL REFERENCES carrier_accounts(id), endpoint_id uuid,
 kind text NOT NULL CHECK(kind IN ('poll','scheduled','webhook')), business_key char(64) NOT NULL, digest char(64) NOT NULL,
 payload_ciphertext text, replay_hash char(64), state text NOT NULL CHECK(state IN ('pending','processing','completed','blocked','quarantined','dead_letter')),
 actor_id uuid REFERENCES users(id), auth_version integer, origin_token_id uuid, origin_session_id uuid,
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5), lease_token uuid, lease_until timestamptz, available_at timestamptz NOT NULL DEFAULT now(),
 error_code text, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(account_id,merchant_id,kind,business_key), UNIQUE(endpoint_id,replay_hash),
 CONSTRAINT tracking_work_binding_account_scope FOREIGN KEY(merchant_id,binding_id,account_id) REFERENCES tracking_bindings(merchant_id,id,account_id), FOREIGN KEY(merchant_id,account_id,endpoint_id) REFERENCES tracking_endpoints(merchant_id,account_id,id),
 CHECK((kind='webhook' AND endpoint_id IS NOT NULL AND replay_hash IS NOT NULL AND payload_ciphertext IS NOT NULL) OR (kind<>'webhook' AND binding_id IS NOT NULL)),
 CHECK((kind='poll' AND actor_id IS NOT NULL AND auth_version IS NOT NULL AND ((origin_token_id IS NULL)<>(origin_session_id IS NULL))) OR kind<>'poll')
);
CREATE UNIQUE INDEX tracking_one_active_poll ON tracking_work(merchant_id,binding_id) WHERE kind IN ('poll','scheduled') AND state IN ('pending','processing');
CREATE INDEX tracking_work_due ON tracking_work(state,available_at,lease_until);
CREATE TABLE tracking_commands (
 merchant_id uuid NOT NULL REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id), key_hash char(64) NOT NULL, digest char(64) NOT NULL, result jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(merchant_id,actor_id,key_hash)
);
CREATE TABLE tracking_event_details (
 event_id uuid PRIMARY KEY, merchant_id uuid NOT NULL, binding_id uuid NOT NULL, account_id uuid NOT NULL, operation_id uuid NOT NULL,
 work_id uuid NOT NULL, source text NOT NULL CHECK(source IN ('poll','webhook')), validation text NOT NULL CHECK(validation IN ('verified','synthetic_test_only','unknown_code')),
 provenance text NOT NULL, outcome text NOT NULL CHECK(outcome IN ('projected','unknown','stale','conflict')), correlation_id uuid NOT NULL,
 provider_id_ciphertext text NOT NULL, location_ciphertext text, fact_hash char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,event_id), FOREIGN KEY(merchant_id,binding_id,account_id,operation_id) REFERENCES tracking_bindings(merchant_id,id,account_id,operation_id),
 FOREIGN KEY(account_id,merchant_id,operation_id,event_id) REFERENCES carrier_tracking_events(account_id,merchant_id,operation_id,id), FOREIGN KEY(merchant_id,work_id) REFERENCES tracking_work(merchant_id,id)
);
CREATE TABLE tracking_attempts (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, work_id uuid NOT NULL, number integer NOT NULL, outcome text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,work_id) REFERENCES tracking_work(merchant_id,id), UNIQUE(work_id,number,outcome)
);
CREATE TABLE tracking_cases (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, binding_id uuid NOT NULL, event_id uuid NOT NULL, reason text NOT NULL CHECK(reason IN ('conflicting_evidence','unknown_code')),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(event_id,reason), UNIQUE(merchant_id,id),
 FOREIGN KEY(merchant_id,binding_id) REFERENCES tracking_bindings(merchant_id,id), FOREIGN KEY(merchant_id,event_id) REFERENCES tracking_event_details(merchant_id,event_id)
);
CREATE TABLE tracking_reviews (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, case_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL,
 grant_id uuid NOT NULL REFERENCES platform_access_grants(id), key_hash char(64) NOT NULL, digest char(64) NOT NULL, note_ciphertext text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,case_id) REFERENCES tracking_cases(merchant_id,id), FOREIGN KEY(actor_id,session_id) REFERENCES auth_sessions(user_id,id), UNIQUE(merchant_id,actor_id,key_hash)
);
CREATE TABLE tracking_account_health (
 account_id uuid PRIMARY KEY REFERENCES carrier_accounts(id), next_call_at timestamptz NOT NULL DEFAULT now(), failures integer NOT NULL DEFAULT 0 CHECK(failures>=0), circuit_until timestamptz
);
CREATE TABLE tracking_preferences (
 merchant_id uuid NOT NULL, user_id uuid NOT NULL, consent boolean NOT NULL DEFAULT false, PRIMARY KEY(merchant_id,user_id), FOREIGN KEY(merchant_id,user_id) REFERENCES merchant_users(merchant_id,user_id)
);
CREATE TABLE tracking_notifications (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, event_id uuid NOT NULL, recipient_id uuid NOT NULL, state text NOT NULL DEFAULT 'disabled' CHECK(state='disabled'), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(event_id,recipient_id), FOREIGN KEY(merchant_id,event_id) REFERENCES tracking_event_details(merchant_id,event_id), FOREIGN KEY(merchant_id,recipient_id) REFERENCES tracking_preferences(merchant_id,user_id)
);
CREATE OR REPLACE FUNCTION tracking_binding_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM shipping_observations o JOIN shipment_submissions s ON s.id=o.submission_id AND s.merchant_id=o.merchant_id JOIN shipments sh ON sh.id=s.shipment_id AND sh.merchant_id=s.merchant_id AND sh.carrier_observation_id=o.id AND sh.state='accepted' WHERE o.id=NEW.observation_id AND o.merchant_id=NEW.merchant_id AND o.outcome='accepted' AND o.provenance=NEW.provenance AND s.shipment_id=NEW.shipment_id AND s.carrier_operation_id=NEW.operation_id) THEN RAISE EXCEPTION 'Invalid tracking submission evidence' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tracking_binding_guard BEFORE INSERT ON tracking_bindings FOR EACH ROW EXECUTE FUNCTION tracking_binding_guard();
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['tracking_bindings','tracking_commands','tracking_event_details','tracking_attempts','tracking_cases','tracking_reviews','tracking_notifications'] LOOP
 EXECUTE format('CREATE TRIGGER tracking_history_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable()',t);
 END LOOP;
END $$;
CREATE OR REPLACE FUNCTION tracking_work_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF (NEW.id,NEW.merchant_id,NEW.binding_id,NEW.account_id,NEW.endpoint_id,NEW.kind,NEW.business_key,NEW.digest,NEW.payload_ciphertext,NEW.replay_hash,NEW.actor_id,NEW.auth_version,NEW.origin_token_id,NEW.origin_session_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.binding_id,OLD.account_id,OLD.endpoint_id,OLD.kind,OLD.business_key,OLD.digest,OLD.payload_ciphertext,OLD.replay_hash,OLD.actor_id,OLD.auth_version,OLD.origin_token_id,OLD.origin_session_id,OLD.created_at) THEN RAISE EXCEPTION 'Tracking work identity immutable'; END IF;
 IF OLD.state IN ('completed','blocked','quarantined','dead_letter') AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Tracking work terminal'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tracking_work_guard BEFORE UPDATE ON tracking_work FOR EACH ROW EXECUTE FUNCTION tracking_work_guard();
CREATE TRIGGER tracking_work_delete BEFORE DELETE ON tracking_work FOR EACH ROW EXECUTE FUNCTION shipping_immutable();

CREATE TABLE tracking_scan_cursors(name text PRIMARY KEY,cursor uuid);
CREATE TABLE tracking_receipts (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, endpoint_id uuid NOT NULL, account_id uuid NOT NULL, work_id uuid,
 outcome text NOT NULL CHECK(outcome IN ('accepted','duplicate','replay_conflict')), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,account_id,endpoint_id) REFERENCES tracking_endpoints(merchant_id,account_id,id), FOREIGN KEY(merchant_id,work_id) REFERENCES tracking_work(merchant_id,id)
);
CREATE TRIGGER tracking_history_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON tracking_receipts FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE TRIGGER tracking_work_truncate BEFORE TRUNCATE ON tracking_work FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE TRIGGER tracking_carrier_history_truncate BEFORE TRUNCATE ON carrier_tracking_events FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE TRIGGER tracking_delivery_history_truncate BEFORE TRUNCATE ON carrier_webhook_deliveries FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE OR REPLACE FUNCTION tracking_projection_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM carrier_tracking_events e JOIN tracking_event_details d ON d.event_id=e.id WHERE e.id=NEW.event_id AND e.account_id=NEW.account_id AND e.merchant_id=NEW.merchant_id AND e.operation_id=NEW.operation_id AND e.normalized_status=NEW.status AND e.occurred_at=NEW.occurred_at AND d.outcome='projected') THEN RAISE EXCEPTION 'Projection must match confirmed tracking evidence' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (NEW.occurred_at<=OLD.occurred_at OR (OLD.status IN ('returned','cancelled') AND NEW.status<>OLD.status) OR (OLD.status='delivered' AND NEW.status NOT IN ('delivered','returning','returned'))) THEN RAISE EXCEPTION 'Tracking projection cannot regress' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tracking_projection_guard BEFORE INSERT OR UPDATE ON carrier_tracking_projections FOR EACH ROW EXECUTE FUNCTION tracking_projection_guard();
