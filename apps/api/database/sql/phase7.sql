CREATE TABLE shipments (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), version integer NOT NULL DEFAULT 1 CHECK(version>0), revision integer NOT NULL DEFAULT 1 CHECK(revision>0),
 state text NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','ready','queued','sending','accepted','rejected','unknown','cancelled','blocked')),
 financial_state text NOT NULL DEFAULT 'none' CHECK(financial_state IN ('none','reserved','protected','capture_pending','captured','release_pending','released','review')),
 carrier_observation_id uuid,
 exception_code text CHECK(exception_code IS NULL OR exception_code='CONFLICTING_CARRIER_EVIDENCE'),
 document_state text NOT NULL DEFAULT 'not_requested' CHECK(document_state IN ('not_requested','pending','quarantined','ready','failed')),
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id)
);
CREATE TABLE shipment_revisions (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, revision integer NOT NULL CHECK(revision>0), payload text NOT NULL,
 input_hash char(64) NOT NULL, pricing_hash char(64) NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,shipment_id,revision), UNIQUE(merchant_id,id), FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
ALTER TABLE shipments ADD FOREIGN KEY(merchant_id,id,revision) REFERENCES shipment_revisions(merchant_id,shipment_id,revision) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE shipment_validations (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, revision integer NOT NULL, valid boolean NOT NULL, report jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,shipment_id,revision) REFERENCES shipment_revisions(merchant_id,shipment_id,revision)
);
CREATE TABLE pricing_quote_acceptances (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, revision integer NOT NULL, quote_id uuid NOT NULL, input_hash char(64) NOT NULL,
 pricing_hash char(64) NOT NULL, amount bigint NOT NULL CHECK(amount>0), currency char(3) NOT NULL REFERENCES financial_currencies(code), snapshot jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(merchant_id,quote_id), UNIQUE(merchant_id,shipment_id,revision),
 FOREIGN KEY(merchant_id,shipment_id,revision) REFERENCES shipment_revisions(merchant_id,shipment_id,revision), FOREIGN KEY(merchant_id,quote_id) REFERENCES shipping_quotes(merchant_id,id)
);
CREATE TABLE shipment_submissions (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, revision integer NOT NULL, acceptance_id uuid NOT NULL,
 wallet_id uuid NOT NULL, hold_id uuid NOT NULL, currency char(3) NOT NULL, amount bigint NOT NULL CHECK(amount>0), carrier_operation_id uuid NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id), auth_version integer NOT NULL, origin_token_id uuid, origin_session_id uuid,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id), UNIQUE(merchant_id,shipment_id), UNIQUE(merchant_id,acceptance_id), UNIQUE(merchant_id,hold_id), UNIQUE(merchant_id,carrier_operation_id),
 FOREIGN KEY(merchant_id,shipment_id,revision) REFERENCES shipment_revisions(merchant_id,shipment_id,revision),
 FOREIGN KEY(merchant_id,acceptance_id) REFERENCES pricing_quote_acceptances(merchant_id,id),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency), FOREIGN KEY(merchant_id,hold_id,currency) REFERENCES balance_holds(merchant_id,id,currency),
 CHECK((origin_token_id IS NOT NULL)::int + (origin_session_id IS NOT NULL)::int = 1)
);
ALTER TABLE carrier_operations ADD CONSTRAINT shipping_carrier_operation_scope UNIQUE(merchant_id,id);
ALTER TABLE shipment_submissions ADD FOREIGN KEY(merchant_id,carrier_operation_id) REFERENCES carrier_operations(merchant_id,id);
CREATE TABLE shipment_events (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, kind text NOT NULL, actor_id uuid REFERENCES users(id), metadata jsonb NOT NULL DEFAULT '{}', created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
CREATE TABLE shipping_request_keys (
 merchant_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), operation text NOT NULL, key_hash char(64) NOT NULL, digest char(64) NOT NULL, result jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(merchant_id,actor_id,operation,key_hash), FOREIGN KEY(merchant_id) REFERENCES merchants(id)
);
CREATE TABLE shipping_outbox (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, submission_id uuid NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(submission_id),
 FOREIGN KEY(merchant_id,submission_id) REFERENCES shipment_submissions(merchant_id,id)
);
CREATE TABLE shipping_deliveries (
 event_id uuid PRIMARY KEY REFERENCES shipping_outbox(id), completed_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE shipping_observations (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, submission_id uuid NOT NULL, outcome text NOT NULL CHECK(outcome IN ('accepted','rejected','unknown')),
 evidence_hash char(64) NOT NULL, carrier_reference text, provenance text NOT NULL CHECK(provenance='synthetic_test_only'), created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(merchant_id,id), UNIQUE(submission_id,evidence_hash), FOREIGN KEY(merchant_id,submission_id) REFERENCES shipment_submissions(merchant_id,id),
 CHECK(carrier_reference IS NULL OR length(carrier_reference) BETWEEN 1 AND 100)
);
CREATE TABLE shipping_reconciliation_requests (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, submission_id uuid NOT NULL, observation_id uuid NOT NULL, maker_id uuid NOT NULL REFERENCES users(id), maker_session uuid NOT NULL,
 maker_grant uuid NOT NULL REFERENCES platform_access_grants(id), reason text NOT NULL, expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id),
 FOREIGN KEY(merchant_id,submission_id) REFERENCES shipment_submissions(merchant_id,id), FOREIGN KEY(merchant_id,observation_id) REFERENCES shipping_observations(merchant_id,id),
 FOREIGN KEY(maker_id,maker_session) REFERENCES auth_sessions(user_id,id), CHECK(expires_at>created_at AND expires_at<=created_at+interval '15 minutes')
);
CREATE TABLE shipping_reconciliation_approvals (
 request_id uuid PRIMARY KEY REFERENCES shipping_reconciliation_requests(id), checker_id uuid NOT NULL REFERENCES users(id), checker_session uuid NOT NULL, checker_grant uuid NOT NULL REFERENCES platform_access_grants(id), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(checker_id,checker_session) REFERENCES auth_sessions(user_id,id)
);
CREATE TABLE financial_hold_resolutions (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, wallet_id uuid NOT NULL, hold_id uuid NOT NULL, currency char(3) NOT NULL, amount bigint NOT NULL CHECK(amount>0),
 observation_id uuid NOT NULL, approval_id uuid REFERENCES shipping_reconciliation_approvals(request_id), kind text NOT NULL CHECK(kind IN ('capture','release')), business_key text NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,hold_id), UNIQUE(merchant_id,kind,business_key),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency), FOREIGN KEY(merchant_id,hold_id,currency) REFERENCES balance_holds(merchant_id,id,currency), FOREIGN KEY(merchant_id,observation_id) REFERENCES shipping_observations(merchant_id,id)
);
CREATE TABLE shipment_documents (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, shipment_id uuid NOT NULL, object_key text NOT NULL UNIQUE, checksum char(64) NOT NULL, size_bytes integer NOT NULL CHECK(size_bytes BETWEEN 1 AND 5242880),
 mime text NOT NULL CHECK(mime='application/pdf'), state text NOT NULL CHECK(state IN ('quarantined','ready','revoked','failed')), provenance text NOT NULL CHECK(provenance='synthetic_test_only'), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id), UNIQUE(merchant_id,id)
);
CREATE TABLE pickups (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), version integer NOT NULL DEFAULT 1 CHECK(version>0), state text NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','sending','confirmed','rejected','unknown','cancel_pending','cancelled')),
 window_start timestamptz NOT NULL, window_end timestamptz NOT NULL, timezone text NOT NULL, origin_hash char(64) NOT NULL, payload text NOT NULL,
 carrier_operation_id uuid, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id), CHECK(window_end>window_start), FOREIGN KEY(merchant_id,carrier_operation_id) REFERENCES carrier_operations(merchant_id,id)
);
CREATE TABLE pickup_shipments (
 merchant_id uuid NOT NULL, pickup_id uuid NOT NULL, shipment_id uuid NOT NULL, PRIMARY KEY(merchant_id,pickup_id,shipment_id),
 FOREIGN KEY(merchant_id,pickup_id) REFERENCES pickups(merchant_id,id), FOREIGN KEY(merchant_id,shipment_id) REFERENCES shipments(merchant_id,id)
);
CREATE TABLE pickup_events (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, pickup_id uuid NOT NULL, kind text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(merchant_id,pickup_id) REFERENCES pickups(merchant_id,id)
);
CREATE INDEX shipments_directory ON shipments(merchant_id,created_at DESC,id DESC);
CREATE INDEX shipment_events_history ON shipment_events(merchant_id,shipment_id,created_at,id);
CREATE INDEX shipments_recovery ON shipments(state,updated_at);
CREATE OR REPLACE FUNCTION shipping_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN RAISE EXCEPTION 'immutable shipment history' USING ERRCODE='23514'; END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['shipment_revisions','shipment_validations','pricing_quote_acceptances','shipment_submissions','shipment_events','shipping_request_keys','shipping_outbox','shipping_deliveries','shipping_observations','shipping_reconciliation_requests','shipping_reconciliation_approvals','financial_hold_resolutions','pickup_shipments','pickup_events'] LOOP
 EXECUTE format('CREATE TRIGGER shipping_history_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable()',t);
 END LOOP;
END $$;
CREATE OR REPLACE FUNCTION shipping_identity_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF (NEW.id,NEW.merchant_id,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.created_at) THEN RAISE EXCEPTION 'immutable shipment ownership' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='pickups' THEN
  IF (NEW.window_start,NEW.window_end,NEW.timezone,NEW.origin_hash,NEW.payload) IS DISTINCT FROM (OLD.window_start,OLD.window_end,OLD.timezone,OLD.origin_hash,OLD.payload) THEN RAISE EXCEPTION 'immutable pickup snapshot' USING ERRCODE='23514'; END IF;
  IF NEW.state<>OLD.state AND NOT ((OLD.state='draft' AND NEW.state IN ('sending','cancelled')) OR (OLD.state='sending' AND NEW.state IN ('confirmed','rejected','unknown')) OR (OLD.state='confirmed' AND NEW.state='cancel_pending') OR (OLD.state='cancel_pending' AND NEW.state IN ('cancelled','confirmed','unknown'))) THEN RAISE EXCEPTION 'invalid pickup transition' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_TABLE_NAME='shipments' THEN
  IF OLD.carrier_observation_id IS NOT NULL AND NEW.carrier_observation_id IS DISTINCT FROM OLD.carrier_observation_id THEN RAISE EXCEPTION 'immutable decisive carrier observation' USING ERRCODE='23514'; END IF;
  IF NEW.revision<>OLD.revision AND (OLD.state NOT IN ('draft','ready') OR NEW.revision<>OLD.revision+1 OR NEW.state<>'draft') THEN RAISE EXCEPTION 'frozen shipment revision' USING ERRCODE='23514'; END IF;
  IF NEW.state<>OLD.state AND NOT ((OLD.state IN ('draft','ready') AND NEW.state IN ('draft','ready','queued','cancelled')) OR (OLD.state='queued' AND NEW.state IN ('sending','blocked')) OR (OLD.state='sending' AND NEW.state IN ('accepted','rejected','unknown'))) THEN RAISE EXCEPTION 'invalid shipment transition' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END $$;
CREATE TRIGGER shipment_identity BEFORE UPDATE ON shipments FOR EACH ROW EXECUTE FUNCTION shipping_identity_guard();
CREATE TRIGGER pickup_identity BEFORE UPDATE ON pickups FOR EACH ROW EXECUTE FUNCTION shipping_identity_guard();
CREATE TRIGGER shipment_delete BEFORE DELETE OR TRUNCATE ON shipments FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE TRIGGER pickup_delete BEFORE DELETE OR TRUNCATE ON pickups FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();
CREATE OR REPLACE FUNCTION shipping_synthetic_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF current_database() NOT IN ('clicktoship_test','clicktoship_e2e') THEN RAISE EXCEPTION 'external evidence and real charges remain disabled' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='financial_hold_resolutions' THEN
  IF NOT EXISTS(SELECT 1 FROM shipping_observations o JOIN shipment_submissions s ON s.id=o.submission_id AND s.merchant_id=o.merchant_id JOIN balance_holds h ON h.id=s.hold_id AND h.merchant_id=s.merchant_id
   WHERE o.id=NEW.observation_id AND s.merchant_id=NEW.merchant_id AND s.wallet_id=NEW.wallet_id AND s.hold_id=NEW.hold_id AND s.currency=NEW.currency AND s.amount=NEW.amount
   AND ((o.outcome='accepted' AND NEW.kind='capture') OR (o.outcome='rejected' AND NEW.kind='release'))
   AND ((NOT h.unknown AND NOT EXISTS(SELECT 1 FROM shipping_observations u WHERE u.submission_id=s.id AND u.outcome='unknown')) OR EXISTS(SELECT 1 FROM shipping_reconciliation_requests r JOIN shipping_reconciliation_approvals a ON a.request_id=r.id WHERE r.id=NEW.approval_id AND r.observation_id=o.id AND r.submission_id=s.id AND r.maker_id<>a.checker_id AND r.expires_at>now()))) THEN RAISE EXCEPTION 'resolution evidence mismatch' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_TABLE_NAME='shipping_reconciliation_approvals' THEN
 IF NOT EXISTS(SELECT 1 FROM shipping_reconciliation_requests WHERE id=NEW.request_id AND maker_id<>NEW.checker_id AND expires_at>now()) THEN RAISE EXCEPTION 'independent approval required' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER observation_test_only BEFORE INSERT ON shipping_observations FOR EACH ROW EXECUTE FUNCTION shipping_synthetic_guard();
CREATE TRIGGER resolution_test_only BEFORE INSERT ON financial_hold_resolutions FOR EACH ROW EXECUTE FUNCTION shipping_synthetic_guard();
CREATE TRIGGER approval_independent BEFORE INSERT ON shipping_reconciliation_approvals FOR EACH ROW EXECUTE FUNCTION shipping_synthetic_guard();
CREATE TRIGGER document_test_only BEFORE INSERT ON shipment_documents FOR EACH ROW EXECUTE FUNCTION shipping_synthetic_guard();

CREATE TABLE pickup_commands (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, pickup_id uuid NOT NULL, operation_id uuid NOT NULL, kind text NOT NULL CHECK(kind IN ('submit','cancel')),
 actor_id uuid NOT NULL REFERENCES users(id), auth_version integer NOT NULL, origin_token_id uuid, origin_session_id uuid, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(pickup_id,kind), FOREIGN KEY(merchant_id,pickup_id) REFERENCES pickups(merchant_id,id), FOREIGN KEY(merchant_id,operation_id) REFERENCES carrier_operations(merchant_id,id),
 CHECK((origin_token_id IS NOT NULL)::int + (origin_session_id IS NOT NULL)::int = 1)
);
CREATE TRIGGER pickup_command_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON pickup_commands FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();

ALTER TABLE pricing_quote_acceptances ADD UNIQUE(merchant_id,shipment_id,revision,id);
ALTER TABLE shipment_submissions ADD FOREIGN KEY(merchant_id,shipment_id,revision,acceptance_id) REFERENCES pricing_quote_acceptances(merchant_id,shipment_id,revision,id);
ALTER TABLE balance_holds ADD CONSTRAINT shipping_hold_wallet_scope UNIQUE(merchant_id,wallet_id,currency,id);
ALTER TABLE shipment_submissions ADD FOREIGN KEY(merchant_id,wallet_id,currency,hold_id) REFERENCES balance_holds(merchant_id,wallet_id,currency,id);
ALTER TABLE shipping_observations ADD UNIQUE(merchant_id,submission_id,id);
ALTER TABLE shipping_reconciliation_requests ADD FOREIGN KEY(merchant_id,submission_id,observation_id) REFERENCES shipping_observations(merchant_id,submission_id,id);
CREATE OR REPLACE FUNCTION shipping_document_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF (NEW.id,NEW.merchant_id,NEW.shipment_id,NEW.object_key,NEW.checksum,NEW.size_bytes,NEW.mime,NEW.provenance,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.merchant_id,OLD.shipment_id,OLD.object_key,OLD.checksum,OLD.size_bytes,OLD.mime,OLD.provenance,OLD.created_at) THEN RAISE EXCEPTION 'immutable document ownership and content' USING ERRCODE='23514'; END IF;
 IF NEW.state<>OLD.state AND NOT ((OLD.state='quarantined' AND NEW.state IN ('ready','failed','revoked')) OR (OLD.state IN ('ready','failed') AND NEW.state='revoked')) THEN RAISE EXCEPTION 'invalid document transition' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER document_guard BEFORE UPDATE ON shipment_documents FOR EACH ROW EXECUTE FUNCTION shipping_document_guard();
CREATE TRIGGER document_delete BEFORE DELETE OR TRUNCATE ON shipment_documents FOR EACH STATEMENT EXECUTE FUNCTION shipping_immutable();

ALTER TABLE shipments ADD FOREIGN KEY(merchant_id,carrier_observation_id) REFERENCES shipping_observations(merchant_id,id);
CREATE OR REPLACE FUNCTION shipment_submission_binding() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM pricing_quote_acceptances a JOIN balance_holds h ON h.merchant_id=a.merchant_id WHERE a.id=NEW.acceptance_id AND a.merchant_id=NEW.merchant_id AND a.shipment_id=NEW.shipment_id AND a.revision=NEW.revision AND a.amount=NEW.amount AND a.currency=NEW.currency AND h.id=NEW.hold_id AND h.wallet_id=NEW.wallet_id AND h.original=NEW.amount AND h.currency=NEW.currency AND h.captured=0 AND h.released=0) THEN RAISE EXCEPTION 'submission price/reservation mismatch' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER submission_binding BEFORE INSERT ON shipment_submissions FOR EACH ROW EXECUTE FUNCTION shipment_submission_binding();
