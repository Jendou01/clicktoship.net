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

## Phase 3 approved decisions

* Account active/suspended/closed, verification unverified/pending/verified/rejected and evaluated shipping eligibility are independent. Preserve existing activation during backfill; fail closed for all shipping in Phase 3.
* Manual business-data review only; no documents or external KYC integration. Profile changes require review of a new immutable snapshot, never editing approved history. This is not legal/regulatory certification.
* Built-in roles only. Owner-only business attestation, Owner/Admin profile/settings/team management, Shipping Manager address mutation, other shipping/viewer address reads and Finance limited profile/address reads. Server grants/token intersections remain authoritative.
* Every platform administrator must confirm MFA and step up within 15 minutes. Request/approve merchant access separately; an independent MFA-authenticated Super Admin approves target/capability-specific session-bound grants. No self-approval or global bypass. Grant lifetime counts from request, not approval.
* Bootstrap two existing independently controlled verified MFA-enrolled users exactly once through an audited CLI command; no production/default accounts or passwords. Platform assignment changes invalidate target credentials and grants and preserve the last usable Super Admin.
* Closed merchant accounts cannot reopen through Phase 3 APIs; suspended accounts can reactivate under approved access. Security revocation is permanent for old credentials. No user/merchant hard deletion.
* Backup-code recovery is supported; assisted/admin MFA reset is explicitly unavailable pending identity-proof, dual-control and notification requirements. Loss of all factors is not grounds for a bypass.
* Encrypted private-mail invitations expire after 72 hours, rotate on resend, recheck inviter privileges at acceptance and never overwrite an existing membership. Existing Phase 2 direct-add remains available for compatibility.
* E2E administrators exist only in an opt-in testing database with generated private temporary credentials. Normal setup never seeds privileged accounts. The fixture refuses normal development/production targets.

## Phase 4 approved policies and implementation decisions

* SAR is the initial enabled currency; architecture remains currency-specific with explicit precision and no FX. All API amounts are minor-unit strings; no floating-point arithmetic.
* Use immutable double-entry journals and mutable, database-checked posted/reserved projections. Reservations do not recognize revenue; captures use provisional service clearing. Chart/templates are database configuration under migration review, not arbitrary client-selected postings. Accountant approval remains outstanding.
* No negative available balance, implicit credit facility, artificial opening payment, real PSP, funding, settlement, payout, withdrawal or shipping charges. DisabledPaymentProvider is the only runtime binding; synthetic adapter lives under autoload-dev Tests.
* Financial corrections require a second independently authorized person plus existing target grants/recent MFA. All amounts use dual control conservatively. Approval expires within 15 minutes, binds immutable payload/reason and originating sessions, and rechecks current authority under locks. Normal correction execution remains hardcoded disabled pending professional review.
* Request and permanent business-operation idempotency are separate. Outbox processing uses atomic local consumer receipts with SKIP LOCKED; no exactly-once network delivery claim. Financial history never expires with a response cache.
* Unknown/protected reservations remain reserved past timeout and cannot be force-released by expiry/structural reconciliation. External resolution is blocked pending authoritative carrier evidence and an approved independent decision contract.
* Initial financial writes serialize on the merchant root for consistency with Phase 3 revocations. Correctness precedes lock-granularity optimization; no measured production performance SLA is claimed.

## Phase 5 pricing decisions

* Approved scope: deterministic internal static estimates, immutable versioned snapshots, confidential cost separation and zero wallet/shipping effects. No Aramex tariff/tax/availability is inferred from the illustrative 14 SAR input.
* Reuse integer `Money` and currency catalog; only SAR enabled, no FX. Require explicit verified tax treatment, service capabilities and city classification; unknown cities/routes fail closed.
* Merchant > tier > carrier > global, then explicit selector specificity. Reject ambiguity. Versions use common closed configuration variants with generated relational FKs/bands, avoiding duplicate history schemas.
* All publication/disable operations use independent current authority and recent MFA; merchant-specific work requires separate approved session-bound grants for both parties. No tenant bypass.
* [Calculation, security, API, retention and approval details](architecture/PHASE_5_PRICING.md). Accounting/tax/contract and live-carrier launch approvals remain outstanding.

## Phase 6 evidence-gated carrier connector

* Current V2 responses, webhook authenticity/status catalogue and sandbox authorization are missing. Implement request/configuration/reliability foundations independently; code-owned gates prohibit all external execution and production. Never translate legacy SOAP evidence into current JSON verification.
* Version and encrypt master account secrets and candidate route terms; independent current MFA-authenticated approval changes configuration only, never capability verification or merchant eligibility.
* No automatic retry of uncertain mutations, unknown-state reset, client-reference resolver, label URL download or webhook authentication protocol. Scope/deduplicate persistent intents and retain encrypted append-only facts; verified mappings and full workflow await evidence/later phases.
* [Evidence matrix](integrations/PHASE_6_EVIDENCE.md), [architecture](architecture/PHASE_6_CARRIERS.md). No changes to financial posting or pricing invariants.

## Phase 7 approved decisions

Single-use quote acceptance binds full revision and canonical pricing inputs; valid at reserve and dispatch, fixed thereafter. Carrier evidence persists before finance. Unknown outcomes retain protected funds without external retries or timeout release. Independent reconciliation appends an effective decision without editing terminal carrier evidence. Typed encrypted revision snapshots avoid duplicate mutable PII tables. Default carrier and document scanner are disabled; synthetic adapters remain autoload-dev and isolated-database guarded. No real charges or deployment.
