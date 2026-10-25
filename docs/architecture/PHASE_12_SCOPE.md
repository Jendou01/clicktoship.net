# Phase 12 implementation scope and permission policy (before coding)

Base main verified at PR #11 merge `18ae1e3ded0ba17166aa3f3ff92c8a9fe7943872`; feature branch `feat/phase-12-super-admin`.

## Approved independent implementation

Operations composes existing module-owned records into platform overview, period trends, redacted activity, approval workload, derived operational alerts, safe health and fixed read-only settings. Existing merchant directory and all mutation/review workflows remain owned by their existing services. Phase 11 UI primitives are reused by a distinct bilingual responsive platform sidebar/shell and module landing sections; existing admin pages are integrated, not replaced by duplicate business engines.

## Permissions

All new platform APIs remain browser-session-only with current identity/session verification and recent MFA. Merchant/Sanctum API tokens, including wildcard tokens belonging to administrators, cannot become platform credentials. Existing platform token rejection preserves rather than expands the authorization contract.

New `platform.overview` allows shell/source discovery only. Separate `platform.metrics.merchants`, `.users`, `.shipping`, `.bulk`, `.tracking`, `.carriers`, `.pricing`, `.finance`, `.customs` authorize aggregate metadata/counts for those sources; unauthorized sources are not queried. `platform.health.view`, `platform.settings.view`, `platform.alerts.read`, `platform.alerts.acknowledge` cover safe health, code-owned settings and actor-specific alert receipts. These permissions are never target-access capabilities and never enter platform_access_grants.

Super Admin receives these bounded overview permissions. Operations receives operational/merchant/carrier/pricing/customs metrics and health, not users/finance metrics or settings. Finance receives finance/merchant counts and approval links; Support receives merchant/shipping/bulk/tracking counts. Existing privileges, MFA, last-admin protection and independent grant controls are unchanged. No actor may grant these permissions through merchant roles or tokens.

Global activity exposes only platform-level audit IDs/action/time, under existing platform.audit.view; no metadata, private merchant audit or contacts. Approval summaries contain allowlisted types/counts and existing workflow links, not financial payloads or private merchant identifiers. Exact-target views and mutations still require existing capabilities/grants and independent review. Aggregate access cannot open private data.

## Policy limits and deliberately stopped capabilities

No policy authorizes global cross-merchant posted/reserved amount disclosure. Finance overview therefore shows separate currency wallet counts and reconciliation/restriction counts, with amounts explicitly unavailable pending exact target grants; existing granted WalletBilling views remain available. No balances are mislabeled as revenue and no new ledger/financial action exists.

There is no pending account status: active/suspended/closed are separate from pending business verification. The UI must not invent a pending account. Carrier success/verified tracking exclude synthetic provenance. Country review does not clear customs. Platform settings are a typed read-only policy/limit catalogue; no arbitrary editor, unlock, gate override, worker restart or unsafe replay control.

## Schema and monitoring

A small append-only actor-specific alert receipt table is justified solely by requested read-state handling. Alerts themselves are computed from existing current facts, not copied into another event store. Opaque stable fingerprints include condition and source watermark; changed facts become unread. Receipts do not resolve underlying cases or send notifications. Other business tables/state machines remain unchanged.

Add module read indexes only after measured plans justify them; use existing indexes and bounded date/group/keyset queries first. PostgreSQL/Redis integration, independent security races, representative synthetic volume, final UI inspection and complete regressions are mandatory. No preview/tunnel/deployment, real carrier/payment/notification activation, automatic merge or Phase 13.
