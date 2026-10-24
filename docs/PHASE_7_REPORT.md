# Phase 7 — Shipment management completion report

Date: 2026-10-24. Base: verified main merge `19289f88d0b76b330647bf09aad651b0f424efe0` (PR #5). Branch: `feat/phase-7-shipment-management`. Existing commits/code preserved; no automatic merge, production deployment, Phase 8, live shipping or real-money activation.

## Implemented modules and lifecycle

- Shipping: merchant-owned drafts, encrypted immutable revisions, optimistic edits, local validation, single-use quote acceptance, immutable submission identity, lifecycle events and operational exception flags.
- Drafts include country-aware sender/recipient/optional return address, contacts, bounded packages, manufacture country and international item/customs details. Local validation does not establish customs compliance or carrier readiness.
- Pricing owns quote acceptance: exact revision/full-input hash, canonical pricing-input hash, merchant/currency/service/expiry and single consumption. Every revision invalidates the active acceptance. The authorized price is fixed once dispatch starts; no silent extra charge.
- WalletBilling remains the only financial ledger. Reserve/intent/outbox commit together; protect before dispatch; persist carrier observation before capture/release. Capture failure retains accepted/capture_pending and retries local settlement only. Protected/unknown flags remain monotonic.
- Carriers: provider-neutral gateway/outcomes and operation coordinator reuse Phase 6 operation/attempt storage and capability registry. Default gateway cannot cross the evidence gate. Isolated autoload-dev synthetic adapter exercises acceptance, rejection, unknown/lost response and conflicts without any real Aramex response claim.
- Durable PostgreSQL outbox and delivery receipts, encrypted one-try queue job, `shipping:outbox` publisher and scheduled `shipping:recover`. Recovery works from PostgreSQL without Redis; stale dispatch markers become unknown, never another carrier-create attempt.
- Evidence-linked independent reconciliation appends effective resolutions without changing terminal carrier operations. Conflicting definitive evidence blocks approval. Financial hold resolution is an additive WalletBilling-owned extension of the existing engine, restricted to isolated test databases while accounting/carrier activation remains unapproved.
- Pickups: immutable request draft/window/timezone, same-merchant shipment associations, compatible origin/account checks, durable creation/cancellation commands, state history, safe pre-dispatch authorization failure, unknown quarantine. A local draft window is limited to 24 hours; actual carrier scheduling terms remain gated. Pickup cancellation never cancels a shipment or refunds money.
- Documents: private storage abstraction, bounded PDF input/checksum/metadata, quarantine/scanner boundary, integrity checks, revocation and audited authorized download with safe attachment/no-store/nosniff/sandbox headers. Default scanner rejects all content; real ingestion/download remains disabled. Revocation cannot lose a race to scanning.

## Migration and integrity

`2026_10_24_110000_create_shipment_management.php` installs `database/sql/phase7.sql`:

- shipments, shipment_revisions, shipment_validations;
- pricing_quote_acceptances, shipment_submissions, shipment_events;
- shipping_request_keys, shipping_outbox, shipping_deliveries;
- shipping_observations, shipping_reconciliation_requests/approvals;
- financial_hold_resolutions;
- shipment_documents;
- pickups, pickup_shipments, pickup_events, pickup_commands.

Composite merchant FKs bind revision, accepted quote, wallet/hold, carrier operation, observation, pickup and document relationships. Submission amount/currency must match the accepted quote and original hold. Historical tables are append-only. Ownership, frozen revisions, carrier outcomes and document transitions are guarded in PostgreSQL. Existing Phase 2 permissions/role IDs are preserved; additive grants support the new operations. Normal downgrade refuses retained history or grants. Disposable tests alone can tear down their history.

Normal development rollback/reapply preserved all existing user, merchant and journal counts. Isolated migration tests also verify rollback/reapply and refusal of destructive normal downgrade. No normal financial journal or synthetic shipment was created.

## REST API

20 added operations under `/api/v1`, documented with request/success/error resources in OpenAPI:

- `GET/POST /shipments`; `GET/PATCH /shipments/{shipment}`.
- `POST /shipments/{shipment}/validate`, `/quote-acceptance`, `/submit`, `/cancel`.
- `GET /shipments/{shipment}/events`, `/documents`.
- `GET /shipment-documents/{document}/download`; `POST .../revoke`.
- `GET/POST /pickups`; `GET /pickups/{pickup}`; `POST .../submit`, `.../cancel`.
- `GET /admin/merchants/{merchant}/shipping/exceptions`.
- `POST .../submissions/{submission}/reconciliations` and `.../reconciliations/{reconciliation}/approve`.

Normal submission returns503 before reservation. Local draft cancellation is supported; post-submission shipment cancellation remains unsupported. There is no force-release, arbitrary carrier response, raw upstream URL, direct balance edit or external retry endpoint. Existing OpenAPI paths and schemas were checked for semantic preservation.

## Bilingual screens

English and Arabic/RTL routes: `shipments`, `shipment-create`, `shipment-details`, `pickups`, `shipment-documents`, `admin-shipping`. Includes draft wizard, public quote review, operational/financial/document indicators, immutable history, pickup drafts/cancellation and exact-target administrative review. Real submission, labels and execution controls display disabled status truthfully. No simulated normal-runtime shipment success.

A shared CSPRNG request-key helper fixes existing pricing/finance failures on the private HTTP browser origin where `crypto.randomUUID` is unavailable. It uses Web Crypto `getRandomValues` without weakening randomness. Shipping retains draft/quote command keys for unchanged retries. Existing browser assertions/timeouts/security checks were retained. The obsolete foundation assertion that shipments were404 now correctly asserts authentication on the newly implemented resource; unreleased payment/dashboard assertions remain intact.

## Security and reliability

Current membership/RBAC and token scope intersection apply server-side. Workers retain actor auth version and exact originating token/session, rechecking authorization at the dispatch fence. Platform administration requires recent MFA, independently approved expiring session-bound exact merchant grants and current maker/checker authority; no Super Admin bypass. Reconciliation checker additionally needs financial reconciliation permission and cannot be the maker or a member of the target merchant.

Merchant-root locks, Pricing catalog locks and the existing platform-security lock serialize relevant decisions. No network call occurs inside a PostgreSQL transaction. Request payload conflicts, permanent business identities, unique quote/hold/submission associations and immutable financial resolution prevent duplicate effects. Unknown outcomes retain funds across expiry/restarts; a timeout is never proof of nonacceptance. Frozen/suspended settlement stays pending instead of bypassing financial guards. No exactly-once external delivery guarantee is claimed.

## Executed verification

| Check | Result |
|---|---|
| Full backend regression, real PostgreSQL/Redis | 217 passed; 2,219 assertions |
| Backend concurrency | Independent PostgreSQL process/barrier tests: duplicate submissions, overspend, token revocation, duplicate capture and release |
| Recovery/security | Rollback before commit, stale sending, lost response, Redis connection unavailable, frozen capture, suspension/revocation, unknown independent approval and maker revocation, conflicting evidence, IDOR, quote substitution/expiry, document revocation race and unsafe URL rejection |
| Frontend/component tests | 38 passed across 9 files |
| Playwright full stack | 16/16 passed in three complete successful cycles, including the final post-return-address cycle |
| Production Next.js build | Passed; TypeScript and 105 generated pages |
| ESLint / TypeScript | Passed |
| Laravel Pint | Passed |
| Composer validation/audit | Valid; no vulnerability advisories |
| npm audit, production and full dependency tree | Zero vulnerabilities |
| Python infrastructure tests | 4 passed |
| OpenAPI | YAML/local references/operation identifiers checked; prior paths/schemas preserved |
| Migrations | Isolated tests and normal development rollback/reapply passed; existing counts preserved |

An intermediate backend run was invalidated by accidentally overlapping two suites against the same disposable test database. It was discarded and the complete suite was rerun sequentially with the passing result above. Browser diagnosis also identified the private-HTTP Web Crypto issue and a missing stateful Origin/Referer in the new direct API test; both were corrected without delays, skipped tests or relaxed assertions.

## Remaining capability stops and launch decisions

No actual Aramex sandbox contract validation has occurred. Current V2 response/error/label/pickup schemas, authorized secure sandbox access, account/route/service entitlements and verified quote-service mapping remain required. Reference lookup, webhook authentication and post-submission shipment cancellation are not invented. Multi-package serialization and international payer/Incoterms/insurance/DG/COD support remain evidence/policy gates.

Real document storage region/provider, malware scanning, encryption/retention/access policy; suspended/frozen already-incurred obligation handling; conflicting-evidence resolution standards; qualified accounting/regulatory review and production least-privilege database/function-owner provisioning remain required. Labels, real carrier transmission, merchant funding, real charges, settlement and payments remain disabled. Normal reconciliation execution cannot accept synthetic proof.

## Git delivery

Feature branch: `feat/phase-7-shipment-management`, based on PR #5's verified merge. The delivery response supplies the exact commit and push verification. Git transport permits the feature-branch push, while GitHub REST API access currently returns Forbidden; PR creation and Actions verification may remain blocked. A prepared PR description is in PHASE_7_PR.md. Do not merge or enable external operations automatically.

## Files changed

The following manifest includes application, test and documentation changes in this feature branch:

- `README.md`
- `apps/api/app/Modules/Carriers/Application/ShipmentCarrierOperations.php`
- `apps/api/app/Modules/Carriers/Domain/ShipmentGateway.php`
- `apps/api/app/Modules/Carriers/Domain/ShipmentOutcome.php`
- `apps/api/app/Modules/Carriers/Infrastructure/EvidenceGatedShipmentGateway.php`
- `apps/api/app/Modules/Operations/Application/Administration.php`
- `apps/api/app/Modules/Pickups/Application/Pickups.php`
- `apps/api/app/Modules/Pricing/Application/QuoteAcceptance.php`
- `apps/api/app/Modules/Shipping/Application/Documents.php`
- `apps/api/app/Modules/Shipping/Application/Recovery.php`
- `apps/api/app/Modules/Shipping/Application/ShipmentReconciliation.php`
- `apps/api/app/Modules/Shipping/Application/Shipments.php`
- `apps/api/app/Modules/Shipping/Application/ShippingAccess.php`
- `apps/api/app/Modules/Shipping/Application/Submission.php`
- `apps/api/app/Modules/Shipping/Domain/DocumentScanner.php`
- `apps/api/app/Modules/Shipping/Domain/ShipmentInput.php`
- `apps/api/app/Modules/Shipping/Http/ShipmentController.php`
- `apps/api/app/Modules/Shipping/Infrastructure/DisabledDocumentScanner.php`
- `apps/api/app/Modules/Shipping/Jobs/ProcessShipment.php`
- `apps/api/app/Modules/WalletBilling/Application/ShipmentSettlement.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/config/horizon.php`
- `apps/api/database/migrations/2026_10_24_110000_create_shipment_management.php`
- `apps/api/database/sql/phase7.sql`
- `apps/api/routes/api.php`
- `apps/api/routes/console.php`
- `apps/api/routes/shipping.php`
- `apps/api/tests/Feature/ConcurrentShipmentTest.php`
- `apps/api/tests/Feature/FinancialMigrationTest.php`
- `apps/api/tests/Feature/HealthTest.php`
- `apps/api/tests/Feature/Phase3MigrationTest.php`
- `apps/api/tests/Feature/PricingTest.php`
- `apps/api/tests/Feature/ShipmentLifecycleTest.php`
- `apps/api/tests/Feature/ShipmentReconciliationTest.php`
- `apps/api/tests/Feature/ShipmentSecurityTest.php`
- `apps/api/tests/Fixtures/concurrent-shipping.php`
- `apps/api/tests/Support/ShippingTestCase.php`
- `apps/api/tests/Support/SyntheticShipmentGateway.php`
- `apps/web/src/app/[locale]/[screen]/page.tsx`
- `apps/web/src/components/finance-screen.tsx`
- `apps/web/src/components/management-screen.tsx`
- `apps/web/src/components/pricing-screen.tsx`
- `apps/web/src/components/shipping-screen.tsx`
- `apps/web/src/lib/request-key.ts`
- `apps/web/src/lib/shipping.ts`
- `apps/web/tests/e2e/shipping.spec.ts`
- `apps/web/tests/request-key.test.tsx`
- `apps/web/tests/shipping.test.tsx`
- `docs/DECISIONS.md`
- `docs/OPEN_QUESTIONS.md`
- `docs/PHASE_7_PR.md`
- `docs/PHASE_7_REPORT.md`
- `docs/ROADMAP.md`
- `docs/api/API_DESIGN.md`
- `docs/api/openapi.yaml`
- `docs/architecture/ARCHITECTURE.md`
- `docs/architecture/PHASE_7_SHIPPING.md`
- `docs/database/SCHEMA.md`
