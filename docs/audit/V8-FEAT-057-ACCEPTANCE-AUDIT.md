# FEAT-057 ML Feature Engineering, Training & Validation — reconciliation audit

Status: **IN PROGRESS**

This is a code-reconciliation checkpoint against `docs/archive/specs/V8-ML-Feature-Engineering-Training-Validation-Specification.md`. Existing inherited ML code is useful V7/V8 foundation, but it is not treated as complete merely because training and admin endpoints exist.

| Requirement | Status | Evidence / finding |
|---|---|---|
| Versioned machine-readable feature registry | VERIFIED locally | `MlFeatureRegistryService` now emits stable IDs, formula version, source, transformation, lookback, horizons, sector applicability, tier, missing policy, PIT classification, deprecation field and definition hash |
| Immutable feature-set versioning | IMPLEMENTED/UNVERIFIED | Horizon feature sets now expose deterministic version/hash; persistence through every trained model still requires audit |
| Balanced V8 candidate catalogue | PARTIAL | 49 numeric features plus sector exist; growth/profitability/risk/volume/pattern/market/sector families are present, but frozen catalogue-to-registry completeness needs a formal key matrix |
| Horizon-specific applicability | PARTIAL | Registry declares all current features for all horizons; architect-governed differentiated profiles are not yet complete |
| 1m / 3m / 6m sampling | PASS locally | `MlTrainingDatasetBuilder` now emits versioned horizon-aware sampling metadata and selects the last available trading day per ISO week for 1m, and per month for 3m/6m; `MlTrainingDatasetBuilderTest` covers all three policies and label-safe streamed builds |
| Current active/eligible training universe | PASS locally | Dataset universe now requires active NSE stocks, excludes benchmarks and requires price history |
| PIT fundamentals | VERIFIED locally | Fundamental facts are filtered by availability date and latest known revision per period/as-of row |
| PIT prices and labels | VERIFIED locally | Features use as-of unadjusted prices; labels use adjusted prices only in the forward label window |
| PIT market breadth / sector context | PARTIAL | `MlUniverseMembership` and `MlHistoricalUniverseMembershipService` now provide effective-dated membership/sector snapshots; context services refuse to fall back to current active membership. Snapshot ingestion/backfill and production coverage are still missing |
| Intraday/minute boundary | PASS by search | No minute/order-book/microstructure features are wired into FEAT-057 builder; FEAT-065 remains separate |
| Missing-value policy | IMPLEMENTED/UNVERIFIED | Registry declares median-plus-flag/unknown-category policy; actual fitting/preprocessing is delegated to the Python adapter and needs runtime evidence |
| Outlier handling / redundancy pruning | PARTIAL | Effective feature-set/excluded-feature metadata exists; training-time clipping, redundancy and stability selection need direct evidence |
| Logistic baseline | PASS by architecture | `MlScoringService` persists `interpretable_logistic_baseline` model path |
| Gradient-boosted challenger | PASS by architecture | Challenger evidence and `hist_gradient_boosting_challenger` path exist |
| Secondary return regressor | IMPLEMENTED/UNVERIFIED | Adapter metadata is persisted when supplied; no local runtime artifact proof in this environment |
| Chronological validation | PASS locally | Partitioned chronological builder, label-overlap purging and `MlChronologicalValidationGridService` tests exist |
| Repeated windows / regime slices | PASS locally | Monthly test-window grid and benchmark-volatility slices are covered |
| Probability calibration | IMPLEMENTED/UNVERIFIED | Promotion/evidence paths persist calibration metadata; fitting/runtime proof depends on the Python adapter |
| Active-model comparison | IMPLEMENTED/UNVERIFIED | Promotion review compares candidate evidence and active model paths; complete paired-row proof remains |
| Deterministic StoX baseline comparison | PASS locally | Baseline file is evaluated against the exact test partition and persisted in training evidence |
| Promotion evidence | PASS locally | Candidate/rejected lifecycle, thresholds, challenger evidence and explicit Admin promotion gate exist |
| Explainability | IMPLEMENTED/UNVERIFIED | Adapter contract requires contributions and scoring exposes them; investor-facing evidence needs acceptance review |
| FEAT-056 boundary | PASS by architecture | Scheduling, deployment and live drift remain in FEAT-056 services rather than dataset construction |

## Verified implementation slice

Horizon-aware reference sampling is now implemented and tested. The next mandatory slice is to add an auditable membership snapshot ingestion/backfill path and wire its coverage into dataset diagnostics. The current implementation remains **IN PROGRESS** until the remaining frozen catalogue, PIT context coverage, preprocessing/runtime, and acceptance evidence are closed.
