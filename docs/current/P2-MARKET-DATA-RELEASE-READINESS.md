# P2 market-data completeness release readiness

Assessment date: 15 November 2026  
P2 branch: `fix/priority2-coverage-20261113` at `3cda465c50517a6158fa8d4b647f5a0945174ec8`  
Reviewed initially: `origin/master` at `a6c41bfd9ee67c5d03e37fdd49ece947feaf943e`

## Decision

**Code review and focused verification are in progress; release has not been published.** An earlier independent review found synthetic merge tree `7eb925f49c42a675aae761cdb66de1fdfbbd9f65` retained P2 retry/backoff and tests while incorporating master `a6c41bfd` (PR297). A Codex sandbox could not fetch into shared Git metadata, but the owner-authorized supervisor subsequently fetched `origin/master` to `d1e2546bd3b17cfb1a8b555e529a687b7f935a5d` from outside that sandbox. Branch reconciliation against current master is the release prerequisite. GitHub branch search found no remote P2 feature branch, and no P2 PR appeared in the read-only search. The local branch has not been committed or pushed, so hosted checks, protected merge, deployment and deployed SHA verification remain outstanding.

The current worktree has four modified source/test files and this new readiness document. On this exact worktree, focused PHPUnit passed **19 tests, 127 assertions**. This checkout has not yet been committed, pushed, or submitted as a PR. Production completeness remains unqualified.

## Evidence reviewed

- Read `docs/current/universe-daily-bhavcopy-sync.md`, `docs/current/CODEX-VPS-REPO-WORKFLOW-LEARNINGS.md`, the P2 commit diff, and current service, matcher, scheduling, and regression-test code.
- Compared the standalone `origin/master` code with P2 and inspected the actual synthetic merged tree. Standalone master does not yet include P2 retry/backoff; the synthetic merged tree **retains** the P2 branches, source hashes, retry persistence, and regression assertions while adding PR297.
- The concurrent matcher change in master is comment-only. Master’s NSE inline XBRL parser change is in `Fundamentals/Historical/NseXbrlFactsParser.php` and does not participate in the daily bhavcopy flow. No changed provider URL or response assumption was found in that parser change.
- The P2 service continues using the official NSE final ZIP and BSE adapter through the existing daily collection path. It does not add a provider fallback or scheduling path.
- Ran from `app`: `php artisan test --filter='UniverseDailyBhavcopySyncTest|NseBhavcopyServiceTest'`. Result: **19 passed, 127 assertions**. `phpunit.xml` configures SQLite `:memory:` and Laravel ran its test migrations there. Test telemetry emitted local OTLP connection errors to `127.0.0.1:4318`; these did not affect the passing result. No provider call or live sync was run.

## P2 behavior and safety findings

- Source SHA-256 is diagnostic retry evidence only. It does not override canonical identity. NSE hashes the exact inner CSV bytes only after successful parser validation; BSE hashes the downloaded body. Repacking the same NSE CSV with different ZIP comments, metadata or compression preserves source identity and does not reset a conflict retry window. A changed validated CSV is a new revision and earns the first retry delay. Identical revisions and missing/malformed revision evidence continue bounded backoff through 5, 15, 60, then 360 minutes. The hash never accepts rows or bypasses canonical identity matching.
- An unchanged conflict inside its delay is not downloaded again. The deferred result carries the old conflict/error evidence into stats and logs, marks affected stock IDs conflicted, and does not write a successful receipt or price for those identities. When due, it downloads and runs the matcher again. A corrected source is accepted only when the existing matcher validates it against canonical identity.
- Fetch/parser failures also receive durable bounded retry state. They remain file errors and are included in the run’s errors; deferred failures are surfaced as warnings. Retry state is scoped by stock/universe identity fingerprint, exchange/session, and the current coverage hash. Force bypasses it. Dry-run preview bypasses receipts and writes.
- Coverage success remains tied to `StockPriceHistoryService::getMissingHistoryRanges`, not to file collection completion. Unavailable required sessions remain failed, so a completed download or a partial set of matched rows cannot by itself create false universe success. Existing good prices are preserved by the store path.
- Successful receipt reuse remains invalidated by coverage changes and by the broader stock/identity/ignored-gap fingerprint. A conflict does not become a success receipt.

## Offline cases exercised

The 19 passing feature/unit cases include full-scope collection and receipt behavior, failure visibility with cached prices, throttling, historical adjustment blocking, identity rejection, unchanged-conflict deferral, corrected-source revalidation/application, BSE conflict evidence, and NSE parsing. A new regression proves different ZIP envelopes around the same CSV keep the same source hash while changed CSV bytes yield a new hash. Full CI remains outstanding.

## Production blockers and semantics

The only production completeness evidence available in this checkout is the previously supplied snapshot: a universe price sync processed 5,178 stocks and reported 557 unavailable, comprising 530 missing the Nov 13 session and 27 effective historical coverage failures. This is not a fresh live observation. GitHub issue #282 says the earlier three-stock NSE identity conflicts were resolved in PR #284; the supplied snapshot's Nov 3–5 conflict and critical-alert claim has not been independently rechecked against current production. Obtain a current read-only coverage/alert snapshot before making acceptance claims. This retry/hash change cannot create missing bars or resolve identity conflicts by itself.

Do not substitute 466 naive raw gaps for the 27 effective failures. Effective coverage must continue through the existing missing-history owner, including listing-prefix, gap-tolerance, and ignored-gap rules. Scope completion, raw gap counts, and effective price completeness are separate measures. This report does not regenerate raw-gap inventories.

## Acceptance requirements before release

1. Reconcile normally with freshly fetched latest `origin/master`, preserving the existing edits. The supervisor has fetched master outside the Codex sandbox; reconcile and verify before push.
2. Run the focused feature/unit suite after reconciliation with provider fakes and an isolated test database. Confirm required hosted CI jobs before merge; do not use production credentials.
3. Verify unchanged-source deferral, changed-source immediate revalidation, success after corrected canonical identity, persistent failure on unchanged invalid identity, forced retry, coverage/fingerprint invalidation, deferred file-error surfacing, and no prices/receipts on invalid identity.
4. Require sync outcomes to remain partial/failed while effective required sessions or identity conflicts remain unresolved. Confirm the exact deployed source SHA and observed exchange file/session/identity/effective-coverage results through the normal production qualification process.
5. Resolve and document current effective coverage failures and confirm the live identity-conflict/alert state before asserting production completeness. Keep alert ownership and production state changes outside this code review.

## Rollback

Use the normal verified-master rollback procedure and restore the exact previously verified application commit SHA. Do not manually deploy or mutate production cache/configuration to roll back. Since this patch adds no schema migration, rollback requires no schema reversal. If retry receipts from a deployed version exist, the prior code ignores their extra JSON fields; preserve evidence and let the normal receipt lifecycle or an explicitly reviewed cleanup handle them rather than editing production settings ad hoc.
