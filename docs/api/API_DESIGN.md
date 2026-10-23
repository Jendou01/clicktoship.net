# API conventions

Canonical prefix `/api/v1`; OpenAPI 3.1 is in openapi.yaml. Health plus Phase 2 identity/merchant/team/session/token routes are implemented and specified in OpenAPI. Remaining business routes below are planned.

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

## Implemented Phase 2 contract

The authoritative operation list and request/response schemas are in `openapi.yaml`. Browser clients first GET `/sanctum/csrf-cookie`, then send credentials and decoded XSRF-TOKEN as X-XSRF-TOKEN for mutations. First-party Origin/Referer must match configured Sanctum stateful domains. Public registration/login/recovery/reset also require this browser session and CSRF. HTTP 419 indicates CSRF expiry/mismatch. Logout/revocation return 200 envelopes. Auth and tenant responses use no-store, private.

Registration optionally creates an owner organization; verified email is required before tenant operations. `/auth/me` returns a whitelisted identity and selected merchant ID, not authorization to use that merchant. `/merchants` and `/auth/memberships` list current authorized memberships. POST `/merchants/select` revalidates a requested merchant and stores it per browser session. Browser-only creation/switch/session/token endpoints reject bearer-only requests. Tokens are merchant-bound and cannot switch; effective permissions are token abilities intersected with current role permissions. X-Merchant-ID is an optional stale-context guard, never a tenant selector.

Team mutations accept only a built-in merchant role and active/suspended/removed status; PATCH/DELETE require current integer version. Foreign member IDs return 404. Version or last-owner conflicts return 409. Team listing is page-based, default25/max100 with stable created_at/id ordering; unlike planned shipment cursor pagination, this small initial administrative collection uses page metadata. Session/token lists are bounded to 100.

Verification email links carry the original expiring relative signed URL in a frontend fragment; POST `/auth/email/verify` submits it as `verification`. Reset links similarly carry token/email in the fragment; POST `/auth/password/reset` consumes the token once. Fragments are captured into memory and removed from history, including links opened on an already mounted screen. Credential forms use POST and disabled submission until hydration. Do not move credentials into query strings, analytics, logs or persistent browser storage.

Roles/permissions are read-only catalogues. Platform roles have separate assignments and no public grant, impersonation or bypass endpoint. There is no admin API in this phase; explicit audited support access requires a later authorized workflow.

## Implemented Phase 3 contract

OpenAPI now includes profiles/settings/address CRUD, independent eligibility, immutable business-verification submission/manual review, team invitations, name/locale updates, MFA, scoped platform merchant/user administration, access requests/independent approval/revocation and audit history. Phase 2 routes remain compatible; suspended membership tokens are now deleted and consequently return401, including after reactivation.

Profile/address/settings creation uses version0; updates use the returned version. Verification submission uses the merchant version from /merchants/current; review/status changes use the current merchant version. User-security/role changes use admin_version. Grant approval/revocation and invite resend/revoke require their current version. Conflicts are409. New address/admin/audit lists use page-based bounded25-row pagination consistent with the existing team API; switching to cursor pagination for these collections is deferred rather than introducing a different partial contract. Verification, invitation and access-request history reads are currently bounded to100 entries; full retained history remains in PostgreSQL.

Admin requests are browser-only, with CSRF for mutations and confirmed fresh MFA for every privileged operation. Target-specific reads carry access_grant as query parameter, mutations in JSON body; it is a non-secret UUID and never sufficient without the matching authenticated session/current permissions. Grant requests contain allowlisted capabilities and reason; a different authorized MFA-authenticated Super Admin approves them. Grants expire no later than15 minutes after request and cannot select merchant context for ordinary tenant APIs. Platform directory metadata is explicitly allowed; merchant-private profile/review/audits require target authorization. Global audit reads include platform-only events.

Business verification and account status never enable shipping. /merchants/current/eligibility always returns eligible:false with unavailable evaluator reasons. No /addresses/validate, document upload or MFA recovery bypass is implemented. TOTP/manual enrollment and backup codes are returned only where appropriate; status/list APIs never return secrets or token hashes. Invitation links use frontend fragments, bind to authenticated verified email and are consumed by POST; reopen the email link after sign-in/verification if necessary. Invitation acceptance never changes an existing member's role.

Generic platform business-profile reads omit contact and registration/tax identifiers even with a view grant. Full submitted business data is available only through an independently approved verification-review capability. MFA writes recheck the locked user/current session and password so concurrent credential revocation cannot complete a stale factor mutation.

## Phase 4 financial API contract

22 additional operations are documented in OpenAPI (90 total). Merchant wallet/history/hold/statement reads are tenant-derived; wallet opening is an authorized CSRF-protected zero-balance action, unique by merchant/currency. Money uses exact integer-minor-unit strings plus ISO currency/exponent; only SAR is enabled. Statements are bounded by wallet sequence/cutoff and never sum currencies. Holds paginate by UUID; transactions by sequence. There is no exposed reserve/capture/top-up/withdrawal or shipping-charge API.

Target financial administration requires independent merchant access grants, recent MFA and granular platform permissions. Correction requests carry request_key and business_key, immutable amount/currency-derived wallet/reference/reason, and a separate second-person approval. Versioned approve/execute/revoke endpoints never edit an existing journal. Execution is normally disabled pending accountant acceptance; repeat execution returns the committed result under current authorization. Internal SQL domain conflicts map to409 without raw financial SQL errors. Reconciliation is structural only and freezes discrepancies; no external PSP/statement contract is implied.
