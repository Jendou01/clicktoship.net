# Phase 10 — International Shipping & Customs

## Delivery scope and base

Feature branch: `feat/phase-10-international-customs`, based on fetched `main` with verified Phase 9 PR #9 merge `a7c5e19cc5ab50205b862f8a07ca9780641d0de3`. This delivery implements international shipment preparation, structured customs and draft documents. It does not activate shipping, payments, wallet charges, customs authority filing, production deployment or Phase 11. No automatic merge.

## Implemented capabilities and reuse

* International preparation reuses Shipping's sender/receiver/origin/destination/service/packages and immutable revision lifecycle. Customs adds exporter/importer, purpose, invoice date, references, trade terms, duty payer, instructions and notes. A customs edit produces a Shipping-owned revision; quote, readiness and document applicability remain bound to their exact source.
* Structured bilingual item declarations include whole-unit quantity/UOM, SKU, merchant-provided HS, manufacture country, material, intended use, category/notes, exact unit/total minor units, explicit currency exponent, total line net/gross grams and package allocations. The server recomputes values, rejects mixed currency, unknown countries/fields, malformed lists and inconsistent quantity/weight/package totals. It never guesses HS classifications or currency conversions.
* Merchant customs profile history, explicit imported-draft synchronization, version history and historical declaration review. Item edits are an atomic complete aggregate under expected shipment/declaration versions rather than independent mutable child routes.
* Readiness reuses Shipping structural validation and adds six classifications: validated, missing_information, requires_merchant_review, requires_external_verification, unsupported and blocked. It reports local preparation completeness separately from country/carrier/legal evidence. Customs clearance, legal approval, duties calculation and shipping enablement remain false.
* Nine informational goods flags, merchant acknowledgement and independently reviewed country requirement proposals. Approved preparation configuration may identify missing declared fields; external verification and runtime enablement remain false. No administrative switch confers legal compliance or makes a service available.
* Existing Pricing public preview is reused. Unavailable prices are explicit; no tariff, insurance, VAT, customs duties or carrier fee is fabricated. No financial write path or second ledger.
* International CSV/XLSX template variants append safe `customs_json` mapping, canonical item validation, row-level readiness and draft-only preparation. Existing 10 MiB, 10,000-row, 4,096-byte cell and workbook security protections remain. Per-row current `customs.write` authorization prevents narrower import tokens creating customs data.
* Carrier normalized DTO retains documented base request fields only. Customs-specific unverified fields are reported unmapped; mapping verification/external execution remain false. Existing Aramex request builders/evidence gates and Tracking remain authoritative and unmodified in behavior.

## PostgreSQL migration

Additive migration `2026_10_24_170000_create_international_customs.php` executes `database/sql/phase10.sql`, adding 16 tables:

`customs_declarations`, `customs_versions`, `customs_items`, `customs_profiles`, `customs_country_rules`, `customs_rule_versions`, `customs_rule_reviews`, `customs_readiness`, `customs_reviews`, `customs_commands`, `customs_events`, `customs_document_counters`, `customs_documents`, `customs_document_work`, `customs_document_results`, `customs_document_revocations`.

UUID identities, tenant/source composite foreign keys, immutable history, deferred currency/arithmetic checks, optimistic version guards, independent-review constraints and durable-work identity/terminal guards are enforced in PostgreSQL as well as services. Merchant/type counters and source-version/kind/locale uniqueness prevent duplicate numbers/effects. Rollback refuses retained customs history/grant references. Disposable rollback/reapply tests preserve previous-phase assertions and normal development data. Schema integrity verifies critical columns and enabled constraints/triggers independently of migration history.

## Versioned API

All paths below are under `/api/v1`; OpenAPI 0.10.0 documents 16 paths / 18 operations, request schemas, permissions and session CSRF requirements.

| Method | Path | Purpose |
|---|---|---|
| GET | `/customs/declarations` | Merchant history |
| GET, PUT | `/customs/profile` | Current/versioned preparation profile |
| GET | `/customs/countries/{country}` | Country proposal/evidence status |
| GET | `/customs/shipments/{shipment}` | Current or authorized historical declaration, readiness/documents |
| PUT | `/customs/shipments/{shipment}/declaration` | Versioned complete item aggregate |
| POST | `/customs/shipments/{shipment}/sync` | Explicit source-revision synchronization |
| POST | `/customs/shipments/{shipment}/validate` | Persist readiness snapshot |
| GET | `/customs/shipments/{shipment}/pricing` | Existing Pricing preview/unavailable result |
| POST | `/customs/shipments/{shipment}/documents` | Durable draft invoice or packing list request |
| GET | `/customs/documents/{document}/download` | Current-authority private scanned draft attachment |
| POST | `/customs/documents/{document}/revoke` | Immutable revocation |
| GET, POST | `/admin/customs/rules` | Country evidence proposal/history |
| POST | `/admin/customs/rules/{country}/review` | Independent MFA-authenticated proposal review |
| GET | `/admin/merchants/{merchant}/customs` | Target-granted redacted diagnostics |
| GET | `/admin/merchants/{merchant}/customs/declarations/{declaration}` | Stronger target-granted audited private structured review |
| POST | `/admin/merchants/{merchant}/customs/declarations/{declaration}/reviews` | Immutable review annotation, not clearance |

## Frontend

Fully bilingual English/Arabic routes: `international`, `customs-declaration`, `customs-products`, `commercial-invoice`, `packing-list`, `customs-readiness`, `customs-documents`, `international-history`, `admin-customs`. The international wizard reuses existing shipment creation; screens provide item/party/profile forms, field errors, readiness guidance, draft document/history controls, reviewed-country evidence and restricted target review/monitoring/audit sections. Merchant navigation is permission-aware; shipment details link preparation. Locale switching retains only an authorized shipment UUID and current server-side merchant context. RTL/LTR controls and narrow mobile viewport are exercised in the real browser.

## Document generation and security

TCPDF 6.11.4 is the sole new dependency, providing Unicode/Arabic font/layout support. Renderer inputs are typed text cells only, never HTML, uploaded PDFs, remote resources, JavaScript, signatures or certificates. Default PDF vendor URL annotations are disabled; bidi override controls are stripped in display. Invoice and packing list bind immutable declaration/source versions and server numbers. Every page says **DRAFT — NOT CUSTOMS CLEARANCE** in English/Arabic. Synthetic render samples are clearly labeled test data and not stored as real transactions.

Jobs contain encrypted IDs; PostgreSQL durable work uses 90-second leases, 60-second jobs and five bounded attempts. Recovery republishes missing notifications. Current merchant/membership/permission/token or session, auth version, suspension, source revision and document revocation are checked at both claim and result commit. Rendering/scanning runs outside DB locks. Retries reuse document identity/number. Result bytes are authenticated-encrypted on existing private storage; checksum and scan policy are enforced on download with no-store, nosniff, sandbox and draft/historical headers. Default scanning yields quarantine with no download URL. **Production generation/download are hard disabled.**

Merchant permissions `customs.read`, `customs.write`, `customs.documents` intersect token scopes; document commands additionally require Shipping document authority. Generic Shipping writes cannot bypass customs permission to change or strip a declaration. Platform rules/reviews require current MFA, independent approval where applicable and an exact expiring session-bound merchant grant for target data. Private structured review uses `platform.customs.review`, stronger than metadata viewing. No automatic Super Admin bypass. Sensitive history/evidence/reasons are encrypted, immutable and audited; raw credentials/payloads are not logged or committed.

## Automated verification

| Check | Result |
|---|---|
| Full backend Phase 0–10, real PostgreSQL/Redis | **312 tests / 3,248 assertions passed** |
| Frontend unit/component | **81 tests / 14 files passed** |
| Full Chromium real-stack suite | **20/20 passed twice consecutively** |
| Concurrent edits/document IDs/revocation/crash recovery | Passed, actual independent PHP/PostgreSQL processes and lock waiters |
| Migration rollback/reapply and schema drift | Passed in guarded disposable databases; prior-phase assertions retained |
| Laravel Pint | **263 PHP files passed** |
| ESLint / TypeScript / production build | Passed; build generated 139 pages |
| Installer tests | **4 passed** |
| Composer strict validation / Composer audit / npm audit | Passed; **zero known dependency advisories** |
| OpenAPI strict YAML / unique operation IDs / local refs | Passed, 16 customs paths / 18 operations |

Final service health and restored normal development database counts are recorded in the external delivery evidence accompanying this report. GitHub-hosted CI results must be reported separately from local results and cannot be assumed from these checks.

Security tests include cross-merchant IDs/source FKs, token-scope intersection, narrow generic Shipping/Bulk write denial, exact arithmetic and allocations, concurrent edits/number collisions, command and document idempotency, expired/revoked current authority, MFA/maker-checker/grants, quarantine/unauthorized downloads, encrypted-byte tampering, malicious text/no active PDF content, schema immutability, stale-job and credential-revocation commit fences, durable Redis-loss recovery, country rules, partial carrier mapping and no carrier/financial effects.

Initial browser development runs exposed three test/infrastructure assumptions: sharing Bulk's fixture consumed its real per-user action budget (429); nested select options contaminated exact label lookup; document quarantine text shares a status paragraph. Fixed with a dedicated isolated customs fixture, explicit localized accessible control labels, and assertions scoped to the actual server-issued document number. No rate limit, permission, timeout, retry or quarantine assertion was weakened; all prior 19 browser cases remained green.

## Remaining external approvals and limitations

* Only SAR is enabled by existing financial policy; architecture preserves currency/exponent, no FX. Quantities are whole units; complex bulk declarations remain bounded by existing cell limits.
* Country catalogue and reviewed preparation hints are not legislation or lane entitlement. HS classification/applicability, regulated goods and destination requirements need qualified verified policy; DDP selection does not promise collection/service.
* Current Aramex customs item/document/response and lane/payer/product contracts remain unverified; no sandbox/production carrier request was made. Partial base DTO testing is not integration certification.
* Fees/tax/duties/insurance/revenue/contract/regulatory decisions require approved evidence and accounting/legal review. No funds or reservations were created by customs.
* Draft numbering, language variants, wording and unsigned PDF layout require legal/tax approval before any official invoice/certificate workflow. Existing labels have incompatible label-only provenance, so Customs uses dedicated version/work tables while reusing private storage/scanner conventions.
* Production shared encrypted storage/scanner certification/access/retention/privacy/backup policy is not approved. Quarantine is intentional; the application does not expose these PDFs publicly or claim a successful malware integration.
* No automatic merge, production deployment, government submission, external activation or Phase 11 work. Review the feature branch before approving further capabilities.

## Delivery references

[Architecture](architecture/PHASE_10_CUSTOMS.md) · [OpenAPI](api/openapi.yaml) · [Changed-file manifest](PHASE_10_FILES.txt) · [Prepared PR description](PHASE_10_PR.md). Actual commit/remote verification and PR access outcome are provided in the final delivery message and synthetic-artifact gallery, avoiding a self-referential commit hash in this file.
