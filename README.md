# ClickToShip

Aramex-first, multi-carrier-ready shipping SaaS. **Current scope: Phase 0 architecture and initial Phase 1 foundation.** No merchant authentication flows, business shipments, real payments, carrier adapters or store integrations are implemented yet. No production deployment has occurred.

## Requirements
Docker Engine + Compose v2, Node.js 24/npm, Python 3 and ripgrep. PHP runs inside Docker: PHP 8.3, Laravel 12, Sanctum, Horizon and Predis. Next.js 16 uses TypeScript, Tailwind and bilingual routes. PostgreSQL 17 and Redis 7 have persistent local volumes; only Nginx exposes a loopback port. This Compose setup is development-only.

## Setup and startup
Use the existing isolated checkout; do not create a worktree unless requested.

```bash
cd /workspace/clicktoship.net   # or your actual checkout root
./scripts/setup.sh
./scripts/start.sh
```

Setup preserves existing .env files and application keys, generates a development DB password without printing it, installs locked dependencies, creates a dedicated test DB and runs migrations. Never replace a retained DB volume's password without a credential migration. Setup primes Composer's archive cache from GitHub codeload at exact locked commits when GitHub API ZIP downloads are restricted. The helper validates archive integrity, commit references and any supplied artifact checksums, with TLS verification enabled; it never rewrites lockfiles. This avoids cloning full dependency histories. Start launches API, Horizon, scheduler, web and Nginx, then checks actual API readiness and both language pages. Live processes must restart after restoring an environment snapshot. Laravel serve uses --no-reload so Docker-injected database credentials remain available to its child process; restart the API when environment settings change. Stop this project's services using `docker compose stop`; `docker compose down` preserves named volumes. Do not use `down -v` unless deliberately discarding local databases.

The development server routes are `/en` and `/ar`; API liveness `/api/v1/health/live`, readiness `/api/v1/health/ready`. Use internal HTTP checks in cloud onboarding, where localhost preview links are unsupported. HTTP_PORT in ignored root .env defaults to 8080; for custom ports set `SMOKE_BASE_URL` when running smoke.py. No PostgreSQL, Redis, API or Horizon port is published. Sanctum token plumbing exists, but no merchant authorization is claimed until Phase 2.

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

For browser tests against the production build (install Chromium once):

```bash
npx --workspace=@clicktoship/web playwright install --with-deps chromium
npm run test:e2e
```

If the cloud denies `cdn.playwright.dev`, the supported fallback installs Chromium through signed Debian packages. Start Compose first, then:

```bash
BUILDX_CONFIG=/tmp/clicktoship-buildx docker build \
  --secret id=cloud_ca,src=/usr/local/share/ca-certificates/environment-proxy-ca.crt \
  -f infrastructure/docker/browser.Dockerfile -t clicktoship-browser:dev .
docker run --rm --init --ipc=host --network clicktoship_default \
  -e NO_PROXY=nginx,web,localhost,127.0.0.1 -e no_proxy=nginx,web,localhost,127.0.0.1 \
  -e E2E_BASE_URL=http://nginx -v "$PWD:/workspace" clicktoship-browser:dev
```

`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` is optional and only selects an explicitly installed system Chromium; CI normally uses Playwright's matching bundled browser. Never claim unexecuted browser tests passed. See [execution report](docs/FOUNDATION_REPORT.md) and [test strategy](docs/TESTING.md).

## Repository map and design
* `apps/api`: Laravel REST API and PHPUnit infrastructure tests.
* `apps/web`: Next.js English/Arabic foundation shell, component/E2E tests.
* `packages/shared`: reserved for genuinely shared contracts; no empty build dependency.
* `infrastructure`: development containers, Nginx and DB initialization.
* `docs`: [architecture](docs/architecture/ARCHITECTURE.md), [schema/ERD](docs/database/SCHEMA.md), [API](docs/api/API_DESIGN.md), [OpenAPI](docs/api/openapi.yaml), [roadmap](docs/ROADMAP.md), [decisions](docs/DECISIONS.md), [Aramex evidence](docs/ARAMEX_INTEGRATION.md), [security](docs/SECURITY.md), [open questions](docs/OPEN_QUESTIONS.md).

Read [AGENTS.md](AGENTS.md) before changes. Raw uploaded source documents are intentionally outside Git because examples contain credential/PII fields; only provenance and field/type inventories are retained. Source dates/claims do not establish current service availability. Aramex resale authorization, route/account permissions, current API responses/webhook specification, financial policies and PSP/store documentation remain external prerequisites for later phases.

## Next increment
Phase 2: membership-derived tenant context, permissions, suspension, Sanctum authentication/reset/verification/token lifecycle and cross-merchant negative tests. Foundation does not activate shipping or payment by changing an environment flag. Production credentials and application keys belong in secure settings, never Git or NEXT_PUBLIC variables. Project licensing requires owner decision; framework components retain their original licenses.
