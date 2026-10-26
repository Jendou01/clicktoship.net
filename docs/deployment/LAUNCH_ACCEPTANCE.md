# Owner interactive acceptance — protected staging only

This is a test plan, not a currently accessible URL. Owner must first authorize exact hosting and pass applicable security/storage/backup/mail gates. After authorization the operator privately provides the **verified actual HTTPS URL** and revocable per-reviewer gateway credentials. Never use Git/PR/public screenshots to distribute app passwords, MFA secrets or recovery codes. Gateway entry does not replace application login/MFA.

Record release SHA/browser/device/locale, synthetic merchant, expected permission, actual result and redacted issue ID for each journey. Repeat English LTR/Arabic RTL, desktop/tablet/mobile, keyboard/focus/errors, back/forward and language/context switching. Do not use real persons/orders/credentials or pay a carrier.

| Journey | Acceptance |
|---|---|
| Private gateway | no credential401 for UI/API; exact approved TLS chain/host; revoked gateway account denied |
| Homepage EN/AR | accurate disabled-live copy, visible navigation/controls, correct direction, no overflow |
| Registration/login | unique synthetic account, private verification email, CSRF, password reset, logout/session/token revocation; no seed/default password |
| Onboarding/dashboard | verified email permits account, independent business verification; authorized merchant context only |
| Team | invitations/revocation/last-owner protection, foreign-merchant IDs denied, language switch preserves current context |
| Shipping drafts | field validation, exact immutable revisions/quote, no real submit/label/charge/pickup side effect |
| Bulk CSV/XLSX | small synthetic files, required mappings/row errors/reviewed draft dedupe; staging scanner gate visibly rejects processing until approved |
| Rates/tracking | synthetic estimates/provenance, no fabricated real quotes/delivery; unknowns remain unresolved |
| Wallet | zero-balance opening/views/exact currency/permissions, no funding/real charge; no synthetic credit represented as payment |
| Customs | draft readiness/quarantine and policy limitations, no real upload/filing/download without storage/scanner approval |
| Commerce | both provider-disabled states truthful; synthetic internal tests do not imply owner can connect real stores |
| Analytics | authorized aggregates/exact permission omission, tenant switching safe; normal private export unavailable |
| Platform | two independent verified/MFA-enrolled admins, recent challenge; no merchant bypass; exact expiring independently approved grants |
| Administration | merchant/carrier/pricing/financial reviews use existing role/grant/approval fences; closed actions cannot be activated |
| Responsive/accessibility | named tables, labelled fields, mobile cards, visible keyboard focus, RTL identifiers; formal AT review separately required |

Preserve test evidence safely. A disabled capability passing its fail-closed check is not a successful live integration test. Stop any journey requiring unapproved storage/provider/payment/notification. Revoke synthetic accounts/gateway credentials after the approved review window using existing audited workflows; do not erase immutable history.
