CREATE TABLE pricing_definitions (
 id uuid PRIMARY KEY, kind text NOT NULL CHECK(kind IN ('service','zone','tier','assignment','tariff','rule','fee','tax')),
 merchant_id uuid REFERENCES merchants(id), code text NOT NULL CHECK(length(code) BETWEEN 1 AND 80),
 version integer NOT NULL DEFAULT 1 CHECK(version>0), state_version integer NOT NULL DEFAULT 1 CHECK(state_version>0), active_version integer, enabled boolean NOT NULL DEFAULT false,
 created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(merchant_id IS NULL OR kind IN ('assignment','rule')), CHECK(NOT enabled OR active_version IS NOT NULL),
 UNIQUE NULLS NOT DISTINCT(kind,merchant_id,code), UNIQUE(id,merchant_id)
);
CREATE TABLE pricing_versions (
 definition_id uuid NOT NULL REFERENCES pricing_definitions(id), version integer NOT NULL CHECK(version>0),
 configuration jsonb NOT NULL CHECK(jsonb_typeof(configuration)='object'),
 effective_from timestamptz NOT NULL, effective_to timestamptz NOT NULL CHECK(effective_to>effective_from),
 currency text REFERENCES financial_currencies(code), created_by uuid NOT NULL REFERENCES users(id),
 created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(definition_id,version),
 CHECK(length(configuration::text)<65536)
);
ALTER TABLE pricing_definitions ADD FOREIGN KEY(id,active_version) REFERENCES pricing_versions(definition_id,version) DEFERRABLE INITIALLY DEFERRED;
CREATE TABLE pricing_events (
 id uuid PRIMARY KEY, definition_id uuid NOT NULL, version integer NOT NULL,
 actor_id uuid NOT NULL REFERENCES users(id), session_id uuid NOT NULL REFERENCES auth_sessions(id),
 action text NOT NULL CHECK(action IN ('draft','publish','disable')), access_grant uuid REFERENCES platform_access_grants(id),
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(definition_id,version) REFERENCES pricing_versions(definition_id,version)
);
CREATE TABLE shipping_quotes (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), actor_id uuid NOT NULL REFERENCES users(id),
 parent_id uuid, revision integer NOT NULL CHECK(revision>0), currency text NOT NULL REFERENCES financial_currencies(code),
 public_snapshot jsonb NOT NULL CHECK(jsonb_typeof(public_snapshot)='object'), private_snapshot text NOT NULL,
 expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
 CHECK(expires_at>created_at), UNIQUE(merchant_id,id),
 FOREIGN KEY(merchant_id,parent_id) REFERENCES shipping_quotes(merchant_id,id)
);
CREATE TABLE pricing_quote_requests (
 merchant_id uuid NOT NULL, actor_id uuid NOT NULL REFERENCES users(id), key_hash text NOT NULL CHECK(length(key_hash)=64),
 digest text NOT NULL CHECK(length(digest)=64), quote_id uuid NOT NULL,
 PRIMARY KEY(merchant_id,actor_id,key_hash), FOREIGN KEY(merchant_id,quote_id) REFERENCES shipping_quotes(merchant_id,id)
);
CREATE INDEX pricing_active ON pricing_definitions(kind) WHERE enabled;
CREATE INDEX pricing_merchant ON pricing_definitions(merchant_id,kind);
CREATE INDEX quote_history ON shipping_quotes(merchant_id,created_at DESC,id);
CREATE INDEX pricing_event_history ON pricing_events(definition_id,created_at DESC);
CREATE OR REPLACE FUNCTION pricing_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN RAISE EXCEPTION 'Pricing history is immutable'; END $$;
CREATE TRIGGER immutable_pricing_versions BEFORE UPDATE OR DELETE ON pricing_versions FOR EACH ROW EXECUTE FUNCTION pricing_immutable();
CREATE TRIGGER immutable_pricing_events BEFORE UPDATE OR DELETE ON pricing_events FOR EACH ROW EXECUTE FUNCTION pricing_immutable();
CREATE TRIGGER immutable_shipping_quotes BEFORE UPDATE OR DELETE ON shipping_quotes FOR EACH ROW EXECUTE FUNCTION pricing_immutable();
CREATE TRIGGER immutable_pricing_requests BEFORE UPDATE OR DELETE ON pricing_quote_requests FOR EACH ROW EXECUTE FUNCTION pricing_immutable();
CREATE OR REPLACE FUNCTION pricing_identity() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
BEGIN
 IF (NEW.id,NEW.kind,NEW.merchant_id,NEW.code,NEW.created_at) IS DISTINCT FROM (OLD.id,OLD.kind,OLD.merchant_id,OLD.code,OLD.created_at) THEN RAISE EXCEPTION 'Pricing identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER pricing_identity_guard BEFORE UPDATE ON pricing_definitions FOR EACH ROW EXECUTE FUNCTION pricing_identity();
-- Typed projections provide real PostgreSQL FKs and bounds for versioned payloads.
ALTER TABLE pricing_versions
 ADD service_id uuid GENERATED ALWAYS AS ((configuration->>'service_id')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD tier_id uuid GENERATED ALWAYS AS ((configuration->>'tier_id')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD tax_id uuid GENERATED ALWAYS AS ((configuration->>'tax_id')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD origin_zone uuid GENERATED ALWAYS AS ((configuration->>'origin_zone')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD destination_zone uuid GENERATED ALWAYS AS ((configuration->>'destination_zone')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD weight_from bigint GENERATED ALWAYS AS ((configuration->>'weight_from')::bigint) STORED,
 ADD weight_to bigint GENERATED ALWAYS AS ((configuration->>'weight_to')::bigint) STORED,
 ADD packages_from integer GENERATED ALWAYS AS ((configuration->>'packages_from')::integer) STORED,
 ADD packages_to integer GENERATED ALWAYS AS ((configuration->>'packages_to')::integer) STORED,
 ADD CHECK(weight_from IS NULL OR (weight_from>=0 AND weight_to>weight_from AND weight_to<=1000000001)),
 ADD CHECK(packages_from IS NULL OR (packages_from>=1 AND packages_to>=packages_from AND packages_to<=100));
CREATE INDEX pricing_service_bands ON pricing_versions(service_id,weight_from,weight_to);
CREATE OR REPLACE FUNCTION pricing_version_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE d pricing_definitions; key text; expected text; ref uuid;
BEGIN
 SELECT * INTO STRICT d FROM pricing_definitions WHERE id=NEW.definition_id;
 IF jsonb_typeof(NEW.configuration->'evidence') IS DISTINCT FROM 'string' OR length(NEW.configuration->>'evidence')<10 THEN RAISE EXCEPTION 'Verified evidence reference required' USING ERRCODE='23514'; END IF;
 IF (d.merchant_id IS NOT NULL) IS DISTINCT FROM (d.kind='assignment' OR (d.kind='rule' AND NEW.configuration->>'scope'='merchant')) THEN RAISE EXCEPTION 'Invalid pricing tenant scope' USING ERRCODE='23514'; END IF;
 IF d.kind IN ('tariff','rule','fee') AND ((NEW.configuration->>'service_id') IS NULL OR NEW.currency IS NULL OR (NEW.configuration->>'weight_from') IS NULL OR (NEW.configuration->>'weight_to') IS NULL OR (NEW.configuration->>'packages_from') IS NULL OR (NEW.configuration->>'packages_to') IS NULL) THEN RAISE EXCEPTION 'Missing pricing selectors' USING ERRCODE='23514'; END IF;
 IF d.kind='rule' AND ((NEW.configuration->>'tax_id') IS NULL OR NEW.configuration->>'scope' NOT IN ('global','carrier','tier','merchant') OR ((NEW.configuration->>'scope'='tier') IS DISTINCT FROM ((NEW.configuration->>'tier_id') IS NOT NULL))) THEN RAISE EXCEPTION 'Invalid selling rule references' USING ERRCODE='23514'; END IF;
 IF d.kind='assignment' AND (NEW.configuration->>'tier_id') IS NULL THEN RAISE EXCEPTION 'Missing tier' USING ERRCODE='23514'; END IF;
 IF d.kind='tariff' AND (NEW.configuration->>'origin_country' IS NULL OR NEW.configuration->>'destination_country' IS NULL) THEN RAISE EXCEPTION 'Explicit contract route required' USING ERRCODE='23514'; END IF;
 FOREACH key IN ARRAY ARRAY['base_minor','fixed_minor','step_minor','discount_minor','min_margin_minor'] LOOP
  IF NEW.configuration ? key AND ((NEW.configuration->>key)!~'^(0|[1-9][0-9]{0,12})$' OR (NEW.configuration->>key)::numeric>1000000000000 OR jsonb_typeof(NEW.configuration->key)<>'string') THEN RAISE EXCEPTION 'Invalid minor-unit amount' USING ERRCODE='23514'; END IF;
 END LOOP;
 FOREACH key IN ARRAY ARRAY['percent_bps','discount_bps','min_margin_bps'] LOOP
  IF NEW.configuration ? key AND ((NEW.configuration->>key)!~'^[0-9]+$' OR (NEW.configuration->>key)::numeric>100000) THEN RAISE EXCEPTION 'Invalid basis points' USING ERRCODE='23514'; END IF;
 END LOOP;
 FOREACH key IN ARRAY ARRAY['service_id','tier_id','tax_id','origin_zone','destination_zone'] LOOP
  ref := (NEW.configuration->>key)::uuid;
  expected := CASE key WHEN 'service_id' THEN 'service' WHEN 'tier_id' THEN 'tier' WHEN 'tax_id' THEN 'tax' ELSE 'zone' END;
  IF ref IS NOT NULL AND NOT EXISTS(SELECT 1 FROM pricing_definitions WHERE id=ref AND kind=expected AND merchant_id IS NULL) THEN RAISE EXCEPTION 'Invalid pricing relationship' USING ERRCODE='23514'; END IF;
 END LOOP;
 RETURN NEW;
END $$;
CREATE TRIGGER pricing_version_guard BEFORE INSERT ON pricing_versions FOR EACH ROW EXECUTE FUNCTION pricing_version_guard();
CREATE OR REPLACE FUNCTION pricing_specificity(c jsonb) RETURNS integer LANGUAGE sql IMMUTABLE SET search_path=pg_catalog,public,pg_temp AS $$
 SELECT (CASE WHEN c->>'origin_country' IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN c->>'destination_country' IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN c->>'origin_zone' IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN c->>'destination_zone' IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN c->>'lane' IS NOT NULL THEN 1 ELSE 0 END)+(CASE WHEN (c->>'weight_from')::bigint>0 OR (c->>'weight_to')::bigint<1000000001 THEN 1 ELSE 0 END)+(CASE WHEN (c->>'packages_from')::integer>1 OR (c->>'packages_to')::integer<100 THEN 1 ELSE 0 END)
$$;
CREATE OR REPLACE FUNCTION pricing_publication_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE d pricing_definitions; v pricing_versions; other record; c jsonb; b jsonb; selector text; compatible boolean;
BEGIN
 PERFORM pg_advisory_xact_lock(5052026);
 SELECT * INTO STRICT d FROM pricing_definitions WHERE id=NEW.id;
 IF NOT d.enabled THEN RETURN NULL; END IF;
 SELECT * INTO STRICT v FROM pricing_versions WHERE definition_id=d.id AND version=d.active_version;
 c:=v.configuration;
 FOR other IN SELECT pv.configuration FROM pricing_definitions pd JOIN pricing_versions pv ON pv.definition_id=pd.id AND pv.version=pd.active_version WHERE pd.id<>d.id AND pd.kind=d.kind AND pd.merchant_id IS NOT DISTINCT FROM d.merchant_id AND pd.enabled AND tstzrange(pv.effective_from,pv.effective_to,'[)') && tstzrange(v.effective_from,v.effective_to,'[)') LOOP
  b:=other.configuration;
  IF d.kind='assignment' THEN RAISE EXCEPTION 'Conflicting tier assignment' USING ERRCODE='23514'; END IF;
  IF d.kind='zone' AND c->>'country'=b->>'country' AND EXISTS(SELECT 1 FROM jsonb_array_elements_text(c->'cities') x JOIN jsonb_array_elements_text(b->'cities') y ON x.value=y.value) THEN RAISE EXCEPTION 'Conflicting city zones' USING ERRCODE='23514'; END IF;
  IF d.kind NOT IN ('rule','tariff','fee') THEN CONTINUE; END IF;
  IF d.kind='rule' AND ((c->>'scope' IS DISTINCT FROM b->>'scope') OR (c->>'tier_id' IS DISTINCT FROM b->>'tier_id') OR pricing_specificity(c)<>pricing_specificity(b)) THEN CONTINUE; END IF;
  IF d.kind='fee' AND (c->>'code'<>b->>'code' OR c->>'side'<>b->>'side') THEN CONTINUE; END IF;
  compatible:=true;
  FOREACH selector IN ARRAY ARRAY['service_id','origin_country','destination_country','origin_zone','destination_zone','lane'] LOOP
   IF c->>selector IS NOT NULL AND b->>selector IS NOT NULL AND c->>selector<>b->>selector THEN compatible:=false; END IF;
  END LOOP;
  IF compatible AND int8range((c->>'weight_from')::bigint,(c->>'weight_to')::bigint,'[)') && int8range((b->>'weight_from')::bigint,(b->>'weight_to')::bigint,'[)') AND int4range((c->>'packages_from')::integer,(c->>'packages_to')::integer,'[]') && int4range((b->>'packages_from')::integer,(b->>'packages_to')::integer,'[]') THEN RAISE EXCEPTION 'Conflicting published price bands' USING ERRCODE='23514'; END IF;
 END LOOP;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER pricing_publication_guard AFTER INSERT OR UPDATE ON pricing_definitions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION pricing_publication_guard();

ALTER TABLE shipping_quotes
 ADD service_id uuid GENERATED ALWAYS AS ((public_snapshot->>'service_id')::uuid) STORED REFERENCES pricing_definitions(id),
 ADD CHECK(public_snapshot->>'currency'=currency),
 ADD CHECK(public_snapshot->>'total_minor' ~ '^(0|[1-9][0-9]{0,12})$' AND (public_snapshot->>'total_minor')::numeric<=1000000000000),
 ADD CHECK(public_snapshot->'shipping_enabled'='false'::jsonb),
 ADD CHECK(NOT jsonb_exists_any(public_snapshot,array['carrier_cost_minor','estimated_margin_minor','configuration','selected_versions','private_snapshot']));
ALTER TABLE shipping_quotes
 ADD CHECK(public_snapshot ?& array['id','service_id','currency','total_minor','shipping_enabled','expires_at','pricing_version','inputs']),
 ADD CHECK(public_snapshot->>'id'=id::text AND jsonb_typeof(public_snapshot->'total_minor')='string');
CREATE OR REPLACE FUNCTION pricing_event_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE tenant uuid; maker uuid;
BEGIN
 SELECT d.merchant_id,v.created_by INTO STRICT tenant,maker FROM pricing_definitions d JOIN pricing_versions v ON v.definition_id=d.id WHERE d.id=NEW.definition_id AND v.version=NEW.version;
 IF (NEW.action='draft' AND maker<>NEW.actor_id) OR (NEW.action<>'draft' AND maker=NEW.actor_id) THEN RAISE EXCEPTION 'Independent pricing authority required' USING ERRCODE='23514'; END IF;
 IF tenant IS NULL AND NEW.access_grant IS NOT NULL THEN RAISE EXCEPTION 'Unexpected tenant grant' USING ERRCODE='23514'; END IF;
 IF tenant IS NOT NULL AND NOT EXISTS(SELECT 1 FROM platform_access_grants WHERE id=NEW.access_grant AND merchant_id=tenant AND actor_id=NEW.actor_id AND session_id=NEW.session_id) THEN RAISE EXCEPTION 'Cross-tenant pricing audit relationship' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER pricing_event_guard BEFORE INSERT ON pricing_events FOR EACH ROW EXECUTE FUNCTION pricing_event_guard();
