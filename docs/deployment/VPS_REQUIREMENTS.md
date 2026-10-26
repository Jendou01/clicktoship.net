# Initial VPS requirements and provider evaluation

Planning estimates, **not measured production capacity or a purchase recommendation**. No server/provider has been selected or authorized. Phase16 development benchmarks cannot size live business traffic.

| Resource | Minimum for a protected small synthetic staging | Recommended initial dedicated staging |
|---|---|---|
| CPU | 4 modern vCPU with measured steal time | 4–6 vCPU; build elsewhere |
| RAM | 8 GiB plus monitored emergency swap; never rely on swap for normal DB load | 12–16 GiB for measured headroom |
| Disk | 80 GiB SSD, encrypted host filesystem, ≥30% free | 160 GiB NVMe with growth monitoring |
| PostgreSQL | 1.5 GiB container budget initially; bounded pool/FPM four children | Tune shared buffers/connections from representative measurements |
| Redis | 256 MiB maxmemory /384 MiB container, noeviction | Alert at70%; reject excess work rather than drop durable authority |
| PHP / workers | FPM four children; separate critical1/background1 workers, 60s timeout<90s retry | Bound per-source backlog; tune only after authority/race/load tests |
| Next | 768 MiB standalone runtime | Monitor RSS/restarts; no builds on host |
| Backups | Independent encrypted off-host destination plus key escrow | Versioning/immutability, tested restore; estimate from actual retained data |
| Temporary objects | bounded per-task sizes and filesystem quotas | Reserve ≥10GiB; normal documents/exports remain disabled |
| Network | protected TLS gateway and controlled egress only | Measure bandwidth/latency/transfer billing; no fabricated traffic forecast |

Compare current Hostinger and Contabo proposals using documented dated quotes: region/residency and DPA, dedicated versus shared CPU/steal, usable storage/IOPS, backup/PITR terms, rescue console, network/anti-DDoS/firewall, support response, SLA exclusions, restore tests, account MFA, renewal/transfer costs and cancellation. Prices/features have not been verified; neither vendor is endorsed as compliant.

Scale/review when sustained CPU>70%/steal>5%, memory>75%/OOM, disk>70% or projected<30days, Redis>70%, critical queue wait>30s, readiness errors or p95 exceeds an approved SLO. Thresholds are proposals requiring named owners. Separate DB/worker hosts before increasing concurrency beyond tested lock/resource budgets. Single VPS is a failure domain, not HA; managed backups/offsite alone do not remove it.

Verify a supported Docker storage driver (normally overlay2 on a compatible Linux filesystem) and physical free-space accounting. This cloud uses VFS, which duplicates image layers per container; logical image/cache sizes understate disk requirements. It caused a real local capacity failure and is not a validated VPS storage design.
