# Analytics, Review, And Backtesting

## Current Behaviour

StoX analytics explain portfolio, stock, market, strategy, recommendation, tax, and review outcomes. Dashboard is the main summary surface. Explorer, market depth, indices, patterns, performance/tax, review reports, backtests, portfolio replay, and screener backtests provide deeper diagnostic views.

Review workflows generate periodic reports and dashboards from recommendations, outcomes, and metrics. Review should feed back into strategy and screener tuning rather than remain passive reporting.

Backtests are historical-only simulation runs. They are resumable, cancellable, comparable, and can create strategy drafts. They pin strategy version, entry/exit screeners, benchmark evidence, configurable parameters, assumptions, lifecycle state, trades, snapshots, and timeline output. Paper portfolios and replay workflows extend the same safety idea: simulated state must be traceable rather than confused with live brokerage/accounting state.

Exploratory analytics provide throttled ad hoc analysis. Recommendation preview, evaluation profile, market analytics, and stock research endpoints are intended to explain why the system sees a security a certain way.

## Technical Contract

Key API routes:

- Dashboard and classic analytics: `/api/dashboard`, `/api/analytics/portfolio`, `/api/analytics/stocks/{stock}`, `/api/analytics/explore`
- V1 analytics: `/api/v1/analytics/portfolio`, `/api/v1/analytics/market`, `/api/v1/analytics/dashboard`, `/api/v1/analytics/stocks/{stock}`, `/api/v1/analytics/stocks/{stock}/evaluation-profile`, `/api/v1/analytics/stocks/{stock}/recommendation-preview`, `/api/v1/analytics/stocks/{stock}/research`
- Review: `/api/v1/reviews/generate`, `/api/v1/reviews`, `/api/v1/reviews/{id}`, `/api/v1/review/dashboard`, `/api/v1/review/outcomes`
- Backtests: `/api/v1/backtests/*`
- Screener backtests: `/api/screeners/{screener}/backtest`, `/api/screener-backtests/*`
- Portfolio replay: `/api/replays/*`
- Performance/tax: `/api/analysis/*`, `/api/tax/*`

Primary models include `BacktestRun`, `BacktestRunHit`, `BacktestSnapshot`, `BacktestTrade`, `BacktestTransaction`, `ScreenerBacktest`, `ScreenerBacktestDay`, `ScreenerBacktestHit`, `ReviewReport`, `ReviewMetric`, `EvaluationRun`, `EvaluationResult`, `AnalysisEvidence`, `AnalysisPreference`, `PortfolioSnapshot`, `PortfolioReplayRun`, `PortfolioReplayCheckpoint`, `PaperSimulationEvent`, and `MarketAnalyticsSnapshot`.

Primary services include `DashboardController` dependencies, `PortfolioAnalyticsService`, `StockAnalyticsService`, `MarketAnalyticsService`, `MarketDepthService`, `ExploratoryAnalyticsService`, `EvaluationProfileService`, `RecommendationPreviewService`, `BacktestSimulationEngine`, `SimulationDayProcessor`, `SimulationContext`, `BacktestPersistenceService`, `StatisticsGenerator`, `TimelineBuilder`, `AsOfFactorScorer`, `EligibilityPrecomputeService`, `PortfolioReplayService`, `PortfolioReplayProcessor`, `ReplayStatisticsService`, `ReplayEconomicStateCalculator`, `ScreenerBacktestService`, and review controller/services.

## Data Rules

- Backtests and replays must use as-of historical evidence, not today’s mutable state.
- Configurable parameters and pinned artifact versions are part of the backtest record.
- Review metrics should be tied to persisted recommendations/outcomes.
- Dashboard cards must be derived from current portfolio/market data and should surface stale/empty state clearly.
- Exploratory endpoints are throttled and should not become hidden write paths.

## Debugging Sources

- Dashboard oddity: check portfolio summary, price freshness, market analytics bundle, top movers, calendar upcoming, and cache invalidation.
- Backtest reproducibility issue: check pinned strategy/screener versions, benchmark evidence, run assumptions, and lifecycle metadata.
- Review report mismatch: check recommendation status transitions, execution outcomes, metric aggregation period, and report generation payload.

## Related Docs

- [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md)
- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Discovery, Screeners, And Registries](./discovery-screeners-registries.md)

## Historical Context

Dashboard, Explorer, Analytics Architecture, Review, screener backtests, strategy backtests, paper simulation, and portfolio replay were specified across multiple versions. Current debugging should start from whether the question is summary, ad hoc explanation, historical simulation, or outcome review.

