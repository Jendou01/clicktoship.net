# ClickToShip

Protected disposable remote demonstrations use the **separate**, manually approved
[demo profile](docs/demo/REMOTE_DEMO_SETUP.md). They do not waive any staging or
production gate. No deployment is triggered by push. See its synthetic-only loss
policy, reviewed archive promotion, acceptance checklist and teardown procedure.

Aramex-first, multi-carrier-ready shipping SaaS. **Current scope: Phases 0–16 development foundations and pre-deployment QA**, including authentication/tenancy, merchant administration, a financial ledger, static pricing, evidence-gated carrier adapters, shipment/bulk drafts, tracking foundations, customs preparation bilingual merchant/platform dashboards and an evidence-gated store integration foundation. Live carrier operations, wallet funding, payments and customs clearance remain disabled. No production deployment has occurred.

## Requirements
Docker Engine + Compose v2, Node.js 24/npm, Python 3 and ripgrep. PHP runs inside Docker: PHP 8.3, Laravel 12, Sanctum, Horizon and Predis. Next.js 16 uses TypeScript, Tailwind and bilingual routes. PostgreSQL 17 and Redis 7 have persistent local volumes; Nginx and the development-only Mailpit inbox expose loopback ports. This Compose setup is development-only.

## Setup and startup
Use the existing isolated checkout; do not create a worktree unless requested.

```bash
cd /workspace/clicktoship.net   # or your actual checkout root
./scripts/setup.sh
./scripts/start.sh
```

Setup preserves existing .env files and application keys, generates a development DB password without printing it, installs locked dependencies, creates a dedicated test DB and runs migrations. Never replace a retained DB volume's password without a credential migration. Setup primes Composer's archive cache from GitHub codeload at exact locked commits when GitHub API ZIP downloads are restricted. The helper validates archive integrity, commit references and any supplied artifact checksums, with TLS verification enabled; it never rewrites lockfiles. This avoids cloning full dependency histories. Start launches API, Horizon, scheduler, web and Nginx, then checks actual API readiness and both language pages. Live processes must restart after restoring an environment snapshot. Laravel serve uses --no-reload so Docker-injected database credentials remain available to its child process; restart the API when environment settings change. Stop this project's services using `docker compose stop`; `docker compose down` preserves named volumes. Do not use `down -v` unless deliberately discarding local databases.

The development server routes are `/en` and `/ar`; API liveness `/api/v1/health/live`, readiness `/api/v1/health/ready`. Use internal HTTP checks in cloud onboarding, where localhost preview links are unsupported. HTTP_PORT in ignored root .env defaults to 8080; for custom ports set `SMOKE_BASE_URL` when running smoke.py. No PostgreSQL, Redis, API or Horizon port is published. Phase 2 uses CSRF-protected Redis browser sessions and merchant-bound Sanctum tokens. Mailpit captures verification/reset messages locally on port 8025; SMTP is private on the Compose network. Authentication mail may never use the log transport. For a custom HTTP port also configure SANCTUM_STATEFUL_DOMAINS to match the browser host:port. Restart Nginx after proxy configuration changes.

### Managed proxy trust
If container HTTPS downloads report a certificate-chain error, supply the **public** managed proxy CA as a build secret. Do not copy credentials or disable TLS verification. On the current cloud machine:

```bash
BUILDX_CONFIG=/tmp/clicktoship-buildx docker build \
  --secret id=cloud_ca,src=/usr/local/share/ca-certificates/environment-proxy-ca.crt \
  -f infrastructure/docker/api.Dockerfile -t clicktoship-api:dev .
```

Normal developer machines do not need that optional secret. `BUILDX_CONFIG` gives Buildx a writable cache when the home directory is read-only. A later Compose build reuses this image/layers. The cache helper uses only public GitHub distributions already present in composer.lock. Non-GitHub packages remain with Composer's normal downloader; private packages need their own supported authentication. No personal access token is required for these public dependencies.

## Verification
Run after setup, with PostgreSQL and Redis running:

```bash
docker compose run --rm api composer validate --strict
docker compose run --rm api vendor/bin/pint --test
docker compose run --rm api php artisan test
docker compose run --rm api composer audit
python3 -m unittest discover -s scripts/tests -v
npm run lint
npm run typecheck
npm test
NEXT_TELEMETRY_DISABLED=1 npm run build
npm audit --omit=dev --audit-level=high
python3 scripts/smoke.py
```

Backend tests force `clicktoship_test`, never the development database. Never point test credentials at production. Migration rollback/reapply can be verified with `php artisan migrate:rollback` and `php artisan migrate` in a disposable local database; rollback is destructive to the selected schema and is not a routine startup step.

Browser tests require the opt-in **isolated E2E stack**, including Horizon/Mailpit and synthetic test administrators (install Chromium once). Prepare it before running Playwright:

```bash
./scripts/prepare-browser-tests.sh
npx --workspace=@clicktoship/web playwright install --with-deps chromium
npm run test:e2e
```

If the cloud denies `cdn.playwright.dev`, the supported fallback installs Chromium through signed Debian packages. Start Compose first, then:

```bash
BUILDX_CONFIG=/tmp/clicktoship-buildx docker build \
  --secret id=cloud_ca,src=/usr/local/share/ca-certificates/environment-proxy-ca.crt \
  -f infrastructure/docker/browser.Dockerfile -t clicktoship-browser:dev .
docker run --rm --init --ipc=host --network clicktoship_default \
  -e NO_PROXY=nginx,web,mailpit,localhost, 127.0.0.1 -e no_proxy=nginx,web,mailpit,localhost, 127.0.0.1 \
  -e E2E_BASE_URL=http://nginx -e MAILPIT_URL=http://mailpit:8025 \
  -v /tmp/clicktoship-e2e-credentials.json:/tmp/clicktoship-e2e-credentials.json:ro \
  -v "$PWD:/workspace" clicktoship-browser:dev
```

`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` is optional and only selects an explicitly installed system Chromium; CI normally uses Playwright's matching bundled browser. Never claim unexecuted browser tests passed. See [execution report](docs/FOUNDATION_REPORT.md) and [test strategy](docs/TESTING.md).

## Repository map and design
* `apps/api`: Laravel REST API and PHPUnit infrastructure tests.
* `apps/web`: Next.js English/Arabic auth, onboarding, team and security screens, component/E2E tests.
* `packages/shared`: reserved for genuinely shared contracts; no empty build dependency.
* `infrastructure`: development containers, Nginx and DB initialization.
* `docs`: [architecture](docs/architecture/ARCHITECTURE.md), [schema/ERD](docs/database/SCHEMA.md), [API](docs/api/API_DESIGN.md), [OpenAPI](docs/api/openapi.yaml), [roadmap](docs/ROADMAP.md), [decisions](docs/DECISIONS.md), [Aramex evidence](docs/ARAMEX_INTEGRATION.md), [security](docs/SECURITY.md), [open questions](docs/OPEN_QUESTIONS.md).

Read [AGENTS.md](AGENTS.md) before changes. Raw uploaded source documents are intentionally outside Git because examples contain credential/PII fields; only provenance and field/type inventories are retained. Source dates/claims do not establish current service availability. Aramex resale authorization, route/account permissions, current API responses/webhook specification, financial policies and PSP/store documentation remain external prerequisites for later phases.

## Next increment
Review the Phase 13 store integration feature branch and [completion report](docs/PHASE_13_REPORT.md). Salla OAuth/API/webhook contracts, demo credentials and an approved callback remain unavailable; the runtime adapter is disabled. Existing carrier, financial, privacy and regulatory gates remain closed. Do not merge, deploy or start Phase 14 automatically.

## Phase 2 workflows

Use `/en/register` or `/ar/register`, verify email through Mailpit, create/select an organization, then open dashboard, team or security. Team additions require an existing verified account; there is no invitation acceptance flow. Security lists/revokes browser sessions and creates merchant-bound tokens after current-password confirmation. Raw API tokens are displayed once. No default users or platform administrators are seeded.

When CI runs Compose under a different user, export LOCAL_UID and LOCAL_GID to the runner's numeric IDs before setup. Stop the development web container before a production build to avoid concurrent writes to `.next`, then restart it. Tests fail before migrations unless the PostgreSQL database name is exactly `clicktoship_test`.

## Phase 3 workflows and test isolation

Merchant screens: `/en/business-profile`, `/en/addresses`, `/en/organization-settings`, `/en/business-verification`, `/en/invitations`, `/en/personal-profile`, `/en/mfa`, `/en/audit` and Arabic equivalents. Email-verified active merchants can use dashboards without business verification. Account status (active/suspended/closed), verification (unverified/pending/verified/rejected) and shipping eligibility are separate. Shipping is always unavailable in Phase 3. Closed accounts cannot reopen through the API; suspended accounts can reactivate. No documents are collected.

Platform screens: `/en/admin-merchants`, `/en/admin-access`, `/en/admin-users`, `/en/admin-audit`. A platform role alone never grants merchant-private access. All admin operations require confirmed MFA challenged within 15 minutes. Merchant-private operations additionally need a target/capability-specific grant approved by another MFA-authenticated Super Admin. Grants expire within 15 minutes of request and bind to the requesting browser session. No impersonation. The limited platform directory shows organization metadata; user lists omit names/email/contact data.

For initial platform setup, two independently controlled existing users must verify email and enroll MFA. An authorized operator then runs `docker compose exec api php artisan platform:bootstrap FIRST_USER_UUID SECOND_USER_UUID`. This one-time audited command refuses an existing platform assignment table and creates no account/password. It has not been executed against the normal development database by Phase 3 setup. Later built-in platform assignments require MFA-enrolled active verified targets, audited reasons and version checks. MFA recovery uses current password plus one-use backup codes; there is no administrative MFA-reset bypass.

`prepare-browser-tests.sh` uses `infrastructure/docker/compose.e2e.yml`, a separate PostgreSQL database named clicktoship_e2e, Redis prefix and session cookie. Default preparation preserves all databases and prepares synthetic accounts through a CLI fixture that refuses every database/environment except testing/clicktoship_e2e. Random test credentials are written only to `/tmp/clicktoship-e2e-credentials.json` with mode 0600, never Git or console. The browser runner reads this file; use E2E_FIXTURE_FILE to select another private path and mount it when running the supported container. Refresh fixtures before another complete cycle; this resets test-only MFA/recovery credentials, not application security policies. Existing public registration tests still obey real rate limits. Normal setup/start never seeds these accounts. Run `./scripts/start.sh` afterwards to restore the normal development API/worker configuration. Reload Nginx if replacing upstream containers outside these scripts.

Two new multi-screen/multi-browser Phase 3 journeys cover real SMTP, MFA and independent approval; all tests retain the default timeouts. Full execution evidence is in PHASE_3_REPORT.md.


## Phase 4 wallet workflows

`/en/wallet` and `/ar/wallet` show currency-specific posted/reserved/available balances, reservations, transaction history and sequence-bounded statements. Authorized browser users can open an empty SAR wallet; no artificial payment is created. `/en/admin-finance` and Arabic equivalent require recent MFA and independently approved target financial grants. Request wallet.view plus finance.correct or finance.reconcile through admin-access. Corrections have an additional independent maker/checker approval; execution stays disabled pending qualified accountant review. There is no direct balance edit or real payment route.

Run `docker compose exec api php artisan finance:outbox` and `finance:reconcile` for local durable monitor receipts and structural reconciliation. Scheduled equivalents run every minute/daily. They neither call a PSP nor release protected/unknown reservations. Migration owners must be able to provision the NOLOGIN cts_finance_runtime capability role; tests SET ROLE to verify direct money writes are denied. Full production application login/function-owner provisioning remains a launch gate.

## Phase 5 static pricing workflows

Merchant calculator/service discovery/history: `/en/rates`, `/en/available-rates`, `/en/quote-history` and Arabic equivalents. Pricing administration: `/en/admin-pricing`, `/en/carrier-costs`, `/en/merchant-pricing`, `/en/pricing-tiers`, `/en/shipping-zones`, `/en/pricing-fees`, `/en/pricing-rules`, `/en/pricing-audit`. Quotes are configured estimates, never verified carrier availability or wallet charges. Normal migrations seed no tariffs, services or tax policies.

Authorized platform personnel must enter evidenced service/zone/tariff/selling/tax definitions, then obtain independent publication with recent MFA. Merchant-specific configuration/private quote access also requires independent session-bound target grants. Currency is SAR only; amounts use integer minor units and percentages use basis points. Missing assumptions fail closed. [Calculation/security architecture](docs/architecture/PHASE_5_PRICING.md), [completion report](docs/PHASE_5_REPORT.md) and [OpenAPI](docs/api/openapi.yaml).

`./scripts/prepare-browser-tests.sh` also prepares a strictly isolated, clearly synthetic pricing fixture at `/tmp/clicktoship-pricing-e2e.json` (0600). It refuses normal development/production databases. Never commit, print or publish either browser credentials file. No live carrier API, shipping, funding or production deployment is enabled.

## Phase 6 carrier connector

Platform-only EN/AR screens: `carriers`, `aramex`, `carrier-services`, `carrier-capabilities`, `carrier-health`, `carrier-errors`, `carrier-webhooks`, `carrier-mappings`. Configure encrypted candidate master accounts/routes and obtain independent recent-MFA approval; approval never enables outbound calls or claims a successful sandbox connection. No merchant command route, webhook authentication invention, wallet effect or shipping eligibility activation.

[Evidence matrix](docs/integrations/PHASE_6_EVIDENCE.md), [architecture](docs/architecture/PHASE_6_CARRIERS.md), [report](docs/PHASE_6_REPORT.md). All current V2 responses/status/webhook contracts remain missing; legacy SOAP structure tests and request-only Postman shapes do not prove live integration. The normal transport is hard disabled. No carrier request or billable shipment has been sent.

## Phase 7 shipment management

Drafts, immutable revisions, quote acceptance, merchant shipment history, pickup drafts and EN/AR screens are available on main. Open `/en/shipments`, `/en/shipment-create`, `/en/shipment-details`, `/en/pickups`, `/en/shipment-documents`, `/en/admin-shipping` or Arabic equivalents. `shipping:outbox` publishes encrypted one-try jobs; scheduled `shipping:recover` recovers PostgreSQL work independently of Redis. The default gateway refuses before reserve; real carrier requests, shipping charges and labels remain disabled. Complete reserve/submit/capture/release/reconcile workflows are verified only with guarded synthetic adapters and isolated PostgreSQL test databases. See [architecture](docs/architecture/PHASE_7_SHIPPING.md) and [report](docs/PHASE_7_REPORT.md). No Phase 8 or production deployment.


## Disposable browser schema repair

Browser preparation now runs `php artisan schema:verify` after migrations and refuses to start fixtures against missing/disabled critical Phase 7 schema objects. Migration history alone does not establish schema integrity. If retained **disposable** browser fixtures have drifted, use the explicit documented rebuild:

```bash
./scripts/prepare-browser-tests.sh --rebuild
```

This destroys only synthetic `clicktoship_e2e` contents. The Laravel `e2e:rebuild` command refuses any environment/database except `testing`/`clicktoship_e2e`; do not substitute generic `migrate:fresh` against normal development. The script stops browser consumers, rebuilds, reapplies migrations, verifies schema, recreates private fixtures and starts the browser stack. Run `./scripts/start.sh` afterwards to restore the normal stack. Missing/disabled-column/trigger and reset-refusal tests are part of the backend suite. See [QA report](docs/POST_PHASE_7_QA_REPORT.md).

## Phase 8 — private bulk draft preparation

Merchant workspace routes `/en/bulk`, `/en/bulk-import`, `/en/bulk-results`, `/en/bulk-history` and Arabic equivalents prepare drafts from UTF-8 CSV/restricted single-sheet XLSX. Download a blank EN/AR template, choose CSV comma/semicolon/tab delimiter, upload privately, confirm mapping, review validation and explicit duplicate decisions, calculate public estimates and approve preparation. Acknowledgement is required for valid unpriced drafts. No import submits shipments, accepts a shipment quote, reserves a wallet, creates pickups/labels or activates payments.

Apply additive migrations with `docker compose run --rm --no-deps api php artisan migrate --force` before starting updated services. Existing development data is preserved; never run migrate:fresh against normal development. The existing isolated browser preparation migrates `clicktoship_e2e` and now also provisions one private synthetic bulkOwner fixture. Start/recreate Horizon so the new `bulk` queue is included; `bulk:recover` republishes durable PostgreSQL work after Redis loss. `scripts/start.sh` restores normal runtime after browser testing. Keep native PHP ZipArchive, XMLReader, intl, bcmath and sodium available (the published image provides them); no spreadsheet Composer dependency is added.

Default resource ceilings are 10MiB/file, 10,000 rows, 96 columns, 4KiB/cell, 256KiB/CSV record and 32MiB expanded XLSX. Work is bounded/chunked with permanent row keys and reauthorization at each commit. Original bytes/source payloads are private and encrypted. Local/testing structural inspection rejects active spreadsheet content; **production processing fails closed until approved malware scanning, shared storage and retention/deployment policies exist**. Never equate structural parsing with antivirus certification. Unsupported workbooks must be exported to the documented subset or UTF-8 CSV. [Architecture/runbook](docs/architecture/PHASE_8_BULK.md), [implementation report](docs/PHASE_8_REPORT.md), [OpenAPI](docs/api/openapi.yaml).

### Phase 9 tracking foundations

Arabic/English merchant tracking and restricted administrative diagnostics reuse the existing immutable carrier event store. Aramex polling, external webhooks and customer notifications remain disabled pending verified contracts and launch approval. `tracking:schedule` performs bounded incremental identity backfill; `tracking:recover` republishes durable PostgreSQL work every minute. `tracking:retention-candidates` counts candidates without deleting data. No tracking operation submits a shipment or changes money. See [Phase 9 architecture](docs/architecture/PHASE_9_TRACKING.md) and [completion report](docs/PHASE_9_REPORT.md).

### Phase 10 international/customs preparation

Structured customs data extend existing immutable shipment drafts, with independent readiness and versioned draft commercial invoice/packing-list PDFs. SAR remains the only enabled currency; values use exact minor units and no FX. English/Arabic screens include `/{locale}/international`, `customs-declaration`, `customs-products`, `commercial-invoice`, `packing-list`, `customs-readiness`, `customs-documents`, `international-history` and restricted `admin-customs`.

Run the existing `./scripts/start.sh` installation; it applies the additive migration and Horizon consumes the `customs` queue. `docker compose exec -T api php artisan customs:recover` republishes durable work; the scheduler runs recovery every minute. `docker compose exec -T api php artisan schema:verify` checks critical schema objects independently of migration rows. Use only the documented disposable test databases for rollback/rebuild tests.

PDF generation uses locked TCPDF 6.11.4 for native Unicode/Arabic layout, not an HTML renderer or uploaded PDF processor. The renderer only accepts typed text. Generated bytes are encrypted on private storage. Default scanning quarantines all documents, with no downloadable/public URL; production generation and download remain hard disabled. Independent evidence review never enables country rules, carrier service, customs clearance, DDP collection or charges. [Architecture](docs/architecture/PHASE_10_CUSTOMS.md), [results and limitations](docs/PHASE_10_REPORT.md), [OpenAPI](docs/api/openapi.yaml).

### Phase 11 merchant dashboard

`/en/dashboard` and `/ar/dashboard` compose real authorized operational counts, UTC date filters, current currency-specific wallet balances, bulk progress, customs readiness and recent activity. No data is seeded by normal startup and no real carrier/payment capability is enabled. `GET /api/v1/dashboard` and `/dashboard/activity` require current merchant authority and filter each source by existing permission/token scopes. Responses are private/no-store, with no shared cache. Six additive read indexes are installed through the normal migration process. Shared page headers/navigation and responsive bilingual components reuse existing module workflows. [Architecture and exact KPI semantics](docs/architecture/PHASE_11_DASHBOARD.md), [completion report](docs/PHASE_11_REPORT.md). Historical Phase 11 boundary; Phase 12 was separately authorized. No external deployment/preview work.


### Phase 12 platform administration

`/en/platform-overview` and `/ar/platform-overview` compose authorized merchant/user/shipping/bulk/tracking/carrier/pricing/finance/customs counts, UTC trends, redacted audit, current approval workload, derived alerts, safe health and a read-only settings catalogue. All new APIs require current browser sessions, role rechecks and recent MFA. Private merchant details and financial amounts still require independently approved expiring exact session-bound grants; aggregate visibility never grants private access. Finance totals are count-only; no global monetary disclosure policy is assumed.

The shared responsive platform navigation also wraps existing admin workflows. Only an immutable actor-specific alert-read receipt table and bounded aggregate permissions are added; no second ledger, carrier gateway or business system. [Architecture/source dictionary](docs/architecture/PHASE_12_PLATFORM.md), [scope/RBAC](docs/architecture/PHASE_12_SCOPE.md), [report](docs/PHASE_12_REPORT.md), [API](docs/api/openapi.yaml). Normal startup seeds no accounts or activity. Refresh disposable E2E fixtures before each full browser cycle; private credentials remain 0600 under /tmp. Historical Phase 12 delivery boundary; Phase 13 was subsequently authorized. No live activation or deployment.


### Phase 13 store integration foundation

`/en/store-integrations`, `/store-orders`, `/store-order?order=UUID` and `/store-syncs` (and Arabic equivalents) provide truthful connection state, scoped imported order inspection, explicit source/shipping review, single/bounded-batch draft preparation and durable progress. Merchant/dashboard and count-only platform-commerce monitoring reuse existing authorization/UI/module boundaries. Two additive migrations install12 integration tables, 6 permissions and an OAuth cancellation fence; normal startup seeds no store, account, order or financial activity.

**Live Salla remains unavailable.** All three requested official documentation URLs returned managed proxy 403. No Salla endpoint, OAuth mode/scope, wire field/status, signature/event or sandbox result is claimed verified. Default provider blocks all external calls. Internal normalized/synthetic tests prove architecture, not Salla compatibility. [Evidence/activation checklist](docs/integrations/SALLA_EVIDENCE.md), [architecture and ownership](docs/architecture/PHASE_13_COMMERCE.md), [report](docs/PHASE_13_REPORT.md), [OpenAPI](docs/api/openapi.yaml).

Store identities cannot move between merchants. Current sessions/token abilities/roles and connection generations fence every step; credentials/source snapshots/cursors/batch inputs encrypt at rest. Disconnect cancels pending/in-flight authorization intents and invalidates late refresh/sync work. Ambiguous rotating refresh requires reauthorization. Unresolved source conflicts cannot be overridden by a browser acknowledgement. Commerce invokes Shipping.importDraft only: no carrier submission, paid waybill, wallet charge, COD settlement, fulfillment write-back or notification. `commerce:recover` republishes durable work on the existing encrypted bulk queue. No deployment, tunnel, automatic merge or Phase 14.

## Phase 14 — Shopyzee Commerce foundation

The unified `/en/store-integrations`, `/en/store-orders`, `/en/store-order` and `/en/store-syncs` workspaces (and Arabic equivalents) now distinguish Salla and Shopyzee. Orders support an explicit provider filter and retain persisted provider/platform-instance/store identity. Merchant and platform dashboards reuse Commerce counts; platform visibility remains aggregate and requires existing recent-MFA authority. Shipment preparation continues through `Shipping.importDraft`, with immutable source links and no carrier/financial/fulfillment effects.

**Real Shopyzee connection, sync and webhooks remain unavailable.** All eight supplied official Xgenious plugin-guide pages returned managed proxy tunnel403. Actual deployed Shopyzee version/models/authorization/contracts are not available. No OAuth protocol, plugin manifest/base import, order API, webhook signature or plugin compatibility is invented. Synthetic adapters exist only in guarded isolated tests. The proposed separate connector is documented, not an installable production package. [Evidence](docs/integrations/SHOPYZEE_EVIDENCE.md), [connector design and missing source contracts](docs/integrations/SHOPYZEE_CONNECTOR_DESIGN.md), [architecture](docs/architecture/PHASE_14_SHOPYZEE.md), [report](docs/PHASE_14_REPORT.md).

Apply the additive migration through the documented setup (`docker compose exec -T api php artisan migrate --force`), then `php artisan schema:verify` in the API container. No normal data reset is needed. Provider ownership cannot be transferred by changing a domain or disconnecting. Downgrade refuses retained Shopyzee evidence instead of deleting it. Legacy orders API defaults to Salla; the unified UI explicitly requests `provider=all`.


## Phase 15 analytics and reporting

Merchant `/en/analytics` (and `/ar/analytics`) adds a source-permission-filtered catalogue, builder, shipment/financial/integration reports, operational insights and export history. Platform `/en/platform-analytics` requires a current browser session, recent MFA and per-source aggregate permissions. It grants no merchant-private access. [Metric dictionary](docs/analytics/METRIC_DICTIONARY.md), [report contracts](docs/analytics/REPORT_CATALOG.md), [architecture](docs/architecture/PHASE_15_ANALYTICS.md), [execution report](docs/PHASE_15_REPORT.md).

Periods are UTC, at most 366 inclusive dates. Row pages use 25-row UUID keysets; current balances are not historical. Amounts stay exact minor-unit strings with currency/exponent; no FX/revenue/profit claims. Reports create no shipping, financial, provider or notification effects.

CSV/XLSX orchestration is implemented but downloadable artifacts remain **disabled in every normal runtime** pending approved private storage, scanning and retention. An environment flag cannot open this gate. Tests use explicit isolated storage/scanner only. Do not configure a public disk or expose Horizon. Recovery runs through the existing scheduler (`analytics:recover`) and encrypted jobs use the existing Horizon bulk queue.

Verification: run the existing complete CI commands above, `docker compose run --rm --no-deps api php artisan test --filter=Analytics`, `docker compose exec -T api php artisan schema:verify`, and `./scripts/prepare-browser-tests.sh` before the complete Playwright suite. Only the explicitly disposable `clicktoship_test`/`clicktoship_e2e` databases may be reset; no normal-data resets. New browser analytics accounts are isolated, generated with private temporary credentials and never committed. See the execution report for exact results and remaining storage/accounting/evidence decisions.


## Phase 16 security, performance and recovery review

Read the [audit](docs/security/SECURITY_AUDIT.md), [threat model](docs/security/THREAT_MODEL.md), [benchmarks](docs/performance/BASELINE.md), [bounded load workflow](docs/performance/LOAD_TEST_PLAN.md), [isolated restore runbook](docs/operations/BACKUP_RESTORE.md), [readiness verdicts](docs/operations/STAGING_READINESS.md), [launch blockers](docs/operations/LAUNCH_BLOCKERS.md) and [execution report](docs/PHASE_16_REPORT.md). Local synthetic verification does not authorize deployment or live providers/money/documents/exports.

`php artisan security:check-config` checks non-development configuration without printing values; **failure is expected for the current development stack**. The staging example is inert and requires reviewed hosts/TLS/proxies, private secret injection and a restricted database login. It enables no external or financial operation. Exact allowed hosts fail closed outside local/testing. Configured logs retain event codes/correlation and discard raw messages/context/exception traces. API and CSRF responses are private/no-store.

Run `python3 scripts/qa/audit_repository.py`, the complete test/build commands above and the opt-in isolated QA workflows in TESTING.md. The first-party scan is not exhaustive external secret/CVE/image/provenance certification. GitHub Action revisions are pinned; declared lockfile licenses and locally observed image digests are inventoried for review. Production deployment, separate critical worker capacity, secure storage/scanning/retention, managed recovery and external evidence remain unapproved.

## Phase 17 deployment-readiness artifacts

Development setup remains unchanged. The standalone protected staging design is in [deployment setup](docs/deployment/STAGING_SETUP.md), [architecture](docs/deployment/ARCHITECTURE.md) and [security preflight](docs/deployment/SECURITY_PREFLIGHT.md). `infrastructure/deployment/compose.yml` is never an overlay on development; it publishes only loopback HTTPS and consumes protected external secret files. Release packaging is manual and has no remote deploy step. Actual VPS access/deployment needs separate exact-host authorization and verified backup/security gates. See [Phase17 report](docs/PHASE_17_REPORT.md); no live integration/payment/document/export capability is enabled.
