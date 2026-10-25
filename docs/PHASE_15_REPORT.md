# Phase 15 — Analytics & Advanced Reporting

## Delivery and source audit

Implementation branch `feat/phase-15-analytics-reporting`, based on verified Phase 14 PR14 merge `58fadd7cb18d1fbe36c1eaaf8347cb36765b2e69`. Latest main was fetched explicitly and fast-forwarded before branching. Existing code/history was preserved. No merge, deployment/VPS, Phase 16 or external activation was performed.

AGENTS.md, the supplied Technical Blueprint, architecture/decisions/open questions, roadmap and Phase 11–14 read/security/Commerce conventions were reviewed. The metric dictionary and report catalogue were recorded before implementation. PostgreSQL module tables remain authoritative; reporting adds no ledger, tracking, pricing or order replica. No new application dependency was introduced. See [metric definitions](analytics/METRIC_DICTIONARY.md), [fixed report/export contracts](analytics/REPORT_CATALOG.md), [architecture](architecture/PHASE_15_ANALYTICS.md), [operations](analytics/OPERATIONS.md) and [changed-file manifest](PHASE_15_FILES.txt).

## Implemented reporting

* Merchant workspace: source-permission-filtered overview/catalogue/builder; shipment drafts/current status/history, recorded submission intents/attempts, verified evidence and unknown outcomes, tracking exceptions, bulk cohorts, quotes/expiry, exact ledger movements/reservation activity/reconciliation, customs preparation and Commerce imports/conversion/readiness/activity.
* Sixteen fixed merchant report definitions with explicit source grant, fixed columns/date semantics, applicable dimensions and export policy. No arbitrary SQL, columns, filenames or customer search. Missing unsupported fields are unavailable rather than reconstructed from private payloads.
* Separate platform experience: ten existing Operations aggregate sections plus source-backed recorded reactivation/status-change/verification outcomes, current approvals and deterministic insights. Current status/queues are distinct from creation/decision/event trends. Global aggregates do not confer private merchant or monetary access.
* UTC today/yesterday/7/30/90/custom periods, at most 366 inclusive dates; Monday weeks/calendar months; source-specific status/provider/currency/service filters. Safe filter/language context survives locale changes without customer PII, credentials or tenant overrides.
* Accessible source-count trend charts with table alternatives, status distributions, exact local draft-conversion fraction, empty/loading/unavailable/error states, generation/as-of time, responsive tables and Arabic RTL/English LTR navigation.
* Informational insights use explicit threshold ≥1 and event/current/cohort semantics. No invented increasing/decreasing comparison or automated action/notification.

## Financial/evidence boundaries

Current posted/reserved/available wallet balances come from existing wallet projections; available is exact posted minus reserved. Immutable ledger/hold events are grouped by recorded kind and currency/exponent. Amounts/sequences/cutoffs remain integer strings; no JS/PHP float arithmetic for money, cross-currency totals or implicit FX. Current balances are not historical replay.

Quotes show only approved public selling/discount/total snapshots, never private costs, invoice estimates or realized revenue. Wallet funding is not platform revenue. Verified acceptance/delivery requires existing exact binding and evidence gates; synthetic observations are never verified carrier facts. Both external Commerce providers, real shipping/payment/funding/COD/financial execution and customer notifications remain disabled. Global finance reports expose only existing currency-separated operation/wallet counts and current queues; exact private targets remain on existing independently granted workflows.

Unavailable without further approved authoritative evidence: carrier invoice costs/margins, realized revenue/P&L, historical balance statements, implicit FX, complete duplicate-suppression totals and queryable country/service shipment dimensions. No report writes a financial transaction or retries/resolves an unknown carrier outcome.

## Database, API and authority

One additive migration `2026_10_25_150000_create_analytics_exports` adds `report_exports` and append-only `report_export_events`, immutable request/origin identity, actor/request uniqueness, active business-digest uniqueness, bounded states/attempts/rows/bytes, history/recovery/expiry indexes and merchant/date indexes on existing hold events/wallet transactions/Commerce orders. Schema verification covers columns, triggers and all new indexes; a negative index-drift regression proves failure detection. Rollback refuses retained export history, while disposable down/reapply preserves previous users and source data.

Four permissions: `analytics.read`, `analytics.export`, `platform.analytics.read`, `platform.analytics.export`. Grants are explicit built-in roles only; source permissions remain required. Merchant viewers/shipping operators and platform support do not receive export permission. Current membership, merchant/account state, auth version, originating session/token and bearer ability intersection remain mandatory. Platform reads require a current browser, recent MFA, platform overview and source metrics permission; no private Super Admin bypass. Financial exports require browser/recent MFA.

Sixteen operations across merchant `/api/v1/analytics` and platform `/api/v1/admin/analytics`: GET catalogue, overview, report by approved definition, export history, export metadata and download; POST export request and revocation. All exact paths, data/row schemas, permissions, UTC filters, limits/errors and closed-storage semantics are in [OpenAPI](api/openapi.yaml). Browser mutations retain CSRF and existing throttles. Responses are private/no-store; no cross-tenant cache exists.

The Operations tracking reader now accepts an optional tenant scope on every query. A dedicated regression creates another merchant's synthetic tracking work and proves its counts/status evidence cannot appear in the current merchant's analytics. Platform null-scope calls preserve existing authorized aggregate behavior.

## Export lifecycle and security

CSV UTF-8 BOM and native minimal XLSX inline-string generation are implemented with Arabic/English values and RTL XLSX direction. Fixed machine headers are documented. Formula-trigger text is escaped after leading Unicode whitespace/control characters; legitimate exact signed integer accounting cells retain their bytes. XLSX contains no formulas/macros/external links and strips invalid XML controls. Import CSV integer columns as text to avoid spreadsheet auto-formatting; XLSX explicitly preserves text precision.

Requested/authorized/queued/processing/ready/expired/failed/revoked states have append-only events. Request-key payload conflicts return 409; active equivalent business requests cannot duplicate expensive work. Source reads use PostgreSQL repeatable-read, then publication/download recheck current origin/source authority/MFA. Artifacts are encrypted and verified by size/digest. Owner/scope/merchant and exact original credential binding are mandatory; no direct predictable storage URL or customer filename is returned.

Limits: 25-row UUID keysets; 1,000 export rows; 2 MiB plaintext; 15-minute expiry; 3 active requests per actor/merchant and 100 unexpired queued/processing/ready globally. Workers cap at 4 global/1 actor/1 merchant, 45-second timeout, 60-second lease and 3 claims. Encrypted Horizon jobs use the existing bulk queue; PostgreSQL owns durable state and the scheduler runs bounded `analytics:recover` every minute. Known private staging paths are durably recorded so crash/late-write cleanup can revisit expired artifacts without deleting unrelated files. Sanitized errors contain no report contents/SQL/credentials.

**Normal artifact generation/download stays closed**, even if a test flag is set: explicit `testing` environment plus exact disposable database and a clean scanner are required for synthetic artifact tests. No approved normal private storage/scanning/retention policy exists. Missing safeguards audit a failed request without a file/job/public URL. Download routes do not bypass this gate.

## Verification

Final backend source passed **453 tests / 5,166 assertions** on real PostgreSQL/Redis, including all Phase 0–14 regression tests and 46 added test cases (45 Analytics plus tenant-scoped Tracking regression). Complete frontend: **124 tests / 18 files**. Production build: **203 pages**. Pint: **335 files**, no issues. ESLint and TypeScript passed. Four Python installation regressions passed; strict Composer validation and audit passed; full and production-only npm audits reported zero vulnerabilities. OpenAPI 3.1 validation, duplicate-key checks, all local references and **223 unique operations** passed, including 16 new analytics operations across 14 paths.

Final clean disposable-database browser suite: **32/32 passed (3.7 minutes)**, including all prior journeys and three new Analytics journeys. The previously timed-out directory/independent-approval test passed unchanged in 16.6 seconds after the fixture rebuild. An earlier full 32/32 pass is also recorded; the contaminated intermediate run is disclosed below and is not counted as success.

Visual inspection: **54/54 English/Arabic desktop/tablet/mobile views**, zero JavaScript errors, failed API requests or horizontal overflow; 54 nonempty PNGs verified for signature/dimensions. Platform overview captures were refreshed after adding the permission-filtered administrative workload. Representative screenshots are linked in the external QA README. No credentials or raw attachments/screenshots are committed. No skipped/disabled tests, weakened permissions/assertions, arbitrary UI sleeps, timeout inflation or external provider calls are used.

Normal development database preservation was checked after the additive migration: 23 users retained, 24 migrations, zero export records/shipments/ledger transactions. No normal database reset or synthetic business seeding occurred.

Synthetic report benchmark: 2,000 merchant audit records, constant 16 HTTP/database queries before/after growth, 23.76 ms measured locally in the final run (not a production SLA). Keyset pages are disjoint and payload bounded. A 1,000-row Arabic synthetic format benchmark produced CSV 43,906 bytes in 1.88 ms and XLSX 7,727 bytes in 10.51 ms; no database/payment record was created. Independent PostgreSQL processes prove one active idempotent export and one ready artifact; scanning runs outside SQL locks and current-authority publication remains fenced.

## UI routes and visual evidence

Merchant routes in both locales: `analytics`, `analytics-reports`, `analytics-builder`, `analytics-exports`, `analytics-finance`, `analytics-shipments`, `analytics-integrations`, `analytics-insights`. Platform: `platform-analytics`, `platform-analytics-reports`, `platform-analytics-exports`. All retain current source authority and safe locale/merchant context.

Actual Chromium inspection uses only isolated synthetic accounts/data through local Nginx. Evidence resides outside Git at `/workspace/shared/clicktoship-phase15/README.md` with representative screenshots, `index.html` and machine-readable `inspection.json`. Screenshots are not an interactive preview or production certification. Private test credentials remain outside the gallery and Git.

## Remaining approval/evidence requirements

* Approved private export storage, scanning, key/operator/residency/retention/deletion policy and independent operational security/scale review before normal downloads can be implemented/enabled.
* Qualified accounting/regulatory approval and authoritative cost/settlement/revenue/FX/historical replay evidence before new financial measures. No current reports imply approval of stored-value/payment/accounting launch policies.
* Existing Aramex/Salla/Shopyzee/customs evidence and deployed-version compatibility gates remain unchanged. Synthetic tests prove internal behavior only.
* Full duplicate-suppression counters, source-backed country/service projections and comparison-based alerts need separately defined source contracts; no fabricated estimates are displayed.
* GitHub API access may block PR creation/CI inspection; Git transport is checked separately. No automatic merge is authorized.


### Repeated-run fixture diagnosis

A full browser run passed 32/32. A subsequent repeated run on the long-lived disposable database hit the existing 30-second management-test deadline while traversing directory page 20 of 21. The error context identified accumulated synthetic merchant fixtures, rather than an analytics authorization/server outage. No timeout, assertion or directory policy was changed. The documented `prepare-browser-tests.sh --rebuild` path is used for final clean-database verification; it is guarded to `testing`/`clicktoship_e2e` and touches no normal data. This distinction is retained instead of claiming every intermediate run passed.


## Review workflow

55 changed files are listed in `docs/PHASE_15_FILES.txt`. The branch is prepared for review against main; no automatic merge or deployment is authorized. Git commit/push and PR/API results are reported with the final delivery. Resolve the current review commit with `git log -1 --format=%H` on `feat/phase-15-analytics-reporting`.

Normal development stack was restored with `scripts/start.sh`: API/frontend/PostgreSQL/Redis/Horizon/scheduler/Nginx healthy, schema verification and English/Arabic readiness smoke passed. Normal data remains 23 users / 24 migrations / zero report exports, ledger transactions and shipments. Only the disposable E2E database was rebuilt.
