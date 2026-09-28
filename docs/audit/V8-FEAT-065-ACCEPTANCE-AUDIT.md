# FEAT-065 Intraday ML Historical Data Platform — acceptance audit

Status: **IN PROGRESS**

Evidence is mapped to `docs/archive/specs/V8-Intraday-ML-Historical-Data-Platform-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Current NIFTY 500 fixed research universe | PASS locally | `shared/intraday/backfill_worker.py` accepts an explicit audited symbol/token manifest, deduplicates and processes it deterministically; no current-universe reconstruction is performed in the worker |
| Kite historical 1-minute OHLCV acquisition | PASS locally | `kite_historical_client.py` normalizes Kite candles and chunks bounded requests; credential/network runtime remains external |
| Schema-versioned Parquet with ZSTD | PASS locally | `parquet_store.py`, `schema_v1`, atomic staging replacement, isolated Parquet tests |
| Idempotent windowed writes | PASS locally | Same-symbol/day writes upsert by timestamp and preserve prior windows; repeated-write regression passes |
| DuckDB and Polars analytical access | PASS locally | `dataset_builder.py`; isolated project-compatible suite runs with DuckDB/Polars |
| Resumable/idempotent backfill and Laravel checkpoints | PASS locally | Mac worker posts bounded checkpoint rows through the internal token-protected API; worker and API tests cover stable ordering and checkpoint payloads |
| Coverage/quality reporting | PASS locally | `coverage_report.py` and tests expose symbol/date/bar counts and empty-root behavior |
| Retry/backoff and failed-window tracking | PASS locally / runtime population pending | Kite client retries bounded 429/5xx/network failures with exponential backoff; worker records failed windows through the durable checkpoint API and returns a non-zero process status for failed windows. Production-scale retry behavior and a populated corpus run remain external |
| POC before full corpus | EXTERNAL VALIDATION PENDING | No live Kite credential/corpus campaign is claimed in this environment |
| Optional derivatives/OI do not block Dataset A | PASS by architecture | Canonical Dataset A writer is OHLCV-only; no derivative dependency is introduced |
| No automated backup subsystem | PASS | README explicitly documents manual external-disk backup and no application backup path is implemented |
| FEAT-057 handoff remains PIT-safe and separate | PASS by architecture | Dataset helpers are research-corpus access only; FEAT-057 production feature registry contains no intraday/minute feature definitions |

## Verification

- Isolated project-compatible intraday suite: **20/20 passed**, including DuckDB, Polars, real Parquet paths, bounded retry/backoff and failed-window reporting.
- No live Kite credentials or full NIFTY 500 corpus population was available for runtime validation.

The epic remains **IN PROGRESS** until a bounded real Kite POC, durable retry/failure evidence and corpus handoff acceptance are recorded.
