# Phase 6 carrier connector — evidence-gated implementation

## Honest capability boundary

[The source/evidence matrix](../integrations/PHASE_6_EVIDENCE.md) is authoritative for the implemented connector. Eight V2 JSON **request** shapes are supplied, but zero saved/current V2 responses, no tracking status catalogue and no webhook authentication specification. Legacy SOAP tables are not a V2 JSON response contract. Accordingly, all external capabilities and production remain hard disabled. No admin checkbox, account approval, test double, configured route or database edit establishes verification. No sandbox request or billable shipment is authorized by an uploaded sample credential.

Implemented independently: adapter/registry/capability registry, typed/closed request-shape validators, exact decimal formatting, current-response fail-closed normalization, separate legacy documented structure validation, encrypted versioned master account configuration, route/service candidate mapping, independent MFA approval, safe error classes and transport readiness, scoped operation/deduplication/quarantine journals, encrypted raw-event inbox/queue/ordering foundations, health/circuit readiness and bilingual administration.

Stopped capabilities: all live V2 operations/response normalization, sandbox contract testing, unprovided location endpoints, authenticated external webhook ingestion, real status-code mapping, carrier-reference reconciliation, label retrieval/storage and multi-package dimensional serialization. No merchant shipping, wallet posting, actual availability/eligibility, bulk or Phase 7/9 workflow.

## Module boundaries

`App\Modules\Carriers` owns all carrier code. `CarrierAdapterInterface` separates carrier identity, request preparation and protocol-specific normalization; `CarrierRegistry` resolves only explicit supported carrier IDs. `AramexAdapter` owns eight documented request names/paths (not arbitrary URLs). `CarrierCapabilityRegistry` reports request-documented/response-unverified or unsupported states, with all network enablement flags false.

`CarrierCredentialsManager` validates eight supplied ClientInfo field names and encrypts their entire payload under existing application key management. No credential getter is exposed through HTTP. Active-version sandbox credential retrieval refuses production and historical/disabled versions. Managed production vault/key rotation remains a launch integration decision; do not put real values in Git/chat or copy historical examples. Production accounts can be stored only as inactive configuration; no production endpoint is guessed.

`CarrierServiceMapper` validates explicit country-to-country product/service candidates and postal/state requirements. DOM/CDS and EXP/EPX are guidance, not permissions or a seeded route. Goods origin is a required truthful input, never defaulted to Saudi Arabia. Address checks validate syntax and configured postal/state requirements (including existing SA/US conventions), not carrier validation or authoritative global postal rules. Weights/dimensions are bounded integers, converted exactly to decimal KG/CM strings. Currency precision is read from the existing financial catalog; no FX or rate invented. Multiple packages are rejected for external serialization until package-dimensional semantics are documented; the schema accepts collections for future adapters without guessing those semantics. Embedded/linking pickup variants, COD details, optional customs/item/attachment schemes and legacy field spelling/date conflicts require current evidence.

`CarrierResponseNormalizer` rejects every `aramex-v2-json` response as unverified, including a superficially successful HTTP200. The explicit `legacy-soap-manual` structure parser handles envelope/per-shipment errors and minimal shipment/pickup references from the old manual. It is not a SOAP transport and cannot establish V2 compatibility. Notification strings, labels/URLs and personal carrier messages are never exported.

## Configuration and permission model

One additive migration creates versioned global operator accounts. Sandbox and production are distinct immutable account identities, not a mutable URL switch. Credential and route changes create a full new snapshot; omitted credentials retain the preceding encrypted value, explicit clear removes it, simultaneous clear/provide is rejected. No plaintext/ciphertext/hash/ClientInfo credential fields are returned by account/history reads.

Platform Super Admin and Platform Operations receive `platform.carriers.view`, `.manage`, `.approve`; no merchant role receives them. Current session + confirmed recent MFA applies to every administrative read/write. Sensitive configuration approval/disable requires a different person from the revision author, and rechecks maker status/role/session/auth-version/MFA version/freshness. Global security lock then carrier lock serializes changes against existing role/MFA/user changes; expected draft/publication counters reject stale commands. Configuration approval is expressly not successful connection or permission to send a carrier request. Tenant-owned operations are never listed in global configuration views; error monitoring exports only safe correlation/error classes, not merchant/customer data or carrier references. Existing target-grant/financial isolation rules are unchanged.

## Database

`2026_10_24_100000_create_carrier_connector` installs `database/sql/phase6.sql` and additive built-in platform permissions:

* `carrier_accounts`, immutable `carrier_account_versions`, immutable `carrier_config_events`: UUIDs, immutable carrier/environment/code, optimistic counters, independent author/checker audit, encrypted credentials, candidate service/route snapshots.
* `carrier_operations`: immutable merchant/account/environment/revision/actor/origin-token/business-key/input identity, encrypted request and optional result, unique correlation UUID and `(account,merchant,operation,business_key)` deduplication. Origin token IDs are retained as identifiers rather than restrictive FKs, so token revocation can delete the token. Background work rechecks its existence/binding/version/scope.
* `carrier_attempts`: append-only per-operation attempt facts, safe outcome/error/HTTP status/correlation only.
* `carrier_health_records`: append-only blocked/unverified/degraded/circuit facts. No health probe implies live verification.
* `carrier_webhook_deliveries`, `carrier_tracking_events`, `carrier_tracking_projections`: composite account+merchant+operation relationships, encrypted bounded raw payloads, keyed payload fingerprints/digests, unique event identity, append-only facts, separate provider-neutral projection.

Services, route eligibility and future raw-code mappings are account-versioned evidence candidates rather than duplicated mutable tables. No raw carrier status is mapped without a verified catalogue. Account configuration alone never enables a route. PostgreSQL rejects operation reparenting, history updates/deletes, invalid state transitions, retry of terminal unknown results, cross-tenant event/delivery relationships and self-approved configuration events. Normal downgrade refuses retained history. Only strictly guarded disposable testing databases allow destructive teardown; older phase rollback tests first remove this additive migration.

## Reliability and queues

No database transaction is held over HTTP. Current internal `CarrierOperations` validates and atomically deduplicates scoped intent, then its one-try encrypted worker reauthorizes actor/current merchant membership and originating API-token scopes. The immutable capability gate blocks execution **before sending** and records why. This is not a functioning live submission orchestrator: no code path crosses the current verification gate. A persisted `sending` marker is always treated as unresolved; stale markers can be quarantined as `unknown`, never reset/retried. Actual send/finalize/reference-lookup orchestration requires current carrier contracts and Phase 7 approval. There is no public command/execute/create endpoint.

Transport readiness is isolated in `HttpCarrierTransport`: fixed documented HTTPS sandbox host/path allowlist, TLS verification retained, redirects blocked, 3-second connection/15-second request limits, UUID correlation header, bounded response read, exact JSON decoding and classified errors. The default `DisabledAramexTransport` authorizes nothing. Testing uses an autoload-dev guarded subclass and HTTP fakes, never a runtime bypass flag or real request. Safe reads have at most three attempts, short bounded jitter, and only retry network/429/5xx failures; authentication/redirect/malformed responses are not retried. Mutating calls, including cancellation conservatively, are never retried automatically after transport/HTTP uncertainty. Error text never includes raw upstream messages or credentials. Read-only retry exhaustion remains an error, not a fabricated rate.

`CarrierIntegrationHealth` persists safe evidence and provides a Redis cooldown/circuit readiness counter (five failures/60 seconds); it does not enable a capability or claim a fully active circuit-protected integration. Carrier queues are added to existing Horizon worker configuration. `ProcessCarrierOperation` has one try; encrypted event retention jobs are idempotent and clear tenant context in finally. No production event producer/authenticator is installed.

## Webhooks, events and reconciliation

POST `/api/v1/carrier-webhooks/aramex/{integration}` returns503 and stores nothing. It never treats an invented `x-api-key`, HMAC, secret, request merchant ID or trusted flag as authentication. Missing authoritative auth/replay/ack/registration documentation is a capability-specific stop, not a reason to invent a protocol.

Internal inbox tests explicitly supply synthetic trusted evidence. `(account,operation,event-key)` deduplication plus a canonical payload digest returns the original delivery for exact replay and rejects changed replay. Raw payloads are encrypted/bounded (64 KiB), event times/codes retained, unknown codes normalize only to `unknown` and cannot update projections. Provider-neutral ordering rejects stale/equal-time updates, prevents delivered/returned regression and permits legitimate later returns. No merchant-facing tracking workflow exists. Verified status mappings and raw-event retention/erasure/key policy remain approval gates before live ingestion.

Unknown outcomes expose reconciliation-required state; no client-reference lookup or definitive reconciliation resolver is invented. No wallet hold is released and no transaction is posted by any carrier operation.

## HTTP/UI and launch limits

Seven documented HTTP operations: authenticated admin overview, draft create/revise, independent approve/disable, safe account health/audit/error history, and the disabled external webhook. Internal carrier request operations are described separately in OpenAPI extensions, not exposed as merchant endpoints.

EN/AR/RTL/LTR screens: `carriers`, `aramex`, `carrier-services`, `carrier-capabilities`, `carrier-health`, `carrier-errors`, `carrier-webhooks`, `carrier-mappings`. Credential fields never preload secrets and clear on save; route forms are typed, no raw JSON editor. Collection reads are bounded to100 accounts/history records; large-scale pagination/performance work remains before exceeding that initial operational limit.

No live/sandbox carrier validation, production launch, payment, shipping eligibility or Phase 7 activation is claimed. Current schemas/scrubbed responses, authorized secure credentials/non-billable sandbox policy, contract/route/payment/customs entitlements, webhook/status/lookup specifications and private label storage requirements remain in the evidence report.
