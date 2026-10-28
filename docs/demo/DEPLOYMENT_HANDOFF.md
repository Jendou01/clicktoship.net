# ChatGPT Work: disposable demo deployment handoff

Status: preparation only. No approved merged demo release exists yet. PR #18 is
the review candidate; do not substitute the earlier Phase 17 commit or a CI test
signature for release approval. This document does not authorize deployment.

1. **Freeze approval.** Read PR #18, SECURITY_REVIEW.md and the final CI evidence.
   Obtain independent review and owner approval. Record the resulting full merged
   main SHA and successful Foundation CI push run for that exact SHA. The operator
   must stop if these fields are absent; a feature-branch check is insufficient.
2. **Build and verify artifacts.** Follow DEMO_RELEASE_PROMOTION.md on a trusted
   clean Linux packager. `package.py` uses tracked commit bytes and production
   Dockerfiles, records three immutable image content IDs and the archive hash.
   Independent reviewers inspect these actual values, then sign exact manifest
   bytes with a real off-host approval key. Independently pin its public-key hash.
   Transfer privately; no CI ephemeral signing key or mutable tag is authority.
   This supplies reviewed integrity, not signed build provenance.
3. **Recheck the authorized host.** Only after separate deployment authorization,
   verify srv2104412.hstgr.cloud / 2.24.48.221, existing SSH recovery, patched Linux,
   supported Compose, free RAM, /run tmpfs, no swap, free subnet172.30.73.0/24 and
   gateway port. Do not alter unrelated resources or silently disable swap.
   Verify Docker forwarding firewall protection while public web ingress is closed.
4. **Select demo configuration.** Use only infrastructure/demo/compose.yml and
   the controller's exact template/host/proxy settings. Production preflight stays
   unchanged. Accept synthetic-only data and permanent loss. No providers,
   payments, funded wallets, commerce integrations or external SMTP are enabled.
5. **Generate secrets.** In a private root shell, disable tracing/core dumps;
   choose a fresh cts-demo-12hex instance. Controller generates separate app,
   owner/runtime DB, Redis and gateway secrets in /run with service-readable
   ownership. Do not put secret values in chat, Git, argv or evidence.
6. **Prepare TLS.** Obtain owner-approved DNS-01 certificate issuance and clean
   only the validation TXT. Keep key/ACME state in protected RAM; validate trusted
   chain, canonical hostname, key match and >21 days validity. No port80 issuance.
   Name a renewal operator and expiry alert. Reissue after host reboot; there is
   no unattended renewal or durable key recovery claim. Atomically replace the
   matching pair and use refresh-gateway, then verify the served certificate.
7. **Load, prepare and start privately.** Use the exact root-shell `demo` wrapper
   and arguments in REMOTE_DEMO_SETUP.md. Run `demo load --archive ...`,
   `demo prepare --certificate ... --tls-key ...`, `demo verify`, then only after
   explicit host/release authorization `demo start`. Initial ingress is loopback
   127.0.0.1:18443. Controller never changes firewall/DNS.
8. **Initialize the database.** Startup creates fresh owner/runtime roles, runs
   migrations with owner-only credentials, applies unchanged runtime-grants.sql,
   then schema:verify, security:check-config and demo:check. Never use test DB
   preparation scripts or direct financial writes. Partial startup is stopped;
   inspect static errors and tear down the exact recorded instance before retry.
9. **Provision synthetic users.** Register/verify owner and operational member
   normally using example.invalid mail through private Mailpit. Create merchant,
   invite/accept shipping_operator. Run demo:fixtures for the verified owner and
   selected merchant; verify three repeatable drafts and an empty wallet. Activate
   sample pricing only through normal two-person draft/approval workflows.
10. **Enroll MFA personally.** Two independently controlled verified platform
    users enroll/confirm their own MFA before existing platform:bootstrap. Do not
    read/store real authenticator seeds or recovery codes in ChatGPT. No direct
    role/MFA table writes or default shared passwords.
11. **Test privately.** Use authenticated loopback SSH forwarding and normal
    trusted TLS. Run browser-acceptance.cjs with a private0600 synthetic fixture
    and --private-loopback. Supply fresh one-use MFA challenges, never seeds.
    Complete DEMO_ACCEPTANCE_CHECKLIST, including EN/AR/mobile, tenant/CSRF/RBAC,
    workers/scheduler, revocation, forbidden ports and disabled live operations.
12. **Publish only after approval.** Obtain separate gateway/public ingress
    approval for the exact tested release. `demo publish --approve-public-gateway`
    recreates only the gateway at2.24.48.221:443. Keep forwarding firewall blocked
    until certificate/authentication/runtime/port checks pass. Then authorize only
    required HTTPS ingress; no DB/Redis/mail/Horizon/debug/dev ports.
13. **Check independently from outside.** Repeat public browser acceptance without
    --private-loopback, trusted TLS, unauthorized401/revoked401 and private-port
    checks. Record actual evidence; private CI is not public-host acceptance.
14. **Rollback safely.** Close access on failure. Emergency `control.py stop` needs
    only exact instance directory, acknowledgement and local identity receipt;
    it does not require working TLS/GitHub. Use DEMO_RESET_AND_TEARDOWN.md. A
    rollback is a fresh isolated last-approved release with new disposable data,
    never a retained-schema downgrade or reuse of lost secrets.
15. **End the demo.** Revoke access, stop writers/workers/scheduler, and run exact
    receipt-validated teardown. Verify only recorded containers/networks/tmpfs
    volumes disappeared and unrelated resources survive. No global prune, forced
    foreign-volume removal or broad deletion. Remove only the verified private
    RAM instance and operator fixture after successful resource teardown.

Actual deployment remains blocked until independent release review/signature,
merged-SHA CI, target prerequisites and explicit owner deployment approval exist.
Off-server backup is not a requirement for this expressly disposable profile;
this does not waive the separate staging/production backup or encryption gates.
