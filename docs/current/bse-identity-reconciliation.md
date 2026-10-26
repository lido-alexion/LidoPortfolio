# BSE missing-identity reconciliation

Tracked under defect #283. This is a bounded security-master metadata repair, separate from ordinary stocks:sync lifecycle or price-history repair.

## Preview

    php artisan stocks:reconcile-bse-identities --json

Preserve the complete JSON plan. Preview performs one official active-equity source fetch plus current-master reads; it never writes stocks, prices, holdings, lifecycle state, sync runs or receipts.

The source is constrained to the official HTTPS BSE active-equity API. The plan binds normalized scrip code/symbol/ISIN and Active source scope, plus current master identity/eligibility fields. Version 2 uses semantic JSON hashes so native MySQL object-key normalization does not invalidate retained evidence. Volatile names, market-cap values and unrelated timestamps do not invalidate an otherwise identical plan. Source/master row order does not affect its SHA-256.

Only an effectively active, non-benchmark BSE stock with an absent ISIN, unique existing scrip code and exact official symbol may be proposed. Official and existing-master ISIN collisions, ambiguous codes, symbol changes, nonempty ISIN conflicts, unavailable source identities and inactive/admin-deactivated records cannot be silently repaired. Outcomes retain reason codes rather than interpreting a missing active-master row as delisting.

## Impact assessment and apply

Before applying a large plan, use its targets to inspect a projected stock collection against the bounded recent bhavcopy window **without saving projected stocks**. Filling previously-null ISINs enables stricter bhavcopy mismatch detection. A new identity match is not authority to insert unadjusted pre-split bars; historical identity/action issues stay with the existing price-history and corporate-action owners. Confirm the canonical scope is unchanged and record newly visible source/adjustment conflicts.

Apply only the reviewed current plan:

    php artisan stocks:reconcile-bse-identities --apply --expected-plan=PLAN_SHA256 --json

Apply requires enabled sync-log retention. Source acquisition completes before taking the 300-second universe writer database-cache lease. The command requires the lock and stock writes to share one database connection, fences the actual cache-lock row with FOR UPDATE through commit/rollback, reacquires current stock identities in stable order, and recomputes the exact plan. It checks owner/expiry and a 120-second mutation budget before writes and completion; source/master inspection is bounded to 20,000 rows and apply to 5,000 targets. A source or identity/eligibility revision requires a fresh preview. Each update also atomically requires the exact BSE ID/code/symbol, active/non-admin/non-benchmark state and still-absent ISIN. Required plan/change audit records and successful run completion are verified durable inside the same transaction as the identity updates. Silent no-op logging, a missing completion or any refused target/audit failure rolls the entire plan back; failure-status cleanup cannot mask the original error. Accepted plan/source/master hashes and per-stock old/new identity evidence are recorded through the existing stock-master sync-log owner.

Only ISIN and its normal model modification timestamp change. No new stocks are inserted; names/provider symbols/IDs/activation/admin overrides are preserved. No dual-listed repair, price deletion/fetch, holdings remap, lifecycle change, ignored gap or eligibility-policy change occurs. Preview again after apply: successfully reconciled rows become already_identified with zero proposed rewrites.

The cache-row database fence protects the daily universe owner even if a process pauses: a new owner cannot replace the lease row until this transaction commits or rolls back. The code does not assume that elapsed TTL alone grants authority, renew a lost lease, or alter session/database timeout policy. Ordinary stock-master edits remain authoritative; a committed identity or admin change after preview invalidates the plan, and current row locks/conditional updates prevent overwriting it. This command does not broaden or replace ordinary stock-master scheduling.

## Durable provenance and retention

The additive StockMaster-owned portfolio_bse_identity_audits ledger retains the accepted full plan JSON, applied-result JSON, plan hash, fresh-source acquisition time and application time in the same transaction as the identity changes. It is append-only through the owner and has no foreign key/cascading dependency on prunable SyncRuns. Version-2 semantic hashes remain verifiable after native JSON object-key normalization. The command returns audit_id for the durable record.

At the 26-October qualification, existing SyncLog retention was configured to **seven days** (the existing setting permits 0–90). That policy is unchanged. The durable ledger is not automatically pruned; deleting historical identity provenance requires a separately reviewed owner decision. Failed/refused attempts remain in the existing operational run evidence, while successful metadata mutations retain their full reviewed/applied provenance independently of operational log rotation.

The new migration has JSON columns without defaults and an explicit short index name. Run the repository migration-portability verifier and canonical hosted MySQL tests before release.

## Remaining data availability

This metadata operation cannot close the 142 history-availability cases by itself. Listings, non-traded sessions, suspensions, delistings and fund/unit eligibility need the dataset-specific authoritative evidence required by DATA-003. A complete exchange file without a security's row is still not proof of delisting or permission to fabricate an OHLCV bar. If the official source is unavailable, acquisition fails closed; an old saved snapshot is diagnostic evidence, not authority for a fresh production apply.
