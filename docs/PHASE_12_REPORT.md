# Phase 12 — Super Admin Dashboard & Platform Operations

## Base, scope and functionality

Verified Phase 11 PR #11 merge `18ae1e3ded0ba17166aa3f3ff92c8a9fe7943872`, fetched main and preserved its history. Implementation branch: `feat/phase-12-super-admin`. The scope/permission policy was documented before implementation in [PHASE_12_SCOPE.md](architecture/PHASE_12_SCOPE.md).

Delivered a distinct unified platform workspace with 16 bilingual screens: overview, merchants, users/roles, approvals, shipping, bulk, tracking, carriers, pricing, finance, customs, operational health, alerts, activity, settings and exact-granted merchant summaries. The searchable permission-aware sidebar, breadcrumbs, active links, responsive mobile disclosure and safe language context also integrate existing administrative workflows. Shared Phase 11 UI components remain in use; no dependencies added.

Overview composes real persisted counts, UTC calendar filters, daily registration/verification/creation/submission/exception trends, state distributions, approval workload, internal alerts, redacted security activity and observed service health. Existing domain screens retain their verification/status/user-role/configuration/publication/correction/review services. The merchant directory and grant-request selector support bounded literal search and independent account/verification filters.

## Sources and semantics

[Complete KPI/source dictionary and architecture](architecture/PHASE_12_PLATFORM.md).

| Area | Persisted source | Meaning |
|---|---|---|
| Merchants/users | merchants, verification decisions, users, platform assignments | Current stocks/statuses; creation/decision trends in UTC period |
| Shipping | shipments, submissions, observations, pickups | Creation cohort, submission records and current exceptions; verified acceptance requires exact accepted verified observation |
| Bulk | batch/work records | Batch creation trend/state and current failed/incomplete work; recovery candidates are possible stalls, not proven failures |
| Tracking | bindings, exact projections/details, cases/reviews/work | Verified provenance/validation only for verified statuses; current cases/failures/staleness and period exception trend |
| Carriers/pricing | account/configuration/intent records, capability registry, global definition metadata | Configuration readiness and pending versions, never claimed sandbox success/live tariffs or confidential cost payloads |
| Finance | currency/wallet/balance metadata, latest reconciliation per wallet, approval and settlement records | Currency-separated counts only; no global monetary disclosure, revenue or profit |
| Customs | current country version/review, current-revision readiness and unrevoked document/work records | Local preparation/review/quarantine counts, never customs clearance |

Stocks/current queues do not change meaning under period filters. Synthetic fixtures are development observations and never verified external activity. Account status remains active/suspended/closed, separate from pending verification. Verified acceptance/tracking remain zero when evidence is absent. No invented transactions or normal development fixtures were created.

## API and database

Nine new operations under `/api/v1/admin/dashboard`:

| Method | Suffix | Purpose |
|---|---|---|
| GET | root | Per-source authorized aggregates and UTC period |
| GET | /activity | Redacted global platform audit, exact action filter and 25-item keyset pages |
| GET | /health | Safe dependency/schema/Horizon/queue observations |
| GET | /settings | Fixed read-only currency/limit/security/gate catalogue |
| GET | /approvals | Allowed source workload, own financial request count |
| GET | /grants | Bounded redacted current actor/session grant references |
| GET | /alerts |25 recent source-derived opaque alerts and actor read state |
| POST | /alerts/{alert}/read | CSRF-protected idempotent immutable receipt; does not resolve case |
| GET | /merchants/{merchant} | Existing exact-grant authority for merchant/shipping/finance/pricing section |

OpenAPI 0.12.0 documents all new paths, schemas, permissions/errors, bounded parameters and existing directory filters. The document has 164 paths and 189 unique operations; duplicate-key and local-reference validation passed.

Only new table: `platform_alert_receipts`, migration `2026_10_25_100000_create_platform_overview`; actor/key PK, 64-hex check, user FK, composite actor/session FK, UTC timestamp and immutable update/delete/truncate trigger. 14 new aggregate/read-state permissions map to existing built-in roles. They cannot be included in merchant access grants. SchemaIntegrity checks actual objects independently of migration history. Downgrade refuses retained normal history; guarded disposable tests verify rollback/reapply and preserve previous schemas. No second ledger, projection, notification/business store or speculative index.

## Security and financial boundaries

Every new operation requires active verified identity, current browser session, current roles and recent MFA. Personal API tokens cannot become platform credentials. Current-user/session locks and the existing PostgreSQL platform-security advisory key serialize authorization with revocation. Private target reads additionally revalidate exact independent approval/session/MFA/expiry/capability through existing services. Aggregate permissions omit unauthorized source queries and cannot open private merchant data. Financial own-membership restrictions remain enforced. Late private browser responses are discarded after selection changes.

Responses are private/no-store; failed refreshes clear old data. Global audit omits merchant data and metadata; monitoring omits infrastructure/debug/queue payloads/secrets. Opaque HMAC alert identities disclose no private record IDs; read-state is actor-specific, immutable, idempotent and cannot resolve source state or send messages. Settings has no arbitrary editor, gate override, restart or blind replay. No automatic Super Admin bypass.

Global monetary amount disclosure was stopped because no approved policy defines it. The authorized overview shows separate currency wallet/frozen/reservation counts and reconciliation/settlement queues. Exact-granted WalletBilling reads retain integer strings and explicit precision. No balance editing, funding, capture/refund/charge/payment activation, new accounting policy or revenue/profit claim. No carrier execution, webhook activation, customs clearance or customer notifications.

## Automated verification

Complete backend regression passed twice: 347 tests / 3,865 assertions, including all prior phases. New coverage includes 19 backend tests (source correctness, evidence provenance, source omission, empty/multi-currency data, session/token/MFA, exact grants/approver expiry/revocation, CSRF/immutable receipts, rollback/schema drift, filters/date bounds and real independent-process authority races) and 11 frontend tests. The old 328 backend and 91 frontend tests remain present.

Verification findings corrected without weakening security: new browser assertions initially matched both the application alert and Next.js route announcer; assertions now scope to main content. A retained multi-page E2E directory made the long financial approval journey depend on all prior fixture tenants; the real grant-request search and bounded selector helper remove that dependency. Concurrent visual/backend/browser verification also increased multi-session journey time; final browser cycles run sequentially with unchanged default timeouts. No skips, assertion removals or arbitrary sleeps were added.

| Check | Final result |
|---|---|
| Complete backend, real PostgreSQL/Redis | **347 passed / 3,865 assertions**, repeated complete run; final 315.78 seconds |
| New platform backend/security/evidence/performance | 19 passed, included in the full regression |
| Independent platform role/session/MFA revocation races | Actual independent PHP requests and PostgreSQL lock waiters; permitted control 200, winning revocations 401/403, no aggregate data |
| Previous finance/shipping/bulk/tracking/customs concurrency/idempotency | Included in 347; no skipped tests or external live calls |
| Frontend unit/component | **102 passed**, 16 files |
| Full Playwright | **23/23 passed twice consecutively**, 2.7 and 2.6 minutes, unchanged default timeouts |
| Schema and migration compatibility | Actual object/trigger integrity, drift detection and guarded rollback/reapply passed; 20 normal migrations installed |
| Laravel Pint | 287 files passed |
| ESLint / TypeScript | Passed |
| Next.js production build | 171 generated pages, passed |
| Composer strict validation / audit | Valid, no security advisories |
| npm production and full dependency audits | 0 vulnerabilities |
| Installer/archive integrity tests | 4 passed |
| OpenAPI structure | 164 paths / 189 unique operations, duplicate keys/references validated |
| Visual responsive EN/AR inspection | 72 views, 48 nonempty PNGs; no runtime errors, failed API requests or horizontal overflow |
| Normal development data | 20 migrations; zero browser-fixture users, shipments, ledger transactions, carrier operations, customs documents and alert receipts |

The normal development stack is restored after E2E/build verification. Screenshots remain isolated test evidence; they do not seed the normal application or establish production readiness.

## Performance

A guarded PostgreSQL test inserts 2,000 synthetic merchants, compares request query count with the empty baseline, runs ANALYZE/EXPLAIN ANALYZE, verifies 25-item filtered directory pages and enforces a response below 256 KiB. Query count remains constant rather than per merchant. Final full-suite result: 63 queries, 32.06 ms HTTP, 12,819 response bytes, 0.416 ms aggregate SQL plan execution. These are local measurements, not production capacity/SLA claims. Existing indexes were adequate for this measured workload; no speculative read index added. Query execution has a 5-second statement timeout and sanitized 503 failure behavior.

## Visual QA and evidence

Used only guarded `clicktoship_e2e` synthetic users with random passwords/single-use MFA recovery codes in 0600 files under /tmp; no normal database seeding or public credentials. Checked 72 page/device/language combinations, including all 16 platform sections. Eight core sections (overview/merchants/pricing/finance/carriers/shipping/tracking/customs) were checked in EN/AR at 1440×1000, 820×1180 and 390×844. Captured 48 PNGs, checked real API failures, runtime errors, direction and horizontal overflow: none. Links were observed on the rendered pages; this is not a claim of crawling every workflow link.

Evidence remains outside Git: `/workspace/shared/clicktoship-phase12/inspection.json` and 48 representative screenshots. Example [English overview](/workspace/shared/clicktoship-phase12/en-desktop-platform-overview.png), [Arabic mobile overview](/workspace/shared/clicktoship-phase12/ar-mobile-platform-overview.png), [Arabic tablet finance](/workspace/shared/clicktoship-phase12/ar-tablet-platform-finance.png). Full artifact listing/status is in the adjacent README. Screenshot counts reflect isolated persisted fixture activity, not real carrier/money activity.

## Delivery and remaining gates

[Changed-file manifest](PHASE_12_FILES.txt), [prepared PR description](PHASE_12_PR.md). Branch/hash/push/PR status are supplied in the completion message and external evidence README so this report does not self-reference its own commit hash.

Outstanding policy/external decisions: global monetary disclosure; qualified accounting/regulatory review; verified Aramex sandbox response/auth/webhook evidence and account authorization; secure storage/scanning/retention; independent scheduler heartbeat; representative production load/soak, runtime DB role/provisioning and recovery signoff. APP_KEY rotation changes derived alert identity/read-state; source cases/history remain authoritative. Existing domain workflows retain their own policy limits. See [OPEN_QUESTIONS.md](OPEN_QUESTIONS.md).

No automatic merge, production/VPS deployment, external preview/tunnel, live activation or Phase 13.
