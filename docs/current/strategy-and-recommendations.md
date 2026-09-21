# Strategy And Recommendations

## 1. Purpose And Scope

This document is the current product and technical contract for strategy policy, deterministic evaluation, recommendation generation and lifecycle, capital-status interpretation, exit intent, and supersession. It owns the path from a strategy's policy to a reviewable recommendation; it does not make broker orders or become the financial ledger.

Boundaries:

- Discovery and screeners produce candidate evidence; their runtime grammar belongs in [Discovery, Screeners, And Registries](./discovery-screeners-registries.md).
- Portfolio ownership, cash, lending, and accounting truth belong in [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md).
- Approval-to-submission, broker orders, and execution safety belong in [Execution, Broker, And Safety](./execution-broker-safety.md).
- Backtests and analytical outcome corpus details belong in [Analytics, Review, And Backtesting](./analytics-review-backtesting.md).
- ML and fundamentals may enrich this pipeline but do not replace its deterministic authority.

**Current implementation anchors:** `app/app/Engines/Recommendation/RecommendationGenerationPipeline.php`, `app/app/Engines/Evaluation/EvaluationEngine.php`, `app/app/Engines/Recommendation/RecommendationLifecycleService.php`, and `app/app/Models/TradingRecommendation.php`.

## 2. Strategy Identity And Multi-Strategy Model

One portfolio may operate multiple strategies concurrently. A strategy is a named, versioned investment hypothesis and policy, not the portfolio's identity. It has its own configuration, enabled state, allocation context, recommendation evidence, and holdings ownership boundary.

- More than one strategy may be enabled for the same portfolio.
- The same security may be held or recommended in separate strategy contexts; identity and evidence must retain the strategy/version that produced each result.
- A strategy must only act on positions it owns. Unmanaged holdings are a separate accounting state and require adoption before strategy-directed management.
- The V1 rule that exactly one strategy must be active per portfolio is superseded by the V3 concurrent-strategy contract.

**Current implementation anchors:** `TradingStrategy`, `TradingStrategyVersion`, `Holding.strategy_id`, `StrategyArtifactRegistry`, and `RecommendationGenerationPipeline::run()`.

## 3. Strategy Lifecycle

The product journey is **Create -> Draft/Edit -> Enable -> Run concurrently -> Archive**.

| State or action | Required contract |
| --- | --- |
| Create | A user may create a distinct draft from the default strategy or create/import a validated strategy artifact. Creation must not replace another strategy. |
| Draft/Edit | Editing produces versioned policy evidence. Saving configuration changes policy only; it does not generate recommendations. |
| Enable | `active` means enabled for eligible pipeline runs. Enablement is non-exclusive. |
| Run | The pipeline evaluates each enabled strategy independently, preserving strategy-scoped stale cancellation and output provenance. |
| Archive | Archive removes a strategy from enabled runtime without silently deleting its historical evidence. The last enabled strategy is protected from archive. |

The strategy registry is the management surface; the editor selection is a UI selection, not a global exclusive-active switch. Current stored statuses are `draft`, `active`, and `archived`.

**Current implementation anchors:** `app/app/Models/TradingStrategy.php`, `app/app/Services/Artifacts/StrategyArtifactRegistry.php`, `app/app/Http/Controllers/Api/V1/StrategyRegistryController.php`, and `app/resources/js/src/pages/StrategyRegistryPage.jsx`.

## 4. Strategy Configuration Model

A strategy configuration may define screeners and eligibility, factors and indicators, weights, thresholds and labels, exit policy, portfolio construction, position sizing, capital allocation, recommendation rules, and market-gate behavior. Configuration must be normalized and validated against supported runtime inputs; disabled factors do not contribute.

Configuration changes take effect only when a later decision pipeline or explicit evaluation/recommendation generation consumes the selected strategy version. Saving does not silently regenerate, supersede, cancel, or execute recommendations.

**Current implementation anchors:** `StrategyConfigurationService`, `StrategyParameterSchema`, `SupportedIndicators`, `StrategyArtifactRegistry`, and the `/api/v1/strategy/*` routes.

## 5. Deterministic Evaluation Pipeline

The conceptual path is:

`Market dataset -> discovery/candidate evidence -> eligibility -> evaluation factors -> deterministic score -> label/fit -> position-aware policy -> recommendation intent`.

Evaluation records measurable factor facts for a discovery run. Strategy configuration applies the strategy-specific weighting and policy; recommendation generation then considers ownership, position sizing, market gates, ranking, capital, and lifecycle rules. Evaluation results, strategy versions, generated timestamps, evidence, failed checks, and artifact bindings provide provenance. Holdings and cash are inputs to the decision, not outputs that this layer may fabricate.

**Current implementation anchors:** `EvaluationEngine`, `EvaluationResult`, `EvaluationRun`, `RecommendationGenerationPipeline`, and `DailyDecisionPipeline`.

## 6. Eligibility

Eligibility uses configured screeners, candidate evidence, supported/active securities, cached market data, data-quality gates, and the current strategy/position context. A valid false eligibility result is distinct from unavailable inputs or an evaluation failure:

- **False/not selected:** inputs were sufficient but the screener or policy did not qualify the security.
- **Unavailable:** a required completed evaluation cycle, dataset, or other prerequisite is absent; diagnostics must say why.
- **Failed:** evaluation or pipeline processing failed and must not be represented as a valid recommendation result.

Market and data-quality guards must fail closed for actions that require valid inputs. See discovery documentation for screener grammar and cached-data behavior.

**Current implementation anchors:** `StrategyEligibilityService`, `ScreenerEvaluationService`, `DatasetFreshnessGate`, `DataQualityGuardService`, and `RecommendationGenerationPipeline::decideForSecurity()`.

## 7. Scoring

Strategy scoring is deterministic for a given strategy version and input evidence:

- Factor/indicator weights are normalized and validated; disabled factors have no contribution.
- Only the supported factor set and compatible artifact/indicator versions may participate.
- Missing or unavailable factor evidence must be surfaced through evidence, failed checks, or an unavailable result; it must not be treated as a fabricated neutral or passing value.
- The evaluation engine's informational factor score is not automatically the strategy's weighted score.
- An equal-weight mean may be used for evaluation-result information, but it is not a substitute for configured strategy weighting.

**Fit score**, evaluation score, historical success rate, return magnitude, and final capital-fill ranking are distinct concepts. A high fit score does not by itself establish that a candidate has the best return-quality priority.

**Current implementation anchors:** `StrategyConfigurationService::score()`, `EvaluationFactorRuleSet`, `EvaluationFactorRule`, `EvaluationEngine`, and `ReturnQualityRankingService`.

## 8. Thresholds And Labels

Thresholds map deterministic strategy scores to configured labels and policy outcomes. They must be configuration-driven, ordered, and validated rather than embedded as untracked UI conventions. Labels explain evaluation fit or recommendation intent; they are not themselves execution authority.

An actionable portfolio action is one of `OPEN_POSITION`, `INCREASE_POSITION`, `REDUCE_POSITION`, or `EXIT_POSITION`. `HOLD_POSITION` and `WATCH` are informational outcomes and are not executable or reviewable as trades.

**Current implementation anchors:** `TradingRecommendation::ACTIONABLE_ACTIONS`, `TradingRecommendation::INFORMATIONAL_ACTIONS`, `StrategyConfigurationService`, and `RecommendationGenerationPipeline`.

## 9. Market Regime And Market Gates

Strategy consumes market analysis rather than independently recalculating a separate regime. The stable categorical mapping is bullish, neutral, and bearish; market phases are not independently scored by the strategy mapper.

Market gates apply entry policy: an otherwise qualifying OPEN may become WATCH and an INCREASE may become HOLD when entry gates block. REDUCE and EXIT remain eligible exit actions when entry gates block. A market gate must communicate whether it blocked an action or adjusted a permitted sizing/allocation; it must not disguise a gate as an unexplained score change. Missing market analysis remains an input-availability condition rather than a fabricated regime.

**Current implementation anchors:** `MarketAnalyticsService`, `MarketGateEvaluator`, `MarketRegimeScoreMapper`, and `EvaluationEngine`.

## 10. Position-Aware Recommendation Policy

Recommendation policy combines evaluation with the strategy-owned position:

- **OPEN_POSITION:** initiate a strategy-owned position when none exists.
- **INCREASE_POSITION:** add to that strategy's existing position subject to sizing, cooldown, and limits.
- **HOLD_POSITION:** retain an existing position; informational only.
- **WATCH:** publish research/monitoring intent; informational only.
- **REDUCE_POSITION:** sell part of the owning strategy's position.
- **EXIT_POSITION:** close the owning strategy's position.

The same security must not be reduced or exited on behalf of a different strategy solely because another strategy owns a position in it.

**Current implementation anchors:** `RecommendationGenerationPipeline`, `StrategyPositionTargetService`, `Holding`, and `ExitStrategyEvaluator`.

## 11. Exit Policy And Precedence

Exit policy can include strategy exits, portfolio stop-loss, trailing stop, horizon, and other configured causes. When more than one cause applies, precedence must produce a single authoritative primary reason that is persisted with the recommendation and carried into realized transaction evidence where applicable.

An entry market gate does not suppress a valid REDUCE or EXIT. Exit attribution must remain explainable; a generic sell outcome is not sufficient when a policy reason is available.

**Current implementation anchors:** `ExitPrecedenceEvaluator`, `ExitAttribution`, `ExitStrategyEvaluator`, and `RecommendationGenerationPipeline`.

## 12. Position Sizing

Desired position construction is distinct from actual funding. It considers target allocation, score/policy influence, current owned position, whole-share constraints, minimum actionable amount, cooldown and staggered-entry rules, strategy/portfolio maximum position limits, and maximum holdings.

The pipeline must preserve the desired target even where the presently fundable amount is smaller or zero. Whole-share rounding and the reference price constrain an actionable quantity; they must not silently convert an investment opinion into WATCH.

**Current implementation anchors:** `StrategyPositionTargetService`, `StaggeredEntryCalculator`, `BuyCooldownEvaluator`, `MinimumActionableAmountResolver`, `WholeShareQuantityCalculator`, and `ReturnQualityCapitalAllocator`.

## 13. Capital Status

Capital state expresses readiness, not investment opinion. A recommendation may be `funded`, `partially_funded`, `unfunded`, `awaiting_lender_selection`, or `capital_committed` as its current resolution requires.

**Lack of funding does not change an underlying OPEN/INCREASE investment opinion into WATCH or HOLD.** The target, funded amount, remaining gap, and capital/lending path remain explicit. Approval to pending execution is separately gated until the actual capital/lending condition permits it. See [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md) for the cash, lending, recall, and bridge-loan mechanics.

**Current implementation anchors:** `TradingRecommendation` allocation constants, `PortfolioCapitalAccountingService`, `CapitalResolutionService`, `RecommendationLendingCoordinator`, and `RecommendationLifecycleService`.

## 14. Recommendation Generation

Generation runs per enabled strategy against a completed evaluation and market-data context. It builds strategy-scoped drafts, ranks them, applies capacity and capital outcomes, persists recommendation records, and records evidence/provenance including strategy version and evaluation result.

Saving a strategy is not a generation trigger. A scheduled or explicit pipeline trigger is required. Generation must avoid duplicate live intent through strategy-scoped stale handling and supersession rules, while preserving history rather than erasing prior records.

**Current implementation anchors:** `RecommendationGenerationPipeline::run()`, `DailyDecisionPipeline`, `PipelineController`, `RunDecisionPipelineCommand`, and `DecisionPipelineScheduleService`.

## 15. Recommendation Lifecycle

| Stage | Current status/decision | Required behavior |
| --- | --- | --- |
| New actionable recommendation | `pending_review` | Await a recorded review decision. |
| New informational recommendation | `published` | Visible without trade approval; HOLD/WATCH stay non-executable. |
| Approve | `approved` decision -> `pending_execution` | Record review, meet capital readiness, and reserve cash when applicable. Approval is not broker submission. |
| Reject or defer | `rejected` or `deferred` | Preserve review history; defer remains an open lifecycle condition. |
| Reopen | `reopened` decision | Reopen eligible rejected/deferred work through the lifecycle service. |
| Cancel execution | `cancelled` | Stop an approved pending-execution intent and release the relevant reservation. |
| Execution completion | `executed` | Link actual accounting transaction evidence and convert reservation at actual amount. |
| Expiry | `expired` | End stale eligible intent according to execution lifetime/window policy. |
| Replacement | `superseded` | Preserve old-to-new relationship and release/reconcile old readiness state. |
| Historical retention | `archived` | Retain historical record rather than delete decision evidence. |

`accepted` is a backward-compatible status alias and is not the preferred current state name. Execution-window dates and broker-side partial fulfillment are owned by the execution contract.

**Current implementation anchors:** `TradingRecommendation`, `RecommendationLifecycleService`, `RecommendationExecutionLifetime`, `RecommendationSupersessionService`, and `RecommendationReview`.

## 16. Review Semantics

Review supports Approve, Reject, Defer, and Reopen with user, time, decision, and optional notes retained as review history. Only actionable, open portfolio decisions may be reviewed. Informational HOLD/WATCH recommendations do not require and must not accept trade review.

Approve moves a capital-ready actionable recommendation to `pending_execution`; for a buy it reserves the suggested investable amount when reservation applies. Reject, cancellation, expiry, supersession, and reopening must handle reservation release according to lifecycle rules. Approval authorizes the next execution workflow; it does not submit a broker order.

**Current implementation anchors:** `RecommendationLifecycleService::recordReview()`, `RecommendationLifecycleService::reserveForApproval()`, `RecommendationReview`, and `TradingRecommendation`.

## 17. Supersession

A materially changed target for the same strategy and security supersedes the prior live intent, preserving an old-to-new link, timestamp, evidence, and reservation consequences. An unchanged target must not reset the recommendation lifetime merely because the pipeline ran again. Cross-strategy records must not supersede each other simply because they involve the same security.

If execution is in flight or a broker order exists, its treatment is governed by the execution safety contract; recommendation supersession must not erase broker or accounting evidence.

**Current implementation anchors:** `RecommendationSupersessionService`, `TradingRecommendation.superseded_by_id`, `RecommendationGenerationPipeline`, and `RecommendationExecutionLifetime`.

## 18. Recommendation Preview

Recommendation preview is a non-mutating diagnostic surface for a selected stock and strategy. It combines relevant persisted current recommendation state with newly derived deterministic evaluation, eligibility, market-gate, and explanation payloads. It must identify unavailable prerequisites and reasons rather than pretending a recommendation is valid.

Preview does not persist, cancel, supersede, reserve, or execute recommendations. It is not a backtest and does not replace the generated lifecycle record.

**Current implementation anchors:** `RecommendationPreviewService`, `RecommendationGenerationPipeline::decideForSecurity()`, `F137RecommendationPreviewTest`, and `GET /api/v1/analytics/stocks/{stock}/recommendation-preview?strategy_id=`.

## 19. Ranking And Return Quality

The product distinguishes:

- **Fit/evaluation score:** deterministic compatibility with configured factors and policy.
- **Historical success rate:** frequency of outcomes meeting a success definition.
- **Return magnitude / return quality:** expected or observed magnitude-aware outcome evidence.
- **Final ranking/fill order:** priority for capital attention and allocation.

Where the authoritative outcome corpus is eligible, final ranking uses return quality rather than raw fit score or hit rate. Where ranking is unavailable, the defined deterministic fallback order applies. Capital allocation follows ranking/fill order and must preserve partial/unfunded outcomes rather than proportionally disguising them as fully funded.

**Current implementation anchors:** `ReturnQualityRankingService`, `ReturnQualityCapitalAllocator`, `CapitalFillOrderService`, and `RecommendationGenerationPipeline::rankDrafts()`.

## 20. Historical Outcome Corpus

Historical ranking evidence must respect point-in-time boundaries. Only a suitable backtest/evaluation corpus and historical OHLCV range may influence a current ranking; future state must not leak into historical outcomes. Annualized outcome evidence is used only when the configured holding-period rules are satisfied.

The corpus supports ranking evidence, not a silent rewrite of deterministic strategy policy. Detailed backtest dataset and simulation rules remain in analytics documentation.

**Current implementation anchors:** `ReturnQualityRankingService`, strategy-backtest models/services, and `ReplayStrategyEvaluator`.

## 21. Ownership And Strategy Isolation

Strategy identity is part of each recommendation's provenance. A strategy can OPEN or INCREASE its own position and can REDUCE or EXIT only its owned holding episode. Multiple strategies may own the same security without blending basis, sizing, exit authority, or recommendation records. Unmanaged holdings remain outside a strategy until explicit adoption.

**Current implementation anchors:** `Holding.strategy_id`, `HoldingAdoption`, `TradingStrategyVersion`, `TradingRecommendation.strategy_version_id`, and `RecommendationGenerationPipeline`.

## 22. Strategy Artifacts And Registry Boundary

`TradingStrategy` is the portfolio-scoped runtime identity. Strategy artifacts/registry records provide reusable, validated, portable definitions and versioned binding evidence. An enabled strategy may use a pinned artifact/runtime binding; a draft/import/export operation must not silently alter another strategy's runtime lifecycle.

The full artifact envelope and registry format belong in [StoX Trading Artifacts And AI Guide](./stox-trading-artifacts-ai-guide.md). This document owns the requirement that runtime recommendation evidence identifies the configuration/version actually consumed.

**Current implementation anchors:** `StrategyArtifactRegistry`, `ArtifactRuntimeBindingResolver`, `TradingStrategy.active_version_id`, `TradingStrategy.reusable_artifact_id`, and `TradingStrategyVersion`.

## 23. ML And Fundamentals Boundary

Fundamentals and ML may enrich analysis, including configured score contribution or an explicit strategy gate where supported. They remain additive and explainable: deterministic screener, strategy, evaluation, eligibility, and recommendation semantics remain authoritative.

ML must not silently override deterministic eligibility, gates, score, or recommendation action; it cannot become sole recommendation authority. Predictions used by a decision require versioned/persisted provenance rather than later recomputation with an arbitrary active model. Fundamental/ML data availability rules do not make stale or missing deterministic inputs valid.

**Current implementation anchors:** `MlScoringService`, `EvaluationEngine`, indicator/strategy configuration, and `docs/archive/specs/V7-ML-Scoring-Models-Specification.md`.

The V7 ML lifecycle uses a managed Python adapter for interpretable logistic training and inference. Laravel remains authoritative for point-in-time dataset construction, historical eligible-universe selection, fundamentals availability, labels, model/version lifecycle, promotion/rollback, prediction provenance and deterministic Strategy/Evaluation integration. Historical ML baseline scoring routes as-of factors through `EvaluationParameterResolver` and the pinned factory Strategy configuration before comparison; the canonical Minervini factory eligibility definition is also pinned, and the entry decision uses the explicit `open_position` threshold rather than a classifier half-probability. Raw factor averages are not treated as Strategy scores. Historical feature prices use unadjusted closes; adjusted prices are reserved for realised outcome labels because corporate-action repair may rewrite stored adjusted history. Model artifacts are immutable, SHA-256 verified and retained outside release directories; ML live-health checks include model age and matured non-shadow outcomes, remain advisory, and never auto-promote, deactivate or place orders. Production deployment/runtime verification of the artifact lifecycle remains a separate audit boundary.

## 24. Data Model And Relationships

| Model | Relationship and responsibility |
| --- | --- |
| `TradingStrategy` | Portfolio-scoped strategy identity, allocation and lifecycle state. |
| `TradingStrategyVersion` | Versioned strategy policy and the provenance anchor consumed at runtime. |
| `StrategyScreener` | Links a strategy configuration to screener eligibility input. |
| `EvaluationRun` / `EvaluationResult` | Discovery-cycle factor evidence and results; inputs to strategy recommendation policy. |
| `TradingRecommendation` | Persisted strategy/security recommendation, status, target/funding, evidence, review, execution, and supersession state. |
| `RecommendationReview` | Immutable review-decision history associated with a recommendation and actor. |
| `ExecutionDecision` | Execution-domain decision evidence; it is not a substitute for recommendation state. |
| `CapitalRequest` / `CapitalLoan` | Lending/capital-resolution path for a recommendation's remaining gap. |

Portfolio/profile ownership scopes all strategy and recommendation reads/mutations. A recommendation carries a strategy version and security identity; execution and accounting must retain traceability back to it.

## 25. API Contract

The API is active-portfolio scoped and must enforce the current user's ownership boundary.

- **Strategy configuration:** `/api/v1/strategy`, `/summary`, `/catalogue`, `/screeners`, `/eligibility`, `/scoring`, `/exit`, `/factors`, `/thresholds`, `/portfolio-rules`, `/capital-allocation`, `/recommendation-rules`.
- **Registry/lifecycle:** `/api/v1/strategy-registry/*` for list/detail/version/create/import/export/validate/activate/archive/selection operations.
- **Recommendations:** `/api/v1/recommendations/generate`, `/api/v1/recommendations`, `/pending-execution`, `/{id}`, `/{id}/review`, `/{id}/reopen`, `/{id}/cancel-execution`, `/{id}/expire`, and `/{id}/reviews`.
- **Preview/pipeline:** `/api/v1/analytics/stocks/{stock}/recommendation-preview` and `/api/v1/pipeline/run`.
- **Capital-resolution handoff:** `/api/v1/capital/*` and `/api/v1/recommendations/{recommendation}/capital-resolution`.

Route names and detailed payload validation are implementation-owned; callers must not assume a strategy selection implies exclusive activation or that a review call submits a broker order.

## 26. Services And Orchestration

`StrategyConfigurationService` owns normalization and strategy score policy. `StrategyEligibilityService` owns strategy-context eligibility. `EvaluationEngine` records factor evidence. `RecommendationGenerationPipeline` orchestrates enabled strategy iteration, drafts, ranking, capacity, capital outcomes, persistence, and evidence. `StrategyPositionTargetService`, `StaggeredEntryCalculator`, `MinimumActionableAmountResolver`, `WholeShareQuantityCalculator`, and `BuyCooldownEvaluator` own sizing constraints.

`ExitPrecedenceEvaluator` and `ExitAttribution` own exit priority/reason. `RecommendationLifecycleService` owns review, reservation, cancellation, reopening, and execution-completion state. `RecommendationSupersessionService` and `RecommendationExecutionLifetime` own replacement and lifetime semantics. `CapitalResolutionService`, `RecommendationLendingCoordinator`, `LenderRankingService`, and `CapitalFillOrderService` coordinate capital/lending with the accounting domain.

## 27. Scheduling And Pipeline

The decision pipeline may run explicitly or on its scheduled path. It requires a usable dataset/evaluation context, iterates enabled strategies, persists scoped recommendation output, hands notifications to the notification layer, and coordinates with recommendation execution lifetime.

Scheduled runs require idempotency and lock/once-per-day safeguards. A partial profile failure must be observable and retryable without duplicating successful profile work or notifications. Scheduling does not authorize broker execution by itself.

**Current implementation anchors:** `DailyDecisionPipeline`, `DecisionPipelineScheduleService`, `RunDecisionPipelineCommand`, `DecisionPipelineHardeningTest`, and `DecisionPipelineScheduleTest`.

## 28. Critical Strategy And Recommendation Invariants

- Deterministic strategy policy remains authoritative.
- Strategy/version identity must remain in recommendation evidence.
- A strategy must not act on another strategy's holding.
- Lack of funds must not change an investment opinion into WATCH/HOLD.
- Saving configuration must not silently generate recommendations.
- HOLD/WATCH are informational and not executable.
- Approval is not broker execution.
- Preview must not mutate recommendation lifecycle state.
- Supersession preserves history and must be strategy-scoped.
- Market/data gates must not fabricate valid inputs or silently allow blocked entries.
- ML/fundamentals must not silently override deterministic recommendation authority.

## 29. Error And Recovery Semantics

| Condition | Required behavior |
| --- | --- |
| No candidates | Produce a valid empty/no-recommendation result, not a synthetic failure. |
| Stale/missing dataset or evaluation cycle | Block dependent generation/preview and report availability reasons. |
| Invalid screener, weights, factor, or artifact binding | Reject/disable the invalid runtime path with a diagnosable validation reason. |
| Missing market analysis | Do not invent a regime; apply the configured unavailable/gate behavior. |
| Insufficient capital | Preserve actionable opinion with explicit funding/capital state and a resolution path where eligible. |
| Archived/disabled strategy | Do not generate new work from it; preserve historical records. |
| Superseded/stale intent | Preserve linkage/history and apply lifetime/reservation policy rather than duplicate live work. |
| Preview unavailable | Return an explanation payload; do not mutate records to make preview appear current. |

Operational failures must be distinguishable from a valid empty result. Execution expiry, broker failures, and partial fills are further governed by the execution safety contract.

## 30. Test And Verification Anchors

The following automated tests provide meaningful evidence for the named behavior; they do not prove every acceptance criterion in this document.

| Area | Test anchor | What it proves |
| --- | --- | --- |
| Multi-strategy | `app/tests/Feature/V3RecommendationGenerationTest.php` | Independently generates enabled strategies, preserves strategy-scoped stale work and holding isolation. |
| Registry lifecycle | `app/tests/Feature/StrategyRegistryApiTest.php` | Default drafts, independent created strategies, validation/import/activation, and last-enabled archive protection. |
| Configuration/scoring | `app/tests/Unit/StrategyConfigurationServiceTest.php` | Weight normalization, supported configuration handling, and score/gate behavior. |
| Eligibility/data gates | `app/tests/Feature/DataQualityPipelineGatingTest.php` and `MarketGateRecommendationTest.php` | Data-quality gate and entry-block/exit-allow semantics. |
| Evaluation/market mapping | `app/tests/Unit/Evaluation/MarketRegimeScoreMapperTest.php` and `EvaluationParameterOverrideTest.php` | Categorical regime mapping and parameter behavior. |
| Recommendation preview | `app/tests/Feature/F137RecommendationPreviewTest.php` | Strategy/profile boundary, canonical derived action, unavailable result, and preview non-mutation. |
| Capital status | `app/tests/Unit/Recommendation/ReturnQualityCapitalAllocatorTest.php` and `app/tests/Feature/Lending/RecommendationLendingLifecycleTest.php` | Partial/unfunded target preservation, no WATCH demotion, lender readiness and idempotent requests. |
| Ranking | `app/tests/Unit/Recommendation/ReturnQualityCapitalAllocatorTest.php` and `V3RecommendationGenerationTest.php` | Return-quality ordering plus deterministic fallback/fill order. |
| Exit precedence | `app/tests/Feature/Risk/ExitPrecedencePipelineTest.php` | Primary exit attribution is persisted and carried to sell transaction evidence. |
| Supersession | `app/tests/Unit/Execution/RecommendationSupersessionServiceTest.php` | Material target change replaces same strategy/security live intent while unchanged target does not. |
| Pipeline scheduling | `app/tests/Feature/DecisionPipelineHardeningTest.php` | Profile-level retry, locks, no duplicate run/notification side effects, and scheduling safety. |

**Test coverage gap — implementation audit follow-up:** complete end-to-end UI create/edit/enable/archive discoverability, all lifecycle transitions through routed React surfaces, ML non-authoritative behavior, and full historical-corpus point-in-time acceptance coverage need direct audit confirmation.

## 31. Debugging Guide

| Symptom | Likely layer to inspect |
| --- | --- |
| Strategy does not generate recommendations | Dataset/evaluation run, enabled strategy/version, `DailyDecisionPipeline`, `RecommendationGenerationPipeline`, and pipeline locks. |
| Duplicate-looking rows | Strategy/version identity, strategy-scoped stale cancellation, and `RecommendationSupersessionService`. |
| Same stock appears under two strategies | Holding ownership/adoption and strategy version evidence; this can be valid when distinct owned episodes exist. |
| Wrong score or label | Strategy config normalization, supported factors, evaluation evidence, thresholds, and `StrategyConfigurationService`. |
| Eligible security missing | Screener/candidate evidence, data-quality and market gates, position/cap constraints, and no-candidate versus unavailable classification. |
| HOLD appears executable | `TradingRecommendation` action/status handling, lifecycle review validation, and the recommendations UI. |
| Recommendation changed unexpectedly | Generation timestamp/version, material target comparison, supersession linkage, and execution lifetime. |
| Capital state is wrong | Capital snapshot, target/remaining amount, lending coordinator, reservation, and accounting docs/services. |
| Exit reason is wrong | `ExitPrecedenceEvaluator`, `ExitAttribution`, recommendation evidence, and transaction realization. |
| Preview disagrees with list | Persisted-current versus newly derived preview state, selected strategy ID, evaluation-cycle availability, and `F137RecommendationPreviewTest`. |
| Strategy cannot enable/archive | Registry validation, active profile ownership, enabled-count rule, and `StrategyRegistryApiTest`. |

## 32. Implementation Alignment Notes

The following accepted contracts remain subject to the V1-V7 implementation audit and runtime verification; this list does not weaken them:

- The full investor UI journey for multi-strategy create, edit, enable, selection, archive, and concurrent-run visibility.
- Complete reachability of all recommendation review/reopen/cancel/expire actions and their loading, empty, error, accessibility, and responsive states.
- Exact runtime handling of unavailable market analysis and every configured factor/threshold/artifact combination.
- End-to-end adherence to strategy ownership where legacy/unmanaged positions, broker fills, and partial execution coexist.
- Full point-in-time outcome-corpus rules and ML/fundamental provenance in operational runtime.

## 33. Historical Context

V1 introduced the original single-strategy and recommendation baseline. V3 superseded exclusive activation with concurrent strategy identity, ownership isolation, return-quality ranking, and explicit capital states. V4 refined parameters, evaluation, data/market gates, and exit behavior. V5 introduced artifact/registry integration. V7 added fundamental and ML enrichment under a non-authoritative deterministic boundary.

Current behavior is defined by this document and its linked current-domain contracts, not by release chronology.
