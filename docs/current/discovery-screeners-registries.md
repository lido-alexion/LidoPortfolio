# Discovery, Screeners, And Registries

## Current Behaviour

Screeners filter stocks using cached OHLCV and indicator expressions. They support CRUD, metadata, live runs, resumable continuation, run history, run comparison, scheduled runs, backtests, shared screeners from the same user’s other portfolios, and import/export through registry-style flows.

Screener definitions are expression trees. They can reference technical indicators, price/volume series, lookbacks, boolean groups, and comparison conditions. The evaluator tracks required lookback and missing data. Volume-dependent expressions must have adequate volume history.

Discovery uses screeners and market data to produce candidates for evaluation and later recommendation generation. In the current sidebar, discovery/candidates are not a primary workflow, but the API and pages exist for pipeline inspection.

Registries make indicators, screeners, strategies, and reusable trading artifacts portable, versioned, bindable, shareable, importable/exportable, and auditable. Legacy screeners/strategies remain supported while registry infrastructure adds lifecycle, draft/publish, fork, bind, bundle deploy, adoption, and structural diff capabilities.

The Indicator Registry is admin-visible. It describes deterministic indicator capabilities and status; it does not imply arbitrary user-defined executable code.

## Technical Contract

Key API routes:

- Classic screeners: `/api/screeners/*`, `/api/screener-runs/*`, `/api/screener-backtests/*`
- V1 discovery: `/api/v1/discovery/runs`, `/api/v1/candidates`
- Artifact library: `/api/v1/artifact-library/*`, `/api/v1/artifact-bindings/*`, `/api/v1/artifact-share-grants/*`
- Generic artifacts: `/api/v1/artifacts`, `/api/v1/artifacts/{type}`, where type is indicator, screener, or strategy
- Screener registry: `/api/v1/screener-registry/*`
- Strategy registry: `/api/v1/strategy-registry/*`
- Admin indicators: `/api/v1/indicators`, `/api/v1/indicators/meta`, `/api/v1/indicators/{id}`

Primary models include `Screener`, `ScreenerVersion`, `ScreenerRun`, `ScreenerRunHit`, `ScreenerBacktest`, `ScreenerBacktestDay`, `ScreenerBacktestHit`, `Candidate`, `DiscoveryRun`, `ReusableArtifact`, `ReusableArtifactVersion`, `ArtifactBinding`, `ArtifactBindingRevision`, `ArtifactShareGrant`, `ArtifactBundleDeployment`, `TradingArtifactDraft`, and strategy registry models.

Primary services include `ScreenerService`, `ScreenerEvaluationService`, `ScreenerRunService`, `ScreenerBacktestService`, `ScreenerVersioningService`, `TechnicalIndicatorService`, `IndicatorRegistryFactory`, `IndicatorRegistryValidator`, `ScreenerCatalogueProjector`, `StrategyCatalogueProjector`, `ArtifactRegistry`, `ReusableArtifactLifecycleService`, `ArtifactValidationService`, `ArtifactPackageService`, `ArtifactBindingService`, `ArtifactRuntimeBindingResolver`, `ArtifactLibraryAccessService`, `ArtifactSharingService`, and `ArtifactBundleDeploymentService`.

## Data Rules

- Registry import creates portfolio/user-owned copies or bindings according to artifact type and access rules.
- Shared classic screeners are visible only across the same user’s portfolios and import as private copies.
- Registry artifacts must validate structural envelopes before import/publish/bind.
- Runtime selection should resolve the active/bound version and preserve pinned evidence for backtests and screener runs.
- Screeners consume cached market data; stale or missing data should be visible as a quality/precondition problem.

## Debugging Sources

- Screener hit mismatch: inspect definition JSON, indicator lookback, stock activation, OHLCV availability, volume history, and `ScreenerEvaluationService`.
- Registry import/export mismatch: inspect artifact envelope, owner/access grant, version status, and `ArtifactPackageService`.
- Backtest mismatch: inspect pinned screener versions, benchmark evidence, run assumptions, and dataset status.

## Related Docs

- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Analytics, Review, And Backtesting](./analytics-review-backtesting.md)
- [Knowledge And Documentation](./knowledge-and-documentation.md)

## Historical Context

Earlier docs split indicators, screeners, strategy registry, trading artifacts, JSON authoring, and shared import across many specs. Current behaviour is one artifact-and-screening system with legacy compatibility.

