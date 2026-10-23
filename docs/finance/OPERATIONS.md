# Financial integrity and operations

## Transaction protocol

Merchant row serialization matches Phase 3 membership/lifecycle operations; then wallet projection, hold/original capture and business operation are locked/checked. Administrative mutation adds the existing security advisory lock before merchant, then user UUIDs sorted and auth-session UUIDs sorted before approval execution. This serializes with MFA/password/session changes. Different wallets within one merchant intentionally serialize initially; optimize only after measured contention.

Each SQL command atomically persists permanent financial_operations and financial_request_keys, journal/entries where monetary, reservation/event where applicable, balance and statement projections, and immutable outbox. PHP records an immutable audit in the same outer transaction. No network call occurs under a financial transaction. Only bounded Laravel database-concurrency retries (3) are allowed. HTTP timeouts/repeated commands use the original request and business keys; never infer rollback from a lost response. Different request keys with the same business key replay the original result; changed canonical payload is a conflict.

financial_request_keys scopes request keys to merchant and actor scope; financial_operations permanently scopes business keys to merchant and command kind. SHA256 request and payload digests are retained, not raw credentials. Correction requests use request_key in the JSON body (16–150 chars), with a separate permanent business_key. Repeated approve/revoke with a stale version returns409; repeated executed requests return the existing operation after current target authorization. Wallet provisioning dedupes by merchant/currency and creates zero balance only.

## Database boundary

UUID/composite merchant-currency FKs, positive minor units, currency catalogue, no-overdraft checks, immutable history/ownership and deferred per-journal balance/projection checks enforce structural integrity. Entry insertion is confined to the transaction that creates its journal using pg_current_xact_id(); a balanced append to an older journal is forbidden. Mutable reservation totals only increase captured/released; original ownership/amount/expiry cannot change. Runtime cannot directly DML monetary tables; cts_finance_runtime is a NOLOGIN database capability role with SELECT and EXECUTE on the constrained finance_command entry point. SQL functions use qualified objects/fixed search paths with temporary schemas last; PUBLIC cannot execute the monetary function.

Migrations require schema-owner/role-provisioning privileges. cts_finance_runtime is cluster-wide and is retained across disposable database tests; migration rollback drops database objects but does not delete a potentially shared role. Production must provision a separate non-owner application login and a restricted non-superuser function owner, grant only required module capabilities and test the full application privilege set before launch. Development Compose still uses its existing database owner; SET ROLE integration tests prove the monetary boundary, not a production DB deployment. DB owners/superusers can alter/drop triggers; off-host immutable retention and restricted operational access remain required.

No finance table is populated with a fabricated opening balance. Ordinary migrations are additive. Empty-finance rollback/reapply preserves Phase 3 identities and role IDs. Downgrade refuses posted history or outstanding financial access grants outside the strictly guarded disposable test DB. Forward-only corrective migrations are required after actual operational postings. Never disable triggers to “repair” money.

## Outbox and reconciliation

`php artisan finance:outbox` runs every minute. It claims committed rows with FOR UPDATE SKIP LOCKED and writes finance-monitor consumer receipts in the same transaction. Rollback leaves an event pending; retries dedupe by event/consumer. This is a local durable monitor consumer, not an external message broker or an implemented shipping/payment notification. Additional consumers must use durable receipts and at-least-once semantics; no exactly-once network delivery claim.

`php artisan finance:reconcile` runs daily. Each wallet is locked while journal liability totals and outstanding hold totals are compared to projections; immutable cutoff/result records are written. The admin endpoint performs the same operation under a finance.reconcile target grant and fresh MFA. Discrepancy freezes that wallet and creates an immutable case event. No balancing plug, journal rewrite, automatic projection rebuild or unfreeze endpoint is provided. Review discrepancy evidence and approve a recovery runbook before repair. Normal suspended merchants cannot self-access, but scoped platform review can inspect retained obligations.

There is no verified bank/PSP/carrier statement source, real settlement reconciliation, document upload or external unknown-outcome resolution. Synthetic provider event tests cover repeated, pending, confirmed, conflicting and late events, without claiming a sandbox integration.

## Recovery and monitoring gates

Before operational activation: approve ledger backup/PITR and restoration drill, retention, function-owner/runtime grants, reconciliation owner/escalation and alert thresholds, freeze/unfreeze authority, accounting periods and financial limits. Monitor projection mismatch, outstanding unknown holds/age, duplicate conflicts, posting rejections, pending outbox/receipt lag and lock contention. Production observability/alerts, performance SLOs and full disaster recovery are launch work; this phase is not deployed.
