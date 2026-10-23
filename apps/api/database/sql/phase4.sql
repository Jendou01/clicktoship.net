CREATE TABLE financial_currencies (code char(3) PRIMARY KEY CHECK(code ~ '^[A-Z]{3}$'), exponent smallint NOT NULL CHECK(exponent BETWEEN 0 AND 3), enabled boolean NOT NULL DEFAULT false);
INSERT INTO financial_currencies VALUES ('SAR',2,true),('JPY',0,false),('KWD',3,false);
CREATE TABLE ledger_accounts (
 id uuid PRIMARY KEY, merchant_id uuid REFERENCES merchants(id), currency char(3) NOT NULL REFERENCES financial_currencies(code),
 purpose text NOT NULL, class text NOT NULL CHECK(class IN ('liability','asset','clearing','expense','income')), UNIQUE(merchant_id,id,currency), UNIQUE(id,currency), UNIQUE NULLS NOT DISTINCT(merchant_id,currency,purpose)
);
CREATE TABLE wallets (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), currency char(3) NOT NULL REFERENCES financial_currencies(code), account_id uuid NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,currency), UNIQUE(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,account_id,currency) REFERENCES ledger_accounts(merchant_id,id,currency)
);
CREATE TABLE wallet_balances (
 wallet_id uuid PRIMARY KEY, merchant_id uuid NOT NULL, currency char(3) NOT NULL, posted bigint NOT NULL DEFAULT 0, reserved bigint NOT NULL DEFAULT 0,
 version bigint NOT NULL DEFAULT 0, frozen boolean NOT NULL DEFAULT false, CHECK(posted>=reserved AND reserved>=0),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE TABLE financial_operations (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), wallet_id uuid NOT NULL, currency char(3) NOT NULL, kind text NOT NULL,
 business_key text NOT NULL CHECK(length(business_key) BETWEEN 1 AND 150), digest char(64) NOT NULL, result jsonb NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,kind,business_key), UNIQUE(merchant_id,id), UNIQUE(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE TABLE financial_request_keys (
 merchant_id uuid NOT NULL REFERENCES merchants(id), actor_scope text NOT NULL, request_key_hash char(64) NOT NULL, digest char(64) NOT NULL,
 operation_id uuid NOT NULL REFERENCES financial_operations(id), created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(merchant_id,actor_scope,request_key_hash), FOREIGN KEY(merchant_id,operation_id) REFERENCES financial_operations(merchant_id,id)
);
CREATE TABLE financial_templates (
 code text PRIMARY KEY, debit_purpose text NOT NULL, credit_purpose text NOT NULL, description text NOT NULL, version integer NOT NULL DEFAULT 1 CHECK(version>0)
);
INSERT INTO financial_templates VALUES
 ('capture','wallet','service_clearing','Provisional service clearing; no revenue/tax recognition',1),
 ('refund','service_clearing','wallet','Wallet credit against original service capture; no payout',1),
 ('adjust_credit','adjustment_expense','wallet','Independently approved correction',1),
 ('adjust_debit','wallet','adjustment_income','Independently approved correction',1),
 ('synthetic','test_clearing','wallet','TEST DATABASE ONLY; never a real payment',1);
CREATE TABLE ledger_transactions (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, wallet_id uuid NOT NULL, currency char(3) NOT NULL, operation_id uuid NOT NULL UNIQUE,
 posting_xid xid8 NOT NULL DEFAULT pg_current_xact_id(), template text NOT NULL, template_version integer NOT NULL, reversal_of uuid UNIQUE REFERENCES ledger_transactions(id),
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,operation_id,currency) REFERENCES financial_operations(merchant_id,id,currency)
);
CREATE TABLE ledger_entries (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, transaction_id uuid NOT NULL, account_id uuid NOT NULL, currency char(3) NOT NULL,
 side text NOT NULL CHECK(side IN ('debit','credit')), amount bigint NOT NULL CHECK(amount>0),
 FOREIGN KEY(merchant_id,transaction_id,currency) REFERENCES ledger_transactions(merchant_id,id,currency),
 FOREIGN KEY(account_id,currency) REFERENCES ledger_accounts(id,currency)
);
CREATE INDEX ledger_entries_account ON ledger_entries(account_id,transaction_id);
CREATE TABLE balance_holds (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, wallet_id uuid NOT NULL, currency char(3) NOT NULL,
 original bigint NOT NULL CHECK(original>0), captured bigint NOT NULL DEFAULT 0, released bigint NOT NULL DEFAULT 0,
 protected boolean NOT NULL DEFAULT false, unknown boolean NOT NULL DEFAULT false, expires_at timestamptz NOT NULL, version bigint NOT NULL DEFAULT 1,
 CHECK(captured>=0 AND released>=0 AND captured+released<=original), UNIQUE(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE INDEX balance_holds_wallet ON balance_holds(wallet_id,expires_at);
CREATE TABLE balance_hold_events (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, hold_id uuid NOT NULL, currency char(3) NOT NULL, operation_id uuid NOT NULL UNIQUE,
 kind text NOT NULL, amount bigint NOT NULL CHECK(amount>=0), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,hold_id,currency) REFERENCES balance_holds(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,operation_id,currency) REFERENCES financial_operations(merchant_id,id,currency)
);
CREATE TABLE wallet_transactions (
 id uuid PRIMARY KEY REFERENCES ledger_transactions(id), merchant_id uuid NOT NULL, wallet_id uuid NOT NULL, currency char(3) NOT NULL,
 sequence bigint NOT NULL, delta bigint NOT NULL, posted_after bigint NOT NULL, reserved_after bigint NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(wallet_id,sequence),
 FOREIGN KEY(merchant_id,id,currency) REFERENCES ledger_transactions(merchant_id,id,currency),
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE TABLE financial_refunds (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, original_id uuid NOT NULL, currency char(3) NOT NULL, operation_id uuid NOT NULL UNIQUE REFERENCES financial_operations(id),
 amount bigint NOT NULL CHECK(amount>0), created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(merchant_id,original_id,currency) REFERENCES ledger_transactions(merchant_id,id,currency)
);
CREATE TABLE financial_approvals (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), maker_id uuid NOT NULL REFERENCES users(id), maker_session uuid NOT NULL,
 payload jsonb NOT NULL, digest char(64) NOT NULL, reason text NOT NULL, expires_at timestamptz NOT NULL,
 checker_id uuid REFERENCES users(id), checker_session uuid, checker_grant uuid REFERENCES platform_access_grants(id), approved_at timestamptz, revoked_at timestamptz, executed_operation uuid UNIQUE REFERENCES financial_operations(id), version integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(), CHECK(checker_id IS NULL OR checker_id<>maker_id),
 FOREIGN KEY(maker_id,maker_session) REFERENCES auth_sessions(user_id,id), FOREIGN KEY(checker_id,checker_session) REFERENCES auth_sessions(user_id,id),
 CHECK(expires_at>created_at AND expires_at<=created_at+interval '15 minutes')
);
CREATE TABLE financial_approval_events (
 id uuid PRIMARY KEY, approval_id uuid NOT NULL REFERENCES financial_approvals(id), actor_id uuid NOT NULL REFERENCES users(id), kind text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE financial_outbox (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), operation_id uuid NOT NULL UNIQUE REFERENCES financial_operations(id),
 event_type text NOT NULL, payload jsonb NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(merchant_id,operation_id) REFERENCES financial_operations(merchant_id,id)
);
CREATE TABLE financial_outbox_deliveries (
 event_id uuid NOT NULL REFERENCES financial_outbox(id), consumer text NOT NULL, processed_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(event_id,consumer)
);
CREATE TABLE financial_reconciliation_runs (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL REFERENCES merchants(id), wallet_id uuid NOT NULL REFERENCES wallets(id), currency char(3) NOT NULL,
 cutoff bigint NOT NULL, posted bigint NOT NULL, reserved bigint NOT NULL, ledger_posted numeric NOT NULL, hold_reserved numeric NOT NULL, matches boolean NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(), FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE TABLE financial_case_events (
 id uuid PRIMARY KEY, run_id uuid NOT NULL REFERENCES financial_reconciliation_runs(id), actor_id uuid REFERENCES users(id), kind text NOT NULL, evidence text NOT NULL, created_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE payment_intents (
 id uuid PRIMARY KEY, merchant_id uuid NOT NULL, wallet_id uuid NOT NULL, currency char(3) NOT NULL, amount bigint NOT NULL CHECK(amount>0),
 provider text NOT NULL CHECK(provider='synthetic'), environment text NOT NULL CHECK(environment='testing'), reference text NOT NULL UNIQUE,
 FOREIGN KEY(merchant_id,wallet_id,currency) REFERENCES wallets(merchant_id,id,currency)
);
CREATE TABLE payment_events (
 id uuid PRIMARY KEY, intent_id uuid NOT NULL REFERENCES payment_intents(id), provider text NOT NULL CHECK(provider='synthetic'), environment text NOT NULL CHECK(environment='testing'),
 event_key text NOT NULL, digest char(64) NOT NULL, outcome text NOT NULL, operation_id uuid REFERENCES financial_operations(id), created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(provider,environment,event_key)
);
CREATE OR REPLACE FUNCTION finance_immutable() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN RAISE EXCEPTION 'immutable financial history' USING ERRCODE='23514'; END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['financial_operations','financial_request_keys','ledger_transactions','ledger_entries','balance_hold_events','wallet_transactions','financial_refunds','financial_approval_events','financial_outbox','financial_outbox_deliveries','financial_reconciliation_runs','financial_case_events','payment_events','payment_intents','wallets','ledger_accounts','financial_currencies'] LOOP
 EXECUTE format('CREATE TRIGGER immutable_finance BEFORE UPDATE OR DELETE OR TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION finance_immutable()',t);
 END LOOP;
END $$;
CREATE OR REPLACE FUNCTION finance_balanced() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE tid uuid; m uuid; c char(3); n int; d numeric; BEGIN
 IF TG_TABLE_NAME='ledger_transactions' THEN tid:=NEW.id; ELSE tid:=NEW.transaction_id; END IF;
 SELECT merchant_id,currency INTO m,c FROM ledger_transactions WHERE id=tid;
 SELECT count(*),coalesce(sum(CASE WHEN side='debit' THEN amount::numeric ELSE -amount::numeric END),0) INTO n,d FROM ledger_entries WHERE transaction_id=tid;
 IF n<2 OR d<>0 OR EXISTS(SELECT 1 FROM ledger_entries e JOIN ledger_accounts a ON a.id=e.account_id WHERE e.transaction_id=tid AND (a.currency<>c OR (a.merchant_id IS NOT NULL AND a.merchant_id<>m))) THEN
 RAISE EXCEPTION 'unbalanced or cross-tenant journal' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER journal_balanced AFTER INSERT ON ledger_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION finance_balanced();
CREATE CONSTRAINT TRIGGER entry_balanced AFTER INSERT ON ledger_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION finance_balanced();
CREATE OR REPLACE FUNCTION finance_command(p_merchant uuid,p_wallet uuid,p_kind text,p_amount bigint,p_ref uuid,p_business text,p_digest text,p_scope text,p_request text,p_expires timestamptz DEFAULT NULL)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE w public.wallets%ROWTYPE; b public.wallet_balances%ROWTYPE; h public.balance_holds%ROWTYPE;
 old public.financial_operations%ROWTYPE; req public.financial_request_keys%ROWTYPE; original public.ledger_transactions%ROWTYPE;
 op uuid:=gen_random_uuid(); tid uuid:=gen_random_uuid(); hid uuid:=gen_random_uuid(); contra uuid; tpl public.financial_templates%ROWTYPE;
 delta bigint:=0; reserved_delta bigint:=0; result jsonb; refundable numeric; e record;
BEGIN
 IF length(p_business) NOT BETWEEN 1 AND 150 OR p_request IS NULL OR length(p_request)<>64 OR length(p_digest)<>64 OR p_scope IS NULL THEN RAISE EXCEPTION 'invalid idempotency' USING ERRCODE='23514'; END IF;
 PERFORM 1 FROM public.merchants WHERE id=p_merchant AND status='active' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'inactive merchant' USING ERRCODE='23514'; END IF;
 SELECT * INTO w FROM public.wallets WHERE id=p_wallet AND merchant_id=p_merchant;
 IF NOT FOUND THEN RAISE EXCEPTION 'wallet not found' USING ERRCODE='23514'; END IF;
 SELECT * INTO b FROM public.wallet_balances WHERE wallet_id=p_wallet FOR UPDATE;
 SELECT * INTO req FROM public.financial_request_keys WHERE merchant_id=p_merchant AND actor_scope=p_scope AND request_key_hash=p_request;
 IF FOUND THEN
  IF req.digest<>p_digest THEN RAISE EXCEPTION 'idempotency conflict' USING ERRCODE='23514'; END IF;
  SELECT o.result INTO result FROM public.financial_operations o WHERE o.id=req.operation_id; RETURN result;
 END IF;
 SELECT * INTO old FROM public.financial_operations WHERE merchant_id=p_merchant AND kind=p_kind AND business_key=p_business;
 IF FOUND THEN
  IF old.digest<>p_digest OR old.wallet_id<>p_wallet THEN RAISE EXCEPTION 'business idempotency conflict' USING ERRCODE='23514'; END IF;
  INSERT INTO public.financial_request_keys VALUES(p_merchant,p_scope,p_request,p_digest,old.id,now()); RETURN old.result;
 END IF;
 IF b.frozen OR NOT EXISTS(SELECT 1 FROM public.financial_currencies WHERE code=w.currency AND enabled) THEN RAISE EXCEPTION 'wallet unavailable' USING ERRCODE='23514'; END IF;
 IF p_amount<0 OR p_amount>1000000000000 THEN RAISE EXCEPTION 'amount out of range' USING ERRCODE='23514'; END IF;
 IF p_kind NOT IN ('protect','unknown','reverse') AND p_amount=0 THEN RAISE EXCEPTION 'positive amount required' USING ERRCODE='23514'; END IF;
 IF p_kind IN ('capture','release','protect','unknown','expire') THEN
  SELECT * INTO h FROM public.balance_holds WHERE id=p_ref AND wallet_id=p_wallet AND merchant_id=p_merchant FOR UPDATE;
  IF NOT FOUND OR h.original=h.captured+h.released THEN RAISE EXCEPTION 'hold unavailable' USING ERRCODE='23514'; END IF;
  IF h.unknown AND p_kind NOT IN ('unknown') THEN RAISE EXCEPTION 'unknown outcome requires reconciliation' USING ERRCODE='23514'; END IF;
  IF p_kind IN ('capture','release','expire') AND p_amount>h.original-h.captured-h.released THEN RAISE EXCEPTION 'hold exceeded' USING ERRCODE='23514'; END IF;
 END IF;
 IF p_kind IN ('adjust_credit','adjust_debit') AND current_database() NOT IN ('clicktoship_test','clicktoship_e2e') THEN RAISE EXCEPTION 'accountant acceptance required' USING ERRCODE='23514'; END IF;
 IF p_kind IN ('adjust_credit','adjust_debit') AND NOT EXISTS (
  SELECT 1 FROM public.financial_approvals a
  JOIN public.auth_sessions ms ON ms.id=a.maker_session AND ms.user_id=a.maker_id
  JOIN public.auth_sessions cs ON cs.id=a.checker_session AND cs.user_id=a.checker_id
  WHERE a.merchant_id=p_merchant AND a.approved_at IS NOT NULL AND a.revoked_at IS NULL AND a.executed_operation IS NULL AND a.expires_at>now()
  AND ms.revoked_at IS NULL AND cs.revoked_at IS NULL AND ms.expires_at>now() AND cs.expires_at>now()
  AND a.payload->>'wallet_id'=p_wallet::text AND a.payload->>'kind'=p_kind AND a.payload->>'amount'=p_amount::text AND a.payload->>'business_key'=p_business
 ) THEN RAISE EXCEPTION 'independent financial approval required' USING ERRCODE='23514'; END IF;
 IF p_kind='reserve' THEN
  IF p_expires IS NULL OR p_expires<=now() THEN RAISE EXCEPTION 'invalid expiry' USING ERRCODE='23514'; END IF;
  reserved_delta:=p_amount;
 ELSIF p_kind='capture' THEN delta:=-p_amount; reserved_delta:=-p_amount;
 ELSIF p_kind IN ('release','expire') THEN
  IF h.protected THEN RAISE EXCEPTION 'protected hold requires reconciled outcome' USING ERRCODE='23514'; END IF;
  IF p_kind='expire' AND h.expires_at>now() THEN RAISE EXCEPTION 'hold not expired' USING ERRCODE='23514'; END IF;
  reserved_delta:=-p_amount;
 ELSIF p_kind IN ('protect','unknown') THEN
  IF p_amount<>0 THEN RAISE EXCEPTION 'nonmonetary command' USING ERRCODE='23514'; END IF;
 ELSIF p_kind IN ('synthetic','adjust_credit') THEN
  IF p_kind='synthetic' AND current_database() NOT IN ('clicktoship_test','clicktoship_e2e') THEN RAISE EXCEPTION 'synthetic money forbidden' USING ERRCODE='23514'; END IF;
  delta:=p_amount;
 ELSIF p_kind='adjust_debit' THEN delta:=-p_amount;
 ELSIF p_kind IN ('refund','reverse') THEN
  SELECT * INTO original FROM public.ledger_transactions WHERE id=p_ref AND wallet_id=p_wallet AND merchant_id=p_merchant FOR UPDATE;
  IF NOT FOUND OR original.reversal_of IS NOT NULL OR EXISTS(SELECT 1 FROM public.ledger_transactions WHERE reversal_of=p_ref) THEN RAISE EXCEPTION 'original unavailable' USING ERRCODE='23514'; END IF;
  IF p_kind='refund' THEN
   IF original.template<>'capture' THEN RAISE EXCEPTION 'not refundable' USING ERRCODE='23514'; END IF;
   SELECT coalesce(sum(amount),0) INTO refundable FROM public.financial_refunds WHERE original_id=p_ref;
   IF refundable+p_amount>(SELECT -t.delta FROM public.wallet_transactions t WHERE t.id=p_ref) THEN RAISE EXCEPTION 'refund exceeds capture' USING ERRCODE='23514'; END IF;
   delta:=p_amount;
  ELSE
   IF p_amount<>0 OR EXISTS(SELECT 1 FROM public.financial_refunds WHERE original_id=p_ref) OR original.template='refund' THEN RAISE EXCEPTION 'dependent refund prevents reversal' USING ERRCODE='23514'; END IF;
   SELECT -t.delta INTO delta FROM public.wallet_transactions t WHERE id=p_ref;
  END IF;
 ELSE RAISE EXCEPTION 'unsupported financial command' USING ERRCODE='23514'; END IF;
 IF b.posted::numeric+delta<b.reserved::numeric+reserved_delta OR b.reserved::numeric+reserved_delta<0 THEN RAISE EXCEPTION 'insufficient available funds' USING ERRCODE='23514'; END IF;
 result:=jsonb_build_object('operation_id',op,'wallet_id',p_wallet,'currency',w.currency,'kind',p_kind,'hold_id',CASE WHEN p_kind='reserve' THEN hid WHEN p_kind IN ('capture','release','expire','protect','unknown') THEN p_ref ELSE NULL END,'transaction_id',CASE WHEN delta<>0 THEN tid ELSE NULL END);
 INSERT INTO public.financial_operations VALUES(op,p_merchant,p_wallet,w.currency,p_kind,p_business,p_digest,result,now());
 INSERT INTO public.financial_request_keys VALUES(p_merchant,p_scope,p_request,p_digest,op,now());
 IF p_kind='reserve' THEN
  INSERT INTO public.balance_holds(id,merchant_id,wallet_id,currency,original,expires_at) VALUES(hid,p_merchant,p_wallet,w.currency,p_amount,p_expires);
 ELSIF p_kind IN ('capture','release','expire','protect','unknown') THEN
  UPDATE public.balance_holds SET captured=captured+CASE WHEN p_kind='capture' THEN p_amount ELSE 0 END,
   released=released+CASE WHEN p_kind IN ('release','expire') THEN p_amount ELSE 0 END,
   protected=protected OR p_kind IN ('protect','unknown'),unknown=unknown OR p_kind='unknown',version=version+1 WHERE id=p_ref;
 END IF;
 IF p_kind IN ('reserve','capture','release','expire','protect','unknown') THEN
  INSERT INTO public.balance_hold_events VALUES(gen_random_uuid(),p_merchant,CASE WHEN p_kind='reserve' THEN hid ELSE p_ref END,w.currency,op,p_kind,p_amount,now());
 END IF;
 UPDATE public.wallet_balances SET posted=posted+delta,reserved=reserved+reserved_delta,version=version+1 WHERE wallet_id=p_wallet;
 IF delta<>0 THEN
  IF p_kind='reverse' THEN
   INSERT INTO public.ledger_transactions(id,merchant_id,wallet_id,currency,operation_id,template,template_version,reversal_of,created_at) VALUES(tid,p_merchant,p_wallet,w.currency,op,'reverse',original.template_version,p_ref,now());
   FOR e IN SELECT * FROM public.ledger_entries WHERE transaction_id=p_ref LOOP
    INSERT INTO public.ledger_entries VALUES(gen_random_uuid(),p_merchant,tid,e.account_id,w.currency,CASE WHEN e.side='debit' THEN 'credit' ELSE 'debit' END,e.amount);
   END LOOP;
  ELSE
   SELECT * INTO tpl FROM public.financial_templates WHERE code=p_kind;
   IF NOT FOUND THEN RAISE EXCEPTION 'posting template missing' USING ERRCODE='23514'; END IF;
   INSERT INTO public.ledger_transactions(id,merchant_id,wallet_id,currency,operation_id,template,template_version,reversal_of,created_at) VALUES(tid,p_merchant,p_wallet,w.currency,op,p_kind,tpl.version,NULL,now());
   SELECT id INTO contra FROM public.ledger_accounts WHERE merchant_id IS NULL AND currency=w.currency AND purpose=CASE WHEN delta>0 THEN tpl.debit_purpose ELSE tpl.credit_purpose END;
   IF p_kind='refund' THEN
    SELECT original_entry.account_id INTO contra FROM public.ledger_entries original_entry WHERE original_entry.transaction_id=p_ref AND original_entry.side='credit' AND original_entry.account_id<>w.account_id;
   END IF;
   IF contra IS NULL THEN RAISE EXCEPTION 'counteraccount missing' USING ERRCODE='23514'; END IF;
   INSERT INTO public.ledger_entries VALUES(gen_random_uuid(),p_merchant,tid,w.account_id,w.currency,CASE WHEN delta>0 THEN 'credit' ELSE 'debit' END,abs(delta)),(gen_random_uuid(),p_merchant,tid,contra,w.currency,CASE WHEN delta>0 THEN 'debit' ELSE 'credit' END,abs(delta));
  END IF;
  INSERT INTO public.wallet_transactions VALUES(tid,p_merchant,p_wallet,w.currency,b.version+1,delta,b.posted+delta,b.reserved+reserved_delta,now());
 END IF;
 IF p_kind='refund' THEN INSERT INTO public.financial_refunds VALUES(gen_random_uuid(),p_merchant,p_ref,w.currency,op,p_amount,now()); END IF;
 INSERT INTO public.financial_outbox VALUES(gen_random_uuid(),p_merchant,op,'wallet.'||p_kind,jsonb_build_object('operation_id',op,'wallet_id',p_wallet),now());
 RETURN result;
END $$;
REVOKE ALL ON FUNCTION finance_command(uuid,uuid,text,bigint,uuid,text,text,text,text,timestamptz) FROM PUBLIC;
DO $$ BEGIN IF NOT EXISTS(SELECT 1 FROM pg_roles WHERE rolname='cts_finance_runtime') THEN CREATE ROLE cts_finance_runtime NOLOGIN; END IF; END $$;
GRANT USAGE ON SCHEMA public TO cts_finance_runtime;
GRANT SELECT ON financial_currencies,ledger_accounts,wallets,wallet_balances,financial_operations,financial_request_keys,ledger_transactions,ledger_entries,balance_holds,balance_hold_events,wallet_transactions,financial_refunds,financial_templates TO cts_finance_runtime;
GRANT EXECUTE ON FUNCTION finance_command(uuid,uuid,text,bigint,uuid,text,text,text,text,timestamptz) TO cts_finance_runtime;

CREATE OR REPLACE FUNCTION finance_approval_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF NEW.id<>OLD.id OR NEW.merchant_id<>OLD.merchant_id OR NEW.maker_id<>OLD.maker_id OR NEW.maker_session<>OLD.maker_session OR NEW.payload<>OLD.payload OR NEW.digest<>OLD.digest OR NEW.reason<>OLD.reason OR NEW.expires_at<>OLD.expires_at OR NEW.created_at<>OLD.created_at THEN RAISE EXCEPTION 'immutable approval request' USING ERRCODE='23514'; END IF;
 IF OLD.executed_operation IS NOT NULL OR OLD.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'terminal financial approval' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER financial_approval_guard BEFORE UPDATE ON financial_approvals FOR EACH ROW EXECUTE FUNCTION finance_approval_guard();
CREATE TRIGGER financial_approval_delete BEFORE DELETE OR TRUNCATE ON financial_approvals FOR EACH STATEMENT EXECUTE FUNCTION finance_immutable();
CREATE OR REPLACE FUNCTION finance_projection_check() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$
DECLARE w public.wallets%ROWTYPE; b public.wallet_balances%ROWTYPE; posted numeric; held numeric; BEGIN
 SELECT * INTO w FROM public.wallets WHERE id=NEW.wallet_id;
 SELECT * INTO b FROM public.wallet_balances WHERE wallet_id=NEW.wallet_id;
 SELECT coalesce(sum(CASE WHEN side='credit' THEN amount::numeric ELSE -amount::numeric END),0) INTO posted FROM public.ledger_entries WHERE account_id=w.account_id;
 SELECT coalesce(sum(original::numeric-captured-released),0) INTO held FROM public.balance_holds WHERE wallet_id=w.id;
 IF posted<>b.posted OR held<>b.reserved THEN RAISE EXCEPTION 'financial projection mismatch' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER projection_consistent AFTER INSERT OR UPDATE ON wallet_balances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION finance_projection_check();
CREATE CONSTRAINT TRIGGER hold_consistent AFTER INSERT OR UPDATE ON balance_holds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION finance_projection_check();
CREATE CONSTRAINT TRIGGER journal_projection_consistent AFTER INSERT ON ledger_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION finance_projection_check();

CREATE OR REPLACE FUNCTION finance_entry_insert_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM ledger_transactions WHERE id=NEW.transaction_id AND posting_xid=pg_current_xact_id()) THEN RAISE EXCEPTION 'cannot append to a posted journal' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER financial_entry_insert_guard BEFORE INSERT ON ledger_entries FOR EACH ROW EXECUTE FUNCTION finance_entry_insert_guard();

CREATE OR REPLACE FUNCTION finance_wallet_identity_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF NEW.merchant_id<>OLD.merchant_id OR NEW.wallet_id<>OLD.wallet_id OR NEW.currency<>OLD.currency THEN RAISE EXCEPTION 'immutable financial ownership' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='balance_holds' THEN
  IF NEW.id<>OLD.id OR NEW.original<>OLD.original OR NEW.expires_at<>OLD.expires_at OR NEW.captured<OLD.captured OR NEW.released<OLD.released OR (OLD.protected AND NOT NEW.protected) OR (OLD.unknown AND NOT NEW.unknown) THEN RAISE EXCEPTION 'invalid reservation projection mutation' USING ERRCODE='23514'; END IF;
 END IF; RETURN NEW;
END $$;
CREATE TRIGGER hold_identity BEFORE UPDATE ON balance_holds FOR EACH ROW EXECUTE FUNCTION finance_wallet_identity_guard();
CREATE TRIGGER balance_identity BEFORE UPDATE ON wallet_balances FOR EACH ROW EXECUTE FUNCTION finance_wallet_identity_guard();
CREATE TRIGGER hold_delete BEFORE DELETE OR TRUNCATE ON balance_holds FOR EACH STATEMENT EXECUTE FUNCTION finance_immutable();
CREATE TRIGGER balance_delete BEFORE DELETE OR TRUNCATE ON wallet_balances FOR EACH STATEMENT EXECUTE FUNCTION finance_immutable();
CREATE OR REPLACE FUNCTION finance_template_guard() RETURNS trigger LANGUAGE plpgsql SET search_path=pg_catalog,public,pg_temp AS $$ BEGIN
 IF NEW.code<>OLD.code OR NEW.version<>OLD.version+1 THEN RAISE EXCEPTION 'posting template change requires a new version' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER finance_template_version BEFORE UPDATE ON financial_templates FOR EACH ROW EXECUTE FUNCTION finance_template_guard();
