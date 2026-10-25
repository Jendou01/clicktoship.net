# Testing strategy

## Foundation commands
Four Python installer tests verify checksum/reference/integrity rejection for cached release archives. See README for Docker setup and exact test/build commands. Backend PHPUnit runs against PostgreSQL + Redis with a dedicated clicktoship_test database. Run migrations and rollback/reapply in development to exercise schema compatibility. Tests block stray Laravel HTTP requests. A queue integration test dispatches a synthetic Redis job and verifies completion in a separate PHP worker process. Feature tests cover minimal liveness, real dependency readiness, DB/cache failure handling, non-leaking responses, uniform errors and disabled sensitive routes. Sanctum/Horizon presence is not auth feature completion.

Frontend: ESLint, TypeScript, Vitest/Testing Library for bilingual shell, Next.js production build, and Playwright for English/Arabic rendering, direction and navigation. Playwright uses a test-only base URL on loopback and no carrier traffic. Browser tool downloads may require additional network access; report blockers distinctly. Health smoke tests use actual running services through Nginx.

## Required future gates
* Phase 2: registration/login/logout/reset/verification/session/token abuse, CSRF, suspension, every role and cross-merchant/IDOR path, jobs context reset.
* Finance: real PostgreSQL concurrent holds/debits, lock contention/deadlock retry bounds, double-entry balance, immutable postings, idempotent PSP callbacks/capture/release/refund and unknown outcome handling. Test rollback and duplicate payment/refund events.
* Pricing: effective-date/weight/country/service boundary, rounding/exponents, min margin, quote expiry and immutable snapshot.
* Carrier: HTTP-faked scrubbed fixtures for each documented request/response/error, malformed 200, item failures, timeouts, rate limits and no unsafe create retry; separate opt-in sandbox suite, never production.
* Bulk: 1,000 rows asynchronously, parser limits, duplicates, partial success, retry and safe exports. Tracking: duplicate/out-of-order events, unknown status, invalid auth, replay and reconciliation.
* Commerce: installation/revocation, token rotation, duplicate orders, fulfillment loop prevention, provider errors and retries.
* Release: dependency/SAST/secret scans, migration upgrade/rollback rehearsal, API schema compatibility, RTL accessibility, load/soak, backup/restore drill and finance/operations UAT.

## Reporting rules
Record command, target, count and exit status. Distinguish passed, failed, skipped and unrun. A successful build does not prove security or integration correctness. Never add placeholder passing tests or disable assertions to pass CI. No real customer data or uploaded credential values in fixtures. Current execution evidence is recorded in FOUNDATION_REPORT.md.

## Phase 2 execution and commands

Run README verification commands with real PostgreSQL/Redis; the bootstrap refuses any database except clicktoship_test. Tests include real CSRF/session requests, 6-role authorization, cross-tenant IDs and spoofed context, missing/invalid/legacy/expired/revoked tokens, suspension, email signatures, hashed single-use recovery, revocation/replay, immutable PostgreSQL constraints and audits, row-lock/version races, and one Redis worker processing distinct merchant jobs. API-originated job tests preserve narrowed scope and reject revoked tokens.

Browser tests now require the full Compose stack and development SMTP inbox; default E2E_BASE_URL is http://127.0.0.1:8080 and MAILPIT_URL http://127.0.0.1:8025. Six Chromium tests include real registration/email verification, organization creation/switch, team read-only denial, scoped token mint/use/revoke, password recovery/session invalidation and Arabic/RTL/CSRF, plus the three existing foundation flows. Mail messages are selected by unique synthetic recipient, never by clearing the inbox. Component/client tests exercise rendering, validation, API credentials/XSRF/error behavior. No external carrier/payment API is called.

GitHub Actions has separate api, web and full-stack browser jobs. Local results and limitations are in PHASE_2_REPORT.md; defining CI is not evidence that GitHub-hosted CI ran.

## Phase 3 verification

Additional backend suites exercise profile/address/settings scope/encryption/versioning, ten-role platform/merchant access, immutable PostgreSQL relationships/history, manual verification, expiring/revoked/single-use email invitations and inviter demotion, TOTP/recovery replay/expiry/rate limits, session/token revocation, independently approved target/session-bound access, user/merchant suspension/reactivation/closure, protected Owner/Super Admin and true separate-process admin/settings races. A migration test rolls back and reapplies only Phase 3 in the guarded test database, proving Phase 2 accounts/memberships/tokens/audits survive.

New component tests cover Arabic RTL, editable profile values/version, verification status/history, transient MFA secrets, unauthenticated redirect and safe privileged-access denial. Browser testing uses the opt-in prepare-browser-tests.sh runtime with testing/clicktoship_e2e, separate Redis namespace/session cookie, Mailpit and Horizon. The test fixture refuses other database/environment combinations; random credentials stay in a mode 0600 /tmp JSON file read by the browser runner. Existing six tests remain, plus two complete Phase 3 journeys spanning profiles, country-aware addresses/defaults, verification/MFA setup, real invitation email acceptance and two independent MFA-authenticated administrators requesting/approving/accessing a merchant. All tests retain default timeouts; no readiness/action waits are loosened. Shared form labels are explicitly associated with inputs/selects so option text cannot contaminate label lookup.

Normal setup/start does not create these administrators. Reprepare browser fixtures before a complete repeat to renew synthetic MFA/recovery credentials; respect real Redis signup windows and do not weaken rate limits. GitHub Actions browser job uses the same preparation. Record local and GitHub-hosted outcomes separately in PHASE_3_REPORT.md.

## Phase 4 financial verification

New suites test journal balance/immutability/current-transaction append, nonnegative available balances, partial hold capture/release, refund caps, reversal dependencies, double idempotency, rollback/outbox replay, exact minor units and SAR-only enablement, synthetic provider event replay/conflict, tenant/token/CSRF isolation, independent financial approval and MFA freshness, stable statement cutoffs and migration preservation. Restricted-role SQL tests use SET ROLE cts_finance_runtime; they do not assert development Compose uses a production DB login.

ConcurrentFinanceTest uses independent PHP/PostgreSQL processes and waits for a real pg_stat_activity Lock waiter before releasing the first transaction; no arbitrary delay is used as proof. Tests include overspending/duplicate reservations, simultaneous captures, merchant suspension and MFA/credential/approval revocation winning against correction execution. DB and HTTP results are checked separately.

Five additional frontend tests cover exact string formatting/parsing, Arabic balances, zero wallet provisioning and denied admin access. Two additional full-stack Playwright journeys cover zero SAR statements/Arabic and two independent finance reviewers with execution disabled. The finance browser owner is a separate opt-in fixture so it cannot consume the existing merchant journey's per-user rate budget. No production rate limit, retry or timeout was loosened. Normal fixtures remain zero-funded. Complete execution counts/outcomes are in PHASE_4_REPORT.md.

## Post-Phase 7 QA gates

`schema:verify` checks critical Phase 7 columns, triggers (including immutable-history guards), enabled state and the decisive-observation FK independently of migration history. Browser preparation fails before fixture creation on drift. Only the explicit `prepare-browser-tests.sh --rebuild` path resets `testing/clicktoship_e2e`; the guarded command rejects normal/regression database targets. Never use a normal-data reset to repair browser fixtures. Schema tests remove/disable objects inside rolled-back PostgreSQL transactions and prove that migration rows alone cannot hide the failure.

New component tests cover bilingual field paths/hints, focus/invalid attributes, fail-closed permission menus and locale URL allowlisting. The full browser suite adds English/Arabic field-error reproduction, persisted shipment locale/refresh context, authorized module navigation and mobile team-card/overflow checks. Screenshots contain synthetic data only; do not publish traces or credentials.

## Phase 8 regression gates

Bulk tests use real PostgreSQL/Redis and opt-in synthetic fixtures. Coverage includes a 1,000-row import with non-divisible chunks, partial validation, BOM/Arabic aliases/digits, exact pricing/private-field suppression, duplicate review versus idempotency, source/export isolation, ZIP traversal/expansion/macros/formulas/relationships/entities/corrupt XML/limits, authenticated encryption tampering, cancellation and credential-revocation commit fences, repeated fresh installs and preservation rollback. Concurrency assertions wait for actual PostgreSQL lock waiters and refresh the statistics snapshot within the parent transaction; no sleep proves success. Native parser tests exercise bounded quoted/multiline CSV and inline/shared-string XLSX, plus a real Arabic-template XLSX through preparation.

The browser suite adds a dedicated bulkOwner account to avoid sharing existing pricing/shipping action budgets. It runs actual multipart upload/Horizon processing, mapping, duplicate skip, public quote preview, partial draft preparation, formula-safe report download, retained AR locale/batch context and responsive overflow checks. No retry, timeout or permission assertion is weakened. Production scanner absence is a fail-closed limitation, not a passing malware integration test.

## Phase 9 tracking

`docker compose run --rm --no-deps api php artisan test --filter=Tracking` runs provider gates, forged/replayed/out-of-order/unknown events, terminal preservation, tenant/scope/session/MFA/grant boundaries, immutable data/projection constraints, same-account reference collisions, actual independent PostgreSQL processes and isolated real Redis queue-message loss. Synthetic fixtures are not Aramex contracts. `npm test` includes bilingual timeline/disabled-state rendering; the full Playwright suite includes a real draft tracking journey with Arabic mobile viewport, CSRF, external-ingress rejection and no live data claims. Use the existing disposable E2E installation scripts; never seed/reset normal development data.

## Phase 10 international/customs verification

The complete backend run uses real PostgreSQL and Redis (`docker compose run --rm --no-deps api php artisan test`), including CustomsTest, CustomsAdministrationTest, CustomsIntegrityTest, ConcurrentCustomsTest, InternationalBulkTest and InternationalImportTest. Concurrency uses independent PHP/PostgreSQL processes and observes actual lock waiters; credentials are passed on stdin. Tests cover canonical decimal-safe arithmetic, country/currency/HS input, package allocation consistency, immutable versions/history, tenant/scopes/MFA/current grants, credential revocation during generation, default quarantine, encrypted bytes/tampering, document numbering/idempotency, lease crash recovery, schema drift and rollback/reapply. Carrier/provider HTTP remains fake and all external gates closed.

Component tests exercise localized item/readiness/document/admin states. A dedicated opt-in customsOwner browser fixture isolates existing per-user action budgets; it exists only in testing/clicktoship_e2e. The real Chromium journey creates an international draft, edits customs, verifies pricing unavailability, waits for actual Horizon PDF quarantine, checks no download, switches to Arabic and inspects mobile overflow. Reprepare fixtures before each complete suite to refresh single-use admin recovery credentials. Assertions target the actual server document number and localized quarantine status. No retries, permissions, rate limits or timeouts were weakened.

Synthetic PDF samples demonstrate Unicode rendering only; they are not real payments, customs filings, carrier responses or legally valid invoices. Never publish browser traces, cookies, fixture credentials or raw source attachments. Full counts and external limitations are recorded in PHASE_10_REPORT.md.

## Phase 11 dashboard verification

MerchantDashboardTest covers true zero states/no write effects, UTC date boundaries/366-day bounds, mixed-currency exact strings, source-level permission query omission, token/header/tenant boundaries, session organization switching/revocation, cursor/date/identifier rejection, deterministic 20-row keysets, real bulk cursor row counts, quote/exception semantics, current-revision customs readiness, constant query count with growing data and index rollback/drift preservation. DashboardTrackingTest proves synthetic carrier acceptance/tracking cannot inflate verified KPIs. DashboardRaceTest uses independent PHP/PostgreSQL processes, observes a real lock waiter and proves suspension/token revocation winning the merchant lock denies the report. Credentials pass through private process stdin only.

The complete existing regression suite remains mandatory. Component tests cover bilingual/RTL, large exact currency formatting, safe locale filters, permissions, clearing old KPIs on failure and context redirects. The full real-stack browser journey uses a dedicated opt-in dashboardOwner (testing/clicktoship_e2e only), creates a real draft, checks aggregate changes and real activity, applies filters, switches locale/merchant and inspects mobile overflow. No timeout/rate-limit/permission assertions are weakened. Screenshots use synthetic data only; no raw credentials/cookies/traces are published.


## Phase 12 platform administration

PlatformOverviewTest, PlatformOverviewEvidenceTest, PlatformOverviewRaceTest and PlatformOverviewPerformanceTest use real PostgreSQL/Redis in guarded clicktoship_test. They verify source KPI semantics, omission of unauthorized queries, current roles/session/MFA, exact target grants and independent approver authority, schema drift/down/up, immutable idempotent CSRF-protected read receipts, redaction, UTC periods, per-currency count-only finance, no financial/carrier side effects and bounded pagination/search. Independent PHP processes and pg_stat_activity prove the competing revocation holds/waits on real locks; cookies go over stdin, not process arguments. A 2,000-merchant fixture measures constant query counts, actual EXPLAIN ANALYZE and response size; never seed a normal DB.

New platform component tests cover permission-aware navigation, MFA/error states, exact money, RTL/context and late private response fences. The full Playwright suite includes real platform/finance-role journeys and reuses isolated clicktoship_e2e fixtures with test-only random MFA credentials. Refresh fixtures before each complete run; there are no assertion-removing sleeps or test skips. A route announcer is also role=alert, so application error assertions are scoped to main content. Run all README checks and restore ./scripts/start.sh after browser/visual inspection. Full outcomes and visual evidence are in PHASE_12_REPORT.md.
