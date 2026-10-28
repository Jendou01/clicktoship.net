# Demo acceptance — record evidence, never assume PASS

No remote checks were performed by implementing this profile. All actual-host and browser rows start BLOCKED until separately approved execution.

| Gate | Required evidence |
|---|---|
| Release | Exact clean SHA, successful exact hosted CI, independent PR/manifest review, pinned signature key, verified archive/content digests/labels; no provenance claim |
| Isolation | Fresh unique project, tmpfs volumes with no retained bind/external mounts, /run tmpfs, no swap, private subnet with no overlap |
| Configuration | control.py verify; schema:verify, unchanged security:check-config and demo:check from actual runtime; no failed or omitted check |
| DB privileges | Runtime not superuser/createdb/createrole/bypassrls; no schema CREATE/DDL/TRUNCATE/direct money UPDATE; reviewed grants after migrations; migrator not mounted by runtime |
| TLS/gateway | Trusted hostname/chain/key match, >21 days; TLS1.2/1.3, 401 without credential, 401 after revocation, authenticated readiness200, unknown Host denied; no -k or insecure browser context |
| Exposure | Actual Docker ports/networks plus independent outside-host verification: only approved gateway443 and existing SSH; DB/Redis/PHP/mail/debug/Horizon closed; verify Docker forwarding firewall before start |
| External denial | Disabled adapters, internal runtime network, rejected HTTP requests, financial/submission/connector writes fail closed; no SMTP relay, payment/carrier/customer message |
| Accounts | Owner + operational member via normal invite; two independently controlled verified MFA admins via normal bootstrap, no default passwords/recovery-code seeding |
| Browser | EN/AR homepage, login/register, RTL, desktop/mobile overflow; owner/member dashboard/logout; CSRF rejection, unauthorized401, cross-tenant404, RBAC403; admin rejected before recent MFA and accepted afterwards |
| Synthetic functionality | Fixture repeatability and draft creation, configured synthetic quote, empty wallet, draft tracking/analytics, closed bulk scanner/export paths; never count disabled live capabilities as successful operations |
| Operations | API schema/DB/Redis readiness, both Horizon roles, one scheduler, memory/disk/expiry monitoring; named manual DNS-01 renewal operator; operator accepts permanent loss |

## Automated browser runner

Existing full Playwright integration suites remain the regression baseline (identity/merchant/platform/shipping/pricing/tracking/finance/etc.) on their dedicated local testing database. Never point the E2E preparation/rebuild scripts at this VPS. They have distinct guards and may not seed the demo.

For later approved remote acceptance, use `scripts/demo/browser-acceptance.cjs` on a trusted operator Linux workstation with the locked Playwright dependencies and trusted browser CA roots. It requires a root/user-private 0600 regular fixture JSON file containing fresh private gateway credentials and previously normally provisioned synthetic owner/member/otherOwner/adminA/adminB accounts. owner/member/otherOwner contain email/password/merchantName; adminA/adminB contain email/password/mfaCode (a fresh one-use authenticator challenge code, **not an MFA seed or recovery code**). Include the active synthetic serviceId from normal independently approved pricing configuration. Prepare this file privately; never upload it to ChatGPT/Git or record a test trace/screenshot containing it. Two independent admin owners supply their own short-lived challenge codes; stale codes correctly fail.

```sh
CTS_DEMO_ACK=SYNTHETIC_ONLY_PERMANENT_DATA_LOSS_ACCEPTED \
  node scripts/demo/browser-acceptance.cjs /private/demo-browser-fixture.json
```

Runner checks protected EN/AR/mobile pages and banner, login/logout, API draft/quote, denied submit, isolated tenant access and two MFA/admin dashboards. It emits codes only; failed private diagnostics are suppressed. It does not test real provider calls, customer mail, funded ledger, delivered carrier events, full accessibility, load, or production readiness. Manual registration, invitations, CSRF/RBAC and detailed feature acceptance must also use the existing full-stack regression tests plus actual demo checklist; a script pass does not fill untested rows.

If an account, approved rate card, browser, service or evidence is missing, record BLOCKED. No skipped/zero-test run counts as approval. Keep private account provisioning material outside chat, and revoke access after the review window.
