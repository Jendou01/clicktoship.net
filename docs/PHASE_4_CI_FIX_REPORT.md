# Phase 4 CI correction — PR #3

Repository/branch: Jendou01/clicktoship.net, feat/phase-4-wallet-ledger. Investigated against delivered commit `0ac47f33d81337ecad736b0f570a875b470c7a2a`. No financial application logic, permissions, rate limits, workflow timeouts, migrations, live operations or production deployment were changed.

## Root causes established before editing

### Formatting

The configured Pint reproduced `method_argument_space` in FinancialLedgerTest.php. The final `provision($m,'KWD')` call lacked a space after the comma. Pint changed it to `provision($m, 'KWD')`; no test behavior or financial assertion changed. The delivered commit contained this error despite the earlier Phase 4 verification report; this correction checks the final source again.

### Initial browser attempt: premature pagination decision

The management page renders its H1 before its authenticated asynchronous directory load completes. `showMerchantCard()` awaited only the heading, immediately counted target cards, and could see zero cards while initial loading was still in progress. It then committed to `nextDirectoryPage()` instead of rechecking the loaded first page.

On a fresh CI database the target is on page 1 of 1. Once data finishes loading, Next is correctly disabled. The helper was incorrectly waiting for that disabled button to become enabled. The retained local E2E database had multiple pages and newer test organizations on later pages, which masked this premature navigation decision.

Reproduction used a new ephemeral PostgreSQL server on the private development network, the real unmodified schema and guarded testing/clicktoship_e2e fixtures, a distinct Redis prefix/cookie, and the real Nginx/Next.js/API/Horizon/SMTP stack. Existing development and retained E2E databases were preserved. Before any fix, the failing journey reproduced at merchant-directory.ts/showMerchantCard with a disabled Next button.

Real responses in that initial attempt included invitation creation201, invitation acceptance200, MFA challenge200, access request201 and independent approval200. The initial pre-MFA directory403 was expected and asserted. The failure was a test readiness/creation-order assumption, not a directory API pagination defect, backend authorization failure, service restart or missing merchant.

### Retry: reusing a consumed MFA recovery code

Playwright starts a new worker for a retry. The test rereads the same credential fixture file, so `.shift()` starts again with recovery code zero even though the backend already consumed it during attempt zero. Before the fix, retry #1 reproduced `POST /api/v1/auth/mfa/challenge ->422`; the UI correctly showed an error instead of “Changes saved”. No429 response occurred in this reproduction. Rate limits and single-use MFA enforcement were correct and remain unchanged.

## Small fixes

1. `showMerchantCard()` waits for the directory pagination navigation to be visible before checking for cards or deciding to advance. That navigation renders only after initial directory loading finishes. Existing exact-target and Next-enabled assertions remain.
2. The management journey uses `testInfo.retry` to select a different single-use admin recovery code per attempt. The finance journey uses its reserved code range `6 + retry` for the same reason. Existing worker/retry configuration remains unchanged (CI has at most one retry).
3. Pint fixes the one comma-spacing error.
4. A frontend test controls a delayed first-page response and proves the H1 can exist while navigation remains absent, then proves the one-page target loads with Next disabled. Two browser helper tests cover loaded first-page targets and actual two-page traversal without timers or sleeps.

No assertions were removed, no tests skipped, no arbitrary delays added and no permissions/security controls weakened. No fixture creation order was manipulated to force pagination. The fix supports both fresh one-page and retained multi-page directories.

## Retry-path diagnostic

A temporary, uncommitted diagnostic wrapper intentionally requested a retry only after the complete first journey had passed. The new worker then repeated the full real invitation/MFA/independent-approval/access journey successfully with the next recovery codes. Playwright labels that intentional probe flaky by design; it is not counted as a passing stability run. The wrapper and its generated empty host mount placeholder were removed before normal tests and are not part of the commit. Production tests contain no intentional failure.

## Changed files

- apps/api/tests/Feature/FinancialLedgerTest.php — Pint formatting only.
- apps/web/tests/e2e/merchant-directory.ts — authoritative initial-directory readiness wait.
- apps/web/tests/e2e/management.spec.ts — retry-specific MFA recovery codes.
- apps/web/tests/e2e/finance.spec.ts — retry-specific codes for the independent finance administrators.
- apps/web/tests/management.test.tsx — delayed first-page loading regression.
- apps/web/tests/e2e/merchant-directory.spec.ts — first-page and multi-page helper regressions.
- docs/PHASE_4_CI_FIX_REPORT.md and docs/PHASE_4_REPORT.md — correction evidence and final results.

## Verification

Final execution evidence and delivery status are appended after complete checks. GitHub REST/GraphQL log access returned Forbidden during investigation, so hosted failures were supplied by the user and independently reproduced locally; no claim is made that hosted logs were downloaded.

### Completed local verification

| Check | Result |
|---|---|
| Configured Laravel Pint | Passed, all 122 PHP files; only the reported comma spacing changed |
| Full backend financial/regression suite | 136 passed, 1266 assertions on real PostgreSQL/Redis |
| Financial concurrency/idempotency | Included and passed: overspend/duplicates, capture/refund races, suspension/MFA/credential/approval revocation and before/after-commit process-exit recovery |
| Frontend component/client suite | 23 passed, including the new deferred-directory regression |
| Playwright stability | Three complete 12-test cycles, 36/36, retries 0 and original timeouts |
| Browser coverage breakdown | Ten real full-stack journeys plus two DOM-only directory-helper regressions per cycle |
| Fresh-vs-retained data | Two cycles on the new isolated single-page database; one on retained E2E data with 50 merchants before the run, exercising real pagination |
| TypeScript / ESLint / Next production build | Passed |
| Composer strict validation / audit | Passed; no vulnerability advisories |
| npm production audit | Zero vulnerabilities |
| Installer integrity | Four passed |
| Runtime restoration | Normal development configuration restored; PostgreSQL/Redis/schema and EN/AR smoke passed, Horizon running |

No normal or retained E2E database was reset. The temporary PostgreSQL server used only for isolated synthetic reproduction was stopped/removed after testing. Build ran with the web process stopped to avoid shared .next writes; normal service was then restored. No financial data or logic was changed by this fix. The temporary intentionally retried diagnostic is excluded from 36/36 and does not exist in committed tests.

Delivery remains on the existing Phase 4 feature branch for PR #3. Git commit/push and hosted check access are reported after Git operations; no merge, production deployment or Phase 5 work.
