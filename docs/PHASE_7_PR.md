# Phase 7: gated shipment management, immutable quote commitment and safe settlement

Adds merchant shipment drafts/revisions, validation, single-use quote acceptance, durable submission orchestration, reservations, protected settlement and evidence-linked independent reconciliation using the existing ledger and Phase 6 carrier journals. Adds private document/scanner foundations, pickup drafts/commands/history and English/Arabic merchant/admin screens.

Normal carrier transmission, real charges and real labels remain disabled. Complete financial/carrier lifecycles are tested only with guarded synthetic outcomes in isolated PostgreSQL/Redis environments. Unknown outcomes retain reservations and never automatically create another shipment or release on expiry. Existing carrier evidence, accounting, storage and regulatory launch gates remain intact.

Validation: 217 backend tests (2,219 assertions), 38 frontend tests, full 16-test browser cycles, production build, Pint, ESLint, TypeScript, normal/disposable migration rollback/reapply, OpenAPI preservation/reference checks, infrastructure tests and dependency audits. See docs/PHASE_7_REPORT.md for final execution evidence and capability stops.

Targets main. Review only: no automatic merge, production deployment or Phase 8.
