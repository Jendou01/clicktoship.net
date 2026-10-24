## Change

Add merchant-scoped international/customs preparation on top of existing immutable Shipping revisions: bilingual structured item declarations, profiles, country evidence proposals with independent review, readiness, international bulk templates and private versioned draft invoice/packing-list PDFs. Reuse Pricing, Carrier evidence gates, private storage/scanning and the existing ledger boundaries; no live shipping, payments, customs filing or production activation.

## Security and constraints

16 additive PostgreSQL tables enforce tenant/source composite relationships, immutable history, exact minor-unit arithmetic, versioning, independent review and durable document work. Current RBAC/token scopes, recent platform MFA and independent target grants are rechecked. Default scanner quarantines documents; production generation/download remain disabled. TCPDF supplies native Arabic/Unicode PDF rendering with typed text only and explicit draft disclaimers. Country review never confers legal clearance or enables a route.

## Validation

312 backend tests / 3,248 assertions using real PostgreSQL/Redis; 81 frontend tests; full 20-case Playwright suite passed twice consecutively. Concurrency, idempotency, recovery, revocation, IDOR, schema drift and rollback/reapply are covered. Pint (263 files), ESLint, TypeScript, production build (139 pages), installer tests, strict Composer/OpenAPI validation and dependency audits pass. Final service verification is recorded in the completion evidence.

See docs/PHASE_10_REPORT.md and docs/architecture/PHASE_10_CUSTOMS.md for the API/screen inventory, evidence gates and outstanding carrier/legal/accounting/storage/scanning approvals. Do not merge or deploy automatically; Phase 11 is outside scope.
