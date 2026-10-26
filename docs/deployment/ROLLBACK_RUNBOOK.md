# Data-preserving release rollback

A failed release is not authority to downgrade schema or restore stale money. Stop ingress/writers and identify the incident owner; retain redacted audit and exact images/schema/backup/unknown-operation state. Do not log credentials/private payloads.

* Before release, demonstrate old application compatibility with the post-upgrade schema on a restored disposable copy. If compatible, restore **previous reviewed image digests only**, keep current DB/objects/keys, recreate application containers and verify schema/readiness/config/TLS/tenant/auth/closed-gates. No `migrate:rollback` or volume reset.
* If incompatible or history changed irreversibly, keep maintenance mode and prepare a reviewed forward repair. Do not claim every migration is reversible. Existing migrations deliberately refuse deletion of retained ledger/audit/provider evidence.
* DB restore is a separately approved incident procedure, not normal image rollback. Recover a compatible encrypted DB/object point into a new isolated target, preserve keys, verify immutable balanced ledger/tenant identities/audit, revoke restored sessions/tokens/grants under incident policy, reconcile external unknown outcomes independently, and authorize cutover. Never blindly release holds or resubmit shipments.
* Re-enable only after preflight, both locales, private access checks, queues/scheduler, backup and independent acceptance. Document RPO/data impact and recovery timings without inventing SLA. Keep previous release artifacts until approved retention expires.

Phase17 local rehearsal recreates the **same recorded image IDs** without any schema downgrade; this tests artifact recreation/restarts, not backward compatibility between different releases or a production rollback. Cross-version release rollback and offsite recovery remain blocked until actual approved artifacts/host/backup evidence exist.
