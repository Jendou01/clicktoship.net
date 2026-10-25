# Proposed standalone ClickToShip Connector

This is a design and verification checklist, not an installable or version-compatible plugin. No Shopyzee source is modified, packaged, installed or contacted with credentials.

## User-supplied official documentation

* [Getting started](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/getting-started/)
* [Plugin API](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/plugin-api/)
* [Hook system](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/hook-system/)
* [Multi-tenant features](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/features/)

All four were requested on 2026-10-25 UTC; the managed HTTPS proxy returned Tunnel connection failed: 403 Forbidden before content could be read. User supplied shopyzee.com and the CodeCanyon product URL as context; storefront/product HTML cannot verify runtime API or deployed schema. A verified source export or accessible version-pinned docs is required. No proxy restriction is bypassed.

## Proposed two-sided boundary

A separately reviewed connector repository/package should use the actual documented plugin manifest/base/lifecycle and route/hook registration, without editing Nazmart core. ClickToShip continues to own Commerce, normalization, durable sync and reviewed shipment drafts. The connector owns tenant-authorized read-only order projection from the *actual deployed models*. No shared landlord-wide customer-order key or direct ClickToShip DB access.

Before source implementation, verify exact `plugin.json` schema, PluginBase namespace/method signatures, register_api_routes/register_web_routes contracts, install/enable/upgrade/uninstall behavior, tenant initialization/teardown and tenant settings encryption. These names are user-provided investigation targets, not verified signatures. Verify auth guards/middleware, current tenant resolver and stable instance/store IDs, settings scoping and order/item/customer/address/payment/COD models and status semantics.

Each store must explicitly consent using a supported verified protocol. Strong per-store credentials are a candidate, not an implemented invented protocol. Specify provision/exchange, hash/encrypt storage, least-privilege scopes, expiry/rotation/revocation, exact current tenant authentication and authorization, HTTPS, bounded responses, rate limits and replay rules before implementation. Never select a tenant solely from domain, caller tenant ID or a global API key. Any webhook scheme must be supported by verified source or separately designed/reviewed and approved, not represented as existing Nazmart behavior.

## Verification and installation gates

1. Obtain version-pinned plugin docs plus actual Shopyzee development source/schema and a separate authorized development checkout.
2. Confirm tenant identity is permanent across domain changes, and context is restored/cleared in jobs and long-lived processes.
3. Specify read-only minimized order DTO mapping, stable pagination/update/conflict semantics, money precision and explicit unknown values.
4. Implement separate plugin in its own reviewed development workflow with no core modifications, secrets or production data.
5. Test install/upgrade/disable/uninstall, two tenants/same IDs, per-store consent, token replay/rotation/revocation races, callback and webhook security, rate/body bounds, zero unauthenticated disclosure and zero tenant leakage.
6. Test connector and ClickToShip together against actual supported versions in isolated staging. Independently approve enablement. No live installation, fulfillment write-back, shipment submission or financial effects in Phase 14.

A source package would currently encode unverified imports/models/middleware/manifest syntax. It is deliberately not fabricated. The ClickToShip-side internal foundation can be completed independently.

## Expanded documentation check

The additional user-supplied [dashboard settings](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/dashboard-settings/), [distribution](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/distribution/), [reference](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/reference/), and [security](https://docs.xgenious.com/docs/nazmart-multi-tenancy-ecommerce-platform-saas/plugin-developer-guide/security/) pages were requested along with the original four. **All eight returned managed proxy tunnel 403 before document content was accessible.** No claim about their contents is made. An offline official documentation export can resolve this access blocker; the actual deployed-version models and authorization behavior still require source/staging verification.
