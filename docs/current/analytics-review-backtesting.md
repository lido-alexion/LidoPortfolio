# Analytics, Review, And Backtesting

## 1. Purpose And Scope

This document owns analytical read models, performance, attribution, review, recommendation outcomes, backtests, historical replay, paper simulation, and result evaluation. Accounting owns durable transactions/cash/holdings; market data owns bars/dataset provenance; strategy owns deterministic decision policy. Analytics consume those inputs and do not own live broker execution.

## 2. Analytical Source Boundaries

Analytics consume durable financial transactions, holdings/history, market and benchmark data, strategy/recommendation evidence, and pinned dataset/artifact/model evidence where available. **Analytics do not rewrite financial/accounting truth.** Derived reports, snapshots, simulations, and metrics are never a substitute for ledger evidence.

## 3. Portfolio Performance

Portfolio performance reports wealth/value, cash flows, realized and unrealized P&L, money-weighted return/XIRR, risk measures, and benchmark-relative performance where inputs are available. External cash flows are neutralized appropriately for performance comparison; missing price/benchmark inputs must remain unavailable/incomplete rather than become zero.

**Current implementation anchors:** `PortfolioPerformanceService`, `MoneyWeightedReturnCalculator`, `PerformanceRiskCalculator`, `V5PortfolioPerformanceApiTest`.

## 4. Account Performance

Account performance aggregates included wealth across the applicable account/profile scope; it is not an average of portfolio percentages. Date ranges and benchmark selection are explicit, and cross-account what-if input is rejected.

**Current implementation anchors:** `AccountPerformanceService`, `AccountPerformanceController`, `V5AccountPerformanceApiTest`.

## 5. Attribution

Attribution separates strategy-owned, unmanaged, security/holding, charges/cash, benchmark, and residual contributions. An unexplained residual must remain visible and must not be silently assigned a causal strategy/security explanation. Attribution is read-only analysis of source evidence.

**Current implementation anchors:** `PortfolioAttributionService`, `PortfolioAttributionController`, `V5PortfolioAttributionApiTest`.

## 6. Review Surfaces

Review surfaces explain recommendations, executed recommendations, holdings, strategy outcomes, benchmark comparisons, evaluation evidence, and historical decisions. They are analytical/read-only unless a separately documented lifecycle action is explicitly invoked. Dashboard, Explorer, performance/tax, review reports, evaluation profile, stock research, and recommendation preview serve different explanatory scopes.

## 7. Recommendation Outcome Evaluation

Outcome evaluation uses recorded entry/reference evidence, action type, holding period, realized/observed return, benchmark-relative return, and exit evidence. It must distinguish an opportunity outcome from a live recommendation's lifecycle and does not retroactively change that recommendation's original decision.

**Current implementation anchors:** `SuccessCriteriaEvaluator`, `BacktestTradeSuccessAttacher`, recommendation/review models.

## 8. Success Criteria

Success/hit criteria are not raw positive return. The current evaluator requires a positive Nifty beat and opportunity-cost threshold, with threshold behavior scaling by calendar holding period and profile configuration. Exit outcome, absolute return, and relative return remain separately inspectable.

**Current implementation anchors:** `SuccessCriteriaEvaluator`, `SuccessCriteriaEvaluatorTest`.

## 9. Return Quality

Return quality is magnitude-aware historical outcome evidence for ranking; hit rate is only the frequency of a success definition. Closed eligible trades contribute to the ranking corpus; open, wrong-strategy, or missing-entry-score records do not. CAGR is used only when holding-period rules permit it, while simple return remains distinct.

**Current implementation anchors:** `ReturnQualityRankingService`, `ReturnQualityRankingServiceTest`, and [Strategy And Recommendations](./strategy-and-recommendations.md).

## 10. Backtest Purpose

A backtest is historical simulation under frozen run inputs and point-in-time constraints. It supports strategy evaluation, comparison, research, and the outcome corpus; it is not live recommendation generation or broker execution.

## 11. Backtest Lifecycle

Current statuses are `preparing`, `running`, `completed`, `failed`, and `cancelled`; stages are `PREPARING`, `SIMULATING_DAYS`, `GENERATING_STATISTICS`, `GENERATING_REPORT`, `COMPLETED`, `FAILED`, and `CANCELLED`. Progress records processed/total days, percentage, current date, start/completion/cancel time, errors, and execution duration.

Cancellation is checkpoint-aware. A cancelled/failed run is terminal and must never be presented as completed.

**Current implementation anchors:** `BacktestRun`, `BacktestSimulationEngine`, `BacktestPersistenceService`, `ProcessBacktestsCommand`, and `V5BacktestLifecycleTest`.

## 12. Backtest Input Contract

A run records profile/user, strategy and strategy version, pinned entry/exit screener versions, reusable artifact/binding revision, date range, starting capital, assumptions, context, benchmark evidence, and parameter overrides permitted by the declared bounded schema. A new/duplicate run is a new experiment and must retain its own input evidence.

## 13. Dataset Pinning

Dataset/version evidence identifies the successful market-data context used by a run. `DatasetVersion` is an immutable successful-sync attribution record, not a full physical OHLCV snapshot. Historical simulation requires as-of reads and pinning evidence, but a claim that later physical resync can never affect replay requires implementation-audit verification.

See [Market Data And Data Quality](./market-data-and-data-quality.md).

## 14. Artifact And Strategy Pinning

Backtests pin strategy version, entry/exit screener versions, artifact version, binding revision, and declared parameter overrides. Current mutable registry/configuration state must not reinterpret an old run. A completed modified run may create an unpublished next draft with provenance rather than mutating the historical run.

**Current implementation anchors:** `BacktestRun`, artifact binding services, `V5BacktestParameterOverrideTest`, and `BacktestDuplicateTest`.

## 15. Benchmark Pinning

Benchmark identity, data/range evidence, and comparison assumptions are run inputs. Missing benchmark data is unavailable/incomplete, never zero and never a silent substitute benchmark. Portfolio performance keeps selected comparison benchmarks distinct from its primary excess-return basis.

## 16. Point-In-Time Discipline

Historical evaluation must not use future market bars, future fundamentals, future corporate-action knowledge outside effective/known rules, today's mutable holdings/cash, or mutable current strategy parameters. Dataset/artifact/model timestamps and run context provide evidence. Full end-to-end point-in-time isolation remains an implementation-audit requirement where physical data is later corrected/resynced.

## 17. Replay

Historical replay replays a pinned historical strategy/screener world and next-session decisions through a durable timeline. It differs from a backtest's aggregate strategy simulation and from live recommendation generation. Replay input must reconstruct required historical capital/economic state; unavailable reconstruction blocks rather than silently shortening history.

**Current implementation anchors:** `PortfolioReplayService`, `PortfolioReplayProcessor`, `ReplayStrategyEvaluator`, `PortfolioReplayRun`, `PortfolioReplayCheckpoint`, `V5PortfolioReplayFoundationTest`.

## 18. Paper Simulation Boundary

Paper simulation is forward-running simulated trading over an effective current timeline with durable simulation events/checkpoints and no live broker effect. It differs from a historical backtest/replay and must not contaminate live ledger or execution evidence. Missing session price waits without advancing the checkpoint; processing is idempotent.

**Current implementation anchors:** `PaperSimulationProcessor`, `PaperSimulationEvent`, `SimulationPriceService`, and `V5PaperSimulationProcessorTest`.

## 19. Backtest Execution Engine

The engine prepares pinned context, iterates historical sessions/universe, computes as-of evaluation/eligibility, applies strategy decisions to virtual cash/positions, persists simulated transactions/trades/snapshots, evaluates benchmark/outcomes, and produces statistics/timeline/report. `SimulationContext`, `SimulationDayProcessor`, `AsOfFactorScorer`, `EligibilityPrecomputeService`, `StatisticsGenerator`, and `TimelineBuilder` divide these responsibilities.

## 20. Position And Cash Simulation

Simulation uses stated starting capital, virtual cash/positions, pinned price/charge/execution assumptions, whole trade sequence, and insufficient-capital handling. It does not silently draw on live portfolio cash/lending/borrower state. Partial affordability can progress without duplicate economic effects; future broker execution is excluded.

**Current implementation anchors:** `PaperPortfolioManager`, `PaperTradeExecutor`, `BacktestMath`, `BacktestTransaction`, and paper/backtest tests.

## 21. Corporate Actions In Backtests

Backtests consume the approved as-of price-series adjustment policy for split/bonus continuity. Financial ledger corporate-action transactions are not created by a historical simulation. Dividends, rights, and any unsupported event treatment must be explicitly represented as assumptions/unavailable conditions rather than implied.

## 22. Missing Data And Incomplete Runs

Missing OHLCV, stale/unavailable dataset, data-quality-blocked security, missing benchmark, incomplete factor evidence, or provider gaps must result in a documented skip, wait, blocked/incomplete outcome, or failed run according to the owning engine. Missing next-session OHLC must wait atomically rather than advance as though a price existed. A valid empty candidate set differs from an operational failure.

## 23. Cancel

User/system cancellation is observed at a durable checkpoint. Progress/trades/snapshots already persisted remain evidence, the run becomes `cancelled`, and deletion may retain a tombstone. Partial/cancelled results are not final completed analytics and must be labeled as such.

**Current implementation anchors:** `BacktestRun`, persistence/processor checkpoints, `V5BacktestLifecycleTest`.

## 24. Resume And Retry

Backtest lifecycle evidence supports checkpoint-aware processing; exact supported user-facing resume/retry routes and semantics require implementation-audit verification. A resume/retry must retain the original pinned inputs and cannot silently switch strategy, artifact, benchmark, dataset evidence, or assumptions. Paper simulation explicitly resumes by its durable effective-session checkpoint.

## 25. Partial Persistence

Backtest transactions, trades, hits, snapshots, context, progress, and errors are persisted as run evidence. Replay checkpoints and paper simulation events separately preserve their own progress/economics. Final statistics/report are completion outputs; partial evidence after cancel/failure remains queryable for diagnosis but is not equivalent to completed results.

## 26. Reproducibility

Reproducibility requires retained strategy/artifact/screener evidence, dataset/version attribution, benchmark identity/range, configuration/assumptions, generated timestamps, and deterministic engine/evaluator behavior where versioned. It must disclose limitations: dataset-version rows are not immutable OHLCV snapshots and live market-data corrections can require verification of historical replay isolation.

## 27. Comparison And Experiment Review

Completed backtests can be compared with assumption disclosure. Strategy/version/parameter variations are separate experiments, and resulting drafts remain unpublished until deliberately promoted. Portfolio/account/strategy performance and benchmark comparison views must keep scope/date/assumptions visible rather than averaging incompatible runs.

## 28. Data Model

`BacktestRun` owns lifecycle, inputs/context, progress, and statistics. `BacktestTransaction`, `BacktestTrade`, `BacktestRunHit`, and `BacktestSnapshot` are derived simulated evidence. `ScreenerBacktest` and day/hit rows are separate screener-history analysis. `PortfolioReplayRun` and checkpoints retain replay world/progress; `PaperSimulationEvent` retains forward paper economics. Performance/attribution models are read models over durable accounting/market evidence.

## 29. API Contract

- Portfolio/account/strategy performance and attribution: `/api/analysis/*`, `/api/v1/analytics/*`, account/strategy/portfolio performance endpoints.
- Review: `/api/v1/reviews/*`, review dashboard/outcomes, evaluation profile, research, recommendation preview.
- Backtests: `/api/v1/backtests/*`, including run/detail/compare/lifecycle actions where routed.
- Screener backtests: `/api/screeners/{screener}/backtest`, `/api/screener-backtests/*`.
- Replay/paper: `/api/replays/*` and paper simulation routes.

All runs are user/active-profile scoped; foreign strategy/profile/run access must fail.

## 30. Services And Orchestration

`PortfolioPerformanceService`, `AccountPerformanceService`, `StrategyPerformanceService`, `PortfolioAttributionService`, and calculators own analytical metrics. `SuccessCriteriaEvaluator` and `ReturnQualityRankingService` own outcome/ranking distinctions. Backtest simulation/persistence services own historical runs; replay and paper simulation services own their distinct durable timelines. Benchmark/market-data consumers provide inputs but do not mutate ledger through analytics.

## 31. Scheduling And Background Work

`ProcessBacktestsCommand`, `ProcessPortfolioReplaysCommand`, and `ProcessPaperSimulationsCommand` process durable work. Workers/checkpoints must be idempotent and respect lifecycle/cancellation state. Scheduler/queue deployment and automatic resume behavior require runtime verification.

## 32. Critical Analytics Invariants

- Analytics never rewrite financial ledger truth.
- Future data must not leak into historical runs.
- Old runs retain strategy/artifact/version evidence.
- Missing benchmark is not zero or a silent substitute.
- Partial/cancelled/failed run is not completed.
- Resume/retry cannot silently change pinned inputs.
- Mutable registry state cannot reinterpret prior run.
- Return quality is distinct from success rate.
- Simulation/backtest evidence is distinct from live execution/accounting evidence.

## 33. Error And Recovery Semantics

| Condition | Expected behavior |
| --- | --- |
| Invalid range/input/override | Reject before run or record validation error. |
| Empty candidates | Valid empty analytical result, not necessarily failure. |
| Missing/stale data or benchmark | Unavailable/blocked/wait/incomplete according to engine; no fabricated zero. |
| Failed worker/persistence | Record failed run/error and preserve durable partial evidence where written. |
| Cancelled job | Stop at checkpoint; retain labelled partial evidence. |
| Incompatible/missing pinned artifact/world | Block rather than silently substitute current state. |

## 34. Test And Verification Anchors

| Area | Test anchor | What it proves |
| --- | --- | --- |
| Performance | `V5PortfolioPerformanceApiTest`, `V5AccountPerformanceApiTest`, `V5StrategyPerformanceApiTest` | Wealth/cash-flow/benchmark behavior, account aggregation, owner attribution/no invented cash. |
| Attribution | `V5PortfolioAttributionApiTest` | Reconciled strategy/unmanaged/charges/residual output without causal fabrication. |
| Success/quality | `SuccessCriteriaEvaluatorTest`, `ReturnQualityRankingServiceTest` | Relative/opportunity success and magnitude-aware corpus/ranking distinctions. |
| Backtest inputs/results | `BacktestDuplicateTest`, `V5BacktestParameterOverrideTest` | Pinned price/assumptions, duplicate/new-run inputs, bounded overrides, provenance. |
| Lifecycle/cancel | `V5BacktestLifecycleTest` | Checkpoint cancellation and tombstone behavior. |
| Replay | `V5PortfolioReplayFoundationTest` | Pinned world, completion, immutable queued scenario, block on unavailable reconstruction, idempotent trade transition. |
| Paper simulation | `V5PaperSimulationProcessorTest`, `V5SimulationPriceServiceTest` | Effective-session checkpoint, missing-price wait, idempotent/partial economics. |
| Calendar/PIT inputs | `ScreenerBacktestCalendarTest`, dataset version/freshness tests | Calendar-aware historical screening and market input gates. |

**Test coverage gap — implementation audit follow-up:** complete dataset/artifact/model pinning across all run types, true end-to-end future-data prevention after resync, public resume/retry behavior, incomplete-result UI, and production worker recovery require direct verification.

## 35. Debugging Guide

| Symptom | Likely layer |
| --- | --- |
| Performance changed | Ledger/cash flows, price/benchmark range, performance calculator, missing-data flags. |
| Benchmark missing | Benchmark master/sync/history and run benchmark evidence. |
| Attribution does not sum | Owner attribution, unmanaged/charges/residual handling, source data. |
| Backtest changed after resync | Dataset/version attribution, as-of reader, pinned artifacts/assumptions, PIT audit. |
| Run stuck/cancel ineffective | Run status/stage, processor/worker/checkpoint, cancellation observation. |
| Resumed run differs | Original context/pins/checkpoint versus mutable current inputs. |
| Future data suspected | As-of scorer, market-data date bounds, fundamentals/model timestamps. |
| Paper confused with backtest | Replay/paper event/checkpoint versus BacktestRun simulated records. |
| Success rate inconsistent | Success criteria, trade close/holding period, benchmark/opportunity-cost inputs. |

## 36. Implementation Alignment Notes

The following accepted contracts require V1-V7 implementation/runtime verification and are not defect conclusions:

- Complete reproducibility after market-data corrections/resync despite attribution-only dataset versions.
- Full UI/reachability for run progression, cancellation, partial evidence, compare, resume/retry, and error states.
- All queue/worker retry/recovery behavior and durable checkpoint correctness in deployment.
- Precise model/fundamental provenance through every historical engine.
- End-to-end source/derived accounting separation under all analytical exports.

## 37. Historical Context

Early portfolio analytics established summary read models. V3 added outcome and return-quality ranking. V4 introduced backtest/replay foundations. V5 added pinned artifacts, reproducibility-oriented lifecycle, benchmarks, performance/tax, paper simulation, and portfolio replay. Later fundamentals/ML enrich analytics under the deterministic strategy boundary.

Current behavior is defined by this document and linked current-domain contracts, not prototype or one-off analytical code.
