-- Run as separate migration owner after every reviewed forward migration.
-- cts_runtime must already exist; its password is provisioned out of band.
BEGIN;
DO $$ DECLARE expected text[] := ARRAY['audit_logs','auth_sessions','balance_hold_events','balance_holds','bulk_approvals','bulk_batches','bulk_commands','bulk_decisions','bulk_drafts','bulk_events','bulk_files','bulk_mappings','bulk_prices','bulk_rows','bulk_validations','bulk_work','cache','cache_locks','carrier_account_versions','carrier_accounts','carrier_attempts','carrier_config_events','carrier_health_records','carrier_operations','carrier_tracking_events','carrier_tracking_projections','carrier_webhook_deliveries','commerce_authorizations','commerce_commands','commerce_connections','commerce_credentials','commerce_draft_links','commerce_events','commerce_orders','commerce_pages','commerce_receipts','commerce_results','commerce_runs','commerce_snapshots','customs_commands','customs_country_rules','customs_declarations','customs_document_counters','customs_document_results','customs_document_revocations','customs_document_work','customs_documents','customs_events','customs_items','customs_profiles','customs_readiness','customs_reviews','customs_rule_reviews','customs_rule_versions','customs_versions','failed_jobs','financial_approval_events','financial_approvals','financial_case_events','financial_currencies','financial_hold_resolutions','financial_operations','financial_outbox','financial_outbox_deliveries','financial_reconciliation_runs','financial_refunds','financial_request_keys','financial_templates','job_batches','jobs','ledger_accounts','ledger_entries','ledger_transactions','merchant_addresses','merchant_invitations','merchant_profiles','merchant_role_assignments','merchant_settings','merchant_users','merchant_verification_decisions','merchant_verification_submissions','merchants','mfa_recovery_codes','migrations','password_reset_tokens','payment_events','payment_intents','permissions','personal_access_tokens','pickup_commands','pickup_events','pickup_shipments','pickups','platform_access_grants','platform_alert_receipts','platform_role_assignments','pricing_definitions','pricing_events','pricing_quote_acceptances','pricing_quote_requests','pricing_versions','report_export_events','report_exports','role_permissions','roles','sessions','shipment_documents','shipment_events','shipment_revisions','shipment_submissions','shipment_validations','shipments','shipping_deliveries','shipping_observations','shipping_outbox','shipping_quotes','shipping_reconciliation_approvals','shipping_reconciliation_requests','shipping_request_keys','tracking_account_health','tracking_attempts','tracking_bindings','tracking_cases','tracking_commands','tracking_endpoints','tracking_event_details','tracking_notifications','tracking_preferences','tracking_receipts','tracking_reviews','tracking_scan_cursors','tracking_work','user_mfa_credentials','users','wallet_balances','wallet_transactions','wallets']; actual text[]; BEGIN
 SELECT array_agg(tablename::text ORDER BY tablename) INTO actual FROM pg_tables WHERE schemaname='public';
 IF actual IS DISTINCT FROM expected THEN RAISE EXCEPTION 'Review runtime grants for changed schema before release'; END IF;
END $$;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
REVOKE ALL ON ALL TABLES IN SCHEMA public FROM cts_runtime;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM cts_runtime;
GRANT USAGE ON SCHEMA public TO cts_runtime;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO cts_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO cts_runtime;
DO $$ DECLARE t record; BEGIN
 FOR t IN SELECT tablename FROM pg_tables WHERE schemaname='public'
 AND tablename NOT IN ('migrations','roles','permissions','role_permissions',
 'financial_currencies','ledger_accounts','wallets','wallet_balances',
 'financial_operations','financial_request_keys','ledger_transactions','ledger_entries',
 'balance_holds','balance_hold_events','wallet_transactions','financial_refunds','financial_templates')
 LOOP EXECUTE format('GRANT INSERT,UPDATE,DELETE ON TABLE public.%I TO cts_runtime',t.tablename); END LOOP;
END $$;
-- Zero-balance provisioning only. Amount/reserved/version/frozen columns remain unwritable.
GRANT INSERT(id,merchant_id,currency,purpose,class) ON ledger_accounts TO cts_runtime;
GRANT INSERT(id,merchant_id,currency,account_id) ON wallets TO cts_runtime;
GRANT INSERT(wallet_id,merchant_id,currency) ON wallet_balances TO cts_runtime;
-- Reconciliation needs a row lock and may freeze; monetary/version columns remain denied.
GRANT UPDATE(frozen) ON wallet_balances TO cts_runtime;
-- Portable dumps omit ACLs: always restore the definer function's non-public boundary explicitly.
REVOKE ALL ON FUNCTION finance_command(uuid,uuid,text,bigint,uuid,text,text,text,text,timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION finance_command(uuid,uuid,text,bigint,uuid,text,text,text,text,timestamptz) TO cts_finance_runtime;
GRANT cts_finance_runtime TO cts_runtime;
COMMIT;
