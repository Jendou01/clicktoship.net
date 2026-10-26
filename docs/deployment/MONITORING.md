# Private monitoring and operational ownership

No public monitoring listener or external notifications is enabled. Existing recent-MFA platform overview and exact granted views remain the operator UI; global counts never grant private merchant access. Proposed owner roles below must be assigned real people/escalation channels before hosting.

| Signal | Proposed observation threshold | Required owner / response |
|---|---|---|
| API ready + frontend | two consecutive failed independent checks,30s apart | SRE: inspect dependency/process codes; no automatic financial replay |
| DB/Redis | unhealthy or Redis memory>70%, rejected write/eviction>0 | DBA/SRE: capacity and durable recovery; Redis is not financial authority |
| Critical queue | wait>30s or missing expected worker | SRE: reserved process/connection/retry alignment; do not increase retries for unknown carrier work |
| Background queues | oldest age>5m or backlog growth across3samples | SRE/module owner: bounded concurrency and current authority |
| Failed jobs | any new sanitized failure / repeated fingerprint | Module owner/security: identifiers only, restricted encrypted payload access |
| Scheduler | no successful externally observed cycle for>2min | SRE: confirm exactly one running scheduler and locks; process presence alone is insufficient |
| Schema | schema:verify failure or unexpected migration inventory | Release/DBA: stop release; never rewrite migration history to hide drift |
| Disk / RSS / CPU | disk>70%, projected<30days; RSS>75%limit; CPU>70% sustained10m /OOM | SRE: capacity, tmpfs/volume quotas and host steal; no production SLA inferred |
| Backup | last verified receipt exceeds approved RPO (proposal15min) or restore overdue | DBA/security: check offsite receipt/keys and restore drill, not only backup command exit |
| TLS | expiry<21days, chain/hostname failure | Security/SRE: approved certificate renewal, no trust bypass |
| Auth / grants | rate-limit anomalies, revoked/expired attempts, failed MFA | Security: aggregate event codes, lockout policy review; no email/IP/customer dump |
| Domain exceptions | any financial mismatch/unknown shipment; quarantine or integration reauth count>0 | Finance/carrier/privacy/Commerce: current evidence and independent reconciliation, no auto release |
| Exports | expired lease/artifact past cleanup cycles | Reporting/security: authority/expiry recovery, normal downloads remain closed |

Docker output has bounded json-file rotation10MiB×3 per container and application/failed-job redaction. Actual centralized access/retention, encrypted disks, emergency logs, private metrics collection, heartbeat history, pager policy and on-call tests remain unprovisioned. FPM raw request/error output is disabled; investigate code/fingerprints through approved private diagnostic escalation. Do not broaden logs with request bodies, Authorization, signed URLs or raw exceptions to debug a host.

Health/schema checks in Compose cover process/container/config aspects; they do not by themselves measure backlog, validate each master supervisor or detect a stuck scheduler. Rehearsal and host acceptance must inspect actual worker processes and durable recoveries. Do not publish `/horizon`, Mailpit, Docker socket, DB, Redis or debugging tools.
