# V9-OPS-001 live prerequisite checks — 2026-10-15

Readiness only; implementation NOT STARTED. Root executed these read-only checks through the authorized stoxla-prod terminal at approximately 15:03–15:10 IST. No ingestion, production writes, notification sends or service/configuration changes were performed. SQL was schema inspection and SELECT aggregates within START TRANSACTION READ ONLY / ROLLBACK; provider response payloads and secrets were excluded.

## Dependencies resolved or narrowed

- Release resolved to `/var/www/stoxla/releases/20261015091717-b2096e2fd0b5`. Cron invokes `/var/www/stoxla artisan schedule:run` every minute; artisan/app symlinks point into current. `php artisan schedule:list` succeeded and lists fundamentals update and completeness checks hourly, forward data every 15 minutes. This verifies wiring, not every scheduled outcome.
- General queue service is active/running; cwd `/var/www/stoxla`; command `/usr/bin/php /var/www/stoxla/artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120`. ML acceptance service is inactive. No service was changed.
- Live queue configuration: database driver, `portfolio_jobs`, default queue `default`. Queue counts empty at check. Existing worker subscribes to default and notifications; no need for a new general worker merely to establish baseline readiness. OPS001 future job routing still requires implementation proof.
- Cache uses database store/table `cache`; `cache_locks` exists. Config `cache.stores.database.lock_table` was null; deployed Laravel CacheManager.php line 206 resolves null to `cache_locks`. The configured lock backend/table is therefore resolved; no lock acquired or modified.
- Facts: 912816 rows, 4697 distinct stocks, max updated_at `2026-10-15 09:30:48` (raw DB time). Current annual: 519539 facts, 4696 stocks, periods 2021-12-31 through 2026-06-30. Current quarterly: 392539 facts, 4692 stocks, periods 2024-12-31 through 2026-06-30. These stock counts are NOT eligible-universe coverage percentages or per-field completeness.
- Yahoo provider checks: 9388 records, latest successful check `2026-10-15 09:30:46` (raw DB time). This supersedes a claim that only a three-issuer historical observation exists; it does not prove all metric/period completeness or a fresh stratified fetch.
- Incremental runs: 214 completed, 101 completed_with_errors, one running. Latest id 316 requested 40, processed 20, succeeded 10, failed 0, skipped 10, started `2026-10-15 09:30:11`, updated `09:30:49`. Previous id 315 completed 40/40 with 20 successes and 20 skips, updated `08:30:42`. Scheduler has actual recent updater evidence; running status alone is not a fault.
- Notification transport: SMTP configuration host/username/password/from are present (booleans only; no secrets disclosed). Actual notification delivery history has 52 Telegram delivered records, latest `2026-10-15 07:50:06`, one suppressed; no error-coded delivery attempts found. This clears generic transport-absence based on historical null timestamps. It does NOT prove OPS001-specific outcome routing/recipient policy. Zero verified notification email destination records; do not conflate this table with account-level email or all email delivery paths.
- Earlier user workbook located: Library `NSE.xlsx`, 194820 bytes, uploaded 2026-10-06. Text extraction supplied no readable content. Sample existence is resolved; workbook schema/parser compatibility still requires byte-level offline validation. No sample contents were copied into this repository.

## Existing state requiring an explicit recovery plan

Bootstrap runs: six completed, one running. Job totals across all runs: complete_good 5078, complete_partial 2, failed 453, queued 4762. Run 6 remains running with requested 5148, queued 4762, running 0, completed 384, failed 2; last update `2026-10-04 07:53:44`. Run 7 completed two stocks on October 4. Do not silently mark run 6 completed, cancel, resume, or launch overlapping backfills during readiness. Establish preservation/recovery/terminalization semantics before OPS001 activation. Queue table emptiness does not clear the bootstrap service's own queued jobs.

## Remaining checks

1. Cache lock default is resolved. Complete run/stock fencing design; isolated MySQL concurrency acceptance remains a post-code gate.
2. Validate eligible-stock denominator and metric/cadence exception matrix; avoid deriving percentages from raw stock totals.
3. Offline validate the located real workbook with existing importer and expected normalization manifest.
4. Confirm OPS001 notification destination/recipient policy; existing delivery evidence proves transport only.
5. Verify isolated test DB/browser capacity without migrate:fresh or heavy suites on production.
6. Agree safe handling of the stale October 4 bootstrap run before enabling new operations.

Provider policy and local writer map are in the readiness assessment: Yahoo primary; NSE/BSE optional conditional fallbacks; both writers use shared FundamentalDataService stock-row transaction locking. This evidence does not authorize application implementation or replace CI/live acceptance.
