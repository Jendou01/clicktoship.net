# Phase 11 — Merchant Dashboard & Experience

## Delivered scope

Branch `feat/phase-11-merchant-dashboard`, based on fetched `main` and verified Phase 10 merge `965ac806097a957ade4685d432d5b1d34849e909`. Phase 11 adds a real, permission-filtered merchant operational dashboard and shared bilingual experience. No carrier, financial, customs-clearance or notification execution is activated; no external preview, forwarding, tunnel, VPS deployment, automatic merge or Phase 12 work.

## Features

* Operational overview: shipment drafts/creation, actual submissions, verified carrier acceptance, exceptions, daily creation trends and current recorded state distribution. Zero days and empty/unavailable states are explicit; no fabricated revenue/shipments/tracking.
* Verified tracking uses the existing projection and exact binding/event detail provenance. Synthetic/unverified facts do not inflate confirmed KPIs. Current carrier evidence remains unverified, so verified acceptance/tracking may correctly remain zero.
* Current posted/reserved/available wallet balances are separate exact minor-unit strings per currency and exponent, never combined or converted. A missing wallet does not become an artificial funded wallet.
* Real bulk phase/row progress and prepared draft counts, estimate/unexpired quote counts with route-dependent availability guidance, and current-revision persisted customs preparation readiness without clearance claims.
* UTC today/last 7/30/90 days and inclusive custom ranges up to 366 days. Source-specific period semantics are visible: shipment cohort versus submission/acceptance events, projection confirmation date, import/quote/declaration creation and current balances.
* Bounded operational activity, module/exact UUID filtering and 20-row timestamp/UUID/module keyset pagination. No PII payloads, carrier credentials/cost or confidential margins are projected.
* English/Arabic dashboard, responsive KPI cards, accessible daily tabular chart data, status-distribution meters, wallet/import/activity tables, safe locale filter retention, refresh/retry/loading/empty/warning states and permission-aware quick actions.
* Unified searchable navigation for dashboard, shipments/create, bulk, tracking, international, pickups, rates/quotes, wallet, team, address book and settings. Current merchant indicator/switching and active-page state are consistent. Platform sections remain separate and permission-controlled.
* Reusable PageHeader, Panel, KpiCard, EmptyState, StatusBadge, Notice and DataTable primitives. Shipping, Pricing, Bulk, Tracking, Wallet and Customs adopt the shared page header, retaining existing business workflows and security controls. No frontend/chart dependency added.

## APIs and authorization

| Endpoint | Behavior |
|---|---|
| `GET /api/v1/dashboard` | Current merchant context, effective permission list, bounded reporting period, authorized widgets and first activity page |
| `GET /api/v1/dashboard/activity` | Reauthorized date/module/exact-subject filtering and next keyset page |

Both reuse Sanctum, active verified identity, current merchant membership and `merchant.view` intersected with token abilities. Source permissions are independently enforced: `shipments.read`, `tracking.read`, `wallet.read`, `bulk.read`, `rates.read`, `customs.read`. Unauthorized widgets and source queries are omitted. A platform role never bypasses merchant membership. No new role or permission grant is introduced. Responses are private/no-store; a shared cache is deliberately unnecessary and would complicate revocation safety.

Current authority is rechecked under existing merchant-then-user lock order. Separate-process race tests prove a suspension/token revocation that wins the merchant lock denies a waiting dashboard request. No external request runs within those locks. Frontend refresh clears old KPIs before fetching, ignores obsolete asynchronous results and does not redisplay previous merchant data on failure. Existing session CSRF and security policies remain in effect; dashboard APIs add no write route.

## Database and source ownership

One additive migration: `2026_10_25_080000_add_dashboard_read_indexes.php`, containing six non-unique indexes:

`dashboard_submissions`, `dashboard_quotes`, `dashboard_customs_latest`, `dashboard_customs_directory`, `dashboard_customs_activity`, `dashboard_shipping_activity`.

No new tables, ledger, tracking state machine or business records. Down removes only read indexes. Preservation and schema-drift tests prove data survive rollback/reapply and a recorded migration cannot hide a missing index. Prior-phase rollback tests first remove the new additive migration, with original assertions retained. Normal development migrations are applied without resetting or seeding normal data.

The [architecture document](architecture/PHASE_11_DASHBOARD.md) defines every KPI source, permission and timestamp. PostgreSQL aggregates existing module-owned records; it does not decrypt customer shipment/pricing payloads. Output bounds are 366 daily points, 20 activities plus lookahead, six recent batches and 100 currency rows. Per-statement SQL deadlines are five seconds after authorization. Query-count tests prove constant query count as tenant records grow, below 35 queries including HTTP authentication/context work. This is not a production load/SLA certification; coarse merchant locking and future large-dataset sizing require measurement before launch.

## Verification and inspection

| Check | Final result |
|---|---|
| Complete backend Phase 0–11 | **328 passed / 3,421 assertions**, real PostgreSQL/Redis |
| Frontend component/unit | **91 passed / 15 files** |
| Full Chromium regressions | **21/21 passed in two complete successful runs**; final run includes status meters |
| Independent-process security races / schema drift / index rollback-reapply | Passed |
| Query count under growing tenant data | Constant, below 35 including request authorization |
| Laravel Pint | **272 PHP files passed** |
| ESLint / TypeScript / production build | Passed without warnings; **139 pages built** |
| Composer strict validation / Composer audit / npm audit | Passed; **zero known dependency advisories** |
| Installer regressions | **4 passed** |
| OpenAPI strict YAML / duplicate keys / operation IDs / local references | Passed |
| Real browser inspection | **4 views**, EN/AR desktop/mobile; **0 runtime errors / 0 horizontal-overflow views** |

Normal development was restored through scripts/start.sh: all services running, Horizon active, PostgreSQL/Redis/schema and EN/AR smoke passed. Normal database clicktoship has **19 applied migrations**, with zero shipments, customs documents, ledger transactions and carrier operations; no normal data was seeded or reset. GitHub-hosted results are separate from these local checks. Checks include:

* Full Phase 0–10 backend regressions plus dashboard tests against real PostgreSQL/Redis.
* KPI/date boundaries, tenant/header/session switching, token intersection/revocation, no-source/unauthorized query omission, exact mixed-currency values, source event semantics, bulk cursor gaps, latest current-revision customs checks and absence of financial/carrier side effects.
* Independent PostgreSQL-process revocation/suspension races observing actual row-lock waiters, without arbitrary timing delays.
* Safe bounded cursor/identifier validation, deterministic pagination, index drift, migration rollback/reapply preservation and query-count growth checks.
* Full frontend component/unit suite, full-stack Playwright regressions, EN/AR desktop/mobile inspection, Pint, ESLint, TypeScript, production build, strict OpenAPI/Composer validation and dependency audits.

The initial full browser run passed all 20 previous cases and exposed one new dashboard issue: Shipping's real `draft_saved` event fell through to a generic activity label. The UI now maps that actual source event to localized “Shipment draft saved”; the browser assertion remains specific to the save event. No tests, assertions, permissions, rate limits, retries or timeouts were weakened. A dedicated testing-only dashboardOwner avoids consuming existing journeys' per-user request budgets.

Four real Chromium views cover EN/AR at 1440-pixel desktop and 390-pixel mobile widths. Both initial and refreshed final inspections recorded no runtime JavaScript errors or horizontal overflow; screenshots/evidence accompany delivery. Desktop retains searchable navigation; mobile disclosure, responsive table cards, focused controls and direction-isolated references/money are usable. Example data are synthetic isolated E2E drafts, not carrier transactions or payments. No cookies, credentials or traces are published.

## Remaining boundaries

* UTC is the explicit reporting default; configurable local business-day/accounting periods need separately approved policy. Date-specific counts and current balances are intentionally not presented as one financial flow.
* Quote counts/unexpired status are informational, not a guarantee that current route/service/tariff/country eligibility still permits acceptance. Current-revision customs snapshots are persisted local checks, not new live legal checks.
* Verified carrier acceptance remains zero while existing observation provenance supports synthetic tests only. No carrier contract, live tracking, notification, real wallet funding/charges or customs authority filing is enabled by this dashboard.
* Existing country/carrier/contract, accounting/regulatory and storage/scanning/privacy/retention gates remain unchanged. Production performance, backup/operational launch and formal accessibility certification remain future readiness work.
* This is a merchant-experience phase, not the separately planned Phase 12 platform dashboard. Existing protected admin links are retained; no new unrestricted administration or tenant bypass.
* GitHub-hosted CI/PR access must be reported from actual results, not inferred from local checks.

## Delivery references

[Architecture and KPI source table](architecture/PHASE_11_DASHBOARD.md) · [OpenAPI](api/openapi.yaml) · [Changed-file manifest](PHASE_11_FILES.txt) · [Prepared PR description](PHASE_11_PR.md). The actual commit SHA, push verification, final check counts and PR URL/access limitation are in the final delivery message and external evidence; no self-referential commit hash is embedded here.
