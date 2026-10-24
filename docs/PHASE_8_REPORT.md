# Phase 8 — Bulk shipping import completion report

Base main: `6921d5c0a34cf5a5e05ae949939cd5f8ac34592b` (PR #7 verified). Feature branch: `feat/phase-8-bulk-shipping`. No automatic merge, production deployment, live carrier/financial activation or Phase 9.

## Implemented scope

* Private authenticated UTF-8 CSV and restricted single-sheet XLSX upload, EN/AR CSV/XLSX templates, explicit alias-based column mapping, upload progress and import history.
* Streaming, bounded parsing, encrypted original/source/draft data, authenticated file integrity, structural security inspection and a fail-closed production scanning gate.
* Reuse of Phase 7 draft/readiness validation, package/customs arrays, international fields, Arabic names and flat numeric/phone digit normalization; immutable row/mapping/error history.
* Tenant-specific full-shipment duplicate detection, repeated-file warnings and immutable explicit keep/skip review; separate command and business idempotency.
* Reuse of Phase 5 calculation/configuration, allowlisted public pricing snapshots, unavailable/expired states and exact per-currency totals for priced rows only.
* Explicit approval, acknowledgement for unpriced drafts, transaction-safe Shipping-owned draft creation, progress/cursors, bounded leases/recovery, partial-success preservation and cancellation before commitment.
* Granular server RBAC and token-scope intersections; credential/membership/suspension fences and no platform bypass. No bulk code calls the financial posting engine, carrier gateway, pickup or label service.

See [architecture, limits and operational decisions](architecture/PHASE_8_BULK.md). APIs are specified in OpenAPI; list below.

## Database migration

`2026_10_24_130000_create_bulk_imports` adds bulk_batches, bulk_files, bulk_rows, bulk_mappings, bulk_work, bulk_validations, bulk_decisions, bulk_prices, bulk_approvals, bulk_drafts, bulk_events and bulk_commands. It adds bulk permissions to existing built-in roles without replacing role IDs. Composite foreign keys, one-active-work uniqueness, immutable histories and identity/state guards preserve tenant relationships. Downgrade refuses retained runtime history; destructive rollback is permitted only in the guarded regression database. Previous phase rollback tests explicitly remove the new additive phase before exercising their original preservation assertions.

A full regression run exposed PostgreSQL functions surviving Laravel migrate:fresh. The new function now uses CREATE OR REPLACE, matching earlier module SQL. Repeated fresh-install testing covers this rather than ignoring duplicate-function errors. Native CSV tests also cover a BOM before quoted Arabic headers; race tests refresh PostgreSQL statistics snapshots before asserting actual lock waiters.

## API endpoints

All under `/api/v1/bulk`:

| Method | Path | Capability |
|---|---|---|
| GET | /templates/{locale}/{format} | bulk.read |
| GET / POST | /imports | bulk.read / bulk.upload |
| GET | /imports/{batch} | bulk.read |
| POST | /imports/{batch}/file | bulk.upload |
| POST | /imports/{batch}/mapping | bulk.map |
| GET | /imports/{batch}/rows | bulk.read |
| POST | /imports/{batch}/rows/{row}/decision | bulk.map |
| POST | /imports/{batch}/pricing | bulk.price + rates.quote |
| POST | /imports/{batch}/prepare | bulk.prepare + shipments.write |
| POST | /imports/{batch}/cancel | bulk.cancel |
| GET | /imports/{batch}/progress | bulk.read |
| GET | /imports/{batch}/events | bulk.read |
| GET | /imports/{batch}/errors | bulk.export |

Mutation requests require idempotency_key; existing batches require expected version. Upload is multipart; all other commands are JSON. No merchant_id/actor/grant/storage key is accepted as tenant authority. Errors/source/history are paginated or streamed and never globally scoped.

## Verification

| Check | Executed result |
|---|---|
| Complete backend regression/security/financial/concurrency suite on PostgreSQL + Redis | 261 passed; 2,566 assertions; 168.21 seconds |
| Complete frontend suite | 64 passed, 12 files |
| Full-stack Playwright | 18/18 passed in three complete runs (about 2 minutes each), including the final history-refresh/mobile-navigation regression check; no skipped tests |
| 1,000-row preparation | Passed with chunk size 73, stale-lease recovery and exactly 1,000 draft references |
| Actual PostgreSQL concurrency | Duplicate-worker, cancellation and credential-revocation commit fences passed |
| Spreadsheet security | Traversal/macros/formulas/relationships/entities/ZIP expansion/encoding/cell/row/quoting/tampering cases passed; common theme/internal-target and populated Arabic-template XLSX pipeline passed |
| Migrations | Repeated fresh installation, Phase 8 rollback/reapply and existing Phase 3–7 preservation regressions passed |
| Pint | 217 PHP files passed; the final style-only method-argument issue was formatted with project rules |
| ESLint / TypeScript | Passed; TypeScript also passed after production build |
| Next.js production build | Passed with the development web process stopped to avoid build/cache races |
| Composer validate --strict | Passed |
| Composer / npm dependency audits | No advisories / zero vulnerabilities, including development dependencies |
| Installer integrity tests | 4 passed |
| OpenAPI | YAML parses; local references resolve; 13 bulk paths with explicit session-CSRF security and documented request/response schemas |
| Normal development startup | Restored and healthy; migrations/schema and PostgreSQL/Redis readiness passed; Horizon running |
| Safety spot check in normal development | 16 applied migrations, zero bulk batches and zero ledger journals/holds/submissions/carrier operations; no normal fixture seeding |

The browser journeys use real local HTTP, multipart upload, PostgreSQL, Redis, Horizon and Mailpit. Recovery/concurrency tests use PostgreSQL work records and independent PHP processes; queue-loss republishing is asserted separately from browser execution. Tests use synthetic sources/configuration, never real tariffs/payments or carrier responses. No test timeout/rate-limit assertion was weakened. Synthetic tests never certify live Aramex, payment behavior or a malware scanner integration.

## Limitations and remaining decisions

Production malware scanning, shared private object storage, storage region, retention/deletion/privacy policy, key rotation and operations monitoring/quotas require approval before production processing. Structural inspection in local/testing is not an antivirus claim; production fails closed. The native XLSX reader deliberately rejects unsupported OOXML parts, additional sheets and formulas rather than silently interpreting them. CSV supports UTF-8 only; convert legacy encodings explicitly. Header row is row1; CSV row numbers count logical records, including quoted multiline cells.

No spreadsheet editing, automatic remap of approved batches, silent actor reassignment after revocation or undo/delete of committed shipment drafts. Failed/blocked work/history remains inspectable within authorization; recoverable infrastructure crashes use bounded leases. Original files are retained privately; automatic deletion is deferred pending retention approval, and retained-byte quotas stop growth. Unpriced valid rows may become drafts only with explicit acknowledgement. No preview estimate is a shipment acceptance, carrier availability guarantee or financial reservation. Current currency/COD restrictions remain Phase 7's SAR-only/COD-disabled policy.


## Screens and delivery

English/Arabic bulk dashboard, new import/upload/mapping, validation/source/duplicate review, pricing preview, explicit approval/progress/results, history, audit and error download are implemented across /{locale}/bulk, bulk-import, bulk-results and bulk-history. Public home correctly marks the bulk foundation available while retaining disabled live-shipping/payment notices. Visual inspection confirmed immediate history updates after creation, current batch status, shared authorized navigation context and collapsed mobile navigation after page load. Example browser captures are retained outside Git in `/workspace/shared/clicktoship-phase8/` (synthetic data only).

See [complete changed-file manifest](PHASE_8_FILES.txt). The feature branch will be pushed with a prepared PR targeting main; actual commit/remote/PR outcome is reported at completion. No automatic merge or deployment.
