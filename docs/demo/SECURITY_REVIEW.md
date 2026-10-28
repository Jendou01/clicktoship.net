# Demo PR security review and runtime evidence

Scope: every file in PR #18 against main, plus the follow-up corrections below.
No Hostinger access, DNS/firewall changes, live operation or merge is authorized.

| Finding | Correction | Validation |
|---|---|---|
| P1: expired TLS, missing secrets or GitHub outage prevented emergency stop/teardown | Cleanup validates local root/target/instance/receipt identity independently of operational TLS/CI/image readiness | Unit tests assert no online approval/TLS call; isolated runtime exercise removes certificate before cleanup |
| P1: matching Compose labels did not establish exact ownership | Root-private receipt binds canonical resource names, container/network IDs, image IDs, mounts and volume creation metadata; unknown/replaced resources abort before deletion | Forged-receipt negative test and independent retained-resource canary; explicit-ID stop/rm only, no global prune/down-volumes |
| P1: inherited Docker contexts could redirect some operations to another daemon | Every Docker operation explicitly uses the local Unix socket and a bounded environment | Context-injection regression and actual isolated stack |
| P2: mutable host worktree/ignored files could enter image builds | Build context is a fresh archive of tracked approved commit bytes | Linux builder uses the actual packaging function; runtime credentials are generated only afterward |
| P2: archive hash/labels alone did not reject extra images/tags | Inspect safe outer tar members, exactly three config content hashes, expected source-SHA tags and referenced layers before Docker load | Actual built image archive plus tampered archive/signature/key negative cases |
| P2: same-name retained volumes/networks could be reused | Check canonical names, absent prior resources and exact tmpfs drivers; validate rendered commands/secrets/limits/identities | Negative policy tests and actual rendered/private runtime |
| P2: browser APIRequestContext bypassed private browser endpoint mapping | Use same-origin in-browser fetch, canonical TLS/SNI and an explicit loopback browser mode | Private browser acceptance runs inside an internal network namespace, preventing access to the real VPS even on resolver error |
| P1: the gateway's no-referrer policy removed first-party metadata from browser GETs, so Sanctum could not identify cookie-authenticated frontend requests | Internal API fetches explicitly use same-origin referrer policy; gateway policy, exact Sanctum origins, CSRF and cookie authentication stay intact | API-client regression plus actual protected production-image browser acceptance; never forge Origin or relax Sanctum |
| P2: fixture provisioning accepted a non-owner with shipping permissions | Require the selected verified active merchant.owner as well as existing service permissions | Positive repeated fixture creation and negative operational-user attempt |
| P2: certificate replacement needed bind-mount refresh | Explicit refresh-gateway validates new pair and recreates only the recorded gateway, adopting its new ID | Peer certificate DER comparison, revocation/recreation and retained other resource IDs |
| P2: socket-only PostgreSQL initialization could satisfy readiness before the final server | Demo readiness explicitly probes loopback TCP, unavailable to the temporary socket-only initialization server | Negative policy regression and actual fresh-start/redeploy lifecycle; no host DB port is published |
| P2: failed inline role provisioning could copy SQL credentials into persistent Docker logs | Demo PostgreSQL suppresses statement text, parameter values and verbose error details while retaining ordinary server errors | Exact rendered command policy and actual runtime settings inspection; no blanket RAM-only host-log claim |

The PostgreSQL temporary-server behavior is documented in the
[official image entrypoint](https://github.com/docker-library/postgres/blob/master/docker-entrypoint.sh).

## Validation boundary

The `demo-lifecycle` CI job builds the actual release Dockerfiles from tracked
source, retains images/archive only inside the disposable runner, and invokes
the same lifecycle cores against fresh RAM resources. It refuses the target VPS
hostname/IP. It never calls production CLI host/release approval as if a CI test
key were a human approval. A test CA and separately generated ephemeral test
review key exercise cryptography without public ACME/DNS or trusted-release claims.

The browser process enters web's internal Docker network namespace and maps only
the canonical demo name to the private gateway. It cannot route to Hostinger.
Normal API registration/email verification/invitation/MFA/bootstrap/pricing
approval procedures provision synthetic accounts. No verified-email, membership,
admin-role, MFA or financial rows are directly seeded. Draft and zero-wallet
fixtures use owning services. Tests cover worker queues, scheduler, isolation,
restart, certificate/gateway changes, emergency stop, canary-preserving teardown
and fresh redeployment. Only sanitized checks, source SHA and content digests are uploaded; no image,
key, credential, mail, browser trace or raw private log is an artifact.

Record actual job URLs/results in the final PR/handoff. Adding the job does not
mean it passed. All unexecuted or failed cases remain explicitly BLOCKED/FAIL.

## Unchanged and residual requirements

Phase 17 staging/production Compose, security preflight, runtime configuration
audit, SQL monetary/carrier guards, tenant/RBAC/CSRF/MFA and disabled adapters are
not relaxed. This is not provider disk-encryption certification. Root/provider
memory compromise is outside the synthetic disposable threat model. Synthetic-only
content also requires operator discipline; no software reliably detects real PII
in arbitrary text.

Independent human PR/manifest review, merged main SHA and successful main CI,
an independently pinned real review key, approved private artifact transfer,
actual target-host prerequisites and a separate deployment authorization are
still required. CI keys/CA/fixtures cannot be promoted as that approval. Existing
application regression CI is distinct from new demo lifecycle and real-host
acceptance evidence. No funded ledger, real waybill or completed carrier delivery
is fabricated. Host reboot/unmount may permanently lose all demo data/secrets.
