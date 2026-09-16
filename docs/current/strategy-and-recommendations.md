# Strategy And Recommendations

## Current Behaviour

Strategy is portfolio policy. It defines what stocks are eligible, how factors are scored, how thresholds map to labels, what exit rules apply, how portfolio construction works, how capital is allocated, and which screeners participate in entry/exit logic.

Saving strategy configuration changes policy only. It does not automatically create new recommendations. The decision pipeline must run to generate or refresh recommendations.

Recommendations are persisted lifecycle records. They can be generated, listed, shown, reviewed, reopened, expired, cancelled for execution, marked through execution paths, notified, reviewed historically, and resolved for capital. Recommendations can be actionable or informational depending on strategy, market gates, execution plan, capital availability, and review decisions.

Recommendation preview provides a stock-level view by combining persisted current recommendation state, deterministic strategy evaluation, market context, and explanation payloads. It is a diagnostic and UX aid; it must not silently mutate recommendation state.

Capital resolution is part of recommendation readiness. It can use available cash, pending sale proceeds, capital requests, lending approval, recall windows, bridge loans, and weakest-position sale logic depending on current state.

## Technical Contract

Key API routes:

- Strategy: `/api/v1/strategy`, `/api/v1/strategy/summary`, `/api/v1/strategy/catalogue`, `/api/v1/strategy/screeners`, `/api/v1/strategy/eligibility`, `/api/v1/strategy/scoring`, `/api/v1/strategy/exit`, `/api/v1/strategy/factors`, `/api/v1/strategy/thresholds`, `/api/v1/strategy/portfolio-rules`, `/api/v1/strategy/capital-allocation`, `/api/v1/strategy/recommendation-rules`
- Recommendations: `/api/v1/recommendations/generate`, `/api/v1/recommendations`, `/api/v1/recommendations/pending-execution`, `/api/v1/recommendations/{id}`, `/api/v1/recommendations/{id}/review`, `/api/v1/recommendations/{id}/reopen`, `/api/v1/recommendations/{id}/cancel-execution`, `/api/v1/recommendations/{id}/expire`, `/api/v1/recommendations/{id}/reviews`
- Preview: `/api/v1/analytics/stocks/{stock}/recommendation-preview`
- Capital resolution: `/api/v1/capital/*`, `/api/v1/recommendations/{recommendation}/capital-resolution`
- Pipeline: `/api/v1/pipeline/run`

Primary models include `TradingStrategy`, `TradingStrategyVersion`, `StrategyScreener`, `TradingRecommendation`, `RecommendationReview`, `ExecutionDecision`, `CapitalRequest`, `CapitalLoan`, `CapitalRecall`, `RecallBridgeLoan`, and `PendingSaleProceeds`.

Primary services include `StrategyConfigurationService`, `StrategyEligibilityService`, `StrategyPositionTargetService`, `StaggeredEntryCalculator`, `MinimumActionableAmountResolver`, `WholeShareQuantityCalculator`, `BuyCooldownEvaluator`, `RecommendationPreviewService`, `DecisionPipelineScheduleService`, `RecommendationSupersessionService`, `RecommendationExecutionLifetime`, `RecommendationExecutionNotificationService`, `MarketAnalyticsService`, `CapitalResolutionService`, `RecommendationLendingCoordinator`, `LenderEligibilityService`, `LenderRankingService`, `CapitalFillOrderService`, `ReturnQualityRankingService`, and `ExitPrecedenceEvaluator`.

## Scoring And Policy Rules

- Strategy indicator/factor weights are normalized and validated.
- Disabled indicators/factors do not contribute to score.
- Thresholds and labels are configuration-driven.
- Eligibility links to configured screeners and cached market data.
- Market gates can block otherwise eligible entries.
- Exit rules can produce sell intent for holdings and must be evaluated with precedence.
- Position sizing uses score/allocation/cash/minimum-actionable rules rather than raw desire to buy.
- Capital/cash shortfalls should create explicit resolution state rather than hidden failure.

## Debugging Sources

- Expected recommendation missing: check dataset status, active strategy version, linked screeners, candidate generation, evaluation score, market gate, current holding/position target, cash/capital resolution, and supersession.
- Wrong score/label: inspect strategy config normalization, factor weights, enabled indicators, source metrics, and `StrategyConfigurationService::score`.
- Preview disagrees with list: determine whether preview is showing persisted recommendation state, freshly derived diagnostic state, or unavailable payload.

## Related Docs

- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Discovery, Screeners, And Registries](./discovery-screeners-registries.md)
- [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md)
- [Execution, Broker, And Safety](./execution-broker-safety.md)
- [Analytics, Review, And Backtesting](./analytics-review-backtesting.md)

## Historical Context

V3 established multi-strategy identity, ownership, lending, recalls, ranking, exits, and chart-aware behaviour. Later specs added preview, holiday-aware execution coordination, paper/replay, artifact registries, fundamentals, and ML. Current docs collapse that into the strategy/recommendation contract above.

