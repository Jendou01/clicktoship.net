# Phase 2 completion report

Implemented 23 October 2026 on `feat/phase-2-auth-tenancy`, based on main `0eae68242efd9553b27b131d1c73fca4aab6bb5e`. No main merge, production deployment or financial/shipping implementation. The branch head is the delivery commit; GitHub delivery outcome is reported separately after pushing.

## Implemented features

Registration, secure login/logout, signed email verification/resend, hashed single-use password recovery/reset, revocable Redis sessions, Sanctum merchant-bound expiring API tokens, secure password hashing/rehashing, suspension enforcement and Redis authentication throttles. Password reset invalidates every session/token. Email notifications are encrypted queued jobs sent after commit through private mail; development uses Mailpit.

Merchant organization creation, multiple memberships per user, per-session merchant selection, membership-derived context, fail-closed tenant queries, policies and foreign-ID protection. Team creation/change/suspension/removal uses merchant row locks, optimistic versions, locked reauthorization, subset grants, owner-only ownership changes and final-owner protection. PostgreSQL prevents cross-merchant relationships and membership reparenting.

Four platform roles: Super Admin, Platform Operations, Platform Finance, Platform Support. Six merchant roles: Merchant Owner, Merchant Admin, Shipping Manager, Shipping Operator, Finance Manager, Read-Only Viewer. Granular grants are server-enforced. Platform assignments are separate and never bypass merchant isolation. Role catalogues are read-only; no platform administration/impersonation route is exposed.

## Database migrations

* `2026_10_23_010000_create_identity_and_merchant_tenancy`: merchants, merchant_users, roles, permissions, role_permissions, merchant_role_assignments, platform_role_assignments, auth_sessions and audit_logs; user locale/suspension/authentication version; merchant-bound token columns. Reuses existing verification/reset support. UUIDs, scope/status checks, composite foreign keys and case-insensitive email uniqueness; ten built-in roles, no accounts seeded.
* `2026_10_23_020000_harden_membership_identity`: immutable membership identities and audit TRUNCATE protection. Audit UPDATE/DELETE already prohibited.

Both applied successfully in development. Automated migration refresh/rollback/reapply and locking tests use a dedicated real PostgreSQL database. The inherited test environment precedence was corrected and a pre-migration database-name guard added after an initial test selected the empty foundation development database; its schema was restored. Subsequent tests use clicktoship_test exclusively.

## New API endpoints

All below are under `/api/v1`; full operation schemas/security are in [OpenAPI](api/openapi.yaml). GET `/sanctum/csrf-cookie` bootstraps browser CSRF separately.

| Methods | Path | Purpose |
|---|---|---|
| POST | /auth/register, /auth/login, /auth/logout | Identity lifecycle |
| GET | /auth/me | Whitelisted current identity/context reference |
| POST | /auth/password/forgot, /auth/password/reset | Generic recovery and single-use reset |
| POST | /auth/email/verify, /auth/email/resend | Own signed email verification |
| GET | /auth/sessions | Current user's revocable sessions |
| DELETE | /auth/sessions/{session} | Revoke own session |
| GET | /auth/memberships | Authorized organization memberships |
| GET, POST | /merchants | List memberships / create owned organization |
| POST | /merchants/select | Revalidate and switch browser context |
| GET | /merchants/current | Selected organization and effective grants |
| GET | /roles, /permissions | Merchant role catalogue / effective permissions |
| GET, POST | /team | Bounded team list / add verified account |
| GET, PATCH, DELETE | /team/{membership} | Scoped membership read/change/removal |
| GET, POST | /tokens | Own selected-merchant tokens / mint narrowed token |
| DELETE | /tokens/{token} | Revoke own selected-merchant token |

## Frontend screens

English and Arabic routes under `/en` and `/ar`: register, login, verify-email, forgot-password, reset-password, onboarding, organizations, dashboard, team and security. Document-level RTL/LTR, responsive forms, safe errors, real onboarding/switch/team/session/token flows. Credential forms use POST and wait for hydration. Email fragments remain in memory, are removed from history and can refresh on an already mounted screen; no raw credential persistence in browser storage.

## Security verification

Negative tests cover anonymous requests, missing CSRF, foreign origins, foreign membership IDs, forged merchant context, role escalation, six merchant roles, platform bypass attempts, suspended accounts/merchants/memberships, invalid/legacy/expired/revoked tokens, token/password/session invalidation, tampered/expired/foreign verification links, reset token replay/expiry/wrong email, immutable database constraints and audits, concurrent membership versions/owner loss/actor demotion, and queue context cleanup/isolation. Token-originated jobs preserve original scope and recheck revocation at execution. Auth responses are no-store; raw tokens are returned once. No external carrier or payment calls.

## Executed local checks

| Check | Result |
|---|---|
| PHPUnit, real PostgreSQL + Redis | **64 passed, 468 assertions**; includes original 15 foundation tests |
| Vitest component/client | **10 passed** |
| Full-stack Chromium Playwright | **6 passed**; real Mailpit/Horizon verification/reset delivery |
| Python installer integrity | **4 passed** |
| PHP Pint, Composer strict validation | Passed |
| ESLint, TypeScript, Next.js production build | Passed |
| Composer audit | No vulnerability advisories |
| npm production dependency audit | Zero vulnerabilities |
| Migration status, Nginx API readiness, English/Arabic startup | Passed |
| Horizon / Redis queue workers | Running; real-worker integration tests passed |
| OpenAPI reference and unique-operation checks | Passed, 28 operations including health/CSRF |

Docker/Compose, PHP 8.3, Composer, PostgreSQL 17 and Redis 7 are available through the running development containers. PHP/Composer are container tools rather than host requirements. GitHub-hosted CI results are not claimed; CI now includes separate API, web and full-stack browser jobs.

## Files and remaining work

[PHASE_2_FILES.txt](PHASE_2_FILES.txt) records the complete changed-file list relative to main. Changes are concentrated in Identity/Merchants modules, migrations, negative/integration tests, bilingual screens, OpenAPI/architecture/roadmap/security docs, development mail/proxy configuration and CI. No new application dependency declarations or lockfile changes.

Team management currently adds existing verified registered users; invitations and custom/multiple roles are later product scope. MFA enrollment/challenges, privileged platform administration and explicit audited support-access workflows remain unexposed. Production sender/domain, TLS/secure cookies/exact origins, restricted database roles, monitoring/retention and broader release/security review remain launch prerequisites. The development stack is not a production configuration. Phase 3 remains planned.

GitHub API requests return Forbidden in this environment, preventing PR creation/API verification unless access changes. A normal Git push is attempted independently; no force-push, branch deletion or main update is authorized or performed.

## Browser CI redirect correction

PR #1's default-route browser failure was reproduced without application changes using CI's public base URL, `http://127.0.0.1:8080`, and host-network Chromium. The original root handler constructed an absolute redirect from Next.js's internal `request.url`. An actual Nginx response was HTTP 307 with `Location: http://0.0.0.0:3000/en`; following it failed because only Nginx port 8080 is published. Playwright reported the original navigation URL in its ERR_CONNECTION_REFUSED message. Direct `/en` and `/ar` requests succeeded, Nginx configuration validation passed, and both Nginx/Next.js remained running with zero container restarts and no OOM kills. There was no test-triggered restart or transient outage.

The handler now returns HTTP 307 with relative `Location: /en`, preserving the browser's public host, scheme and port without trusting a forwarded Host. The existing default-route test additionally checks the unfollowed redirect response and exact final public URL. No tests were disabled; no startup, workflow, baseURL, webServer, timeout or retry settings were changed. Compose already owns the full-stack lifecycle, Playwright has one worker with isolated browser contexts, and CI cleanup runs after the browser step.

GitHub Actions logs/check status remain inaccessible from this environment: GitHub REST/GraphQL requests return Forbidden. Local reproduction establishes the defect independently; GitHub-hosted success requires checking the pushed commit's checks on PR #1.

Follow-up regression checks: 64 backend tests / 468 assertions with real PostgreSQL and Redis; 10 frontend component/client tests; 4 installer integrity tests; PHP style/Composer validation, frontend lint/TypeScript/production build, both dependency audits and Nginx/readiness/Horizon checks passed. Chromium runs use host networking with the exact CI baseURL, not a Docker service alias.

An initial unpaced `--repeat-each=3 --retries=0` stress run passed 16/18 tests; two signup flows correctly received HTTP 429 from the existing five-registrations-per-minute IP limit (trace Retry-After values 17s and 5s). All three default-route repetitions passed. Full-suite verification therefore runs separate cycles after the registration bucket's measured Redis end time. This changes neither application limits nor CI/test delays; the external verification harness respects the existing security limit instead of disabling it.

Final browser verification: **three complete cycles passed, six tests each, 18/18 executions with retries disabled** (28.4s, 27.5s, 27.7s). Each used `E2E_BASE_URL=http://127.0.0.1:8080`, `MAILPIT_URL=http://127.0.0.1:8025` and `node node_modules/@playwright/test/cli.js test --config=apps/web/playwright.config.ts --retries=0` inside the supported Chromium image with `--network host`. The same live stack served all three cycles without a restart or limiter reset. The only four changed files are the root handler, existing workspace E2E test, this report and the roadmap. No Phase 3 work, PR merge or production deployment.
