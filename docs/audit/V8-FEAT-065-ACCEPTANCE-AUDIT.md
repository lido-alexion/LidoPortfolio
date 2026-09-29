# FEAT-065 Intraday ML Historical Data Platform — acceptance audit

Status: **REVIEW — local implementation and isolated verification complete; live Kite POC/full-corpus evidence pending**

Evidence is mapped to `docs/archive/specs/V8-Intraday-ML-Historical-Data-Platform-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Current NIFTY 500 fixed research universe | PASS locally | `shared/intraday/backfill_worker.py` accepts an explicit audited symbol/token manifest, deduplicates and processes it deterministically; no current-universe reconstruction is performed in the worker |
| Selected broad-market and sector indices | PASS locally | The worker accepts a separate `--index-map`, emits `NSE_INDEX` checkpoints, and keeps index Parquet partitions distinct from equity partitions; isolated tests cover token/exchange preservation |
| Kite historical 1-minute OHLCV acquisition | PASS locally | `kite_historical_client.py` normalizes Kite candles and chunks bounded requests; credential/network runtime remains external |
| Schema-versioned Parquet with ZSTD | PASS locally | `parquet_store.py`, `schema_v1`, atomic staging replacement, isolated Parquet tests |
| Idempotent windowed writes | PASS locally | Same-symbol/day writes upsert by timestamp and preserve prior windows; repeated-write regression passes |
| DuckDB and Polars analytical access | PASS locally | `dataset_builder.py`; isolated project-compatible suite runs with DuckDB/Polars |
| Resumable/idempotent backfill and Laravel checkpoints | PASS locally | `backfill_worker.py` deterministically plans bounded instrument/exchange/date work units, reads durable checkpoints before each unit, skips compatible completed units, and resumes the first unfinished unit; `test_planned_run_skips_completed_windows_and_resumes_first_incomplete` and API checkpoint-read coverage pass |
| Coverage/quality reporting | PASS locally | `coverage_report.py` and tests expose symbol/date/bar counts and empty-root behavior |
| Retry/backoff and failed-window tracking | PASS locally / runtime population pending | Kite client retries bounded 429/5xx/network failures with exponential backoff; each bounded unit records failed state through the durable checkpoint API and remains retryable. |
| Durable pause/resume control | PASS locally | `stox_intraday_backfill_controls` persists the global operator hold; Admin-only pause/resume endpoints survive restart, the internal worker control read prevents new units, and `test_planned_run_honours_durable_pause_before_next_unit` plus API/Admin tests cover the contract |
| Bounded checkpoint state semantics | PASS locally | Checkpoint service validates `pending/running/complete/failed`, rejects invalid windows/statuses, clears stale completion timestamps on retry, and exposes filtered durable checkpoint reads |
| POC before full corpus | EXTERNAL VALIDATION PENDING | No live Kite credential/corpus campaign is claimed in this environment; this is the remaining frozen runtime gate |
| Optional derivatives/OI do not block Dataset A | PASS by architecture | Canonical Dataset A writer is OHLCV-only; no derivative dependency is introduced |
| No automated backup subsystem | PASS | README explicitly documents manual external-disk backup and no application backup path is implemented |
| FEAT-057 handoff remains PIT-safe and separate | PASS by architecture | Dataset helpers are research-corpus access only; FEAT-057 production feature registry contains no intraday/minute feature definitions |

## Verification

- Isolated project-compatible intraday suite: **22/22 passed**, including DuckDB, Polars, real Parquet paths, index/equity partition separation, bounded retry/backoff and failed-window reporting.
- Correction slice: **25/25 intraday Python tests passed locally** (7 Parquet-dependent tests skipped in the default interpreter); focused Laravel correction tests passed **24/24** with 120 assertions.
- No live Kite credentials or full NIFTY 500 corpus population was available for runtime validation.

The epic is **REVIEW**. The implementation and isolated project-compatible verification are complete locally; a bounded real Kite POC, populated-corpus performance/coverage run, and live corpus handoff acceptance remain external because no Kite credentials or target corpus runtime are available here.
