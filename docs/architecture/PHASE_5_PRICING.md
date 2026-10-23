# Phase 5 — versioned static shipping estimates

## Scope and activation gates

This module implements internal, configured estimates. It makes **no carrier HTTP requests**, asserts no live availability, creates no shipment, and writes no wallet/ledger/outbox entry. The only runtime `CarrierRates` binding is `StaticCarrierRates`; a future verified adapter can supply carrier configuration/cost without controlling selling rules or finalization. There is no Aramex default tariff, 14 SAR seed, universal route, tax percentage, FX conversion or presumed contract entitlement. Normal migrations create **zero pricing definitions**. Explicitly synthetic data is limited to guarded PostgreSQL test fixtures.

The owner must supply verified service/account/route/weight terms, volumetric divisor and rounding, remote-area city classification, fees, currencies and effective windows. Independently controlled platform personnel publish them. A source/evidence reference is mandatory, but software approval is not an accountant's, tax authority's, regulator's or carrier's certification. Tax exemption is also an explicit, evidenced policy, never a fallback.

## Owning module and schema

`App\Modules\Pricing` separates HTTP authorization, catalog lifecycle, immutable quotes, a pure integer calculator, and the carrier-cost source interface. It reuses the existing `WalletBilling\Domain\Money` bound and immutable `financial_currencies` catalog rather than introducing a ledger. SAR (exponent 2) is the only enabled quote currency. Every selected monetary version must match it; no implicit FX.

Two additive migrations create:

* `pricing_definitions`: UUID identity, stable code, kind, optional merchant ownership, optimistic draft version and publication `state_version`, current active version and explicit enablement.
* `pricing_versions`: append-only configuration, source reference, author, currency, effective interval. Shared versioning avoids duplicating eight nearly identical history tables. Closed application schemas define service, zone, tier, assignment, tariff, selling rule, fee and tax variants. Generated relational selectors provide real UUID FKs, integer weight/package bands and indexed lookups. Database triggers verify target kinds/global ownership, monetary bounds, tenant/scope consistency and publication conflicts.
* `pricing_events`: append-only draft/publication/disable events with actor, originating session, access grant, definition and version.
* `shipping_quotes`: immutable public DTO plus encrypted confidential calculation snapshot, actor/merchant, parent/revision, creation and expiry. Composite parent FK prevents cross-merchant quote ancestry.
* `pricing_quote_requests`: permanent merchant + actor + SHA-256 request-key identity, canonical payload digest, composite merchant/quote FK. The digest includes recalculation parent. These records do not expire when a quote expires.

Effective intervals and weight bands are half-open `[from,to)`; package-count bands are inclusive. An adjacent weight band may start at the preceding upper boundary. Version, quote, request and audit history reject SQL UPDATE/DELETE. Definition identity is immutable. Publication has a deferred database conflict guard and an application check under a transaction-wide advisory lock. FKs are deferred where the header and first revision are created atomically.

Downgrades with retained pricing history are refused outside the strictly named disposable `testing/clicktoship_test` database. Use forward migrations for real retained history. Empty additive rollback/reapply preserves Phase 0–4 entities. Existing Phase 3/4 rollback tests first remove the two additive pricing migrations and retain their original preservation assertions.

## Verified configuration and deterministic selection

Each service explicitly configures carrier code, bilingual name, divisor in cm³/kg, weight step in grams, aggregate/per-package rounding mode, quote TTL (30–3600 seconds), monetary rounding (`half_up` or `up`), and COD/remote/declared-value capability flags. Distinct service identities support additional carriers. Capabilities do not claim live account permission.

City identifiers normalize to trimmed lowercase Unicode. Both origin and destination must resolve to exactly one current approved country/city zone. Unknown/overlapping cities fail closed; absence of a zone cannot bypass a remote surcharge. Tariffs require explicit origin and destination countries and a matching service, optional zones, lane, weight and package band. Unsupported international routes remain unavailable.

Selling precedence is **merchant > assigned tier > carrier > global**. Every rule references a service, including global defaults: global describes ownership/precedence, never universal carrier availability. Within one scope, specificity counts non-null country/zone/lane restrictions and restricted weight/package bands. Identical-rank compatible overlapping selectors are rejected at publication and again at calculation. Tariffs cannot overlap at all; fee code/side bands cannot overlap. One current assignment per merchant; city zones cannot overlap in a country/effective interval. A disabled/expired tier assignment falls back to the remaining explicit selling scopes. No cheapest-price or UUID tie breaker exists.

References may be prepared as drafts in any order; only currently effective, enabled referenced versions can calculate a quote. Unknown capabilities, tariff, fee policy for a requested additional service, tax policy/jurisdiction, currency, conflicting configuration or insufficient margin return a generic merchant validation failure without cost diagnostics.

## Arithmetic

All money is a canonical nonnegative minor-unit string at API boundaries and a bounded integer internally (existing maximum 1,000,000,000,000). Package weights and millimeter dimensions are bounded integers; no monetary float operation exists. Overflowing step costs are rejected before multiplication.

For each package:

`volumetric_grams = ceil(length_mm × width_mm × height_mm / configured_divisor)`

`unrounded_billable = max(actual_grams, volumetric_grams)`

Aggregate mode sums package maxima, then rounds up to the configured gram step. Per-package mode rounds each package maximum before summation. This explicit choice is preserved in the snapshot; aggregate does not compare total actual with total volume. Billable weight above the supported bound fails closed.

Tariff base cost = base + fixed component + rounded percentage of base + ceiling additional-weight steps × configured step price. Matching carrier fees add to cost. A selling rule starts from either fixed base or full carrier cost, adds fixed/percentage markup and weight steps, then deducts fixed + percentage discount. Discounts above selling price fail; no silent clamping. An unused base amount in markup mode has no effect and should be configured as zero.

Fee policies explicitly declare cost/selling side, fixed amount, percentage basis (base tariff, discounted selling price, COD amount or declared value) and per-package application. Carrier fees cannot depend on selling price, preventing circular calculations. There is one fee per code/side/matching band; optional generic service fee, COD, remote and declared-value fee classes are supported. Per-package percentage fees multiply the configured quote-level basis per package; administrators must verify that this matches contract terms. Unsupported compound carrier charge/tax schemes require a future approved schema extension, not invented defaults.

Pretax merchant total = discounted selling price + merchant fees. Estimated gross margin = pretax total − carrier cost including carrier fees; tax is excluded. Enforce both minimum minor-unit margin and minimum margin/pretax-sale basis points. This is a pricing safeguard, **not approved revenue recognition or a posted accounting margin**.

Tax is a referenced, verified origin- or destination-country policy: explicit exemption (zero basis points) or exclusive tax, applied to the full pretax merchant amount. Complex inclusive, reverse-charge, multiple jurisdiction, withholding and exemption-evidence decisions remain blocked pending qualified advice. Rounding happens at each documented percentage line using the service policy, never via display decimals.

## Quotes and isolation

Merchant APIs resolve authenticated active merchant context; supplied `merchant_id`, prices and unsupported top-level inputs are rejected. Built-in merchant roles receive `rates.read`/`rates.quote`; scoped API tokens must also have those abilities. Merchants cannot modify tariffs or rules. Quote creation takes shared pricing lock, merchant root lock, user lock, validates current session/token and re-establishes tenant context/permissions before writing. This shares existing membership, suspension and credential-revocation serialization.

A quote's public whitelist contains only service names/identifiers, estimated/non-live status, normalized inputs, selling/discount/merchant fee/tax totals, integer weights, currency, parent/revision, opaque HMAC pricing version, creation and expiry. Private encrypted snapshots retain full selected versions, rules, inputs, cost, margin, divisor and rounding assumptions. Never serialize a catalog row into merchant responses. Lists, detail, validation and recalculation are merchant-scoped; UUID guessing is not authorization.

Expiry is the earlier of service TTL and **every selected definition's** effective end. Historical retrieval returns the same immutable price after updates or expiry. Validation checks expiry and explicit service disablement; it never authorizes billing. Replay with the same key/input returns the original quote, including original expiry; changed payload or recalculation parent conflicts. Recalculation uses a new key and current configuration, retaining its parent. Multiple recalculations can branch from one historical quote; revision denotes ancestry depth, not a mutable merchant counter. Future shipment orchestration must recheck current authority, expiry and approved acceptance rules before any wallet reservation; no Phase 5 billing consumption endpoint exists.

Platform permissions are `platform.pricing.view`, `.manage`, `.approve`, initially only Super Admin and Platform Finance. All administrative reads/writes require a current browser session and recent MFA. Writes serialize with global security changes and exclusive pricing lock; draft and publication optimistic counters reject stale state. Publication/disable require a different person from the latest version author and revalidate that author's current role, active session, credential/MFA version and recent challenge. Merchant-specific definitions and confidential quote reads require exact merchant/capability/session-bound independently approved access grants; maker and publisher each need their own grant. An administrator with a merchant membership cannot manage that merchant's pricing. Global administrative lists/audits never include merchant-owned definitions. No Super Admin tenant bypass.

## APIs and bilingual UI

Implemented API contracts are in `docs/api/openapi.yaml`:

* GET `/rates`; POST `/rates/quote`; GET `/rates/quotes`; GET `/rates/quotes/{quote}` and `/validate`; POST `/rates/quotes/{quote}/recalculate`.
* GET/POST `/admin/pricing/definitions`; PATCH `/{definition}`; POST `/{definition}/publish` or `/disable`; GET `/{definition}/versions`; GET `/admin/pricing/audit`.
* Equivalent target-merchant catalog/version/action/audit routes under `/admin/pricing/merchants/{merchant}`; GET target `/quotes/{quote}` for explicitly authorized private inspection.

English/Arabic, LTR/RTL routes: `rates` (calculator/configured service discovery/detail), `quote-history` (history/detail/recalculate), `admin-pricing` (service and tax configuration/overview), `carrier-costs`, `merchant-pricing`, `pricing-tiers`, `shipping-zones`, `pricing-fees`, `pricing-rules`, `pricing-audit`. Typed configuration fields use minor units/basis points explicitly. No raw JSON configuration editor or merchant cost display. Detailed field constraints/evidence requirements remain enforced server-side.

## Remaining approvals and operational limits

* Verified Aramex account/service/route tariffs, weight/divisor/rounding, COD/remote/declared-value terms and tax advice are absent. No live offer is activated.
* No live rates, carrier account verification, FX, customs/duties, shipment acceptance, payment, wallet effect or production deployment has been attempted.
* Pricing publication uses existing 15-minute MFA and merchant-grant lifetimes. Independent business controllers must use separate accounts/devices; software cannot prove organizational independence.
* Admin catalog/reference lists are bounded to 500 latest definitions and per-definition version/audit lists to 100; quote history is paginated. Large catalog import/search/pagination and performance tuning need a measured later increment before exceeding those bounds.
* Supplied city identifiers are not address verification. Operational city aliases/coverage and fee classifications must be reviewed before real use.
* A later phase must define quote acceptance/revocation semantics across service/contract suspension and shipment reservation. Phase 5 validation is expressly not a charging contract.
* Encryption depends on existing application key management/backups; no cost-bearing diagnostics/request bodies should be logged. Runtime DB ownership/separate restricted roles and production backup/retention controls retain the existing launch gates.

Catalog writes additionally intercept SQL exceptions inside their application transaction boundary before Laravel can log cost-bearing query bindings. Constraint/serialization conflicts become generic409; other database failures become generic503. Successful API envelopes and private snapshots remain separate. Diagnostic monitoring must use identifiers/statuses, never re-enable logging raw tariff SQL.
