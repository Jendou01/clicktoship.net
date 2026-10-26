# Backup, restore and recovery runbook

Scope: tested **local/disposable rehearsal**, not production DR approval. Proposed Blueprint RPO ≤15 minutes / RTO ≤4 hours require explicit business/SRE approval and representative infrastructure validation. A small local restore duration is not proof of these targets.

## Owners and prerequisites

Proposed accountable roles: DBA owns PostgreSQL/PITR, security owns key custody/rotation, storage/privacy owns object versions/residency/retention, SRE owns recovery/supervision/observability, finance owns balance/reconciliation evidence and release management owns version acceptance. Actual named owners, on-call and independent recovery authority are not assigned by code.

Retain an exact application commit, lockfiles/images/extensions and schema version; encrypted database and immutable object backups with integrity manifests; separately protected matching APP_KEY/approved previous keys, session/token revocation policy, provider credentials and configuration. Never include keys in Git, backup filenames, logs or plaintext shared artifacts. Test loss/wrong-key failure explicitly. Database backups alone cannot recover encrypted rows/objects after key loss. Do not silently rotate a key during setup/restore.

## Reproducible isolated rehearsal

1. Start the documented isolated E2E profile and create synthetic drafts using the existing tests or `phase16-load.php`. `phase16-state.php guard` must confirm actual `testing`/`clicktoship_e2e`. The source needs saved encrypted revisions; normal environments are rejected before interruption.
2. Run `python3 scripts/qa/restore_rehearsal.py --output /tmp/phase16-restore.json`. It stops only the E2E API/worker/scheduler to quiesce its source, then adds a clearly marked synthetic test ledger operation through the owning Ledger service. It never writes this fixture to normal data.
3. The script fingerprints every bounded public source table and exports only synthetic E2E with pg_dump custom format into a private temporary directory/file. SHA-256 is recorded; the dump is deleted after the rehearsal. No source credentials or private row contents are printed.
4. It creates a uniquely named internal Docker network and PostgreSQL/Redis containers with **no published ports**, using locally observed exact image digests. Trust authentication is restricted to this disposable private cluster and is not a deployment configuration. No retained development volume is attached. Temporary container/anonymous volumes are removed only by exact created names.
5. Fresh migrations create required roles/schema, `schema:verify` passes, then pg_restore cleans/restores **only the new disposable cluster**. Source and restore table counts/SHA-256 row fingerprints must match. Encrypted revision samples decrypt with the preserved key and fail with a random wrong key; immutable ledger entries remain balanced per transaction/currency.
6. A private synthetic encrypted object is copied through source/backup/restored files, with hash and key-based decryption checked. This proves the local test file mechanism only; no cloud/object-store versioning, scanner, retention or database/object consistency policy is certified. Approved real storage remains absent/closed.
7. Fault injection targets only the created cluster: terminate one tagged uncommitted SQL transaction and require rollback; stop/restart its Redis and PostgreSQL independently, requiring readiness 503 then 200. Verify durable source fingerprints again. No normal Redis flush/outage, live carrier call or financial release/replay occurs.
8. Finally remove only created containers/network/temporary files and restore normal development with `scripts/start.sh`. Recheck normal data counts, schema, Horizon, Redis and both frontend locales. Do not use normal `migrate:fresh`, TRUNCATE, `down -v` or a production restore for this rehearsal.

The script uses bounded polling to observe actual dependency/barrier states; no arbitrary workflow delay is used. It rejects non-E2E source runtimes. Failed evidence/guard/decryption is a failed rehearsal, never ignored. A restore into a disposable new cluster is not a test of restoring a production cluster in place.

## Production procedure requiring approval

* Declare incident/restore authority, isolate ingress/writers/workers and retain forensic/audit evidence. Determine recovery point from PostgreSQL WAL/PITR and matching immutable object versions/manifests. Verify trusted release artifact and secret custody before any restore.
* Restore into an isolated target; verify checksums, schema/version/privileges, tenant identities, all immutable history and financial projections/reconciliation. Quarantine missing/mismatched objects. Missing keys, artifacts or provider evidence block dependent capabilities.
* Invalidate/review restored browser sessions, tokens, grants and MFA freshness under an approved incident policy before re-enabling access; old Redis sessions are not a durable authority. Do not make a recovered revoked credential usable through stale backups.
* Recover durable module work only after reviewing its origin/lease/evidence. PostgreSQL owns holds/dedupe/outbox/receipts; Redis is ephemeral scheduling/session infrastructure. Do not blindly replay unknown carrier submissions, ambiguous token refresh, settlements or unresolved externally billable work. Keep protected holds; independent verified reconciliation is required.
* Independently approve reconciliation, external status, observability, ingress and worker resumption. Exercise rollback/failback, monitoring and customer communication only under approved policies. No production backup deletion, restore, notification or activation was performed here.
