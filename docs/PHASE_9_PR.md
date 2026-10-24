# Phase 9: evidence-gated tracking and webhook foundations

Merchants can inspect tenant-scoped tracking overviews, timelines and exceptions in Arabic and English; administrators can inspect redacted delivery health and append review-only annotations under recent MFA and independently approved merchant access grants. Unsubmitted, unknown, stale and externally disabled states remain explicit. Synthetic carrier events never appear as live merchant data.

Reuse the existing carrier event store/projection and committed Phase 7 submission evidence. Add encrypted durable webhook/poll work, verified-authenticator boundaries, independent replay/event/command idempotency, conservative terminal-state projection, PostgreSQL lease recovery, account spacing/circuit readiness and a disabled consent-bound notification outbox. Tracking binding runs asynchronously so tracking outages cannot roll back accepted shipment evidence. No Shipping/ledger state mutation, invented Aramex protocol, live polling/ingress, customer messaging, deployment or Phase 10.

Validation: 286 backend tests / 2,972 assertions; 74 frontend tests; 19/19 Playwright tests twice consecutively; 243 Pint files, ESLint, TypeScript, production build, migration preservation, four installer checks and dependency audits passed (zero advisories).

Validation results are recorded in docs/PHASE_9_REPORT.md, alongside the retained integration gates, architectural limits and full file manifest. Backend regression/security/concurrency tests use real PostgreSQL and Redis; browser tests use the local full stack and synthetic data. No tests or assertions are disabled.

External activation still requires verified current Aramex responses/status mappings and webhook authenticity/replay/ACK contracts, authorized sandbox scope, privacy/retention/operations policy and approved notification controls. Adapter flags cannot bypass the existing Phase 6 evidence gates.
