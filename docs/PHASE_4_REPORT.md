# Phase 4 implementation report — wallet and financial ledger

Implemented on `feat/phase-4-wallet-ledger`, based on verified Phase 3 PR #2 merge `465d692c2251e146f0966ea0946742254d8d13b0`. Existing commits/code are preserved. No main merge, production deployment, Phase 5, real funding/settlement/withdrawal/payment provider or carrier charge.

## Implemented components

- WalletBilling modular application/domain/infrastructure/HTTP boundary. SAR alone enabled; currency/exponent catalogue supports a multi-currency design without FX. Money uses integer minor units and exact JSON strings; frontend parses/formats strings without Number rounding. Opening a wallet creates zero balance and no journal.
- Immutable double-entry journals/entries with configurable migration-reviewed chart/posting templates. Per-transaction/currency balance, at least two positive entries, immutable ownership/history, current-transaction-only entry insertion and deferred projection checks in PostgreSQL. Captures use provisional service clearing, not unapproved revenue/tax recognition.
- Posted/reserved/available balances with no overdraft. Internal reserve, partial capture/release, expiry command, protection/unknown markers, bounded partial capture refunds and unique exact reversal commands. Refunds debit the original capture counteraccount, not a dynamically reinterpreted old price. Refunded/reversed/dependent postings cannot be reversed again; debit corrections preserve available>=0.
- Permanent business-operation and request-key idempotency with canonical payload hashes, locked transitions, atomic journal/hold/projection/audit/outbox commit and bounded database-concurrency retries. Replays never create another monetary effect; changed payloads conflict.
- Immutable financial outbox with a transactional local finance-monitor consumer/receipt. `finance:outbox` is scheduled every minute. This is not a claim of exactly-once external publication or a shipping/payment consumer.
- Structural reconciliation records, immutable discrepancy events and wallet freezing. `finance:reconcile` is scheduled daily. No arbitrary balancing plug, automatic projection repair or external settlement claim.
- Restricted target-authorized financial administration: additional merchant access grant capabilities, recent MFA, browser CSRF, independent maker/checker correction approval, immutable payload/reason, expiring session authority, version checks and user/session locks against concurrent security changes. No own-merchant correction or Super Admin bypass. Normal correction execution remains disabled pending qualified accountant review; enabled only inside isolated tests to verify the complete engine.
- Runtime binds DisabledPaymentProvider only. SyntheticPaymentProvider is under Composer autoload-dev Tests, requires testing/clicktoship_test and uses explicitly synthetic intent/event rows. SQL synthetic credit also refuses ordinary databases. No merchant-facing funding or payment callback route exists. Browser fixtures remain zero-funded.

## Migrations

1. `2026_10_23_060000_create_wallet_ledger` installs 21 tables plus controlled SQL entry points/triggers/privileges: financial_currencies; ledger_accounts; wallets; wallet_balances; financial_operations; financial_request_keys; financial_templates; ledger_transactions; ledger_entries; balance_holds; balance_hold_events; wallet_transactions; financial_refunds; financial_approvals; financial_approval_events; financial_outbox; financial_outbox_deliveries; financial_reconciliation_runs; financial_case_events; payment_intents; payment_events.
2. `2026_10_23_070000_financial_permissions` adds granular built-in-role financial grants while preserving existing IDs/assignments, and extends the database/application target-access capability allowlist.

Composite ownership/currency relationships, positive amounts, cumulative reservation limits, nonnegative available balance and immutable journal/event triggers are enforced in PostgreSQL. cts_finance_runtime is a NOLOGIN capability role; SET ROLE tests prove direct monetary writes are denied while the constrained command can execute. Development still uses its existing DB owner; complete production login/function-owner provisioning is a launch gate, not an implemented deployment.

Rollback/reapply on guarded PostgreSQL fixtures preserves Phase 3 identities/memberships and role IDs. Posted history/retained financial grants deliberately prevent normal downgrade. Only disposable testing/clicktoship_test teardown permits deletion of synthetic finance tables. Phase 3 rollback regression now removes the two additive Phase 4 migrations first, preserving all original Phase 3 checks.

## Accounting and reservation rules

B=posted liability credits−debits; H=sum(original−captured−released); A=B−H>=0. Reservation/release has no journal. Capture x reduces B and H by x. Refund x increases B and does not reopen a hold. Currency never changes during an operation; no implicit conversion or mixed-currency totals. Internal command cap is 1,000,000,000,000 minor units as a technical bound, not an approved business limit.

Every hold takes an explicit expiry from its trusted use case; no business TTL is silently chosen. Protected/unknown holds cannot be released/expired merely because time passed. Unknown outcomes stay blocked until a future approved evidence-based reconciliation contract exists; structural ledger matching is not carrier-rejection evidence. No unsafe force-release operation was exposed.

Exact assumptions, chart templates and accountant/regulatory gates are in [finance/ACCOUNTING.md](finance/ACCOUNTING.md). Locking, idempotency, outbox, privilege and recovery behavior is in [finance/OPERATIONS.md](finance/OPERATIONS.md).

## APIs

22 new operations; 90 total documented OpenAPI operations, with resolving references, unique operation IDs and explicit security. All paths below are under `/api/v1`:

| Methods | Path | Purpose |
|---|---|---|
| GET, POST | /wallets | Scoped list / authorized empty SAR wallet provisioning |
| GET | /wallets/{wallet} | Posted/reserved/available, exponent/version/freeze state |
| GET | /wallets/{wallet}/transactions[/{entry}] | Scoped sequence-paged history/detail |
| GET | /wallets/{wallet}/holds[/{entry}] | Reservations and immutable detail timeline |
| GET | /wallets/{wallet}/statement | Opening/movements/closing through fixed sequence cutoff |
| GET | /admin/merchants/{merchant}/wallets | Exact-grant target financial read |
| GET | /admin/merchants/{merchant}/wallets/{wallet} | Target balance |
| GET | /admin/merchants/{merchant}/wallets/{wallet}/transactions[/{entry}] | Target history/detail |
| GET | /admin/merchants/{merchant}/wallets/{wallet}/holds[/{entry}] | Target reservation history/detail |
| GET | /admin/merchants/{merchant}/wallets/{wallet}/statement | Target statement |
| GET, POST | /admin/merchants/{merchant}/financial-requests | Scoped requests / immutable correction request |
| POST | /admin/merchants/{merchant}/financial-requests/{approval}/approve | Independent current authority approval |
| POST | /admin/merchants/{merchant}/financial-requests/{approval}/execute | Guarded one-time execution; disabled normally |
| POST | /admin/merchants/{merchant}/financial-requests/{approval}/revoke | Versioned revocation before execution |
| GET, POST | /admin/merchants/{merchant}/reconciliation | Immutable structural runs / run under wallet lock |

No public reserve/capture/release/funding/payout/carrier-charge route. Foreign IDs404; stale versions/payload/SQL domain conflicts409; permissions/MFA/disabled execution403. Monetary DTOs are currency-specific minor-unit strings. Session mutations preserve CSRF and financial responses are private/no-store. Detailed history/statement permissions intersect current merchant/token scope.

## Frontend

Two new EN/AR route screens, `/[locale]/wallet` and `/[locale]/admin-finance`, with wallet/currency selection, distinct balances, hold details, transaction history, paged statement cutoff, grant selection, immutable correction review/approval/revocation and reconciliation history. Accessible labels, document RTL/LTR and LTR-isolated exact money/IDs. Existing navigation exposes the wallet only under its merchant permission; finance target grant choices are integrated into the existing access-request screen. Funding/shipping charges remain unavailable and normal correction execution is visibly disabled.

## Verification evidence

Results are local execution, not a claim about GitHub-hosted CI:

| Check | Result |
|---|---|
| Full backend on real PostgreSQL/Redis | 136 passed, 1266 assertions; all prior suites included |
| Separate-process finance concurrency/security races | Passed: reserve overspend/duplicate, competing captures/refunds, capture-vs-release, merchant suspension, MFA/credential/approval revocation |
| Actual process exit recovery | Passed before outer commit (rollback) and after commit/lost response (one replayed effect) |
| PostgreSQL integrity/runtime-role checks | Passed; journal balance/immutability/late append, projections, owner/currency relationships and denied direct monetary writes |
| Migration rollback/reapply | Passed on guarded fixtures; normal posted-history downgrade refused |
| Frontend component/client | 22 passed |
| Python installer integrity | Four passed |
| Composer strict validation/advisory audit | Passed; no security advisories |
| npm production dependency audit | Zero vulnerabilities |
| ESLint / TypeScript | Passed |
| OpenAPI | 90 operations, 22 added, unique IDs and internal references valid |

Browser repetition, final production build/Pint/runtime restoration and Git delivery results are appended after their completion.

The first browser cycle had 9/10 passes: the new finance journey shared a merchant fixture with the existing invitation journey and consumed its per-user request budget. Dedicated finance owner/admin fixtures now preserve independent budgets without weakening production limits. A subsequent cycle exposed that retained test organizations exceeded the first 25 directory rows: tests now navigate the real pagination before selecting/reviewing the exact organization. No timeouts/delays/retries were increased, tests skipped or assertions removed. SQL errors caught during development were corrected and rerun; failed rounds are not counted as passes.

## Remaining gates and limitations

- Qualified accountant approval for chart/revenue/tax/carrier liability/settlement, fiscal periods, financial limits and corrections; legal/regulatory/safeguarding/retention review. Normal corrections are disabled at application and ordinary-database adjustment boundaries; changing config alone cannot activate a real payment provider.
- No PSP/bank/carrier integration or sandbox validation, real funding/settlement/withdrawal/external refund, COD, pricing, invoices, FX or shipping eligibility activation.
- Unknown/protected reservation resolution intentionally unavailable without an approved authoritative evidence/reconciliation workflow. Structural discrepancies freeze activity; repair/unfreeze requires an approved recovery policy. No force release or arbitrary journal editing.
- Outbox currently has a durable local monitor consumer, not external delivery. Reconciliation is ledger/projection structural matching, not bank/PSP reconciliation. Full production alerting, performance SLO/load evidence and backup/PITR restoration drill remain launch work.
- Hold detail timelines are bounded to 100 entries. Administrative API collections are cursor-paged at 25; initial finance review UI shows the first 25 requests/runs. Larger operational-history browsing can be extended independently; no financial history is deleted.
- Restricted production application/function-owner privilege provisioning remains a launch gate. Current development Compose is not a production deployment. Accounting/regulatory compliance is not established merely by automated tests.

[PHASE_4_FILES.txt](PHASE_4_FILES.txt) records the complete delivery file manifest. Branch/commit/push/PR outcome is reported after Git operations; no automatic merge/deployment/Phase 5.

## Final delivery verification

Three complete ten-test Chromium cycles passed after fixing fixture isolation and actual directory pagination: 30/30 executions, retries0, original timeouts. The final complete backend run passed 136 tests/1266 assertions on PostgreSQL/Redis, including actual process-exit recovery and new financial authority races. Frontend 22 and installer 4 passed. Pint (122 PHP files), ESLint, TypeScript, Next.js production build, Composer strict validation/advisory audit and npm production audit passed. All 90 documented operations match constrained Laravel routes, including the 22 Phase 4 additions; internal references and operation IDs are valid.

Normal development had zero financial operations and zero financial access grants before safe rollback/reapply of the two new migrations. Both reapplied successfully, preserving existing Phase 0–3 data. Normal API/worker configuration was restored after E2E; PostgreSQL/Redis/schema and EN/AR smoke checks pass, all migrations are applied and Horizon is running. Development wallet/financial history remains zero-funded. Build verification was performed with the development web process stopped to prevent .next races, then normal service was restored.

Delivery manifest: 56 files. No dependencies were added, credentials committed, tests skipped, production deployment performed or Phase 5 started. Commit, push and GitHub PR/check access are reported separately after Git operations.

## PR #3 CI follow-up

The delivered commit had a Pint comma-spacing error and a browser helper that could decide to paginate before the first directory page loaded. Fresh single-page CI data exposed the browser race; a retry then reused a consumed MFA backup code after worker restart. These issues were reproduced before editing and corrected without changing financial business logic, permissions, rate limits or timeouts. See [PHASE_4_CI_FIX_REPORT.md](PHASE_4_CI_FIX_REPORT.md) for evidence, changed files, regression tests and new verification results. Its follow-up results supersede the earlier delivery's CI-readiness claims.
