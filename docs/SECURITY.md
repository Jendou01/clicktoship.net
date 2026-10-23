# Security baseline and launch gates

## Implemented foundation boundary
No merchant, carrier, payment or administrative business routes are exposed. Carrier/payment flags default false and are not implementations. Sanctum and Horizon are dependencies; authentication/tenant policies arrive in Phase 2. Horizon routes/dashboard are disabled until admin authentication, MFA and authorization exist. Health endpoints reveal only status/check names and correlation IDs, never exception messages, versions, connection strings or secrets. Readiness probes PostgreSQL, Redis and schema migration presence; liveness does not require dependencies. Runtime disables debug by default.

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
