# Phase 14 — provider and instance isolation

Reuse Commerce connections, encrypted tokens/snapshots, bounded durable runs, immutable source-to-draft links and Shipping.importDraft. The provider registry selects adapters from persisted connection identity; request fields cannot select an adapter for an existing connection. Salla's existing binding and routes remain compatible. Shopyzee has a distinct disabled adapter; no unverified authorization mode is selected.

An additive migration extends source identity to (provider, external_platform_id, external_store_id), includes the instance in the immutable ownership trigger, and scopes authorization intents by provider. Domains are display only. Existing Salla rows retain their internal `salla` instance namespace. History is not moved or erased on disconnect. Downgrade refuses retained Shopyzee connections/intents or presentation metadata instead of deleting data.

Sync pages, refresh results and authenticated webhook signals must match the persisted provider/instance/store. Existing merchant locks, origin auth-version and session/token checks, generation fences, rotating refresh leases and post-I/O checks remain authoritative. No SQL lock spans network I/O. Source conflicts block new drafts and preserve existing drafts. No shipping submit, ledger posting, COD settlement, external notifications or fulfillment writes are introduced.

Legacy orders API defaults to Salla for compatibility; the unified UI explicitly requests `provider=all` and displays each row's persisted source. All queries retain merchant scope. Platform visibility is aggregate only. See [the evidence matrix](../integrations/SHOPYZEE_EVIDENCE.md) for unresolved external evidence and future write-back gates.

## Authority and operating limits

Existing built-in permissions remain: Owner/Admin manage connections; Owner/Admin/Shipping Manager may synchronize; Owner/Admin/Shipping Manager/Shipping Operator may prepare orders only with current `orders.read`, `orders.prepare` and `shipments.write`. Viewer reads only. API tokens intersect current role and abilities. Every background step rechecks its originating session/token, membership, auth version and merchant status; a provider credential does not grant standing merchant authority. Super Admin has no automatic private store access. Platform Commerce counts require the existing browser/recent-MFA/source permission controls, and grant no customer-order authority.

Existing limits remain 50 source orders/page,512KiB normalized page,10 pages/500 rows per automatic segment, explicit bounded continuation up to1,000 source orders per run,25 orders/preparation batch and bounded directory keysets. Cursor/checkpoint values remain opaque and encrypted; internal fixtures assert no real upstream ordering or cursor guarantee. Retry-After is respected; refresh ambiguity and disconnected generations do not silently resume. No timeout or retry relaxation is introduced.

## Internal normalization, not external field mapping

| Internal value | Handling | Actual Shopyzee mapping |
|---|---|---|
| provider/platform/store | Exact permanent connection proof; domain excluded | Unverified |
| order ID/reference | Bounded identity and encrypted reference with blind exact search | Unverified |
| created/updated timestamp | UTC normalized; older/equal/unknown conflicts retained for review | Unverified |
| payment/fulfillment/source status | Explicit normalized enum or unknown; no guessed payment confirmation | Unverified |
| currency/amount/COD | Explicit ISO currency and integer minor-unit strings or null; no FX or assumed zero | Unverified |
| recipient | Minimized bounded name/phone/country/city/region/address/postal/email where supplied | Unverified |
| items | Bounded description/SKU/quantity/value/weight/origin/HS data where supplied | Unverified |
| origin/packages/service/customs | Existing explicit shipping review and validation; nothing fabricated | Unverified |

No provider fields/endpoints/weight units/payment enums/signatures are inferred by this table. The normalized model and source-to-draft behavior are independently verified with isolated synthetic fixtures only.

## Aggregate monitoring definitions

These are retained/current-state counts, independent of the dashboard date period (not revenue or period activity). Verified stores require both authorized state and verified provenance; synthetic stores include retained test sources. Disconnected and reauthorization counts reflect current connection state. Sync failures count failed/blocked runs. Draft failures count immutable failed preparation rows, including partial completed batches. Orders count retained source identities; review count reflects current review flags. No private identifier or monetary value is selected. Six grouped/aggregate queries serve the Commerce widget without per-merchant/provider loops over SQL.
