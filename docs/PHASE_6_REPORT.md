# Phase 6 — Aramex connector implementation and evidence report

Date: 2026-10-24 (Asia/Riyadh).

Repository: `Jendou01/clicktoship.net`.
Branch: `feat/phase-6-aramex-integration`.
Verified main base: `e3043d0ec4e2945ae90db491ca14fbd6fb797fba`, merge of PR #4, containing Phase 5 `e80d6b60877dd00e5fe076ca99aa99a8bac46b6d`. Existing code/history preserved; no automatic merge or production deployment.

## Outcome and limits

Implemented the independently testable, evidence-gated connector foundation. **No Aramex sandbox/production request has been sent and no live service is enabled.** Current V2 response/error/status contracts, secure authorized sandbox credentials and webhook authentication evidence are missing. The request-only Postman collection contains zero saved responses. Old SOAP tables are not treated as current JSON contracts. These specific capabilities are stopped safely; configuration, request preparation, persistence, security, queues, reliability tests, UI and documentation are delivered independently.

## Implemented services and components

* `CarrierAdapterInterface`, `CarrierRegistry`, `CarrierCapabilityRegistry`, `CarrierCredentialsManager`, `CarrierServiceMapper`, `CarrierResponseNormalizer`, `CarrierErrorNormalizer`, `CarrierTrackingStatusMapper`, `CarrierIntegrationHealth`, plus configuration/operation/inbox services and encrypted jobs.
* Documented request-shape building/validation for **CalculateRate, CreateShipments, CreatePickup (standalone), CancelPickup, PrintLabel, TrackShipments, TrackPickup and FetchCities**. These are request builders, not enabled carrier services. Unknown endpoints/operations fail explicitly.
* Explicit domestic/international country-route candidates and product mapping; bounded integer grams/millimeters; exact decimal KG/CM/declared-value formatting using existing currency precision; truthful required goods origin, address/contact/postal/state checks. No universal SA origin, tariff, FX, COD or route entitlement. Multi-package dimensional serialization and embedded/linking pickup variants remain blocked pending precise current contracts.
* Protocol-tagged response boundary: every current V2 JSON response is unverified and rejected. A separate **legacy manual structure parser** validates published envelope/per-item error/reference structures for shipment/pickup examples and strips labels/URLs/raw messages. It is not a live SOAP transport, a V2 rate normalizer or a sandbox verification claim.
* Immutable versioned encrypted master-account configuration with separate sandbox/production identities, candidate routes, independent current MFA approval/disable and optimistic draft/state counters. Secrets are write-only, never preloaded/exported/logged; active-version sandbox retrieval refuses production/historical/disabled versions. No runtime switch can enable external calls.
* Internal merchant/account-scoped operation intents, canonical input deduplication, unique correlation UUID, origin-token scope retention and one-try encrypted worker. The current evidence gate records `blocked` before any transmission. Persisted sending/crash markers are unresolved and can only quarantine to immutable `unknown`, never reset/blindly retried. No merchant execute/create HTTP endpoint or full shipment orchestration.
* Isolated transport readiness: fixed HTTPS sandbox path allowlist, TLS preserved, redirects blocked, 3s connect/15s request limits, response size bounds, correlation identifiers and safe error classes. Read retries bounded to three for network/429/5xx with short jitter; authentication/redirect/malformed responses are not retried. Uncertain mutations (including pickups) never auto-retry. Default runtime transport is hard disabled; a guarded autoload-dev test probe uses HTTP fakes only.
* Safe health/error monitoring and Redis cooldown/circuit readiness; carrier queues added to existing Horizon. No fabricated healthy/live connection state.
* Encrypted bounded internal raw-event inbox, canonical replay digest/conflict detection, duplicate suppression, append-only unknown events and separate provider-neutral out-of-order/terminal-return projection rules. Unknown Aramex raw codes remain `unknown`. Encrypted event jobs clear tenant context. **External webhook always returns503 and stores nothing** until real authenticity/replay/ack contracts exist; no invented HMAC/x-api-key protocol and no Phase 9 merchant tracking workflow.

## Supported versus blocked capability matrix

| Capability | Implemented/tested now | External/live status |
| --- | --- | --- |
| Eight documented operations above | Request validators/builders; registry/gates; generic reliability tests | Disabled: current V2 response and account/sandbox evidence missing |
| Shipment/pickup legacy response structures | Manual-derived synthetic structure tests, envelope/item error handling | No SOAP transport; never reused as JSON verification |
| Rate response normalization | Fail-closed current-response contract boundary, exact request money formatting | Successful current rate normalization blocked; no invented carrier cost |
| Country list/details, carrier address/postal validation, city suggestions, offices | Explicit unsupported/unverified capabilities | No guessed paths or fabricated responses |
| Tracking statuses/webhooks | Internal persistence/dedupe/ordering/queue foundation | External authenticity and real code mapping blocked |
| Unknown-outcome reconciliation | Scoped journals, immutable unknown state and review-required flags | No reference lookup or definitive resolver invented |
| Production, billable shipment, wallet/merchant shipping actions | Explicit hard denials; no public command route | Disabled; no payments, eligibility, wallet or Phase 7 activation |

[Detailed capability/evidence matrix and source conflicts](integrations/PHASE_6_EVIDENCE.md).

## Database, API and UI

One additive migration: `2026_10_24_100000_create_carrier_connector`, SQL in `database/sql/phase6.sql`.

Tables: `carrier_accounts`, `carrier_account_versions`, `carrier_config_events`, `carrier_operations`, `carrier_attempts`, `carrier_health_records`, `carrier_webhook_deliveries`, `carrier_tracking_events`, `carrier_tracking_projections`. Services/routes are immutable account-version JSON candidates; capability/status evidence remains code-owned rather than granting verification through an admin-controlled table.

UUIDs, account/environment/version FKs, account+merchant+operation/event/delivery composite FKs, author/session composite FKs, independent configuration audit checks, immutable history/identity guards, local operation/event uniqueness and constrained state transitions. Existing identity/merchant/pricing/financial data is preserved; retained carrier history blocks normal destructive downgrade. Prior phase rollback tests first remove the one additive carrier migration.

Seven new OpenAPI operations (118 total, all previous111 preserved):

* GET `/api/v1/admin/carriers`.
* POST `/api/v1/admin/carriers/accounts`.
* PATCH `/api/v1/admin/carriers/accounts/{account}`.
* POST `/api/v1/admin/carriers/accounts/{account}/approve` and `/disable`.
* GET `/api/v1/admin/carriers/accounts/{account}/history`.
* POST `/api/v1/carrier-webhooks/aramex/{integration}` — deliberately disabled503.

OpenAPI additionally documents normalized **internal** request DTOs for all eight operations without exposing master-account shipment commands. All administration requires platform `.carriers.view/manage/approve`, current session and recent MFA. Initially granted only to Super Admin/Platform Operations; merchants receive no carrier privileges. Independent approval rechecks the maker's current authorization. Monitoring exposes only global safe configuration, correlation/error classes, not merchant/customer/credential/rate payloads; no tenant bypass is introduced.

Eight bilingual EN/AR/RTL/LTR pages: `carriers`, `aramex`, `carrier-services`, `carrier-capabilities`, `carrier-health`, `carrier-errors`, `carrier-webhooks`, `carrier-mappings`. Typed route/credential forms, explicit unavailable/connection-unverified states and safe audit/health/error views. No raw JSON editor, merchant create action or secret export.

## Executed verification

| Check | Result |
| --- | --- |
| Full backend, real PostgreSQL + Redis | **182 tests passed; 1,733 assertions**; all Phase 0–5 regressions plus20 new carrier tests |
| Contract/failure tests | All eight request shapes; validation/route/international/precision checks; separate legacy/V2 boundary; bounded read retry; authentication/redirect rejection; network/429 ambiguous mutations not retried; no actual HTTP sent by runtime adapter |
| Security/persistence tests | MFA/independence/stale maker/versions; encrypted secrets/no export; production/activation/URL injection denial; webhook503/no retention; tenant IDOR; origin-token revocation/job context cleanup; duplicate and changed replay; crash/unknown transition; event retention/order; no ledger effects; migration preservation |
| PostgreSQL process concurrency | Real observed lock wait; simultaneous identical commands create one operation; conflicting reuse returns409 |
| Frontend | **32 Vitest tests passed**, seven files |
| Full-stack Chromium browser | **15/15 in two consecutive complete runs**, retries disabled; existing financial/pricing/identity/management/browser suites retained |
| Formatting/static checks | Laravel Pint167 files; ESLint and TypeScript passed |
| Migrations | Upgrade and empty rollback/reapply passed on real PostgreSQL; existing phase preservation tests passed; normal development has zero carrier accounts |
| Dependency checks | Composer strict validate/audit passed; full npm audit zero vulnerabilities; no new dependencies |
| Installer regressions | Four Python integrity tests passed |
| OpenAPI | Parses, all local references resolve, unique IDs; all previous111 operation contracts/components preserved |

Next.js production build passed for both locales. The normal development runtime was restored; Nginx/API/PostgreSQL/Redis/schema and English/Arabic smoke checks passed, Horizon is running, and the normal carrier catalog is empty. The first new E2E diagnostic used a standalone API client without same-origin Sanctum browser headers and correctly received401; it was corrected to inspect the real authenticated UI response, with no authentication weakening, skipped assertion, timeout increase or arbitrary delay.

## Blockers and next evidence

1. Current V2 request/response/error schemas and scrubbed successful/failed fixtures, rate decimal/currency contract, tracking codes/timestamps and service limitations. No successful current response is fabricated.
2. Secure, explicitly authorized sandbox credentials plus non-billable contract-test authorization/policy. Historical sample credentials are not authorization. **Sandbox contract tests: not executed.**
3. Resale/master-account/route/payer/product/customs/COD/remote/payment entitlements; current API limits and reference/idempotency guarantees. No universal domestic/international availability.
4. Real webhook authentication/rotation/replay/event-ID/ack specification and registration; raw-event retention/erasure and label private storage/scanning/access/retention policy.
5. Production endpoint, restricted runtime secrets/DB roles/key rotation/operations/legal/financial launch approval. Production stays disabled and no deployment occurred.
6. Operational limits: account/history lists bounded100; large-catalog pagination/performance and provider retry/circuit tuning need verified operating terms. Full send/finalize/reconciliation, merchant shipments/wallet orchestration, bulk and full tracking remain later approved phases.
7. GitHub API/GraphQL currently returns **Forbidden**; PR creation and CI inspection are attempted after push. Git transport is separate; no Actions result is claimed without access.

Architecture/security/reliability details: [Phase 6 architecture](architecture/PHASE_6_CARRIERS.md). No automatic merge, production deployment or Phase 7 implementation.

## Files changed

54 files:

- `README.md`
- `apps/api/app/Modules/Carriers/Application/CarrierAdministration.php`
- `apps/api/app/Modules/Carriers/Application/CarrierCredentialsManager.php`
- `apps/api/app/Modules/Carriers/Application/CarrierErrorNormalizer.php`
- `apps/api/app/Modules/Carriers/Application/CarrierIntegrationHealth.php`
- `apps/api/app/Modules/Carriers/Application/CarrierOperations.php`
- `apps/api/app/Modules/Carriers/Application/CarrierRegistry.php`
- `apps/api/app/Modules/Carriers/Application/CarrierServiceMapper.php`
- `apps/api/app/Modules/Carriers/Application/CarrierTrackingInbox.php`
- `apps/api/app/Modules/Carriers/Application/CarrierTrackingStatusMapper.php`
- `apps/api/app/Modules/Carriers/Domain/CarrierAdapterInterface.php`
- `apps/api/app/Modules/Carriers/Domain/CarrierCapabilityRegistry.php`
- `apps/api/app/Modules/Carriers/Domain/CarrierFailure.php`
- `apps/api/app/Modules/Carriers/Domain/CarrierTransport.php`
- `apps/api/app/Modules/Carriers/Domain/TrackingOrder.php`
- `apps/api/app/Modules/Carriers/Http/CarrierController.php`
- `apps/api/app/Modules/Carriers/Infrastructure/Aramex/AramexAdapter.php`
- `apps/api/app/Modules/Carriers/Infrastructure/Aramex/CarrierResponseNormalizer.php`
- `apps/api/app/Modules/Carriers/Infrastructure/DisabledAramexTransport.php`
- `apps/api/app/Modules/Carriers/Infrastructure/HttpCarrierTransport.php`
- `apps/api/app/Modules/Carriers/Jobs/ProcessCarrierOperation.php`
- `apps/api/app/Modules/Carriers/Jobs/RetainCarrierEvent.php`
- `apps/api/app/Modules/Merchants/Domain/MerchantContext.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/config/horizon.php`
- `apps/api/database/migrations/2026_10_24_100000_create_carrier_connector.php`
- `apps/api/database/sql/phase6.sql`
- `apps/api/routes/api.php`
- `apps/api/routes/carriers.php`
- `apps/api/tests/Feature/CarrierContractTest.php`
- `apps/api/tests/Feature/CarrierSecurityTest.php`
- `apps/api/tests/Feature/ConcurrentCarrierTest.php`
- `apps/api/tests/Feature/FinancialMigrationTest.php`
- `apps/api/tests/Feature/Phase3MigrationTest.php`
- `apps/api/tests/Feature/PricingTest.php`
- `apps/api/tests/Fixtures/concurrent-carrier.php`
- `apps/api/tests/Support/CarrierTestCase.php`
- `apps/web/src/app/[locale]/[screen]/page.tsx`
- `apps/web/src/components/carrier-screen.tsx`
- `apps/web/src/components/management-screen.tsx`
- `apps/web/src/lib/carriers.ts`
- `apps/web/tests/carriers.test.tsx`
- `apps/web/tests/e2e/carriers.spec.ts`
- `docs/ARAMEX_INTEGRATION.md`
- `docs/DECISIONS.md`
- `docs/OPEN_QUESTIONS.md`
- `docs/PHASE_6_REPORT.md`
- `docs/ROADMAP.md`
- `docs/api/API_DESIGN.md`
- `docs/api/openapi.yaml`
- `docs/architecture/ARCHITECTURE.md`
- `docs/architecture/PHASE_6_CARRIERS.md`
- `docs/database/SCHEMA.md`
- `docs/integrations/PHASE_6_EVIDENCE.md`
