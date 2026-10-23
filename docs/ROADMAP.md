# Development roadmap

Phases 0–2 are implemented in bounded changes. Money and shipping remain separate reviewable phases. Blueprint sprint labels are delivery estimates; the user's explicit Phase 0–17 sequence is canonical. Salla precedes Shopyzee as requested. No production readiness is implied by scaffolding.

Each phase becomes bounded issues/PRs with testable acceptance criteria, feature flags where needed and an updated completion report. Required external documents/contract decisions block the corresponding capability, not independent work. Migration entries below are intended changes, not already installed domain tables.

## Phase 0 — Repository audit and architecture

* Scope: Repository audit and architecture.
* Dependencies: None.
* Implementation tasks: Audit all sources, evidence matrix, glossary, module/API/financial boundaries, decisions and open questions.
* Database changes: Target ERD and constraints; no domain migrations.
* API changes: Conventions and health contract draft.
* Frontend changes: Bilingual/accessibility plan.
* Automated tests: Source/contract review; documentation consistency.
* Acceptance criteria: All requested design docs present; unknown APIs explicit.
* Completion report: Complete baseline; runtime evidence in FOUNDATION_REPORT.

## Phase 1 — Project foundation

* Scope: Project foundation.
* Dependencies: Phase 0.
* Implementation tasks: Scaffold Laravel 12 + Sanctum/Horizon dependencies, Next.js, Compose, health, CI and tooling.
* Database changes: UUID users/tokens; framework migrations; PostgreSQL test DB.
* API changes: Live and dependency readiness only.
* Frontend changes: English/Arabic shell, RTL/LTR, disabled business features.
* Automated tests: PHPUnit real dependencies, Vitest, lint/types/build, Playwright, HTTP smoke.
* Acceptance criteria: Repeatable setup; DB/Redis ready; no exposed Horizon/business operations.
* Completion report: Initial foundation implemented; see execution report for measured checks.

## Phase 2 — Authentication and multi-tenancy

* Scope: Authentication and multi-tenancy.
* Dependencies: Foundation.
* Implementation tasks: Registration/login/reset/verification/session lifecycle, token scopes, memberships, suspension, RBAC, admin MFA enrollment boundary.
* Database changes: merchants, merchant_users, roles, permissions, scoped composite FKs.
* API changes: /auth and current membership; token revoke/list.
* Frontend changes: Auth/onboarding/team context; CSRF-safe session handling.
* Automated tests: Cross-tenant/IDOR, token abuse, CSRF, suspension, jobs context reset.
* Acceptance criteria: Every tenant access checked server-side; no privilege escalation.
* Completion report: Implemented on the Phase 2 feature branch; see [PHASE_2_REPORT.md](PHASE_2_REPORT.md). Follow-up: root redirects preserve the public origin behind Nginx; the existing browser regression checks the redirect header and exact destination. Authentication, six merchant/four platform role definitions, isolation, onboarding and security tests are complete. Platform administrative workflows and actual MFA enrollment/challenge remain gated and unexposed for later administrative scope.

## Phase 3 — Merchant and admin management

* Scope: Merchant and admin management.
* Dependencies: Phase 2.
* Implementation tasks: Profiles, verification, addresses, users, permissions, admin audit.
* Database changes: addresses/settings/verification/audit.
* API changes: Merchant and scoped admin CRUD.
* Frontend changes: Profile/address/team/admin screens.
* Automated tests: Role matrix, mass assignment, cross-merchant FKs and audit.
* Acceptance criteria: Merchant lifecycle and audited restricted admin controls.
* Completion report: Planned.

## Phase 4 — Wallet and financial ledger

* Scope: Wallet and financial ledger.
* Dependencies: Phase 2; finance policy.
* Implementation tasks: Double-entry posting, holds/capture/release/refunds, immutable DB enforcement, mock PSP, reconciliation, audited dual approval.
* Database changes: wallets/accounts/transactions/entries/holds/payment events/refunds.
* API changes: Wallet/history, internal money commands; mock funding only.
* Frontend changes: Balances/holds/history with currency; no fake payment completion.
* Automated tests: Concurrent holds, balance, immutable/balanced postings, duplicate callbacks, reversals.
* Acceptance criteria: No overspend or duplicate posting under retries/races; accountant-reviewed model.
* Completion report: Planned.

## Phase 5 — Pricing engine

* Scope: Pricing engine.
* Dependencies: Phase 4; approved tariff/tax policy.
* Implementation tasks: Route/weight/service/merchant rules, fees/tax/discount/min margin, expiry and snapshots.
* Database changes: quotes/rules/snapshot lines.
* API changes: /rates/quote and admin rules.
* Frontend changes: Rate estimate/expired quote states/admin editor.
* Automated tests: Rule boundaries, currency exponent, expiry, historical snapshot invariance.
* Acceptance criteria: Deterministic approved sell price; no implicit FX.
* Completion report: Planned.

## Phase 6 — Aramex carrier integration

* Scope: Aramex carrier integration.
* Dependencies: Fresh schemas, sandbox access; Phase 5.
* Implementation tasks: Registry/capabilities, encrypted accounts, verified request/response DTO mapping, safe errors/timeouts.
* Database changes: carrier accounts/services/routes/status maps/integration logs.
* API changes: Internal carrier operations and enabled capability catalogue.
* Frontend changes: Admin config health; no credential export.
* Automated tests: HTTP fake contracts then explicit sandbox suite for each operation.
* Acceptance criteria: Only tested/account-enabled operations surfaced; no production calls.
* Completion report: Planned; external docs/access gaps recorded.

## Phase 7 — Shipment management

* Scope: Shipment management.
* Dependencies: Phases 4–6.
* Implementation tasks: Draft/validate/reserve/outbox/submit/capture-or-release; unknown-result quarantine; labels/pickups.
* Database changes: shipments/packages/items/documents/attempts/pickups/outbox/idempotency.
* API changes: Shipment CRUD/create/status/label and pickup cancel.
* Frontend changes: Create wizard, lists/detail, secure labels, pickup scheduling.
* Automated tests: Crash boundaries, duplicate creates, ambiguity, tenant authorization, document access.
* Acceptance criteria: Carrier acceptance distinct from money capture; no blind create retries.
* Completion report: Planned.

## Phase 8 — Bulk shipment processing

* Scope: Bulk shipment processing.
* Dependencies: Phase 7; private storage.
* Implementation tasks: CSV/Excel templates, bounded upload/parse, preview, dedupe, queued execution, progress, partial retry/labels.
* Database changes: bulk_imports/rows.
* API changes: /shipments/bulk, bulk jobs/rows/retry.
* Frontend changes: Upload/map/preview/errors/progress/history.
* Automated tests: 1k rows, parser abuse, formula-safe export, retries with no duplicate AWB.
* Acceptance criteria: Async bounded processing and row-level idempotent partial success.
* Completion report: Planned.

## Phase 9 — Tracking and webhooks

* Scope: Tracking and webhooks.
* Dependencies: Phase 7; actual webhook contract.
* Implementation tasks: Verified durable inbox, dedupe/replay, raw status map, event projection, poll reconciliation, notifications.
* Database changes: events/inbox/delivery/subscription tables.
* API changes: Tracking timelines + authenticated carrier ingress + subscriptions.
* Frontend changes: Timeline/exception/replay/admin views.
* Automated tests: Invalid auth, duplicates, out-of-order, unknown code, reconciliation outage.
* Acceptance criteria: Append-only history; stale events cannot silently regress projection.
* Completion report: Planned; webhook specification missing.

## Phase 10 — International shipping

* Scope: International shipping.
* Dependencies: Phases 5–9; lane/customs approval.
* Implementation tasks: Feature-gated countries/routes/customs/commodities/invoices/multi-package/currency.
* Database changes: customs/items/route constraints.
* API changes: Country/city/validation only with verified capabilities.
* Frontend changes: International fields, restrictions and invoice document flow.
* Automated tests: Blocked lane before hold, truthful origin, customs validation, currency isolation.
* Acceptance criteria: Enabled lane/service/account combinations only.
* Completion report: Planned.

## Phase 11 — Merchant dashboard

* Scope: Merchant dashboard.
* Dependencies: Phases 2–10 APIs.
* Implementation tasks: Overview, shipment/bulk/label/pickup/wallet/invoice/rate/address/team/integration views.
* Database changes: Read models/indexes only when justified.
* API changes: Allowlisted filtering/sort/cursor pagination.
* Frontend changes: Complete responsive Arabic/English workflows and error/empty/loading states.
* Automated tests: Components, keyboard/accessibility, RTL and complete mocked/sandbox E2E.
* Acceptance criteria: Merchant quote-to-track flow works without exposing carrier cost/secret.
* Completion report: Planned.

## Phase 12 — Super admin dashboard

* Scope: Super admin dashboard.
* Dependencies: Phases 3–10.
* Implementation tasks: Merchant/carrier/pricing/ledger/COD/reconciliation/roles/audit/queues/notifications operations.
* Database changes: Audited settings/approval/report models.
* API changes: Separately authorized /admin resources.
* Frontend changes: Platform overview and all scoped operational screens.
* Automated tests: Permission/MFA matrix, dual approval, masked PII, safe replay.
* Acceptance criteria: Operators resolve cases with auditable least privilege; no arbitrary ledger edits.
* Completion report: Planned.

## Phase 13 — Salla integration

* Scope: Salla integration.
* Dependencies: Shipping stable; official app access.
* Implementation tasks: Official authorization/token rotation/order import/fulfillment/tracking/health/retry.
* Database changes: store integrations/orders/sync jobs.
* API changes: Salla install/callback/sync hooks per official contract.
* Frontend changes: Install/map/health/revoke screens.
* Automated tests: Provider contract, revocation, duplicate import and update-loop protection.
* Acceptance criteria: Official authorized integration; no invented API calls.
* Completion report: Planned; partner documentation/access missing.

## Phase 14 — Shopyzee integration

* Scope: Shopyzee integration.
* Dependencies: Shipping stable; official extension docs.
* Implementation tasks: Dedicated connector/plugin, scoped auth, order shipment and fulfillment sync.
* Database changes: Reuse connector tables; provider mappings.
* API changes: Documented provider hooks only.
* Frontend changes: Install/map/health/retry screens.
* Automated tests: Connector contract and duplicate/revoked token/retry tests.
* Acceptance criteria: Verified extension mechanisms and isolated merchant credentials.
* Completion report: Planned; official API/hook documentation missing.

## Phase 15 — Reporting and analytics

* Scope: Reporting and analytics.
* Dependencies: Financial/shipping/commerce event models.
* Implementation tasks: Merchant/platform reports, shipment exceptions, margin vs actual billing, COD settlement and safe exports.
* Database changes: Reporting projections and indexes.
* API changes: Scoped reports/export jobs.
* Frontend changes: Date/currency/merchant filters, transparent financial totals.
* Automated tests: Tenant/report totals vs ledger, reconciliation and export injection.
* Acceptance criteria: Reports reconcile without mixing currencies or changing history.
* Completion report: Planned.

## Phase 16 — Security, performance and QA

* Scope: Security, performance and QA.
* Dependencies: All relevant modules.
* Implementation tasks: Threat-model review, scans, concurrency/load/soak, retention, accessibility, monitoring and recovery drills.
* Database changes: Indexes/retention policies only after measurements.
* API changes: Rate limit/abuse/error review.
* Frontend changes: RTL/accessibility/UAT fixes.
* Automated tests: 1k-row imports, webhook bursts, carrier outage, restore, finance invariants.
* Acceptance criteria: No critical unresolved defects; measured load/recovery and signoff.
* Completion report: Planned.

## Phase 17 — Production deployment preparation

* Scope: Production deployment preparation.
* Dependencies: Contract/legal/security/finance approval; Phase 16.
* Implementation tasks: Staging runbooks, TLS/secret management, separate runtime DB role, backups/PITR, metrics/alerts, rollback, pilot plan.
* Database changes: Migration/backfill and backup/restore rehearsal.
* API changes: Production origin/cookie/proxy/ingress configuration.
* Frontend changes: Environment validation and maintenance states.
* Automated tests: Staging smoke, recovery/rollback/UAT; no automated production shipment creates.
* Acceptance criteria: Approved controlled pilot only; publication/deployment requires explicit action.
* Completion report: Planned; not deployed.

## Immediate next increment
Review and merge the Phase 2 feature branch through normal review. Phase 3 remains planned; no automatic implementation or production deployment. Resolve legal/carrier/PSP prerequisites independently.
