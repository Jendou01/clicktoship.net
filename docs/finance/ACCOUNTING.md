# Phase 4 accounting assumptions and activation gates

SAR (exponent 2) is the only enabled currency. JPY/KWD catalogue entries are disabled precision fixtures, not enabled business products. Money uses integer minor units; APIs use exact decimal strings and no implicit FX. No PAN/CVV/bank credentials or live provider code exists.

## Implemented provisional chart

The operator owes the merchant its wallet balance: credits increase the wallet liability; debits decrease it. All journals balance within a single currency and merchant context. Platform counteraccounts never authorize another merchant account. financial_templates defines debit/credit purposes and version; ledger_accounts defines currency, owner, class and purpose. Changes are controlled database migrations reviewed by an accountant, not a web “edit journal” tool. Posted entries retain exact account IDs and template version even after a subsequent configuration change.

| Operation | Debit | Credit |
|---|---|---|
| Internal capture | Merchant wallet liability | Service clearing |
| Internal wallet refund of capture | Original service clearing | Merchant wallet liability |
| Approved credit adjustment | Adjustment expense/control | Merchant wallet liability |
| Approved debit adjustment | Merchant wallet liability | Adjustment income/control |
| Full reversal | Exact opposite sides of original entries | Same original accounts/amounts |
| Synthetic test funding | Explicit test clearing | Merchant wallet liability |

Reservations and releases are encumbrances only; they create hold events, not revenue. Service clearing avoids claiming final revenue recognition before accountant signoff. Revenue, VAT/tax, principal-versus-agent treatment, carrier expense/payable, PSP clearing/fees, bank settlement and fiscal period policy remain unapproved. No carrier or payment settlement is created in Phase 4. COD is a separate future liability, never wallet funding.

Posted B is liability credits minus debits. Outstanding H sums original−captured−released for all holds, including unknown/protected holds. Available A=B−H must remain nonnegative. Capturing x reduces both B and H; release x reduces H only; wallet refund increases B and does not reopen the hold. Multiple refunds cannot exceed original capture. Full reversal is unique and blocked for a refunded original, a refund journal, an already reversed journal or a reversal itself. Any wallet-debit reversal must preserve available>=0.

All monetary commands are bounded to 1,000,000,000,000 minor units per command as a conservative technical safety cap, not an approved operational balance/transaction limit. There is no credit facility. No balance backfill, fictional opening entry or real-value funding occurs at migration/provisioning.

## Hard gates

`config/finance.php` hardcodes corrections_enabled=false and provider=disabled. No environment toggle enables payments. Normal UI can request/review a correction, but cannot execute money corrections until a separately reviewed code/config change after accounting/operational acceptance. Tests alone enable corrections in their isolated application configuration. There is no merchant top-up, withdrawal, payout, payment callback or shipping-charge endpoint. DisabledPaymentProvider is the only application binding; SyntheticPaymentProvider is under autoload-dev Tests and refuses every DB except testing/clicktoship_test. SQL synthetic commands additionally refuse databases other than the dedicated test/E2E DBs. E2E wallets contain zero money, not simulated payment receipts.

Approval payload/reason is immutable. Maker and checker need separate users, active sessions, recent MFA, target-specific independently approved merchant grants and finance.correct. No platform own-merchant corrections, bearer-only administration or Super Admin bypass. Financial approval expires after 15 minutes from request. Authority is rechecked under user/session locks immediately before execution. Adjustments additionally require a matching independent approval inside the DB command. All corrections require the HTTP approval workflow; internal reserve/capture/release/refund/reversal services are trusted module use cases, not customer APIs.

## Decisions still requiring qualified review

Saudi payment-services/stored-value applicability, safeguarding/ownership and custody of funds; fiscal entity and chart/revenue/tax rules; enabled countries/currencies and financial limits; refunds versus external payouts; chargebacks/debt/negative adjustments; suspended/closed merchant corrections; purpose-specific reservation TTLs; evidence and independent approval policy for resolving unknown carrier outcomes; accounting period close/backdating; statement/export and retention requirements; bank/PSP statement contracts and daily operational signoff; recovery and monitoring SLAs.

Unknown/protected outcomes cannot be automatically released, expired or manually marked resolved in Phase 4. There is no carrier evidence source or approved external resolution contract. Structural reconciliation records do not prove carrier rejection. That capability remains deliberately blocked; later implementation must link authoritative external evidence, immutable reconciliation decision and independent approval before changing a quarantined hold. No arbitrary expiry default or unsafe “force release” exists.
