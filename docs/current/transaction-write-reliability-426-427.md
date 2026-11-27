# Transaction write reliability (#426/#427)

## Write and retry contract

`POST /api/transactions` accepts an `Idempotency-Key` header containing 16–128 URL-safe characters. Receipts are scoped by authenticated actor, active portfolio, and a SHA-256 digest of the key. The canonical request payload is hashed; reusing a key with a different payload returns `409`. The receipt, immutable response metadata, ledger transaction, cash/holding effects, recommendation completion, and database queue job are committed in one database transaction.

The first-party Transactions form retains one pending create key per authenticated user and portfolio in `sessionStorage`. It reuses that key after an ambiguous response only when the submitted payload is unchanged. A changed payload remains blocked until the user reviews the Transactions list and explicitly clears the unresolved request. If browser storage is unavailable, the key remains only in page memory and the UI warns that a reload can lose retry identity. Update (`PUT`) requests do not use this create idempotency contract; their ambiguous result asks the user to review saved evidence.

Replays return the saved transaction representation and recommendation result. The cash summary is recalculated as current portfolio state and may be `null` if the summary read fails; the failure is logged. A transaction deleted after commit leaves its receipt/tombstone in place, and a replay returns `410` without creating another ledger row. Deleting a user or portfolio cascades its receipts with that scope.

Clients that omit `Idempotency-Key` remain accepted for compatibility, but the server generates a one-request key; those callers do not have retry protection and must not retry an ambiguous response automatically.

## Price enrichment and post-commit effects

Provider-backed stock validation runs after the fast receipt lookup but before acquiring the portfolio ledger lock. The transaction rechecks the receipt after locking, so successful concurrent resolution converges on the original commit. If resolution fails while a same-key request has committed, the controller checks the serialized receipt before returning the resolver failure. The ledger lock is therefore held only for local transaction/recommendation validation and the atomic financial write; a slow provider cannot hold the portfolio lock.

Historical price backfill is queued on the database `default` queue inside the ledger transaction. This ensures a successful ledger commit has a durable queue row; if the queue insert fails, the financial transaction rolls back and the failure is logged. The queue and ledger must use the same database connection. The deployed `stoxla-queue.service` consumes `notifications,default`; do not route these jobs to an unconsumed queue name or change production configuration for this fix.

This atomic queue contract applies to user transaction POST/PUT, bulk imports, internal Strategy transfer buys, and manual TOS order execution. `createFinancialUnit` and `updateFinancialUnit` do not queue by default; composing callers must opt in explicitly. `applyAfterCreate` is deprecated and now only recomputes ledger-derived holdings/realizations and invokes post-commit snapshot work; it does not enqueue enrichment. Broker fill reconciliation has a different contract: the external fill and its order bookkeeping commit first, then a buy fill attempts one enrichment enqueue as best effort. Queue failure is logged and cannot make an already executed fill appear failed. A reconciliation with no new fill delta returns before either enqueue path, preventing replay duplicates. Paper simulation and Kite onboarding continue to omit provider enrichment because their existing caller contracts do not opt in.

Snapshots retain their existing synchronous post-commit rebuild behavior and soft-fail semantics. Snapshot and protection failures are logged without changing the committed financial result. Backfill job provider failures are recorded by the job's existing system log path and remain visible to queue retry/failed-job handling.

## Rollout and rollback

1. Run the backend verifier and isolated tests, including the recommendation replay, paper pause, actor/portfolio scope, tombstone, queue dispatch, and browser retry cases. Run the MySQL migration portability gate.
2. Apply the additive receipt migration before serving the new application code. Confirm `portfolio_jobs` is available, the database queue shares the ledger connection, and the existing `stoxla-queue` worker is active and consuming `default`.
3. After release, monitor transaction POST errors/409s, default queue depth and failed jobs, plus the existing transaction and snapshot diagnostics. A queue insert failure is a pre-commit request failure, so the same retained key can be retried safely.
4. For rollback, restore the previous application release while leaving the receipt table and rows intact. Do not run the migration down during rollback: dropping receipts would remove replay protection and tombstones. Remove the table only in a later planned retirement after the old code is restored and the retained records are no longer needed.

No production queue/service configuration change is part of this implementation.

## Local preparation evidence (2026-11-27)

The broker-fill enrichment regression is covered by `BrokerFillEnrichmentQueueIsolationTest`: one test verifies a successful buy fill creates exactly one database `default` queue row and a no-delta replay adds none; the other points only the database queue connection at an unavailable table and verifies the broker fill and order bookkeeping still commit while a regular user write and manual order execution remain atomic. `RefreshDatabase` provides per-test isolation; the fixture does not drop tables or run `migrate:fresh`. The tests are driver-independent and are included in both SQLite runs and hosted MySQL backend verification.

The request identity tests cover successful persistence, unavailable/throwing storage, memory-only retry durability reporting, changed-payload blocking, and isolated module state between tests. No provider, broker, production database, or external integration is contacted by these tests.

Local verification on this worktree: the direct Node smoke check of the request identity module passed; PHP syntax checks for the changed service, execution engine, and broker queue fixture passed; migration portability passed for all 205 migrations; `git diff --check` passed. The exact PHPUnit and Vitest suites could not run because `app/vendor/bin/phpunit` and `app/node_modules/.bin/vitest` are absent. Dependencies were not installed. Hosted CI must supply the full PHPUnit and frontend test evidence.
