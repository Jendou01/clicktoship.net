# Approved reports and export contract

Merchant catalogue: shipment_summary, shipment_history, submission_outcomes, submission_attempts, tracking_exceptions, bulk_summary, wallet_transactions, wallet_reservations, financial_reconciliation, quote_summary, customs_readiness, connected_orders, draft_conversion, connection_states, commerce_activity, merchant_activity. Each definition contains an explicit source permission, table/date, allowlisted columns and applicable filters; API exposes only authorized definitions. Platform reports reuse existing Operations per-source aggregate definitions; no private merchant IDs or PII rows.

Read pages:25 rows plus one lookahead, UUID keyset, at most366 UTC dates. Row APIs are live observations, not frozen cross-page snapshots. Exports: one bounded generation snapshot, at most1,000 rows and2MiB; over-limit fails rather than truncates. Formats CSV UTF-8 BOM and XLSX with explicit inline strings, currency/exponent/minor-unit strings. Plain signed numeric monetary cells retain their accounting value; dangerous text beginning with formula triggers after whitespace/control characters is escaped for CSV. XLSX contains no formula/macros/external relationships. No arbitrary columns, SQL or user filenames.

Exports require analytics.export plus analytics.read and all current report source permissions; originating token abilities intersect current membership. Financial exports additionally require a browser session and recent confirmed MFA. Platform exports are aggregate-only, browser/recent-MFA/current platform source permissions. Same actor/scope/merchant only for history/download. IDs are not download authority.

Lifecycle requested→authorized→queued→processing→ready, with failed/revoked/expired terminal states and append-only events. Hashed request keys/digests prevent conflicting retries. Global/merchant/actor backpressure, leased work, bounded attempts, origin rechecks before/after generation and expiry cleanup prevent stale or duplicated artifacts. PostgreSQL is durable authority; encrypted Horizon jobs notify existing bulk queue. Artifact paths stay private and opaque; ciphertext is stored at rest. No direct storage URLs. Download reauthorizes current source permissions, origin/session/token, MFA when required, owner and15-minute expiry, then rechecks size/digest. Artifact cleanup deletes only known reporting-owned objects; immutable audit metadata is retained.

Runtime downloads are CLOSED outside isolated testing until separately approved private storage/retention/scanning safeguards exist. A scanner must return clean even in testing. Missing safeguards create a sanitized failed request with no artifact, never a public URL. Test-only authorization/scanner adapters do not certify production safety. Export history may be inspected while downloads are disabled. No external notifications.

## Exact approved merchant columns and source grants

All row dates below are source `created_at` in UTC. Status filters select the indicated report's existing group field (state/kind/reason/action), never a caller-provided SQL expression. Provider applies only to Commerce; currency only to money/quote reports; service only to the persisted public quote service UUID. All reports additionally accept the bounded UTC period and day/week/month grain; row API keysets accept `after`. Filters not applicable to the definition are rejected. Exports cannot accept a row cursor, tenant override, arbitrary columns or filenames.

| Report | Source grant | Approved columns | Applicable dimensions |
|---|---|---|---|
| `shipment_summary` | `shipments.read` | `id`, `state`, `financial_state`, `document_state`, `created_at` | status |
| `shipment_history` | `shipments.read` | `id`, `shipment_id`, `kind`, `created_at` | status |
| `submission_outcomes` | `shipments.read` | `id`, `shipment_id`, `created_at` | Period/grain only |
| `submission_attempts` | `shipments.read` | `id`, `outcome`, `created_at` | status |
| `tracking_exceptions` | `tracking.read` | `id`, `reason`, `created_at` | status |
| `bulk_summary` | `bulk.read` | `id`, `state`, `row_count`, `created_at` | status |
| `wallet_transactions` | `wallet.transactions.read` | `id`, `sequence`, `kind`, `delta`, `posted_after`, `reserved_after`, `currency`, `exponent`, `created_at` | status, currency |
| `wallet_reservations` | `wallet.holds.read` | `id`, `kind`, `amount`, `currency`, `exponent`, `created_at` | status, currency |
| `financial_reconciliation` | `wallet.read` | `id`, `matches`, `currency`, `exponent`, `cutoff`, `created_at` | currency |
| `quote_summary` | `rates.read` | `id`, `currency`, `exponent`, `total_minor`, `selling_minor`, `discount_minor`, `expires_at`, `service_id`, `created_at` | currency, service |
| `customs_readiness` | `customs.read` | `id`, `local_valid`, `created_at` | Period/grain only |
| `connected_orders` | `orders.read` | `id`, `provider`, `provenance`, `state`, `review_required`, `shipment_id`, `created_at` | provider, status |
| `draft_conversion` | `orders.read` | `id`, `provider`, `provenance`, `state`, `review_required`, `shipment_id`, `created_at` | provider, status |
| `connection_states` | `integrations.read` | `id`, `provider`, `provenance`, `state`, `created_at` | provider, status |
| `commerce_activity` | `integrations.read` | `id`, `provider`, `kind`, `created_at` | provider, status |
| `merchant_activity` | `merchant.view` | `id`, `action`, `created_at` | status |


Merchant exports require `analytics.read`, `analytics.export` and the exact source grant above, all currently intersected with token abilities. Wallet transactions/reservation/reconciliation exports require browser/recent MFA and reject bearer origins. Platform reports are `merchants`, `users`, `shipping`, `bulk`, `tracking`, `carriers`, `pricing`, `finance`, `customs`, `commerce`; each requires `platform.analytics.read`, `platform.overview`, recent MFA and `platform.metrics.<section>`. Platform export adds `platform.analytics.export` and emits only flattened `metric,value` aggregate cells, not a private merchant report.

Money/sequence/cutoff cells are exact integer strings. Currency and precision are explicit; no date-range filter turns current balances into as-of history. CSV bytes preserve exact signed integer accounting values; spreadsheets may auto-format CSV on opening, so import exact integer columns as text. XLSX deliberately uses inline strings to preserve precision and prevent formula execution. Headers are stable approved machine names; Arabic/English Unicode values are preserved and Arabic XLSX sheet direction is RTL. UTC source timestamps are not converted to local dates.

Normal artifact policy is closed for every report. API format support does not imply normal downloads are enabled. Replayed request keys return the original identity and frozen request filters, never a new generation. Source rows are read at generation within a PostgreSQL repeatable-read transaction; permission/origin is rechecked before publication and download. Platform read widgets are live observations and are not an atomic accounting statement across modules. Current row pages may change between page requests.

Limits: 25 rows/page; 366 inclusive UTC dates; 1,000 export rows; 2 MiB plaintext; encrypted artifact read ceiling 4 MiB; expiry 15 minutes; at most 3 active requests per actor and per merchant; 100 unexpired active/ready artifacts globally. Processing limits are 4 global, 1 per actor and 1 per merchant, 45-second job timeout/60-second lease, at most 3 claims. Recovery scans at most 100 active plus 100 expired records per minute; it revisits all bounded recorded staging paths after expiry. Immutable audit metadata is retained; its future retention policy requires approval.
