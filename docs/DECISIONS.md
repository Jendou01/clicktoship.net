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

## Phase 8 implementation decisions

* Canonical import workflow is /api/v1/bulk/imports. The blueprint's proposed one-shot bulk-shipment endpoint is not implemented because Phase 8 creates drafts only and requires private scan/mapping/review/approval stages. No bulk carrier submission alias.
* Native bounded CSV/ZipArchive/XMLReader parsers use the published runtime's existing extensions; unsupported active/complex workbook content fails explicitly. Original bytes and row payloads are encrypted. Production processing is closed pending real malware scanning/shared storage/retention approval; structural inspection is not antivirus certification.
* Duplicate matches require explicit keep/skip rather than silent discard. Command replay and permanent per-row draft business keys are separate. Approval acknowledges unpriced drafts and does not accept a price or authorize charges.
* PostgreSQL work records/cursors are authoritative, with Redis/Horizon notifications, per-row reauthorization, expiring leases and bounded recovery. Cancellation ends at the first committed draft; completed references/history are never deleted as an undo.

## Phase 9 tracking policies

Use one existing carrier tracking history/projection; Shipping, finance and document dimensions remain independent. Receipt order is not truth: unknown/stale facts remain immutable history, conflicting terminal/forward-transition evidence becomes review cases. Exact provider reference lookup always includes merchant/account; same-tenant collisions remain unbound and audited. Default adapters and Phase 6 gates prohibit all live tracking/ingress. Synthetic protocols exist only under autoload-dev and guarded testing databases, never as live merchant timelines. Administrative reviews require current MFA and an independently approved target grant and append annotations only. Notification intents remain disabled and require explicit consent; retention hooks count candidates without deleting evidence. See Phase 9 architecture for provider-specific launch prerequisites.

## Phase 10 customs preparation decisions

* Typed customs is an optional Shipping revision extension; immutable declarations bind the exact source revision. Complete item aggregates are atomically replaced with optimistic versions rather than mutable child endpoints. Import synchronization is explicit; reads never silently create declarations.
* Country-rule review approves an evidence-backed preparation proposal only. External verification and runtime enablement remain code-owned false, even after independent review. Country catalogue membership is not route availability. HS codes are merchant data, never guessed classifications; applicability needs external policy.
* Only SAR is currently enabled. Item quantities are whole units, values integer minor-unit strings and line weights total grams. Package allocations reconcile quantities and optional weights; no FX/duties/taxes/insurance are invented.
* Existing label documents are constrained synthetic label records with incompatible provenance. Customs keeps a dedicated immutable document reference/work/result history, reusing private storage and Shipping DocumentScanner. TCPDF is the sole new dependency, necessary for native Unicode/Arabic PDF/font support; no remote resource/HTML/signature execution.
* Draft numbers are merchant/type counters with immutable per-version/language identities, not approved fiscal/legal invoice numbers. All pages say DRAFT / NOT CUSTOMS CLEARANCE. Default scanner quarantines; production generation/download await explicit storage, scanning, privacy and retention approval.
* Admin review annotates immutable declarations under recent MFA plus an independently approved session-bound target grant. It never clears a shipment or grants financial/carrier execution. No Phase 11 work is authorized.

## Phase 11 reporting and merchant experience

* Compose read-only existing module data, preserving authority and ownership. Reuse merchant.view plus per-source read permissions rather than introduce redundant role grants. Platform assignment is not merchant membership.
* Use explicit UTC inclusive calendar date ranges (maximum 366 days), event-specific source timestamps and separate current wallet balances. Never imply local customs readiness is clearance or an unexpired estimate is current route eligibility.
* Exclude synthetic/unverified acceptance and tracking from verified KPIs; label current recorded shipment distributions separately. No revenue, combined-currency money or invented projections.
* Serialize current authority with existing merchant/user lock order; no shared dashboard cache. Private/no-store responses and request generation fences prevent stale role/context reuse. Scale/lock tuning needs measured production evidence.
* Add only six read indexes and reusable bilingual UI primitives/navigation; no additional UI/chart dependency, ledger or tracking state machine. No Phase 12 or external preview/deployment scope.


## Phase 12 platform reporting decisions

* Aggregate source permissions are separate from target merchant capabilities; unauthorized source queries are omitted. Sessions/recent MFA remain mandatory; personal tokens do not become platform credentials.
* Reuse module state, approvals, exact grants and financial reads. Shared platform security locks plus current user/session locks serialize authorization with revocation. Private reads acquire exclusive security authority before reusing target services.
* No approved global amount disclosure policy: finance overview counts wallets/reservations/frozen/reconciliation separately by currency, and exact-granted views retain existing integer-string balances. Stop the global monetary capability pending policy; do not fabricate revenue or profit.
* Alerts derive from current facts with opaque HMAC identities; append-only actor/session read receipts neither resolve source issues nor send notifications. Current country drafts, carrier draft events and unrevoked current customs documents avoid stale-workload claims.
* Explicit UTC periods; stocks/current queues remain independent of the period. Synthetic carrier/tracking observations never count as verified activity. No pending-account status is invented.
* Health is redacted local observation, scheduler is independently unobservable, settings are a typed read-only catalogue. No unrestricted config/replay/activation control or production readiness claim.


## Phase 13 store integration decisions

* Official Salla sources returned proxy 403; do not substitute invented URLs, OAuth scopes/modes, API fields/statuses or webhook headers/events. A disabled runtime adapter and explicit internal DTO/test-only synthetic protocol isolate all unverified contracts.
* Permanently bind each provider/store identity to one merchant, including after disconnect. No self-service or administrator reassignment is authorized in Phase 13. Verified server-returned identity is mandatory; no browser store-ID claim.
* Use existing merchant/user/current-origin locks and role/token intersections, plus connection generations, rotating-refresh nonce/version and durable page leases. No SQL transaction holds network I/O. Unknown refresh outcome requires reauthorization; no blind refresh replay.
* Cancel pending/in-flight authorization intents on merchant disconnect. Two migrations preserve already-installed development history instead of editing migration rows or resetting normal data.
* Orders are minimized/encrypted immutable normalized snapshots; reference search is blind-indexed. Unknown/older/equal conflicting changes do not silently replace current data. New drafts remain blocked until authoritative source conflict is resolved; browser acknowledgement is insufficient. Existing drafts remain unchanged.
* Request replay and permanent order-to-draft business identity are separate. Catalog/merchant locks and Shipping.importDraft commit draft/link/outcome atomically. Bulk API orders reuse orchestration patterns without inventing uploaded files. No quote acceptance, wallet/carrier/COD settlement or external notification.
* Platform visibility is aggregate only. No provider-specific private platform grant route or standing background synchronization authority is invented. Current originating session/token can expire and block future work.
* No real-data retention/deletion period is assumed. Retained encrypted history is not automatically deleted; privacy/consent/key rotation/transfer/support policies remain launch gates. No new dependency or Phase 14.

## Phase 14 Shopyzee boundaries

* Separate permanent platform-instance identity from tenant/store identity and from mutable display domains. The unique tuple and ownership trigger survive disconnect; no transfer endpoint exists.
* Preserve Salla DI/routes and legacy Salla-default orders API. The unified merchant UI explicitly asks for all providers and renders per-row source from the scoped server join.
* Reuse Commerce sync/refresh/draft/batch/recovery/security engines. A persisted provider selects its adapter; Shopyzee pages and refresh results require exact normalized internal provider/instance/store evidence. Internal DTOs are not verified external mappings.
* All eight user-supplied official Nazmart plugin documentation pages are blocked by managed proxy403. No source version/order/auth/plugin contract is verified. A separately reviewed connector using actual supported plugin lifecycle/routes/hooks is proposed; no fabricated or live-installed plugin is delivered.
* Shopyzee authorization method remains null, with no callback invented. Live connect/sync/hooks and fulfillment write-back remain disabled. Existing shipping/financial gates are unchanged. No Phase 15 or deployment.


## Phase 15 — source-owned analytics, bounded export safety

Analytics reuses existing module facts and Operations read models, with no ledger/order/tracking replicas or speculative materializations. Metric definitions precede implementation; event cohorts and current stocks are distinct. All periods are UTC; money is exact currency/exponent-separated integer text. Missing accounting/evidence projections are unavailable rather than estimated as facts.

Only approved fixed report columns/filters are executable. Merchant source permissions intersect tokens; platform count-only permissions never create private target grants. Export source reads use repeatable-read, with live authority checked again before publication. Durable lease/recovery and append-only origin/audit records prevent request/business duplicates and orphaned unsafe downloads. CSV UTF-8 and minimal inline-string XLSX need no additional application dependency. Normal downloads are closed until storage/scanning/retention are approved; isolated testing is not an activation mechanism. No new notifications, real carrier or financial operations are introduced.


## Phase 16 pre-deployment hardening

Preserve all source-owned financial, carrier, Commerce, document and export gates. Log only static event codes, selected identifiers/metrics and exception type/fingerprint; raw messages, bodies, SQL/context and traces are discarded at configured sinks. Apply private/no-store uniformly to API/CSRF responses, and require exact hosts outside local/testing. Non-development configuration preflight is read-only and cannot approve deployment/activation.

Replace per-row shipment revision reads with one bounded exact merchant/revision batch, preserving only the original approved summary fields. Reuse exact monetary strings in shipment UI and name actual table elements. Pin official Action commits; retain declared-license/image inventories without asserting signed provenance. Benchmark and restore only isolated synthetic data; tests do not infer production SLA, PITR/key/object policy or provider compatibility. No new domain migration, real financial operation or Phase 17 is introduced.

Queue failure metadata is sanitized independently at default failed_jobs/Horizon persistence boundaries; application log taps alone do not protect these stores. Preserve the exact encrypted retry payload and job callbacks/business exception type; no automatic retry policy changes. Non-development preflight excludes unreviewed alternate failure drivers. Real-data diagnostic retention/access remains operationally unapproved.

Negative login work uses one configuration-scoped, non-credential cached dummy hash, warmed/read before identity lookup on all attempts. It contains no user/merchant key or password, changes with driver/options and cannot authenticate a missing user. This removes per-unknown-identity hash generation without artificial delay, credential-result caching or throttle changes. Legacy hash cost migration and remote timing behavior are not claimed constant-time by local measurements.

## Phase 17 — protected staging and release separation

Use standalone non-development Compose artifacts rather than modifying retained development volumes. Release PHP-FPM/standalone Next/gateway are non-root; DB/Redis/internal services stay private. Exact gateway IP trusts only client IP/protocol, never forwarded host. Native secret bind ownership is checked rather than assuming Compose remaps uid/mode. Separate maintenance and runtime DB credentials; zero-wallet insertion is column-limited, money mutations retain the existing function boundary. Horizon defaults must not inject the development pool into separate critical/background plans. No production plan or live activation is supplied.

Manually approved CI packages only reviewed main SHA with hosted tests; it never SSHs or deploys. A local artifact/rehearsal does not authorize a host. Authenticated AES-GCM/CMS backups use a separately escrowed recipient key; offsite/key/storage/retention approvals remain external blockers. App rollback preserves schema/history and requires explicit compatibility proof; same-image recreation is not cross-version rollback certification.


## Separate disposable remote demonstration policy

Remote demonstration is an explicit demo-only opt-in, exact host/database/project-bound, with synthetic-only identities, volatile tmpfs data, no swap and accepted permanent loss. It does not weaken Phase 17 staging/production backup, storage or release preflight. Retained data cannot use this profile. A separately reviewed signed Docker archive manifest authenticates demo artifact review; it does not assert signed build provenance. Existing money/carrier database guards and two-person administrator MFA onboarding remain intact. No automatic deployment, provider activation or new product phase.
