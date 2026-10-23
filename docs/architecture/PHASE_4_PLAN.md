# ClickToShip Phase 4 — Wallet & Financial Ledger: proposed implementation plan

Historical planning snapshot, subsequently approved with SAR as the sole initial enabled currency and real-money operations disabled. The user's implementation approval overrides proposal defaults. See docs/PHASE_4_REPORT.md for implemented behavior and actual verification.

## 1. Verified baseline and evidence

- Repository: Jendou01/clicktoship.net. Fetched remote main explicitly and fast-forwarded local main without modifying application files.
- Main: `465d692c2251e146f0966ea0946742254d8d13b0`, merge of PR #2; parents `b48eb7171ab5971b1a0583d383b6b4a242ca62d3` and `dd025faddaaada85d7df1901be9b8c738d7c24c9`.
- Git ancestry confirms the delivered Phase 3 commit is contained in main. Working tree/index clean after update. No new commit, branch or push for this planning task.
- Reviewed AGENTS.md; Technical Blueprint v1.0; architecture, decisions, open questions, schema, roadmap, API design, security/testing documents and Phase 3 implementation report. Reviewed current role catalogue and platform MFA/security implementation as compatibility checks.
- GitHub GraphQL remains Forbidden; Git transport independently verifies the actual merge commit. No claim of a new hosted CI run.
- Phase 3 report records 108 backend tests/999 assertions, 17 frontend tests, four installer tests and three eight-test browser runs. These are historical results, not tests rerun for this planning task.
- Blueprint sprint numbers differ from the canonical roadmap. This plan follows roadmap Phase 4 only; pricing, carriers, shipment creation, COD settlement, invoices and FX remain outside scope.

## 2. Scope, deliverables and safety boundary

Implement a WalletBilling module in the existing Laravel modular monolith with PostgreSQL as monetary authority. Identity retains credentials/RBAC; Merchants retains tenant lifecycle/context; Operations orchestrates separately authorized administrative work. Next.js consumes scoped DTOs. Redis handles queues/rate limits only, never balances or monetary locks.

Deliver currency-specific wallets, immutable double-entry journals, auditable reservations, internal capture/release/refund/reversal commands, approved manual-adjustment workflows, reconciliation cases, idempotency/outbox infrastructure, a disabled payment-provider contract and bilingual read/review screens.

Normal merchant wallet top-ups, withdrawals and shipping charges remain disabled. There is no live provider implementation, callback route, payment redirect or real-money initiation. Synthetic funding/capture exist only through guarded test fixtures/internal test adapters, never a browser-accessible debug endpoint. Normal installations create no funded balances or artificial opening entries. All existing merchants start with zero financial history; create wallets lazily through an authorized service or an explicit zero-balance provisioning step after enabled currencies are approved.

Business verification is separate from account status and financial eligibility. Email verification, a wallet balance or a business approval alone never enable shipping. Phase 3 shipping eligibility remains fail-closed until later pricing/carrier/contract capabilities exist. Suspended/closed merchants cannot initiate financial mutations. Financial records and pending obligations persist; restricted finance review/reconciliation must remain possible under explicit grant, with any corrective posting subject to a separate approved policy.

## 3. Money, currencies and accounting model

- UUID identifiers; UTC timestamptz; integer minor units plus ISO 4217 currency and immutable exponent snapshot. No floats, inferred conversion, FX endpoint or totals summing different currencies.
- API amounts are canonical integer decimal strings, e.g. `"12500"`, currency `"SAR"`, exponent 2. This avoids JavaScript precision loss. Reject exponent fractions, scientific notation, negative unsigned amounts, unknown codes and values above a finance-approved operational cap. Parse display input as strings; checked integer arithmetic in PHP; SQL SUM(bigint) as numeric to avoid overflow before range checks. Never cast through float.
- A wallet is unique per merchant/currency. Merchant preferred currency is presentation only; it is not an FX instruction or authority to move funds across currencies. Currency enabling is explicit, not “all ISO codes accepted.” Support exponent 0/2/3 in tests (JPY/SAR/KWD); activation list requires approval. Currency exponent cannot change underneath history.
- Each journal uses one currency and at least two positive entries. Sum(debit)=sum(credit). Cross-currency balancing is prohibited. No backdated journal alteration; record effective_at and posted_at separately, with posting-period rules approved by Finance.
- The merchant wallet is an operator liability, not revenue. Its posted balance is credits minus debits on the dedicated merchant liability account.

### Proposed chart and postings — subject to accountant approval

| Event | Debit | Credit | Phase 4 behavior |
|---|---|---|---|
| Confirmed funding | PSP clearing asset | Merchant wallet liability | Contract/test vectors only; no live funding |
| PSP settlement | Bank asset, PSP fee expense as approved | PSP clearing asset | Reconciliation design only; no bank/PSP calls |
| Reservation/release | None | None | Operational encumbrance, not revenue |
| Confirmed service capture | Merchant wallet liability | Approved service clearing account | Test/internal command; shipping disabled |
| Later service recognition | Service clearing | Revenue/tax liabilities | Deferred to approved pricing/tax/accounting policy |
| Wallet refund of captured service | Original capture counteraccount(s) | Merchant wallet liability | Bounded compensating journal, not external payout |
| Correction of erroneous journal | Exact original opposite sides | Exact original opposite sides | New full reversal journal, never edit original |
| Approved positive adjustment | Explicit adjustment expense/control account | Merchant wallet liability | Dual-controlled, no arbitrary counteraccount from client |
| Approved negative adjustment | Merchant wallet liability | Explicit adjustment income/control account | Must preserve available >=0; no hidden debt |

The repository conceptual schema illustrates direct revenue/tax recognition on shipment charge. This proposal uses service clearing until recognition/tax policy is signed off. That is a proposed accounting decision, not an implemented change to ADR-005. If Finance chooses direct recognition, replace this posting template and its reversal/refund mappings before implementation acceptance. COD remains a separate future liability and must never credit the spendable wallet implicitly.

## 4. Balances and reservations

For a wallet: B = posted liability credits − debits; H = sum of reservation remaining amounts for active or quarantined holds; A = B − H. Enforce B >= H >= 0 for prepaid wallets; no overdraft/credit facility. Show posted, reserved and available separately in API/UI. A UI request is a snapshot, never spending authorization.

Reservation fields: original amount Q, cumulative captured C, cumulative released R, remaining H_i=Q−C−R; all nonnegative and C+R<=Q. Captured money is no longer reserved. Refunds credit posted balance without reopening the original hold.

Proposed reservation lifecycle:

- Create: require A>=Q, reserve Q, store purpose/reference/version/expiry and immutable hold event.
- Capture x: require 0<x<=remaining and approved final charge evidence; post debit liability, reduce remaining by x and increment captured. Under the same transaction both B and H fall by x; available is unchanged.
- Release x: require 0<x<=remaining; reduce H and increment released, no journal, available rises by x.
- Partial capture/release supported, each command with its own permanent operation ID. Terminal status derives from zero remaining and totals: captured, released or mixed/closed. No amendment of original amount; release and create a new reservation when authorized.
- Cancellation before dispatch can release remaining only; it does not refund captures. Refund is a separate operation against the captured posting.
- Future external dispatch atomically marks a hold protected/in-flight before network transmission. Timeout/crash after dispatch becomes unknown/quarantined, remains reserved past nominal expiry and cannot be released by a generic expiry job.
- Expiry worker may release only provably undispatched/unprotected reservations. It rechecks state/time/version under lock and records an immutable reason. Unknown work requires evidence-based reconciliation and explicit approved decision, never automatic retry/release.
- TTL is configurable per purpose after Finance/Operations approval; no arbitrary default imported into production. Protected/unknown reservations escalate by age but do not silently expire.

Example in minor units: B=10000, H=0; reserve6000 => A4000; capture4000 => B6000,H2000,A4000; release2000 => B6000,H0,A6000; refund1000 => B7000,H0,A7000. A second currency is an independent wallet.

## 5. Refunds, reversals, adjustments and reconciliation

### Refund

A service refund references a posted capture, merchant, wallet and original currency. Multiple partial refunds are allowed only while total posted refunds plus pending approved allocations <= captured refundable amount. Lock the original capture/allocation aggregate; no parallel refund can exceed the cap. Refund is a new journal using the approved original allocation, with reason/approval/reference. It does not erase service history, rewrite taxes or send money to a bank/card. External withdrawal/funding refund is explicitly excluded until provider, compliance, negative-balance and chargeback rules exist.

### Reversal

A full correction reverses the exact original entries and links `reversal_of_id` uniquely. A reversal cannot itself be directly reversed in Phase 4; a new independently reviewed corrective workflow is required. Reject reversal if dependent refunds/settlements exist, or require a documented correction chain—never blindly add a second credit. Any reversal that debits a wallet must still respect reservations/no-overdraft. Impossible corrections open reconciliation cases; do not force a negative balance or destroy a hold.

### Adjustment and approval

No “set balance” endpoint. Request a signed-direction amount, approved reason code and evidence reference; counteraccount is mapped server-side. Proposed conservative default: every adjustment and financial correction requires a different active MFA-authenticated authorized approver, regardless of amount. Threshold exceptions require a subsequent explicit policy decision.

Financial approval is distinct from the existing merchant access grant. Bind it to an immutable payload digest, target merchant/wallet, currency, amount, operation and expected version; expire after a proposed 15 minutes (requires approval). No self-approval, role-combination workaround, approval after alteration or token-authenticated admin operation. On execution revalidate both parties/current permissions, active sessions/MFA versions, matching target access grants and unexpired financial approval. Approval approves one atomic execution; retry returns the existing result. Revocation/demotion/suspension invalidates outstanding authority. Approval itself never changes money.

### Reconciliation

1. Scheduled read-only structural reconciliation at a fixed database snapshot: journals balance, wallet journal balance matches projection, hold totals match projection, no orphaned operations/refunds/outbox references.
2. Compare provider/clearing/bank statements only when a real provider/import contract is later approved. Phase 4 uses synthetic normalized statement fixtures; do not add document uploads or claim bank reconciliation coverage.
3. Reconciliation runs have per-currency cutoffs, counts/totals, source digest, status and immutable findings. Late data produces a later run, not an edited historical result.
4. Unmatched/duplicate/amount/currency/status mismatch creates an assigned case with evidence and immutable decision history. Marking resolved requires a linked corrective posting or explicitly justified no-posting decision, independently approved where financial.
5. Projection discrepancy freezes new financial writes on affected wallets; ledger is authoritative, caches cannot repair journals. Rebuild caches from journals/hold events under exclusive wallet lock with audit and before/after comparison. Unbalanced journals trigger global finance fail-closed and incident escalation. Never auto-post “plug” adjustments.
6. Alert on oldest unresolved case, aged quarantine, projection drift, clearing mismatch, outbox backlog and repeated posting denial. Finance owns daily signoff/runbooks; thresholds/SLAs remain approval items.

## 6. Proposed PostgreSQL tables and constraints

All tenant child relations include `(merchant_id, id[, currency])` candidate keys and matching composite FKs. RESTRICT historical parent deletion; no financial cascades. All monetary/state columns relational, not opaque JSON. Add indices `(merchant_id,currency,created_at,id)`, wallet/status holds, original posting allocations, pending reconciliation/outbox readiness and unique operation references.

| Table | Essential fields and invariants |
|---|---|
| financial_currencies | code PK, exponent, enabled/version; exponent immutable once used |
| wallets | UUID, merchant, currency, liability_account; UNIQUE merchant/currency; version, state; composite account ownership/currency FK |
| ledger_accounts | UUID, owner scope merchant/platform, merchant nullable only for platform, purpose/class, currency, normal side; scoped uniqueness with partial indexes/NULLS NOT DISTINCT; immutable ownership/currency |
| financial_operations | UUID, merchant, kind, permanent business key, payload digest, currency, actor/source, result reference, committed_at; UNIQUE merchant/kind/business key |
| ledger_transactions | UUID, operation UNIQUE, merchant, currency, posting type/template version, posted_at/effective_at, reversal_of FK/unique; immutable posted row |
| ledger_entries | UUID, journal/account, merchant transaction context, currency, debit/credit, positive bigint amount; composite journal/currency and account/currency/owner relationships; balanced journal enforced at commit |
| wallet_balances | wallet/merchant/currency composite identity, posted/reserved/version; CHECK posted>=reserved>=0; updated solely by financial DB posting/reservation functions; rebuildable projection |
| balance_holds | UUID, merchant/wallet/currency, unique operation, Q/C/R, purpose/reference, protected/unknown marker, expires_at, version; CHECK Q>0,C>=0,R>=0,C+R<=Q |
| balance_hold_events | immutable reservation/capture/release/quarantine/expiry events, command operation UNIQUE, hold composite FK, amount, reason, linked journal if capture |
| wallet_transactions | immutable merchant-safe journal projection, wallet sequence unique, signed delta, posted/reserved/available-after, currency, origin; not an independent balance source |
| financial_approvals | immutable request payload digest, maker/session/target, expected version, reason/evidence; append-only approval/revoke/execute events; separate versioned current state; maker != checker |
| refunds | original capture composite FK, requested/posted amount, currency, operation, approval, state/version; sum cap enforced under capture/allocation lock and by controlled DB entry point |
| payment_intents | merchant/wallet/currency, provider/environment, amount, internal/external refs, state/version; no enabled production provider |
| payment_events | immutable provider/environment/event_id unique, digest, normalized outcome/time, processing reference; same ID/different digest is conflict, not success |
| reconciliation_runs/items/case_events | cutoff/source digest, per-currency totals, immutable findings and case action history; versioned assignment/status separate |
| idempotency_keys | merchant or explicit platform actor scope, operation/key hash unique, canonical request digest, bounded response reference/status; no secrets in raw cached response |
| outbox_events/outbox_deliveries | immutable event payload/id, schema version, merchant, aggregate/sequence; separate mutable delivery retry/lease fields and consumer dedupe unique event/consumer |

Platform counteraccounts must not weaken tenant FKs: a journal has exactly one merchant context; each entry must address either an account owned by that merchant or an approved platform-purpose account in the same currency. A database posting function/constraint trigger rejects any other merchant account and disallowed counteraccount/template. Merchants never choose ledger account IDs. No direct merchant-to-merchant transfer operation.

### Database enforcement and privilege design

- Use a narrowly granted posting/reservation function to atomically insert journal, entries, operation, projections, hold changes, audit and outbox in one caller transaction. It validates templates, source state, currency, amounts, ownership and all balance/cumulative caps. No generic public SQL “post arbitrary journal” API.
- Deferred constraint triggers independently check at commit: journal has >=2 entries, per-currency debit=credit, account ownership/currency, one declared wallet effect and matching projection/hold invariants. Trigger checks must include journal INSERT so an empty journal cannot commit. Concurrent predicate checks use the same wallet/original-operation locks; a deferred trigger alone does not serialize competing refunds.
- Block UPDATE/DELETE/TRUNCATE of posted journals/entries and financial events, immutable tenant/currency/parent changes, and unauthorized balance/hold mutations. There are no externally visible partially posted drafts. Any approval draft is outside the posted ledger.
- Separate migration owner from non-owner runtime role; runtime cannot create/alter/drop tables/functions/triggers or disable integrity checks, and cannot directly DML monetary tables. Only vetted entry points. Security-definer functions, if needed, use a fixed safe search_path, fully qualified objects, restricted owner, revoked PUBLIC execution and minimal grants. Test using the actual restricted role; superuser-only tests are insufficient.
- DB constraints preserve monetary integrity; HTTP/services still authenticate/authorize. A compromised all-tenant application credential is not claimed to be solved by tenant FKs. RLS is not currently installed; introducing it requires a separate approved tenant-context/pool/admin threat model, not an untested promise.
- Include grants, procedures, trigger and privilege regression tests in migration verification. No account ownership changes on historical accounts.

### Migration sequence (proposed, not generated)

1. Currency catalogue, ledger accounts/wallets/operations and restricted function ownership/grants.
2. Journals/entries and deferred balance/immutability enforcement; zero-balance projections.
3. Holds/events, refunds/allocations, wallet transaction projection and concurrency functions.
4. Idempotency/outbox, disabled payment intents/events, reconciliation and financial approvals.
5. Add granular permissions to existing built-in roles without replacing IDs or assignments; extend explicit platform access capability CHECK allowlist and authorization mappings together.

Rehearse upgrade against populated Phase 3 fixtures, with no balance backfill guessed from legacy data. Rollback/reapply only on guarded disposable PostgreSQL databases. A downgrade must refuse to delete nonempty posted finance history; after first real posting, forward-only corrective migrations are the default. Approve backup/restore and accounting retention before operational use.

## 7. Concurrency, atomicity and idempotency protocol

All mutations use PostgreSQL transactions; no HTTP/provider call while locks are held.

1. Validate syntax/authentication; derive merchant from current authorized context. Admin commands require exact target grant and MFA, never substitute MerchantContext.
2. Start transaction; acquire existing security lock where needed in consistent order, then merchant root (matching Phase 3 lifecycle/member writes). Reauthorize active merchant/member/permission/token or both admin authorities under lock. Canonical order: global security lock if required → merchant roots sorted → affected users sorted where required → wallets sorted UUID → hold/original capture/approval aggregate sorted → accounts/projections sorted → operation records. Audit current Phase 3 locking before freezing this contract; no path may acquire them in reverse.
3. Claim durable idempotency record under uniqueness. An uncommitted competing INSERT waits for commit/rollback; same digest replays safe stored result, different digest409. Retry after rollback may execute. Do not return another actor's result after authorization is lost.
4. Lock wallet/projection and hold/original allocation. Recheck expected version, available balance, approval and transition. Get consistent database time for expiry; distinguish a nominal hold expiry from a protected external obligation.
5. Execute one controlled financial entry point: journal + hold + balance projection + operation result + immutable audit + outbox commit together. No split transaction between debit and reservation change. A failure rolls back everything.
6. Return only committed result. Bounded retries with jitter for PostgreSQL40P01/40001 only, using the same key; never blindly retry a provider call. Instrument contention; merchant-root serialization is an initial correctness choice, optimize after measurement.
7. Outbox worker claims committed events with SKIP LOCKED and expiring leases. Publish at least once; consumers dedupe durable event IDs. Redis loss/restart must not lose financial truth or create extra postings.

Idempotency-Key required for every financial mutation. Scope includes authenticated tenant or explicit admin command scope and operation. Canonical digest includes path target, currency, integer amount, operation kind, reference, approval/version semantics and API schema version. Actor metadata is retained; an unrelated caller never receives a replayed private response. Distinct keys with the same business reference still dedupe permanently. Cache response retention can expire; monetary operation dedupe/unique provider IDs persist for the approved accounting lifetime. A key is never silently reusable to create a new journal.

Payment events can arrive before intents, out of order or repeatedly. Preserve evidence, quarantine unresolved states, transition from verified authoritative evidence only and credit exactly once. Browser redirects, client “success” flags and unverified callbacks are never financial evidence. These scenarios are synthetic contract tests in Phase 4.

## 8. Financial RBAC and tenant access

Do not treat existing broad `wallet.manage` or `platform.finance` as an unrestricted money-write permission. Add explicit permissions through an additive migration; all server policy/token-ability intersections remain effective. Built-in roles only.

| Role | Proposed Phase 4 rights |
|---|---|
| Merchant Owner / Merchant Admin | wallet.read, wallet.transactions.read, wallet.holds.read, wallet.statements.read; no posting/adjustment privileges |
| Merchant Finance Manager | Same detailed financial read/statement rights; future refund-request permission separately gated, not external payment execution |
| Shipping Manager / Operator | No financial history; future narrowly authorized availability check by shipping service, not wallet debit token scope |
| Read-Only Viewer | No financial detail by default; financial read requires explicitly approved built-in-role policy change |
| Platform Finance | financial read/reconciliation/request/approve capabilities, always fresh MFA + target access grant + independent transaction approval where applicable |
| Super Admin | May hold finance permissions but must satisfy identical grants/dual-control; no bypass |
| Platform Operations / Support | No monetary write or private statement access by default; minimal safe case metadata only under approved permission |

Proposed codes: merchant `wallet.read`, `wallet.transactions.read`, `wallet.holds.read`, `wallet.statements.read`; platform `platform.wallet.read`, `platform.finance.reconcile`, `platform.finance.adjust.request`, `platform.finance.adjust.approve`, `platform.finance.refund.request`, `platform.finance.refund.approve`, `platform.finance.reverse.request`, `platform.finance.reverse.approve`. Internal reserve/capture/release authority is an application use-case capability with trusted operation evidence, not a customer-grantable arbitrary debit permission.

Add exact-target grant capabilities such as wallet.view, finance.reconcile, finance.adjust, finance.refund and finance.reverse to the Phase 3 service AND database allowlist. Independent access approval remains by another fresh-MFA Super Admin; independent transaction approval is an additional control. Explicitly reject a merchant member reviewing/correcting their own merchant finances as a platform actor. Grant expiry/revocation and role/session/MFA changes are rechecked on each action; approval/financial authority is never queued as an eternal privilege.

Background financial finalization must distinguish already committed business obligations from new discretionary commands. A posted obligation/outbox event may complete idempotently using a narrow system identity even if the initiating user's session later expires; it must not acquire new spending authority. New admin approvals/mutations always require live authority. Cancellation on merchant suspension is not assumed to erase legitimate liabilities. This policy requires approval before external financial processing.

## 9. Proposed API design

Versioned under `/api/v1`; OpenAPI3.1 for all additions. Cursor pagination, deterministic `(wallet_sequence,id)` order and stable per-currency statement cutoffs; no unbounded lists. Monetary DTOs never include platform counteraccounts, contract costs, PSP secrets or other merchant identifiers. Financial responses no-store; reasons/evidence sanitized and access audited.

| Method/path | Purpose / exposure |
|---|---|
| GET /wallets | Current merchant's permitted wallets; no supplied merchant_id |
| GET /wallets/{wallet} | Posted/reserved/available, currency/exponent/version and cutoff |
| GET /wallets/{wallet}/transactions | Bounded filter/cursor history |
| GET /wallets/{wallet}/transactions/{transaction} | Scoped effect, hold/refund/reversal links |
| GET /wallets/{wallet}/holds; GET /wallets/{wallet}/holds/{hold} | Scoped reservation state/history |
| GET /wallets/{wallet}/statement | Currency-specific paged opening/movements/closing through fixed sequence; not a tax invoice |
| GET /admin/merchants/{merchant}/wallets | Fresh MFA + wallet.view target grant |
| GET /admin/merchants/{merchant}/wallets/{wallet}/transactions | Same, platform-safe authorized view |
| POST /admin/merchants/{merchant}/financial-requests | Typed adjustment/refund/reversal request; no posting yet |
| GET /admin/merchants/{merchant}/financial-requests[/{request}] | Exact-target review/history |
| POST .../financial-requests/{request}/approve | Different authorized reviewer; payload-bound/expiring approval |
| POST .../financial-requests/{request}/execute | Revalidate authority; one atomic approved operation |
| POST .../financial-requests/{request}/revoke | Before execution, versioned/audited |
| GET /admin/merchants/{merchant}/reconciliation-cases[/{case}] | Target-authorized financial findings |
| POST .../reconciliation-cases/{case}/decisions | Audited linked resolution; no arbitrary balance rewrite |
| POST /admin/finance/reconciliation-runs; GET /admin/finance/reconciliation-runs[/{run}] | Authorized structural run/minimized platform summaries; merchant detail still needs target grant |

Reserve, capture, release and external funding are internal application services/contracts with dedicated tests, not general merchant REST endpoints in this phase. No active `/topups`, `/charges`, `/withdrawals` or public PSP webhook. Normal payment capability can report enabled:false through read-only capabilities. If policy approval for real-value admin corrections is pending, keep monetary execution disabled outside isolated testing while delivering review/read/reconciliation capabilities.

Errors:401 invalid credentials,403 permission/MFA/disabled capability,404 foreign-tenant resource,409 stale version/idempotency payload mismatch/state conflict,422 invalid money/currency or insufficient available funds with stable domain code,429 limits. No raw SQL/provider errors. CSRF on session mutations; admin routes browser-session-only; read token scope cannot be upgraded to monetary authority.

## 10. Payment-provider boundary — disabled by default

Define a small provider interface for capabilities, create intent, query authoritative outcome, request refund and verify/normalize event. DTOs carry provider/environment/reference/amount/currency/status and verification evidence, never provider-specific bodies into domain code. Do not implement a provider until real docs/terms/auth/signature/idempotency/status/reconciliation contracts are reviewed.

Provide a DisabledProvider for all normal environments and a deterministic FakeProvider bound only in guarded tests. Fake outcomes explicitly say simulated and include success/reject/timeout/duplicate/out-of-order/conflicting payload vectors. Environment and test-DB guards prevent fixture funding in normal databases. Network egress to payment services is blocked in tests via HTTP fake/stray request protection. A config flag alone cannot expose a fake-money endpoint in production.

Persist durable outgoing intent/reference before any future provider call; unknown initiation/refund outcome means query/reconcile, not blind retry. Verify event signatures against raw bytes/replay policy from the eventual actual contract; do not invent HMAC schemes. Unique provider+environment+event and permanent business operation keys prevent double funding. Never store PAN/CVV/bank credentials; future provider-hosted/tokenized flows require separate PCI/privacy assessment.

## 11. English/Arabic screens

- Wallet overview: independent currency cards with posted/reserved/available, unambiguous signs and “funding unavailable” state; no active top-up/ship-charge control.
- Currency wallet detail: paged transaction list, stable date/kind/status filters and links to reservation/refund/reversal detail. Separate provider outcome, journal posting and hold status.
- Reservation detail: original/captured/released/remaining, expiry/protection/quarantine and immutable timeline.
- Statement view: opening/movement/closing with exact cutoff and currency; no inferred tax invoice or combined cross-currency total. Download/export deferred unless approved secure export scope is added.
- Admin target wallet: grant/MFA status, safe ledger effects, reconciliation cases and immutable history.
- Admin correction request/review: immutable amount/currency/direction/reason, original posting evidence, approval expiry and maker/checker distinction; execution controls absent/disabled until approved operational policy.
- Reconciliation overview/case detail: per-currency totals, run cutoffs, match exceptions, assignment, evidence-linked resolution.

Full EN/AR strings, proper document RTL/LTR, accessible labels/focus/error states, UTC storage with approved local display, currency code always visible. Use a tested string-based minor-unit formatter/parser; never convert bigint strings to Number for formatting. Isolate LTR identifiers/money where needed inside RTL. Preserve all existing screens and permission-gated navigation; frontend hiding never replaces API authorization.

## 12. Required tests and acceptance criteria

### Money/domain and property tests

Known 0/2/3-exponent vectors; integer bounds; bad precision/sign/locale input; no implicit FX; balanced templates; hold algebra; partial capture/release/refund sequences; full reversal allocations; randomized sequences preserve B>=H>=0, debit=credit and refund caps.

### Real PostgreSQL integration and independent-process races

- Raw SQL through restricted runtime role cannot alter/truncate history, bypass entry points, use another tenant account/currency, commit empty/unbalanced journals, mutate protected hold state or DML balances.
- Two reservations of7000 against10000: exactly one succeeds. Two partial captures cannot exceed remaining; capture vs release and expiry vs dispatch are serialized. Refund/refund and refund/reversal cannot double-credit.
- Duplicate same-key/different-key same-reference requests, same-key changed payload, repeated approval/execute, wallet creation races, idempotency wait/rollback and HTTP disconnect after commit.
- Merchant/member suspension or role/token/grant revocation racing a command: authoritative ordering, no stale privilege mutation. Independent maker/checker changes, access expiry and session/MFA revocation.
- Concurrent wallets/currencies never contaminate each other. Background contexts clear after success/failure; a system completion cannot create a new discretionary debit.
- Forced crash before/after journal commit/outbox publish, Redis outage/restart and worker redelivery give one committed operation and eventual one logical consumer effect. Deadlock/serialization retry bounded; no arbitrary sleeps as race proof.
- Statement under concurrent writes retains opening+sum(movements)=closing at the chosen wallet sequence; reconciliation snapshot is consistent.

### Security/functional and payment-contract tests

All built-in role/token matrices; unauthorized/IDOR/mass-assignment attacks; merchant switching; CSRF; MFA and target-grant expiry/revocation; self-approval and own-merchant conflict; sanitized logs/audits/reasons; amount overflow and malformed currency. Fake verified/unverified/duplicate/conflicting/out-of-order provider evidence cannot create extra money. Disabled provider routes cannot fund/charge; fail-closed shipping remains unchanged. Projection drift creates cases/freezes, never silent adjustment. Postings/audits/events immutable; restore/rebuild produces identical journal hashes/totals.

### Frontend, regressions and release checks

Vitest/client tests + Playwright EN/AR journeys: zero-balance wallets, simulated test-only posting displayed, hold/capture/release history, no merchant funding controls, dual-admin review/denial, stale versions, RTL and accessible money formatting. Use isolated E2E PG/Redis/SMTP/Horizon; never seed normal DB.

Run all existing backend/frontend/installer/browser suites, plus new finance suites; repeat complete expanded browser suite at least three times without retries to expose instability. Pin result counts to actual execution, do not predeclare future counts. Pint, Composer validate/audit, ESLint, tsc, production build, npm production audit, OpenAPI references/route coverage, migration upgrade/rollback/reapply and restricted-role verification. Conduct contention/load benchmark and backup/restore rehearsal with recorded hardware/dataset; performance thresholds require approval rather than inventing an SLA.

Acceptance requires: zero overspend, unbalanced journals, tenant leaks, duplicate effects or approval bypass in defined races; immutable/rebuildable history; deterministic same-key replay; no mixed-currency totals; reconciled projections and visible unresolved cases; disabled real funding/shipping/provider access; signed-off accounting templates/policies; regression checks passing with precise evidence. Builds/tests alone do not establish regulatory compliance.

## 13. Proposed execution increments after approval

1. **Policy/ADR and threat-model gate:** confirm chart, permitted corrections, currencies, hold TTL/ambiguity, dual-control and restriction behavior; document blockers per capability. Create `feat/phase-4-wallet-ledger` from then-current verified main only after implementation authorization.
2. **Monetary/database core:** Money type/currency rules, migrations, restricted DB role/functions, journal invariants and raw SQL negative tests.
3. **Concurrency engine:** reservations/partial capture/release, refunds/reversals, durable operation dedupe, outbox and process-race/crash tests. Keep external funding/charges inaccessible.
4. **Tenant APIs/read models:** scoped wallets/history/statement, OpenAPI, role backfill; no broad wallet.manage execution route.
5. **Controlled administration/reconciliation:** extend target grants, payload-bound financial approvals, correction controls and structural reconciliation. Gate uncertain financial policies; continue independent approved work.
6. **Disabled provider boundary and bilingual UI:** deterministic test adapter only; complete EN/AR screens and E2E fixtures.
7. **Final verification/documentation:** schema/architecture/decisions/security/testing/OpenAPI/roadmap/report, complete changed-file manifest, local and CI evidence. Commit/push feature branch, open PR to main when authorized, do not merge/deploy/start Phase 5 automatically.

Exact calendar estimates depend on accounting decisions and test scope; no production schedule implied. A blocked policy disables only that sensitive capability, not independent ledger/test/read work.

## 14. Decisions requiring explicit approval

| Decision | Proposed default / question | Owner / gate |
|---|---|---|
| Legal nature of wallet | Closed-loop service prepayment only; not a bank account, remittance product or withdrawable stored value. Confirm jurisdiction/legal entity and whether this is permitted | Legal/Compliance; real value blocked |
| Ownership/safeguarding | Who legally holds merchant funds; safeguarding/segregated accounts, insolvency and dormant balances | Legal/Treasury; funding blocked |
| Chart/revenue/tax | Approve liability/clearing templates, service-clearing vs direct revenue, VAT/tax credit notes and principal-vs-agent treatment | Accountant; financial acceptance blocked |
| Currency list and limits | Approve enabled codes/exponents, max amounts/balances, min commands; no FX/overdraft | Finance/Product; operational activation blocked |
| Opening balances | Zero by default; any external opening balance requires reconciled import and dual-approved journal, never a guessed backfill | Finance; migration/correction gate |
| Holds | Purpose-specific TTL, partial capture/release policy, quarantine resolution evidence and escalation owner/SLA | Finance/Operations; expiry/ambiguous execution gate |
| Refund vs payout | Wallet-credit refund only; no card/bank payout, chargeback or withdrawal until separate policy/provider | Finance/Legal; external refunds blocked |
| Corrections | All amounts dual-approved; same target-grant rules plus payload-bound15min approval, no self/own-merchant review | Security/Finance; mutation activation gate |
| Suspended/closed merchants | No discretionary spend; retain/freeze obligations, permit scoped review. Specify which corrections/refunds can proceed and by whom | Finance/Legal/Security; special corrections blocked |
| Negative balance/debt | Disallow; insufficient funds correction opens case. Define handling of future chargebacks/post-billing carrier adjustments before integration | Finance/Risk; future charges blocked |
| Accounting periods | Business timezone, effective-date limits, close/reopen controls and whether Phase 4 needs journal-period locking | Accountant; posting date policy gate |
| PSP | Provider/countries/methods, verified event/query/settlement contracts, fees/chargebacks and sandbox access | Finance/Engineering; real adapter blocked |
| Financial RBAC | Approve matrix, financial viewer policy, who can approve requests, segregation beyond independent identities, and transaction authority after actor session expiry | Security/Finance; privileged operation gate |
| Reconciliation | Daily ownership, tolerance (default exact minor-unit match), source of truth, close evidence and escalation targets | Finance/Operations; operational acceptance gate |
| Retention/privacy | Financial/audit retention by jurisdiction, evidence access, exports, immutable backups and deletion conflicts | Legal/Security; production gate |
| Recovery/operations | RPO/RTO, on-call owner, freeze/unfreeze authority, restore drills, restricted DB provisioning and monitoring thresholds | Operations/Security; production gate |

Approval of this technical plan does not authorize a live PSP, merchant top-ups, shipping charges, production deployment or subsequent phases. Those require separate explicit activation approval and evidence. No credentials or sensitive documents should be supplied in chat or committed.
