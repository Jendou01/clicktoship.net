# Phase 2 implementation plan

Scope: identity/authentication, merchant organizations and memberships, immutable built-in role catalogue, scoped tokens, server-side authorization, bilingual onboarding/team UI. No wallet/shipping/payment/pricing or production deployment.

1. Add additive UUID PostgreSQL migrations: merchant/membership catalogue and role assignments with scope/composite constraints; auth sessions, user suspension/auth version, merchant-bound tokens, append-only audits. Seed only built-in roles/permissions, never default accounts.
2. Implement modular Identity and Merchants application services. Sanctum uses first-party CSRF-protected Redis sessions, plus hashed expiring tokens bound to a merchant. A server registry allows individual session revocation; reset revokes every session/token. Email links use client URL fragments to avoid token-bearing server/access-log URLs.
3. Resolve membership from authenticated session/token only. All tenant models fail closed without context. Context clears on every request/job, and queued work revalidates membership/permissions at execution. Platform assignments never create a tenant bypass.
4. Serialize membership changes with merchant row locks; reauthorize under lock, reject stale versions, prevent last-owner loss, prevent non-owners assigning/altering owners, and reject grants beyond the actor's permissions. Audit changes transactionally.
5. Add bilingual registration/login/verification/recovery/reset, merchant setup/switching, dashboard, team, session/token management. Local Mailpit captures development notifications without logging credentials; production SMTP remains external configuration.
6. Add negative security tests, real PostgreSQL concurrency tests, Redis queue/session/limiter tests, component tests and browser flows. Re-run all foundation suites, builds, style, audits and real startup checks.
7. Update schema/architecture/OpenAPI/roadmap/report, commit and push feature branch; open PR if GitHub API permissions permit. Do not merge main.

Initial role names follow the explicit Phase 2 request rather than the earlier proposed role labels. Each membership has one initial built-in role; custom/multiple merchant roles and platform administrative workflows are later scope. Platform-role assignments have a separate table and no public grant API. Built-in role/permission definitions are read-only to merchant clients. MFA enrollment is a reserved privileged-access gate, not an implemented MFA challenge in this phase.
