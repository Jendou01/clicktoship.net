# Open questions and blockers

Foundation work is independent of these integration/launch prerequisites. Supply credentials through secure environment settings, never chat or Git.

| Priority | Question / recommended default | Owner | Blocks |
|---|---|---|---|
| P0 | Is aggregation/resale under the master account contractually allowed? Obtain written shipper-of-record terms | Business/Aramex | production activation |
| P0 | Which origins, third-country lanes, payer accounts, pickup areas and services are enabled? Default deny unknown routes | Aramex | eligible quotes/creates |
| P0 | Current V2 JSON schemas, error samples, timestamps, rate limits, service/status catalogue and sandbox credentials? Do not reuse legacy manual credentials | Engineering/Aramex | adapter contract/sandbox validation |
| P0 | Supply actual webhook spec/sample/email referenced in blueprint; confirm auth, event identifier, key semantics, replay window and retry/ack protocol | Engineering/Aramex | webhook implementation |
| P0 | Is lookup by client reference supported and how are unknown creates resolved? Default manual quarantine; no blind resend | Operations/Aramex | safe submission recovery |
| P0 | Tariffs, fuel/remote/weight changes, VAT, billing currencies and final adjustment policy? No global 14 SAR price | Finance/Aramex | pricing and reconciliation |
| P0 | PSP, supported merchant countries, funding/refund methods, regulatory and PDPL requirements? No real PSP until approved | Finance/Legal | funding/launch |
| P0 | Chart of accounts, hold expiry/unknown-result policy, adjustment thresholds, dual approvers and tax invoice requirements? No implicit FX | Finance | ledger acceptance |
| P1 | COD eligibility/currency and settlement liability? Resolve USD-only manual and COD/CODS conflict | Finance/Aramex | COD |
| P1 | Current country/postal/address/office services docs? Only FetchCities explicitly supplied | Aramex | location capabilities |
| P1 | Shipment cancellation/return/POD API contracts? Keep disabled/manual until confirmed | Aramex | optional capabilities |
| P1 | Salla partner access/scopes and Shopyzee official extension/API contracts? | Integrations owner | Phases 13/14 |
| P1 | Launch geography, languages, timezone defaults, volume/retention and object-store region/provider? Arabic/English and UTC data default | Product/Security | production sizing/privacy |
| P1 | Design/brand assets and merchant verification requirements? Use restrained accessible bilingual design | Product | full dashboard |

No missing external detail authorizes invented endpoints or fabricated integration success. Phase 2 authentication/tenancy is implemented independently. Production email sender/domain, TLS/origins, restricted DB runtime roles and privileged MFA remain launch configuration/administrative work; no secret values should be supplied in chat.

## Phase 3 remaining policy boundaries

Business data can be manually reviewed without changing account activation; legal/KYC certification and country-specific statutory requirements are not claimed. Document collection remains deliberately absent until secure storage, malware scanning, authorized download and retention policies are approved. Assisted MFA reset is blocked until identity proof, independent approval and notification requirements are defined; supported recovery uses password plus one-use backup code. Closed-account reopening is not supported; active/suspended reactivation is supported. Production privileges, private delivery/domain/TLS configuration and privacy/retention remain launch gates. These do not block the approved non-financial Phase 3 workflows.

## Phase 4 remaining accounting/operational gates

SAR, integer money, no overdraft, immutable double entry, idempotency and independent correction approval are approved. Qualified accountant signoff is still required for the provisional configurable chart/templates (service clearing/revenue/tax/carrier liabilities/settlement), financial limits, accounting periods, refunds/debt and Saudi regulatory/safeguarding classification. Normal correction execution remains disabled. No real funding/settlement/withdrawal/payment/shipping charges exist.

Unknown external-outcome resolution remains blocked pending an authoritative evidence source and approved independent reconciliation-decision contract. Structural matching is insufficient evidence of carrier rejection. Additional launch gates: purpose TTLs, suspended/closed financial correction policy, retention/export, daily reconciliation owners/alerts, restricted production DB principals/function owner and backup/restore/performance evidence. Independent approved ledger/test/read/review work is complete without resolving those gates.

## Phase 5 — blocked external assumptions

1. Owner-supplied verified Aramex tariffs by service/route/weight/package/effective period, currency, divisor and rounding; additional carriers remain configuration-ready, not integrated.
2. Authoritative city identifiers/aliases and remote-area classifications, COD/declared-value capabilities and charge bases, compound fuel/other charge policy.
3. Qualified approval of country/jurisdiction tax treatment, inclusive/multiple-tax/customs handling and margin interpretation. Explicit exemption also needs evidence.
4. Future quote acceptance/contract revocation, carrier account availability and shipment orchestration policy before Phase 7 wallet reservations. No quote is currently a shipment charge.
5. FX source, conversion/rounding policy and currencies remain separately blocked; no implicit conversion.

## Phase 6 capability-specific stops

Independent connector/configuration/request/queue/security work is implemented. External operations still need current V2 response/error/status schemas and scrubbed fixtures, secure authorized sandbox credentials/non-billable test policy, account/route/product/payer/customs/COD terms, rate-limit/reference/idempotency guarantees and the missing webhook authenticity/replay/ack/registration specification. Legacy SOAP/manual credentials and request-only examples are insufficient evidence. Label storage/retention, verified status maps and actual send/finalize/reconciliation remain separately gated. See [evidence matrix](integrations/PHASE_6_EVIDENCE.md); no live success or Phase 7 authorization is implied.

## Phase 7 activation blockers

Verified V2 response/lookup/label/pickup contracts, account/service mapping and route entitlements, authorized sandbox policy, storage/scanning/retention, customs/Incoterms/COD/insurance/DG policy, financial settlement during suspension/freeze, conflicting-evidence resolution standards and qualified accounting/regulatory/runtime-role signoff remain unresolved. Independently testable orchestration proceeds; real carrier/financial/document activation stays disabled.

## Phase 10 external approvals still required

* Verified country/lane/product customs evidence, commodity/HS applicability, restricted-goods and dangerous-goods legal/carrier policies. Reviewed proposal metadata is not legal verification.
* Authorized current Aramex customs item/document/response contracts and sandbox contract evidence; route/payer/Incoterm (including DDP) and account eligibility. No transport is enabled by normalized DTOs.
* Approved fee/tax/duty/insurance sources and accounting/regulatory policies before prices or collection. No implicit FX or new wallet ledger.
* Approved commercial invoice/packing-list legal wording, draft-to-official workflow, numbering, signatures/certificates and authority filing mechanism. Current PDFs are preparation drafts only.
* Production private/shared encrypted storage, malware scanning, backup/access/retention/deletion/privacy controls. Default quarantine and hard production generation/download gates cannot be overridden by merchant/admin review.


## Phase 12 operational policy boundaries

* Approve a precise cross-merchant monetary disclosure policy (roles, purpose, currency scope, audit, retention and confidentiality) before any global posted/reserved/available amounts. Current overview provides counts only; exact-granted financial views remain available.
* Scheduler monitoring needs an approved persisted heartbeat and its operational ownership before independently claiming health. Current UI explicitly reports no independent observation.
* APP_KEY rotation changes derived opaque alert identities/read-state fingerprints; agree on retention/rotation transition before production. Source cases remain authoritative and immutable receipts are retained.
* 2,000 synthetic merchants and local plans are not representative production load/soak evidence. Production requires separate runtime DB role provisioning, disaster recovery, retention, observability and security review.
* Existing carrier response/auth/webhook evidence, accountant/regulatory review, document scanning/storage/retention and external notification consent/transport gates remain unresolved and cannot be enabled in dashboard settings.


## Phase 13 Salla prerequisites and deliberately stopped capabilities

* Obtain accessible current official Partners/API/OAuth/event documentation and dated contract evidence. All requested docs URLs were denied by the managed proxy. No official API endpoint, wire field/status, OAuth mode/scope, signature/event/retry/replay/ACK contract is verified.
* Register/approve a legitimately controlled demo Partners app; agree least privilege/installation mode and exact reachable HTTPS callback/redirects. No production keys, tunnels, DNS or deployment were requested/created. Runtime Salla/client/webhook capability remains closed.
* Verify order payment/fulfillment/cancellation, COD, currency precision, item weight/address semantics, pagination/checkpoints and rate limits before mapping real data. Existing Shipping allows SAR and zero COD only; missing/ambiguous fields cannot be fabricated. International UI supplementation still uses existing Shipping/Customs workflows; complete typed API review cannot bypass those rules.
* Approve standing automated sync consent/authority and uninstall/revocation policy. Current durable work rechecks its originating browser session/token; expiry can block unattended processing. No permanent credential/membership bypass.
* Approve ownership transfer and provider-specific private platform support permissions under independent exact target grants before exposing either capability. Current ownership cannot move, and platform receives counts only.
* Approve lawful customer-data minimization/retention/redaction/deletion, storage/backups, key rotation and blind-index transition policy before real ingestion. Immutable evidence is retained by default; no arbitrary history erase control or PII export.
* No actual Salla OAuth/sandbox test, fulfillment write-back, live carrier/financial/COD settlement/notification or production readiness is implied by internal synthetic tests. Existing accounting, carrier, customs and regulatory blockers remain.

## Phase 14 Shopyzee connector prerequisites

1. Accessible official plugin documentation export (all eight supplied links were proxy-denied), exact deployed Nazmart/Shopyzee version, and authorized separate development source/staging environment.
2. Verified manifest, PluginBase namespace/signatures, lifecycle, API/web route/hook registration, tenant initialization/teardown/settings isolation and encryption.
3. Actual permanent platform and tenant/store identifiers, custom-domain lifecycle, order/item/customer/address/payment/COD/fulfillment models and statuses. Domain alone never proves authority.
4. Supported per-tenant installation/consent/authentication, least-privilege permissions, credential provision/rotation/revocation and replay guarantees. No OAuth/global customer-order key is assumed.
5. Verified bounded initial/incremental ordering/cursor/update/rate-limit contracts, source conflicts and webhook authenticity/replay/ACK/uninstall semantics. Current tests are internal synthetic fixtures only.
6. Independently approved connector packaging/install/upgrade/uninstall compatibility tests in isolated staging; no live site modification is authorized.
7. Privacy/retention/key rotation, standing background consent, source-ownership transfer and exact-granted support policies. Future fulfillment writes require a separately approved verified API and carrier-verified tracking/outbox/idempotency/reconciliation design.


## Phase 15 remaining approval/evidence requirements

* Approve private export storage, encryption key management/rotation, malware/content scanning, residency, retention/deletion and operator access before any normal artifact enablement. Current implementation intentionally has no normal-runtime enable switch.
* Qualified accountant/regulatory review of realized revenue, carrier invoice costs, margin/profit, settlement and tax recognition remains required. No wallet funding-as-revenue, P&L, implicit FX or global private monetary disclosure is authorized.
* Historical balance replay/as-of semantics, authoritative shipment country/service projection, complete duplicate-suppression counters and comparison-based growth alerts require separately approved source contracts. Current insights are threshold observations, not increase/decrease claims.
* Carrier/provider evidence gates remain unchanged; synthetic data cannot establish verified delivery or real integration compatibility. Any future normal exports require a reviewed implementation change and independent security/operations validation.

## Phase 16 launch acceptance

Resolve B16-01–12 in security/SECURITY_AUDIT.md and operations/LAUNCH_BLOCKERS.md: actual restricted runtime roles, reviewed immutable release/ingress/proxy/secret/supervision/capacity configuration, private storage/scanning/residency/retention, qualified accounting/regulatory approval, current carrier/store/deployed-version sandbox contracts, managed PITR/key/object recovery and approved RPO/RTO, critical queue capacity, formal accessibility/on-call/monitoring and independent remote release checks. Default failure diagnostic privacy is fixed; alternate stores/retention need review. Review legacy hashing-cost migration/remote timing policy separately; no universal constant-time or production SLA certification is implied by configured-cost local samples. No real-world capability becomes enabled from passing local tests.

## Phase 17 host acceptance pending

Exact VPS/provider/region/domain, separate deployment authorization, named operator/on-call, protected CI environment/review/provenance, actual host firewall/TLS/patching/resource/DB-role validation, private SMTP/controlled egress, encrypted offsite retention/PITR and matching key escrow, approved storage/scanning/residency, formal accessibility and cross-version rollback remain required. See deployment/PRODUCTION_CHECKLIST.md. A protected local simulation cannot supply an owner-accessible external URL. No production or provider activation follows from a merged PR.
