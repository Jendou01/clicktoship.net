# Phase 15 metric dictionary — recorded before implementation

PostgreSQL module records remain authoritative. Reporting owns only export orchestration/audit, never a ledger, tracking projection, order store or pricing source. All period windows are UTC start-inclusive/end-exclusive, at most366 inclusive calendar days; today/yesterday/7/30/90/custom. Weekly buckets start Monday, monthly buckets on the first. Current stocks/balances are explicitly independent of event periods. No shared response cache. Every request/job/download rechecks current authority.

| KPI/report | Source and calculation | Date, ownership, permission, evidence and limits |
|---|---|---|
| Shipment creations/drafts/current status | shipments count, state groups; drafts=current draft within creation cohort | created_at UTC; merchant shipments.read; recorded local state, not confirmed delivery |
| Recorded submissions | shipment_submissions count | created_at; merchant shipments.read; immutable submission intent, not carrier acceptance |
| Carrier submission attempts | carrier_attempts joined carrier_operations and shipment_submissions by exact operation/merchant | attempt.created_at; shipments.read; recorded attempts/outcome, no assumed execution |
| Verified acceptance | exact shipment/decisive shipping_observation, accepted outcome and verified provenance | observation.created_at; shipments.read; synthetic excluded; current schema cannot produce verified acceptance |
| Verified delivery | existing exact tracking binding/projection/detail join; binding and detail verified and detail validation verified | occurred_at; tracking.read; synthetic/unknown excluded, no inferred delivery |
| Unknown/financial exceptions | shipments unknown/blocked/exception_code or pending/review financial_state | current stock, no historical inference; shipments.read |
| Bulk performance | batches by state, actual row_count and durable results | batch.created_at; bulk.read; parsing total can be unknown, no rate inferred from missing timestamps |
| Quotes/expiry/selling amounts | shipping_quotes public_snapshot approved total_minor/selling_minor/discount_minor, currency; expires_at | quote.created_at; rates.read; estimates only, no costs/private_snapshot/profit/revenue |
| Wallet balances | existing wallet_balances + financial_currencies; available=posted-reserved exact integers | CURRENT as_of only; wallet.read; separate currency/exponent; no historical balance or funding-as-revenue |
| Ledger movements | wallet_transactions + immutable ledger/operation kind, currency/exponent | transaction.created_at; wallet.transactions.read; exact decimal strings; synthetic category remains explicitly synthetic |
| Reservation activity | balance_hold_events kind/amount/currency, existing hold identity | event.created_at; wallet.holds.read; expiry never implies release; unknown outcomes stay protected |
| Reconciliation | financial_reconciliation_runs match/cutoff state | run.created_at; wallet.read; no reconciliation action or monetary correction |
| Customs completeness | declarations joined current shipment revision and latest matching customs_readiness | declaration.created_at cohort; customs.read; null=not assessed, local_valid is not clearance |
| Imported orders/conversion | commerce_orders joined permanent connections and optional immutable draft link | order.created_at import time (not external sale time); orders.read; distinct source identities; linked count/total exact fraction, synthetic provenance explicit |
| Integration readiness | commerce_connections provider/provenance/state and commerce_events; failed preparation results | current connection stocks, events.created_at for activity; integrations.read; both providers unavailable; duplicate suppression has no complete authoritative counter, unavailable |
| Merchant activity | audit_logs merchant-bound action/id/time only | audit.created_at; merchant.view; no metadata or customer PII |
| Platform growth/activation/verification | existing Operations Metrics/Approvals plus merchant audit actions and verification decisions | registration/decision/audit dates vs current status clearly separated; existing platform.overview + per-source permission + recent MFA; counts only |
| Platform finance/cost boundaries | existing Operations count-only currency/exponent and reconciliation/approval queues | platform.metrics.finance + recent MFA; no global monetary policy, costs/invoice/P&L/revenue unavailable; exact private views use existing independently approved target routes |

Unavailable: realized revenue/profit, verified carrier costs/margins, cross-currency totals/FX, historical balances, country/service shipment dimensions without an approved queryable projection, unsupported live carrier/provider performance and full duplicate suppression counts. No financial/carrier/provider/notification side effects. Insights are deterministic threshold observations with explicit periods, never instructions to mutate business state.


## Platform count definitions and periods

All following measures require `platform.analytics.read`, `platform.overview`, the matching `platform.metrics.<section>` and current browser/recent MFA. No private merchant IDs, addresses, credentials or monetary balances are exposed. Existing Operations owns these definitions; Analytics reuses them.

| Section | Authoritative calculation | Period versus stock / evidence |
|---|---|---|
| merchants | COUNT merchants; status and verification_status groups; creation trend; decision outcome counts; audit `merchant.status_changed` destination groups and destination=active trend | registrations: merchants.created_at; verification: decision.created_at; changes/reactivations: audit.created_at. Current status groups are stocks, not historical state |
| users | COUNT users, suspended_at nonnull, platform_role_assignments | current stocks; no personal identity/contact fields |
| shipping | creation and immutable submission counts/trends, current states/exceptions/pending settlement/documents/pickup exceptions | creations use shipment.created_at; submissions use submission.created_at; decisive accepted observation requires exact merchant binding and verified provenance; other queues are current |
| bulk | grouped batches and creation trend; failed batches, incomplete work, expired/stale work candidates | cohort=batch.created_at; recovery/failed queues current, no fabricated throughput/completion duration |
| tracking | work/webhook states, exact binding/projection/detail verified statuses, stale verified projections, unknown codes, unresolved cases, dead-letter/quarantined work, case trend | statuses use occurred_at with both binding/detail verified and detail validation verified; cases.created_at trend; work/queues current. All merchant reuse is scoped at every source |
| carriers | account/environment/configured/disabled counts; operation state groups; existing capability evidence matrix | operations.created_at cohort, account configuration stock. No live response compatibility claim |
| pricing | global definition kind/enabled/pending counts | current configuration coverage, excludes merchant-private definitions and cost payloads; no rate execution |
| finance | wallet and operation counts per currency/exponent, latest mismatching reconciliation runs, live pending financial approvals, pending shipment settlement | latest/current stocks only; no global private amounts, cross-currency sums, revenue or P&L |
| customs | country-rule and pending-review counts; work states; current quarantine; latest local incomplete result at exact current shipment revision | current queues; incomplete means saved report.local_valid=false, not legal customs clearance |
| commerce | provider/provenance-specific authorized/synthetic/reauthorization counts; durable run state/kind counts, source order and review counts, immutable draft links and quarantined receipts | current source counts, not external sales or real provider performance. Both provider activation gates remain closed |
| approvals | existing Approvals current permission-filtered independent review workload | current pending workload; no authority to approve or access private targets is added |

Weekly/monthly trends aggregate source day counts without changing the filtered period. A bucket label can precede the period start (Monday/first-of-month), while only events inside the selected UTC window are counted. No comparison-based growth alert is inferred; deterministic insights report counts at threshold ≥1 with event-period/current-stock/cohort semantics and `action_enabled=false`. Unknown unsupported metrics are explicitly unavailable.
