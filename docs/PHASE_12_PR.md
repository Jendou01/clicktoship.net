## Problem and behavior

Platform administrators previously navigated separate domain screens without a permission-filtered operational overview. This change adds a bilingual platform dashboard with source-backed counts/trends, approval workload, redacted audit, safe service health/settings and persisted alert read-state, plus a common responsive administration sidebar and bounded merchant-directory search/filtering.

Aggregate visibility is distinct from private merchant access. Current session/role/recent MFA are rechecked under PostgreSQL locks; private sections reuse existing independently approved exact session-bound target grants. Finance overview is count-only per currency; exact-granted balances remain in WalletBilling. Synthetic observations never become verified carrier activity. No carrier/payment/financial/notification activation or arbitrary settings controls.

## Schema and API

One additive immutable actor/session alert receipt table and 14 narrowly scoped permissions; no new ledger, business state machine or speculative read indexes. Nine OpenAPI paths/nine operations under /api/v1/admin/dashboard; existing controlled workflows remain authoritative. Migration rollback/reapply and actual schema drift are tested.

## Validation

347 backend tests / 3,865 assertions; 102 frontend tests; full 23 Playwright tests passed twice consecutively. PostgreSQL/Redis revocation races, 2,000-merchant measurement, rollback/schema checks, Pint 287 files, lint/types, 171-page production build, Composer/npm audits and 4 installer tests passed. Visual QA checked 72 EN/AR desktop/tablet/mobile views with 48 screenshots and no runtime/overflow/API failures. See docs/PHASE_12_REPORT.md for exact sources, measurements and remaining policy gates. No merge, deployment, external preview, live activation or Phase 13.
