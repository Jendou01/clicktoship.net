# Protected remote disposable demo setup

This is a deployment procedure for a later explicitly approved release. The coding task neither accesses the VPS nor deploys. Target only srv2104412.hstgr.cloud / 2.24.48.221, HTTPS staging.zeeship.cloud. No business data, paid service or real operations.

## Prerequisites and approval

Read DEMO_SECURITY_POLICY and DEMO_RELEASE_PROMOTION. Obtain merged reviewed source SHA, exact successful CI, signed independently approved archive manifest and owner-pinned review public key hash. Existing Phase 17 images are insufficient after these code changes. Controller also verifies the local hostname is srv2104412 (or its exact FQDN) and 2.24.48.221 is assigned locally. It refuses another machine. Host needs patched Linux, root operator, Docker Compose >=2.33.1 (`!override` and gw_priority support), /run tmpfs, no active swap, enough free RAM for bounded tmpfs data (2 GB database, 256 MB Redis and 256 MB objects), working private monitoring, an unoccupied 172.30.73.0/24 subnet and port 443. Preserve existing SSH/root, data and unrelated websites. Do not disable active swap to satisfy a check without separate host review: stop if the host does not qualify.

Verify the already approved DNS A, no conflicting AAAA, and valid trusted fullchain/key for staging.zeeship.cloud. Controller validates chain/hostname/key match and >21 days validity. Keep public ingress closed during preparation. It never opens a firewall or changes DNS.

## Manual commands

Operate on the clean approved repository checkout, not an unreviewed workspace. Choose a fresh random instance ID; no existing retained project may use it. Use a private root shell, disable shell tracing/core dumps, and keep private files out of Git/logs.

```sh
sudo -i
umask 077
ulimit -c 0
# On the independently verified, clean, exact approved checkout:
INSTANCE="cts-demo-$(openssl rand -hex 6)"
ACK=SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED
# All paths below are explicit operator-selected files. PUBLIC_REVIEW_KEY_HASH
# comes from independent custody; it is not inferred from the delivered archive.
demo() {
  python3 scripts/demo/control.py "$@" --directory "/run/$INSTANCE" \
    --manifest /protected/demo-release/manifest.json \
    --signature /protected/demo-release/manifest.sig \
    --review-key /protected/demo-release/review.pub \
    --review-key-sha256 "$PUBLIC_REVIEW_KEY_HASH" --ack "$ACK"
}
demo load --archive /protected/demo-release/images.tar
demo prepare --certificate /approved/ram/fullchain.pem --tls-key /approved/ram/privkey.pem
demo verify
# Separate exact-host/release deployment approval is required before this step:
demo start
```

Use resolved regular RAM paths for certificates rather than symlinks. No unencrypted permanent copy is created. Prepare refuses an existing directory; failure leaves a private partial RAM directory for operator inspection, never a ready state. Start creates detached services, fresh schema and separate restricted runtime DB login, applies the unchanged SQL runtime grants, and runs schema/security/demo audits before starting the gateway last. No temporary rehearsal cleanup runs on success. Any error aborts; do not repeatedly rerun start on partial resources. Keep firewall closed, inspect static error codes privately and stop/reset only the named demo resources.

After `start`, gateway is bound only to 127.0.0.1:18443. The controller performs trusted TLS/401/authenticated readiness/EN/AR/private-path tests there. It does not publish external ingress. After successful private checks and a separate owner approval, `demo publish --approve-public-gateway` repeats runtime/TLS checks and recreates only the gateway using publish.yml on 2.24.48.221:443. Publication failure stops the gateway. Host firewall must still block external ingress until independently verified TLS, unauthorized 401, valid credential and API readiness, revocation and port audit. Docker can bypass naive UFW rules: before publish, a host operator must verify the host's DOCKER-USER/nftables policy actually blocks forwarded gateway traffic. Do not assume UFW alone. Open only exact gateway HTTPS ingress after separate authorization; keep 80/DB/Redis/PHP/mail/UI/debug ports closed. This controller does not install or weaken firewall policy.

## Configuration inventory

`application.env.example` is not deployable. Controller generates independent APP_KEY, runtime DB, owner DB, Redis and gateway credentials. It sets all variables in that template and rejects extras (including DB_URL/REDIS_URL/external credentials). APP_ENV=demo, CTS_DISPOSABLE_DEMO=true and CTS_DEMO_ACK require explicit opt-in; CTS_DEMO_INSTANCE binds to the unique project. Host/proxy/database/session/mail settings are exact and not user-selectable. Owner credentials exist only in migration.env; runtime gets application.env. Redis config, host rule, TLS and gateway files have restricted service-readable ownership. Every app has private tmpfs caches; two Horizon roles and one scheduler persist while the instance runs.

Compose substitution variables are internal to controller: CTS_PROJECT, CTS_SECRET_DIR, CTS_PROXY_IP and CTS_API_IMAGE/CTS_WEB_IMAGE/CTS_GATEWAY_IMAGE. They come only from verified instance/manifest, not inherited user environment. DOCKER_HOST is fixed to local Unix socket. Compose config output may contain operational metadata and must not be published. Never pass database or gateway passwords in arguments.

## Accounts and data

Privately give each reviewer a unique gateway credential; the initial root-readable reviewer-access.json is handover material, not a chat artifact. Register synthetic merchant owner and operational member through normal UI using `@example.invalid`; choose passwords in browser. Retrieve verification/invitation mail via an authenticated operator's private internal Mailpit access, never by publishing an inbox port. Use normal team invitation/acceptance with merchant.shipping_operator role as supported by the role directory. Register two independently controlled platform users, verify email, enroll/confirm MFA in their own browsers. Then use existing platform:bootstrap FIRST_UUID SECOND_UUID through the restricted runtime CLI. Never seed a confirmed MFA secret or edit role tables to bypass onboarding.

Invoke `php artisan demo:fixtures OWNER_UUID MERCHANT_UUID` inside the selected API service. The fixture validates owner/merchant authority, creates three repeatable synthetic drafts and an empty wallet through owning services. Only identifiers/counts may be recorded. Team accounts, pricing configuration and two-person approvals use normal authenticated application workflows. Sample rate definitions are in `infrastructure/demo/synthetic-rates.json`; they are a review/input fixture, not an automatically activated tariff. Load through the normal pricing draft/independent activation UI using the exact service/tax IDs returned; never directly write active pricing tables. Synthetic draft counts feed analytics; tracking history stays empty until real supported synthetic source facts exist.

Nonzero synthetic ledger entries, carrier-accepted/delivered statuses and waybills are deliberately unsupported in this remote demo database because unchanged SQL guards forbid them. Wallet/track screens must truthfully show empty/disabled states. Bulk scanner-gated execution and normal private export downloads remain blocked. No test-case DB or test-only adapter may be substituted to bypass these limits.

## Certificate lifetime

This demo accepts loss, so certificate/key/ACME state are RAM-only rather than copying secrets to unverified disk. Normal container restart retains secrets while /run exists; host reboot requires operator reissuance and fresh deployment. This is not durable certificate recovery. Use approved manual DNS-01 issuance to /run, clean the exact TXT after validation, and privately re-copy validated certificate/key into the demo directory with required ownership. Verify hostname, key match, CA chain and expiry; recreate only the gateway so the secret bind mount sees new files. Never reload a gateway with mismatched key/cert. Owner must approve DNS validation actions and ACME terms where required. No automatic renewal is claimed; alert at 21 days and schedule a named operator renewal before expiry. No port 80 needed. An unattended DNS credential mechanism requires a separately reviewed narrow-scope design. Demo browser access must end if renewal fails.

Run DEMO_ACCEPTANCE_CHECKLIST before reporting availability. A successful controller exit is not a passing browser test or production readiness.

## Restricted operator commands for onboarding

In the same approved root shell and exact instance context:

```sh
API_ID="$(docker ps -q --filter "label=com.docker.compose.project=$INSTANCE" --filter label=com.docker.compose.service=api)"
# Require exactly one container ID and verify its project/service labels first.
docker exec -it "$API_ID" php artisan demo:check
docker exec -it "$API_ID" php artisan platform:bootstrap FIRST_VERIFIED_MFA_USER_UUID SECOND_VERIFIED_MFA_USER_UUID
docker exec -it "$API_ID" php artisan demo:fixtures VERIFIED_OWNER_UUID SELECTED_MERCHANT_UUID
```

No passwords/MFA codes appear in these commands. Owner and administrator UUIDs come from normal authenticated account workflows; never invent their verification/MFA state. Runtime accounts do not inherit migration credentials.

For private mail verification, an operator may inspect the exact demo mailpit container's internal IP and forward its 8025 inbox through the existing authenticated SSH session to the owner's **127.0.0.1 only** (for example `ssh -N -L 127.0.0.1:18025:VERIFIED_PRIVATE_MAILPIT_IP:8025 ctsadmin@2.24.48.221`). Do not publish a container mail port or use a wildcard local bind. The owner reads verification/invitation mail privately; do not expose/reset links in chat or test evidence. Close the SSH forwarding session after use.
