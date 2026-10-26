# Deployment readiness assessment — Phase 16

Passing local tests is not proof of deployability, live integrations, regulatory compliance or formal accessibility. This repository still supplies a development Compose stack. No staging/production deployment, domain/TLS provisioning, tunnel, external notification or Phase 17 work occurs in Phase 16.

| Capability | Verdict | Evidence / remaining prerequisites |
|---|---|---|
| Local development | READY WITH CONDITIONS | Complete regression/browser/real PG/Redis checks and bounded rehearsal in Phase 16 report. Local-only synthetic use; closed external/storage/financial gates and test reset guards must remain |
| Staging deployment | BLOCKED | Production-like reviewed artifacts/manifests, exact TLS/host/proxy/firewall, restricted runtime DB roles, secret injection, supervision/limits, storage policies, monitoring and deployment ownership not provisioned; remote CI/review must be verified |
| Production infrastructure | BLOCKED | Development PHP serve/Next dev/Compose is unsuitable as release deployment; HA/PITR/keys/object backup, controls, capacity/SLOs, incident/rollback and signoff outstanding |
| Real Aramex shipping | BLOCKED | Official current response/auth/label/webhook/reference contracts, sandbox proof/account permissions, accounting and separate carrier activation review missing |
| Real wallet/payment | BLOCKED | No live payment/funding/settlement execution; qualified accountant/legal/regulatory/provider/safeguarding and operational gates not approved |
| Salla integration | BLOCKED | Disabled adapter; official protocol/evidence, callback/consent/signature/order proof and isolated compatibility not verified |
| Shopyzee integration | BLOCKED | Disabled adapter; actual Nazmart deployed source/version/plugin/order/auth/tenant contracts and isolated connector testing missing |
| Production customs/document processing | BLOCKED | Local preparation/revision/quarantine is tested; legal/carrier rules, approved secure storage/scanning/retention/residency and processing evidence absent; no customs clearance enabled |
| Analytics private exports | BLOCKED | Synthetic CSV/XLSX lifecycle tested; every normal artifact/download remains closed pending approved private storage/scanner/key/operator/retention controls |

## Configuration review and hardening

`php artisan security:check-config` is a read-only **non-development preflight**, not deployment/activation authority. It reports only codes/booleans, returns nonzero for unsafe settings and deliberately fails the current development stack. It checks debug, HTTPS origin, exact hosts and stateful origin, Secure/HttpOnly/host-only SameSite cookies, encrypted Redis sessions, private mail, valid encryption key presence, unchanged closed gates, and actual PostgreSQL login superuser/create-role/create-db/bypass privileges. Hardened setting tests use no real deployment or secrets.

`infrastructure/staging.env.example` is inert, uses reserved `.test`, empty secret values and no live enable flags. Do not copy it and claim readiness. Supply approved exact hosts/TLS chain/trusted proxy CIDRs and secret injection. Unknown hosts fail closed outside local/testing; no wildcard forwarded-host trust is added. Configure HSTS/CSP/Permissions-Policy with the actual ingress/Next.js release and test it; local HTTP/dev eval behavior is not a production policy.

API/web development users are non-root as configured; Nginx/PostgreSQL startup, root filesystem/capabilities, image/package digest/provenance, process liveness/supervision and resource budgets need a reviewed production design. Locally observed image references are inventoried, not promoted as signed approved production artifacts. Three official CI actions are commit-pinned from verified public v4 refs; CI token permissions remain contents:read, no privileged pull_request_target or production secrets are added.

All database, Redis and API services remain internal; Nginx and development Mailpit are loopback-only. Horizon routes remain absent. API/CSRF responses are private/no-store; configured logs redact raw exception context/trace. Infrastructure log drivers, retention/access/alerting and a private diagnostic escalation process still need owners. Two Horizon processes share development queue capacity; queue order alone is not a reserved critical-worker guarantee.

Actual package versions and declared licenses are inventoried; dependency/lockfile/audit/scan and build checks are in the report. First-party secret-pattern/artifact scanning is not an exhaustive external scanner. Trivy, gitleaks and syft were not installed/claimed. Remote GitHub API restrictions, if present, must not be mistaken for passing remote Actions or independent review.

Failed-job/Horizon exception fields are a separate privacy boundary. Phase 16 sanitizes persistence in the approved default UUID database provider and Horizon repository, without changing the encrypted retry payload or exceptions seen by job business logic. A real failed Redis job verifies both sinks. Unreviewed alternate failure drivers cannot pass non-development configuration preflight; custom adapters and real-data access/retention/forensic policy remain prerequisites (B16-12). Encrypted job payloads alone are not claimed to protect arbitrary diagnostic fields.

## Phase 17 superseding repository preparation

Phase16 was merged as `a211ca10e9f33313a73e48acece01b8d43523931`. Phase17 now supplies separate release templates, runtime grants, protected local HTTPS rehearsal, critical/background Horizon capacity and release/backup/rollback/acceptance tooling. See [Phase17 report](../PHASE_17_REPORT.md), [architecture](../deployment/ARCHITECTURE.md), [setup](../deployment/STAGING_SETUP.md) and updated LAUNCH_BLOCKERS acceptance register. The historical Phase16 findings above are preserved as their original evidence; **actual staging hosting remains BLOCKED**, not retroactively completed. No owner-accessible external URL, host authorization, offsite backup/key custody, private mail/storage/scanning or protected release approval is supplied by this repository work.
