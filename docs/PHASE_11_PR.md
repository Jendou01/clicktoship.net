## Change

Replace the static merchant dashboard with real tenant-authorized operational reporting across existing Shipping, Tracking, WalletBilling, Bulk, Pricing and Customs. Add explicit UTC periods, exact per-currency current balances, daily creation/status charts, safe activity keysets, current-revision readiness and honest empty/disabled states. Unify bilingual navigation, quick actions, shared page headers and responsive UI primitives without new dependencies or duplicated business logic.

## Security and data

Two read endpoints require current membership, merchant.view and each source's existing permission/token scope. Reauthorization under the existing merchant/user lock order prevents winning revocations from leaking reports; no shared dashboard cache or automatic platform bypass. No PII snapshots, carrier costs or margins. Six additive read indexes only; no business tables, ledger or tracking state machine. All live carrier/financial/customs/notification gates remain closed.

## Validation

328 backend tests / 3,421 assertions, 91 frontend tests, two successful full 21-case Playwright runs, actual independent PostgreSQL-process security races, real-stack Playwright, EN/AR desktop/mobile inspection, migration rollback/schema drift, query-count growth checks, Pint, ESLint, TypeScript, production build, strict OpenAPI/Composer validation and dependency audits. Production build generated 139 pages; the normal 19-migration development runtime is healthy. Four EN/AR desktop/mobile views have no runtime errors or horizontal overflow. Final results are recorded in docs/PHASE_11_REPORT.md and delivery evidence.

See docs/architecture/PHASE_11_DASHBOARD.md for precise source/date/provenance semantics and remaining launch gates. Do not merge or deploy automatically. Phase 12 is outside scope.
