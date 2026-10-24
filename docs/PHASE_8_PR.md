# Phase 8: private bulk shipping import and draft preparation

Merchants can upload UTF-8 CSV or restricted single-sheet XLSX, confirm bilingual column mappings, review row errors and explicit duplicate decisions, preview configured public prices and approve valid rows for shipment draft preparation. Imports never submit to a carrier, accept a shipment price or reserve/capture funds.

Add encrypted private sources, bounded structural parsers, immutable tenant-bound histories, idempotent commands/row identities, transactional work records, per-row authorization, leases and database-backed recovery. Reuse Shipping validation/persistence and the Phase 5 calculator. Production processing remains closed pending approved malware scanning, shared storage and retention/operations policy; structural inspection is not an antivirus claim.

Validation: 261 backend tests / 2,566 assertions; 64 frontend tests; 18/18 Playwright tests in three complete runs; 1,000-row, concurrency/revocation/cancellation, spreadsheet attack, migration preservation and repeated-install tests passed. Pint, ESLint, TypeScript, production build, Composer validation, four installer tests and dependency audits passed (zero advisories).

Architecture, API contracts, limits, remaining launch decisions and complete execution evidence: docs/architecture/PHASE_8_BULK.md and docs/PHASE_8_REPORT.md. No merge, deployment, live carrier/financial activation or Phase 9.
