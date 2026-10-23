# Domain glossary

| Term | Meaning |
|---|---|
| Merchant | Tenant organization; canonical database scope is merchant_id |
| Membership | Active user-to-merchant association with a scoped role; identity alone grants no tenant access |
| Platform operator | Separately authorized staff identity; not an implicit cross-tenant bypass |
| Carrier account | Operator-owned billing/service profile, isolated by environment and permissions |
| Capability | Operation that must be documented, verified, account-enabled and route/service-eligible before use |
| Service / lane | Carrier product configuration / origin-destination eligibility, not universal coverage |
| Quote | Expiring approved rate/sell-price result with explicit currency and rule version |
| Price snapshot | Immutable approved historical cost/sell/fee/tax components; never recomputed from current tariffs |
| Wallet | Merchant liability account in one currency; not a PSP account or unrestricted payment service |
| Hold | Auditable reservation that reduces available spend without recognizing shipping revenue |
| Capture / release | Consume reserved money after carrier acceptance / free reservation after definitive rejection |
| Ledger transaction | Balanced, append-only set of debit/credit entries in one currency |
| AWB | Carrier shipment reference; not globally unique without account/region confirmation |
| Submission attempt | Durable record of one outbound request including uncertain outcomes and correlation |
| Unknown submission | Request may have been accepted; quarantine until reconciliation instead of blind resend |
| Outbox / inbox | Transactionally persisted outbound domain event / authenticated durable inbound provider event |
| Tracking projection | Current normalized state computed from retained raw events and explicit transition rules |
| Pickup cancellation | Cancellation of collection request only; does not establish shipment cancellation |
| COD | Carrier collection and remittance liability; never automatically wallet funding |
| Store connector | Provider-specific authorization/order/fulfillment adapter, independent of shipping domain |
