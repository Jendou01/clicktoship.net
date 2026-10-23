# Architectural decisions

| ID | Decision | Reason and consequences | State |
|---|---|---|---|
| ADR-001 | Modular monolith; apps/api + apps/web | One consistency boundary; independent web/worker processes, no microservices | Accepted |
| ADR-002 | Laravel 12 / PHP 8.3, Next.js 16 / Node 24, PostgreSQL 17, Redis 7 | Blueprint pins Laravel 12; explicit lockfiles and Docker/CI toolchains | Accepted for foundation |
| ADR-003 | Canonical merchant_id and UUID identities | Reconciles tenant/merchant naming; global users, membership-based access | Accepted; enforcement Phase 2 |
| ADR-004 | /api/v1 prefix; /rates/quote canonical | User request wins over blueprint /v1/quotes; no alias until clients need one | Accepted |
| ADR-005 | Immutable double-entry ledger + auditable holds | Concurrency and replay safety; currency-specific balancing; no implicit FX | Accepted; accounting signoff pending |
| ADR-006 | Transactional outbox; uncertain submissions quarantined | Network timeout is not proof of rejection; avoid duplicate billable AWBs | Accepted |
| ADR-007 | Carrier capabilities distinguish documented/verified/enabled | Postman examples do not establish entitlement or production correctness | Accepted |
| ADR-008 | No live carrier/PSP operations in foundation | Missing contractual/technical evidence must not block independent work | Accepted |
| ADR-009 | Blueprint webhook details provisional | Actual webhook files absent; do not implement speculative authentication/payload | Pending evidence |
| ADR-010 | Sanctum session auth for same-origin web; scoped hashed tokens for API | CSRF protection for cookies; tokens never replace membership policies | Accepted; endpoints Phase 2 |
| ADR-011 | Redis queues/cache; PostgreSQL persistence | Horizon installed, dashboard disabled in foundation; no unauthenticated monitoring UI | Accepted |
| ADR-012 | PostgreSQL integration tests | SQLite cannot validate composite constraints, concurrency or ledger triggers | Accepted |
| ADR-013 | Private object storage before document/import phases | Local private disk in foundation; S3 provider/region selected later | Deferred |

The cloud package network blocks GitHub API ZIP downloads. Setup primes Composer 2's normal cache with codeload ZIPs at locked commit references, verifies integrity and supplied checksums, and installs with --prefer-dist. Source fallback is disabled after full-history clones exhausted installation storage. No lockfile URL/reference rewriting or TLS bypass is used. The helper is tested and scoped to public GitHub packages.

Changes to an accepted decision require a rationale, migration/compatibility impact and tests. Version constraints express supported major versions; lockfiles fix exact application dependencies. Docker base tags require scheduled updates and digest pinning for production deployment.

## Phase 2 decisions (2026-10-23)

* Keep merchant selection in each authenticated browser session, not globally on users; tokens bind to exactly one merchant and never switch.
* Seed four platform and six merchant role definitions from the explicit Phase 2 request. Initial memberships have one role, catalogues are read-only, and platform assignment storage has no public mutation API. No Super Admin bypass or exposed MFA-less admin route.
* Use merchant row serialization plus expected membership versions, reauthorization under lock and a last-owner invariant; optimistic validation alone cannot prevent concurrent owner loss.
* Keep server-revocable session registry hashes alongside Redis sessions; reset increments authentication version and revokes every credential.
* Use encrypted queued private-email notifications and fragment-to-POST link consumption; reject logging transports. Mailpit is development-only.
* Add existing verified users directly to teams; invitation acceptance/delivery and custom roles are later product scope. Do not simulate invitation success.
* Force and guard the dedicated PostgreSQL test database in both PHP environment/server state before destructive migration traits. Preserve tests using real Redis and separate PostgreSQL processes.
* Keep token scope in queued work by capturing its token ID; execution rechecks revocation, expiry, version and narrowed permissions. Session-originated work carries actor/merchant permission and is similarly reauthorized.
