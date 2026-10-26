# Launch blockers and operational acceptance

No external activation is approved by Phase 16. IDs map to SECURITY_AUDIT; an owner below is a required role, not an assertion of staffing/signoff.

| Blocker | Required evidence / approval | Responsible role | Status |
|---|---|---|---|
| B16-01 Runtime database privilege separation | Dedicated restricted login, function/table owner separation, role audit and adversarial direct-write checks on actual deployment | DBA/security | PENDING |
| B16-02 Release infrastructure | Immutable tested API/web artifacts, approved digests/provenance, TLS/hosts/proxy CIDRs/firewall, secret injection, non-root/capabilities, resource/supervision/rollback and protected release process | Release/SRE/security | PENDING |
| B16-03 Private storage/scanning/retention | Provider/region, private authorization, encryption/rotation, malware scanning/quarantine, audit, retention/deletion/PDPL and backup object version consistency | Security/privacy/operations | PENDING; normal downloads CLOSED |
| B16-04 Aramex | Authoritative current contracts and sandbox evidence for account/service/route/create/unknown/reference/labels/pickups/tracking/webhooks, contractual approvals and separate launch review | Carrier operations/integration | MISSING; transport CLOSED |
| B16-05 Real-value financial operation | Qualified accounting/tax/legal/regulatory/stored-value/safeguarding policy, provider verification, maker/checker/limits/settlement/reconciliation/incident controls | Finance/legal | UNAPPROVED; funding/charges/payments CLOSED |
| B16-06 Commerce providers | Official Salla contracts; deployed Nazmart/Shopyzee plugin/source/tenant/order/auth/version proof; per-store consent/least privilege/rotation/replay and isolated compatibility | Commerce integration | MISSING; both providers CLOSED |
| B16-07 Disaster recovery | Approved RPO/RTO, key custody/escrow, PITR/offsite encrypted backups, consistent object manifests, named incident/restore authority, post-restore revocation/reconciliation and repeated representative drills | DBA/SRE/security/finance | LOCAL REHEARSAL ONLY; production PENDING |
| B16-08–09 Capacity | Production-like load/soak/abuse, multi-merchant/same-merchant contention, reserved critical queue budgets/fairness, retention growth and provider outage evidence | SRE/performance | SMALL SYNTHETIC EVIDENCE ONLY |
| B16-10 Repository release evidence | Remote Actions status, protected main/review gates, independent security/finance review, supply-chain provenance policy | Repository/release owner | NOT ATTESTED BY LOCAL TESTS |
| B16-12 Alternate diagnostic stores / retention | Default failed_jobs/Horizon privacy is fixed/tested; approve retention/forensic access and independently review any alternate file/dynamodb/custom provider. Non-development preflight rejects unreviewed failed-job drivers | Security/SRE | ALTERNATE CAPABILITY / RETENTION REVIEW PENDING |
| B16-11 Accessibility/operations | Formal assistive-technology/WCAG review, on-call, private monitoring/sink retention, approved thresholds/notification incident policy | UX/SRE/security | REVIEW PENDING |

## Proposed monitoring and thresholds (approval pending)

Internal existing dashboards expose health, work/failure counts, unknown outcomes, tracking evidence cases, bulk failures, financial reconciliation, integration reauthorization, customs quarantine and report expiry/recovery. Access requires existing current permissions/recent MFA; this audit adds no public admin/monitoring endpoint or external notification.

Propose an internal observation when: readiness is 503 on two independent checks; any unknown externally billable outcome exists; financial reconciliation mismatch count exceeds zero; work has expired leases and no recovery progress across two scheduler cycles; dead-letter/quarantine or reauthorization count exceeds zero; exports retain artifacts past expiry/recovery cycles. Review source/date/ownership before treating counts as incidents. Baseline thresholds are informational proposals, not production SLOs or instructions to release holds/retry calls.

Independent scheduler heartbeat, health/queue wait retention, failure/error-rate dashboards, owned runbooks and approved escalation destinations must be provisioned before launch. Record identifiers/codes/aggregate counts only. Do not put customer payloads, credentials, financial details or raw document/order/exception data in logs, alert messages or customer notifications. No email/WhatsApp/SMS/push is enabled by this proposal.

## Phase 17 traceable staging acceptance register

These are **local remediation artifacts/evidence**, not closure of actual-host approvals. No remote infrastructure was contacted. Evidence and executed counts: [Phase17 report](../PHASE_17_REPORT.md).

| Existing blocker | Phase17 remediation / acceptance test | Still required before remote staging | Owner / status |
|---|---|---|---|
| B16-01 | Separate maintenance/runtime env mounts; runtime-grants.sql, restricted actual login, adversarial DDL/money denial and zero-wallet provisioning in isolated rehearsal | Review exact provisioned host roles/function ownership/schema/public privileges and synthetic tenant journeys | DBA/security — LOCAL EVIDENCE; HOST BLOCKED |
| B16-02 | Pinned PHP-FPM/standalone Next/non-root gateway builds, read-only roots/caps/resources/secret modes, protected TLS/auth/host/private routing rehearsal; manual packaging workflow | Exact host/domain authorization, protected reviewers/hosted CI/artifact integrity/provenance, Linux/firewall/TLS/patching/secrets/ingress/mail/storage acceptance | SRE/release/security — TEMPLATES/LOCAL ONLY |
| B16-03 | Private volumes/no public storage, disabled scanners/document/export gates retained, storage acceptance/runbook | Approved provider/region/encryption/scanning/quarantine/retention/PDPL/download and object backup policy | Privacy/security — BLOCKED |
| B16-04 | Separate Aramex checklist includes duplicate/unknown/weight reconciliation, no outbound network/API | Official current contracts, authorized sandbox/account/service evidence and independent live activation | Carrier operations — BLOCKED |
| B16-05 | Finance legal/accountant/payment/COD/settlement/tax checklist; no real-money enable path | Qualified accounting/regulatory/provider/operational signoff | Finance/legal — BLOCKED |
| B16-06 | Provider-specific Salla and deployed Nazmart/Shopyzee evidence checklists | Official app/auth/model/version/tenant/plugin/contracts, per-store consent and authorized sandbox | Commerce owner — BLOCKED |
| B16-07 | Authenticated AES-GCM/CMS local backup tool, separate recipient key, quiesced dump/object manifest, disposable restore/tamper test; data-preserving release/restore runbooks | Independent offsite receipt/retention/PITR/key escrow/object consistency, named recovery owners, approved RPO/RTO and actual-host repeated restore | DBA/SRE/security — LOCAL ONLY; OFFSITE BLOCKED |
| B16-08–09 | Disjoint actual Horizon plans, reserved critical1/background1, timeout/retry/grace alignment, resource limits; local restart checks | Representative host load/soak/fairness/backpressure and reserved capacity/resource tuning; cloud VFS32GiB limits are not production evidence | SRE/performance — CONDITIONAL DESIGN; HOST PENDING |
| B16-10 | Manual main-SHA+hosted-CI packaging, no SSH/deploy; protected release audit/backup/security steps documented | Configure real environment reviewers/main protection, independent review/provenance, verify remote Actions; local tests cannot attest these | Repository/release owner — BLOCKED |
| B16-12 | Existing sanitized failed-job/Horizon sinks retained; rotated output and disabled raw FPM request/error output | Actual sink retention/access/forensic policy and alternate-provider review | Security/SRE — REVIEW PENDING |
| B16-11 | Bilingual protected staging UAT, accessibility/mobile/monitoring thresholds/owner roles and escalation playbooks | Named on-call, private heartbeat/monitoring/backup/TLS alerts, approved external escalation, formal AT/user acceptance | UX/SRE — OPERATIONAL ACCEPTANCE PENDING |
