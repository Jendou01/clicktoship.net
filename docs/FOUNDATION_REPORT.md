# Phase 0 and initial Phase 1 completion report

## Scope delivered
* Phase 0: audited the empty repository and all four supplied references; recorded source provenance, Aramex evidence/conflicts, missing webhook documentation, module ownership, tenant/financial constraints, initial ERD/table catalogue, API conventions/OpenAPI, decisions, threat model, test strategy and detailed Phase 0–17 roadmap.
* Initial Phase 1: Laravel 12/PHP 8.3 API; Sanctum UUID token plumbing; Horizon workers with no dashboard routes; PostgreSQL/Redis; development Compose/Nginx; idempotent setup/start helpers; dependency-aware health checks and uniform safe API errors; bilingual Next.js/TypeScript/Tailwind shell; PHPUnit/component/Playwright infrastructure; GitHub Actions pipeline.
* No merchant auth flows, tenant enforcement, wallet/ledger implementation, pricing engine, carrier API, payment gateway, bulk shipping, webhook ingestion, store connector or production deployment. These are planned, not simulated.

## Validation evidence
The following checks executed on the current cloud machine. CI definitions are committed, but a GitHub-hosted CI run is not claimed. Fresh-machine restoration/publication is not verified.

| Check | Outcome |
|---|---|
| Reference source inspection and sanitized field inventory | Reviewed; no saved Postman responses, webhook spec/sample/email missing |
| Docker PHP runtime / extensions | Built with PHP 8.3 and PostgreSQL, pcntl, bcmath, intl, mbstring, zip |
| PostgreSQL and Redis health | Passed; isolated persistent development volumes; separate test database created |
| Frontend ESLint and TypeScript | Passed |
| Vitest components | 2 passed |
| Next.js production build | Passed, English/Arabic pages prerendered |
| Playwright with signed Debian Chromium | 3 passed through Nginx: language switching/RTL, default redirect/404 and mobile overflow |
| npm production audit | 0 vulnerabilities |
| Compose validation, shell/PHP syntax and JSON/YAML parsing | Passed (parsing is not full OpenAPI conformance validation) |
| Sensitive-source-value comparison | 0 matches across repository candidates; .env, dependencies and generated products ignored |
| PHP dependencies | 111 locked archives verified and installed; composer validate --strict passed |
| Installer integrity tests | 4 passed; wrong checksum/reference and corrupt ZIP rejected |
| Laravel Pint | Passed on all 37 PHP files |
| PHPUnit | 15 passed, 187 assertions; real PostgreSQL/Redis and separate-process queue execution |
| PostgreSQL migrations | All 4 migrations applied, rolled back and reapplied successfully |
| Setup repeatability | Existing DB credentials and APP_KEY preserved; no pending migrations |
| Full-stack startup/smoke | Passed through Nginx for API/schema/Redis readiness and English/Arabic pages |
| Horizon | horizon:status reports running; worker commands registered, dashboard routes absent |
| Composer vulnerability audit | BLOCKED: packagist.org/api/security-advisories returned proxy 403; no passing PHP audit claimed |

## Resolved environment issues
The host has no PHP/Composer; container runtime provides them. Container downloads needed the public managed-proxy CA supplied through an optional build secret. GitHub API archive URLs returned proxy 403; Git source fallback exhausted storage while cloning complete dependency histories. The corrected setup uses verified release archives from the permitted codeload host at exact locked commits, preserving any expected artifact checksums and leaving lockfile references unchanged. Browser CDN and official browser-image downloads were blocked; signed Debian Chromium supplies the test browser. Internal Docker browser requests require NO_PROXY/no_proxy for web/nginx. The real HTTP check also exposed Laravel serve dropping injected DB variables in environment-reload mode; --no-reload fixes this without copying the password into another file. Horizon initially lacked its application provider registration; this was corrected and a command-presence/no-dashboard regression test added. All final functional checks above pass. No TLS/signature/checksum verification was disabled, no additional user token was requested, and no carrier request was sent.

## Review and next work
Review architecture and Phase 1 before introducing business modules. Next is Phase 2 authentication, active merchant memberships, permissions, suspension and exhaustive cross-tenant negative tests. Resolve the external account/contract, webhook, tariff, PSP/legal and commerce documentation questions in parallel. Source attachments and credentials remain outside Git. Local-only commit/branch and PR status are reported in the delivery message; no production activation is authorized by this scaffold.

## Reusable environment configuration
Saved install_script and start_skill to the environment draft, plus the additive custom allowed domain packagist.org for Composer advisories. Existing package-manager presets remain. The exact saved installation script was executed successfully, followed by a successful saved startup/smoke run. Saving does not apply network policy, publish a snapshot or prove fresh-task restoration. Review/save in environment settings, then publish; after the policy is applied, rerun composer audit. Current foundation functionality works without carrier/payment credentials. Production security checks, SAST/secret scanning, account authorization and sandbox contracts remain later acceptance gates.

## File inventory
All project files are newly created because the repository was empty. See FILE_MANIFEST.md for the exact paths. Main areas: requested Phase 0 docs and AGENTS.md; apps/api (Laravel/config/migrations/tests); apps/web (Next.js/UI/i18n/tests); scripts (setup/start/smoke/archive verification); infrastructure/docker and infrastructure/nginx; root manifests/lockfiles/Compose; GitHub Actions CI. Dependencies, .env files, uploaded originals, runtime outputs and browser traces are excluded from Git.
