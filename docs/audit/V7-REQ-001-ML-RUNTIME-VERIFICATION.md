# V7-REQ-001 - Point-in-Time-Safe ML Scoring/Lifecycle Runtime Verification

## 1. Finding Recap

`V7-REQ-001` was `RUNTIME_VERIFICATION_REQUIRED`. Repository inspection and read-only production inspection show a reachable Admin/model API and a persisted ML schema, but the deployed database contains no training runs, model versions, predictions, or drift checks. More importantly, the current service is a lifecycle scaffold rather than a complete V7 training/evaluation implementation: retraining records deterministic placeholder metrics and creates metadata, but does not generate a trained artifact from point-in-time features and labels.

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

The schema does not contain a model artifact/blob/path or a persisted training dataset/feature-row manifest. The model version therefore cannot identify or load an actual trained model artifact from the current schema.

### Service behavior

`app/app/Services/ML/MlScoringService.php` provides dashboard, retrain, promote, rollback, prediction and latest-prediction methods. The important implementation facts are:

- `retrain()` creates a `running` row and immediately completes it using `candidateMetrics()`, whose metrics are hard-coded by horizon; it does not build features, labels, partitions, fit a model, evaluate unseen rows, or persist an artifact;
- configuration records declared feature names, chronological split metadata, preprocessing and label/benchmark metadata, but these are declarations rather than evidence of executed training;
- promotion and rollback use transactions and retain prior versions as `retained`;
- `predict()` selects the active version, but scores a small hard-coded formula from a feature snapshot rather than loading the selected model artifact;
- the feature snapshot currently contains sector and two fundamentals metrics. It does not compute the configured technical/market features, labels, benchmark-relative returns, or model-family output;
- fundamentals metrics are requested with an `as_of` date and the canonical fundamentals service applies `availability_date <= as_of`, which is a correct boundary for that sub-path;
- no drift-check service, command, scheduler, controller action, or UI evidence was found.

### Routes and UI

Admin routes are protected by the `admin` middleware: dashboard, retrain, promote, and rollback. The prediction route is authenticated and persists a model-linked prediction. `MlScoringAdminPage.jsx` exposes horizon selection, retrain, candidate promotion, active model and latest run metadata. No drift-management or detailed model-health control is exposed.

No ML command or scheduled retraining was found. This is consistent with the V7 rule that retraining is Admin-triggered only, but it does not provide a scheduled drift-check path.

## 4. Automated-Test Evidence

Focused `MlScoringLifecycleTest` passed **3 tests and 20 assertions**. It proves:

- an Admin can invoke the retrain endpoint and receive a candidate model;
- a candidate can be promoted and an authenticated stock prediction is persisted;
- threshold failure blocks promotion;
- a later retained model can be rolled back.

The full `app/tests/Feature/V7` suite passed **30 tests and 126 assertions**. The ML-specific tests do not prove actual model fitting, chronological feature/label construction, benchmark-relative label correctness, artifact loading, drift checks, shadow non-interference, or production lifecycle state. No dedicated ML leakage test was found.

## 5. Production Runtime Inventory

Read-only inspection was performed on `stoxla-prod` against the active release `3500f4c07aecddba6ee23f9d7f909664e99b4f24`. Counts are sanitized aggregate evidence:

| Table | Count | Status/horizon evidence |
| --- | ---: | --- |
| `stox_ml_training_runs` | 0 | No run lifecycle exists in production |
| `stox_ml_model_versions` | 0 | No candidate, active, retained, or rejected model exists |
| `stox_ml_predictions` | 0 | No scoring/prediction evidence exists |
| `stox_ml_drift_checks` | 0 | No drift evidence exists |

The production `schedule:list` path has no ML retraining/drift job, consistent with Admin-only retraining. Empty ML tables mean promotion, rollback, prediction and drift behavior cannot be runtime-verified in the deployed environment. No training or promotion was triggered for this audit.

## 6. Point-in-Time and Leakage Analysis

### Verified or structurally bounded

- Fundamentals used by the current prediction feature snapshot call `FundamentalDataService::metric(..., $asOf)`, whose fact queries filter `availability_date <= as_of`.
- Persisted predictions include the requested `as_of`, model version, horizon, benchmark symbol, feature snapshot and explanations.
- `latestPrediction()` only returns non-shadow predictions with `as_of <=` the requested evaluation date.
- The model configuration records a chronological split and training-only preprocessing intent.

### Not demonstrated or not implemented

- `retrain()` does not construct any historical feature rows or labels, so cutoff and chronological train/validation/test separation are metadata only.
- No future-return label construction or horizon separation is implemented in the ML service.
- No technical, market, or benchmark historical feature query is part of the current ML feature path; the configured feature list is not executed.
- No actual preprocessing fit is performed on a training partition, and no feature-selection result is generated.
- No artifact is loaded at scoring time, so a prediction is not evidence that the persisted model version's declared configuration was used.
- The service accepts a caller-supplied future `as_of` for prediction rather than demonstrating a bounded historical inference contract.
- No production rows exist to verify point-in-time predictions, benchmark mapping, explanations, or drift windows.

The fundamentals as-of query is a sound dependency boundary, but it cannot by itself establish point-in-time safety for the absent ML training and label pipeline.

## 7. Lifecycle Verification

| Lifecycle | Repository evidence | Production evidence | Assessment |
| --- | --- | --- | --- |
| Training | Route/service/test creates a completed run and candidate metadata; metrics are deterministic placeholders | No runs | Scaffold only; actual training absent |
| Promotion | Transactional candidate threshold check, active replacement and `promoted_by`/timestamp | No models | Test-backed control path; no deployed lifecycle evidence |
| Rollback | Transactional retained-version reactivation; feature test passes | No models | Test-backed control path; no deployed lifecycle evidence |
| Prediction | Active-model lookup, as-of persistence, confidence/explanations/feature snapshot | No predictions | Synthetic score path; no artifact-backed scoring evidence |
| Drift | Table exists | No rows and no implementation path found | Missing implementation |
| Shadow mode | Prediction field and request flag exist | No rows | Storage flag exists; non-interference not independently proven |
| Admin/UI | Protected routes and basic page exist | No live workflow exercised | Reachability exists; operational controls are incomplete |

## 8. Gap Register

| ID | Finding | Classification | Severity | Blocking |
| --- | --- | --- | --- | --- |
| MLR-001 | Retraining does not execute point-in-time feature/label generation or fit/persist a real model artifact; candidate metrics are hard-coded | `PARTIALLY_IMPLEMENTED` | High | Yes |
| MLR-002 | Technical/market/benchmark feature path, chronological label evaluation, and leakage controls are not implemented beyond metadata and the fundamentals as-of dependency | `PARTIALLY_IMPLEMENTED` | High | Yes |
| MLR-003 | Drift checking and Admin drift-health lifecycle are absent | `PARTIALLY_IMPLEMENTED` | Medium | Yes for full V7 lifecycle |
| MLR-004 | Production contains no training runs, model versions, predictions, or drift checks, so deployed lifecycle behavior remains unverified | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes |
| MLR-005 | Promotion/rollback/prediction route behavior is covered by tests, but no real production artifact/version exists to verify it operationally | `RUNTIME_VERIFICATION_REQUIRED` | Medium | No additional static defect beyond MLR-001 |

## 9. Final Assessment

`V7-REQ-001 = PARTIALLY_IMPLEMENTED`.

The schema, protected routes, metadata persistence, candidate/promotion/rollback scaffold, prediction persistence, and fundamentals availability boundary exist. The accepted V7 requirement is not materially complete because actual training, point-in-time feature/label construction, model artifact-backed scoring, drift monitoring, and deployed lifecycle evidence are absent. No production mutation was performed.

## 10. Next Verification Boundary

No production command can close MLR-001 through MLR-003 before implementation exists. After an implementation remediation, the first safe production command should be a read-only aggregate inventory of the four ML tables on the deployed release, before any training or promotion is triggered.

## 11. Sources

- `docs/archive/specs/V7-ML-Scoring-Models-Specification.md`
- `docs/current/strategy-and-recommendations.md`
- `docs/current/market-data-and-data-quality.md`
- `app/app/Services/ML/MlScoringService.php`
- `app/app/Http/Controllers/Api/V1/MlScoringController.php`
- `app/database/migrations/2026_09_12_100001_v7_stox_fundamentals_and_ml.php`
- `app/tests/Feature/V7/MlScoringLifecycleTest.php`
