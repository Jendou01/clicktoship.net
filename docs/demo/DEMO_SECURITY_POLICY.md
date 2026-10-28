# Disposable remote demo security policy

This policy applies only to the separate `infrastructure/demo/compose.yml` profile at `https://staging.zeeship.cloud`, on owner-authorized VPS `srv2104412.hstgr.cloud` (2.24.48.221). It does not approve deployment. It never grants an exception to Phase 17 staging/production preflight.

## Explicit scope

The operator must supply `SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED` to every lifecycle action. APP_ENV must be exactly `demo`; CTS_DISPOSABLE_DEMO=true alone in production fails application boot. Exact host/proxy/database/private mail/session/credential checks reject alternate targets and injected environment keys. The controller refuses a dirty or different source checkout, unapproved images, unverified CI and untrusted release review signatures.

Only fictional `@example.invalid` identities may register. Do not enter personal addresses, phone numbers, financial records, customer uploads or business secrets. Do not attach/import retained databases, Redis instances, directories or volumes. Synthetic-only is also an operator conduct requirement: software cannot determine whether arbitrary text contains real personal information. Private report downloads and document processing remain closed.

## Storage and accepted loss

No offsite backup, retention subscription or production recovery escrow is required by **this profile only**. Database, Redis and private objects use bounded Docker local-driver tmpfs volumes; generated independent secrets and certificate copies stay in a dedicated root-owned `/run/cts-demo-<12 hex>` directory on tmpfs. Controller rejects enabled swap. This is volatile storage, not a claim of disk encryption, secure RAM against a privileged provider, or guaranteed physical erasure. The owner accepts loss on unmount/reboot/host failure/reset; runtime is detached and remains up after setup, but is not designed to recover after loss. No real customer data is permitted. Choose staging/production instead for retained data.

No destructive action touches existing retained volumes. No global prune, migrate:fresh, Redis flush, root encryption, filesystem repartition, firewall or DNS command exists in the demo controller. The production SQL guards still forbid synthetic credits and carrier execution in `clicktoship_demo`. A funded ledger or delivered carrier status is not faked to decorate the demo.

## Network and application controls

Reuse Phase 17 hardened API/web/gateway, read-only non-root runtime, separate migrator credentials, reviewed SQL grants, exact proxy, encrypted Secure/HttpOnly host-only SameSite cookies, CSRF, rate limiting, tenant membership permissions and platform MFA. Only the gateway joins an ingress bridge; API/DB/Redis/workers/scheduler/private Mailpit join an internal Docker network, with no host ports. No Docker socket or host mount is passed to the app. Initial ingress is loopback 127.0.0.1:18443. Separately approved publish uses only 2.24.48.221 TCP443 to gateway8443; operator firewall stays closed until separately authorized authenticated TLS checks pass.

All original disabled carrier/payment/tracking/Commerce/scanner adapters remain. Demo blocks provider setup/financial/submission API writes additionally; Laravel rejects stray HTTP and the private network denies routed provider access. SMTP is only private Mailpit, synthetic-recipient restricted, with no relay or public inbox. No test adapter can silently become a live adapter. Read-only integration screens may show disabled states.

The public gateway requires a fresh random revocable password hash. `reviewer-access.json` is private RAM-only handover material: owner reads it through an independently authenticated private session, never chat/Git/public screenshots. Replace/remove a user's hash and recreate gateway to revoke; prove old credential returns 401. Do not send .env, rendered full container environments, MFA secrets or credentials in evidence.

## Account authority

Normal browser registration/email verification and team invitations remain required. Owner chooses passwords directly in the private application. Two independent verified MFA-enrolled administrators must use the existing audited `platform:bootstrap`; fixtures do not seed roles, MFA seeds, recovery codes, verified emails or passwords. CLI `demo:fixtures OWNER_UUID MERCHANT_UUID` only adds idempotent synthetic drafts and provisions an empty wallet through owning services; it cannot run outside demo. No financial rows are written directly. Existing tenant/RBAC/CSRF/MFA regression suites remain mandatory.

## Readiness and evidence

`control.py verify` is offline/local config, certificate and artifact validation plus hosted-CI query; it is not browser/host acceptance. `demo:check` checks actual restricted runtime configuration and synthetic database boundaries. An empty admin table satisfying “no assigned admin lacks MFA” does not mean onboarding or MFA acceptance passed. Record those journeys separately, with real evidence. Complete DEMO_ACCEPTANCE_CHECKLIST before reporting a working demo. Unknown/unexecuted checks are BLOCKED, not PASS.

Hostinger physical disk/snapshot/backup encryption remains unverified until applicable evidence exists. Application databases, objects, caches and generated secrets use RAM mounts. Docker/provider/system operational logs may persist metadata; do not claim every host byte is RAM-only or securely erased. Demo PostgreSQL suppresses statement/error-detail/parameter logging to keep inline role-provisioning credentials out of diagnostics; ordinary server errors remain available. Application logging uses the existing sanitizer and Mailpit logs are disabled. No provider certification is implied. Production requirements remain intact.


Cleanup safety is deliberately independent of operation readiness: expired/missing
TLS, unavailable GitHub or application secrets cannot block closing the exact
owned instance. A private resource receipt and actual target identity are still
mandatory. Matching labels alone never authorize deletion. No global prune is
used. See SECURITY_REVIEW.md and DEMO_RESET_AND_TEARDOWN.md.
