# Development roadmap

Phases 0–11 are merged; Phase 12 platform administration is implemented on its review branch. Live money and shipping remain separately gated. Blueprint sprint labels are delivery estimates; the user's explicit Phase 0–17 sequence is canonical. Salla precedes Shopyzee as requested. No production readiness is implied by scaffolding.

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
* Completion report: Implemented on feat/phase-3-merchant-admin; see [PHASE_3_REPORT.md](PHASE_3_REPORT.md). Profiles, addresses/defaults, manual immutable verification, invitations, MFA and independently approved administration. Document uploads and assisted MFA recovery remain unavailable; shipping eligibility remains denied.

## Phase 4 — Wallet and financial ledger

* Scope: Wallet and financial ledger.
* Dependencies: Phase 2; finance policy.
* Implementation tasks: Double-entry posting, holds/capture/release/refunds, immutable DB enforcement, mock PSP, reconciliation, audited dual approval.
* Database changes: wallets/accounts/transactions/entries/holds/payment events/refunds.
* API changes: Wallet/history, internal money commands; mock funding only.
* Frontend changes: Balances/holds/history with currency; no fake payment completion.
* Automated tests: Concurrent holds, balance, immutable/balanced postings, duplicate callbacks, reversals.
* Acceptance criteria: No overspend or duplicate posting under retries/races; accountant-reviewed model.
* Completion report: Implemented on feat/phase-4-wallet-ledger; see [PHASE_4_REPORT.md](PHASE_4_REPORT.md). SAR-only activation, immutable ledger/reservations, idempotency, outbox, structural reconciliation and independently reviewed corrections. Real funding/settlement/withdrawals/shipping charges remain disabled; accountant acceptance remains outstanding.

## Phase 5 — Pricing engine

* Scope: Pricing engine.
* Dependencies: Phase 4; approved tariff/tax policy.
* Implementation tasks: Route/weight/service/merchant rules, fees/tax/discount/min margin, expiry and snapshots.
* Database changes: quotes/rules/snapshot lines.
* API changes: /rates/quote and admin rules.
* Frontend changes: Rate estimate/expired quote states/admin editor.
* Automated tests: Rule boundaries, currency exponent, expiry, historical snapshot invariance.
* Acceptance criteria: Deterministic approved sell price; no implicit FX.
* Completion report: [Phase 5 report](PHASE_5_REPORT.md); implementation on feature branch, no live rate/payment/shipment activation.

## Phase 6 — Aramex carrier integration

* Scope: Aramex carrier integration.
* Dependencies: Fresh schemas, sandbox access; Phase 5.
* Implementation tasks: Registry/capabilities, encrypted accounts, verified request/response DTO mapping, safe errors/timeouts.
* Database changes: carrier accounts/services/routes/status maps/integration logs.
* API changes: Internal carrier operations and enabled capability catalogue.
* Frontend changes: Admin config health; no credential export.
* Automated tests: HTTP fake contracts then explicit sandbox suite for each operation.
* Acceptance criteria: Only tested/account-enabled operations surfaced; no production calls.
* Completion report: [Phase 6 report](PHASE_6_REPORT.md); request/configuration/reliability foundations implemented. All external capabilities, sandbox testing and production blocked pending current response/auth/contract evidence.

## Phase 7 — Shipment management

* Scope: Shipment management.
* Dependencies: Phases 4–6.
* Implementation tasks: Draft/validate/reserve/outbox/submit/capture-or-release; unknown-result quarantine; labels/pickups.
* Database changes: shipments/packages/items/documents/attempts/pickups/outbox/idempotency.
* API changes: Shipment CRUD/create/status/label and pickup cancel.
* Frontend changes: Create wizard, lists/detail, secure labels, pickup scheduling.
* Automated tests: Crash boundaries, duplicate creates, ambiguity, tenant authorization, document access.
* Acceptance criteria: Carrier acceptance distinct from money capture; no blind create retries.
* Completion report: Implemented independently testable foundation; see [Phase 7 report](PHASE_7_REPORT.md). Live shipping, real charges and real labels remain evidence-gated and disabled.

## Phase 8 — Bulk shipment processing

* Scope: Private CSV/XLSX import, templates/mapping, validation, explicit duplicate review, informational pricing and approved shipment draft preparation only.
* Dependencies: Phase 5 Pricing and Phase 7 Shipping, merged through PR #7 QA remediation.
* Database: Additive bulk batch/file/row/mapping/validation/decision/price/approval/work/reference/command/event tables with merchant composite constraints and immutable history.
* APIs/UI: Versioned merchant-scoped import lifecycle, paginated inspection/progress/history, formula-safe error export and EN/AR responsive workflow screens.
* Security/recovery: Encrypted private sources, bounded structural parsers, fail-closed production scanner gate, per-row authorization/idempotency, leases and PostgreSQL recovery, cancellation before first committed draft.
* Acceptance: 1,000-row mixed/retry workloads, spreadsheet security, tenant/scope/revocation and actual PostgreSQL concurrency fences; all prior regression/build/audit checks. See [Phase 8 report](PHASE_8_REPORT.md) for executed outcomes.
* No live carrier/financial operations, merge or production deployment. Phase 9 remains separately authorized.

## Phase 9 — Tracking and webhooks (implemented foundations)

* Verified base: PR #8 merge `3b301898f5fb197488a442814716bd274af695be`.
* Reuse Phase 6 immutable carrier events/deliveries and the sole projection, bound to decisive Phase 7 submission evidence; imported drafts remain ordinary drafts.
* Provider-neutral normalization, conservative transition graph, encrypted durable inbox/work, replay/event/command idempotency, bounded retries, quarantine, lease recovery and incremental backfill.
* Merchant overview/timeline/search/exceptions and recent-MFA, independently granted administrator diagnostics/review in Arabic and English.
* Disabled notification outbox readiness with explicit consent and event/recipient uniqueness. No sender, real carrier polling, provider webhook activation or financial resolution.
* New OpenAPI contracts and additive migration/security/concurrency coverage. See [Phase 9 report](PHASE_9_REPORT.md) and [architecture](architecture/PHASE_9_TRACKING.md).
* Aramex V2 response/event and webhook authenticity/replay/ACK contracts and authorized sandbox access remain missing. Adapter flags cannot bypass Phase 6 evidence gates.
* Historical Phase 9 boundary: its delivery did not authorize a merge, deployment or Phase 10. Phase 10 preparation was subsequently authorized separately.

## Phase 10 — International shipping and customs (implemented preparation)

* Reuses Shipping revisions, Pricing estimates, Carrier evidence gates, private storage/scanning and Bulk imports. No second shipment state machine or ledger.
* Typed exporter/importer and bilingual item declarations; exact minor units, ISO countries, package allocations, merchant HS codes, Incoterms and informational category flags.
* Versioned evidence-backed country proposals require independent MFA review; external verification/enablement remain false.
* Immutable declarations/readiness/history, merchant profiles and encrypted durable invoice/packing-list work. Native Unicode PDF drafts, private authenticated delivery and quarantine remain subject to storage/scanning/privacy gates; production rendering/download disabled.
* Nine bilingual merchant/admin screens, 16 API paths (18 operations), international CSV/XLSX variants and additive PostgreSQL constraints/rollback/schema-integrity tests.
* Complete regression and security results: [Phase 10 report](PHASE_10_REPORT.md); [architecture and external approvals](architecture/PHASE_10_CUSTOMS.md).
* No live carrier, financial, authority filing or legal-clearance capability enabled. Phase 11 requires separate authorization.

## Phase 11 — Merchant dashboard and experience (implemented)

* Real permission-filtered operational read models compose Shipping, Tracking, Bulk, WalletBilling, Pricing and Customs, with UTC today/7/30/90/custom filters and safe activity keyset pagination.
* No fabricated revenue, accepted carrier facts or tracking; no cross-currency totals. Empty/unavailable and local-only preparation states remain explicit.
* Existing permissions and current session/token/member checks apply to each source; no shared response cache or platform tenant bypass.
* Unified EN/AR dashboard, daily activity/state charts, current per-currency balances, bulk progress, recent activity, quick actions and permission-aware searchable mobile navigation; shared page headers across operational modules.
* Six additive PostgreSQL read indexes only; no business tables, ledger or tracking state machine.
* See [Phase 11 architecture](architecture/PHASE_11_DASHBOARD.md) and [completion report](PHASE_11_REPORT.md) for verification and external limits.
* No merge, deployment, live operations, customer notifications or Phase 12 work.

## Phase 12 — Super admin dashboard (implemented)

* Verified base: Phase 11 PR #11 merge `18ae1e3ded0ba17166aa3f3ff92c8a9fe7943872`.
* Operations composes permission-separated source-backed counts/trends, safe health/settings, approval workload, redacted audit and current-fact internal alerts over existing module-owned records.
* Distinct bilingual responsive platform sidebar/shell,16 platform screens, filtered merchant directory and existing controlled domain workflows; private summaries retain independently approved exact session-bound grants and current authority/MFA fences.
* Finance is currency-separated count-only globally, exact-granted balances privately; no arbitrary edits, new ledger, funding, carrier calls or notifications.
* One append-only alert receipt table,14 bounded permissions, schema-integrity/down/up checks; no speculative read indexes or new business records.
* Real PostgreSQL/Redis security races,2,000-merchant measurement, full Phase 0–11 regressions and responsive EN/AR browser evidence. See [Phase 12 report](PHASE_12_REPORT.md) and [architecture](architecture/PHASE_12_PLATFORM.md).
* No automatic merge, deployment, live activation or Phase 13. Accounting/global monetary disclosure and external carrier/customs/production gates remain open decisions.

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

Review the Phase 12 platform dashboard feature branch and completion/security report. External carrier, financial, notification and customs authority gates remain closed. No automatic merge, deployment or Phase 13 work.
