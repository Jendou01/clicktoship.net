# API conventions

Canonical prefix `/api/v1`; OpenAPI 3.1 is in openapi.yaml. Only health routes are implemented in foundation. Planned routes below are a contract direction, not an implementation claim.

## Representation
Success: `{data: <object|array>, meta: {request_id: <uuid>, ...}, error: null}`.
Failure: `{data: null, meta: {request_id: <uuid>}, error: {code: <stable>, message: <safe>, details: <object>}}`.
Return X-Request-ID on API responses. Generate server correlation IDs; do not reflect unvalidated user header content. No stack traces, database messages, credentials or raw carrier errors in public responses. Foundation liveness reports live; readiness reports ready only if PostgreSQL, Redis and the migrations table are reachable. Unavailable readiness is HTTP 503 with DEPENDENCIES_UNAVAILABLE.

Use UUID identifiers, UTC RFC3339 timestamps, ISO3166 alpha-2 countries, ISO4217 currency plus integer minor units (exponents vary; not all currencies have two decimals). Weight/dimensions use decimal strings and explicit units. Separate origin, destination, manufacture, billing, declared-value and selling currencies. No implicit FX. Price snapshots store cost, merchant price, margin, tax, fees, currency, rule version and quote expiry.

## Authentication and authorization (Phase 2)
Same-origin web uses Sanctum stateful sessions + CSRF. Public/service clients use scoped hashed Sanctum bearer tokens. Resolve active merchant membership and permissions server-side. Multi-merchant selection must be verified; payload merchant_id cannot grant scope. Suspended users/merchants denied centrally. Admin routes require separate permissions/MFA and audited access. Foreign/unknown object lookup returns consistent 404. Health routes are public, minimal and throttled.

## HTTP, pagination and errors
201 created, 202 accepted async job, 204 deletion/revocation; 400 malformed JSON, 401 unauthenticated, 403 permission denied, 404 unavailable resource, 409 conflict/idempotency mismatch, 422 validation/unsupported capability/route, 429 rate limited with Retry-After, 503 unavailable. Cursor pagination default 25/max100 with stable (created_at,id) ordering; allowlisted filter/sort fields only. Validation details map field names to safe message arrays. No expensive unbounded includes/exports.

## Idempotency
Require Idempotency-Key on shipment, bulk execution, pickup and financial mutations. Scope uniqueness to merchant + operation + key, persist canonical request hash, response reference/status and processing lease. Same payload returns original outcome, different payload 409, concurrent pending request 409/202 with explicit state. TTL/reuse policy requires product definition; financial/provider operation dedupe is permanent even if HTTP response caches expire. Write idempotency, holds, aggregate and outbox atomically. Unknown provider results never become safe retries by TTL expiry.

## Planned resource inventory
| Module | Routes |
|---|---|
| Identity | /auth/register, /auth/login, /auth/logout, /auth/password/*, /auth/email/*, /auth/tokens |
| Merchants | /merchants/current, /merchants/current/users, /addresses |
| Rates | POST /rates/quote (canonical; blueprint /v1/quotes reconciled here) |
| Shipping | /shipments, /shipments/{id}, /shipments/{id}/label, /shipments/{id}/tracking |
| Bulk | POST /shipments/bulk, GET /bulk-jobs/{id}, /bulk-jobs/{id}/rows, /bulk-jobs/{id}/retry |
| Pickup | /pickups, POST /pickups/{id}/cancel |
| Finance | /wallet, /wallet/transactions, /wallet/topups, /invoices |
| Carriers/locations | /carriers, /locations/countries, /locations/cities, /addresses/validate |
| Commerce | /integrations/{provider}/connect, /integrations/{id}/health |
| Merchant events | /webhooks/subscriptions (outbound, distinct from carrier inbox) |
| Operations | /admin/* (separate platform authorization) |

Business create responses expose pending/unknown/accepted/rejected explicitly. Carrier AWB and label exist only after verified acceptance; a reserved/charged wallet is never a success signal. Documents require tenant authorization and short-lived signed URLs. Incoming carrier callbacks live outside merchant session authentication at a separately verified ingress; exact contract is deferred until actual webhook docs arrive.

## Compatibility
Additive schema changes within v1; breaking semantic changes need version/transition. External APIs normalize carrier fields into internal DTOs. Future OpenAPI additions must include request validation, security, error schema, examples and executed tests before being marked implemented.
