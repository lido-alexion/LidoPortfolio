# V5 FEAT-020 — Paper Portfolio, Portfolio Replay, and Strategy Backtest

**Status:** COMPLETE — implemented and verified 2026-09-11
**Date:** 2026-09-06

## 1. Problem
StoX needs safe simulation surfaces for three different questions without weakening the accounting, Strategy, capital-management, execution, or audit semantics of the live product:

1. **Strategy Backtest** — Is this individual Strategy/version promising over historical data?
2. **Portfolio Replay** — How would a complete Portfolio and StoX's portfolio mechanics have behaved historically?
3. **Paper Portfolio** — How does the complete Portfolio system behave prospectively against real market data without real money or broker execution?

These are deliberately distinct. Backtest is simplified and Strategy-scoped; Replay is historical and high-fidelity at Portfolio scope; Paper is forward/current-market and high-fidelity at Portfolio scope. The intended progression is **Backtest → Replay → Paper → Live**.

## 2. Frozen behaviour

### 2.1 Strategy Backtest
- Backtest operates on one immutable published Strategy version plus its pinned Indicator/dependency versions.
- It uses fixed starting capital. BUYs and simulated charges consume capital; SELLs restore it. No borrowing or additional funding is invented.
- It excludes surrounding Portfolio mechanics: other Strategies, cross-Strategy capital competition, inter-Strategy loans/recalls, bridge funding, cross-Strategy netting, Unmanaged holdings, Kite/reconciliation and other Portfolio interactions.
- If a Strategy intrinsically depends on an excluded concept, the limitation is disclosed rather than fabricated.
- Maximum affordable quantity is executed; residual target behaviour follows the Recommendation lifetime rules.
- Configurable run-local overrides are allowed only for parameters explicitly declared configurable by the Strategy. Strategy logic and Indicator formulas cannot be edited in Backtest.
- Overrides do not mutate the published Strategy. A result using overrides is clearly identified as modified parameters.
- V5 does not automatically optimize/search parameter combinations, rank winners or promote parameters into production.
- A Backtest may create a new unpublished FEAT-008 Strategy Draft carrying the tested configurable parameters and Backtest provenance. Normal validation/publication/deployment still applies.
- FEAT-008 declares configurability through its immutable `configurable_parameters` schema. Backtest accepts overrides as a key/value map, rejects undeclared keys and values outside the declared type/bounds/choices, and records the applied map as immutable run evidence. Creating a Draft increments the source lineage's patch SemVer, changes only declared definition paths, and records the source artifact version and Backtest run as provenance. A shared artifact must first be Forked into the Investor's own Library.

### 2.2 Portfolio Replay
- Replay is Portfolio-scoped and uses production StoX economic semantics wherever applicable: multiple Strategies, Strategy ownership, capital/funding state and limits, soft loans, inter-Strategy lending/recalls, bridge funding, competing capital, target-seeking/partial execution, internal netting/reallocation and actual simulated state-driven execution.
- Historical market time changes; StoX Portfolio economics do not.
- Replay supports two starting-state modes:
  1. **New simulated Portfolio:** starting Portfolio cash, one or more Strategies, no initial holdings unless simulated.
  2. **Branch from historical Portfolio state:** choose a real Portfolio/date and reconstruct holdings, cash, Strategy ownership, loans/capital state and other reconstructable state. The branch is isolated and later real activity never leaks into it.
- Starting state is immutable run evidence.
- Replay branched from history defaults to Strategy versions actually bound at the selected starting point, but the Investor may explicitly substitute selected published versions for a counterfactual Replay. Versions are then pinned for the run.
- Replay is deterministic and non-interactive once started. Capital injections, manual trades, Strategy changes, loan changes or other mid-run interventions are prohibited in V5. A different scenario requires a different run.
- Replay may compare a branch with authoritative actual history, including ending value, XIRR/TWR, benchmark/excess return, risk, cash utilization, Strategy contribution, Recommendations/trades, fees, capital constraints, loans/recalls and divergence periods. This is comparison, not causal attribution.
- New simulated Replay has no actual baseline; it may compare with benchmark or compatible Replay runs.
- Replay has no direct “make Live” conversion.

### 2.3 Paper Portfolio
- `Portfolio type = LIVE | PAPER` is immutable from Portfolio creation. Real↔Paper conversion is never allowed.
- Paper requires starting simulated cash and does not require Kite.
- Paper exposes only Manual broker-execution mode; Semi-Automatic/Automatic are unavailable because those represent real broker authority.
- Independently of broker mode, Paper intrinsically auto-simulates eligible Recommendations. This is not Automatic trading and does not consume FEAT-040's one-live-Portfolio slot.
- Kite connection, submission and reconciliation are structurally unavailable for Paper. Account Emergency Halt/Kite kill switches do not stop Paper simulation.
- Paper reuses the normal Portfolio accounting/domain model rather than a duplicate paper-holdings subsystem. Simulated Execution → Trade → Transaction updates Paper holdings, WAVG cost, Portfolio cash, Strategy ownership, loans/capital state, realized/unrealized P&L and subsequent Recommendation state.
- All simulated records carry immutable Paper/Simulated provenance.
- Existing Holdings, Transactions, Strategies, Recommendations, Cash and performance surfaces are reused where meaningful, with persistent PAPER identification.
- Paper is excluded from real Account wealth/performance and real Account tax. FEAT-015 may calculate performance within the Paper Portfolio itself.
- Manual Paper transactions are allowed as explicit **Investor interventions**. They use only simulated assets, affect Paper state normally, remain distinguishable from Strategy-generated simulation and are counted/disclosed in history/analytics. StoX does not retrospectively calculate a hypothetical “without intervention” result.
- Paper can be archived/restored under normal lifecycle rules. Archive stops evaluation/simulation; restoration is prospective and does not backfill the archived period.
- Paper additionally supports lightweight **Pause simulation**. Investor Pause stops evaluation, new Recommendations, simulated executions and manual interventions. Resume is prospective: deliberately paused sessions are skipped and never caught up. The discontinuity is disclosed in history/analytics.
- System/data interruption is different from Investor Pause: system interruption catches up deterministically.

## 3. Historical clock and market data

### 3.1 Eligible sessions and causality
- FEAT-038 trading calendar defines eligible sessions.
- V5 simulation is daily/EOD; no tick/minute simulation and no fabricated intraday event sequence.
- A Recommendation generated after trading-day D data availability remains pending and may execute on the next eligible trading session according to its normal lifetime/rules.
- Simulation preserves causal ordering. Later Strategy decisions cannot be evaluated against state that should already contain an unresolved earlier simulated execution.

### 3.2 Execution-price methodology
Paper and Replay support a pinned simulated execution-price method:
1. **Next Open** — default.
2. **Next Close**.
3. **OHLC average** — `(O + H + L + C) / 4`.
4. **High/Low midpoint** — `(H + L) / 2`.

Backtest supports the same configurable historical execution-price choices where applicable.

- Required market observations must exist; StoX never fabricates a fill price.
- Replay/Backtest may apply an optional adverse slippage/stress adjustment, default `0%`.
- Paper has no mandatory slippage and defaults to none; its OHLC-based execution method is already an approximation.
- Simulated brokerage/statutory charges are separate from execution-price methodology. Use a versioned platform charge model; exact historical accuracy is claimed only where an effective-dated model supports it.
- Evidence records source OHLC/observation, pricing method, adjustment and resulting price.

### 3.3 Effective time vs processing time
- Simulated execution has an economic/effective market session distinct from its database creation/processing timestamp.
- If D+1 market data becomes available later, a D+1 intended simulated execution remains economically D+1; processing latency never moves it to a later market date.
- Future observations may not leak backward to select a favourable execution.
- While required data is unavailable the item is **Pending simulated execution**.
- If required data never becomes available, execution remains unresolved/failed with explicit evidence rather than an invented price.

### 3.4 Paper catch-up
- After infrastructure/data interruption, Paper processes missed work chronologically from the last trustworthy checkpoint until current processable state.
- It does not skip or collapse missed sessions.
- Each event retains effective date and actual processing timestamp.
- Catch-up may execute faster than real time.
- If an earlier unresolved event blocks trustworthy continuation, status is Behind/Waiting for data.
- Manual interventions are blocked while catch-up is incomplete.

## 4. Historical readiness and point-in-time integrity
- Before Backtest/Replay, StoX assesses required data as **Ready**, **Ready with limitations**, or **Blocked**.
- StoX never silently shortens a requested period. If critical historical evidence is missing, show what is missing and the earliest usable date; the Investor must explicitly change the requested period.
- Non-critical missing evidence may yield an explicitly incomplete result.
- Replay additionally validates reconstructability of a requested historical starting Portfolio state.
- Every run pins its Strategy/Indicator/dependency world at creation and uses it for the whole historical period.
- Calculations for historical date D may consume only evidence that would have been available by D under the applicable rules. Current corrected datasets may be authoritative sources, but future observations cannot leak backward.

## 5. Capital and execution mechanics

### 5.1 Backtest
- Fixed starting capital only.
- BUYs/charges consume cash; SELLs restore cash.
- No borrowing/additional funding.
- Maximum affordable whole-share progress toward the Recommendation target is valid; partial/unexecuted capital constraints are reported.

### 5.2 Replay and Paper
- Use production StoX Portfolio mechanics wherever meaningful: Strategy capital/funding state, limits, loans/recalls, bridge funding, competing capital, ownership, target-seeking/partial execution and internal netting.
- Recommendation → execution is driven by actual simulated Portfolio/Strategy state, not an isolated theoretical quantity.
- Reuse production domain services/rules where practical rather than implementing a second economic model.

## 6. Outputs and analytics

### 6.1 Backtest outputs
Include, subject to completeness:
- starting/ending capital and value;
- XIRR and TWR;
- benchmark and Excess Return;
- volatility, maximum drawdown and Sharpe;
- realized/unrealized results;
- charge/slippage impact;
- trade count;
- Recommendation→execution statistics;
- capital-constraint/full/partial/unexecuted statistics;
- equity curve;
- holdings, Trades and Recommendations;
- completeness/limitations.

Compatible Backtest runs may be compared side-by-side. Differences in assumptions/parameters are prominently disclosed. StoX does not automatically rank/promote a “winner”.

### 6.2 Replay outputs
Replay exposes corresponding Portfolio-level performance/evidence plus high-fidelity Portfolio mechanics such as Strategy contributions, capital constraints, loans/recalls, internal allocation/netting and divergence from actual history where an actual baseline exists. Compatible Replay runs may be compared side-by-side.

### 6.3 Paper outputs
Paper uses normal Portfolio analytical surfaces where meaningful. Performance is Paper-only and clearly labelled. Manual intervention counts/events and Pause discontinuities are disclosed so Paper results cannot be mistaken for an untouched experiment.

## 7. Run evidence and reproducibility
- Backtest/Replay runs preserve immutable Run Evidence: parameters, artifact versions, starting state, calendar/config assumptions, execution-price method, charge/slippage assumptions, market-data provenance/version or sufficient fingerprints, generated Recommendations, simulated executions/trades, key resulting states/metrics and completeness/limitations.
- The entire historical Universe dataset is not duplicated per run merely for byte-for-byte reproducibility.
- Completed run results remain immutable historical evidence.
- **Rerun using current data** creates a new run and may differ after historical corrections/backfills.
- Where practical StoX discloses when current source data differs from original run fingerprints.
- Exact recomputation of an old run is not promised after original market data changes unless required source data remains retained.

## 8. Run lifecycle, scheduling and resource model
- Backtest/Replay states include Queued, Running, Completed, Failed and Cancelled, with waiting/incomplete detail where applicable.
- Multiple analytical runs may be submitted. Product semantics do not promise simultaneous execution.
- Paper daily/catch-up processing has priority over analytical Backtest/Replay capacity.
- Investor may cancel queued/running analytical runs at a safe checkpoint.
- Use a bounded, resumable, idempotent simulation processor compatible with cPanel execution limits. Each invocation processes a safe slice, persists a durable checkpoint and resumes later.
- Retries/interruption must not duplicate Recommendations, executions, Trades, Transactions, loans or other state.
- A long Replay remains one logical run even if executed through many infrastructure slices.
- No browser must remain open.
- The domain processor remains infrastructure-neutral; Redis/Celery/permanent workers are not V5 requirements and current cPanel constraints are not encoded as permanent domain rules.
- Failed/retryable infrastructure slices preserve committed deterministic progress and resume from safe checkpoints. Permanent data/domain failures are surfaced explicitly rather than silently skipped.

## 9. Retention
- Live/Paper financial history follows durable Portfolio/audit rules.
- Backtest/Replay are analytical artifacts and may be deleted by the Investor, including bulk deletion.
- Deletion removes detailed generated simulation data/results and leaves only a minimal durable audit tombstone: run ID/type, Portfolio/Strategy context, created/deleted timestamps, status and deleting actor.
- Deleted runs disappear from normal history/comparison; dependent comparisons become unavailable.
- No recycle bin/restoration or automatic retention expiry in V5.

## 10. UX
- Simulation must present the three concepts distinctly: **Strategy Backtest**, **Portfolio Replay**, **Paper Portfolio**.
- Backtest is initiated from Strategy context and run history/results remain Strategy-oriented.
- Replay is initiated/viewed in Portfolio context and exposes complete Portfolio mechanics/evidence.
- Paper is a first-class Portfolio type and uses normal Portfolio navigation with persistent PAPER identification rather than duplicate Paper-only accounting screens.
- Analytical run screens expose configuration, readiness, queue/progress/status, assumptions, completeness, evidence, outputs, comparison and cancellation as applicable.
- Waiting/Behind/Incomplete/Failed states explain the blocking data or condition instead of presenting a misleading successful result.

## 11. Notifications
Use FEAT-004 rather than a simulation-specific notification subsystem.
- Routine successful daily Paper processing is silent/in-app history rather than noisy external notification.
- Paper conditions requiring Investor attention (persistent inability to progress, critical missing data/domain failure, etc.) may create deduplicated FEAT-004 Action-required/Critical conditions according to severity.
- Backtest/Replay completion/failure may use an Info notification when asynchronous completion is useful; failure requiring user action may be Action required.
- Notification state never substitutes for the authoritative simulation/run status.

## 12. Acceptance criteria
1. Investor can create a PAPER Portfolio only at Portfolio creation; its type cannot later change.
2. PAPER can never connect/submit to Kite or participate in reconciliation or the one-live-Semi/Automatic invariant.
3. Eligible Paper Recommendations automatically produce simulated accounting through normal Portfolio domain semantics when required next-session data becomes available.
4. Simulated records preserve effective market session separately from processing timestamp and never use future data for historical decisions.
5. Paper catches up system interruptions chronologically but deliberately skips Investor-Paused periods.
6. Manual Paper interventions are distinguishable and disclosed in analytics/history.
7. Backtest executes one pinned Strategy/dependency world with simplified fixed-capital mechanics and supports explicit run-local configurable parameter overrides.
8. Replay executes a pinned complete Portfolio world with production capital/ownership/netting/loan semantics and supports new-simulation or reconstructable historical-branch starting state.
9. Replay cannot be modified after start; alternate scenarios require new runs.
10. Missing critical historical data blocks rather than fabricates or silently truncates a simulation.
11. Backtest/Replay execution-price methodology and assumptions are pinned and evidenced.
12. Completed run evidence/results are immutable; rerun creates a new run against current authoritative data.
13. Multiple analytical jobs may queue; bounded idempotent processing survives cPanel invocation limits without duplicate economic records.
14. Paper processing receives priority over analytical runs.
15. Backtest/Replay runs can be cancelled and deleted; deletion leaves the required audit tombstone.
16. Backtest can create an unpublished FEAT-008 Strategy Draft from tested configurable parameters; no simulation can directly become Live.
17. Paper data never enters real Account tax or real Account wealth/performance.
18. UI clearly distinguishes Backtest, Replay, Paper and Live and exposes limitations/completeness rather than misleading precision.

## 13. Dependencies
- FEAT-007 Indicator Registry/version/evidence semantics.
- FEAT-008 Trading Artifact Framework publication, pinning, bindings and Strategy Draft workflow.
- FEAT-004 Notification Service.
- FEAT-013 historical holdings/cash/value and export/compare foundations.
- FEAT-015 performance, benchmarks, attribution/risk and tax boundaries.
- FEAT-038 Exchange Calendar.
- FEAT-039 target-seeking execution, internal netting and execution semantics.
- FEAT-040 reconciliation/live-Portfolio invariants, while Paper remains structurally outside broker reconciliation.
- Existing transaction, WAVG accounting, Strategy ownership, cash/capital, loan/recall and Recommendation domain services.

## 14. Non-goals for V5
- Real↔Paper conversion.
- Clone Portfolio as Paper (preserved for V6).
- Tick/minute/intraday simulation or fabricated intraday sequencing.
- Automatic parameter optimization/search or automated Strategy promotion.
- Mid-Replay interactive scenario injection.
- Direct Replay/Paper-to-Live conversion.
- Paper Kite connectivity, real orders or reconciliation.
- Exact historical charge reproduction where effective-dated source rules are unavailable.
- Full historical Universe duplication per run solely for exact recomputation.
- Permanent worker/Redis/Celery dependency.
- Automatic expiry of analytical runs.
- Recycle-bin restoration for deleted analytical runs.
- Causal claims from actual-vs-Replay divergence.
