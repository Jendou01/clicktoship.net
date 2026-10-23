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
