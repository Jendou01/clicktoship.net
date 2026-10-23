# Phase 5 — Shipping Pricing Engine implementation report

Repository: `Jendou01/clicktoship.net`
Branch: `feat/phase-5-pricing-engine`
Base verified: `f3ab72293cdcc9ef8f633df79426c12d18938993` (PR #3 Phase 4 merge). Existing commits preserved; no merge into main or deployment.

## Delivered capabilities

* New owning Pricing module: verified static carrier-cost source, deterministic integer calculator, versioned service/tariff/selling/tier/assignment/zone/fee/tax catalog, immutable quote finalization. Carrier adapters may supply only cost definitions; selling rules, taxes and capabilities remain platform-owned. Only the static source is runtime-bound; no carrier HTTP request exists.
* Domestic/international country routes, city zones, service and package/weight bands, explicit volumetric/billable rounding, fixed/percentage components, discounts, additional carrier/merchant fees, conditional COD/remote/declared-value capability policies, effective dates and minimum gross-margin safeguards.
* Merchant > assigned tier > carrier > global precedence, then selector specificity. Reject equal-rank overlapping selling rules, ambiguous tariffs/fees, overlapping cities/assignments. No arbitrary cheapest-price selection.
* Integer minor-unit strings reuse existing `Money` and currency catalog. SAR enabled; reject mixed currencies, missing tax treatment, unclassified cities, unknown tariffs/services and unsupported routes. No implicit FX, tax rate or Aramex tariff.
* Immutable tenant-scoped quote inputs/public amounts and encrypted confidential selected versions/cost/margin/assumptions. Permanent key+normalized-input replay protection, bounded expiry, history/detail, validity check and immutable recalculation ancestry. Historical prices remain unchanged after repricing or expiry.
* No wallet provisioning/debit/reserve/capture/credit, shipment creation, real payment, carrier sandbox/production integration, live availability or Phase 6 work.

## Database and authorization

Migrations:

1. `2026_10_24_080000_create_pricing.php` loads `database/sql/phase5.sql`: `pricing_definitions`, `pricing_versions`, `pricing_events`, `shipping_quotes`, `pricing_quote_requests`.
2. `2026_10_24_090000_pricing_permissions.php`: additive pricing grants for built-in merchant roles and platform Super Admin/Finance; expands exact target access-grant capability allowlist.

UUIDs, generated relational FK selectors, composite merchant/parent/request relationships, currency/monetary/date/weight/package constraints, optimistic draft/publication counters, append-only history triggers, immutable ownership, deferred publication conflict enforcement and tenant-bound audit-grant relationships. Empty additive rollback/reapply succeeded. Retained history blocks normal downgrade; disposable testing database exceptions are explicit.

Merchant `rates.read`/`rates.quote` require current active membership and matching token abilities. Administrators require `platform.pricing.view/manage/approve`, a current browser session and recent MFA. Independent publication/disable rechecks maker role/session/credential/MFA. Target merchant work/private quote reads additionally require exact independently approved, expiring session-bound grants for each party; conflicting merchant membership is refused. No Super Admin tenant bypass. Platform global lists/audits exclude tenant-owned definitions. Application transaction boundaries sanitize SQL conflicts before cost-bearing bindings can be logged.

## API and screens

21 additional operations under `/api/v1` (111 total documented, all 90 existing operations/components preserved):

* GET `/rates`; POST `/rates/quote`; GET `/rates/quotes`, `/rates/quotes/{quote}`, `/rates/quotes/{quote}/validate`; POST `/rates/quotes/{quote}/recalculate`.
* GET/POST `/admin/pricing/definitions`; PATCH `/definitions/{definition}`; GET `/definitions/{definition}/versions`; POST `/definitions/{definition}/publish` and `/disable`; GET `/admin/pricing/audit`.
* Equivalent grant-protected definition/version/action/audit operations under `/admin/pricing/merchants/{merchant}` plus confidential GET `/quotes/{quote}`.

Full requests/responses and closed configuration variants are in [OpenAPI](api/openapi.yaml). Publishing/disabling requires both current `version` and `state_version`; editing creates a complete new draft without changing the last active revision.

Bilingual EN/AR and LTR/RTL pages: `rates` calculator/detail; `available-rates` configured-service discovery; `quote-history` history/detail/recalculation; `admin-pricing` overview/service/tax configuration; `carrier-costs`; `merchant-pricing`; `pricing-tiers`; `shipping-zones`; `pricing-fees`; `pricing-rules`; `pricing-audit`. Typed evidence/configuration forms and retained version inspection; no raw JSON configuration editor. All merchant pages explicitly distinguish estimates from live availability/charges and omit confidential costs/margins.

## Executed verification

| Check | Result |
| --- | --- |
| Backend full suite, real PostgreSQL + Redis | **162 tests passed; 1,477 assertions**, including all Phase 0–4 regressions and 26 new pricing tests |
| Laravel Pint | All 139 PHP files passed configured rules |
| Real PostgreSQL process concurrency | Duplicate quotes converge to one immutable record; changed duplicate conflicts; suspension blocks creation; concurrent admin revisions cannot overwrite; winning session revocation blocks writer. Barrier + real PostgreSQL lock-wait detection, no timing-based race assumption |
| Pricing/security coverage | Precedence/tier/tenant overrides, ambiguity, database conflict/history triggers, weight boundaries/package modes/volume, fees/discount/tax/margin, capabilities, international routes, currency rejection, TTL/expiry/replay/recalculation, historical repricing, IDOR, token scopes, MFA/maker revocation, independent target approvals, CSRF, confidential DTO/SQL/provider boundaries and no wallet effects |
| Frontend Vitest | **28 passed** across six files |
| Playwright Chromium, real Nginx/Next/API/PostgreSQL/Redis/Mailpit/Horizon | **14/14 passed in three consecutive complete runs (42/42)**, retries disabled; final run includes the cost-source security review |
| ESLint / TypeScript | Passed |
| Next.js production build | Passed for English/Arabic routes; development runtime restored afterwards |
| Migration upgrade/rollback/reapply | Passed; prior users/merchants/wallet schema preserved; retained history has an explicit normal-database downgrade guard |
| Composer validate / audit | Passed / no advisories |
| npm production dependency audit | Zero vulnerabilities |
| Installer integrity regression | Four Python tests passed |
| OpenAPI | Parsed; local references resolved; unique operation IDs; existing 90 operations/components preserved exactly |
| Development startup | Nginx English/Arabic smoke and PostgreSQL/Redis/schema readiness passed; Horizon running; normal development catalog contains zero pricing definitions |

New browser fixture accounts/configurations run only on `testing/clicktoship_e2e`. Generated credentials remain in 0600 temporary files outside Git. They do not seed normal development or production. Browser recovery-code selection respects retry index. The initial new browser selector issue was resolved through explicit label/control association; no timeout increase, delay, skipped test or permission relaxation was introduced.

## Remaining approvals and limits

* Owner-supplied verified Aramex tariffs/contracts, account/route/service entitlements, city/remote coverage, divisor/rounding and additional-service charge terms remain absent. Nothing is automatically activated from illustrative rates.
* Qualified tax/accounting approval remains outstanding. Only explicit evidenced exemption or exclusive origin/destination-country policy is supported; complex inclusive/multiple-tax/customs/FX schemes remain unavailable.
* Configured quotes never authorize shipping or wallet charging. Future shipment work must separately define current-authority/expiry/contract-revocation acceptance and orchestration; no Phase 6/7 implementation here.
* Administrative catalogs/reference lists are bounded to 500 definitions; audit/version reads to 100. Quote history is paginated. Large-catalog import/search/pagination, operational city aliases and measured performance remain follow-up work before exceeding these bounds.
* GitHub GraphQL/API currently returns **Forbidden**. Git transport remains separately usable. PR creation/check inspection is attempted after push; no GitHub Actions success is claimed without access. A prepared PR body is used if permissions allow.

Detailed arithmetic, schema, locking, approval boundaries and limitations: [Phase 5 architecture](architecture/PHASE_5_PRICING.md).

## Files changed

41 files in this implementation:

- `README.md`
- `apps/api/app/Modules/Operations/Application/Administration.php`
- `apps/api/app/Modules/Pricing/Application/Catalog.php`
- `apps/api/app/Modules/Pricing/Application/Quotes.php`
- `apps/api/app/Modules/Pricing/Application/StaticCarrierRates.php`
- `apps/api/app/Modules/Pricing/Domain/Calculator.php`
- `apps/api/app/Modules/Pricing/Domain/CarrierRates.php`
- `apps/api/app/Modules/Pricing/Domain/Configuration.php`
- `apps/api/app/Modules/Pricing/Http/PricingController.php`
- `apps/api/app/Providers/AppServiceProvider.php`
- `apps/api/database/migrations/2026_10_24_080000_create_pricing.php`
- `apps/api/database/migrations/2026_10_24_090000_pricing_permissions.php`
- `apps/api/database/sql/phase5.sql`
- `apps/api/routes/api.php`
- `apps/api/routes/pricing.php`
- `apps/api/tests/Feature/ConcurrentPricingTest.php`
- `apps/api/tests/Feature/FinancialMigrationTest.php`
- `apps/api/tests/Feature/Phase3MigrationTest.php`
- `apps/api/tests/Feature/PricingCalculationTest.php`
- `apps/api/tests/Feature/PricingSecurityTest.php`
- `apps/api/tests/Feature/PricingTest.php`
- `apps/api/tests/Fixtures/concurrent-pricing.php`
- `apps/api/tests/Fixtures/pricing-browser.php`
- `apps/api/tests/Support/PricingTestCase.php`
- `apps/web/src/app/[locale]/[screen]/page.tsx`
- `apps/web/src/components/identity-screen.tsx`
- `apps/web/src/components/management-screen.tsx`
- `apps/web/src/components/pricing-screen.tsx`
- `apps/web/src/lib/management-copy.ts`
- `apps/web/src/lib/pricing.ts`
- `apps/web/tests/e2e/pricing.spec.ts`
- `apps/web/tests/pricing.test.tsx`
- `docs/DECISIONS.md`
- `docs/OPEN_QUESTIONS.md`
- `docs/PHASE_5_REPORT.md`
- `docs/ROADMAP.md`
- `docs/api/API_DESIGN.md`
- `docs/api/openapi.yaml`
- `docs/architecture/ARCHITECTURE.md`
- `docs/architecture/PHASE_5_PRICING.md`
- `scripts/prepare-browser-tests.sh`
