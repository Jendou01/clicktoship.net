# Secure staging setup and interactive access

## Current authorized work

Only local rehearsal is authorized. No VPS access, provisioning, remote commands, DNS, public ports, production credentials or live integrations. To deploy later, owner must identify exact host/provider/region/domain and independently authorize the deployment after every applicable gate passes. See SECURITY_PREFLIGHT and blocker acceptance register.

Build from a clean reviewed SHA with the three `infrastructure/deployment/*.Dockerfile` files. Node output is standalone, PHP is FPM (not artisan serve), and the gateway is non-root. Pass `RELEASE_SHA` truthfully; local uncommitted artifact builds in this phase are reported as simulation builds, not reviewed releases. CI manually packages only committed reviewed main SHAs. Verify exported checksums and actual image IDs before use; signatures/provenance remain a separate release gate.

For this restricted cloud environment only, the existing lock-validated Composer archive cache can be supplied as named build context `archive_cache`; an optional managed CA is a BuildKit `cloud_ca` secret. Do not disable TLS or copy credential/cache metadata into a runtime image. Standard builders use the empty default cache stage and official locked package downloads.

## Local disposable simulation

Build local tags `clicktoship-api:phase17`, `clicktoship-web:phase17`, `clicktoship-gateway:phase17`, then run `python3 scripts/deployment/rehearse.py --output /tmp/phase17-rehearsal.json`. Requires Docker, Python, OpenSSL, Node24 with the locked Playwright setup, Chromium and NSS certutil, existing free loopback18443 and non-conflicting172.30.71.0/24. Fresh unique `cts17-*` project only; generates independent credentials/key/certificate, runs24 migrations with maintenance identity, applies reviewed runtime grants, verifies actual non-development configuration and tests TLS/auth/private routes/restarts. It cleans only its generated resources. Normal dev volume/DB/key are not attached or changed. Local CA is explicitly trusted for verification in Python and a disposable Chromium NSS home; never use curl -k or public self-signed TLS.

The output is evidence, not an externally accessible link. This Codex localhost cannot be opened on the owner's computer. Real browser access remains BLOCKED until authorized hosting/private ingress exists. A future approved URL must be the owner's actual HTTPS hostname; this repository never invents one.

## Host installation only after separate authorization

1. Verify patched supported Linux/Docker/Compose, host disk encryption, account MFA, restricted SSH/firewall, time sync and administrative ownership. No Docker API/socket exposed to application containers.
2. Create an independent project/volumes/network, exact reserved gateway IP and protected secret directory outside Git. Copy environment.example as a checklist, not a usable configuration. Unique APP_KEY, runtime/maintenance DB passwords, Redis credential, staging gateway users and session namespace. No production/test-fixture data or default passwords.
3. Native Compose secret mounts preserve host uid/mode: directory0700 under operator control, individual application/gateway files0440 owned/readable by10001, Redis config0440 owned999, admin secret protected for initialization. Verify actual reads without printing contents. Mount maintenance environment only for explicit migration; remove it from ordinary runtime access.
4. Owner-approved hostname/certificate/key and exact `host.conf` rule; no wildcard trust. Keep Basic password hashes outside Git; issue each reviewer a unique strong credential privately, revoke by removing its hash and recreate gateway. Test401 before disclosing URL. Retain normal app MFA/RBAC and independent tenant grants; enroll actual synthetic admins through existing two-person bootstrap.
5. Provision runtime role, reviewed grants after each migration, backup/key custody, monitoring and storage policies. Run offline and application preflights, host tests, protected hosted CI and independent approval. Missing private SMTP/storage/offsite backup remains a blocker.
6. Only then owner may approve public TLS ingress/firewall for that exact gateway. Any port-binding override is a separate reviewed change; template stays loopback. A port selector or guessed localhost is not external access. No tunnel installed.

Accounts: use normal registration and private mail verification; synthetic owner/admin records only, never seed default passwords, force MFA for administrators, no direct membership/grant bypass. Live shipping/payments and normal documents/export downloads remain unavailable. Follow LAUNCH_ACCEPTANCE for bilingual/mobile testing and record observed failures.

Optional `http-redirect.conf.example` is an inactive exact-host308 fragment on internal8080. Review the actual HTTPS origin (including nonstandard ports if applicable) and authorize a gateway-only external80 mapping before use; no HTTP port is currently published. Never derive redirect origin from an untrusted Host header.
