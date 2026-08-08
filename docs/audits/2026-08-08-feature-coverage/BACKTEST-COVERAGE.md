# Backtest Coverage Analysis

**Date:** 2026-08-08  
**Scope:** Screener Backtesting, Strategy Backtesting, Portfolio Backtesting, Paper Trading  
**Method:** Repository inspection (backend, frontend, migrations, tests) — no code changes

---

## Executive Summary

| Type | Status | Trading simulation | Primary UI |
|------|--------|-------------------|------------|
| **Screener backtest** | IMPLEMENTED | No — hit matrix only | Screener editor (`/screeners/:id`) |
| **Strategy backtest** | IMPLEMENTED | Yes — full paper portfolio | `/backtests`, `/backtests/:id` |
| **Portfolio replay backtest** | NOT IMPLEMENTED | No | — |
| **Live paper trading** | NOT IMPLEMENTED | Internal classes only | — |

Both implemented backtest systems use resumable chunked processing (~20s budget per HTTP request).

---

## 1. Screener Backtesting

### Purpose

Historical **as-of screener hit matrix**: which stocks matched screener conditions on which weekdays. This is **eligibility discovery over time**, not trade simulation.

### Implementation Evidence

| Layer | Path |
|-------|------|
| Service | `app/app/Services/Screener/ScreenerBacktestService.php` |
| Controller | `app/app/Http/Controllers/Api/ScreenerBacktestController.php` |
| Models | `app/app/Models/ScreenerBacktest.php`, `ScreenerBacktestDay.php`, `ScreenerBacktestHit.php` |
| Migrations | `app/database/migrations/2026_07_21_000002_create_screener_backtest_tables.php`, `2026_07_21_000003_persist_screener_backtest_results_by_date.php` |
| Frontend | `app/resources/js/src/pages/ScreenerEditorPage.jsx`, `ScreenerRunsCompareTable.jsx` |
| Tests | `app/tests/Feature/ScreenerTest.php`, `app/tests/Unit/Screener/ScreenerBacktestCalendarTest.php` |

### API Routes

| Method | Route | Purpose |
|--------|-------|---------|
| POST | `/api/screeners/{screener}/backtest` | Start/resume screener backtest |
| GET | `/api/screeners/{screener}/backtest/matrix` | Hit matrix for UI |
| POST | `/api/screener-backtests/{id}/continue` | Chunked continue |
| DELETE | `/api/screener-backtests/session/{token}` | Discard session |

### Capability Matrix

| Capability | Status | Evidence |
|------------|--------|----------|
| Historical date ranges | YES | Presets `1y`, `6m`, `3m`, `1m`, `15d` in `ScreenerCatalog.php`; custom from/to |
| Weekday-only calendar | YES | `ScreenerBacktestService::weekdayDates()` |
| Scopes (holdings/watchlist/all/index) | YES | `ScreenerBacktestService` scope parameter |
| As-of OHLCV evaluation | YES | Reuses `ScreenerEvaluationService` |
| Binary hit/no-hit output | YES | Matrix UI — green = hit |
| Scoring / ranking | NO | Not applicable to screener backtest |
| BUY/SELL/HOLD/WATCH decisions | NO | Not applicable |
| Capital allocation | NO | Not applicable |
| Cash constraints | NO | Not applicable |
| Portfolio positions | NO | Not applicable |
| Transaction simulation | NO | Not applicable |
| P&L | NO | Not applicable |
| Equity curve | NO | Matrix only (stocks × dates) |
| Benchmark comparison | NO | Not applicable |
| Performance metrics | NO | Not applicable |
| Stop loss / partial sells | NO | Not applicable |
| Multiple positions | NO | Not applicable |
| Fees / slippage | NO | Not applicable |
| Configurable strategy parameters | N/A | Uses screener definition, not strategy |
| Reproducibility | PARTIAL | Per-date cache reuse on re-run |
| Persisted results | YES | `portfolio_screener_backtest_days`, `portfolio_screener_backtest_hits` |
| Resumable execution | YES | Chunked continue API |
| UI | YES | Embedded in screener editor |

### Spec Status

**Primary:** IMPLEMENTED · **V1 scope:** V1_SCOPE_AMBIGUOUS · **Secondary:** NOT_SPECIFIED

Screener backtesting is implemented (`ScreenerBacktestService`, editor UI) but has no dedicated section in V1 governance or engine specs. The Screener Specification covers live runs; historical matrix backtest is an as-built extension documented in `implementation.md`.

### V1 Scope Status (reconciled)

| Feature | Primary Status | V1 Scope | Spec Coverage |
|---------|----------------|----------|---------------|
| **Screener backtesting** (F058) | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT_SPECIFIED |
| **Strategy backtesting** (F093) | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT_SPECIFIED |
| Market gates in strategy backtest (F099) | SPECIFIED_NOT_IMPLEMENTED | V1_OUT_OF_SCOPE | Not required V1 |
| Live paper trading (F157) | OUT_OF_SCOPE | V1_OUT_OF_SCOPE | Future |

**Do not count backtests as missing.** Both screener and strategy backtests are implemented; their formal V1 product inclusion is unresolved in `MVP_SCOPE.md`.

### V1 Required?

| Feature | Answer |
|---------|--------|
| Screener backtest | **V1_SCOPE_AMBIGUOUS** — not listed in MVP_SCOPE included or excluded |
| Strategy backtest | **V1_SCOPE_AMBIGUOUS** — not listed in MVP_SCOPE included or excluded |
| Portfolio replay backtest | Not implemented; not a matrix row |
| Live paper trading | **V1_OUT_OF_SCOPE** per live-trading / MVP exclusions |

---

## 2. Strategy Backtesting

### Purpose

Full historical **paper simulation** of the active strategy: eligibility → scoring → recommendation generation → auto paper execution → daily snapshots → statistics.

### Implementation Evidence

| Layer | Path |
|-------|------|
| Engine | `app/app/Services/Backtest/BacktestSimulationEngine.php` |
| Day processor | `app/app/Services/Backtest/SimulationDayProcessor.php` |
| Paper portfolio | `app/app/Services/Backtest/PaperPortfolioManager.php`, `PaperTradeExecutor.php` |
| Scoring | `app/app/Services/Backtest/AsOfFactorScorer.php` |
| Eligibility precompute | `app/app/Services/Backtest/EligibilityPrecomputeService.php` |
| Statistics | `app/app/Services/Backtest/StatisticsGenerator.php`, `BacktestMath.php` |
| Timeline | `app/app/Services/Backtest/TimelineBuilder.php` |
| Persistence | `app/app/Services/Backtest/BacktestPersistenceService.php` |
| Controller | `app/app/Http/Controllers/Api/V1/BacktestController.php` |
| Models | `app/app/Models/BacktestRun.php`, `BacktestSnapshot.php`, `BacktestTrade.php`, `BacktestTransaction.php`, `BacktestRunHit.php` |
| Migration | `app/database/migrations/2026_07_31_000001_create_strategy_backtest_tables.php` |
| Frontend | `app/resources/js/src/pages/BacktestHistoryPage.jsx`, `BacktestDetailPage.jsx` |
| Components | `app/resources/js/src/components/backtest/BacktestPortfolioChart.jsx`, `BacktestTradeTimeline.jsx` |
| Helpers | `app/resources/js/src/utils/backtestHelpers.js` |
| Docs | `implementation.md` § Strategy Backtests; `app/public/docs/backtests.html` |

### API Routes

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/v1/backtests/meta` | Presets, status enums |
| GET/POST | `/api/v1/backtests` | List / create |
| GET/PUT/DELETE | `/api/v1/backtests/{id}` | Show / update / delete |
| POST | `/api/v1/backtests/{id}/continue` | Resumable simulation |
| GET | `/api/v1/backtests/{id}/timeline` | Trade timeline |

### Simulation Stages

`PREPARING` → eligibility precompute → `SIMULATING_DAYS` → `GENERATING_STATISTICS` → `GENERATING_REPORT` → `COMPLETED`

(Source: `BacktestSimulationEngine.php` header comment)

### Capability Matrix

| Capability | Status | Evidence |
|------------|--------|----------|
| Historical date ranges | YES | `range_key` or custom `from_date`/`to_date`; weekdays only |
| Historical eligibility | YES | `EligibilityPrecomputeService` → `portfolio_backtest_run_hits`; requires ≥1 entry screener |
| Historical strategy scoring | YES | `AsOfFactorScorer` + `StrategyConfigurationService::score()` with as-of OHLCV |
| BUY/SELL/HOLD/WATCH decisions | YES | Actions: OPEN, INCREASE, REDUCE, EXIT, HOLD, WATCH in `SimulationDayProcessor.php` |
| Entry/exit simulation | YES | `ExitStrategyEvaluator` + exit screener hits |
| Capital allocation | YES | `ScorePriorityCapitalAllocator` |
| Cash constraints | YES | Paper portfolio tracks cash; allocation respects available capital |
| Portfolio positions | YES | `PaperPortfolioManager` maintains simulated holdings |
| Transaction simulation | YES | `PaperTradeExecutor` at close; `BacktestTransaction` records |
| P&L | YES | Closed trades with `profit_loss`; per-trade stats |
| Equity curve | YES | Daily `BacktestSnapshot` → Recharts in `BacktestPortfolioChart.jsx` |
| Benchmark comparison | NO | Not implemented |
| Performance metrics | YES | CAGR, max drawdown, win rate, holding periods, utilization (`StatisticsGenerator.php`) |
| Stop loss | PARTIAL | Exit rules via strategy config; no intraday stop simulation |
| Partial sells | YES | REDUCE action supported |
| Multiple positions | YES | Portfolio rules (max positions) enforced |
| Fees / slippage | PARTIAL | Uses close price; fee model not clearly applied in paper executor |
| Configurable strategy parameters | YES | Uses pinned `TradingStrategyVersion` config |
| Reproducibility | YES | Pinned strategy version + deterministic day loop |
| Persisted results | YES | Full run/snapshot/trade/transaction tables |
| Market gates in simulation | NO | Explicitly disabled — `SimulationDayProcessor.php` ~line 80: no historical market analytics series |
| Resumable execution | YES | Chunked via `/continue` |
| UI | YES | History list + detail with chart and timeline |
| Does NOT mutate live portfolio | YES | Isolated paper context |
| Feature tests | NO | Only unit tests (`PaperPortfolioSimulationTest`, `BacktestMathTest`) |

### Prerequisites

Strategy must have at least one enabled eligibility screener (`BacktestSimulationEngine.php` lines 88–91).

### Spec Status

**Primary:** IMPLEMENTED · **V1 scope:** V1_SCOPE_AMBIGUOUS · **Secondary:** NOT_SPECIFIED

Built before formal Strategy Engine specs (`specs/architecture/strategy-engine/`). Not listed in `MVP_SCOPE.md`. `implementation.md` documents it as shipped capability.

### V1 Required?

**V1_SCOPE_AMBIGUOUS** — Not in MVP_SCOPE included or excluded lists. Useful intentional feature — document in specs, do not rebuild.

---

## 3. Portfolio Backtesting / Replay

### What Exists (Related, Not Backtest)

| Capability | Status | Evidence |
|------------|--------|----------|
| Historical holdings reconstruction | YES | `PortfolioHistoricalHoldingsService.php` |
| Snapshot rebuild from transactions | YES | `PortfolioSnapshotRebuildService.php`, `POST /portfolio/rebuild-history` |
| Dashboard history chart | YES | `DashboardPage.jsx` calls rebuild-history |

### What Is Missing

| Capability | Status |
|------------|--------|
| "What if I had followed strategy on my actual portfolio" replay | NOT IMPLEMENTED |
| Portfolio equity curve backtest UI | NOT IMPLEMENTED |
| Historical recommendation replay against real holdings | NOT IMPLEMENTED |

**Classification:** NOT IMPLEMENTED (no product feature)

---

## 4. Paper Trading (Live)

### What Exists

| Component | Purpose | Used in live product? |
|-----------|---------|----------------------|
| `PaperTradeExecutor.php` | Simulated buy/sell at close | No — backtest only |
| `PaperPortfolioManager.php` | In-memory paper state | No — backtest only |
| `SimulationContext.php` | Isolated simulation state | No — backtest only |

### What Is Missing

| Capability | Status |
|------------|--------|
| Live paper-trading mode (broker-less) | NOT IMPLEMENTED |
| Paper portfolio UI separate from backtest | NOT IMPLEMENTED |
| Paper execution queue | NOT IMPLEMENTED |

**Note:** Pending Execution (`PendingExecutionPanel.jsx`) is **real** approve → manual ledger fill workflow, not paper trading.

### Spec References

- `specs/architecture/live-trading/00-glossary.md` — future paper trading concepts
- `MVP_SCOPE.md` — broker automation explicitly excluded

**Classification:** OUT_OF_SCOPE for V1 (live paper trading product mode)

---

## 5. Cross-Cutting Comparison

```
┌─────────────────────┬──────────────┬─────────────────┬──────────────────┐
│ Dimension           │ Screener BT  │ Strategy BT     │ Portfolio Replay │
├─────────────────────┼──────────────┼─────────────────┼──────────────────┤
│ Uses strategy config│ No           │ Yes             │ N/A              │
│ Uses screeners      │ Yes (1)      │ Yes (entry+exit)│ N/A              │
│ Simulates trades    │ No           │ Yes             │ No               │
│ P&L / equity curve  │ No           │ Yes             │ No               │
│ Mutates live data   │ No           │ No              │ Yes (rebuild)    │
│ V1 spec coverage    │ Unspecified  │ Unspecified     │ Not required     │
│ Test coverage       │ Feature      │ Unit only       │ Snapshot tests   │
└─────────────────────┴──────────────┴─────────────────┴──────────────────┘
```

---

## 6. Recommendations

| Item | Action | Priority |
|------|--------|----------|
| Strategy backtest absent from specs | Add as-built doc or MVP appendix | P2 — SPEC GAP |
| Strategy backtest E2E feature tests | Add `Feature/BacktestApiTest.php` | P2 — TEST GAP |
| Market gates in strategy backtest | Requires historical market analytics series | P3 — future |
| Benchmark comparison in backtest | Not implemented | P3 — enhancement |
| Fees/slippage in paper executor | Clarify intent; implement if required | P3 — AMBIGUOUS |
| Screener backtest spec section | Document in Screener Specification | P2 — SPEC GAP |

---

## 7. Traceability

| Implementation | Spec reference |
|----------------|----------------|
| Screener backtest | Partial: `specs/architecture/domains/Screener-Specification.md` (live runs); no backtest section |
| Strategy backtest | None in V1 governance; future: `specs/architecture/strategy-engine/` (post-V1) |
| Paper trading classes | Future: `specs/architecture/live-trading/` |

**Do not rebuild** either backtest system — both are functional and should be spec-documented instead.
