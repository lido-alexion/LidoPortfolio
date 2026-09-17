# Discovery, Screeners, And Registries

## 1. Purpose And Scope

This document owns discovery runs, candidate sets, screener definitions/runtime, eligibility inputs, registries, and reusable discovery artifacts. Strategy policy consumes candidates and determines recommendation action; a screener match is never automatically a BUY.

## 2. Discovery Pipeline

`dataset -> active universe -> screener evaluation -> persisted candidate evidence -> evaluation eligibility -> strategy consumption`.

Discovery persists run and candidate evidence so downstream evaluation/recommendation can identify what matched and why. Cached market data is required; discovery is not a live-provider fetch path.

**Current implementation anchors:** `DiscoveryEngine`, `DiscoveryRun`, `Candidate`, `DiscoveryCandidateRepository`, `ScreenerRunService`, and `DailyDecisionPipeline`.

## 3. Universe

The active security universe is filtered by canonical security status, exchange/index scope where configured, dataset freshness, and data-quality gates. Inactive/admin-deactivated securities do not become valid candidates. Historical consumers require as-of universe/session data rather than today's status where available.

## 4. Screener Identity

`Screener` is the portfolio runtime identity; `ScreenerVersion` preserves versioned definition evidence. Reusable screener artifacts provide portable registry identity/version separate from a portfolio runtime instance. System/factory and user-owned definitions remain distinguishable.

## 5. Screener Lifecycle

The operational path is **draft -> validate -> import/create -> enable/use -> version -> archive**. Registry status/binding and legacy screener lifecycle are related but not identical: registry presence does not activate a runtime screener. Archived definitions must retain historical evidence.

## 6. Screener Runtime Grammar

Definitions contain `definition.root`, a `group` or `condition`, with at least one condition. Groups support `AND` and `OR`; nesting is at most four and condition count at most 40. Conditions support only `gt`, `gte`, `lt`, `lte`, and `eq`. Each side is an indicator operand `{ "indicator": "<id>", "params": {} }` or numeric constant `{ "type": "constant", "value": <number> }`.

Strings, booleans, null/date operands, `NOT`, `neq`, crossing, between/outside, and other undocumented operators are unsupported. “Between” is expressed as `AND` of inclusive comparisons; “outside” as `OR`. `eq` is float equality with runtime epsilon; threshold comparisons are preferred.

**Current implementation anchors:** `ScreenerDefinitionValidator`, `ScreenerEvaluationService`, `TechnicalIndicatorService`, `ScreenerCatalog`, and the artifact authoring guide.

## 7. Lookback Semantics

Lookback is the minimum historical OHLCV observation window required to compute the referenced indicator/condition, interpreted over available market sessions/bars rather than invented calendar observations. Screeners calculate required minimum bars from their conditions/parameters. Insufficient history is an explicit skip/failure-to-match condition, not a short-series calculation that pretends completeness.

## 8. Missing Volume

Volume-dependent conditions require adequate volume history. Missing/unavailable volume is **not zero** and must not make a condition pass/fail by fabricated numeric substitution; runtime records/returns insufficient history or unavailable evidence as applicable.

## 9. Operator Semantics

Comparison is numeric indicator/constant comparison after indicator evaluation. `gt`/`gte`/`lt`/`lte` are strict/inclusive as named; `eq` follows documented floating tolerance. Grouping short-circuits according to AND/OR logic but must preserve enough evidence for diagnosis where persisted.

## 10. Normalization

Supported indicator outputs are normalized by their registered/runtime calculation semantics before numeric comparison. Parameters are validated against the Indicator Registry and numerical bounds; unknown indicators/parameters are rejected rather than normalized optimistically. Text/enums/booleans are not general screener operand types.

## 11. Runtime Eligibility

Screener match selects a discovery candidate. Candidate inclusion is not strategy eligibility, and strategy eligibility is not recommendation action. Strategy policy applies strategy version, factor scoring, market gates, owned-position context, sizing, capital, and lifecycle after discovery.

## 12. Discovery Run Lifecycle

Discovery run state records start/completion/failure timestamps, candidate counts, context/error evidence, and dataset/version inputs where supplied. Scheduled/manual retriggering must be idempotent at the run/candidate boundary; failures remain observable rather than represented as empty valid output.

## 13. Candidate Evidence

Candidate evidence identifies security, discovery/screener/version context, matched predicates/indicators, timestamps, and relevant blocked/rejection conditions. It explains why a security entered evaluation; it does not itself constitute a recommendation or order.

## 14. Data Quality Gate

Discovery and screener runtime consume the market-data quality/freshness boundary. Required blocked inputs must fail closed or be reported unavailable; they are never converted to zero/neutral values. See [Market Data And Data Quality](./market-data-and-data-quality.md).

## 15. Point-In-Time Screening

Historical/screener backtests use only bars available as of the historical session, calendar/holiday-aware sessions, and pinned screener versions. Future bars, current mutable definition state, and current live universe assumptions must not reinterpret historical output.

## 16. Screener Backtests

Screener backtests measure historical screener hits/days under a pinned definition and date/session context. They differ from full strategy backtests: they do not model complete strategy policy, virtual portfolio construction, recommendation lifecycle, or broker execution.

## 17. Registries

Registries organize reusable indicators, screeners, strategies, packages, and bindings. Registry metadata describes type, schema, status, ownership, dependencies, versions, and usability; a runtime binding selects what a portfolio instance actually consumes. Indicator Registry is Admin-visible deterministic capability metadata, not arbitrary executable-code authoring.

## 18. Sharing And Ownership

Classic shared screeners are visible only across the same user's portfolios and import as private copies. Artifact library access/sharing follows owner/system/grant scope; no cross-user artifact may silently bind to another user's portfolio. Public cross-user sharing must not be inferred unless an explicit grant/runtime path exists.

## 19. Versioning

Definitions/version records and artifact bindings preserve exact runtime evidence. Historical screener runs, recommendations, backtests, and replays should retain pins rather than resolve against mutable current registry state. Archive removes future usability but not historical explanation.

## 20. API Contract

- Classic screeners/runs/backtests: `/api/screeners/*`, `/api/screener-runs/*`, `/api/screener-backtests/*`.
- Discovery/candidates: `/api/v1/discovery/runs`, `/api/v1/candidates`.
- Registries: `/api/v1/screener-registry/*`, `/api/v1/strategy-registry/*`, `/api/v1/artifacts/*`, `/api/v1/indicators*`.
- Library/binding/sharing: `/api/v1/artifact-library/*`, `/api/v1/artifact-bindings/*`, `/api/v1/artifact-share-grants/*`.

All portfolio runtime access remains user/profile scoped; indicator administration is Admin-gated.

## 21. Services And Orchestration

`ScreenerDefinitionValidator`, `ScreenerEvaluationService`, `TechnicalIndicatorService`, and `ScreenerRunService` own grammar/evaluation/runs. `ScreenerBacktestService` owns historical screen runs. Artifact registry/validation/package/binding/lifecycle/sharing services own reusable definitions and runtime selection. `DiscoveryEngine` creates candidate evidence for later evaluation.

## 22. Scheduling

Due screener and decision-pipeline schedules run against cached/fresh market data and persist run evidence. `RunDueScreenersCommand` and pipeline scheduling must not duplicate candidates/runs merely because a scheduler retries.

## 23. Critical Invariants

- Missing input/volume is never zero.
- Screener pass is not recommendation or BUY authority.
- Runtime consumes exact selected/pinned definition/version.
- Historical screening cannot read future bars.
- Inactive securities cannot become valid candidates.
- Invalid definition, dependency, or binding fails closed.

## 24. Error And Recovery

Malformed definition, unsupported operator/indicator/parameter, insufficient lookback, missing metric/volume, stale dataset, blocked quality issue, failed run, or incompatible artifact version must return validation/unavailable/failed evidence without fallback to an arbitrary definition. Correct the definition/data, validate again, and create/retry through the normal lifecycle; do not mutate historical output.

## 25. Test Anchors

`ScreenerTest`, `TechnicalIndicatorServiceTest`, `LiquidityTradabilityRuntimeTest`, `IndicatorSeriesParityTest`, and `ScreenerBacktestCalendarTest` cover runtime/calculation/volume/parity/calendar behavior. `DataQualityPipelineGatingTest` and `DataQualityEvaluationGatingTest` cover integrity gates. `ScreenerRegistryApiTest`, `ScreenerArtifactRuntimeTest`, `ArtifactRuntimeBindingResolverTest`, `ArtifactBindingServiceTest`, and `ArtifactBindingUsabilityServiceTest` cover registry/runtime/binding boundaries.

**Test coverage gap — implementation audit follow-up:** full parser diagnostics, all lookback/missing-volume UI states, scheduled discovery idempotency, historical pinning across every run type, and cross-portfolio sharing reachability.

## 26. Debugging Guide

| Symptom | Inspect |
| --- | --- |
| Unexpected hit/miss | Definition root, registered indicator/params, bars/lookback, volume, `ScreenerEvaluationService`. |
| No candidates | Active universe, freshness/quality gate, screener run status, persisted candidate evidence. |
| Historical mismatch | Pinned screener/version, as-of bars/calendar, backtest range. |
| Artifact works in library but not runtime | Binding revision/usability/dependencies/runtime resolver. |
| Shared definition unavailable | Owner/grant/profile boundary or classic private-copy import. |

## 27. Implementation Alignment Notes

Verify under V1-V7 audit: routed UI reachability, every parser/runtime error state, concurrency/idempotency for scheduled runs, complete point-in-time universe behavior, sharing/grant semantics, and archive behavior for all historical consumers.

## 28. Historical Context

V2/V3 established cached screeners, shared same-user screeners, and historical runs. V5 added reusable artifacts, packages, bindings, and registry lifecycle. Current contracts define the common runtime and provenance boundary.
