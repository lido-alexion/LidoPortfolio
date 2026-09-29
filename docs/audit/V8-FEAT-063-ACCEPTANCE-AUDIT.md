# V8 FEAT-063 Acceptance Audit

Date: 2026-09-28
Status: **REVIEW — implementation and automated verification complete; VPS/live Kite validation pending**

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
| Complete-minute certainty | PASS | The collector deliberately does not overclaim completeness from a single tick; every emitted minute is explicitly classified as `no_trade`, `partial`, `outage`, or `reconnect_affected` when the evidence does not establish complete observation. |
| Daily coverage summary and low-coverage warning | PASS | Deduplicated heartbeat coverage accounting now reports expected/observed instrument-minutes, quality counts, percentage, and post-market threshold alerts; duplicate rows cannot inflate counters. |
| NSE calendar-aware pre-market/in-session/post-market lifecycle | PASS | Laravel `TradingCalendar` and bootstrap/reminder paths are calendar-aware; Python phase handling accepts fixed instants; fixed-date phase, restart, retry, and manual-hold recovery tests pass. |
| Weekend/holiday startup and reminder suppression | PASS | `TradingCalendar::isScheduledMarketDataDay`, reminder service, existing calendar tests; collector receives the authoritative result from bootstrap. |
| Bounded raw-tick spool and pruning after finalization | PASS | `RawTickSpool`, bounds/pruning tests, finalization integration. Replay-after-crash remains limited to retained debug evidence rather than a full replay pipeline. |
| Atomic/duplicate-safe Parquet writes and validation | PASS | Staged writes, schema validation, manifest/row-count validation, corruption rejection, repeated-finalization idempotency, and isolated real-Parquet read/write tests pass. |
| Durable idempotent finalization with bounded retry/backoff | PASS | `FinalizationState`, atomic manifest, active-minute regression, retry tests, manual retry command. |
| Backup only after validated finalization; retry-safe failure handling | PASS | Finalization gates backup; staged backup replacement preserves canonical data; integration test and Admin retry path exist. Live secondary storage validation pending. |
| Admin status and bounded controls with server-side Admin authorization | PASS | Laravel controller/control service, Admin UI, authorization tests, controls for start/stop/resubscribe/finalization/backup/universe. |
| Manual hold survives restart and authentication | PASS | Persisted Laravel state and login signal tests; live service restart proof pending. |
| Operational alerts and duplicate suppression | PASS | Existing StoX alert publisher handles condition persistence/dedup; stale/error/disk/finalization/backup/low-coverage conditions are covered, while collector-side reconnect and universe failures surface through the actionable error condition. |
| VPS dependency, systemd, persistent paths, writable storage, import checks | PASS locally / deployed validation pending | Deploy script provisions the dedicated venv; runtime gate checks `kiteconnect`, `pyarrow` and `polars`; the systemd unit and runbook are statically validated for the release module path, venv, environment file, persistent storage paths, journal logging and restart policy. Read-only VPS inspection on 2026-09-28 found the service `not-found`/inactive and expected venv/code/data paths absent; deployment has not yet been applied. |
| Live Kite authentication → tick → Parquet → heartbeat | EXTERNAL VALIDATION PENDING | VPS is reachable, but the collector service is not installed and no live Kite session was exercised. No success is claimed. |

## Verification executed

- Isolated venv created from `shared/microstructure/requirements.txt`.
- Installed and imported `kiteconnect`, `pyarrow`, and `polars`.
- Microstructure suite before this continuation: **16 passed, 0 skipped**.
- Microstructure resilience suite after this continuation: **28 passed, 0 skipped**, including real Parquet corruption, backup failure/retry, fixed-date lifecycle, quality edge, coverage deduplication, and universe partial-refresh cases.
- Actual Parquet write/read, manifest, backup, and spool-pruning smoke test: passed.
- Laravel V8 suite before this continuation: **140 passed, 531 assertions**; fixed-date calendar/reminder additions pass.
- Frontend after this continuation: JS **190/190**, Vitest **99/99**, with build/typecheck/docs checks rerun after the frontend acceptance additions.

## Remaining exit-gate work

1. Install and validate the collector service on the StoX VPS, including restart/hold behavior; the repository unit/runbook contract is now statically validated.
2. Exercise the live Kite session → WebSocket → tick → Parquet → heartbeat path when credentials and market conditions permit.
3. Validate the configured secondary backup destination in the deployed environment.

FEAT-063 is **REVIEW** because repository implementation and deterministic automated verification now cover the frozen local behavior, while the VPS is currently unprovisioned and no live Kite run has been claimed.
