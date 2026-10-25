# Phase 13 — Salla store integration foundation

## Delivery and evidence boundary

Implemented on `feat/phase-13-salla-integration`, based on verified Phase 12 PR #12 merge `35400617214e3da08d6bfef29434cc30832c13db`. Fetched main explicitly because the published checkout's fetch refspec only updated origin/HEAD, then fast-forwarded local main and created the dedicated feature branch. Existing code and commits are preserved.

**This is a tested internal architectural foundation, not a verified/live Salla connector.** The three requested official documentation URLs returned managed network `403 Forbidden`. No Salla endpoint/base URL, OAuth app mode/scope, wire field/status/fulfillment policy, token rotation promise, event/signature/replay/ACK contract or actual sandbox result is verified. There is no registered demo app, authorized credentials or approved reachable HTTPS callback. The runtime Salla provider is code-disabled, the API reports unavailable, and Connect/Sync are disabled in the UI. No URL, scope or live success was fabricated. [Exact evidence and setup checklist](integrations/SALLA_EVIDENCE.md).

The generic internal OAuth/token/order/signal interfaces and isolated SyntheticStoreProvider fixtures demonstrate ClickToShip behavior; they are deliberately NOT presented as official-contract-based Salla response/signature fixtures. External mapping/integration tests remain blocked until real evidence is supplied. This implements the independent work authorized by the request's unavailable-documentation fallback.

## Implemented functionality

* Commerce owns permanent provider/store identity and tenant relationships, encrypted current credentials, cancellable one-use session-bound authorization intents, minimized encrypted immutable normalized order snapshots, durable sync/checkpoints/leases, authenticated signal receipts and source-order/draft links.
* Generic server-side authorization lifecycle checks exact redirect/state/current actor-session-merchant, consumes state before exchange, verifies provider-returned identity and serializes ownership collisions. Tokens never serialize to browser responses. Refresh is lease/version/generation-protected; rotating-token ambiguity requires reauthorization. Disconnect removes local credentials, blocks work and cancels pending/in-flight callbacks, including a response arriving after disconnect. No ownership transfer or silent reassignment.
* Bounded manual/background sync commits each normalized page atomically with its cursor receipt; checkpoint advances only at complete chain. Deduplication, cursor-cycle detection, classified rate-limit/temporary/auth/scope/payload failures, bounded retry/crash recovery and originating credential rechecks use real PostgreSQL/Redis/Horizon patterns. Network I/O holds no database locks. Recovery uses the existing bulk queue and `commerce:recover` scheduler entry.
* Scoped order directory/detail, store/status/UTC-source-date/exact-reference filters and 25 UUID-keyset pagination. References are blind-indexed; credential/PII/raw response bodies are not logged. Raw webhooks are not stored. Historical observations remain immutable; late/equal/undated conflicts do not silently overwrite current data. Unresolved conflicts cannot be bypassed by acknowledgement.
* Explicit current snapshot/version/preview review invokes the existing Shipping.importDraft boundary under Catalog/merchant/current-origin fences. Request replay and permanent business identity prevent duplicate drafts across actors/retries/crashes. Existing drafts are retained, never overwritten or cancelled when source data changes. Source amount/currency/COD cannot be changed in the review. Missing/unsupported/unknown payment/fulfillment/COD/currency/address/parcel/service data fail closed.
* Bounded25-order same-store batch preparation supports individual previews, explicit confirmation, encrypted durable inputs, per-row outcomes, partial failure reporting and safe corrected retries. No automatic carrier submission, quote acceptance, paid waybill, wallet debit, COD settlement, source fulfillment write-back or customer notification.
* Webhook foundation is closed before provider parsing in normal runtime. The internal verified-signal boundary requires exact store/event authenticity/replay verification, deduplicates/collision-checks receipts, quarantines unknown kinds and can revoke locally or request an authorized reconciliation read. It never creates drafts. The synthetic HMAC test protocol is not a Salla authenticity implementation.

[Complete ownership, transaction, security, source and limit definitions](architecture/PHASE_13_COMMERCE.md).

## Permissions, UI and platform boundaries

Five merchant permissions: integrations.read/manage/sync and orders.read/prepare. Owner/Admin receive all; Shipping Manager gets integration/order reads, sync and preparation; Shipping Operator gets order read/preparation; Viewer gets reads; Finance receives no customer-order data by default. shipments.write and orders.read are independently required for draft effects, intersected with current token abilities. Current membership/user/merchant/session/auth-version/role checks apply at each request and work commit; no platform role establishes a merchant context.

New platform.metrics.commerce provides counts only to Super Admin/Operations/Support under existing current browser session/recent MFA security. Verified and synthetic connections are counted separately. There is no private order/token dump or automatic target access; no provider-specific private support grant route was invented. Existing independently approved exact merchant grants in other modules are preserved.

Four bilingual merchant screens: `/store-integrations`, `/store-orders`, `/store-order?order=UUID`, `/store-syncs`; Arabic and English equivalents. They provide clear blocked connection/permission state, safe order review, source warnings, genuine origin/parcel/service inputs, explicit recipient corrections, batch preview/progress and synchronization history. Settings/navigation, merchant dashboard and the new count-only `/platform-commerce` integrate existing Phase 11/12 primitives. RTL values/IDs/phones are isolated. Locale switches retain safe internal identifiers, never tokens/codes/PII. Generation fences discard late previous-merchant responses; review edits invalidate previews. No frontend dependencies added.

## Database and API

Two additive migrations:

1. `2026_10_25_120000_create_commerce`: 12 tables (connections, credentials, authorizations, orders, snapshots, runs, pages, draft_links, results, commands, events, receipts), composite merchant foreign keys, global store identity uniqueness, permanent order/draft uniqueness, one-active-run uniqueness and immutable history/identity triggers.
2. `2026_10_25_121000_fence_commerce_authorizations`: cancellation evidence and pending-intent index. Applied additively; normal history was never reset or manually rewritten.

Six permissions are seeded into existing built-in roles. SchemaIntegrity verifies actual tables, enabled history/identity triggers, cancellation column and valid indexes independently of migration history. Guarded rollback/reapply and cross-merchant FK tests pass; retained normal history prevents downgrade. Normal development has 22 applied migrations, zero browser-fixture accounts/connections/orders/shipments/ledger transactions/carrier operations; no normal data was seeded.

| Method | /api/v1/commerce suffix | Authority / behavior |
|---|---|---|
| GET | /providers | integrations.read, truthful unavailable capability catalogue |
| GET | /connections | integrations.read, bounded metadata, no secrets |
| POST | /salla/authorize | integrations.manage + current browser session; closed runtime gate |
| GET | /salla/callback | exact one-use current-session state; closed runtime gate |
| POST | /connections/{id}/disconnect | integrations.manage, version/request key; local revocation only |
| POST | /connections/{id}/sync | integrations.sync, request key; closed runtime provider gate |
| POST | /connections/{id}/prepare | orders.prepare + orders.read + shipments.write, at most25 reviewed orders |
| GET | /connections/{id}/events | integrations.read,25 scoped metadata events |
| GET | /orders | orders.read, bounded filters and keyset |
| GET | /orders/{id} | orders.read, current snapshot/link/history metadata |
| POST | /orders/{id}/preview | explicit current snapshot/version/review; local readiness only |
| POST | /orders/{id}/draft | prepare/read/shipping authority, preview hash/request key and permanent business link |
| GET | /runs | integrations.read,25 bounded run metadata records |
| GET | /runs/{id} | integrations.read, or orders.read for own prepare run only |
| POST | /runs/{id}/retry | integrations.sync, exact position/key/current origin; cannot bypass Retry-After |
| POST | /webhooks/salla | default503; no official authenticity contract claimed or enabled |

All private responses are no-store and tenant-derived. OpenAPI 0.13.0 describes 16 new paths/operations, exact request constraints/errors/idempotency and modified dashboard source shapes. Total 180 paths / 205 unique operations. See [API contract](api/openapi.yaml).

## Executed verification

| Check | Result |
|---|---|
| Full backend regression, real PostgreSQL/Redis | **382 passed / 4,368 assertions**, final 356.72 seconds; all prior Phase 0–12 cases retained |
| New Commerce backend coverage | 35 tests: lifecycle/security/normalization/recovery/drafts/schema and actual independent-process refresh/draft races |
| Frontend component/unit | **110 passed**, 17 files, including 8 new Commerce cases |
| Full Playwright | **26/26 passed twice consecutively**, 3.2 and 3.1 minutes |
| Browser integration | Real local API + Horizon single draft and valid/incomplete batch outcomes; source/callback/Salla API remain synthetic/unavailable |
| Migration/schema | Fresh install, actual trigger/column/index drift checks, safe disposable down/up and prior rollback preservation checks passed; 22 normal migrations |
| Pint | 311 PHP files passed |
| ESLint / TypeScript | Passed, no rule suppression or weakened checks |
| Next.js production build | 181 generated pages, passed |
| Composer strict / audit | Valid; no security advisories |
| npm full and production audits | 0 vulnerabilities |
| Installer/archive verification | 4 tests passed |
| OpenAPI | Unique keys/operation IDs, local refs and schema/route checks; no official Salla contract validation claim |
| Visual QA | 34 EN/AR desktop/tablet/mobile views and 34 nonempty screenshots; no runtime errors, failed API requests or horizontal overflow |

Independent PHP workers prove one credential rotation while a competitor receives REFRESH_BUSY, and exactly one draft/link across simultaneous commands. Additional tests inject a crash after page insertion before checkpoint, verify atomic rollback/recovery, revoke role/token/session/merchant authority during I/O, disconnect during refresh/OAuth, reject wrong store/current source/CSRF/IDOR, enforce immutable/FK/schema constraints and preserve zero financial/carrier effects. There are no skipped tests or real external Salla/carrier calls.

Verification found and corrected select-label ambiguity (explicit accessible labels), shared browser-account throttling (isolated actors, unchanged rate limits), and status text targeting (shared status badge). Existing test assertions were not removed; prior migration setup now removes both additive migrations before its original targets. No arbitrary delays or timeout increases.

The existing 2,000-merchant platform benchmark now includes Commerce aggregates: 71 fixed queries, 27.63 ms local HTTP-handler time, 13,052-byte response and 0.519 ms SQL plan. Query count remains constant. This is not a large Salla-order import/load test or a production capacity/SLA claim. Sync limits are ClickToShip internal: 50 orders/page, 512KiB response, 10 pages/500 records per automatic segment, explicit continuation and 1,000-record ceiling; batch preparation max 25. Official Salla limits remain unverified.

## Visual evidence and changed files

Used only isolated clicktoship_e2e actors with random private 0600 credentials, clearly labeled synthetic stores and normalized orders. Source-derived operational counts in screenshots are development observations, not real Salla/carrier/financial activity. Normal development services were restored after verification; API/EN/AR/schema smoke, PostgreSQL, Redis, Horizon and scheduler remain available. No new port, preview/tunnel or external deployment.

Evidence: [handover/screenshots](/workspace/shared/clicktoship-phase13/README.md), [English integrations](/workspace/shared/clicktoship-phase13/en-desktop-store-integrations.png), [Arabic mobile order](/workspace/shared/clicktoship-phase13/ar-mobile-store-order.png), [inspection metadata](/workspace/shared/clicktoship-phase13/inspection.json). [Changed files](PHASE_13_FILES.txt); [prepared PR description](PHASE_13_PR.md). Actual commit/push/PR/Actions status is recorded in the completion message and external handover README, avoiding a self-referencing commit hash in this report.

## Remaining approval/activation blockers

* Obtain current official Partners/OAuth/order/webhook documentation, approved app mode/scopes/field/status mappings and dated evidence. All requested documentation was denied by the network; no substituted third-party/remembered contract was used.
* Legitimately register/approve a demo application, obtain authorized non-production credentials and an exact approved reachable HTTPS callback; perform real authorized sandbox tests before any live connection claim. No working callback URL or production credentials were invented/requested.
* Approve standing automated sync authority/consent; current session/token expiry intentionally blocks unattended work. Verify provider revoke/uninstall/event/replay/retry/ACK behavior and credential/key rotation before external activation.
* Resolve real-data minimization, retention/redaction/deletion/backups and blind-index/key rotation; no arbitrary erase/export control. Ownership transfer and provider-specific private platform support permissions remain unavailable pending policy.
* Establish authoritative source-conflict resolution and missing international item/customs data through approved workflows. The initial UI covers explicit ordinary parcel review; complete typed international API data still requires existing Customs authority/readiness. A browser acknowledgement cannot resolve uncertain source truth. COD-positive, non-SAR and unknown payment/fulfillment remain blocked.
* No source fulfillment write-back, carrier request, paid waybill, wallet funding/debit, COD settlement, payment, customer notification, production readiness or Phase 14 is activated. Existing accountant/regulatory/Aramex/customs/production gates remain intact.

No automatic merge or production/VPS deployment.
