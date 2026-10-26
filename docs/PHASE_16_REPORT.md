# Phase 16 — Security, Performance, Resilience & Final QA

## Scope, baseline and delivery

Verified/fetched latest main and Phase15 PR15 merge `47e40dd49e32650b52a552277757fbbd9af4bc3e`, clean working tree, then created `feat/phase-16-security-performance-qa`. Read AGENTS, supplied Technical Blueprint, architecture/decisions/open questions, roadmap and prior phase reports/evidence boundaries. Existing code and commit history were preserved; no worktree, domain/production deployment, live integration/financial operation, customer notification, automatic merge or Phase17 work.

Baseline before application changes: **453 backend tests / 5,166 assertions**, **124 frontend tests**, no known Composer/npm advisories. Development Compose/services/data were inventoried. Full source/risk inventory, practical threat model, remediation and required owners/statuses are in [SECURITY_AUDIT](security/SECURITY_AUDIT.md) and [THREAT_MODEL](security/THREAT_MODEL.md).

## Fixed and hardened

1. Configured application log sinks retain static codes, approved identifiers/metrics, correlation and exception type/fingerprint; raw message/context/body/SQL/trace is discarded. Exception messages are redacted even if they resemble uppercase event codes. Real sink and HTTP error tests verify both privacy and operational identifiers.
2. Separate failed-job/Horizon persistence is sanitized through default UUID database/Horizon adapters. Real encrypted Redis job failure proves the exact retry payload/UUID is preserved and decryptable, while exception metadata and console output omit the private diagnostic. Business exception handling, retries, carrier outcomes and financial state are unchanged. Alternate diagnostic drivers cannot pass non-development preflight without review.
3. API success/error and CSRF cookie responses uniformly use private/no-store. Request IDs and status/Retry-After/Allow semantics remain intact.
4. Exact host allowlist and non-development fail-closed host gate; read-only `security:check-config` checks secure cookies/origins/encrypted sessions/key presence/restricted actual DB role/closed gates/reviewed diagnostics. The inert staging example has reserved test host and empty secrets. Passing this configuration check is not deployment or activation authority; current development appropriately fails it.
5. Three official GitHub Actions pinned to actual remotely resolved v4 commit IDs; read-only token permission and existing test assertions/timeouts remain. Added first-party bounded committed-artifact/secret-pattern scanner with negative tests; no external scanner/provenance certification claimed.
6. Shipment quote display reuses exact string money formatting, with explicit currently supported SAR precision; unknown currency is unavailable. No financial posting logic or limits were changed. Every digit beyond JS safe integer is verified in a UI regression.
7. Actual shared and legacy tables receive bilingual accessible names. Existing labels, keyboard behavior, responsive cards/regions, validation focus and RTL/LTR semantics are preserved. Formal WCAG/screen-reader certification is not claimed.
8. Shipment directory N+1 removed: one bounded query fetches exact tenant/shipment/revision pairs, retaining only the original approved summary columns. One/25-row query counts match; another merchant and old revision cannot leak. **32→8 SQL queries** for a 25-row page; no new index/migration or operational data copy.

9. Removed extra identity-dependent dummy hash generation on unknown-account login. A non-credential Redis-cached digest is scoped only to hashing configuration and warmed/read before every identity lookup, including a cold cache. This preserves one configured password check on both negative paths without sleep/throttle changes or caching real user data. Cold/warm behavior, configuration separation and missing-user denial even when the dummy password matches are tested. Current password/status/DB authority is unchanged.

No second ledger/order/tracking/pricing store, application dependency, financial policy or provider protocol is introduced. Production/private export/document gates remain unchanged.

## Security and domain evidence

All Phase0–15 tenant/IDOR/RBAC/MFA/session/token/independent-grant/approval/financial/shipping/pricing/bulk/tracking/customs/Commerce/analytics regression suites remain enabled. They cover cross-tenant references, current role/merchant/session/token changes, expiry/revocation, exact immutable money/currency/quote evidence, concurrent operations, ambiguous carrier outcomes, webhook/callback replay, upload/ZIP/XML/formula safeguards, quarantine and private artifact/download authority. Source module inventory maps every boundary to named tests; absence of a failure is not a formal proof against all attacks.

New worker recovery kills an independent export process at a real PostgreSQL scanner barrier, advances only its abandoned disposable lease and verifies one recovered ready artifact, attempt/audit preservation and cleared context. An encrypted real Redis probe measures queue wait/processing in another worker. Failed-job privacy is tested against actual PostgreSQL and Horizon Redis metadata, not only mocks.

Aramex transport/evidence, real money/funding/charges/COD, Salla/Shopyzee and normal private exports remain closed under their existing server-side gates. No Super Admin private-tenant bypass is added. Normal broker/DB services are never intentionally faulted or flushed.

## Performance and concurrency

[BASELINE](performance/BASELINE.md) records p50/p95/p99, samples, throughput/error counts, SQL min/max/time, payload bytes, PHP CPU/peak, plan observations, hardware/container conditions and limitations. [LOAD_TEST_PLAN](performance/LOAD_TEST_PLAN.md) commits reproducible guarded commands.

Four opt-in benchmark tests pass separately from the full suite (629 assertions), measuring 18 operations. Final configured bcrypt12 samples: valid login p50/p95/p99 **219.468/237.377/440.610ms** (maximum includes identity-independent cold warming); existing/wrong **219.301/230.723/239.352ms**; unknown **216.206/226.403/230.891ms**, compared with unknown p50421.929ms before the fix. No universal timing-immunity/legacy-cost claim. Shipment list **7.019/7.953/8.747ms**, constant8 SQL queries versus32 previously.

Actual loopback Nginx HTTP: **264 requests**, concurrency1/2/4, zero errors, four repeated draft commands produce one ID and84 CSRF/cookie-authenticated merchant switches each verify current context. At concurrency4, bearer workload p50/p95/p99 **133.244/186.163/190.522ms**; exact measured values are recorded in BASELINE/RESULTS. Session switching observed **94.052/107.819/121.855ms**. Real encrypted Redis probe separately measures startup-inclusive queue wait and processing, not production Horizon percentiles.

Small samples/development server/shared host cannot establish production SLA/capacity. Expected negative authentication responses are reported separately; they are not hidden HTTP failures. Workloads that still require representative staging soak, reserved critical queue capacity, production-like artifacts/roles/ingress and actual provider sandbox evidence remain blockers. No existing throttle/assertion or timeout was weakened.

## Backup/restore and dependency faults

Two private disposable-cluster rehearsals executed. Final: **137 tables / 2,658 rows**, **875,321-byte** custom-format synthetic backup; source/restored SHA-256 row fingerprints match. Fresh24 migrations/schema pass; 10 encrypted revision samples decrypt with the preserved key and reject a random wrong key. Immutable ledger remains balanced per transaction/currency. A private synthetic encrypted object is copied/restored and its digest/key verified.

Only a created internal network/new PostgreSQL/Redis containers are faulted, with no published ports or development volume attachment. A tagged uncommitted SQL session is terminated and rolls back. Private Redis and PostgreSQL each return readiness503 during outage and200 after restart; final durable source fingerprints remain unchanged. Restore+verification **5.894s**, full rehearsal **55.622s** — not a production RTO claim. Private temporary dumps/objects and exact created containers/volumes/network are cleaned; normal development is restored without reset.

[BACKUP_RESTORE](operations/BACKUP_RESTORE.md) documents key custody, immutable object/database consistency, quiescence, version/privilege checks, restored credential revocation, protected holds/unknown outcomes, independent reconciliation, ownership and proposed unapproved RPO≤15m/RTO≤4h. Real managed PITR/offsite/object storage/key escrow are not certified. Readiness outages were tested; a production-scale long-running broker/worker outage/soak is not claimed.

## Build, supply chain and configuration

Complete frontend **127 tests /19 files**; ESLint/TypeScript pass. Next production builds produce203 pages; two consecutive same-source builds have identical202-key prerendered route manifests. This is structural repeatability, not byte-identical/signed artifact reproducibility. Dependency locks remain unchanged, Composer strict validation/audit and full/runtime npm audits pass with zero known advisories. Nine Python installer/scanner regression tests pass. OpenAPI3.1, unique YAML keys, all local references and223 unique operations validate. Laravel Pint passes all356 PHP files; first-party bounded current-tree scan reviewed594 tracked files with zero findings (not exhaustive history/entropy or external scanner certification); no skipped/disabled test is represented as validation.

Declared license/version inventory: **679 lockfile entries** (114 Composer /565 npm), including one unclassified own workspace license requiring repository-owner declaration, not an asserted third-party licensing violation. [DEPENDENCIES.json](security/DEPENDENCIES.json) is declared metadata only; [RUNTIME_IMAGES.json](security/RUNTIME_IMAGES.json) records locally observed digests, not signed provenance. Development images/apt/runner mutability, production non-root/capability/resource manifests and actual least-privilege DB provisioning remain unapproved. Trivy/gitleaks/syft were not installed or claimed.

Configured logger and default failure-store diagnostics are minimized; infrastructure/emergency logging, alternate failure providers, retention/access and private forensic escalation still need operational review. No new public monitoring/admin endpoint or external notification is introduced. Existing protected health/work/error/reconciliation/provenance/expiry signals and proposed non-activated thresholds/owners are in LAUNCH_BLOCKERS.

## Browser and accessibility

**120 actual views** across merchant and platform workflows, English/Arabic, desktop1440×1000/tablet820×1180/mobile390×844: zero unexpected authorized API failures/JS errors/horizontal overflow, no unnamed tables/unlabelled visible inputs; keyboard focus navigation checked. Registration, verification/onboarding, teams/revocation, quote/draft, bulk, tracking, customs/quarantine, wallet, both synthetic Commerce providers, exact admin grants and analytics/export boundaries are exercised by the full existing browser/backend journeys. Synthetic authorized export download/expiry/revocation is an API/worker test; normal UI download remains disabled.

Representative screenshots and machine-readable DOM/keyboard/responsive inspection are outside Git: `/workspace/shared/clicktoship-phase16/README.md`, `index.html`, `inspection.json`. No credentials/raw attachments/customer PII are included. This is not an interactive preview, formal screen-reader/WCAG audit or provider certification.

## Readiness and unresolved launch decisions

| Capability | Verdict |
|---|---|
| Local synthetic development | READY WITH CONDITIONS |
| Staging deployment | BLOCKED |
| Production infrastructure | BLOCKED |
| Real Aramex shipping | BLOCKED |
| Real wallet/payment/funding/settlement | BLOCKED |
| Salla | BLOCKED |
| Shopyzee | BLOCKED |
| Production customs/documents | BLOCKED |
| Normal analytics private exports | BLOCKED |

Evidence/prerequisites by capability are in [STAGING_READINESS](operations/STAGING_READINESS.md), [LAUNCH_BLOCKERS](operations/LAUNCH_BLOCKERS.md) and the risk register. Required decisions: qualified accounting/regulatory/provider evidence; current carrier/store/deployed-version contracts and sandbox proof; secure storage/scanning/retention/residency; actual runtime privileges/TLS/proxies/secrets/release artifacts; managed backup/key/object recovery and approved targets; reserved worker/capacity/observability/on-call; independent repository/security/UX review. Unresolved items are not marked fixed/accepted or production-ready.

## Final execution and Git workflow

Final complete backend: **466 passed /5,303 assertions**, zero failed/skipped. Final targeted authentication/log/failure-privacy check: **10 passed /78 assertions**. Opt-in performance: **4 passed /629 assertions**, separate from backend regression count. Frontend **127 passed /19 files**, browser **32/32 passed** after final authentication/diagnostic changes. Earlier complete464/463/460 runs also passed; they are checkpoints, not added into final counts. Intermediate new test/harness failures were corrected and rerun rather than skipped or counted successful.

Normal development restored with scripts/start.sh: PostgreSQL/Redis/schema readiness, both locales through Nginx and Horizon pass. Normal data verified **23 users /24 migrations /0 ledger transactions /0 shipments /0 report exports**, unchanged from baseline except no new domain migration was needed. Only guarded test databases and newly created private cluster were rebuilt/faulted; no normal Redis flush or volume reset. No private QA containers remain. Existing normal business/history records were not rewritten.

Final commit/push and PR access results follow in delivery; the complete manifest includes source fixes, regression/QA tooling, inert configuration and evidence docs only. Temporary credentials, dumps, objects and screenshots remain outside Git.

[PHASE_16_FILES.txt](PHASE_16_FILES.txt) is the complete changed-file manifest. Commit/remote branch SHA and GitHub PR/API results are reported with delivery. No main merge is authorized.
