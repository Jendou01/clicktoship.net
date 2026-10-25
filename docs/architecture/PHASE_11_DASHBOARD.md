# Phase 11 — Merchant dashboard and unified experience

MerchantDashboard is a read composition over existing module-owned records. It introduces no shipment, tracking, customs, notification or financial state machine, no second ledger and no write endpoint. Existing Identity/Merchants resolve session selection or the token's bound merchant. Platform roles/grants do not establish merchant dashboard membership. Development carrier/payment/funding/charges/clearance gates remain closed.

## Authority and consistency

GET `/api/v1/dashboard` and GET `/api/v1/dashboard/activity` require an active email-verified identity, current merchant membership and `merchant.view`, intersected with token scopes. Each source additionally requires its existing module read permission. Unauthorized sections are omitted and their data tables are not queried. ShippingAccess rechecks user, original credential and context after locking merchant then user in the existing mutation order. Concurrent revocation/suspension that wins the root lock denies a waiting report. Reports do not take locks over network work. The API intentionally uses no Redis/shared response cache; responses are private/no-store and vary by authentication/context headers. This avoids revocation-sensitive cache invalidation and cross-role/context cache keys.

Locking follows existing coarse merchant consistency boundaries; it is not a production throughput claim. Each subsequent SQL statement has a five-second PostgreSQL statement deadline. Output and date ranges are bounded; query-count tests exercise growing datasets and prove no per-row query loop. Production load/index/lock-contention measurements and independent operational signoff remain future readiness work.

## Time and KPI definitions

All dates are explicit UTC calendar dates. Today/7/30/90 days include today; custom dates are inclusive, at most 366 days, and cannot end in the future. SQL filters use an inclusive UTC start and exclusive next-day end, so midnight records are counted exactly once. These are operational counts, not accounting-period reports or Saudi local business-day claims. Wallet balances are current, independent of the report date filter, with an as-of timestamp.

| Widget | Authoritative source and period meaning | Additional permission |
|---|---|---|
| Shipments created / drafts | `shipments.created_at`, current recorded state of that cohort | shipments.read |
| Submitted | Immutable `shipment_submissions.created_at`; no dispatch inferred from queued state | shipments.read |
| Carrier acceptance | Decisive `shipping_observations` with verified provenance and accepted outcome, current accepted shipment; observation date | shipments.read |
| Exceptions | Created shipment cohort with exception_code, unknown/blocked state or capture_pending/release_pending/review financial dimension | shipments.read |
| Daily activity / state distribution | UTC creation-day counts (zero-filled), current recorded state distribution for the same cohort | shipments.read |
| Verified tracking | Existing projection joined to exact merchant/account/operation binding and event detail; binding and detail provenance verified, detail validation verified, status known; confirmed event date | tracking.read |
| Wallet | Existing wallets/balance projections and explicit currency precision; posted/reserved/available minor-unit strings, never combined across currencies | wallet.read |
| Bulk | Batches created in period, latest six; current phase and actual source-row count through its durable cursor, prepared draft references and honest unknown parsing total | bulk.read |
| Pricing | Existing quotes created in period and time-unexpired subset, no amounts/cost/margin/private snapshot; route-dependent availability remains unknown until calculator inputs | rates.read |
| Customs | Declarations created in period; latest persisted readiness bound to CURRENT shipment revision, locally complete/not assessed; no clearance claim | customs.read |
| Recent activity | Permission-filtered shipment event, batch creation/current state, quote creation and customs event metadata; identifiers/codes/timestamps only | Per-source permission above |

Current shipping observations allow only synthetic test provenance. Therefore the verified carrier-acceptance KPI remains zero in the current implementation, including after a synthetic accepted orchestration test. Synthetic/unverified tracking is likewise excluded. Recorded accepted state may appear in its separately labeled state distribution, never as a confirmed carrier fact. Missing customs readiness is not success. An unexpired estimate is not a guarantee that current tariff/service/route policy still permits acceptance.

## Bounded reads and schema

The only migration is `2026_10_25_080000_add_dashboard_read_indexes`: six non-unique merchant/time/latest-readiness indexes. No tables, new business records, roles or permissions. Down drops those indexes only; prior state/history remain. Schema integrity independently checks valid/ready read indexes after the migration. Existing prior-phase rollback tests first remove the additive index migration, retaining their original preservation assertions.

Aggregates execute in PostgreSQL, without fetching/decrypting shipment PII or pricing snapshots. Group keys are constrained existing states. Trends return at most 366 days; activity returns 20 items plus one lookahead with descending timestamp/UUID/module keyset, recent imports six, wallet currency rows 100. No offsets/unbounded requested page sizes. Activity filtering is an allowlisted module or exact subject UUID; invalid cursors/dates fail validation, SQL values are bound. Pagination always reapplies tenant/permission/date restrictions. It does not promise a frozen multi-request snapshot of mutable batch states.

## Frontend

The authenticated `/en/dashboard` and `/ar/dashboard` routes render current authorized data, date filters, exact per-currency balances, KPI cards, daily creation chart with accessible tabular data, state distribution, real bulk phase progress, readiness and recent activity. Empty/unavailable states are explicit; no fabricated revenue, funding or live tracking. The previous welcome text remains as a secondary heading for compatibility, while the dashboard becomes the operational entry point.

Reusable page headers, panels, KPI cards, tables, badges, empty states and notices retain the ClickToShip teal identity. Existing Shipping, Pricing, Bulk, Tracking, Wallet and Customs use the same page header; shared navigation provides authorized primary workflows, current merchant indicator, organization switching, page search, active state, separate platform section and mobile disclosure. Quick actions have distinct accessible names to avoid ambiguous duplicate navigation controls. Language switching allowlists date filters or existing public shipment/batch identity only, not tokens/grants/PII; selected merchant remains server-controlled. Currency, identifiers and dates use direction isolation.

Client requests do not retain a shared dashboard cache. Refresh clears previous data before a new request; obsolete asynchronous results are fenced. A failed/revoked request never redisplays previous merchant KPIs. An unauthenticated user goes to login and a missing context goes to organization selection. All financial/carrier/private data permissions remain enforced by the server, independently of visible links.

## Gates and next work

UTC reporting follows the documented data default; configurable local reporting timezone and official accounting periods require separate policy. No production load/SLA or external provider certification is claimed. Country/carrier/contract, storage/scanning/retention/privacy, accounting/regulatory and real-money launch gates from Phases 4–10 remain unresolved and unchanged. No external preview/forward/tunnel/VPS/production deployment or Phase 12 work is part of Phase 11.
