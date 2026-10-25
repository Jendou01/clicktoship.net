# Shopyzee evidence gates

Phase 14 development audit, 2026-10-25 UTC. The user identifies Shopyzee as a customized Nazmart-based multi-tenant platform. No Shopyzee source checkout, version-specific API specification, authorized installation credentials, or webhook contract is available in the supplied workspace. GitHub repository discovery was denied (GraphQL Forbidden). Stock Nazmart and storefront HTML are not evidence of the deployed system.

| Contract | Verified evidence | Runtime consequence |
|---|---|---|
| Framework/version, landlord/tenant models, permanent instance/store IDs | Unavailable | No deployed schema assertion or direct DB access |
| Consent/authentication/installation/rotation/revocation | Unavailable | No OAuth claim, authorize unavailable; no callback invented |
| Orders/items/payment/COD/address/status mapping | Unavailable | Internal normalized DTO fixtures only, explicitly synthetic |
| Pagination, ordering, incremental cursor, rate limits | Unavailable | Existing bounded Commerce workflow tested internally; live sync unavailable |
| Webhook authenticity/replay/ACK/events | Unavailable | Receiver fails closed before payload interpretation |
| Fulfillment write-back | Unavailable and unapproved | Disabled; no endpoint or transport |

## Internal boundary, not a provider contract
Provider + permanent platform-instance ID + permanent tenant/store ID identifies the source. A connection binds that tuple permanently to one ClickToShip merchant; external order IDs are scoped to the connection. Domains are optional presentation metadata and never authority. The legacy Salla namespace is `salla`; it is an internal namespace, not an asserted external API field.

Tests may supply `provider`, `platform_id`, `store_id`, and normalized Commerce order data through an internal synthetic adapter. These are NOT Shopyzee wire fields, endpoints, scopes, signatures, or successful external connections. Test credentials are accepted only under the existing isolated testing database gates.

## Required before external enablement
Obtain read access to the actual reviewed development source/specification, dated deployed-version evidence, permanent instance/tenant identity and ownership proofs, supported tenant-scoped authorization/consent/replay/revocation contract, minimized order/payment/COD mapping, pagination/rate limits, webhook verification and authorized sandbox policy. Review retention, key rotation and ownership-transfer policy. No change to live capability flags is authorized by this implementation.

Future write-back requires separately approved verified fulfillment contracts, current connection/tenant/scope authority, carrier-verified tracking provenance, an idempotent transactional outbox, replay/conflict/reconciliation rules and audit. No synthetic or local tracking state may become an external fulfillment assertion.

Update: user supplied official Xgenious plugin guide URLs. All four requests were denied by the managed proxy (403 tunnel failure); their contents and compatibility remain unverified. See [connector design](SHOPYZEE_CONNECTOR_DESIGN.md).

Expanded recheck: all eight user-supplied plugin-guide pages (including dashboard-settings, distribution, reference and security) returned the same managed HTTPS tunnel 403. No document contents or plugin compatibility were verified. Missing documentation blocks the external/plugin capability only; isolated internal Commerce verification continues.
