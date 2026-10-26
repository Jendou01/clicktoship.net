# Phase 16 measured development baseline

Verified main: `47e40dd49e32650b52a552277757fbbd9af4bc3e` (PR15). Baseline **453 backend tests / 5,166 assertions** and **124 frontend tests** before application changes. Source workload definitions and guards are committed; raw output contains aggregate metrics/SQL plans only and remains outside Git.

## Conditions and limitations

Docker 28.4.0 reports 5 CPUs / 35,743,760,384 bytes host memory; current cgroup limits are **4 CPU equivalents** (`400000/100000`) and **32 GiB** (`34359738368`). Node24.19.0; PHP8.3 CLI development runtime, PostgreSQL17, Redis7, Laravel12 and Next.js16. Exact locally observed digest inventory is in security/RUNTIME_IMAGES.json. API is PHP's development HTTP server, not a tuned multi-process production PHP-FPM deployment. Normal development services remained on the host. The final kernel benchmark ran after the full backend suite and browser inspection completed, without another test runner. No production-like traffic distribution, long soak or SLO is claimed.

Kernel samples execute the real Laravel HTTP kernel in-process against real PostgreSQL/Redis, including auth/current authority and queries. They exclude Nginx/TLS/network. Dataset includes 100 merchant shipments, 100 synthetic source orders and separate 1,000-row imports. Common cases have 20 samples, export10 and each bulk format3; nearest-rank p99 often equals maximum. Bcrypt12 is explicitly asserted; no low-cost password benchmark is mislabeled as production authentication. PHP CPU/peak are process-only, not total PostgreSQL/host utilization. No row/credential/body data is included in results.

## Final controlled kernel measurements

| Operation | n | p50 ms | p95 ms | p99 ms | Sequential req/s | SQL min/max | Max payload bytes | PHP peak MiB |
|---|---:|---:|---:|---:|---:|---|---:|---:|
| merchant_switch | 20 | 4.587 | 5.359 | 5.4 | 214.54 | 5/5 | 144 | 48.5 |
| shipment_list | 20 | 7.019 | 7.953 | 8.747 | 140.64 | 8/8 | 6858 | 48.5 |
| tracking_query | 20 | 7.188 | 10.809 | 12.147 | 132.83 | 7/7 | 3271 | 48.5 |
| merchant_dashboard | 20 | 16.506 | 19.733 | 27.138 | 58.06 | 28/28 | 5959 | 48.5 |
| analytics | 20 | 12.928 | 16.573 | 19.478 | 72.83 | 16/16 | 4974 | 50.5 |
| commerce_orders | 20 | 10.631 | 12.509 | 12.744 | 94.83 | 12/12 | 11799 | 50.5 |
| pricing_services | 20 | 6.699 | 9.073 | 10.779 | 139.78 | 6/6 | 453 | 50.5 |
| draft_creation | 20 | 14.829 | 19.694 | 28.922 | 63.8 | 22/22 | 1189 | 50.5 |
| pricing_quote | 20 | 17.925 | 21.433 | 22.285 | 56.32 | 19/19 | 1267 | 50.5 |
| synthetic_export_generation | 10 | 77.469 | 113.853 | 113.853 | 11.93 | 134/134 | 20687 | 50.5 |
| platform_dashboard | 20 | 29.126 | 37.975 | 39.31 | 32.76 | 69/69 | 9375 | 52.5 |
| admin_approvals | 20 | 9.605 | 13.697 | 14.345 | 94.76 | 19/19 | 581 | 52.5 |
| login_bcrypt12 | 20 | 219.468 | 237.377 | 440.61 | 4.31 | 5/5 | 261 | 52.5 |
| invalid_existing_login_bcrypt12 | 20 | 219.301 | 230.723 | 239.352 | 4.54 | 2/2 | 207 | 52.5 |
| unknown_login_bcrypt12 | 20 | 216.206 | 226.403 | 230.891 | 4.59 | 2/2 | 207 | 52.5 |
| bulk_1000_csv | 3 | 1529.643 | 1565.168 | 1565.168 | 0.66 | 2227/2228 | 6232 | 58.5 |
| bulk_1000_xlsx | 3 | 975.514 | 999.534 | 999.534 | 1.06 | 2227/2228 | 6235 | 62.5 |
| customs_readiness | 20 | 9.141 | 13.84 | 17.666 | 96.39 | 14/14 | 5364 | 62.5 |

All measured operations asserted expected responses; errors0. Expected credential rejection benchmarks assert422, not200. Before the auth-work fix, existing/wrong bcrypt12 p50 was216.601ms and unknown-account421.929ms. After shared configured dummy warming before identity lookup, observed219.301ms and216.206ms respectively, with the same generic207-byte failure response. A cold shared cache adds one hash independent of identity (reflected in valid-login maximum); no sleep/throttle reduction or user-specific credential result cache is used. This is not a universal timing-immunity guarantee for legacy hash costs/network/rehash behavior.

Source-backed export measurements use only an explicit test scanner/encrypted test artifacts and measure request + generation + authorized download, not normal artifact enablement or production Horizon queue latency. Bulk SQL count is linear durable row work, not a constant-cost read claim. Spreadsheet samples use supported explicit cell references and bounded incompressible synthetic values; compression/structure guards were preserved. Different intermediate runs under competing browser load showed tail variance; they were not used to claim a latency improvement.

The reproducible shipment-directory defect was **32 queries for 25 rows**, versus **8** after exact tenant/revision batching. Regression proves 1/25 rows have the same query count, precise revision fields, no foreign tenant and no raw payload. This reduces round trips; query count alone does not prove constant execution cost at millions of rows. No speculative index was added: local EXPLAIN showed bounded small-table sort/scan behavior; large-data index/cardinality/legacy offset plans need representative staging evidence.

## Actual loopback HTTP load

88 bearer read/draft requests (including four repeated commands), 168 cookie-session switch/verification requests and 8 CSRF/login setup requests: **264 total**, no errors. Fixed loopback origin, no redirects, 60-second deadline, max concurrency4, four fresh least-privilege synthetic identities and unchanged throttles. Each of 84 merchant switches is checked against the actual current session context. No financial/carrier/provider execution occurs.

| Workload | Concurrency | Requests | p50 ms | p95 ms | p99 ms | req/s | Errors |
|---|---:|---:|---:|---:|---:|---:|---:|
| bearer reads/drafts | 1 | 12 | 36.52 | 91.691 | 91.691 | 23.64 | 0 |
| bearer reads/drafts | 2 | 24 | 66.386 | 88.513 | 90.13 | 28.5 | 0 |
| bearer reads/drafts | 4 | 48 | 133.244 | 186.163 | 190.522 | 27.95 | 0 |
| cookie switch + current context | 1 | 24 | 26.201 | 29.52 | 35.658 | 37.56 | 0 |
| cookie switch + current context | 2 | 48 | 47.594 | 64.107 | 84.768 | 39.97 | 0 |
| cookie switch + current context | 4 | 96 | 94.052 | 107.819 | 121.855 | 41.61 | 0 |

Concurrent duplicate draft unique IDs: **1**, as required. Bearer payload ceiling observed 5015 bytes. Queue-wait/processing is measured separately by an encrypted real Redis job in another worker: final full run 146.542 ms wait (includes worker startup), 4.046 ms processing, 30.0 MiB PHP peak. These are one-job observations, not steady-state queue percentiles or a reserved critical-capacity guarantee.

## Recovery measurement

Final local rehearsal: 137 tables / 2658 rows, 875321 backup bytes, matching SHA-256 row fingerprints, 10 decrypt checks and wrong-key rejection, balanced immutable journal, synthetic object hash/key roundtrip, killed transaction rollback and private Redis/PG readiness503→200 with unchanged durable state. Restore+verify 5.894 seconds; whole rehearsal 55.622 seconds. This is small disposable evidence, not a production RTO/RPO assertion. See operations/BACKUP_RESTORE.md.

## Capacity and deferred evidence

A captured concurrent-regression Docker sample observed the API test process around110.7 MiB and24.17% CPU, API65.05 MiB, Horizon93.52 MiB and scheduler30.69 MiB; this is a point sample, not measured sustained maxima or a production sizing recommendation. Measured PHP peaks/CPU in kernel output cover only the benchmark process. HTTP server concurrency, same-merchant serialization and host contention explain increasing latency; no benchmark request error or duplicate business effect was hidden.

Production-like FPM/web artifacts, many-tenant/large-data contention, sustained queue saturation/fairness, worker CPU/I/O/limits, image vulnerability/provenance scanning, retained monitoring and soak/chaos require a separately approved staging procedure. Two development Horizon workers are not independent guaranteed capacity pools. No current result establishes SLA readiness.
