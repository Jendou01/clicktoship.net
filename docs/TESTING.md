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
