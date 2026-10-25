# Phase 12 — Platform operational composition

## Ownership and authority

Operations adds count-only read models over existing Identity, Merchants, Shipping, Bulk, Tracking, Carriers, Pricing, WalletBilling and Customs tables. The existing mutation/review screens and owning services remain authoritative. There is no second merchant database, ledger, carrier gateway, projection, approval engine or settings store. Scope and permission policy were recorded before implementation in [PHASE_12_SCOPE.md](PHASE_12_SCOPE.md). Base: Phase 11 PR #11 merge `18ae1e3ded0ba17166aa3f3ff92c8a9fe7943872`.

All nine new API paths require a verified active user, current browser session, current platform permission and recent MFA (15 minutes). Sanctum personal access tokens, including wildcard administrator tokens, cannot access platform administration. This preserves the existing stricter platform boundary. Every source permission is checked before its tables are queried; aggregate permission never becomes a merchant-access capability.

ReadAuthority takes the existing platform security advisory lock (3042026), locks the current user/session, revalidates session/auth version, MFA and current roles, and executes the read in the same PostgreSQL transaction. Ordinary readers use the shared advisory key; private target readers use its exclusive form from the outset, avoiding lock upgrades when calling existing services. Independent approver user/session rows are locked before grant revalidation. Security changes that commit first fence the read; changes after a permitted read serialize behind it. No credential/cache snapshot authorizes later requests. Responses are private/no-store with Cookie/Authorization variation; failed refreshes clear displayed data. Private selection changes fence late browser responses.

## KPI dictionary

Period filters use the existing Dashboard Period service: today, 7/30/90 days and explicit inclusive calendar dates, at most 366 days, UTC start inclusive/end exclusive, no future end. Stocks and current queues are deliberately independent of period filters. Zero-filled daily trends are bounded by that window.

| Section | Authoritative source and definition | Time semantics |
|---|---|---|
| Merchants | merchants total/account status/business verification; merchant_verification_decisions | Current stock; registration/decision trends by created_at |
| Users | users and platform_role_assignments, counts only | Current stock; no names, emails, contacts or MFA secrets |
| Shipping | shipments states, exception_code/financial_state/document_state; shipment_submissions; pickups | Created cohort/state distribution and submission-event trend in period; exceptions current |
| Verified acceptance | shipments joined to its exact merchant-bound shipping_observation, accepted state/outcome and verified provenance | Observation time in period; synthetic evidence excluded; currently zero because verified response evidence remains gated |
| Bulk | bulk_batches and incomplete bulk_work | Batch creation cohort/trend; failed batches and expired-lease/old-unleased recovery candidates current, not a claim of confirmed worker crash |
| Tracking | tracking_work/cases/reviews/event_details; binding + exact carrier projection + event detail | Current work/cases; verified status by event occurrence in period, verified staleness >24 hours; verified binding/detail/validation required, unknown excluded |
| Carriers | carrier_accounts, carrier_operations, CarrierCapabilityRegistry | Current account/environment/configuration counts; recorded intent states in period, not proof of carrier execution |
| Pricing | global pricing_definitions kind/enabled/version/active_version | Current global configuration counts; no private costs, margins, tariffs or customer quotes |
| Finance | wallets + currencies + wallet_balances; latest reconciliation run per wallet; financial_approvals; shipment financial_state | Counts by currency/exponent, wallets with reservations/frozen; latest mismatch only; unrevoked unexecuted unexpired approvals; current settlement exceptions |
| Customs | country roots/current versions/reviews; document work/results/revocations; latest readiness per current shipment revision | Current pending country review, unrevoked quarantined documents and locally incomplete current revisions; never legal clearance |

A pending business verification is not a pending account. Account status remains active/suspended/closed. Configured carrier accounts are not certified integrations. Accepted synthetic fixtures and synthetic tracking never populate verified activity. No revenue/profit/cost estimate, cross-currency sum or live volume is fabricated.

## Permissions and private merchant access

14 new permissions: platform.overview; platform.metrics.{merchants,users,shipping,bulk,tracking,carriers,pricing,finance,customs}; platform.health.view; platform.settings.view; platform.alerts.{read,acknowledge}. All are aggregate/read-state permissions, excluded from access-grant capabilities.

| Built-in platform role | New access |
|---|---|
| Super Admin | All bounded Phase 12 permissions |
| Operations | All except users, finance and settings |
| Finance | Overview, merchant and finance counts, alerts/read-state |
| Support | Overview, merchant/shipping/bulk/tracking counts, alerts/read-state |

Existing narrower workflows retain their own permissions. The merchant directory retains authorized limited metadata, with literal search (max 100 characters), status/verification filters, fixed 25 pagination and bounded page number. Search wildcard characters are escaped rather than expanded. Users/roles, verification, suspension/reactivation, access requests, financial corrections, pricing publication, carrier configuration and country review continue through the original services and controls.

The new private merchant workspace uses an exact target path, selected section and approved current-session grant. It reuses Administration.authorizeTarget, FinancialAdministration.authorize or Catalog.authorize. Grant capability, current issuer authority/MFA/session, merchant state, expiry/revocation, own-membership finance restrictions and audit semantics remain enforced. Its bounded response is merchant/profile metadata plus membership role/status counts, shipping summary, tier-assignment metadata, or exact per-currency WalletRead balances. No arbitrary tenant switching or impersonation. Listing one's own grant references does not authorize a read: the target service revalidates all authority. Grants are never put in URLs by UI language switching.

Global monetary disclosure has no approved policy. Finance overview therefore provides counts only. Posted/reserved/available amounts are shown only in the exact-granted financial view, using existing integer strings and explicit currency exponent. No funding, capture, refund, balance edit, accounting policy approval or external charge is enabled. See OPEN_QUESTIONS.md for the stopped global amount capability.

## Alerts, activity and approvals

Alerts are derived from persisted current facts, not copied business events. Each source is permission-filtered. Per-source queries fetch at most 26 and merge into a recent 25 sample with a truncation flag; this is not an unbounded complete queue. Kinds include pending verification/pricing/country review, inactive carrier configuration, unknown shipment, tracking work failure, possible bulk recovery, current unrevoked customs quarantine and latest reconciliation mismatch. Workflow links are generic, authorized routes; private record IDs and payloads are excluded.

Stable HMAC-SHA256 fingerprints bind kind, private source identity/version/time. The key is opaque, not an access credential. APP_KEY rotation changes fingerprints/read-state identity; preserve the key during normal environment restoration. platform_alert_receipts has one immutable row per actor/key and the acknowledging auth-session FK. POST/read requires CSRF, source permission and a still-current sampled fact; replay is idempotent. A read receipt neither resolves the source nor sends email/SMS/WhatsApp. Reading an alert writes only its receipt and existing immutable audit event. Source resolution/version change removes or changes its fingerprint.

Approval summaries expose allowlisted types/counts and existing routes. Access workload is global only for authorized checkers, otherwise own current session. Financial request counts here are actor/session-specific; the separate finance metric is a restricted global count. Country proposals are pending only for the current root version, and deliberately disabled carrier configurations are not automatically pending approval. Global security activity returns only audit ID/action/UTC time for merchant_id NULL, exact action filter,25-keyset pages; private merchant audit still needs its existing grant.

## Health and settings

Existing readiness and SchemaIntegrity supply dependency/schema observations. Horizon reads its supported master repository and filters current application environment; absence, pause or recent running heartbeat is explicit. Queue counts use a fixed 8-name list and failed_jobs exposes count only. No host, PID, exception body, queue payload, credentials or configurable arbitrary infrastructure probe. Scheduler has no independent persisted heartbeat: status is explicitly not_independently_observable, not a fabricated healthy state. Existing durable Shipping/Bulk/Tracking/Customs recovery remains in its module. Local health is not production certification. The UI links to the monitored sources; no restart/replay button.

Settings is a typed read-only allowlist: UTC, MFA/grant windows, currency precision/enabled catalogue, import limits and disabled activation gates. No arbitrary key/value editor or gate override. All changes remain in existing versioned, authorized, audited services.

## Schema, bounds and performance

Only new table: platform_alert_receipts. PK(actor_id,alert_key), 64-lowercase-hex check, user FK, composite(actor_id,session_id) FK to auth_sessions(user_id,id), UTC timestamp; trigger rejects UPDATE/DELETE/TRUNCATE. SchemaIntegrity independently checks the real table and enabled trigger. Migration adds permissions to existing built-in roles and refuses normal downgrade with retained receipts. Fresh installs use CREATE OR REPLACE for the immutable function; disposable testing rollback/reapply verifies earlier schemas remain intact.

No extra business/read indexes were justified by measured plans; existing Phase 11 and module indexes are reused. Source response size is bounded by fixed enum groups, max200 currencies, max366 trend rows,25 activity/alert pages,100 grant references,20 private tier assignments. Server statement timeout is5 seconds and query failures produce sanitized503. No shared cache or N+1 per-merchant loop. Representative evidence and limitations are in PHASE_12_REPORT.md; 2,000 synthetic merchants are not a production capacity claim.

## Experience and remaining gates

16 bilingual platform screens, permission-aware searchable sidebar, breadcrumbs, responsive disclosure, no false active actions, localized numbers/statuses/UTC dates and direction-isolated technical IDs. Existing admin workflows use the same shell and preserve their own domain semantics. Charts have named summaries and expandable data tables; no chart dependency added. Language switching preserves safe period/target context and excludes tokens, grants, reasons and private payloads.

Carrier sandbox response/auth/webhook evidence, accountant/regulatory decisions, secure document storage/scanning/retention and production provisioning remain external gates. No real carrier request, payment, funding, financial execution, customer notification, clearance, merge, external deployment, tunnel or Phase 13 is authorized by this implementation.
