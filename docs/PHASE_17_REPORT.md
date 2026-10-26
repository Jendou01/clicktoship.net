# Phase 17 — VPS deployment readiness, protected staging and launch preparation

## Scope and repository evidence

Fetched main explicitly, verified Phase16 PR16 merge `a211ca10e9f33313a73e48acece01b8d43523931`, fast-forwarded clean main and created `feat/phase-17-deployment-readiness`. Read AGENTS, supplied Blueprint, architecture/decisions/open questions, previous audit and backup/readiness/risk reports. Preserve code/history and normal development data. No remote host accessed, purchased, provisioned or deployed; no DNS/certificate issuance, tunnel, production deployment, live carrier/payment/Commerce/COD/customs/export enablement, external notification or automatic merge.

Phase16 verified baseline:466 backend/5303 assertions,127 frontend,32 Playwright and24 migrations. Current migration/config/runtime baseline is captured independently. Normal development appropriately fails non-development security preflight; it is not weakened. This environment has a32GiB filesystem and is below the proposed VPS storage minimum; parallel builds/rehearsal caused disk exhaustion, including one full-suite DB setup failure. Targeted cleanup of this task's disposable containers/volumes/cache and serialized reruns are required; failed runs never count as success. An earlier in-progress full test also loaded an old test expectation while proxy checks changed; only the final complete frozen-source run is authoritative.

## Architecture and delivery

[Architecture](deployment/ARCHITECTURE.md) separates non-root protected TLS gateway, standalone Next and PHP-FPM from PostgreSQL/Redis, independently reserved critical/background Horizon processes, singleton scheduler, private storage and one-shot maintenance migrations. Standalone Compose is never overlaid on retained development. Only loopback18443 is published; external browser access is not supplied by localhost. The backend internal network prevents external providers/mail; approved private SMTP/controlled egress remains a staging prerequisite.

Pinned official base manifests were actually resolved/pulled; three multistage artifacts were built locally. API uses locked production Composer dependencies and no tests/.env; Next uses locked Node24 dependencies/standalone output; gateway strips its Basic credential from upstreams. Optional managed public CA is supplied only to builder stages via a build secret; the fresh runtime stage excludes that trust anchor, Composer and compiler tools and asserts all required PHP extensions load; existing lock-validated Composer archive cache addresses blocked GitHub API archives without TLS bypass or lock changes. Local images contain current working source and parent-SHA labels: they are simulation artifacts, not asserted reviewed/signed release artifacts. Manual CI builds only exact reviewed main SHA with hosted Foundation CI evidence; no remote deploy step or credentials exist.

Compose uses uid10001, read-only roots, drop-ALL/no-new-privileges, explicit memory/CPU/PID/tmpfs caps, bounded logs and private volumes. PostgreSQL initialization retains official privileged setup; not claimed rootless. Redis uid999/password/noeviction, no ports. Native bind-secret ownership must be set explicitly; Compose does not remap file uid/mode. Separate API/runtime and maintenance secret files; no password in migration argv. Offline secret/image checks and actual application preflight are separate; both lack remote deployment authority.

Runtime SQL revokes schema creation and direct financial writes, grants reviewed application table DML and column-limited **zero-balance** wallet/account creation. Only existing finance_command can change amounts. Column-only frozen updates support existing reconciliation and PostgreSQL row-lock privileges; no monetary/version update grant exists. The137-table name inventory aborts grants on any unexpected schema change. Runtime is neither schema/function owner nor superuser/create-role/create-db/bypass. Current tenant/RBAC/MFA/grant checks and composite constraints remain authoritative; no RLS or protection from a compromised migration administrator is claimed. No business migration/new HTTP endpoint/dependency is added.

Fixed Horizon plan inheritance: defaults are merged into every environment by the actual ProvisioningPlan, so leaving the development supervisor in defaults would silently add shared workers to staging. Defaults are now empty, local/testing retain their original explicit plan, staging role plans are disjoint and invalid role has none. Tests inspect the real provisioning plan. Critical1/background1,60s timeout<90s retry, tries1 and90s graceful stop; no unknown provider replay or increased retries. Production plan remains absent/blocked.

Exact trusted proxy IPs are explicit and validated by security:check-config. A red/green real HTTP regression proved early environment reads could ignore loaded/cached configuration; TrustedApplicationProxy now resolves exact IPs after configuration boot and rejects wildcard trust. Trusted/untrusted client-IP and protocol behavior and forwarded-host rejection are tested. Only forwarded protocol/client IP trusted, never forwarded host. Gateway pins actual host/TLS, blocks private/debug paths, bounded upload/request limits and security headers. The first cold Chromium journey reproduced edge503 on Arabic navigation: hashed Next chunks consumed the shared dynamic ingress budget. Static chunks now retain the same Basic gate with a separate bounded50r/s/burst100 budget; dynamic/API10r/s/burst40 is unchanged and edge throttling returns429. No delay, timeout increase or skipped assertion. Full nonce-based CSP, real ingress/firewall/cipher/certificate lifecycle and protected environment reviewers need actual-host acceptance.

## Local simulation and backup evidence

Final fresh unique project: **15/15 local checks passed**, application preflight16/16 (also after actual config:cache) and offline simulation preflight14/14. All8 services healthy,24 migrations and schema verified under actual cts_runtime. HTTPS verifies generated certificate/hostname; no TLS validation bypass. UI/API/static assets reject missing gateway credentials, unknown host closes, debug/private paths404, unauthenticated app request401. Real CSRF registration succeeds and leaves email unverified; private SMTP remains unavailable, no external email is delivered.

**12 Chromium views** (EN/AR homepage/login/registration × desktop/mobile) pass against the actual production-build gateway/frontend; private temporary NSS trust is removed afterward. DDL and monetary writes denied under runtime; existing Ledger zero-wallet provisioning and Reconciliation row locking work with column-limited permissions. Critical/background Horizon restart succeeds, readiness recovers within the bounded45-second observation (HTTP status counts {"200": 1}); Docker DNS now resolves changing service addresses rather than retaining startup IPs. Preserve framework cache/session/view leaf mountpoints so Laravel's view cache directory exists with read-only parents.

Recreating the exact recorded API/web/gateway IDs passes with no schema downgrade. Writers are stopped; AES-GCM/CMS encrypted dump/object manifest restore into another disposable DB passes24 migrations, schema and object hashes; preserved APP_KEY decrypts the restored object while a random key fails, and corrupt backup ciphertext is rejected. Portable --no-acl backups now explicitly restore the non-public finance_command ACL; pg_proc/aclexplode confirms no PUBLIC execution after recovery. No offsite storage is accessed. Generated credentials/keys/plaintext/ciphertext/test DBs/containers/volumes/network are removed afterward; normal retained resources are not reset. [LOCAL_EVIDENCE.json](deployment/LOCAL_EVIDENCE.json) records actual redacted booleans/image IDs and scope. These are local working-source/parent-labelled images, not approved release artifacts.

Authenticated encrypted backup tool quiesces writers operationally, creates/catalog-checks consistent dump + object tar/manifest, encrypts with CMS AES256-GCM to a recipient certificate, checksums ciphertext and removes plaintext temporary files. Decryption key/APP_KEY remain separate escrow responsibilities. Local encrypted archive is not an offsite backup; no destination, retention, escrow, PITR, production RPO/RTO or remote restore is certified. See [backup recovery](deployment/BACKUP_RECOVERY.md) and inherited Phase16 recovery evidence.

The simulated release rollback is **same-image recreation**, not cross-version compatibility. Retained data/schema is not rolled back. Actual older-image rollback requires tested post-upgrade compatibility; otherwise maintenance plus forward repair or independently approved incident restore/reconciliation. Runbooks never use destructive retained-data reset/downgrade or claim irreversible history is reversible.

## Full verification

| Verification | Final executed result |
|---|---|
| Complete Phase0–17 backend regressions on real PostgreSQL/Redis | **469 passed /5,336 assertions**,499.91s; no failure/skip |
| Targeted deployment/proxy/config/diagnostic HTTP regressions | **10 passed /78 assertions**; includes a reproduced failing proxy test before its fix |
| Existing Phase16 opt-in performance suite | **4 passed /629 assertions**,34.36s; separate from regression count, no new SLA/capacity claim |
| Full frontend | **127 passed /19 files** |
| Full Playwright with installed Chromium | **32/32 passed**,3.6m, including merchant/platform/EN/AR/mobile journeys; development E2E scope distinct from production-build smoke |
| Protected production-build Chromium smoke | **12 views**,EN/AR × desktop/mobile × homepage/login/registration, certificate verification enabled; no static-asset HTTP failure or JS/layout error |
| Python installation/audit/deployment regressions | **14 passed**, no skip |
| Pint | **358 PHP files pass** |
| ESLint / TypeScript | Pass |
| Standalone Next production build | **203 pages**, actual built artifact served in rehearsal |
| Release runtime | Required PHP extensions load; runtime has no Composer/compiler or builder-only CA; FPM/gateway non-root/read-only startup validated |
| Composer | Strict validation and audit pass, zero known advisories |
| npm | Full and runtime audits report zero known vulnerabilities; locked npm ci build succeeds |
| OpenAPI/YAML | OpenAPI3.1,223 unique operations/local refs/unique explicit keys pass; no new HTTP endpoint |
| Inactive HTTP redirect | nginx -t passes under non-root read-only gateway, no port published |
| Fresh migrations/restore/security |24 migrations, schema and actual runtime role/config checks; private restore/key/object/finance ACL evidence below |

Final bounded source/artifact scan reviewed626 tracked files with zero findings; all48 changed paths match PHASE_17_FILES.txt. Exact delivery Git status accompanies the commit; bounded first-party pattern review is not an external/history/entropy scan. No dependency lockfile changed. Prior intermediate complete468/5325 run is a checkpoint, not added to final counts. Source was frozen for the final469 regression; later runtime-stage packaging/grant tests use the same application source with separate release rehearsal validation.

No skipped/disabled tests or arbitrary timeout increases. Intermediate build failures were network-specific (GitHub API403 / missing managed npm CA), fixed with existing verified archive cache and secret CA; no TLS bypass. Intermediate Compose tmpfs flow-list parsing, synthetic secret test-reader ownership and copied public FPM/Nginx config modes were corrected before passing rehearsal. Explicit0444 config copies preserve non-root reads even under the private checkout umask; secrets are not made public. Disk failure is infrastructure evidence, not hidden as a passing run.

## Readiness verdicts

| Capability | Verdict | Evidence / outstanding gate |
|---|---|---|
| Reproducible local deployment | READY WITH CONDITIONS | Separate pinned builds and documented isolated rehearsal; disk capacity, local-only synthetic scope, not signed artifacts |
| Secure staging configuration | READY WITH CONDITIONS | Reviewed template + local preflight/TLS/roles/worker evidence only; actual host/mail/storage/keys/backup/owners unverified |
| Actual staging VPS | BLOCKED | Exact host/region/domain and separate authorization absent; host hardening/offsite recovery/private mail/monitoring/release approval not provisioned |
| Interactive owner browser access | BLOCKED | Only internal loopback simulation exists; no authorized external HTTPS gateway/URL or private credential delivery |
| Production infrastructure | BLOCKED | Host/release/provenance/HA/PITR/capacity/monitoring/incident/cross-version rollback not accepted; no production worker plan |
| Aramex live shipping | BLOCKED | Current official contracts/sandbox/account/services/reference/unknown/label/tracking/webhook/COD signoff missing; transport closed |
| Real finance | BLOCKED | Accounting/legal/regulatory/safeguarding/tax/provider/funding/charge/settlement/COD approvals absent; gates closed |
| Salla | BLOCKED | Official registered app/OAuth/scopes/callback/signature/store/sandbox evidence absent; provider closed |
| Shopyzee | BLOCKED | Actual deployed Nazmart source/version/model/auth/tenant/plugin/consent/replay/sandbox contracts absent; provider closed |
| Customs/document processing | BLOCKED | Country/carrier/legal goods policies, approved private encrypted storage/scanning/retention/residency and operations absent |
| Private analytics downloads | BLOCKED | Normal artifacts remain hard disabled pending storage/scanner/key/operator/retention acceptance |

## Remaining acceptance and owner testing

[Updated blocker register](operations/LAUNCH_BLOCKERS.md) maps Phase16 IDs to local remediation and exact unverified host/operational evidence. No blocker is closed solely by a template. [VPS requirements](deployment/VPS_REQUIREMENTS.md): minimum proposed4vCPU/8GiB/80GiB, recommended4–6vCPU/12–16GiB/160GiB, separate offsite backup; neither Hostinger nor Contabo selected/priced/authorized. Development numbers are not production sizing guarantees.

After independent exact-host authorization and passing applicable gates, operator privately issues actual verified HTTPS URL + per-reviewer revocable gateway credential. Owner registers synthetic account, completes private email verification, uses existing merchant workflows and independent MFA/admin bootstrap. English/Arabic/mobile journey checklist in [LAUNCH_ACCEPTANCE](deployment/LAUNCH_ACCEPTANCE.md). No default app passwords, emailed secrets in Git or screenshot-as-interactive claims. Closed capabilities are tested as unavailable, not presented as live.

Missing required external decisions: exact VPS/provider/region/domain/deployment approval; named SRE/DBA/security/finance/privacy/incident owners, protected CI reviewers/hosted checks/provenance; private mail and egress, encrypted offsite storage/key escrow/PITR/retention/restore targets, actual TLS/firewall/privilege/resource/monitoring/backup access acceptance; private document/scanner/retention/residency; carrier/payment/Commerce/customs contracts and regulatory review; formal accessibility and representative load/rollback. Production stays blocked.

## Files and delivery

[PHASE_17_FILES.txt](PHASE_17_FILES.txt) lists all changes. Final commit/remote SHA, push/PR/API access results accompany delivery; no main merge is authorized. Synthetic secrets/certificates/archives/evidence remain outside Git or are deleted with their private disposable environment. Normal development was restored after32 browser tests: API/PostgreSQL/Redis/schema readiness, Horizon, scheduler and English/Arabic pages pass. Guarded read-only normal DB counts remain23 users /24 migrations /0 ledger transactions /0 shipments /0 report exports. No cts17 container or volume remains.
