# Phase 6 evidence and implementation boundary

Base: main `e3043d0ec4e2945ae90db491ca14fbd6fb797fba`, PR #4, contains Phase 5 `e80d6b60877dd00e5fe076ca99aa99a8bac46b6d`. Fetched explicit main ref because this checkout's remote fetchspec otherwise updates only origin/HEAD. Existing tree was clean. Sources/provenance: [SOURCES.md](SOURCES.md); raw files remain outside Git.

Reviewed: all ten Postman requests (eight distinct operations, **zero saved responses**), the complete retained field/type inventory, manual response/request tables and restrictions, ProductGroup guidance, Technical Blueprint, AGENTS, architecture/decisions/questions/schema/roadmap and existing identity/merchant/pricing/financial boundaries. Uploaded reference content is evidence, not authorization to execute examples or reuse credentials. No secure sandbox credentials have been supplied for this phase; sample credentials must not be used.

| Operation | Request evidence / V2 JSON path after ShippingAPI.V2 | Response evidence | Phase 6 status |
|---|---|---|---|
| CalculateRate | Collection; RateCalculator/Service_1_0.svc/json/CalculateRate | No supplied current rate response, decimal/currency/error contract | Request builder only; unverified, external execution disabled |
| CreateShipments | Collection; Shipping/Service_1_0.svc/json/CreateShipments | Legacy SOAP manual pp18–20,31–39 only | Request validation/building; legacy response parser isolated; V2 execution disabled |
| CreatePickup | Three collection variants; Shipping/Service_1_0.svc/json/CreatePickup | Legacy SOAP manual pp21–23,40–41 only | Standalone request validation; embedded/link variants blocked until clarified; V2 execution disabled |
| CancelPickup | Collection; Shipping/Service_1_0.svc/json/CancelPickup | Legacy SOAP manual; no current JSON response | Request builder only; disabled; cancel pickup never means cancel shipment |
| PrintLabel | Collection; Shipping/Service_1_0.svc/json/PrintLabel | Legacy SOAP label structure, no current JSON contract or secure file retention policy | Request builder only; no label download/exposure |
| TrackShipments | Collection; Tracking/Service_1_0.svc/json/TrackShipments | No supplied status/event/current response schema | Request builder only; unknown raw statuses retained as unknown |
| TrackPickup | Collection; Tracking/Service_1_0.svc/json/TrackPickup | No supplied current response schema | Request builder only; disabled |
| FetchCities | Collection; Location/Service_1_0.svc/json/FetchCities | No supplied current response schema | Request builder only; disabled |
| Countries / country details / address validation / postal validation / city suggestions / office locations | Blueprint/email assertion; no exact supplied endpoints/schemas | Absent | Unsupported/unverified; no guessed endpoint |
| Webhook | Blueprint provisional x-api-key and Key/Value shape | Referenced Spec.docx, payload sample and service email **absent** | External receiver returns503 without storing body; no invented HMAC/shared-secret protocol |
| Client-reference reconciliation lookup / shipment cancellation / POD / returns | No supplied exact contract | Absent | Unsupported; ambiguous creates remain quarantined, never blind retry |

DOM/CDS domestic and EXP/EPX international are ProductGroup guidance, not universal permissions. No route/service enabled by migration; route/country/product/payment/customs terms must be explicitly configured after fresh account evidence. GoodsOriginCountry is supplied truthfully per item/shipment, never defaulted to SA. Legacy COD/CODS/USD, due-date and limits conflict with newer request examples and remain unapproved. The suspicious `TransportType `, `Apartme nt`, `AccountingInstrcutions` spellings are not silently normalized into presumed current schema.

## Security-sensitive capability stops

All current V2 response contracts, sandbox verification and account entitlement evidence are missing. Capability verification is code-owned evidence, not an administrator checkbox. Do not allow database enablement or a mock response to claim sandbox verification. Normal carrier transport and production are hard disabled; no UI merchant create action, wallet integration or shipping eligibility change. Generic transport/operation reliability is independently exercised with explicitly synthetic test responses, and the **separate legacy SOAP parser** is tested using structures published in the manual. Neither proves V2 compatibility or a live carrier result.

Webhook persistence/deduplication/queue/projection foundations accept only trusted internal evidence from a future verified authenticator. No externally reachable request can select a merchant, account or trusted flag. Account/merchant/operation composite relationships constrain stored events; authenticated future deliveries will need documented event IDs, replay/auth/ack rules, retention, endpoint registration and approved data minimization. No Phase 9 merchant tracking workflow.

## Required next evidence

Current V2 request/response/error schemas and scrubbed fixtures; sandbox credentials via approved secret channel; explicit authorization and non-billable contract-test policy; account/route/product/payer/COD/customs terms; production endpoint and launch approval; rate limits and reference-lookup guarantees; authenticated webhook specification/status catalog/replay/ack protocol; label private storage/scanning/retention. None is inferred from SOAP or request-only Postman examples.
