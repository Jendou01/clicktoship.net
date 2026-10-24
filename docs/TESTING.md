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
