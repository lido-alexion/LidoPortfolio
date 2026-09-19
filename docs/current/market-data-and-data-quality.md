# Market Data And Data Quality

## 1. Purpose And Scope

This document owns the security master, cached market prices, indices, trade-calendar inputs, sync/repair lifecycle, data-quality gates, dataset freshness/version attribution, fundamentals, and ML feature provenance. It does not own accounting transactions, holdings restatement, or corporate-action financial ledger treatment; see [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md).

## 2. Security Master

`Stock` is the security master identity. It records canonical symbol/exchange identity, provider-specific identifiers such as BSE scrip/series where applicable, activation/admin-deactivation state, and benchmark/index roles. Provider symbols are translated into the canonical StoX security identity; downstream strategy, ledger, and analytics consumers must not depend directly on a provider payload.

Admin manages global security-master state. Inactive/admin-deactivated securities are excluded from discovery, evaluation, screener, pattern, and recommendation contexts rather than silently treated as valid candidates.

**Current implementation anchors:** `Stock`, `EquityUniverseService`, `StockMasterSyncService`, `AdminStockController`, and stock routes in `routes/api.php`.

## 3. OHLCV Data Model

The supported canonical price history is daily OHLCV. A bar is identified by security and market session/date and carries open, high, low, close, and volume. Sync/upsert must preserve that identity and avoid duplicate bars. Missing values are not zero; unavailable or invalid bars require explicit data-quality/gap treatment.

Price series may be repaired for split/bonus continuity. That is a market-data transformation, not a financial ledger operation. Live quote/depth data is separate from cached historical OHLCV.

**Current implementation anchors:** `StockPrice`, `StockPriceHistoryService`, `PriceFetchService`, `StockPriceController`, and `StockPriceHistoryServiceTest`.

## 4. Provider Architecture

Provider adapters normalize external payloads before domain use. Current provider-facing services include NSE, BSE bhavcopy, Yahoo, and Alpha Vantage adapters, resolved through provider/fetch services. Source selection/fallback remains an implementation concern; consumers receive canonical market-data records, validation outcomes, and sync evidence rather than provider-native shapes.

Provider identity, response failure, and source-specific translation should remain observable in sync/log evidence. Provider credentials are runtime configuration and must never be exposed in this document or client payloads.

**Current implementation anchors:** `PriceProviderInterface`, `ProviderResolverService`, `PriceFetchService`, `NsePriceProvider`, `BseBhavcopyPriceProvider`, `YahooPriceProvider`, and `AlphaVantagePriceProvider`.

## 5. Market Data Sync Lifecycle

The required lifecycle is:

`scheduled/manual sync -> provider fetch -> normalize -> validate -> persist/upsert -> detect gaps/anomalies -> data-quality evidence -> successful-sync freshness/version update`.

Daily, universe, index/benchmark, historical backfill, and targeted gap fill are distinct entry paths where supported. A scheduler invocation or provider request is not proof of usable data; success, coverage, validation, and downstream gate outcomes must be recorded separately.

**Current implementation anchors:** `DailyMarketSyncService`, `UniversePriceSyncService`, `UniversePriceBatchExecutor`, `IndexPriceSyncService`, `DailyMarketDataJob`, and sync controllers/commands.

## 6. Sync State And Observability

Sync observability includes attempted/successful time, status, counts, failures, partial outcomes, progress/locks, and sync logs/admin surfaces. A partial run must say what succeeded and failed; it must not be promoted to complete dataset readiness merely because the command ran.

Universe gap work exposes enabled/in-progress state, scan/fill mode, required-through session, history window, progress, inventory, latest run, failures, and ignored-gap evidence. Stale locks recover under the service's bounded stale-run rules rather than persisting forever.

**Current implementation anchors:** `SyncLog`, `SyncRun`, `SyncLogService`, `PriceHistoryGapService::status()`, `UniversePriceSyncPage`, and sync feature tests.

## 7. Gap Detection

Expected sessions are calculated against `TradingCalendar` and the required price window. Gap detection reports missing history ranges for eligible security/session coverage; weekends and trade holidays are not expected sessions. An ignored gap is a recorded, auditable exception to a particular gap expectation, not a fabricated bar or a claim that provider data exists.

Gaps can arise from missing provider history, inactive/security status, justified non-trading days, or unresolved fetch/repair failure. They remain explicit quality evidence until repaired, ignored by an authorized decision, or otherwise resolved with evidence.

**Current implementation anchors:** `PriceHistoryGapService`, `IgnoredPriceGap`, `IgnoredPriceGapService`, `TradingCalendar`, and `PriceHistoryGapServiceTest`.

## 8. Gap Repair Lifecycle

`gap detected -> repair candidate -> targeted provider fetch -> repaired / ignored / failed -> quality state updated`.

Repair scans database history before fetching providers, targets only affected securities/ranges, preserves sync/error evidence, and supports bounded retry/progress recovery. Repair must never fabricate a missing bar; when no trustworthy provider result exists it remains failed/unresolved or is recorded as an authorized ignored gap.

**Current implementation anchors:** `PriceHistoryGapService::scanAll()` / `fillAll()`, `FillPriceHistoryGapsCommand`, `StockPriceHistoryService`, `IgnoredPriceGapService`, and gap/ignored-gap tests.

## 9. Data-Quality Issues

Quality evidence covers missing/gapped history, stale/incomplete data, duplicate/invalid bars, suspicious zero/negative values, provider/source mismatch, and corporate-action discontinuity/adjustment concerns where detected. Issues retain evidence and resolution history so Admin action can be reviewed rather than overwriting the original signal.

Admin may inspect, accept/reject, clear, ignore, or auto-resolve supported issue categories. Resolution changes data-quality workflow state, not financial accounting or source facts without a dedicated repair path.

**Current implementation anchors:** `DataQualityIssue`, `DataQualityIssueEvidence`, `DataQualityIssueResolution`, `DataQualityIssueService`, `DataQualityResolutionService`, and `/api/data-quality/*`.

## 10. Data-Quality Guard

`DataQualityGuardService` supplies downstream block evidence for stocks whose market data is pending quality review. Discovery, evaluation, strategy/recommendation generation, analytics, and backtests that require trustworthy data must fail closed or mark unavailable/blocked rather than treating compromised input as a valid zero or neutral score.

The guard is an input-integrity boundary. It does not alter recommendation/accounting history; it informs whether a new analytical decision is permitted.

**Current implementation anchors:** `DataQualityGuardService`, `EvaluationEngine`, `DailyDecisionPipeline`, `DataQualityPipelineGatingTest`, and `DataQualityEvaluationGatingTest`.

## 11. Dataset Freshness

The daily decision pipeline uses the last successful market dataset sync timestamp. It permits age **up to and including 24 hours** on ordinary weekdays and **up to and including 72 hours on Monday** in the configured sync timezone. If no successful timestamp exists or age exceeds the applicable bound, Discovery and later decision stages are stopped with `dataset_not_fresh`.

This gate is timestamp-based and intentionally does **not** apply exchange-holiday/calendar logic. Freshness is not merely “a newest row exists,” and a successful sync timestamp is not a complete assertion about every security's quality; separate coverage/gap/quality evidence still applies.

**Current implementation anchors:** `DatasetFreshnessGate`, `DailyMarketSyncService::lastSuccessfulSyncAt()`, `DatasetPublishGateTest`, and `DatasetFreshnessGateTest`.

## 12. Dataset Versioning

A successful market sync inserts an immutable `DatasetVersion` attribution record. Its key is derived from the successful sync instant and the then-latest OHLCV date; it stores sync time, latest price date, price-bar count, and active-security count. Later syncs create a new version; existing records cannot be updated or deleted.

Dataset version is attribution, not a full physical OHLCV snapshot. Evaluations, backtests, and recommendations must retain the relevant dataset/version evidence so an old run is not silently reinterpreted as having used today's data.

**Current implementation anchors:** `DatasetVersion`, `DatasetVersionLedger`, `MarketDataRepository::inspectionCounts()`, `DatasetVersioningTest`, and `/api/v1/dataset/status`.

## 13. Point-In-Time Read Rules

Historical consumers must read only bars/facts effective and available for the relevant historical date; future bars cannot leak into a backtest or historical evaluation. Dataset/version attribution and generated timestamps make the source context inspectable. Corporate-action price adjustment must use the approved price-series policy without changing ledger truth.

Current dataset versions do not themselves preserve a complete immutable bar snapshot. Any claim that all historic replay reads are fully isolated from later physical resync needs implementation-audit verification.

**Current implementation anchors:** `MarketDataRepository`, simulation/backtest services, `DatasetVersionLedger`, and [Analytics, Review, And Backtesting](./analytics-review-backtesting.md).

## 14. Indices And Market Benchmarks

Supported benchmark/index securities have canonical records and synced price histories. They support relative strength, market analysis, discovery entities, comparisons, and benchmark reporting. Missing/short benchmark history must be exposed as unavailable rather than silently substituting a different index or fabricating values.

**Current implementation anchors:** `Benchmark`, `BenchmarkPriceSyncService`, `IndexPriceSyncService`, `IndexConstituentService`, `IndexPriceSyncServiceTest`, and index API routes.

## 15. Trading Calendar And Holidays

`TradingCalendar` provides expected-session logic using weekends and active trade-holiday records. Exchange holiday sync imports/updates market-calendar metadata; Admin correction is constrained to the calendar/holiday workflow. A future holiday correction can affect future expected sessions, while execution-window dates that have already begun follow the frozen execution lifecycle in the execution domain.

Calendar events may be portfolio events or global Admin trade holidays. Market holiday truth is a data/operations input, not merely a calendar decoration.

**Current implementation anchors:** `TradingCalendar`, `NseHolidaySyncService`, `CalendarEvent`, `CalendarEventService`, `NseHolidaySyncServiceTest`, and `TradingCalendarTest`.

## 16. Corporate-Action Market-Data Adjustment

Split/bonus market-data repair uses adjustment factors to restate the appropriate historical price/volume series, supports preview/repair evidence, and enables downstream charts/metrics to retain comparable history. Corporate-action discontinuity can surface as a quality concern and must remain auditable.

**Price-series repair must not itself create financial transactions or holdings changes.** Ledger/holding restatement is a separate accounting workflow and requires its own evidence and orchestration.

**Current implementation anchors:** `PriceAdjustmentFactor`, `CorporateActionPriceAdjustmentService`, `CorporateActionPriceRepairService`, and corporate-action price repair tests.

## 17. Market Depth And Live Quotes

Market depth/live-quote data is represented separately from cached daily OHLCV through `MarketDepthSnapshot` and market-depth/quote services. Consumers must surface timestamp/freshness or unavailable state; a cached snapshot is not automatically a live execution quote.

Execution quote policy and live-order safety are defined in the execution contract.

**Current implementation anchors:** `MarketDepthService`, `MarketDepthSnapshot`, `MarketPriceService`, `StockQuoteService`, and `MarketDepthServiceTest`.

## 18. Fundamentals

V7 fundamentals ingestion normalizes provider facts into period/effective-date/versioned provenance records and tracks update runs/jobs/settings. Missing or stale fundamentals must remain visible as unavailable/stale evidence; they do not make deterministic market-data eligibility valid or replace cached OHLCV requirements.

Fundamental data can enrich analysis under the strategy boundary but is not financial ledger truth.

**Current implementation anchors:** `FundamentalDataService`, `FundamentalUpdateService`, `YahooFundamentalDataProvider`, `V7\FundamentalFact`, `V7\FundamentalUpdateRun`, and `FundamentalDataIntegrationTest`.

## 19. ML Feature Inputs

ML inputs/predictions require model, feature/data, source timestamp, and prediction provenance sufficient to identify the data/model used. Historical use must follow point-in-time discipline. ML remains additive/advisory unless explicitly configured through the deterministic strategy architecture; it may not silently replace deterministic screeners, gates, or recommendation authority.

**Current implementation anchors:** `MlScoringService`, `V7\MlPrediction`, V7 models/migration, and [Strategy And Recommendations](./strategy-and-recommendations.md).

## 20. Data Integrity Invariants

- Provider payloads are normalized before domain use.
- One security/session OHLCV identity must not duplicate.
- Missing market data is never zero.
- Gap repair never fabricates a bar.
- Trade holidays change expected-session calculation.
- Freshness requires successful-sync timestamp coverage, not simply a latest row.
- Dataset-version rows are immutable attribution records.
- Evaluation/backtest/recommendation evidence must retain data/version context.
- Historical reads must not see future bars.
- Price-series repair never mutates accounting by itself.
- Fundamental/ML provenance remains versioned and time-aware.

## 21. Data Model

`Stock` is the security master; `StockPrice` is canonical daily OHLCV. Benchmark/index records and prices provide market comparison inputs. `SyncLog`/`SyncRun` represent operational runs; `DatasetVersion` is immutable successful-sync attribution. `IgnoredPriceGap` and data-quality issue/evidence/resolution records capture data integrity workflow. `CalendarEvent` supplies global trade-holiday inputs alongside calendar semantics.

`PriceAdjustmentFactor` supports market-series repair. `MarketDepthSnapshot`/market analytics snapshots are derived quote/analysis records. V7 fundamental facts/settings/runs/jobs and ML prediction/model records retain analytical provider/model provenance.

## 22. API Contract

- **Security master/prices:** `/api/stocks*`, `/api/stocks/{stock}/prices`, `/market-prices`, `/api/v1/securities`, `/api/v1/price-bars`.
- **Indices/depth/analysis:** `/api/indexes*`, `/api/market-depth`, `/api/v1/market-analysis/*`.
- **Admin sync/quality:** `/api/universe-price-sync/*`, `/api/sync/*`, `/api/sync-logs/*`, `/api/data-quality/*`.
- **Calendar/holidays:** calendar event and holiday administration routes.
- **Datasets/imports:** `/api/v1/dataset/status`, `/api/v1/imports`.
- **Fundamentals/ML:** stock-facing `/api/v1/stocks/{stock}/fundamentals` and `/ml-predictions`; Admin `/api/v1/admin/fundamentals/*` and `/admin/ml/*`.

Investor market reads remain authenticated/profile-scoped where defined; global stock/sync/quality/fundamental/ML administration requires Admin boundaries. Exact route middleware is owned by `routes/api.php`.

## 23. Services And Orchestration

Provider adapters/fetch services obtain normalized bars; stock-master, daily/universe/index/benchmark sync services orchestrate persistence and logs. `PriceHistoryGapService` scans/fills history and `IgnoredPriceGapService` records exceptions. Data-quality services/guard own issue lifecycle and consumer blocking. `DatasetVersionLedger` records successful-sync attribution; `DatasetFreshnessGate` controls daily decision readiness.

Corporate-action price adjustment/repair services own market-series transformations. Calendar/holiday services own expected-session inputs. Fundamental services normalize/update facts; ML services prepare/evaluate predictions with provenance.

## 24. Scheduling

Scheduled/manual work includes daily market sync, universe price batches, index/benchmark updates, gap scan/fill, data-quality checks/auto-resolution, holiday sync, corporate-action price repair, fundamentals update jobs, and ML administrative/model jobs where configured. Decision-pipeline freshness uses the successful market-sync timestamp, not an assumption that every scheduler cadence completed. The V7 fundamentals updater uses a bounded resumable incremental run: scheduled slices resume unfinished work, respect retry backoff and attempt budgets, compact duplicate unfinished stock/cadence jobs into one newest viable continuation while preserving superseded evidence, and do not create a second active stock/cadence job. Manual targeted runs and completed fact-producing history are excluded from compaction. Yahoo acquisition runs through the managed Python `yfinance` adapter; provider failures remain explicit and do not write zero-valued facts.

Cadence, market windows, and provider quotas are runtime configuration. Do not revive obsolete provider/job timing from historical plans.

Fundamentals are issuer-only: benchmark instruments are excluded when runs are created, and existing benchmark jobs are terminalized as ineligible before provider invocation without raising provider-failure alerts. Ordinary issuer no-data responses retain the normal retry/failure lifecycle.

## 25. Runtime Configuration

Stable configuration categories include provider selection and credential references, market/sync timezone, scheduler/maintenance windows, trade-holiday/exchange inputs, universe enablement/scope, OHLCV history/lookback, internal-gap tolerance, gap-fill batching/retry progress, and data-source fallback where supported.

Values are environment/deployment concerns. Credentials, API keys, broker secrets, and raw provider configuration must not be exposed through UI/docs/logs.

**Current implementation anchors:** `config/portfolio.php`, provider configuration, `DailyMarketSyncService::syncTimezone()`, `PriceHistoryGapService`, and deployment/runtime documentation.

## 26. Error And Recovery Semantics

| Condition | Required behavior |
| --- | --- |
| Provider timeout/rate limit/malformed payload | Record failure/sanitized evidence; do not upsert an invented valid bar. |
| Partial sync | Record partial outcome; do not claim dataset readiness solely from invocation. |
| Missing bar | Detect as gap against expected sessions; repair, ignore with evidence, or remain unresolved. |
| Repair failure | Preserve failed/progress evidence and allow bounded later retry. |
| Stale dataset | Block daily decision pipeline before discovery with freshness reason. |
| Holiday mismatch | Correct calendar input through sync/Admin workflow; reevaluate future expected sessions. |
| Corporate-action repair conflict | Preserve adjustment/quality evidence; do not mutate accounting through price repair. |
| Missing benchmark/fundamental | Return unavailable/degraded analytical input rather than substitute/fabricate data. |

## 27. Test And Verification Anchors

| Area | Test anchor | What it proves |
| --- | --- | --- |
| Provider/OHLCV | `NsePriceProviderTest`, `PriceFetchServiceTest`, `StockPriceHistoryServiceTest` | Provider normalization/fetch behavior and history/upsert rules. |
| Sync | `DailyMarketSyncTest`, `DailyMarketDataJobTest`, `SyncUniversePricesCommandTest`, `UniversePriceSyncApiTest` | Daily/job/command/API sync behavior and observability paths. |
| Gaps/ignored gaps | `PriceHistoryGapServiceTest`, `IgnoredPriceGapServiceTest` | Expected-window detection and explicit ignored-gap handling. |
| Freshness gate | `DatasetPublishGateTest`, `DatasetFreshnessGateTest` | Timestamp freshness blocking/weekday-Monday bounds. |
| Versioning | `DatasetVersioningTest` | Successful-sync immutable attribution/version key behavior. |
| Quality guard | `DataQualityPipelineGatingTest`, `DataQualityEvaluationGatingTest` | Quality blocks downstream pipeline/evaluation input. |
| Holidays/calendar | `TradingCalendarTest`, `NseHolidaySyncServiceTest`, `ScreenerBacktestCalendarTest` | Expected sessions, holiday sync, and calendar-aware historical screening. |
| Index/depth | `IndexPriceSyncServiceTest`, `BenchmarkPriceSyncServiceTest`, `MarketDepthServiceTest` | Index/benchmark/depth service behavior. |
| Corporate-action prices | `CorporateActionPriceRepairServiceTest`, `CorporateActionFactorPriceRepairTest`, `CorporateActionPriceAdjustmentServiceTest` | Price-series adjustment/repair separation and factor behavior. |
| Fundamentals/ML | `V7/FundamentalDataIntegrationTest` | Fundamental ingestion/API/run foundations; ML provenance requires further audit. |

**Test coverage gap — implementation audit follow-up:** full provider fallback behavior, concurrency/idempotency of all price upserts/repair runs, immutable historical replay after later resync, actual scheduled cadence/configuration, and complete ML feature/prediction provenance need direct audit confirmation.

## 28. Debugging Guide

| Symptom | Likely layer |
| --- | --- |
| Latest price stale | Daily/universe sync success timestamp, sync log, freshness gate, provider/configuration. |
| Missing date/gap persists | `TradingCalendar`, required window, ignored-gap record, gap inventory/fill failure, provider history. |
| Duplicate/invalid bar | `StockPrice` identity/upsert, provider normalization, data-quality issue/evidence. |
| False holiday | Exchange sync/admin calendar correction, active trade-holiday state, `TradingCalendar`. |
| Recommendation blocked by quality | Data-quality issue/guard, dataset freshness, evaluation evidence, active stock state. |
| Backtest changed after resync | Historical read/version evidence and point-in-time boundary; audit snapshot/pinning semantics. |
| Index missing | Benchmark/index master, index sync, price history and unavailable fallback. |
| Corporate-action discontinuity | Adjustment factor, corporate-action price repair/evidence, not accounting transaction write. |
| ML score lacks provenance | Fundamental/feature timestamps, model/prediction records, `MlScoringService`. |

## 29. Implementation Alignment Notes

The following accepted contracts require V1-V7 implementation/runtime verification and are not defect conclusions:

- Full production provider/fallback behavior, credentials/configuration, rate-limit handling, and scheduler cadence.
- Exact dataset coverage/readiness semantics across every consumer beyond the daily timestamp gate.
- Race safety/idempotency in concurrent sync, gap-fill, and repair activity.
- Full point-in-time reproducibility after physical market-data resync.
- End-to-end fundamentals and ML feature/model/prediction provenance and UI visibility.

## 30. Historical Context

Early price sync established cached OHLCV. V2/V2.1 added historical repair and gap hardening. V4 added freshness and immutable dataset-version attribution. V5 developed calendar/provider operations. V7 added fundamental and ML analytical inputs.

Current behavior is defined by this document and linked current-domain contracts, not obsolete sync/provider narration.
