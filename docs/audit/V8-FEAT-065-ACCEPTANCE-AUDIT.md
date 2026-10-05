# FEAT-065 Intraday ML Historical Data Platform — acceptance audit

Status: **IMPLEMENTED — local implementation and isolated verification complete; Mac-side functional acceptance pending**. Remaining runtime scenarios are tracked in [FEAT-065 Mac Corpus Acceptance Plan](../testing/V8-FEAT-065-FUNCTIONAL-TEST-PLAN.md).

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
| Mac corpus intake / bounded POC | OPEN — functional acceptance | SKR-001 in StoX-Kite-Rain owns Windows receipt, integrity checks, NTFS archive and Mac handoff; V9-DATA-002 owns VPS staging/secure delivery. FEAT-065 begins with a verified batch on Mac and validates canonical adoption, schema/provenance, DuckDB/Polars access, bounded coverage and Mac performance. See the linked functional test plan. |
| Optional derivatives/OI do not block Dataset A | PASS by architecture | Canonical Dataset A writer is OHLCV-only; no derivative dependency is introduced |
| No automated backup subsystem | PASS | README explicitly documents manual external-disk backup and no application backup path is implemented |
| FEAT-057 handoff remains PIT-safe and separate | PASS by architecture | Dataset helpers are research-corpus access only; FEAT-057 production feature registry contains no intraday/minute feature definitions |

## Verification

- Isolated project-compatible intraday suite: **22/22 passed**, including DuckDB, Polars, real Parquet paths, index/equity partition separation, bounded retry/backoff and failed-window reporting.
- Correction slice: **25/25 intraday Python tests passed locally** (7 Parquet-dependent tests skipped in the default interpreter); focused Laravel correction tests passed **24/24** with 120 assertions.
- No live Kite credentials or full NIFTY 500 corpus population was available for runtime validation.

The implementation is **IMPLEMENTED** based on the local implementation and isolated verification above. Mac-side functional acceptance remains open: a representative verified batch must arrive through SKR-001/V9-DATA-002, then pass FEAT-065 corpus adoption, coverage, DuckDB/Polars and performance checks. Full-corpus coverage and performance are assessed after backfill, separately from the bounded POC.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Real Kite minute-data bounded POC and retries/checkpoint resume | BLOCKED (research machine) | Designated Mac device was offline in connected-device inventory at 2026-10-01 18:17 UTC. No real corpus run started on VPS; daily NSE sources are not minute-bar evidence. |
| Fixed current-NIFTY-500 plus selected-index corpus, coverage/quality/performance and PIT handoff | NOT YET RUN | Plan token/index mapping, windows, Kite limits, storage/time and manual external-disk backup on research machine. |


## 2026-10-05 implementation decision and functional-test handoff

**Decision: FEAT-065 IMPLEMENTED.** The historical-corpus implementation and isolated project-compatible verification are complete. This decision covers the FEAT-065 corpus and analytical contract only; it does not claim a live Kite campaign, a populated full corpus, or end-to-end Windows transfer acceptance.

The Windows downloader, transfer verification, NTFS archive and manual Mac handoff belong to SKR-001 in StoX-Kite-Rain. VPS staging and secure delivery belong to V9-DATA-002. FEAT-065 begins runtime acceptance after a representative verified batch reaches the Mac. Its remaining checks are listed in [V8-FEAT-065-FUNCTIONAL-TEST-PLAN.md](../testing/V8-FEAT-065-FUNCTIONAL-TEST-PLAN.md).

The bounded Mac POC must pass before launching the full backfill. Full-corpus coverage/performance, campaign retry/checkpoint behavior and the manual external-disk backup are checked after that corpus is populated. FEAT-065 remains **IMPLEMENTED, not COMPLETE**, until those applicable runtime exit criteria have evidence.
