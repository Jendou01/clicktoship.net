# Development rules

## Scope and workflow
This repository is a modular shipping SaaS monolith. Read docs/architecture/ARCHITECTURE.md, docs/DECISIONS.md, docs/OPEN_QUESTIONS.md and the relevant module documentation before editing. Use the existing isolated checkout; do not create Git worktrees unless explicitly requested. Work in bounded feature branches, add meaningful tests, run checks, update the roadmap and report what actually executed. Do not deploy or activate live financial/carrier operations without explicit authorization and launch gates.

## Boundaries
PHP 8.3+, Laravel 12, Sanctum, Horizon; Next.js, TypeScript, Tailwind; PostgreSQL is authoritative, Redis is ephemeral. Controllers validate/authorize and delegate to module application services. Carrier DTOs stay in carrier adapters. Module writes go through their owning service; cross-module asynchronous effects use a transactional outbox. Do not add microservices or abstractions without a concrete need.

## Security
Every merchant operation requires authenticated, active membership and permission. Resolve tenant from authenticated context, never trust a merchant_id supplied by a client. Background jobs explicitly restore and clear tenant context. Composite foreign keys must prevent cross-tenant relationships. Platform access is separately authorized and audited; no silent global bypass. Secrets, raw source attachments, .env files, labels, customer data and tokens must never be committed. Hash API tokens; encrypt integration credentials at rest. Log identifiers and error codes, not request bodies or credentials. Never disable TLS/checksum verification.

## Finance and shipping invariants
Use integer minor units with ISO currency/exponent; never float money or implicit currency conversion. Posted double-entry ledger entries are immutable and balance per transaction and currency. Use locks and idempotency for holds/captures/releases/refunds; COD is a separate liability. Persist immutable price snapshots. A wallet charge alone is never carrier success. An ambiguous carrier create result requires reconciliation/manual review, never automatic retry. Retain append-only tracking events and separate raw carrier codes from projections. Unsupported or unverified carrier capabilities must be explicit, never simulated success.

## Tests and release
No production shipment or payment calls in tests. HTTP-fake all external services and block stray requests. Use real PostgreSQL for relational/concurrency tests; SQLite cannot prove locking or isolation. Add negative authorization/IDOR tests when implementing tenant APIs. Test duplicates, retries, out-of-order events and ambiguous failures. Keep unit, integration, component and E2E outcomes distinct. No skipped/zero-test run counts as validation. Missing API docs block that integration only. Run CI commands from README before committing; do not claim a sandbox integration is validated without an actual sandbox test.
