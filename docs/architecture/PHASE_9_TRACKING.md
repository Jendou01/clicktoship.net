# Phase 9 — Tracking and webhook foundations

Tracking reuses `carrier_webhook_deliveries`, `carrier_tracking_events` and `carrier_tracking_projections` from Phase 6. These are the sole tracking history/projection. Shipping state, document state and financial state remain separate; Tracking never updates them. Bulk references continue to point to ordinary Shipping drafts.

## Evidence and binding

Only Shipping-owned decisive immutable accepted observations can bind a carrier reference to the exact tenant/submission/revision/operation/account. No global AWB lookup. The blueprint’s suggested x-api-key/example fields are unverified evidence, not an implemented authenticity contract. Phase 6 code-owned capability gates additionally reject non-synthetic adapters even if an adapter reports positive flags. References are encrypted with keyed search digests. Same-account/same-tenant reference collisions remain unbound with an immutable redacted identity-conflict audit entry; cross-tenant reuse of a reference does not link tenants. Existing accepted observations are backfilled incrementally. Unknown creates with no verified identity remain unbound: tracking cannot invent a reference or resolve financial ambiguity.

The runtime registry always returns a disabled adapter. Aramex request builders already document TrackShipments/TrackPickup, but V2 responses, error/event catalogue, timestamp zones, rate limits, reference uniqueness, webhook authentication/replay/acknowledgement and sandbox authorization are unverified. No V2 parser, status mapping, signature protocol, provider URL fetch or live polling is implemented by inference. Tests inject an isolated synthetic adapter; synthetic evidence is never exposed as live merchant events.

## Event processing

Authenticated adapter envelopes are bounded JSON (64 KiB/100 events), with provider-authenticated timestamp/delivery/replay identities. Endpoint registration is tenant+account-specific and code-owned; there is no public activation API. Runtime external routes fail closed before payload processing. Replay identity is separate from delivery and event identity: exact delivery replay returns its prior receipt; changed payload conflicts; a fresh delivery reusing an authenticated nonce conflicts. Payloads are encrypted. Unknown codes remain unknown. Event key collisions with changed facts quarantine the delivery atomically. Authentication verification precedes durable retention.

PostgreSQL inbox/work and immutable processing/receipt records are authoritative. Redis carries only encrypted identifier jobs. Processing has bounded leases, five attempts, exponential backoff, dead-letter/quarantine classifications and recovery after Redis loss. Valid facts, projection decision, reconciliation case and disabled notification intent commit atomically. Payloads/locations never enter logs or public diagnostics. Raw encrypted retention is preserved; an authenticated retention-policy hook reports candidates but cannot delete history until legal/privacy policy is approved.

## Projection policy

Receipt order never determines truth. UTC provider time is required, future events rejected, equal or older timestamps retained as history without projection. Unknown/unverified events do not project. Known forward transitions follow an explicit graph; terminal delivered/returned/cancelled never regress. Delivered may enter an explicitly documented return flow only through a verified adapter event; returned/cancelled remain terminal. Conflicting newer known events create immutable reconciliation cases and cannot overwrite prior confirmation. Source priority applies only to verified provider facts (poll and webhook equal authority); ambiguous simultaneous conflicting facts retain the existing confirmation and create a case. No manual merchant status write or guessed provider-code mapping exists. Review appends acknowledgement/proposal only; it cannot alter Shipping or financial decisions and cannot rewrite terminal tracking history.

## Scheduling and access

Manual single/batch refresh (maximum 50) binds request/business idempotency, current token/session authority and merchant identity. Workers revalidate the exact originating credential before poll; suspension/revocation blocks work. Scheduled polling is code-gated off; future readiness uses bounded due-binding batches, active merchant checks, per-account spacing, shared account circuit/backoff and no shipment-create retry. Scheduler claims are PostgreSQL-based; recovery republish does not depend on Redis. Defaults: 15-minute polling, one account call/minute, 90-second lease, 60-second job, 5 attempts, 24-hour stale threshold.

Merchant read/refresh and platform global-health/target-diagnostics/review permissions are distinct. Target administrative reads/review require current session, recent MFA and independently approved expiring session-bound merchant access grant. Global health exposes aggregate counts only. No platform bypass or raw-payload export. Review cannot authorize finance, resolve a create, enable a contract or publish mappings.

Notification outbox readiness deduplicates confirmed event+recipient; no default consent, no recipient inference, no sender/channel binding. Only active current members with explicit future preferences may receive intents; channel delivery is hard disabled. Conflicting/stale/unknown evidence produces no notification. Retention candidates and health are operational hooks, not approved deletion/delivery policies.

## Launch gates

Verified scrubbed current carrier schemas/event catalogues/authentication+replay+ACK specs, authorized sandbox credentials/test policy, reference/timestamp/rate-limit guarantees, retention/region/privacy policy, approved notification consent/templates/channels, restricted DB runtime roles, production scale/backfill limits and operations ownership must be approved before enabling an adapter. No live requests, customer messages or financial effects in Phase 9.

## Migration compatibility

Phase 9 adds 13 tables and reuses all three existing carrier event/projection tables. Expanded provider/status catalogs remain forward-compatible on downgrade rather than narrowing away retained carrier facts. Runtime downgrade refuses retained bindings, work, endpoints or tracking grants. Disposable regression teardown removes referencing test tracking history before stripping only Phase 9 grant capabilities. Existing rollback tests remove additive Phase 9 before their original preservation checks. Migration-history drift checks independently verify critical columns, immutable triggers and the binding/account composite FK. Normal development contains no tracking records; only the empty new module was rolled back/reapplied while iterating its migration. Existing merchant/shipping/pricing/financial data was not reset.

Tracking identity binding runs asynchronously from already committed Shipping evidence. Shipping code is unchanged: an outage in Tracking cannot roll back accepted carrier observations or trigger a resend. PostgreSQL observation backfill recovers missing bindings without Redis; an injected tracking-storage outage regression proves this boundary.
