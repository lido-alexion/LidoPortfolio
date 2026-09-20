# V7-REQ-001 - Point-in-Time-Safe ML Scoring/Lifecycle Runtime Verification

## 1. Finding Recap

`V7-REQ-001` is `PARTIALLY_IMPLEMENTED`. The repository now contains the missing training, artifact, scoring and drift implementation, but production deployment/runtime verification remains outstanding. The earlier audit found a lifecycle scaffold with hard-coded metrics and no model artifact; that historical finding is retained below as the reason for this implementation pass.

The audit is therefore not closed. No production behavior was changed.

## 2. Accepted V7 Contract

The authoritative contract is `docs/archive/specs/V7-ML-Scoring-Models-Specification.md`, with the current strategy documentation preserving the additive/non-authoritative boundary. V7 requires:

- separate 1m, 3m, and 6m horizon models;
- benchmark-relative, risk-aware labels with deterministic, versioned definitions;
- broad eligible-universe training with explicit cutoff and reproducible configuration;
- technical, fundamental, market, and contextual features through StoX abstractions;
- point-in-time-safe features, labels, benchmark data, revisions, and preprocessing;
- chronological train/validation/test partitions, with no random temporal split or future leakage;
- explicit missing-value handling and stored preprocessing/selected features;
- statistical metrics, investment-outcome metrics, naive baseline, and deterministic StoX baseline;
- Admin-triggered candidate training, explicit promotion, one active model per horizon, retained versions, and rollback;
- persisted prediction/model provenance, score, confidence, benchmark, explanations, feature snapshot, and shadow state;
- optional shadow prediction mode that cannot affect deterministic decisions;
- rolling drift/live-health checks and Admin warnings without automatic promotion/deactivation;
- additive Strategy/Evaluation integration, with deterministic StoX semantics remaining authoritative;
- Admin/API/UI controls and an auditable lifecycle.

Outside V7: automatic retraining/promotion/deactivation, autonomous trading, neural-network model families, arbitrary runtime plugins, and selection of arbitrary historical model versions by Strategy.

## 3. Implementation Map

### Schema and models

Migration `app/database/migrations/2026_09_12_100001_v7_stox_fundamentals_and_ml.php` creates the four expected ML tables:

- `stox_ml_training_runs`: status, cutoff, configuration, metrics, baselines, selected features, failure, requester and timestamps;
- `stox_ml_model_versions`: horizon/version/status, metadata, metrics, thresholds and promotion audit fields;
- `stox_ml_predictions`: stock/model/as-of/horizon, score, confidence, benchmark, shadow, explanations and feature snapshot;
- `stox_ml_drift_checks`: model, window, status, metrics, warnings and check timestamp.

Migration `2026_09_20_100001_v7_ml_artifacts.php` adds immutable artifact path, SHA-256, format and adapter-version metadata to `stox_ml_model_versions`. Artifacts are stored outside release directories under the shared ML model directory (configurable through `STOXLA_ML_MODEL_DIRECTORY`).

### Service behavior

`app/app/Services/ML/MlScoringService.php` provides dashboard, retrain, promote, rollback, prediction and latest-prediction methods. The implementation now:

- builds a reusable historical dataset through `MlTrainingDatasetBuilder`, using prices and benchmark observations bounded by the cutoff and fundamentals queried with `availability_date <= as_of`;
- constructs benchmark-relative, drawdown-guarded labels only when the full future horizon is observable by the training cutoff;
- records chronological train/validation/test boundaries and row counts, and calls the managed Python adapter for real logistic fitting/evaluation;
- persists candidate/rejected model metadata and an immutable joblib artifact with SHA-256 integrity metadata; training failures finalize the run as `failed` without creating a usable candidate;
- promotion and rollback use transactions and retain prior versions as `retained`;
- `predict()` verifies the selected artifact hash, rebuilds the versioned feature schema at the requested as-of date, delegates inference to the artifact-backed Python adapter, and persists score, probability-derived confidence, feature snapshot and coefficient contributions;
- the adapter fits median-plus-missingness preprocessing and categorical mappings on the training partition only, and stores that state inside the artifact;
- `MlDriftService` persists rolling score-distribution checks with explicit `ok`, `warning` or `insufficient_data` results. Drift review is Admin-triggered; it does not auto-promote, retrain or deactivate models.

### Routes and UI

Admin routes are protected by the `admin` middleware: dashboard, retrain, promote, rollback and drift-check. The prediction route is authenticated and persists a model-linked prediction. `MlScoringAdminPage.jsx` exposes horizon selection, retrain, candidate promotion, active model and a drift-check action. Shadow predictions remain excluded by `latestPrediction()` and the existing Evaluation integration continues to treat deterministic StoX policy as authoritative.

No scheduled retraining is introduced, consistent with the V7 Admin-triggered-only rule. The deployment now prepares a persistent ML Python virtualenv and the runtime health gate verifies the adapter, scikit-learn import and pinned version without contacting an external provider or training a model.

## 4. Automated-Test Evidence

Focused ML lifecycle and dataset tests pass **5 tests and 35 assertions**. They prove:

- an Admin can invoke retraining through a controlled adapter boundary, promote an eligible candidate, persist artifact-backed prediction evidence and roll back a retained version;
- shadow predictions are not returned as authoritative latest predictions;
- an Admin drift check persists an explicit insufficient-data result;
- historical dataset rows are chronological, label horizons end no later than the cutoff, and fundamentals with future availability are excluded from earlier feature rows.

The full `app/tests/Feature/V7` suite passed **32 tests and 141 assertions**. Python adapter contract tests pass **6 tests** across the ML and fundamentals adapters; the ML adapter tests cover machine-readable drift output and failure/noise behavior, while production scikit-learn fitting is validated by the managed deployment runtime. Production lifecycle state remains unverified until the new release is deployed.

## 5. Production Runtime Inventory

Read-only inspection was performed on `stoxla-prod` against the active release `3500f4c07aecddba6ee23f9d7f909664e99b4f24`. Counts are sanitized aggregate evidence:

| Table | Count | Status/horizon evidence |
| --- | ---: | --- |
| `stox_ml_training_runs` | 0 | No run lifecycle exists in production |
| `stox_ml_model_versions` | 0 | No candidate, active, retained, or rejected model exists |
| `stox_ml_predictions` | 0 | No scoring/prediction evidence exists |
| `stox_ml_drift_checks` | 0 | No drift evidence exists |

The production inventory in the prior audit remains valid for the pre-implementation release: the ML tables were empty and no training or promotion was triggered. This implementation task did not mutate production. After deployment, the first verification should be a read-only aggregate inventory before any Admin training or promotion is triggered.

## 6. Point-in-Time and Leakage Analysis

### Verified or structurally bounded

- Fundamentals used by the current prediction feature snapshot call `FundamentalDataService::metric(..., $asOf)`, whose fact queries filter `availability_date <= as_of`.
- Historical price and benchmark rows are selected at or before each reference date and future label observations are bounded by the training cutoff.
- Fundamentals are read through the canonical service with `availability_date <= as_of`; revisions therefore resolve according to the existing V7 availability/revision contract.
- Chronological partition boundaries are persisted in the training run and model audit metadata.
- Preprocessing state is fitted from training rows and retained in the artifact; prediction checks the artifact schema and integrity before inference.
- Persisted predictions include the requested `as_of`, model version, horizon, benchmark symbol, feature snapshot and coefficient-based explanations.
- `latestPrediction()` only returns non-shadow predictions with `as_of <=` the requested evaluation date.

### Remaining verification boundary

- No production training run, promoted artifact, prediction or drift check exists yet for this implementation release.
- The deterministic StoX baseline adapter currently evaluates the configured comparable test rows; live production comparison and promotion review remain Admin/runtime activities.
- The feature set is intentionally the V7 configured baseline set; deep historical fundamental bootstrap remains outside this work and follows the accepted V8 boundary.

## 7. Lifecycle Verification

| Lifecycle | Repository evidence | Production evidence | Assessment |
| --- | --- | --- | --- |
| Training | Dataset builder, labels, chronological partitions and managed logistic adapter; lifecycle test passes | No post-implementation run | Repository implemented; production run pending |
| Promotion | Transactional candidate threshold check, artifact integrity gate and active replacement | No post-implementation model | Repository implemented; production artifact review pending |
| Rollback | Transactional retained-version reactivation with artifact verification | No post-implementation model | Repository implemented; production rollback pending |
| Prediction | Artifact hash/schema verification, model inference, provenance and explanations | No post-implementation predictions | Repository implemented; production scoring pending |
| Drift | Managed score-distribution adapter, persisted result and Admin endpoint/UI | No post-implementation checks | Repository implemented; production evidence pending |
| Shadow mode | Shadow persistence and authoritative latest exclusion | No post-implementation rows | Test-backed non-interference; production evidence pending |
| Admin/UI | Protected retrain/promote/rollback/drift controls and deployment runtime gate | No live workflow exercised | Repository implemented; production reachability pending |

## 8. Gap Register

| ID | Finding | Classification | Severity | Blocking |
| --- | --- | --- | --- | --- |
| MLR-001 | Historical dataset construction, real logistic fitting, evaluation and immutable artifact persistence were absent in the audited scaffold | `IMPLEMENTED` | High | No; repository remediation complete |
| MLR-002 | Point-in-time feature/label construction, chronological partitions and training-only preprocessing were absent in the audited scaffold | `IMPLEMENTED` | High | No; repository remediation complete |
| MLR-003 | Drift checking and Admin drift-health lifecycle were absent in the audited scaffold | `IMPLEMENTED` | Medium | No; repository remediation complete |
| MLR-004 | Production contains no training runs, model versions, predictions, or drift checks, so deployed lifecycle behavior remains unverified | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes |
| MLR-005 | Promotion/rollback/prediction route behavior is covered by tests, but no real production artifact/version exists to verify it operationally | `RUNTIME_VERIFICATION_REQUIRED` | Medium | No additional static defect beyond MLR-001 |

## 9. Final Assessment

`V7-REQ-001 = PARTIALLY_IMPLEMENTED`.

The repository implementation now covers the accepted V7 training, point-in-time dataset, logistic artifact, evaluation/baseline, candidate/promotion/rollback, artifact-backed scoring, shadow and drift lifecycle. The status remains partial only because the implementation release has not yet been exercised in production and no deployed ML rows/artifact lifecycle evidence exists. No production mutation was performed.

## 10. Next Verification Boundary

The first safe production verification command after deployment should be a read-only aggregate inventory of the four ML tables and the ML runtime health state, before any Admin training or promotion is triggered.

## 11. Sources

- `docs/archive/specs/V7-ML-Scoring-Models-Specification.md`
- `docs/current/strategy-and-recommendations.md`
- `docs/current/market-data-and-data-quality.md`
- `app/app/Services/ML/MlScoringService.php`
- `app/app/Http/Controllers/Api/V1/MlScoringController.php`
- `app/database/migrations/2026_09_12_100001_v7_stox_fundamentals_and_ml.php`
- `app/tests/Feature/V7/MlScoringLifecycleTest.php`
