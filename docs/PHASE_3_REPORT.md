# Phase 3 implementation report

Implemented on `feat/phase-3-merchant-admin`, based on verified main merge `b48eb7171ab5971b1a0583d383b6b4a242ca62d3` (PR #1). The user's implementation approval supersedes proposal defaults. No main merge, production deployment, Phase 4, wallet, pricing, carrier integration or shipment creation.

## Features implemented

* Encrypted business profiles/contact information, country-aware address book, default origin/return references, typed locale/timezone settings and personal name/locale updates.
* Account status active/suspended/closed; separate business status unverified/pending/verified/rejected. Email-verified active accounts can use dashboards before business verification. Manual review uses immutable encrypted submission snapshots and immutable independent decisions; pending snapshots freeze profile editing, profile changes invalidate previous verification, rejection permits resubmission. No document uploads or legal/KYC-certification claim.
* A separate fail-closed ShippingEligibility policy considers account/business state and reports unavailable wallet/carrier/contract/restriction evaluators. Every Phase 3 decision is eligible:false, including verified businesses. No financial/carrier/shipping processing is present.
* Email-bound team invitations with 72-hour expiry, encrypted recipient and queued notification, hashed token, revocation, resend rotation and authenticated verified-email single-use acceptance. Inviter permissions and role grantability are rechecked under merchant lock; existing memberships cannot be overwritten by acceptance. Built-in roles only; Phase 2 direct-add remains compatible.
* TOTP MFA through maintained OTPHP11.5.0 plus constant-time encoding dependency. Confirmed/pending secrets are encrypted; enrollment expires after 10 minutes. Current password required for mutations and rechecked with the locked user/live session before writes, reused TOTP steps rejected, eight random 256-bit recovery codes hashed and consumed once. Six mutations per account/minute plus 20/IP. Fresh platform MFA is bound to the current auth-session registry and credential version for 15 minutes. Platform assignments prevent disabling MFA until audited role removal; factor changes revoke other sessions/tokens/grants.
* Explicit merchant access requests with reason, capability allowlist, independent approval by another MFA-authenticated Super Admin, expiry at most 15 minutes after request and binding to the originating user/session. No self-approval, impersonation, ordinary tenant-scope bypass or merchant-token minting. Every use rechecks actor/approver permission/status/session/MFA version and grant scope/expiry/revocation.
* Restricted platform directory, target-authorized profile/verification/audit access, activation/suspension/reactivation/closure, user suspension/reactivation and built-in platform assignments. Global user lists omit contact/name/email. Generic target-profile views disclose legal name/country only; full submitted contact/identifier data requires the separate verification-review grant. All sensitive changes are versioned/audited, use transaction locks and preserve the last usable Merchant Owner and Super Admin. Closure is terminal; suspended accounts can reactivate. Revoked tokens remain invalid after reactivation; access to other active organizations is preserved.
* A one-time audited CLI bootstrap requires two existing independently controlled active, email-verified MFA-enrolled users; no default user/password is created by normal setup. This command was not run against the normal development database. Synthetic test administrators are explicitly restricted to a separate opt-in E2E database.

## Database migrations

1. `2026_10_23_030000_create_merchant_management`: merchant version/verification status/closed constraint; merchant_profiles, merchant_addresses, merchant_settings, merchant_verification_submissions, merchant_verification_decisions, merchant_invitations; additive built-in role permission backfill preserving existing IDs/assignments. PostgreSQL composite default-address and merchant-role FKs, scoped uniqueness, version/status checks; immutable submission/decision UPDATE/DELETE/TRUNCATE triggers.
2. `2026_10_23_040000_create_privileged_access_security`: user_mfa_credentials, mfa_recovery_codes, platform_access_grants; auth-session MFA/version/composite identity; user admin_version. PostgreSQL checks enforce actor/session identity, independent approval, allowed capability set and maximum 15-minute grant lifetime.
3. `2026_10_23_050000_harden_merchant_management`: database-enforced immutable tenant identities for profiles, addresses, settings and invitations.

Real PostgreSQL tests roll back/reapply all three against populated Phase 2 fixtures and prove users, memberships, tokens and audit history survive. Existing closed accounts deliberately block downgrade to the old status constraint; no silent status rewriting. Development and browser schema migration status and startup are checked separately.

## New APIs

40 new operations; 68 total documented operations including existing health/CSRF APIs. Complete request/response/security definitions are in [OpenAPI](api/openapi.yaml).

| Methods | Path under /api/v1 | Purpose |
|---|---|---|
| PATCH | /auth/me | Name/locale only; no email/security mass assignment |
| GET, DELETE | /auth/mfa | Safe status / guarded disable |
| POST | /auth/mfa/enrollment, /confirm, /challenge, /recovery-codes | TOTP setup, step-up and secure recovery lifecycle |
| POST | /auth/invitations/accept | Authenticated verified-email acceptance |
| GET, PATCH | /merchants/current/profile, /merchants/current/settings | Tenant-derived business data and defaults |
| GET | /merchants/current/eligibility | Independent fail-closed shipping decision |
| GET | /merchants/current/audit-events | Authorized scoped safe audit metadata |
| GET | /merchants/current/verification | Status and immutable submission/review history |
| POST | /merchants/current/verification/submissions | Submit exact merchant version for review |
| GET, POST | /addresses | Bounded list / create |
| GET, PATCH, DELETE | /addresses/{address} | Scoped read/update/archive |
| GET, POST | /team/invitations | Bounded invitation list / send |
| POST, DELETE | /team/invitations/{invitation}/resend; /team/invitations/{invitation} | Rotate/resend or revoke with version |
| GET, POST | /admin/access-requests | Own-session requests or authorized approval queue / request |
| POST | /admin/access-requests/{grant}/approve | Independent MFA approval |
| DELETE | /admin/access-grants/{grant} | Authorized versioned revocation |
| GET | /admin/merchants | Minimal platform directory metadata |
| GET | /admin/merchants/{merchant} | Exact authorized target details |
| PATCH | /admin/merchants/{merchant}/status | Audited versioned lifecycle |
| GET | /admin/merchants/{merchant}/verification | Target-authorized snapshots/history |
| POST | /admin/merchants/{merchant}/verification/{submission}/decision | Immutable decision; no self-business review |
| GET | /admin/merchants/{merchant}/audit-events | Target-authorized safe audit and reasons |
| GET | /admin/users, /admin/users/{user} | Minimized directory/security and role metadata |
| PATCH | /admin/users/{user}/status, /admin/users/{user}/platform-roles | Guarded user status / built-in assignment |
| GET | /admin/audit-events | Platform-only safe audit history |

Browser-only admin/MFA/security paths preserve Sanctum CSRF, active-identity and email verification gates. Tenant APIs intersect token abilities with current membership permissions. HTTP409 rejects stale versions/invalid transitions; foreign tenant IDs return404. Revoked membership tokens now return401, including after reactivation; the existing regression was updated to assert this stronger credential revocation.

## UI screens

Thirteen new screens, each English/Arabic with document-level RTL/LTR: business-profile, addresses, organization-settings, business-verification, invitations, invitation, mfa, personal-profile, admin-merchants, admin-access, admin-users, admin-audit and audit. Existing ten auth/onboarding/security screens remain. Navigation links connect current workspace to management/MFA. Forms use POST and wait for hydration; explicit label/input associations prevent option-text contamination. Secret displays are transient, clearable and never stored in browser storage. No upload or unsupported shipping controls.

## Automated verification

Results below are local execution, not a claim about GitHub-hosted CI:

| Check | Result |
|---|---|
| Backend on real PostgreSQL/Redis | 108 passed, 999 assertions; includes all Phase 2 regression suites |
| Migration rollback/reapply | Passed; preserves Phase 2 users/memberships/tokens/audits |
| Concurrency | Separate-process last-Super-Admin/settings/suspension-owner races plus original owner/membership races passed |
| Frontend component/client | 17 passed |
| Full-stack Chromium | Eight tests passed in three complete runs: 24/24 executions, no retries, default timeouts |
| Python installer integrity | Four passed |
| PHP Pint / Composer strict validation | Passed |
| ESLint / TypeScript / Next.js production build | Passed |
| Composer advisory audit | No vulnerability advisories |
| npm production dependency audit | Zero vulnerabilities |
| OpenAPI internal references / unique operation IDs / Laravel route coverage | Passed; 68 operations |

Security tests cover role matrices, IDOR, forged merchant IDs, mass assignment, CSRF, encrypted data, country/phone/timezone checks, invitation expiry/revoke/replay/wrong email/inviter demotion, MFA expiry/recovery/TOTP replay/rate limits and RFC vector, stale versions, independent approval, grant scope/target/session/expiry/revoke/approver-role loss, suspension/closure/reactivation, no token revival, and immutable PostgreSQL audit/verification relationships.

The first browser runs exposed a shared select-label defect: wrapping option text inside the label prevented exact label lookup. The component now uses explicit for/id associations and has a regression test. All eight tests use original default timeouts and retries disabled. Existing Nginx root redirect regression remains intact.

Browser testing is opt-in: prepare-browser-tests.sh uses testing/clicktoship_e2e, separate Redis prefix/session cookie, Mailpit and Horizon. The synthetic fixture refuses every other database/environment; generated temporary credentials remain in a mode0600 /tmp file and are never printed/committed. Normal setup creates no privileged accounts. CI uses the same preparation; startup reloads Nginx after upstream replacement. Normal development configuration is restored after verification.

## Files, delivery and remaining boundaries

[PHASE_3_FILES.txt](PHASE_3_FILES.txt) contains the complete changed-file manifest. Work stays on feat/phase-3-merchant-admin; delivery commit/push/PR outcome is reported after Git operations. Main is not merged automatically.

* Document uploads remain intentionally unavailable pending secure storage, malware scanning, authorized retrieval and retention requirements.
* Assisted MFA reset is blocked pending identity-proof, independent approval and notification policy. Password plus one-use recovery code is the supported recovery mechanism; no bypass is exposed if all factors are lost.
* No external legal/KYC provider, carrier address validation, financial processing, carrier permission/contract evaluation or shipment creation. Country-specific postal checks cover SA/US; other assigned country codes accept validated general address/contact fields without a claim of country-complete postal/carrier validation.
* Verification, invitation and access-request history list APIs are bounded to 100 retained entries; larger address/admin/audit collections have 25-row pagination. Database history is retained; larger-history browsing/cursor pagination is future work.
* Existing memberships require explicit team management rather than invitation overwriting. Custom/multiple roles, closed-account reopening, email-change verification and Horizon dashboard remain outside this release.
* Production legal/privacy retention, sender/domain/TLS/cookie/proxy configuration, restricted runtime database privileges and monitoring remain launch requirements. No production deployment occurred.
* GitHub REST/GraphQL access has returned Forbidden in this environment; branch push and PR creation/check verification are attempted independently and reported accurately.

The final security pass also verifies non-JSON unverified requests receive a safe403 instead of a web redirect, masks generic platform profile contact/identifier fields, and revalidates MFA mutation identity under the user lock after concurrent session/password revocation. A verification run interrupted by switching the runtime configuration was discarded and rerun fully; interrupted execution is not counted as a pass.

Final delivery verification: 108 backend tests / 999 assertions; 17 frontend tests; 4 installer tests; three full eight-test Chromium cycles passed in 50.6s, 50.2s and 49.4s. The final cycle includes locked MFA identity revalidation and platform profile redaction. Normal development was restored after browser verification; startup/readiness, migration state and Horizon are healthy. Production build and dependency audits passed. The complete manifest contains 64 changed files. Git commit/push and PR outcome are supplied in the delivery message.
