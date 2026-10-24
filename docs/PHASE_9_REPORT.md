# Phase 9 — Shipment tracking and webhook foundations

Base: `main`, verified PR #8 merge `3b301898f5fb197488a442814716bd274af695be`. Branch: `feat/phase-9-tracking-webhooks`. No merge, production deployment, Phase 10, live carrier request, wallet charge or customer notification.

## Implemented capabilities and boundaries

* Provider-neutral TrackingService, adapter/authenticator contracts, event normalizer/store/projector, reconciliation annotations, webhook processor, polling/recovery scheduler, health monitor and disabled notification outbox.
* Reuse Phase 6 carrier deliveries, immutable tracking events and the sole tracking projection. No parallel shipment-status table or financial ledger. Phase 7 decisive accepted submission/revision/operation/account identity binds references; Phase 8 imported drafts remain ordinary unsubmitted drafts.
* Tenant/account-specific encrypted reference matching and keyed digests, source/provenance/validation/correlation metadata, provider/receipt times and encrypted optional location/raw events. No raw provider payload or secret export.
* Conservative explicit transition graph: unknown codes never project; older events stay history; equal-time conflicts and invalid forward/terminal transitions create immutable review cases. Returned/cancelled cannot regress; delivered accepts only documented return flow. No fabricated timestamps/locations/POD.
* Durable encrypted PostgreSQL inbox/work, separate request/delivery/replay/event identities, atomic event/projection/case/outbox effects, leased identifier jobs, five-attempt bound, exponential backoff, shared account spacing/circuit readiness and Redis-loss recovery. Same-account reference reuse across tenants cannot link tenants; same-tenant collisions remain unbound and audited.
* Bilingual merchant overview, details/timeline, reference or shipment search, exceptions, last confirmed update, stale/no-data/unsubmitted/disabled states and refresh availability. Shared navigation and shipment detail links preserve only public shipment UUID across locales.
* Bilingual restricted administration for aggregate health, tenant-scoped redacted delivery/work/case diagnostics, mapping gaps, configuration gates and review-only annotations. Current session, recent MFA and independently approved expiring merchant grants remain mandatory; no Super Admin bypass.
* Disabled notification intents with explicit future consent and unique event/recipient identity. No channels, templates, sender or customer delivery. Retention hook counts candidates without deleting evidence.

## Carrier support and blocked functionality

The only runtime adapter is disabled. The provider-neutral pipeline is exercised using isolated synthetic adapters under autoload-dev and guarded testing databases, never shown as live merchant tracking. No real Aramex call or V2 response parser was added. Positive adapter flags cannot bypass the existing Phase 6 code-owned evidence gates.

Existing documented TrackShipments/TrackPickup request builders remain in Carriers. The blueprint's suggested header/example fields are not sufficient verified authenticity/replay/acknowledgement evidence. There is no invented Aramex HMAC, guessed event catalogue, externally enabled endpoint or scheduled live poll. Unknown shipment creation outcomes without a verified identity remain unbound; tracking cannot resolve them or authorize money movement.

## Database and API changes

Migration `2026_10_24_150000_create_tracking` adds 13 tables:

`tracking_bindings`, `tracking_endpoints`, `tracking_work`, `tracking_commands`, `tracking_event_details`, `tracking_attempts`, `tracking_cases`, `tracking_reviews`, `tracking_account_health`, `tracking_preferences`, `tracking_notifications`, `tracking_scan_cursors`, `tracking_receipts`.

Composite tenant/account/submission/event relationships, immutable histories/receipt/audit evidence, guarded projections/work identity, one active poll per binding and unique command/replay/event/notification identities protect retries and concurrent workers. Expanded carrier/status catalogues remain forward-compatible on downgrade; runtime retained tracking history or grants prevent downgrade. Only guarded disposable regression teardown strips new test grants after dropping referencing test tracking history. Existing rollback assertions are preserved behind removing additive Phase 9 first.

Eight documented OpenAPI paths:

| Method | Path | Authority |
|---|---|---|
| GET | `/api/v1/tracking/shipments` | tracking.read; optional tenant/account-scoped reference, shipment or exception filter |
| GET | `/api/v1/tracking/shipments/{shipment}` | tracking.read |
| GET | `/api/v1/tracking/shipments/{shipment}/timeline` | tracking.read; page size 50 |
| POST | `/api/v1/tracking/refresh` | tracking.refresh + current token/session; idempotency key; 1–50 shipment IDs |
| POST | `/api/v1/tracking/webhooks/{endpoint}` | separate code-verified provider authenticator; runtime disabled |
| GET | `/api/v1/admin/tracking/health` | platform.tracking.health + recent MFA; aggregate counts only |
| GET | `/api/v1/admin/merchants/{merchant}/tracking` | platform.tracking.view + recent MFA + independent target grant |
| POST | `/api/v1/admin/merchants/{merchant}/tracking/cases/{case}/reviews` | platform.tracking.review + same boundaries; idempotent immutable note only |

Merchant Owner/Admin/Shipping Manager/Operator receive tracking.read/refresh; Viewer gets read only. Platform Super Admin/Operations receive health/view/review, Support health/view; Finance has no implicit tracking access. Session mutations retain CSRF; only provider ingress uses the separate authentication boundary.

## Scheduling, recovery and operational limits

`tracking:schedule` and `tracking:recover` run every minute. Incremental backfill scans 100 records with PostgreSQL cursors; recovery republishes pending/expired leases. Default poll interval 900 seconds, account spacing 60 seconds, lease 90 seconds, job timeout 60 seconds, maximum five attempts and 24-hour stale warning. Polls are read-only; no shipment-create retry exists. The exact originating actor, auth version, token/session, membership and permission are rechecked before request and event commit. Merchant suspension, credential revocation, account-version changes and endpoint disable fence later effects. Scheduled polling remains blocked by code-owned provider evidence gates.

Ingress accepts bounded JSON only: 64 KiB, maximum 100 events, tenant/account-specific endpoint registration, verified authentication before retention and a 1,000-pending-delivery endpoint cap. Nginx enforces the body limit. Synthetic replay-window/signing examples are test-only contracts and must not be copied into an Aramex integration.

## Verification

| Check | Final executed result |
|---|---|
| Complete Phase 0–9 backend suite on PostgreSQL and Redis | **286 passed / 2,972 assertions**, 200.03 seconds |
| Complete frontend suite | **74 passed**, 13 files |
| Full-stack Playwright | **19/19 passed twice consecutively** after the default-grant regression fix; final run 2.2 minutes |
| Concurrent PostgreSQL workers | Duplicate-worker and credential-revocation commit fences passed |
| Redis loss / crash recovery | Real isolated Redis queue message removal/encrypted job payload; database-backed republishing and expired-lease recovery passed |
| Shipping/tracking outage isolation | Shipping acceptance/financial finalization remain committed while tracking storage is deliberately unavailable; later binding backfill succeeds without a carrier resend |
| Security | Forgery/replay/content limits, unknown/out-of-order/terminal/conflicting events, reference collisions, tenant/token/session/MFA/grant revocation, immutable history/projections and disabled notification uniqueness passed |
| Migration rollback/reapply and drift integrity | Passed; existing Phase 0–8 preservation assertions retained |
| Pint | **243 PHP files passed** |
| ESLint / TypeScript / production build | Passed; TypeScript also passed after build; 121 pages generated |
| Composer validation / dependency audits | Strict validation passed; Composer zero advisories and npm zero vulnerabilities, including development dependencies |
| Installer integrity | **4 passed** |
| OpenAPI | Eight paths correctly nested, no duplicate YAML keys, unique operation IDs, resolved local references, session-CSRF requirements and disabled provider ingress documented |
| Proxy request-size enforcement | >64 KiB rejected with HTTP 413 for both lowercase and uppercase endpoint UUIDs; Nginx configuration valid |
| Development startup | Restored; PostgreSQL/Redis/schema and EN/AR smoke checks passed; Horizon running |
| Normal development safety spot check | 17 migrations; zero tracking bindings/work/events, ledger journals, carrier operations and shipment submissions; no normal fixture seeding |

All prior Phase 0–8 tests remain enabled. No skipped test or weakened assertion is counted as validation. Browser captures show English desktop and Arabic mobile with no horizontal overflow. Synthetic processing tests do not certify an Aramex contract, live sandbox, notification sender or production load profile.

The first browser run caught an access-form regression: prepending tracking permissions changed its existing default from merchant profile read to tracking read. The resulting independently approved grant correctly failed merchant-profile authorization. The options now preserve the original default; a new component regression asserts both the default and explicit tracking-review capability payload. No permission check or assertion was weakened.

Other development findings covered by regressions: Laravel/PostgreSQL's limited-update compiler changes the reused query select to ctid, so the worker reload explicitly selects tracking_work.*; rollback must remove disposable referencing review history before stripping its test grants; first-class account/reference and Redis-loss tests reuse existing pricing configuration rather than publishing conflicting fixture city zones.

## Remaining launch requirements and limitations

1. Scrubbed current Aramex V2 success/error tracking responses, timestamp zones, event/status catalogue, provenance/confidence and contractually valid terminal/return/cancel mappings.
2. Verified webhook enrollment, authenticity/key-rotation, delivery/replay identity, replay window, retry and acknowledgement specifications. No interpretation of the blueprint's example Key/Value fields is trusted.
3. Authorized sandbox credentials and approved non-billable test scope, account/reference/region uniqueness, lookup guarantees, provider batching/rate limits and timeout behavior. Supply secrets through secure environment settings, never Git/chat.
4. Approved retention/deletion/PDPL policy, storage region and key rotation, production DB runtime privileges, monitoring ownership, capacity/backfill sizing and operational alert thresholds.
5. Notification recipient/consent/preferences, templates, channels and delivery/revocation controls. Intents are disabled; there is no customer sender.
6. Approved conflict-resolution evidence/dual-control standards before any manual projection override or dead-letter replay capability. Current administrative reviews are annotations, not resolution/activation. Unknown creates remain in the existing Phase 7 reconciliation and financial approval boundaries.

Normal development data was not reset or fixture-seeded. Only the empty new Phase 9 module was rolled back/reapplied during migration development. No sandbox/live integration, production-scale throughput, scanner, legal retention policy or notification delivery is certified by synthetic tests.

See [architecture](architecture/PHASE_9_TRACKING.md), [OpenAPI](api/openapi.yaml) and [changed-file manifest](PHASE_9_FILES.txt).

Tracking identity binding runs asynchronously from already committed Shipping evidence. Shipping code is unchanged: an outage in Tracking cannot roll back accepted carrier observations or trigger a resend. PostgreSQL observation backfill recovers missing bindings without Redis; an injected tracking-storage outage regression proves this boundary.
