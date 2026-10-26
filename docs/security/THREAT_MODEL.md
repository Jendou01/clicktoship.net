# Phase 16 threat model

Scope: Phases 0–15 modular monolith, pre-deployment development audit. This is source review and bounded synthetic testing, not penetration-test/WCAG/provider certification. No live carrier, payment, store or notification channel is activated.

## Assets and actors

Assets: merchant identities/memberships, passwords and MFA recovery, browser sessions and token hashes, independent platform grants, immutable financial journals/holds/approvals, quote snapshots, shipment revisions/unknown outcomes, source-order identity/provenance, private documents/exports, application encryption keys, audit history and backup artifacts.

Actors: unauthenticated requester, malicious or compromised merchant member, bearer-token thief, compromised platform account, forged carrier/store sender, replaying queue/worker, accidental operator and privileged infrastructure administrator. A DB superuser, stolen application encryption key or compromised build runner is outside application RBAC protection and must be separately controlled. Local Docker credentials/volumes are development-only.

## Boundaries

| Boundary | Controls and residual requirements |
|---|---|
| Browser → Nginx → Laravel/Next | Same-origin CSRF/Sanctum, current sessions, rate limits, exact configured hosts, no-store API responses. External TLS/proxy/host/firewall configuration remains unprovisioned |
| Identity → merchant context | Current active user/membership/merchant and intersected token abilities; UUID is not authority; context clears at request/job boundaries |
| Platform → private merchant | Browser/recent MFA/current capability plus independently approved exact target, session, expiry and approver authority. Aggregates never confer private access |
| HTTP → PostgreSQL | Parameterized typed queries, composite identities, bounded filters/deadlines, immutable constraints, locks/idempotency. Restricted production login/function-owner provisioning is pending |
| Durable work → Redis → worker | PostgreSQL owns leases, business dedupe, receipts and recovery. Encrypted queue payloads, current-origin rechecks, clear context. Redis is not financial truth |
| Adapter → external provider | Disabled concrete bindings and evidence/capability gates. No invented response, OAuth/signature, retry or live compatibility claim |
| Upload/object → parser/scanner → download | Private bounded parsing/quarantine, exact owner/revision/origin checks. Normal report artifacts remain closed; production storage/scanner/retention is unapproved |
| Source records → analytics | Fixed columns/filters, source permissions, exact currency/exponent strings, provenance and no private global money. No arbitrary SQL/cache/second ledger |
| Runtime → logs/monitoring | Static event codes, correlation/aggregate identifiers and sanitized exceptions; no bodies, SQL bindings, credentials, addresses or stack payloads. Infrastructure sink ownership/retention pending |
| Backup → isolated restore | Exact schema/source fingerprints, matching separately preserved key, encrypted object hashes, no worker activation on restore. Production PITR/object consistency/key escrow and recovery signoff pending |

## Abuse cases and verification

* BOLA/IDOR: substitute merchant, shipment, order, quote, wallet, export or document identifiers; require denial without foreign contents. Existing cross-module negative tests plus exact revision/tenant batch-summary regression.
* Privilege escalation: role removal, suspended user/merchant, token ability reduction, expired session/MFA/grant/approver during work; current authority must fence commits/downloads. Existing independent-process suites and export scanner/worker revocation tests.
* Session theft/fixation: authenticated session rotation, auth-version and registry hash binding, password reset/revocation, MFA version/recovery consumption; HTTPS host-only encrypted cookies required before non-development use.
* Forgery/replay: callback state consumed/cancelled once, permanent provider/instance/store ownership, webhook proof/dedupe; unknown contracts stay disabled. Synthetic verification is not official protocol evidence.
* Money/shipment duplication: concurrent reserve/capture/refund/request/business replay; PostgreSQL serializes and enforces exact immutable balance. Unknown carrier outcome retains protected hold and cannot silently retransmit.
* Injection/SSRF: typed status/UUID/filter schema and bound values; providers/transports disabled; no arbitrary URL/query/column surfaces. Spreadsheet text formula escaping and data-only OOXML remain enforced.
* File/retention abuse: ZIP expansion/parts, XML/formula/path/size limits, quarantine, digest, current ownership, lease/expiry cleanup; safe default is no normal private export artifact.
* Queue crash/reordering: durable state survives killed export process, interrupted DB transaction and private dependency restart; bounded recovery must not replay irreversible carrier operations.
* Sensitive diagnostics/cache: exception message/context/trace cannot reach configured log sinks; private API and CSRF responses must not be stored. Log fallback outside Laravel/OS-level diagnostics still require operator policy.
* Supply chain/operator: exact Action commits, lockfile validation, declared license/image inventory, first-party artifact/secret-pattern scan. This is not signed build provenance, exhaustive secret detection or production DR readiness.

Credential rejection work was benchmarked separately for existing/wrong and missing accounts. Configuration-scoped dummy hash caching before identity lookup removes the measured extra hash on the missing path, including identity-independent cold warming. No account-specific result is cached and matching the dummy cannot authenticate a missing user. Legacy hashing-cost variation/rehash and remote timing behavior remain part of staging abuse review; no universal constant-time promise is made.
