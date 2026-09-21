# V7-REQ-001 - Point-in-Time-Safe ML Scoring/Lifecycle Runtime Verification

## 1. Finding Recap

`V7-REQ-001` is `PARTIALLY_IMPLEMENTED`. The repository now contains the training, artifact, scoring and drift implementation, with this correction pass addressing deterministic baseline fidelity, live-health semantics, historical-universe survivorship, corporate-action price safety and artifact concurrency. Production deployment/runtime verification remains outstanding. The earlier audit found a lifecycle scaffold with hard-coded metrics and no model artifact; that historical finding is retained below.

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

### Production scalability finding and repository correction

On 2026-09-21, a read-only production call to MlTrainingDatasetBuilder::build('1m', now()) was terminated after nearly one hour. Production had 2,604 NSE stocks with prices and 14,963,373 portfolio_stock_prices rows spanning 1991-01-02 through 2026-09-18. No MlTrainingRun, MlModelVersion, artifact, prediction or other ML mutation was created.

The root cause was daily reference-row construction for every eligible historical issuer, combined with repeated per-reference price, benchmark, fundamentals and deterministic-baseline work. The repository correction uses the versioned v7-monthly-reference-1 policy: one last-valid-trading-observation per stock per calendar month. It preserves historically eligible inactive issuers and excludes benchmarks, while preloading each stock's price series and point-in-time fundamental facts once per bounded stock chunk. The benchmark series is loaded once per build, and the deterministic Strategy baseline reuses one bounded historical bar cache per test stock.

Production retraining now uses a two-pass partitioned JSONL transport: the first pass derives chronological date boundaries, and the second pass writes train/validation/test rows while buffering only one stock's sampled rows. The PHP service sends dataset paths to the Python adapter, and the baseline writes a matching test-partition JSONL file. Temporary files are deleted in the success and failure paths. Historical Strategy windows use binary search to select at most the trailing 400 bars for each reference date rather than filtering a full history repeatedly.

MlTrainingDatasetBuilder::plan($horizon, $cutoff) is a read-only diagnostic that reports the universe count, monthly sampling definition, estimated reference rows, date range, cutoff and expected chronological partitions without creating lifecycle records or loading the training matrix. Production deployment and a real 1m build remain pending.

### Schema and models

Migration `app/database/migrations/2026_09_12_100001_v7_stox_fundamentals_and_ml.php` creates the four expected ML tables:

- `stox_ml_training_runs`: status, cutoff, configuration, metrics, baselines, selected features, failure, requester and timestamps;
- `stox_ml_model_versions`: horizon/version/status, metadata, metrics, thresholds and promotion audit fields;
- `stox_ml_predictions`: stock/model/as-of/horizon, score, confidence, benchmark, shadow, explanations and feature snapshot;
- `stox_ml_drift_checks`: model, window, status, metrics, warnings and check timestamp.

Migration `2026_09_20_100001_v7_ml_artifacts.php` adds immutable artifact path, SHA-256, format and adapter-version metadata to `stox_ml_model_versions`. Artifacts are stored outside release directories under the shared ML model directory (configurable through `STOXLA_ML_MODEL_DIRECTORY`).

### Service behavior

`app/app/Services/ML/MlScoringService.php` provides dashboard, retrain, promote, rollback, prediction and latest-prediction methods. The implementation now:

- builds a reusable historical dataset through `MlTrainingDatasetBuilder`, using historical eligible NSE issuers rather than today's active flag, one monthly last-valid-trading-day reference per stock, bounded `chunkById` stock processing, partitioned JSONL transport for retraining, unadjusted close prices for PIT features, adjusted prices only for realised label outcomes, and preloaded fundamentals resolved in memory with `availability_date <= as_of`;
- constructs benchmark-relative, drawdown-guarded labels only when the full future horizon is observable by the training cutoff;
- records chronological train/validation/test boundaries and row counts, and calls the managed Python adapter for real logistic fitting/evaluation;
- persists candidate/rejected model metadata and an immutable joblib artifact with SHA-256 integrity metadata; training failures finalize the run as `failed` without creating a usable candidate;
- promotion and rollback use transactions and retain prior versions as `retained`;
- `predict()` verifies the selected artifact hash, rebuilds the versioned feature schema at the requested as-of date, delegates inference to the artifact-backed Python adapter, and persists score, probability-derived confidence, feature snapshot and coefficient contributions;
- the adapter fits median-plus-missingness preprocessing and categorical mappings on the training partition only, and stores that state inside the artifact;
- `MlDeterministicBaselineAdapter` reuses the existing `AsOfFactorScorer` backtest abstraction over the same chronological test rows; the Python adapter compares candidate metrics with those measured baseline outcomes rather than a momentum/trend heuristic.
- The first correction stopped at the raw `AsOfFactorScorer` score. This pass now routes those as-of factors through `EvaluationParameterResolver` and `StrategyConfigurationService::score` using a pinned factory Strategy definition, so weights, gates and scoring configuration are part of the baseline identity.
- The baseline decision is now explicit: the pinned factory uses the `open_position` threshold (85) and requires the canonical Minervini Trend Template eligibility definition. Python consumes that PHP-produced decision; it does not infer a positive baseline from `score / 100 >= 0.5`. Test-row scoring reuses a preloaded historical bar context rather than issuing a price query for each reference date, and the streamed baseline output is checked one-for-one against the test partition.
- The pinned identity includes the factory Strategy key/version, Strategy definition hash, Minervini factory key/version/definition hash, eligibility definition, decision semantics, and baseline adapter version. The same definition is reused for every test row in a run.
- `MlDriftService` persists rolling health checks with explicit `ok`, `warning` or `insufficient_data` results, including model age and matured non-shadow outcomes for hit rate, benchmark-relative return and drawdown. Drift review is Admin-triggered; it does not auto-promote, retrain or deactivate models.
- Model retraining is serialized per horizon, writes to a run-specific temporary artifact, then atomically moves to a versioned immutable path only after integrity verification. Failed lifecycle writes clean up unowned artifacts.

### Routes and UI

Admin routes are protected by the `admin` middleware: dashboard, retrain, promote, rollback and drift-check. The prediction route is authenticated and persists a model-linked prediction. `MlScoringAdminPage.jsx` exposes horizon selection, retrain, candidate promotion, active model and a drift-check action. Shadow predictions remain excluded by `latestPrediction()` and the existing Evaluation integration continues to treat deterministic StoX policy as authoritative.

No scheduled retraining is introduced, consistent with the V7 Admin-triggered-only rule. The deployment now prepares a persistent ML Python virtualenv and the runtime health gate verifies the adapter, scikit-learn import and pinned version without contacting an external provider or training a model.

## 4. Automated-Test Evidence

Focused ML lifecycle, baseline and dataset tests pass **11 tests and 81 assertions** in the production-scale remediation set. They prove:

- an Admin can invoke retraining through a controlled adapter boundary, promote an eligible candidate, persist artifact-backed prediction evidence and roll back a retained version;
- shadow predictions are not returned as authoritative latest predictions;
- an Admin drift check persists an explicit insufficient-data result;
- historical dataset rows are chronological, label horizons end no later than the cutoff, and fundamentals with future availability are excluded from earlier feature rows.
- monthly sampling keeps exactly one reference observation per stock/month, selects the last available trading observation, inactive historical issuers remain represented, the read-only planner reports the sampling plan, and the streamed fixture build stays below the stock-level query-count ceiling with bounded per-stock buffering.
- optimized PIT metrics are compared with the canonical FundamentalDataService at representative as-of dates, and streamed train/validation/test partition metadata records row counts and diagnostics.

The full `app/tests/Feature/V7` suite passed **34 tests and 174 assertions**. Replay/Strategy and historical-baseline tests passed **19 tests and 72 assertions**. Python adapter contract tests pass **7 tests** across the ML and fundamentals adapters, deployment contract tests pass **3 tests**, and TypeScript checking passed. Production lifecycle state remains unverified until the new release is deployed.

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
- Historical feature prices use unadjusted close observations at or before each reference date. Labels use the adjusted series only over the observed future outcome window; this distinction is documented because corporate-action repair can retroactively change stored adjusted values.
- The training universe is based on non-benchmark NSE issuers with historical price observations, so an inactive-now issuer can contribute historical rows without admitting index instruments.
- Fundamentals are read through the canonical service with `availability_date <= as_of`; revisions therefore resolve according to the existing V7 availability/revision contract.
- Chronological partition boundaries are persisted in the training run and model audit metadata.
- Preprocessing state is fitted from training rows and retained in the artifact; prediction checks the artifact schema and integrity before inference.
- Persisted predictions include the requested `as_of`, model version, horizon, benchmark symbol, feature snapshot and coefficient-based explanations.
- `latestPrediction()` only returns non-shadow predictions with `as_of <=` the requested evaluation date.

### Remaining verification boundary

- No production training run, promoted artifact, prediction or drift check exists yet for this implementation release.
- The terminated production build is a scalability observation only: it did not create ML state. The optimized repository path has not yet been exercised against the production-scale universe.
- Revenue growth is computed from comparable available fundamental periods rather than a revenue level. The configured benchmark mapping is versioned and supports explicit sector overrides with a deterministic NIFTY50 fallback; no unapproved sector mappings are invented.
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
| MLR-006 | Deterministic baseline, live-health, historical-universe, feature-price and artifact-concurrency corrections were required after review of the first implementation | `IMPLEMENTED` | High | No; repository correction complete |
| MLR-004 | Production contains no training runs, model versions, predictions, or drift checks, so deployed lifecycle behavior remains unverified | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes |
| MLR-005 | Promotion/rollback/prediction route behavior is covered by tests, but no real production artifact/version exists to verify it operationally | `RUNTIME_VERIFICATION_REQUIRED` | Medium | No additional static defect beyond MLR-001 |
| MLR-007 | The pre-correction production dataset build attempted daily rows across 2,604 issuers and 14,963,373 price rows, ran nearly one hour and was terminated; the monthly/preloaded implementation is not yet deployed or production-verified | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes |

## 9. Final Assessment

`V7-REQ-001 = PARTIALLY_IMPLEMENTED`.

The repository implementation now covers the accepted V7 training, point-in-time dataset, monthly sampled/preloaded dataset construction, logistic artifact, evaluation/baseline, candidate/promotion/rollback, artifact-backed scoring, shadow and drift lifecycle. The status remains partial because the optimized implementation release has not yet been exercised in production, and no deployed ML rows/artifact lifecycle evidence exists. No production mutation was performed.

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
