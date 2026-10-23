# ClickToShip Phase 3 — proposed implementation plan for approval

Reviewed proposal, approved by the user on 23 October 2026. Implementation amendments below supersede proposal defaults; execution evidence is in PHASE_3_REPORT.md. No production deployment or Phase 4 work.

## 1. Verified baseline

Repository: Jendou01/clicktoship.net. Local main was fast-forwarded to origin/main at `b48eb7171ab5971b1a0583d383b6b4a242ca62d3`, the two-parent merge titled `Merge pull request #1 from Jendou01/feat/phase-2-auth-tenancy`. Its parents are the foundation commit and Phase 2 fix `9ebc19fb15ad9403ac5760810e44d2c3465c7a9f`; implementation commit `6da835ef8d29c5c0bfea4e2c58a52cee0f2e23dd` is also an ancestor. Working tree and index are clean. The remote uses a narrow HEAD fetch refspec, so an explicit main-to-origin/main fetch was needed; no repository configuration was changed.

Reviewed AGENTS.md, ROADMAP.md, PHASE_2_REPORT.md, architecture, schema, decisions, security, open questions, current role catalogue and merchant/audit services. Reviewed the full uploaded Technical Blueprint v1.0; SHA256 `2a69f9ee4c5f1d53058461e15d1cd3bf5ec82c95dc645c738c572ec89d28fa31` matches SOURCES.md. The Blueprint is reference evidence; the user's phase sequence and repository decisions govern delivery. Its sprint 3 Aramex work is not this roadmap's Phase 3.

Phase 2 supplies authentication, email verification, real session/token lifecycle, tenant context, six merchant/four platform roles, team management, versioned membership writes and append-only audits. MFA, platform assignment endpoints, merchant profiles/addresses/settings, business-verification review, invitations and explicit privileged tenant access are absent. Email verification is not business/KYC verification. Historical Phase 2 counts are 64 backend, 10 frontend, 6 browser and 4 installer tests; these were not rerun for this read-only planning task. GitHub-hosted Actions success is not inferred from the merge.

## 2. Proposed scope and boundaries

Deliver merchant business profiles, a tenant address book, limited typed organization settings, manual business-verification submission/review, user and membership administration, secure initial platform administration, and scoped audit views. Extend existing Identity and Merchants modules; keep controllers thin and mutations in owning application services. Add a bounded Operations module for platform access grants/review orchestration only if needed by those workflows.

Retain all existing Phase 2 APIs/roles, UUID conventions, CSRF/session protections, merchant-bound tokens and tenant context. Keep custom roles/multiple roles per membership outside initial scope; administrators assign built-in roles through separately authorized operations. No shipping, pricing, wallet, payments, carrier lookup/validation, platform-wide operational dashboard or production deployment. The broad admin dashboard in Phase 12 remains later work. Horizon remains closed unless a distinct monitoring permission and MFA policy are explicitly approved.

## 3. Dependencies and decisions requiring approval

| Decision | Proposed default | Impact |
|---|---|---|
| Business verification | Manual review of versioned business details; states draft/submitted/approved/rejected; rejection reason required | Product/legal must define jurisdiction-specific fields and approval authority; no regulatory compliance claim |
| KYC documents/provider | No uploads or external KYC in initial slice | If documents are required, private storage, encryption, malware scanning, download authorization and retention become dependencies before that slice is enabled |
| Merchant lifecycle | Preserve active/suspended operational status separately from business-verification status | Existing merchants must not become suspended/unverified through an implicit backfill; decide which future capabilities require approval |
| Profile/address fields | Legal/display name, country, business contact, conditional registration/tax identifiers; address label/contact/lines/country/city/optional region/postal and origin/return defaults | Confirm launch countries and required fields; no Saudi-only rules or promise that a saved address is carrier-serviceable |
| Settings | Allowlisted locale, IANA timezone and address defaults only | No arbitrary JSON settings, pricing, currency conversion or billing policy |
| Privileged MFA | TOTP plus hashed recovery codes; mandatory before new platform-admin access | Confirm whether to require MFA for merchant Owner/Admin sensitive mutations now; propose enrollment support for all users, with any merchant enforcement rollout explicitly approved |
| Initial Super Admin | Controlled audited CLI bootstrap using an existing verified active user, no seeded account/password | Identify responsible operator and second approver; do not share credentials in chat |
| Explicit merchant access | Short-lived, session-bound grant for one merchant and allowlisted capabilities, with reason and approval by a different authorized person | Confirm approver: merchant owner or separately authorized platform security administrator; no self-approval, impersonation or unrestricted token |
| Invitations | Email-bound expiring invitation/acceptance workflow; keep current direct-add compatibility initially | Confirm expiry (propose 72 hours), resend policy and whether direct-add should eventually require acceptance |
| Platform role administration | Super Admin/security permission plus MFA/recent reauthentication; preserve last usable Super Admin | Approval policy for grants/revocations and MFA recovery must be agreed before activation |
| Privacy | Minimum data collection, masked platform lists, safe audit metadata, retention policy before sensitive-document collection | Legal owner must decide retention/deletion/data residency and access-review policy |

Carrier permissions, tariffs, PSP and store contracts remain future-phase dependencies, not prerequisites to local profile/address CRUD. Production mail sender/domain, TLS/cookies, database runtime privileges and monitoring remain launch requirements; development can use existing Mailpit. No new dependency should be added without a concrete need; use a maintained, reviewed TOTP implementation rather than inventing cryptography.

## 4. Proposed additive database changes

Final migration grouping follows approved workflows; never rewrite deployed Phase 2 schema/history.

| Schema change | Fields/invariants |
|---|---|
| merchants | Add optimistic version if needed; lifecycle changes stay active/suspended, require reason and audit; verification state is separate |
| merchant_profiles | UUID, merchant_id unique FK, legal/display names, business country/contact, approved optional identifiers, version, timestamps; sensitive values encrypted where needed and excluded from logs |
| merchant_settings | UUID, merchant_id unique FK, locale/timezone, nullable origin/return address references, version; explicit typed columns/checks |
| merchant_addresses | UUID, merchant_id, purpose/label, contact and postal fields, version, timestamps, deleted_at; UNIQUE(merchant_id,id), country/status checks, tenant/list indexes |
| Address defaults | Composite FK(merchant_id,address_id) from settings; service locks prevent pointing defaults at soft-deleted addresses; clear/replace defaults transactionally before archive |
| merchant_verification_submissions | UUID, merchant_id, submission version, immutable profile snapshot with minimum sensitive data, submitted_by/time, review outcome/reason/reviewer/time; at most one pending submission per merchant via partial unique index |
| merchant_invitations | UUID, merchant_id, normalized intended email, merchant role FK/scope, hashed token, inviter, expiry/accepted/revoked fields, version; unique active invitation policy, locked single-use acceptance |
| user_mfa_credentials/recovery codes | Encrypted TOTP secret, confirmation time, last accepted TOTP step for replay protection; hashed one-use recovery codes, version and security audit; recent step-up timestamp bound to auth session |
| platform_access_grants | UUID, admin actor and auth_session FK, target merchant, requested capability allowlist, reason, separate approver, issued/expires/revoked fields; cannot approve own request; short TTL, no wildcard |
| Existing roles/permissions/assignments | Add granular approved permission codes through an additive idempotent catalogue/backfill migration; keep merchant/platform scope constraints and one merchant role per membership; add version metadata for platform-assignment races if needed |
| Existing audit_logs | Reuse immutable table; add safe event types/metadata and query indexes only as needed; never add a second competing audit store |

Every tenant-owned child has merchant_id and composite tenant foreign keys. Restrict history deletion; archive addresses rather than cascading deletion. Invitation role relationships must enforce merchant scope in PostgreSQL. Profile changes after submission cannot silently change the reviewed snapshot. Decisions use expected versions/row locks, permit a defined resubmission path and reject duplicate/stale review. Migration tests cover populated Phase 2 data, upgrade, disposable rollback/reapply, backfill and fresh installation.

## 5. Proposed versioned API contract

Paths below are proposals under `/api/v1`; define requests, DTOs, errors and permission matrix in OpenAPI before implementation.

| Operations | Endpoints |
|---|---|
| Merchant profile/settings | GET/PATCH /merchants/current/profile; GET/PATCH /merchants/current/settings |
| Address book | GET/POST /addresses; GET/PATCH/DELETE /addresses/{address}; select defaults through settings |
| Business verification | GET /merchants/current/verification; POST /merchants/current/verification/submissions; GET own submission/history through the verification resource |
| Team invitations | GET/POST /team/invitations; POST /team/invitations/{invitation}/resend; DELETE /team/invitations/{invitation}; POST /auth/invitations/accept |
| Current user profile | PATCH /auth/me for name/locale only initially; retain existing team, role, permission, session and token APIs |
| MFA | POST /auth/mfa/enrollment; POST /auth/mfa/confirm; POST /auth/mfa/challenge; POST /auth/mfa/recovery-codes; DELETE /auth/mfa; no secret in GET/list responses |
| Platform directory | GET /admin/merchants; GET /admin/users; minimum masked metadata only, separately permissioned |
| Explicit target access | POST /admin/access-requests; POST /admin/access-requests/{request}/approve; DELETE /admin/access-grants/{grant}; bind grant server-side to its originating admin session |
| Merchant administration | GET /admin/merchants/{merchant}; PATCH /admin/merchants/{merchant}/status; GET /admin/merchants/{merchant}/verification; POST /admin/merchants/{merchant}/verification/{submission}/decision |
| User administration | GET /admin/users/{user}; PATCH /admin/users/{user}/status; GET /admin/users/{user}/platform-roles; POST/DELETE platform-role assignment routes with explicit expected versions |
| Audit | GET /merchants/current/audit-events; GET /admin/audit-events with platform/target-grant scope and allowlisted filters |

Never implement /addresses/validate as fake carrier validation. Email changes require a separate verified change protocol and are excluded from the initial name/locale edit unless approved. No account/merchant hard-delete API. Platform grant/role mutations and MFA management are browser-session-only, CSRF-protected and recently reauthenticated; merchant API access remains bounded by current membership AND token abilities. Public invitation-link pages must not disclose membership, business or PII before authenticated acceptance.

Use stable error envelopes: 401 unauthenticated, 403 insufficient permission/step-up, 404 foreign tenant object, 409 stale version/transition conflict, 422 invalid fields and 429 throttling. Bounded lists use explicit documented pagination; new large admin/audit lists use stable cursor ordering. Every sensitive mutation and privileged target access is audited without secrets or raw PII.

## 6. Proposed RBAC and security rules

| Role | Initial Phase 3 authority |
|---|---|
| Merchant Owner | Own organization profile/settings/verification, addresses, team invitations/roles, safe audit view; owner-only ownership safeguards remain |
| Merchant Admin | Own profile/settings, addresses and non-owner team administration; propose owner-only verification attestation pending approval |
| Shipping Manager | Address create/update/archive/defaults relevant to shipping; no verification/legal-profile or user-security changes |
| Shipping Operator | Address list/detail and optionally create/edit (propose no archive/default changes); product approval required |
| Finance Manager | Read business/billing profile and address metadata only as needed; no verification approval, team grant or legal-field changes |
| Read-Only Viewer | Read operational address/profile subset, no sensitive business identifiers or mutations |
| Super Admin | Separately granted platform directory, lifecycle, verification, audit and role administration; mandatory MFA and explicit target scope, no automatic merchant-data bypass |
| Platform Operations | Minimal merchant directory and explicitly granted verification/lifecycle functions if approved; no platform role grants or secret export |
| Platform Finance | No financial processing added; minimum masked business/verification read only if approved |
| Platform Support | Masked directory and approved, expiring merchant read grant; no impersonation or lifecycle/role mutations |

Introduce granular permissions such as addresses.view/create/update/archive, merchant.profile.view/update, merchant.settings.manage, merchant.verification.submit, team.invite, audit.view and platform.merchants.view/suspend, platform.verification.review, platform.users.suspend, platform.roles.assign, platform.access.approve, platform.audit.view. Do not map every new permission to existing broad platform.operations/support by accident. Approval authority must be distinct from a generic platform role label.

Platform target grants are resolved through an explicit platform authorization path; never add a blanket global-scope bypass or attach platform role permissions to ordinary MerchantContext. Every request rechecks role, user/merchant status, MFA freshness, grant expiry/revocation and target/capability. A grant cannot turn into a merchant membership or mint merchant tokens. Require a reason for lifecycle/review/access actions. Tenant-private data must not leak through generic platform search, audit filters, foreign IDs or validation messages.

User suspension increments auth_version and revokes sessions/tokens; reactivation requires fresh authentication. Merchant suspension immediately denies tenant operations and queued jobs, revokes its API tokens, but must preserve the user's access to other active organizations. Membership suspension/removal similarly invalidates that user/merchant's credentials. Review precise reactivation semantics and test that prior revoked tokens do not revive. Use consistent lock ordering, expected versions and reauthorization under lock; prevent concurrent loss of the last usable owner or Super Admin. Never allow an administrator to recover their own MFA through an unaudited bypass.

TOTP enrollment never authenticates the user before confirmation; recovery codes are single-use and plaintext returned once. Rate-limit verification challenges/enrollment/recovery. Treat recovery and factor replacement as high-risk operations. Invite tokens follow Phase 2's hash-at-rest, fragment-to-POST, encrypted private-email pattern; acceptance rechecks inviter authority, intended verified email, merchant state, role grantability, expiry/revocation and membership races. Notifications dispatch after commit. Any future cross-module asynchronous effect must use the architecture's transactional-outbox discipline; do not introduce business events with no consumer merely to scaffold it.

## 7. UI screens

Extend English/Arabic screens with document-level RTL/LTR, accessible keyboard navigation and localized validation/loading/empty/conflict states:

1. Organization profile and typed settings, with permission-based editable fields.
2. Origin/return address list, create/edit, archive and default selection.
3. Business-verification wizard, read-only submitted snapshot, status/history and rejection/resubmit flow.
4. Existing team screen enhanced with invitation list/send/resend/revoke and authenticated acceptance.
5. Personal name/locale and MFA enrollment/challenge/recovery-code controls.
6. Restricted admin merchant directory/detail, verification queue/review, suspend/reactivate with reason.
7. Restricted admin user directory/detail, built-in platform assignments and security status; no passwords/secrets displayed.
8. Explicit merchant-access request/approval/session expiry screen and audit views with masked PII.

UI visibility is convenience only; every operation remains server-authorized. Keep unsupported shipping, billing, carrier validation, exports and document-upload actions unavailable rather than simulated.

## 8. Tests and acceptance criteria

Backend: real PostgreSQL/Redis; preserve the dedicated clicktoship_test guard. Test all endpoints across all ten roles plus anonymous and bearer/session clients. Cover IDOR for addresses/profiles/submissions/invitations/audits/grants, forged merchant IDs, mass assignment, scope FKs, archived defaults, rollback/backfill, transitions/version conflicts, concurrent default changes and reviews, last-owner/Super-Admin races and reauthorization after demotion. Verify current merchant switching does not bleed profile/address caches or job context.

MFA/platform: missing/unconfirmed/replayed factor, incorrect/expired challenges, recovery-code concurrency, credential replacement/session reset, rate limits, expired/revoked/wrong-session/wrong-target grants, self-approval, scope escalation, platform permission loss, suspended actor/merchant, denial/audit behavior and safe PII masking. Test no alternate token route bypasses platform MFA/grants.

Invitations: wrong verified email, expired/revoked/reused token, role escalation, inviter revoked/demoted, duplicate concurrent acceptance, existing/removed memberships, private encrypted mail, generic unauthenticated views and notifications only after successful commit. Secrets and raw tokens must not appear in audit/log/API list outputs.

Frontend: component/client tests for forms, role visibility, conflict handling, RTL/LTR and secret lifecycle. Full-stack Playwright includes merchant profile/address defaults, invitation acceptance, verification submit/review, admin MFA/target access, suspension/other-merchant access and existing six Phase 2 flows. Repeated complete browser cycles must respect measured signup-rate windows, without loosening security limits or hiding failures behind retries.

Completion gates:

* Existing Phase 2 behavior and migrations remain compatible; no account, membership or audit history lost.
* Profile/settings/address CRUD works only for the selected authorized merchant, with database-enforced relationships and tested role matrix.
* Business verification is versioned, reviewable and audited, with approved required fields; email verification remains distinct.
* Platform endpoints cannot be used without verified active identity, current permission, MFA and required explicit target grant.
* All lifecycle/role/review/access mutations require authorization, expected state/version and safe immutable audit; sensitive concurrent changes remain safe.
* Revoked credentials/grants never regain access on reactivation; other authorized organizations remain usable.
* All existing and new suites, style/types/build/audits, migrations and repeated browser checks pass; GitHub CI results must be observed if accessible, otherwise report that limitation explicitly.
* OpenAPI, architecture/schema/decisions, security, roadmap and Phase 3 execution report match actual implementation and state any deferred requirements.
* Dedicated feature branch/PR reviewed; no automatic merge or deployment.

## 9. Proposed implementation sequence after approval

1. Agree business fields/review policy, MFA enforcement, platform grant approvals and invitation scope. Create a Phase 3 feature branch from verified main; define bounded acceptance criteria before code.
2. Add profile/settings/address migrations and tenant policies/services, then profile/address UI/API and negative/concurrency tests.
3. Add MFA and controlled platform bootstrap/role management, with recovery policy and security tests. No admin data route enabled before these gates.
4. Add explicit merchant-access grants and audited platform directory/lifecycle/user controls.
5. Add versioned verification submission/review and invitation workflow with private mail; document any approved deferred slice.
6. Complete bilingual admin/merchant UI, OpenAPI and documentation; execute all regressions and repeated browser suites; push a reviewable Phase 3 PR only when authorized implementation is complete.

The user approved implementation with the amendments below. Unresolved document and assisted-recovery requirements gate those specific capabilities; no further general implementation approval is required.

## Approval amendments applied

The user's approval explicitly adds account status closed, uses unverified/pending/verified/rejected business status, requires platform MFA and independently approved expiring session grants, and leaves shipping eligibility separate/unavailable. No documents are collected. Implemented defaults: 72-hour invitations; 15-minute access grants/MFA freshness; approval by a different MFA-authenticated Super Admin; two-account controlled bootstrap; terminal closure; built-in roles; no assisted MFA reset. Histories retained immutably; selected list reads are bounded. New administrative lists retain the existing page-based contract. MFA library selection is OTPHP.
