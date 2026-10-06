> **Historical audit record. FEAT-063 was permanently retired from the active repository on 2026-10-06.** The evidence below documents prior implementation/deployment only; it is not a current operation or acceptance checklist.

# V8 FEAT-063 Acceptance Audit

Original audit date: 2026-09-28
Updated: 2026-10-04
Status: **REVIEW — live VPS collection, day finalization, and backup validated; remaining deployed operational-control acceptance is open**

This audit records evidence against the frozen FEAT-063 contract at the time of review. It is intentionally conservative: local code/tests did not substitute for live Kite or VPS evidence.

## Requirement matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Current NIFTY 500 universe, canonical StoX identity separate from provider token | PASS | `MicrostructureCollectorBootstrapService` maps stable `stock.id` to current Kite token; `universe_audit.py` records mapping changes without rewriting partitions. |
| Universe additions/removals/token or symbol changes are auditable | PASS | `UniverseAudit`, `test_universe_audit.py`; conflicts are surfaced in heartbeat error state. Live NSE refresh remains external validation. |
| Kite full-mode subscription and reconnect resubscription | PASS | `kite_ticker_bridge.py`, `test_kite_ticker_bridge.py`; live WebSocket proof pending. |
| Minute schema identity, OHLCV, trade, spread, five-level depth/order counts, imbalance, microprice, OI | PASS | `schema_v1.py`, `minute_aggregator.py`, Parquet schema test with real `pyarrow`. |
| Honest no-trade and partial-minute preservation | PASS | `ensure_instrument`, `coverage_class=no_trade/partial`, `test_minute_aggregator.py`. |
| Collector outage and reconnect-affected quality metadata | PASS | Connection callbacks mark `outage` and `reconnect_affected`; automated test covers both. |
| Complete-minute certainty | PASS | The collector deliberately does not overclaim completeness from a single tick; every emitted minute is explicitly classified as `no_trade`, `partial`, `outage`, or `reconnect_affected` when the evidence does not establish complete observation. |
| Daily coverage summary and low-coverage warning | PASS | Deduplicated heartbeat coverage accounting now reports expected/observed instrument-minutes, quality counts, percentage, and post-market threshold alerts; duplicate rows cannot inflate counters. |
| NSE calendar-aware pre-market/in-session/post-market lifecycle | PASS | Laravel `TradingCalendar` and bootstrap/reminder paths are calendar-aware; Python phase handling accepts fixed instants; fixed-date phase, restart, retry, and manual-hold recovery tests pass. |
| Weekend/holiday startup and reminder suppression | PASS | `TradingCalendar::isScheduledMarketDataDay`, reminder service, existing calendar tests; collector receives the authoritative result from bootstrap. |
| Bounded raw-tick spool and pruning after finalization | PASS | `RawTickSpool`, bounds/pruning tests, finalization integration. Replay-after-crash remains limited to retained debug evidence rather than a full replay pipeline. |
| Atomic/duplicate-safe Parquet writes and validation | PASS | Staged writes, schema validation, manifest/row-count validation, corruption rejection, repeated-finalization idempotency, and isolated real-Parquet read/write tests pass. |
| Durable idempotent finalization with bounded retry/backoff | PASS | `FinalizationState`, atomic manifest, active-minute regression, retry tests, manual retry command. |
| Backup only after validated finalization; retry-safe failure handling | PASS | Finalization gates backup; staged backup replacement preserves canonical data; integration test and Admin retry path exist. Live secondary storage validation pending. |
| Admin status and bounded controls with server-side Admin authorization | PASS | Laravel controller/control service, Admin UI, authorization tests, controls for start/stop/resubscribe/finalization/backup/universe. |
| Configured-user daily Kite quick-connect UX | DEPLOYED — live acceptance pending at audit date | Lightweight `/kite-connect` Blade page, authenticated configured-user status/login APIs, dashboard live-tick state card, 09:00 Asia/Kolkata Telegram reminder, and deduplicated 09:20 no-WebSocket/no-recent-packet alert; its operations runbook was removed with FEAT-063 on 2026-10-06. |
| New Kite expiry timestamp UTC persistence | DEPLOYED — live acceptance pending | New session expiry is the UTC instant for 06:00 Asia/Kolkata; existing connection rows are deliberately not rewritten. Focused timezone regression covers before/after 06:00 IST. |
| Zero-row day finalization | DEPLOYED — live acceptance pending | Zero-row partitions fail finalization and are not backed up as successful; legacy zero-row markers remain unchanged and are flagged for review. |
| Manual hold survives restart and authentication | PASS | Persisted Laravel state and login signal tests; live service restart proof pending. |
| Operational alerts and duplicate suppression | PASS | Existing StoX alert publisher handles condition persistence/dedup; stale/error/disk/finalization/backup/low-coverage conditions are covered, while collector-side reconnect and universe failures surface through the actionable error condition. |
| VPS dependency, systemd, persistent paths, writable storage, import checks | PASS — deployed | Dedicated Python environment and systemd collector were provisioned on the VPS; the service was enabled and active during the 2026-09-29 acceptance run. Persistent primary and backup Parquet paths were writable. |
| Live Kite authentication → tick → Parquet → heartbeat | PASS — production path observed | After activation of the paid Kite Connect app, the collector connected, subscribed to 499 mapped instruments, received real FULL-mode packets, wrote raw spool data, and generated minute Parquet partitions. |
| Post-market finalization → validation → backup → spool cleanup | PARTIAL — 2026-09-29 | Finalization markers report `finalized_at=2026-09-29T10:00:03.103706+00:00` and `row_count=15213`; recursive `diff -qr` found no differences between the primary and `microstructure-backup` date partitions; the raw tick spool contained no files. Both paths are under the same VPS shared-storage tree, so independent secondary-storage durability has not been demonstrated. |

## Verification executed

- Isolated venv created from `shared/microstructure/requirements.txt`.
- Installed and imported `kiteconnect`, `pyarrow`, and `polars`.
- Microstructure suite before this continuation: **16 passed, 0 skipped**.
- Microstructure resilience suite after this continuation: **28 passed, 0 skipped**, including real Parquet corruption, backup failure/retry, fixed-date lifecycle, quality edge, coverage deduplication, and universe partial-refresh cases.
- Actual Parquet write/read, manifest, backup, and spool-pruning smoke test: passed.
- Laravel V8 suite before this continuation: **140 passed, 531 assertions**; fixed-date calendar/reminder additions pass.
- Frontend after this continuation: JS **190/190**, Vitest **101/101**, with build/typecheck/docs checks rerun after the frontend acceptance additions.

## Production acceptance evidence — 2026-09-29

The production acceptance run confirmed:

- `stoxla-microstructure-collector.service` was enabled and active after market close.
- Earlier same-day checks established a usable paid Kite Connect session, live WebSocket packets, 499 subscribed instruments, raw-tick spool growth, and real FULL-mode minute Parquet output.
- Post-market finalization produced 15,213 rows. The primary and backup `_FINALIZED.json` files contain the same UTC finalization timestamp and row count.
- `diff -qr` over the full primary/backup date partitions returned no differences.
- The raw-tick spool directory contained no files after finalization, consistent with the bounded-spool cleanup requirement.
- The matching `microstructure-backup` partition proves the copy is complete and byte-for-byte comparable, but it is located under the same VPS shared-storage tree as the primary partition. It does not yet prove an independent secondary backup or failure domain.
- The journal recorded WebSocket close code `1006` at 15:30 IST. Finalization completed; treat the close as an operational observation, not proof of a data-integrity failure.

## Remaining exit-gate work

1. Validate deployed Admin status/controls, persistent manual hold, restart/reconnect/resubscription, and retry paths against the production service.
2. Confirm and validate the configured backup target is independent of the primary VPS storage failure domain; current evidence only proves a matching copy under the same shared-storage tree.
3. Review the 15:30 WebSocket `1006` close in normal market-close behavior and ensure it does not cause repeated post-market reconnect errors.
4. Continue routine monitoring of storage thresholds and later trading-day collection stability.

At the audit date, FEAT-063 was **REVIEW**: the live collection and end-of-day data/backup path passed production checks, while the broader deployed operational-control and recovery acceptance was not evidenced.

## Local UX and integrity continuation — 2026-10-04

The approved FEAT-063 quick-connect and integrity changes are implemented in the isolated `feat063-kite-quick-connect` worktree. The dedicated page is server-rendered and does not load the React dashboard bundle. Its browser APIs require the authenticated StoX session and enforce `MICROSTRUCTURE_KITE_USER_ID`; the existing encrypted callback state, official Zerodha OTP entry, and account trading flow are retained. Reminder cadence remains bounded and calendar-aware. The 09:20 packet/WebSocket alert uses a five-minute packet recency grace after 09:15; this is an operational judgment to allow normal connection setup.

The isolated tests below used no production database or live Kite login. The 2026-10-04 deployment evidence is recorded separately below. The next trading-day verification in the operations runbook remains required, and FEAT-063 remains **REVIEW**.

## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Service continuity | PASS (read-only status only) | Build `ef66133c`, VPS, 2026-10-01 18:25 UTC: microstructure collector service active. Prior 2026-09-29 paid 499-instrument/15,213-minute-row evidence remains as recorded; no new trading-day result claimed. |
| Admin controls, hold/reconnect/universe refresh, independent backup/restore and capacity alerts | NOT YET RUN | Schedule controls outside live collection; backup target must be outside primary VPS failure domain and validated by restore. |
| Market-close code 1006 and another trading day | NOT YET RUN | Compare post-close disconnect/retry journal with completed partition marker on next session. |

### Backup location assessment — 2026-10-01 18:34 UTC

Read-only `findmnt` on `stoxla-prod` showed root/boot filesystems and ephemeral virtual mounts, with no independent backup filesystem mounted. This does not rule out remote object storage, but no external destination or restore validation has been established. Secondary backup/restore remains **BLOCKED pending a configured independent destination**. Do not treat the matching same-VPS partition copy as disaster recovery.

### Isolated verification — 2026-10-04

From `feat063-kite-quick-connect` at base `764ba1f9`, using a disposable MySQL 8.4 container and an ephemeral test encryption key: focused PHPUnit **17 tests, 65 assertions passed**. The Python collector finalization suite passed **4 tests** with the installed `pyarrow` runtime. Frontend checks passed **202 Node tests and 1 focused Vitest test**, and `npm run build` completed. The PHP/JS dependency lockfiles matched the VPS release; dependencies were copied or installed within the isolated worktree. No test used the production database. Deployed login, reminder delivery, WebSocket packets, and next trading-day collection remain unverified.

## Production quick-connect release — 2026-10-04

- [PR #56](https://github.com/lido-alexion/LidoPortfolio/pull/56) merged as `6a843cd4220fe240a1ca0bde13620f4c43e81274` after its pull-request CI passed.
- [Production workflow](https://github.com/lido-alexion/LidoPortfolio/actions/runs/37190925152) passed backend and frontend verification, packaging, VPS activation and its post-deploy health check. Current release path at verification: `/var/www/stoxla/releases/20261004091938-6a843cd4220f`.
- Read-only post-deploy checks: unsigned `https://stoxla.in/kite-connect` returned HTTP 302 to `/login`; the effective Laravel reminder time was `09:00` Asia/Kolkata. These checks prove deployment/configuration, not a signed-in page journey, Telegram delivery, live Kite connection or collected rows.
- **Pending:** next trading-day collector-user login (production user ID 1), official Zerodha callback, configured-user and second-user access, Telegram reminder and packet alert/recovery, live WebSocket/recent packets, positive-row finalization; inspect October 1 zero-row evidence and verify rejection in a controlled check. Also pending: independent backup/restore, deployed Admin controls and recovery, normal market-close `1006` assessment and another complete day of observation. See the operations runbook. The October 1 zero-row day remains missing evidence; do not mark it successful or reconstruct full-mode ticks from OHLCV.

**Epic status remains REVIEW.**
