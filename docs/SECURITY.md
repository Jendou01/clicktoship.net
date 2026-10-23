# Security baseline and launch gates

## Implemented foundation boundary
Phase 2 exposes authenticated identity, merchant and team operations with server-side RBAC. Carrier, payment and platform administrative routes remain unexposed. Carrier/payment flags remain false and do not implement those operations. Horizon routes/dashboard are disabled until admin authentication, MFA and authorization exist. Health endpoints reveal only status/check names and correlation IDs, never exception messages, versions, connection strings or secrets. Readiness probes PostgreSQL, Redis and schema migration presence; liveness does not require dependencies. Runtime disables debug by default.

## Threat model and required controls
| Threat | Required control and verification phase |
|---|---|
| Cross-merchant IDOR/worker leakage | membership-derived context, policies, scoped queries, composite FKs; negative tests Phase 2 |
| Master account/token disclosure | encryption/managed secret refs, log allowlist, no client DTO secrets; Phase 6/13 |
| Duplicate billable shipment/charge | payload-bound idempotency, outbox, locked holds, unknown-result quarantine; Phases 4/7 |
| Ledger tampering/overspend | DB immutability/balance enforcement, locks, restricted DB role, dual approval; Phase 4 |
| Forged/replayed webhooks | documented auth + constant-time verification if shared secret, durable inbox/dedupe; Phase 9 |
| Malicious upload/export | MIME/content limits, parser limits, malware quarantine, formula-safe exports; Phase 8 |
| SSRF through callback/label URLs | destination policy, DNS/IP checks on every redirect, block private/metadata ranges; Phases 6/9 |
| Session theft/CSRF/brute force | secure HttpOnly cookies, SameSite, CSRF, rotation, MFA admins, verified email, rate limits; Phase 2 |
| Secret/PII in traces/backups | field allowlists, scrubbed fixtures, retention, encryption, access audit and restore drill |

## Operational policy
Keep .env and uploaded originals outside Git. Environment examples contain names/placeholders only. Generate a unique Laravel APP_KEY per installation; rotate through a tested migration plan. Development DB credentials are local-only, generated during setup and shared via ignored root .env; no port exposure for PostgreSQL/Redis. Compose is a development configuration, never a production template. Nginx binds loopback in development; production needs trusted reverse-proxy/TLS, secure cookies, origin configuration and secrets manager. Never put secrets in NEXT_PUBLIC_*.

Before launch: data residency and Saudi PDPL/legal review; wallet regulation and tax/accounting approval; permissions/CSRF/IDOR/security test suite; dependency/SAST/secret scans; private storage with lifecycle rules; restore proof; incident/runbook owners; signed webhook/PSP contract checks; MFA/role matrix; monitored reconciliation; documented production rollback. Proposed service objectives remain unmeasured. See OPEN_QUESTIONS for owners. Report vulnerabilities privately to repository maintainers; no formal security mailbox exists yet.

## Implemented identity controls

Passwords require 12–128 characters, mixed case, number and symbol, and use Laravel's configured secure hash/rehash. Browser sessions rotate at login, have Redis storage, HTTP-only SameSite cookies, idle timeout and a 12-hour registry expiry; logout/revoke rejects replay. Password reset is hashed, expiring and single use, locks the token row, increments auth_version and revokes all sessions/tokens. Recovery responses are generic for missing/suspended users. Verification is expiring, signed and bound to the authenticated user's email. Suspended identities, memberships and merchants are denied centrally. Actual CSRF middleware runs in tests, including public auth mutations.

Redis limits login by IP and email/IP, registration, recovery, password reset, authenticated actions and sensitive token creation. Tokens are SHA256-hashed by Sanctum, expire within 30 days, require current-password confirmation, cannot grant wildcard/platform/unowned abilities, and are intersected with current membership grants. No browser token persistence. Membership changes lock/reauthorize, require versions and protect the final owner. Roles cannot be assigned across platform/merchant scope even with direct SQL. Tenant foreign IDs return 404.

Audit rows forbid SQL update/delete/truncate and contain no credentials. Nginx access logging is disabled; notification jobs are encrypted and unsafe/log mail transports are rejected. Development Mailpit contains sensitive temporary links and is loopback-only; it is not a production service. Production requires TLS, secure cookies, exact origins, trusted proxies, a private verified email sender, separate database runtime privileges, retention and monitoring. Actual MFA and platform support/impersonation flows remain closed pending later implementation; no platform role currently bypasses isolation.

The test bootstrap guards the dedicated PostgreSQL database before migration traits run and overrides both environment and server configuration. This fixes inherited Docker environment precedence that initially selected the empty development foundation database during test setup. The development schema was restored, and every subsequent migration/concurrency test ran against clicktoship_test.

## Phase 3 security controls and remaining boundaries

Platform permissions alone never authorize merchant-private data: require verified active user, live browser session, confirmed MFA/version, fresh step-up, exact target/capability and independently approved unexpired grant. Recheck requester/approver status, approver permission/session/MFA version and revocation. Requesters cannot approve themselves; database constraints enforce independent identities and session ownership. No bearer-only admin/MFA management or impersonation. User directory omits email/name; audit APIs return safe allowlisted metadata and decrypt reasons only within authorized scope. Secrets/passwords/tokens/PII are excluded from audit payloads.

OTPHP supplies TOTP; secrets use authenticated encryption. Enrollments expire in 10 minutes; challenges accept six digits with a bounded one-step clock window and reject reused steps. Eight 256-bit backup codes are hashed and consumed once under serialization. MFA mutations require current password, are Redis-throttled, and replacement/disable revokes other sessions/tokens/grants. Platform assignments prevent disabling MFA until roles are removed through administration. Password reset revokes all sessions, therefore also blocks old session-bound grants; possession of a reset email does not satisfy platform MFA.

Merchant changes use context-derived queries, permission/token intersection, root locks, versions and composite tenant FKs. Immutable verification snapshot/decision triggers complement immutable audit history; database owners can still alter/drop triggers, so restrictive production database roles remain a launch gate. Suspension/revocation is enforced on each request and background context restoration, with no token revival after reactivation. No document collection or carrier address-validation claim.

No assisted MFA-reset capability is exposed: identity-proof rules, independent approval and notifications for that process remain undefined. Secure backup codes provide the supported recovery path. Production email/domain/TLS/cookies/proxy configuration, retention/legal review and restricted runtime DB privileges remain launch work. No deployment was performed.

Generic platform business-profile reads omit contact and registration/tax identifiers even with a view grant. Full submitted business data is available only through an independently approved verification-review capability. MFA writes recheck the locked user/current session and password so concurrent credential revocation cannot complete a stale factor mutation.
