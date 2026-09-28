# V8 FEAT-063 Acceptance Audit

Date: 2026-09-28
Status: **IN PROGRESS**

This audit maps the frozen FEAT-063 contract to the current repository evidence. It is intentionally conservative: local code/tests do not substitute for live Kite or VPS evidence.

## Requirement matrix

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Current NIFTY 500 universe, canonical StoX identity separate from provider token | PASS | `MicrostructureCollectorBootstrapService` maps stable `stock.id` to current Kite token; `universe_audit.py` records mapping changes without rewriting partitions. |
| Universe additions/removals/token or symbol changes are auditable | PASS | `UniverseAudit`, `test_universe_audit.py`; conflicts are surfaced in heartbeat error state. Live NSE refresh remains external validation. |
| Kite full-mode subscription and reconnect resubscription | PASS | `kite_ticker_bridge.py`, `test_kite_ticker_bridge.py`; live WebSocket proof pending. |
| Minute schema identity, OHLCV, trade, spread, five-level depth/order counts, imbalance, microprice, OI | PASS | `schema_v1.py`, `minute_aggregator.py`, Parquet schema test with real `pyarrow`. |
| Honest no-trade and partial-minute preservation | PASS | `ensure_instrument`, `coverage_class=no_trade/partial`, `test_minute_aggregator.py`. |
| Collector outage and reconnect-affected quality metadata | PASS | Connection callbacks mark `outage` and `reconnect_affected`; automated test covers both. |
| Complete-minute certainty | PARTIAL | The collector does not claim a minute is complete from one tick; explicit full-minute completeness requires a validated session/tick-coverage policy and live evidence. |
| Daily coverage summary and low-coverage warning | PARTIAL | Heartbeat exposes expected instrument-minutes, quality counts, and percentage; post-market threshold evaluation and low-coverage alert still need completion. |
| NSE calendar-aware pre-market/in-session/post-market lifecycle | PARTIAL | Laravel `TradingCalendar` and bootstrap/reminder paths are calendar-aware; Python session gating exists. Fixed-date restart/post-close integration tests remain to be added. |
| Weekend/holiday startup and reminder suppression | PASS | `TradingCalendar::isScheduledMarketDataDay`, reminder service, existing calendar tests; collector receives the authoritative result from bootstrap. |
| Bounded raw-tick spool and pruning after finalization | PASS | `RawTickSpool`, bounds/pruning tests, finalization integration. Replay-after-crash remains limited to retained debug evidence rather than a full replay pipeline. |
| Atomic/duplicate-safe Parquet writes and validation | PARTIAL | Staged writes, schema tests, row-count validation, atomic manifest, and isolated Parquet read/write smoke test pass; duplicate-part suppression and corruption-injection tests remain. |
| Durable idempotent finalization with bounded retry/backoff | PASS | `FinalizationState`, atomic manifest, active-minute regression, retry tests, manual retry command. |
| Backup only after validated finalization; retry-safe failure handling | PASS | Finalization gates backup; staged backup replacement preserves canonical data; integration test and Admin retry path exist. Live secondary storage validation pending. |
| Admin status and bounded controls with server-side Admin authorization | PASS | Laravel controller/control service, Admin UI, authorization tests, controls for start/stop/resubscribe/finalization/backup/universe. |
| Manual hold survives restart and authentication | PASS | Persisted Laravel state and login signal tests; live service restart proof pending. |
| Operational alerts and duplicate suppression | PARTIAL | Existing StoX alert publisher handles condition persistence/dedup; stale/error/disk/finalization/backup alerts are covered. Reconnect-failure, low-coverage, and universe-refresh alerts need explicit completion/tests. |
| VPS dependency, systemd, persistent paths, writable storage, import checks | PARTIAL | Deploy script provisions dedicated venv; runtime gate checks imports; systemd uses `current/shared/microstructure` and persistent shared storage. Read-only VPS inspection on 2026-09-28 found the service `not-found`/inactive and expected venv/code/data paths absent; deployment has not yet been applied. |
| Live Kite authentication → tick → Parquet → heartbeat | EXTERNAL VALIDATION PENDING | VPS is reachable, but the collector service is not installed and no live Kite session was exercised. No success is claimed. |

## Verification executed

- Isolated venv created from `shared/microstructure/requirements.txt`.
- Installed and imported `kiteconnect`, `pyarrow`, and `polars`.
- Microstructure suite: **16 passed, 0 skipped**.
- Actual Parquet write/read, manifest, backup, and spool-pruning smoke test: passed.
- Laravel V8 suite at prior checkpoint: **136 passed**.
- Frontend at prior checkpoint: JS **182/182**, build/typecheck/docs checks passed.

## Remaining exit-gate work

1. Add fixed-date lifecycle/restart integration tests and post-market coverage validation.
2. Add low-coverage, reconnect-failure, and universe-refresh operational alerts with suppression tests.
3. Add duplicate/corruption failure-injection tests for Parquet/finalization.
4. Validate the installed service and live Kite path on the StoX VPS if accessible.

FEAT-063 must remain **IN PROGRESS** until implementation gaps above are closed. If only live Kite/VPS evidence remains after those fixes, promote to **REVIEW — implementation complete, live runtime validation pending**.
