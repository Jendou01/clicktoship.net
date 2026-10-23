# Aramex evidence and integration boundary

## Source audit
Reviewed all four supplied reference attachments and the request text. Original files stay outside Git because the collection/manual include credential fields and personal sample data. No credentials are copied into fixtures or templates. The repository had no docs or application files at audit. See docs/integrations/SOURCES.md for checksums and provenance.

* Technical Blueprint v1.0: product authority, not proof of API behavior.
* Postman collection: 10 requests, 8 distinct operations, all POST to HTTPS sandbox ws.sbx.aramex.net/ShippingAPI.V2. There are **zero saved response examples**. No sandbox requests have been made.
* Shipping services manual: June 2011 / January 2013 revision; SOAP structures and validation tables, not a current V2 JSON contract. Legacy HTTP endpoints/certificate instructions must not be used to disable HTTPS/TLS verification.
* ProductGroup: domestic DOM/CDS, international EXP/EPX, GoodsOriginCountry=SA. Treat as Saudi account guidance only; goods manufacture country must be truthful and route/service mapping configurable.
* Missing attachments referenced by blueprint: webhook Spec.docx, webhook payload sample.txt, Aramex services email. No actual webhook spec was supplied. Salla and Shopyzee API documents also absent.

## Capability matrix
All listed documented operations remain unimplemented, sandbox-unverified and disabled in Phase 1.
| Internal operation | Collection path after /ShippingAPI.V2/ | Evidence/constraint |
|---|---|---|
| createShipment | Shipping/Service_1_0.svc/json/CreateShipments | collection + manual pp12,19–20,31–37; per-shipment and envelope errors |
| createPickup | Shipping/Service_1_0.svc/json/CreatePickup | collection has standalone, link-existing and embedded-shipment variants; manual pp14,21–23,40–41 |
| cancelPickup | Shipping/Service_1_0.svc/json/CancelPickup | PickupGUID + Comments; manual pp15, only unassigned/pending pickups |
| generateLabel | Shipping/Service_1_0.svc/json/PrintLabel | ShipmentNumber, LabelInfo, OriginEntity, ProductGroup; duplicate AWBs may need context |
| calculateRate | RateCalculator/Service_1_0.svc/json/CalculateRate | OriginAddress, DestinationAddress, ShipmentDetails, PreferredCurrencyCode; response/rate contract missing |
| trackShipment | Tracking/Service_1_0.svc/json/TrackShipments | Shipments array, GetLastTrackingUpdateOnly; full response/status mapping missing |
| trackPickup | Tracking/Service_1_0.svc/json/TrackPickup | Reference; response contract missing |
| getCities | Location/Service_1_0.svc/json/FetchCities | CountryCode, NameStartsWith, State; full response contract missing |
| webhook | no confirmed endpoint contract | blueprint mentions x-api-key and Key/Value.UpdateCode/WaybillNumber/UpdateDateTime; provisional only |
| countries, address/postal validation, city suggestions, offices | no supplied exact endpoints | blueprint assertion/email reference only; separate capability flags false |
| cancel shipment, returns API, POD, lookup by client reference | no supplied exact endpoints | unsupported pending documentation; no fabricated endpoints |

## Request/response findings
Every example has ClientInfo (UserName, Password, Version, AccountNumber, AccountPin, AccountEntity, AccountCountryCode, Source). Transaction carries correlation references, not documented idempotency. Shipment has Shipper, Consignee, optional ThirdParty, dates, Details, attachments and optional PickupGUID. Details includes weights/dimensions, pieces, ProductGroup/ProductType, PaymentType/Options, goods origin/description, customs/COD values and items. The examples contain suspicious keys `TransportType ` (trailing space) and `Apartme nt`, plus AccountingInstrcutions; validate exact spelling with current schemas rather than copying blindly. Dates use legacy JSON serialization in examples; confirm accepted timestamp format and timezone.

Manual response envelope: HasErrors, Notifications(Code/Message), Transaction. ProcessedShipment has ID/AWB, Reference, ForeignHAWB, HasErrors, Notifications and ShipmentLabel(URL or file content). ProcessedPickup has ID/GUID. Reject malformed/missing response fields; inspect both top-level and item errors. HTTP 200 is not proof of shipment success. Parse carrier money as exact decimal strings into minor units with known exponent, never float arithmetic.

Manual conflicts requiring confirmation: COD prose says service COD while appendix C says CODS; manual restricts COD currency to USD (p39), which cannot be assumed current/local policy. DueDate is optional in shipment table p31 but mandatory in datetime table p37. Pickup Reference1/volume requirements also differ across sections/examples. Old max 100 weight/dimension/pieces limits and report ID 9201 are not current universal constraints. EPX does not prove every international lane; CDS absent from older product appendix. Manual pp13 explicitly permits duplicate shipment numbers, so AWB alone must not be globally unique.

## Adapter design for Phase 6
CarrierAdapter exposes capabilities, calculateRate, createShipment, createPickup, cancelPickup, generateLabel, trackShipment, trackPickup, validateAddress, getLocations. Use typed provider-neutral DTOs and typed UnsupportedCapability errors. CarrierRegistry selects explicit account/environment and adapter; capability registry combines documented + sandbox_verified + account_enabled + route/service eligibility. Credentials manager server-side only, encrypted/managed references, no API exposure. Mapping tables own raw statuses/services, normalized errors and rates.

Allow only configured HTTPS destinations; production base URL must be confirmed, never guessed by replacing sbx. Set connect/request timeouts, traceable correlation IDs and redacted structured logs. Retry read operations with bounded backoff/jitter on retryable errors. **Never blanket-retry creates**; ambiguous transport responses and worker crashes quarantine submission until reconciliation. Similar duplicate-risk policy applies to pickups. Contract fixtures must be scrubbed real sandbox responses before claiming validated mapping. Webhook ingestion must be separately authenticated, size-limited, durable, deduplicated and replay-safe before acknowledging; do not assume HMAC without evidence. Unknown codes are retained and surfaced, not converted to delivered.

## Activation gates
Written resale/shipper-of-record permission; eligible origins/third-country lanes and pickup rules; tariffs/taxes/surcharges/billing currency; fresh sandbox credentials; current response/error schemas, rate limits and status catalogue; webhook auth/rotation/replay/retry protocol; carrier account enablement. Live activation requires operations, finance, legal and security approval and controlled pilot. No uploaded credential is implicitly authorized for use.
