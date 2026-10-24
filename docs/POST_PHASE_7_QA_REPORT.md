# Post-Phase 7 QA and UI remediation

Date: 2026-10-24 (Asia/Riyadh). Base main `5e13ba7fd3294fcc889359b3566622a6500169ff` is the PR #6 merge, including `7f3f9c8`. Branch: `fix/post-phase-7-qa-ux`.

## Root causes and remediation

| Finding | Cause | Change |
|---|---|---|
| Retained E2E schema drift | Laravel records migration names, not checksums of referenced SQL. The retained E2E database had a Phase 7 migration row but lacked the decisive-observation column, its composite FK and submission-binding trigger. Re-running migrate does not repair already-recorded migrations. | Explicit disposable-only rebuild and mandatory read-only schema verification before fixtures/startup. Missing/disabled-object regression tests deliberately reproduce drift while preserving the migration row. |
| Missing shipment navigation and unauthorized admin links | Independent screens maintained separate hard-coded menus; the dashboard predates shipment management. | Shared branded workspace header, authorized merchant module links and separately grouped permission-filtered platform navigation. Backend authorization remains authoritative. |
| Generic draft errors | Shipping's catch handler discarded ApiError status/details. | Localized constraint hints, accessible field-error summary, inline input errors, invalid attributes and focus to the earliest invalid wizard step; retained input. Distinct session, permission and conflict messages. |
| Arabic missing-service label | Unknown field tokens fell back to the customs label. | Explicit service/manufacture/package/customs field mappings, numbered collection indices and neutral unknown-field fallback. |
| Shipment lost on locale switch/refresh | Selected detail existed only in React state. | Validated shipment UUID query context, authoritative tenant-authorized reload and locale links carrying only the shipment identifier. Grants, tokens, customer data and arbitrary query parameters never propagate. Merchant selection remains in the existing server session. |
| Inconsistent shell and no wallet locale control | Each module implemented its own frame. | Common logo, active merchant, organization switch, language control, section indicator, collapsible navigation and privileged-menu grouping. |
| RTL phone/reference layout | Direction-sensitive data inherited its surrounding direction. | Explicit LTR/isolation for contact phone/email/country/postal inputs and contact identifiers; bdi for displayed identifiers. |
| Mobile team actions offscreen | Desktop table required horizontal scrolling. | Mobile member cards retain all server-authorized role/status/removal actions and expose localized field labels. Desktop remains a table. |
| Stale availability copy | Foundation-era content was not revised with delivered modules. | Distinguish available accounts/teams/profiles/estimates/wallet statements/shipment drafts from disabled dispatch/funding/payments and future bulk/store modules. |
| UUID-only shipment directory | Directory returned only orchestration metadata. | Minimal tenant-scoped description/recipient summary and creation/revision/state context. No address, phone, cost, margin or carrier credential is added. |
| Sparse wallet/long admin lists/adjacent buttons | Missing explicit unpopulated states, no local filtering, inconsistent action spacing. | Honest unloaded/empty history text, search within loaded pricing/carrier configuration, collapsible platform menus and consistent spacing/touch targets. |

### Schema investigation and isolation evidence

Before repair, `prepare-browser-tests.sh` printed **Nothing to migrate** and then the new verifier exited nonzero for exactly the missing column, trigger and FK. Normal `clicktoship` passed. This proves migration-history/schema disagreement, rather than a failed current migration. The retained database is consistent with having applied an earlier development revision of the same SQL-backed migration. No historical SQL checksum was recorded, so the exact edit/operator that produced that state cannot be established retrospectively.

Executed the documented guarded process: `./scripts/prepare-browser-tests.sh --rebuild`. It stops E2E consumers, invokes a CLI command that refuses any combination except environment `testing` and database `clicktoship_e2e`, rebuilds that disposable schema, migrates, verifies integrity, recreates isolated synthetic fixtures, starts services and reloads Nginx. Normal startup and default E2E preparation never reset a database. Both normal development and the separate backend regression database are excluded from this reset command.

Normalized normal-development data dumps before and after the E2E rebuild match SHA-256 `a1f302c279014879279634ad93f5d9ad4a7bea6fe159ff0d5ac2caff73dff62e` (only pg_dump's random restrict markers excluded). The dumps are private temporary evidence, not committed/distributed. No normal-development data was changed or deleted.

`schema:verify` covers security-critical shipment/submission/observation columns, every declared Phase 7 trigger including dynamically installed immutable-history triggers, enabled status and the validated decisive-observation composite FK. It is a targeted integrity gate, not a claim to detect every possible manual database modification. It executes before browser fixtures; backend regression tests independently detect removed/disabled objects and a forbidden reset. No historical applied migration or financial posting rule was edited.

## Verification

| Verification | Result |
|---|---|
| Complete backend, real PostgreSQL/Redis | 222 passed; 2,240 assertions; 114.02 s |
| Schema integrity/reset-refusal tests | Passed; missing column/trigger and disabled trigger detected despite migration history |
| Migration rollback/reapply | Existing Phase 3–7 preservation/rollback regression tests passed in the isolated regression database |
| Complete frontend component/client suite | 59 passed in 11 files |
| Full Playwright suite | 17/17 passed twice consecutively (1.9 min and 1.7 min); fresh private fixtures each run |
| ESLint / TypeScript | Passed, no lint warnings; TypeScript also passed after the production build |
| Next.js production build | Passed; web development process stopped during build to prevent .next races |
| Laravel Pint | Passed, 196 PHP files |
| Composer validate --strict | Passed |
| Composer audit / npm audit (including development dependencies) | No vulnerabilities reported |
| Installer integrity tests | 4 passed |
| OpenAPI | YAML parses as OpenAPI 3.1.0; 122 schemas; additive directory summary documented |
| Responsive visual pass | 54 EN/AR desktop/mobile route views; 96 internal links; zero uncaught JS errors, unexpected HTTP failures or document overflow |
| Locale probe | Selected shipment retained in Arabic; browser test also verifies refresh and exact selected organization |
| Synthetic safety | E2E financial journals, holds, shipment submissions and carrier operations remain zero |

54 route-view captures plus seven focused regression screenshots are preserved as 61 after images, with 56 original before images. The offline gallery permits language/device filtering and full-resolution inspection. Some paired captures use different viewport sizes; no pixel-diff certification is claimed. Readiness endpoints returned HTTP 200. Five routine headers and two missing-service detail captures were explicitly recaptured after the authorized merchant context and main loading state resolved. The screenshot collector uses a separate opt-in synthetic preview account and respects actual API budgets; it never raises limits or adds application retries. Backend tests use real PostgreSQL `clicktoship_test` and Redis; browser work uses `clicktoship_e2e`, Mailpit and real local HTTP. No skipped tests or increased timeouts/delays. Synthetic carrier/payment adapters remain confined to guarded backend tests. No real carrier creation, funding or shipping charge was activated.

An early browser run found a new test selector matching Next.js's hidden route announcer; the validation alert now has a localized accessible name. Another navigation was aborted during development hot reload. Subsequent real-browser runs exposed duplicated shell context requests exhausting the unchanged per-user rate limit, a test sampling the organization placeholder before context loaded, and the new QA journey sharing the existing pricing/shipping fixture user’s request budget. The shell now reuses already-authorized screen context; personal/admin pages only resolve an actually selected membership; the test asserts the exact created organization; and the new QA journey has its own opt-in synthetic `qaOwner`. Field labels remain stable when inline errors appear. No throttling policy or timeout was loosened. Final browser verification is performed without editing application files. These exploratory failures are retained in local logs rather than reported as successful runs.

## Security and architectural boundaries

The shipment context UUID is an identifier, not authorization. Every reload goes through unchanged authenticated merchant-scoped APIs; no target merchant or grant is inferred from the URL. Navigation permissions improve discovery only and do not replace server checks. Directory summaries decrypt only current-tenant revisions, are escaped as React text and omit full addresses and contacts. Existing IDOR/token/suspension/finance/MFA/concurrency regression suites remain mandatory.

No migration changes, new dependencies, live gateway, financial engine changes, carrier-contract invention, production deployment or Phase 8 work. Unsaved form contents and privileged grant values are intentionally not serialized into locale URLs. Save a draft before changing language to carry its persisted contents.


## Files and review artifacts

[Complete changed-file manifest](POST_PHASE_7_QA_FILES.txt). Core changes are in the shared workspace navigation, shipping validation/context helpers and screen, responsive styles, minimal shipment directory read model, schema integrity checker/CLI and guarded browser setup. Existing finance/carrier execution logic is unchanged.

Local review artifacts: `/workspace/shared/clicktoship-qa/index.html`, `README.md`, `inspection.json`, `screenshots.json` and `clicktoship-qa.zip`. Download/extract the ZIP and open index.html. No public port-forward/preview-sharing tool is attached; these are downloadable inspection evidence, not a public deployment. Credentials, MFA/recovery codes, cookies, traces, scripts and private database dumps are excluded from the bundle.
