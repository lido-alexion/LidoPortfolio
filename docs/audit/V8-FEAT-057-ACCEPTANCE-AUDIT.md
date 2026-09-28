# FEAT-057 ML Feature Engineering, Training & Validation — reconciliation audit

Status: **IN PROGRESS**

This is a code-reconciliation checkpoint against `docs/archive/specs/V8-ML-Feature-Engineering-Training-Validation-Specification.md`. Existing inherited ML code is useful V7/V8 foundation, but it is not treated as complete merely because training and admin endpoints exist.

| Requirement | Status | Evidence / finding |
|---|---|---|
| Versioned machine-readable feature registry | VERIFIED locally | `MlFeatureRegistryService` now emits stable IDs, formula version, source, transformation, lookback, horizons, sector applicability, tier, missing policy, PIT classification, deprecation field and definition hash |
| Immutable feature-set versioning | PASS locally | `MlFeatureRegistryService::resolveFeatureProfile` emits deterministic feature-set ID/version, ordered keys, per-feature versions, preprocessing identity, exclusions and definition hash; invalid/duplicate/ineligible requests fail loudly |
| Balanced V8 candidate catalogue | PARTIAL | 49 numeric features plus sector exist; growth/profitability/risk/volume/pattern/market/sector families are present, but frozen catalogue-to-registry completeness needs a formal key matrix |
| Horizon-specific applicability | PASS locally for current catalogue | Every registered feature declares eligible horizons; resolver filters/validates against the canonical ordered implemented set and rejects ineligible requests. Current catalogue intentionally permits the present implemented families across all three horizons pending evidence-based pruning |
| 1m / 3m / 6m sampling | PASS locally | `MlTrainingDatasetBuilder` now emits versioned horizon-aware sampling metadata and selects the last available trading day per ISO week for 1m, and per month for 3m/6m; `MlTrainingDatasetBuilderTest` covers all three policies and label-safe streamed builds |
| Current active/eligible training universe | PASS locally | Dataset universe now requires active NSE stocks, excludes benchmarks and requires price history |
| PIT fundamentals | VERIFIED locally | Fundamental facts are filtered by availability date and latest known revision per period/as-of row |
| PIT prices and labels | VERIFIED locally | Features use as-of unadjusted prices; labels use adjusted prices only in the forward label window |
| PIT market breadth / sector context | PASS locally / runtime source pending | `MlUniverseMembership`, dated snapshot capture, `MlHistoricalUniverseMembershipService::backfillHistoricalSnapshots`, `BackfillMlUniverseMembershipCommand`, and durable `MlUniverseSnapshotBackfillRun` provide bounded, resumable, idempotent historical ingestion. Unknown identities fail closed; failed dates and coverage gaps are persisted. Context services refuse current-universe fallback. Authoritative provider feed and production population remain external |
| Intraday/minute boundary | PASS by search | No minute/order-book/microstructure features are wired into FEAT-057 builder; FEAT-065 remains separate |
| Missing-value policy | PASS locally | Resolved profile pins `v8-preprocessing-1`; isolated ML runtime tests prove training-only median/missingness handling, categorical unknown handling, profile/list mismatch rejection, persisted preprocessing state, and prediction schema alignment |
| Outlier handling / redundancy handling | IMPLEMENTED/UNVERIFIED | Training-only encoded-column redundancy diagnostics now persist threshold, fit partition and highly correlated pairs; no automatic pruning is performed, preserving feature-set identity and explainability. Outlier policy and any future pruning decision remain unverified |
| Logistic baseline | PASS by architecture | `MlScoringService` persists `interpretable_logistic_baseline` model path |
| Gradient-boosted challenger | PASS by architecture | Challenger evidence and `hist_gradient_boosting_challenger` path exist |
| Secondary return regressor | IMPLEMENTED/UNVERIFIED | Adapter metadata is persisted when supplied; no local runtime artifact proof in this environment |
| Chronological validation | PASS locally | Partitioned chronological builder, horizon-derived forward label windows, explicit `purge_embargo` metadata, label-overlap purging, and `MlChronologicalValidationGridService` tests exist; broad repeated-window training proof remains |
| Repeated windows / regime slices | PASS locally | Monthly test-window grid and benchmark-volatility slices are covered |
| Probability calibration | IMPLEMENTED/UNVERIFIED | Promotion/evidence paths persist calibration metadata; fitting/runtime proof depends on the Python adapter |
| Active-model comparison | IMPLEMENTED/UNVERIFIED | Promotion review compares candidate evidence and active model paths; complete paired-row proof remains |
| Deterministic StoX baseline comparison | PASS locally | Baseline file is evaluated against the exact test partition and persisted in training evidence |
| Promotion evidence | PASS locally | Candidate/rejected lifecycle, thresholds, challenger evidence and explicit Admin promotion gate exist |
| Explainability | IMPLEMENTED/UNVERIFIED | Adapter contract requires contributions and scoring exposes them; investor-facing evidence needs acceptance review |
| Dataset/model feature-set identity | PASS locally | Dataset `feature_definitions.resolved_feature_profile` and model `audit_metadata.feature_profile` pin horizon, registry, feature keys/versions, preprocessing and definition hash; model training config carries the same profile |
| FEAT-056 boundary | PASS by architecture | Scheduling, deployment and live drift remain in FEAT-056 services rather than dataset construction |

## Verified implementation slice

Horizon-aware sampling, dated membership snapshots, explicit horizon profiles, dataset/model feature-set identity, explicit historical snapshot coverage diagnostics, resumable historical snapshot backfill, horizon-derived purge/embargo evidence, and training-only preprocessing are now implemented and tested. The current implementation remains **IN PROGRESS** until authoritative provider population, full training/validation evidence, active/baseline paired comparisons, and explainability acceptance are closed.

Latest evidence: `MlTrainingDatasetBuilderTest` **9/9** (576 assertions) covers weekly/monthly sampling, active-universe exclusion, label purging, context coverage, membership coverage, and persisted resolved profiles; `MlFeatureRegistryAdminTest` covers deterministic profiles and invalid requests; `MlScoringLifecycleTest` verifies model audit pinning; snapshot coverage/backfill/bounded-query tests pass **19/19** in the focused snapshot group; isolated `ml_adapter.py` contract tests pass **9/9**, including training-only redundancy diagnostics. The broad Feature run under local PHP 512 MB completed **1,309 tests / 1,172 passed** with inherited strategy-readiness fixture failures; the OpenAPI artifact has since been regenerated and its contract test passes.
