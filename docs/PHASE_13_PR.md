## Behavior and scope

Merchants gain a bilingual store integration/order workspace with tenant-owned immutable normalized snapshots, durable synchronization and explicitly reviewed single/batch order-to-shipment drafts. Store identity cannot move between merchants; state/session/current-role/token checks, rotating refresh leases and disconnect/callback generation fences prevent installation hijacking or late credential resurrection. Existing Shipping.importDraft remains the only draft engine. Platform monitoring is counts only.

Official Salla documentation is blocked by the environment (403). No app mode/scope/endpoint/field/status/signature/event is claimed verified; default runtime provider and live OAuth/webhooks/Connect/Sync remain disabled. Test protocols are internal synthetic fixtures, not Salla contracts. No live shipping, paid waybill, wallet/COD settlement, notification, deployment or Phase 14.

## Schema and API

Two additive migrations, 12 integration-owned tables and 6 granular permissions; scoped composite constraints, immutable evidence and permanent order/draft links. 16 OpenAPI paths plus merchant/platform dashboard read composition. No new dependency, ledger, carrier or tracking engine. Source changes never silently overwrite a draft; unresolved conflicts cannot be acknowledged into authority.

## Validation

382 backend tests / 4,368 assertions, 110 frontend tests, 26/26 Playwright twice consecutively, real PostgreSQL/Redis independent-process races/crash recovery, schema/rollback checks, Pint 311 files, ESLint/TypeScript, 181-page production build, Composer/npm audits and 4 installer tests. Visual QA: 34 EN/AR desktop/tablet/mobile views without runtime/API/overflow failures. See docs/PHASE_13_REPORT.md for evidence, bounds and unverified external/policy gates. Do not merge automatically.
