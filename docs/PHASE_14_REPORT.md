# Phase 14 — Shopyzee Commerce Integration

Development implementation on `feat/phase-14-shopyzee-integration`, based on verified main merge `92c20a5f9190e710000a44d9575f563278d75e59` (PR13). No automatic merge, deployment, VPS configuration or Phase15 work.

## Evidence and external status

The user identifies Shopyzee as a customized Nazmart multi-tenant system. The supplied Technical Blueprint was reviewed: it calls for a first-party plugin using stable extension hooks/API and per-merchant credentials, without undocumented endpoints. No actual Shopyzee source checkout, deployed framework/version, tenant/order/auth models or sandbox credentials are available. GitHub source discovery returned GraphQL Forbidden. All eight user-supplied official Xgenious plugin-guide pages were requested and returned managed HTTPS tunnel403 before content was accessible. No storefront HTML, stock Nazmart assumptions or guessed endpoints substitute for that evidence.

**Real Shopyzee connection is not supported or enabled by this delivery.** Authorization method is null. Live synchronization and webhook interpretation fail closed; no callback, OAuth mode/scope, signature scheme, wire mapping, fulfilled-order update or compatible plugin package is invented. See [evidence matrix](integrations/SHOPYZEE_EVIDENCE.md) and [separate connector architecture, documentation URLs and exact missing contracts](integrations/SHOPYZEE_CONNECTOR_DESIGN.md).

The proposed separate connector would use verified plugin manifest/base/lifecycle/routes/hooks without core changes, per-store consent and least-privilege authentication, exact permanent tenant identity, encrypted credentials, rotation/revocation/rate/replay controls and minimized read-only order projection. Actual documented method signatures, deployed models and authorization/tenant middleware are prerequisites to source packaging and compatibility testing. Nothing is installed on live Shopyzee.

## Implemented ClickToShip functionality

* Reused the Phase13 provider port, connections, encrypted credentials/snapshots, current-origin authorization, durable bounded sync/preparation runs, checkpoints, recovery, immutable receipts/events/results/draft links and Shipping.importDraft.
* Added a distinct disabled Shopyzee adapter and persisted-provider adapter selection. Salla routes and DI remain compatible. Shopyzee unavailable handling creates no intent, receipt, draft or network request.
* Permanent source identity is `(provider, platform instance, tenant/store)`. The immutable ownership trigger includes all components and merchant identity. Source order identity remains connection + external order ID. The same tenant/order identifiers can exist across instances without collision. Disconnect/domain changes cannot reassign ownership.
* Sync pages and refresh results must match exact provider/instance/store proof; provenance is checked. Source credentials/snapshots remain encrypted. Unknown refresh outcomes require reauthorization, not blind replay. Current session/token/role/merchant/auth-version and connection generation are rechecked before committing returned data.
* Initial/incremental normalized sync, bounded pagination, per-store checkpoints, manual request, durable retries, interrupted-page recovery and bounded bulk preparation reuse existing Commerce orchestration. All new executable source fixtures are explicitly synthetic and guarded to isolated testing databases, not real Shopyzee contracts.
* Single and bulk reviewed draft preparation reuse Shipping.importDraft with source revision/hash, explicit valid origin/package/service data, idempotency and permanent links. Missing money/COD/address/weights/customs are not fabricated; currency is not implicitly converted. Ambiguous source revisions block new preparation and preserve existing drafts.
* Unified bilingual merchant integrations/orders/review/history screens show separate provider availability, synthetic provenance, instance/store identity, status, last sync, credential expiry, imported/review counts, local disconnect and audit. Provider filtering and per-order server-derived source prevent misattribution. Safe locale context retains allowlisted provider/UUIDs only; existing delayed-response/merchant-switch fences remain.
* Platform monitoring is existing recent-MFA/current-role-authorized count-only composition, now with per-provider verified/synthetic/disconnected/reauthorization/sync-failure/order/review/draft-failure counts. Failed preparation rows count even when a batch finishes with partial results. Grouped queries avoid per-provider/merchant query growth. No customer data, credential, private merchant/order identifiers or global financial amounts are returned.

## Database and API

One additive migration: `2026_10_25_140000_extend_commerce_provider_identity`.

* Allow listed Salla/Shopyzee provider values; add permanent `external_platform_id` and optional display-only `display_domain`.
* Preserve existing Salla rows with the internal `salla` namespace, not an asserted external API field.
* Replace source uniqueness with provider/instance/store and extend immutable ownership enforcement.
* Add provider-scoped authorization intents/index; Salla callback cannot consume another provider's intent. Disconnect cancels only its provider's pending/in-flight intents.
* Preserve retained history on downgrade: refuse Shopyzee connections/intents/domain metadata instead of deleting it. Disposable-only integration fixtures reset through guarded installation before cleanup; normal data is not rebuilt.
* Schema integrity independently checks columns, the platform ownership function and unique source constraint.

Existing APIs extended: `GET /api/v1/commerce/providers`, `/connections`, `/orders`, `/orders/{id}` and existing admin dashboard Commerce counts. Orders accept `provider=all|salla|shopyzee`; omitted provider retains the legacy Salla default. Bounded pagination, blind reference/date/status/store filters and merchant authority remain. Existing `/connections/{id}/sync|prepare|disconnect|events`, `/orders/{id}/preview|draft`, and `/runs` use the same service/security boundaries.

New code-owned routes: `POST /api/v1/commerce/shopyzee/authorize` (authenticated authorized merchant, CSRF, throttled, always503 without verified method) and `POST /api/v1/commerce/webhooks/shopyzee` (throttled, always503 before payload interpretation). These are ClickToShip boundaries, not claimed Shopyzee endpoints. OpenAPI documents both gates and extended response schemas. No external callback route is fabricated.

## Security and verification

Test cases cover distinct instances/tenants with duplicate source IDs, permanent ownership after domain changes/disconnect, cross-merchant IDOR, token scopes, expired/wrong-identity refresh, provider dispatch with no Salla fallback, duplicate import, source conflicts, currency/COD/incomplete data, disconnect and role/token/merchant revocation during I/O, provider-bound callback intents, crash/recovery, partial bulk results, immutable source links, safe downgrade/reapply and deliberate schema drift. Independent PostgreSQL/Redis processes test competing refreshes and competing Shopyzee draft commands: one rotation or one draft/link, never duplicate effects. Existing Phase0–13 suites remain required.

All wallet/carrier/COD/notification/fulfillment operations remain outside this increment. Tests assert zero financial/carrier effects from Commerce preparation. No secrets or private fixture files are committed. Private temporary browser credentials remain outside Git with0600 permissions; no global Shopyzee key or production DB access is used.

[Changed files](PHASE_14_FILES.txt). Final verification is recorded below.

## Remaining gates

Accessible official docs/export and actual deployed source/version; reviewed plugin lifecycle/manifest/model/auth/tenant contracts; per-store installation/consent/replay/rotation/revocation; authoritative order/payment/COD/pagination/webhook mapping; privacy/retention/key rotation and standing background consent; isolated connector compatibility/sandbox testing and separate enablement approval. Existing Salla, carrier, financial, customs and regulatory launch blockers remain.

Future fulfillment write-back needs a separately verified API and approval, carrier-verified tracking provenance, current exact tenant/scopes and an idempotent transactional outbox with replay/conflict/reconciliation/audit. It remains disabled; internal/synthetic events cannot fulfill external orders.

## QA fixes during development

The first complete backend run exposed a platform query-budget regression from separate per-provider counts. Grouping source/status counts reduced the 2,000-merchant dashboard to69 queries (the existing strict limit remains unchanged). A new negative test initially expected403 where the established tenant middleware returns409 without a selected merchant and404 for unauthorized merchant selection; it now asserts the precise denied responses and null data, without changing permissions. An existing frontend focus test raced an asynchronous focus effect; its exact focus assertion now waits for observable focus with Testing Library, without a delay or application behavior change. Both full frontend repeats passed afterward.

The first visual exploration reused recently exercised browser identities and encountered a generic API alert. A bounded local reproduction with the same synthetic actor recorded124 API responses and429 from authenticated endpoints after repeated navigation, confirming the existing120/minute protection. Visual QA was rerun using a distinct random `visual14-*` synthetic account namespace generated by the existing guarded E2E fixture process, with private0600 credentials outside Git. Both monitored34-view passes were clean; rate limits and application permissions were unchanged. Connection review counts explicitly describe source-change review flags; field-level readiness is determined by the separate explicit shipping preview, not inferred from that count.

## Final verification record

| Check | Executed result |
|---|---|
| Complete backend Phase0–14 regression | **407 passed,4,658 assertions**; real PostgreSQL and Redis; no skipped tests |
| New Phase14 targeted security/function/races | **25 passed,285 assertions** in the targeted run; also included in the complete run |
| Frontend components/unit | **114 passed across17 files**, repeated after the final locale-context fix |
| Independent processes | Existing Salla and new Shopyzee refresh/draft competitors passed; one rotation or one draft/link; no financial/carrier effects |
| Schema/migrations | Additive upgrade, preservation of legacy Salla/merchant records, rollback/reapply, retained-evidence downgrade refusal and deliberate constraint drift tests passed |
| Laravel Pint | **319 files passed** |
| ESLint / TypeScript | Passed |
| Production Next.js build | Passed; **181 pages** |
| Composer | Strict manifest validation passed; zero advisory findings |
| npm | Full and production-only audits: zero findings; no new dependencies |
| Installer archive tests | **4 passed** |
| OpenAPI | YAML,207 unique operation IDs, local references, extended response schemas and unavailable Shopyzee boundaries verified |
| Platform bounded-query regression | 2,000 synthetic merchants,69 HTTP-request SQL queries, unchanged strict budget |

Browser: **29/29 passed in three complete runs** (3.4m,3.5m,3.5m). The last run includes the final provider/store language-switch regression. No skipped tests, disabled assertions, timeout increases or arbitrary delays. Frontend114 tests passed twice after that fix.

Responsive visual QA:34 recorded views across EN/AR desktop1440×1000, tablet820×1180 and mobile390×844, using a separate private synthetic identity namespace. Integrations, provider-filtered orders, review, synchronization history, merchant dashboard and aggregate platform Commerce were inspected. No observed runtime error, failed API response or horizontal overflow in the successful monitored passes. PNG dimensions, nonempty bytes and representative pixel variation were checked. Current images and the inspection record are in `/workspace/shared/clicktoship-phase14/`; they are outside Git and contain synthetic data only. No ZIP or public preview was created.

The final locale check also found and fixed store-filter restoration: the allowlisted connection UUID was retained in a language-switch URL but had not initialized the list filter. The component and real-browser journey now check provider + authorized store restoration, while the server still derives the merchant and validates the scoped connection. Search references and customer data are not copied into locale URLs.

Final normal development checks:23 migrations applied and schema verification passed; Horizon running, Redis PONG and Nginx bilingual readiness smoke passed. Existing23 user records were preserved. Normal development retained zero Commerce connections/orders, shipments, ledger transactions and carrier operations; all synthetic sources and visual users remained in disposable testing databases. The final34 PNG files were individually verified as valid and nonempty.

## Delivery

Feature branch: `feat/phase-14-shopyzee-integration`. The reviewed change set contains47 files, listed in PHASE_14_FILES.txt. GitHub API discovery was forbidden; PR creation will be attempted after the authorized branch push. The prepared description is available with the local visual report. If API permissions remain unavailable, use https://github.com/Jendou01/clicktoship.net/compare/main...feat/phase-14-shopyzee-integration?expand=1 to open the review. The handoff records the exact delivered commit and actual PR result. No merge or deployment is authorized.
