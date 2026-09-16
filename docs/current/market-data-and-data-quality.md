# Market Data And Data Quality

## Current Behaviour

StoX relies on cached market data rather than live-fetching during screeners or strategy runs. The stock master, OHLCV price history, index data, market analytics snapshots, fundamentals, ML predictions, and dataset publication state together define whether downstream workflows are trustworthy.

Equity data supports NSE/BSE identity, dual-listed repair, BSE scrip codes, series, activation/deactivation, and admin stock maintenance. Universe price sync maintains OHLCV for the tradable universe. Index sync maintains supported benchmark/index histories and constituents. Holiday-aware scheduling prevents market workflows from assuming every calendar day is a trading day.

Data quality detects unresolved issues such as price gaps, corporate-action anomalies, adjustment-factor concerns, and stale or incomplete datasets. Some quality issues gate discovery/evaluation/recommendation workflows. Admins can inspect, accept, reject, clear, ignore, and auto-resolve selected issues.

Fundamental data and ML scoring are V7-era analytical additions. Fundamentals store fact history and freshness. ML models can be retrained, promoted, rolled back, and used for stock-level predictions without silently replacing deterministic strategy behaviour.

## Technical Contract

Key API routes:

- `/api/stocks`, `/api/stocks/search`, `/api/stocks/validate`, `/api/stocks/{stock}/prices`, `/api/stocks/{stock}/market-prices`
- `/api/indexes`, `/api/indexes/page`, `/api/indexes/comparison`, `/api/indexes/{symbol}/constituents`
- `/api/market-depth`
- Admin `/api/universe-price-sync/*`, `/api/sync/daily`, `/api/sync/backfill/{stock}`, `/api/sync-logs/*`
- Admin `/api/data-quality/*`
- `/api/v1/securities`, `/api/v1/price-bars`, `/api/v1/dataset/status`, `/api/v1/imports`
- `/api/v1/market-analysis/*`
- `/api/v1/stocks/{stock}/fundamentals`, `/api/v1/stocks/{stock}/ml-predictions`
- Admin `/api/v1/admin/fundamentals/*`, `/api/v1/admin/ml/*`

Primary models include `Stock`, `StockPrice`, `StockMetric`, `SyncLog`, `SyncRun`, `DatasetVersion`, `IgnoredPriceGap`, `DataQualityIssue`, `DataQualityIssueEvidence`, `DataQualityIssueResolution`, `PriceAdjustmentFactor`, `MarketDepthSnapshot`, `MarketAnalyticsSnapshot`, `Benchmark`, and the V7 fundamental/ML models created by `2026_09_12_100001_v7_stox_fundamentals_and_ml.php`.

Primary services include `StockMasterSyncService`, `StockPriceHistoryService`, `StockQuoteService`, `PriceFetchService`, `ProviderResolverService`, `NsePriceProvider`, `BseBhavcopyPriceProvider`, `YahooPriceProvider`, `AlphaVantagePriceProvider`, `UniversePriceSyncService`, `UniversePriceBatchExecutor`, `IndexPriceSyncService`, `IndexConstituentService`, `NseHolidaySyncService`, `DataQualityGuardService`, `DataQualityIssueService`, `DataQualityResolutionService`, `CorporateActionPriceRepairService`, `DataQualityCorporateActionHeuristicService`, `BenchmarkPriceSyncService`, `MarketDepthService`, `MarketAnalyticsService`, `FundamentalDataService`, `YahooFundamentalDataProvider`, and `MlScoringService`.

Console commands include daily sync, universe maintenance, stock master sync, universe price sync, gap filling, index sync, holiday sync, corporate-action sync/repair, data-quality auto-resolve, and ML/fundamental admin flows.

## Data Rules

- Screeners use cached OHLCV; a screener run should not hide stale-data problems by fetching live ad hoc.
- Dataset publication state is the gate for downstream Trading OS runs.
- Price repair and corporate-action adjustment must preserve auditability through issues/evidence/resolutions.
- Admin-deactivated stocks are excluded from discovery/evaluation/screener/pattern/recommendation contexts.
- ML scores are advisory/shadow analytical data unless an explicit future decision makes them strategy-authoritative.

## Debugging Sources

- Empty screener/recommendation output: check dataset status, price gaps, stock activation, index availability, and data-quality gates before debugging strategy.
- Strange historical chart or analytics: check adjustment factors, ignored gaps, corporate-action repair history, and index/symbol identity.
- Missing fundamentals/ML: check admin settings, provider run status, fact freshness, model promotion state, and stock-level prediction endpoint.

## Related Docs

- [Discovery, Screeners, And Registries](./discovery-screeners-registries.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Analytics, Review, And Backtesting](./analytics-review-backtesting.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

Earlier specs described the data engine, market analysis, corporate-action repair, holiday calendar, fundamental data, and ML as separate feature releases. Current debugging should treat them as one dependency chain.

