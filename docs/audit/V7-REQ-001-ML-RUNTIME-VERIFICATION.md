# V7-REQ-001 - Point-in-Time-Safe ML Scoring/Lifecycle Runtime Verification

## 1. Finding Recap

`V7-REQ-001` is `PARTIALLY_IMPLEMENTED`. The repository now contains the training, artifact, scoring and drift implementation, and production run #4 verifies the complete 1m training/evaluation/artifact/rejection path. Cross-horizon training and active-model lifecycle verification remain outstanding. The earlier audit found a lifecycle scaffold with hard-coded metrics and no model artifact; that historical finding is retained below.

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

Production retraining now uses a two-pass partitioned JSONL transport: the first pass derives chronological date boundaries, and the second pass writes train/validation/test rows while buffering only one stock's sampled rows. The PHP service sends dataset paths to the Python adapter, and the baseline writes a matching test-partition JSONL file. The Python adapter streams JSONL once to fit training-only preprocessing state, then materializes only compact NumPy matrices and outcome arrays; it does not concatenate the partition rows or retain the raw dictionaries alongside model inputs. Baseline rows are streamed and checked against test `stock_id`/`reference_date` identities. Training diagnostics record transport, partition counts, feature count and peak RSS. Temporary files are deleted in the success and failure paths. Historical Strategy windows use binary search to select at most the trailing 400 bars for each reference date rather than filtering a full history repeatedly.

MlTrainingDatasetBuilder::plan($horizon, $cutoff) is a read-only diagnostic that reports the universe count, monthly sampling definition, estimated reference rows, date range, cutoff and expected chronological partitions without creating lifecycle records or loading the training matrix. A production streamed 1m build subsequently completed in 800.67 seconds with 64.5 MB PHP peak memory, 19 peak buffered rows and 14,516,504 temporary bytes. It processed 2,604 stocks and wrote 40,851 rows: 25,009 train, 8,943 validation and 6,899 test. Viable dates ran from 2025-01-27 through 2026-07-31; NIFTY50 history ran from 2025-01-20 through 2026-09-21. The observed ranges were train `2025-01-27..2026-01-29`, validation `2026-01-30..2026-04-30` and test `2026-05-25..2026-07-31`.

### Production partitioning finding

The first production-scale streamed 1m build on commit `51b4e4c` completed the memory objective but exposed a partitioning defect: 2,604 stocks produced 40,851 rows in 794.03 seconds with 64.5 MB PHP peak memory, 19 peak buffered rows and 14,516,504 temporary bytes, but `train=0`, `validation=0`, `test=40,851`. Pass 1 had derived boundaries from issuer histories reaching back to 1991, while the NIFTY50 benchmark history began on 2025-01-20, so no benchmark-relative rows could exist in the early dates. The repository correction makes pass 1 and pass 2 share the same viable-observation predicate, including issuer lookback/future prices, benchmark entry/future prices and cutoff safety. It also fails immediately when any partition is empty or emitted dates fall outside reported boundaries, and reports viable-date and benchmark date diagnostics. No training, model or other ML mutation occurred during the failed production verification.

The corrected production-scale run verified scale, memory and benchmark-aware partition generation, but static review then identified a final leakage issue: partitions were assigned by exact stock-specific reference dates while labels extend forward by 21, 63 or 126 trading observations. The repository correction assigns complete `YYYY-MM` sampling buckets to chronological train/validation/test partitions, then purges train rows with `label_end >= validation_start` and validation rows with `label_end >= test_start`. Final JSONL output is revalidated for non-empty partitions, single-bucket ownership and label separation; purge counts, maximum label-end dates and nominal/actual ranges are persisted as diagnostics. V7-REQ-001 remains partially implemented pending deployment and training verification.

### Controlled production run #4

Production is green on `75ef65a60de8e10b33c1ba4acb7b53600db42e16`. Controlled 1m retraining completed end-to-end: training run `4` is `completed`, model version `id=1`, `version=1`, horizon `1m` is `rejected`, and no promotion or active model was created. Rejection was expected safeguard behavior: `roc_auc=0.5191991580728832` was below `0.52` and `pr_auc=0.472532148524479` was below `0.50`, while `benchmark_relative_return=0.01452686902941843` and `deterministic_baseline_delta=0.01452686902941843` passed their zero thresholds. This is a successful training lifecycle with an ineligible candidate, not a training failure.

The production 1m dataset wrote 36,381 rows from 2,604 stocks with 25,008 train, 4,474 validation and 6,899 test rows; peak buffered rows were 19 and temporary dataset size was 12,918,047 bytes. The chronological ranges were train `2025-01-27..2025-12-31`, validation `2026-02-23..2026-03-30`, and test `2026-05-25..2026-07-31`. Label separation remained valid: train maximum label end `2026-02-09` preceded validation start `2026-02-23`, and validation maximum label end `2026-05-12` preceded test start `2026-05-25`. Purged overlap rows were 2,214 from train and 2,256 from validation.

The configured features were `relative_strength_3m`, `momentum_score`, `trend_score`, `roe`, `debt_equity`, `revenue_growth_proxy`, and `sector`. The effective production model used only `relative_strength_3m`, `momentum_score`, and `trend_score`. `roe`, `debt_equity`, `revenue_growth_proxy`, and `sector` were excluded because their train-partition coverage was zero: respectively `0/25008`, `0/25008`, `0/25008`, and `0/25008`; technical coverage was `19003/25008`, `25008/25008`, and `25008/25008`. The three fundamental exclusions remain the documented PIT availability boundary. A direct stock-master query found zero populated sectors across 2,605 NSE non-benchmark stocks, so the sector exclusion is recorded separately as a stock-master/data-enrichment gap, not an ML mapping defect. The existing `sector ?: '__unknown'` mapping and all-unknown feature exclusion remain correct.

Run #4 production-verifies dataset construction, bounded streaming, PIT semantics, chronological splitting, label-horizon separation, train-only preprocessing, effective-feature exclusion, deterministic baseline generation, Python fitting, evaluation, naive and deterministic Strategy/Evaluation baseline comparison, artifact creation/SHA persistence, candidate eligibility evaluation, and no automatic promotion. V7 explicitly permits controlled exclusion of weak or unavailable features when the final selected set is persisted, so a technical-only 1m model is contract-valid. Production still has no naturally eligible candidate, active model, prediction, retained prior version, rollback or active-model drift evidence.

### Cross-horizon partition finding

Controlled 3m production training run #5 failed safely during dataset construction before model or artifact creation with `RuntimeException: ML dataset partition is empty: validation`. The fixed percentage monthly bucket allocation left the nominal validation window shorter than the 63-trading-observation label horizon, so every validation row crossed the test boundary and was purged. This was a cross-horizon partition-planning defect exposed after successful 1m verification; run #5 remains preserved evidence.

The repository correction keeps complete monthly buckets intact but selects train/validation cut points from horizon-aware candidate allocations. Pass 1 retains the minimum actual `label_end` per viable reference date/bucket, and candidate boundaries are scored against the nominal 70/15/15 split only after requiring at least one post-purge train and validation row. The existing final purge and hard leakage guards remain authoritative. The selected bucket allocation, nominal/selected boundaries, label horizon and adjustment score are persisted. If no allocation can form three usable partitions, the builder now reports a horizon-specific insufficient-history diagnostic.

### Controlled production runs #6 and #7

Production 3m run #6 completed successfully on the deployed horizon-aware partition implementation. It created model `id=2`, horizon `3m`, version `1`; the candidate passed all configured gates, was explicitly promoted by Admin user `2`, and produced an active prediction plus a persisted drift check with the expected `insufficient_matured_predictions` status. The final dataset contained 14,302 train, 2,163 validation and 6,743 test rows. The configured windows were train `2025-01-27..2025-10-31`, validation `2025-11-24..2026-02-27` and test `2026-03-30..2026-05-29`; label purging left actual validation observations only from `2025-11-24..2025-11-28`, with 6,352 train and 6,637 validation rows purged. This is a successful 3m lifecycle result, but the narrow validation coverage remains a quality concern.

Production 6m run #7 failed safely before model creation with `RuntimeException: Insufficient horizon-aware chronological buckets for 126-observation labels: no train/validation/test allocation retains a usable row after label separation.` The primary NIFTY50 history began around `2025-01-20`, while the current cutoff was September 2026. Code inspection confirmed the cause was insufficient historical primary-benchmark depth, not weakened partition or leakage logic: the existing index backfill window defaulted to 365 days and the separate all-universe history campaign did not provide an explicit ML readiness operation. Run #7 remains preserved evidence and 6m is not runtime-verified.

The repository now provides `MlBenchmarkHistoryPolicy` and the operator command `php artisan portfolio:backfill-ml-benchmark-history`. The policy requests 40 monthly reference buckets, approximately 28 train/6 validation/6 test buckets, plus 9 calendar months of conservative overhead for the 63-observation feature lookback and 126-observation maximum label horizon: 49 calendar months in total. The command deepens only the configured primary benchmark through the existing `StockPriceHistoryService` provider chain, stores missing ranges idempotently, records requested/stored ranges and policy evidence through `SyncLog`, supports `--dry-run` and `--status`, rejects a shallower manually requested range, and fails without synthetic prices when the provider cannot satisfy the requested range. Daily benchmark/index synchronization remains unchanged. No production action was performed during this remediation; a read-only dry run followed by the explicit operator command is required before another 6m training attempt.

### Retraining lock correction

Production verification measured the optimized 1m dataset build at 767.78 seconds (approximately 12.8 minutes), before deterministic Strategy baseline evaluation, Python training/evaluation, artifact verification and persistence. The prior 900-second retraining lease was therefore insufficient and was identified before any production ML lifecycle mutation. Same-horizon retraining now uses the configurable `ml.retrain_lock_seconds` lease, defaulting to 14,400 seconds (four hours), while retaining the 15-second lock-acquisition wait and independent per-horizon keys. No production training, promotion, prediction or drift mutation occurred before this correction.

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

The directly rerun ML lifecycle and dataset regression subset passed **10 tests and 247 assertions**. The dataset partition suite now passes **8 tests and 225 assertions**, including horizon-aware 3m boundary adjustment, 6m safety, deterministic allocation and precise insufficient-history failure. It proves:

- an Admin can invoke retraining through a controlled adapter boundary, promote an eligible candidate, persist artifact-backed prediction evidence and roll back a retained version;
- shadow predictions are not returned as authoritative latest predictions;
- an Admin drift check persists an explicit insufficient-data result;
- historical dataset rows are chronological, label horizons end no later than the cutoff, and fundamentals with future availability are excluded from earlier feature rows.
- monthly sampling keeps exactly one reference observation per stock/month, selects the last available trading observation, inactive historical issuers remain represented, the read-only planner reports the sampling plan, and the streamed fixture build stays below the stock-level query-count ceiling with bounded per-stock buffering.
- optimized PIT metrics are compared with the canonical FundamentalDataService at representative as-of dates, and streamed train/validation/test partition metadata records row counts and diagnostics.

The full `app/tests/Feature/V7` suite passed **41 tests and 370 assertions**. Replay/Strategy and historical-baseline tests passed **117 tests and 935 assertions**. Python adapter contract tests pass **11 tests** across the ML and fundamentals adapters, including JSONL training diagnostics, baseline identity alignment, train-only feature exclusion and large-`int8` label aggregation. Deployment contract tests pass **3 tests**, and TypeScript checking passed. Run #4 supplies the first successful deployed 1m training/artifact/rejection evidence; active-model lifecycle and cross-horizon runtime evidence remain open.

## 5. Production Runtime Inventory

Read-only inspection was performed on `stoxla-prod` against the active release `75ef65a60de8e10b33c1ba4acb7b53600db42e16`. Counts are sanitized aggregate evidence after controlled run #4:

| Table | Count | Status/horizon evidence |
| --- | ---: | --- |
| `stox_ml_training_runs` | 4 | Runs #1-#3 failed safely; run #4 completed |
| `stox_ml_model_versions` | 1 | Version 1 / 1m is rejected; no active model |
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

- The first controlled production training run (`training_run_id=1`, horizon `1m`) failed safely before model creation because the configured numeric feature `roe` had no training-partition values. Production then had `training_runs=1` failed, `model_versions=0`, `predictions=0` and `drift_checks=0`.
- The second controlled production 1m run on commit `670a8110f290cf96c28f6a2e5383caebfca3c580` progressed beyond the prior no-training-values failure and reached logistic fitting, then failed safely with zero model versions created. The observed scikit-learn `OptimizeWarning: Unknown solver options: iprint` was independently reproduced as non-fatal with the exact production Python 3.12.3/scikit-learn 1.5.2 runtime (`FIT_OK` and valid probabilities). The actual adapter exception was masked because `MlPythonAdapter` previously persisted only the first stderr line; the remediation now prefers the last `ml adapter failed:` line, otherwise the last meaningful bounded stderr line. No Python dependency or warning policy was changed; another production retrain is required after this diagnostic correction.
- The third controlled 1m run on commit `03a46bbebdb3c642d806fef2cc8997b25b4d08d9` failed safely after the diagnostic correction exposed the actual exception: `Python integer 4474 out of bounds for int8`. No model version was created. The failure was reproduced under NumPy 2.x semantics in the label class-count arithmetic, where a Python partition length was combined with an `int8` NumPy scalar. Counts now widen to Python integers before subtraction/division; the compact label storage remains unchanged. Another controlled production retrain is required.
- Production fundamental facts were all available on `2026-09-19`, while the latest usable 1m historical observation was `2026-07-31`; the absence of historical `roe`, `debt_equity` and `revenue_growth_proxy` values is therefore correct PIT behavior, not a provider mapping failure. The repository correction excludes entirely unavailable train-only features without fabricating medians, while preserving the configured/effective feature-set audit trail. V8 follow-ons `V4-FEAT-054` and `V4-FEAT-057` remain the boundary for deeper historical fundamental bootstrap and expansion.
- The terminated production build is a scalability observation only: it did not create ML state. The optimized repository path has not yet been exercised against the production-scale universe.
- Revenue growth is computed from comparable available fundamental periods rather than a revenue level. The configured benchmark mapping is versioned and supports explicit sector overrides with a deterministic NIFTY50 fallback; no unapproved sector mappings are invented.
- The V7 configured baseline feature set is retained as requested metadata, then filtered using only final-purged TRAIN coverage. Features with no TRAIN values are excluded from the effective model schema with auditable coverage/reason metadata; deep historical fundamental bootstrap remains outside this work and follows the accepted V8 boundary.

## 7. Lifecycle Verification

| Lifecycle | Repository evidence | Production evidence | Assessment |
| --- | --- | --- | --- |
| Training | Dataset builder, labels, chronological partitions, train-only feature selection and managed logistic adapter; lifecycle test passes | Run #4 completed 1m training, evaluation and artifact persistence; candidate rejected by ROC-AUC/PR-AUC gates | 1m training lifecycle verified; 3m/6m remain open |
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
| MLR-004 | Production now has a completed/rejected 1m artifact lifecycle, but no naturally eligible candidate, active model, prediction, retained version, rollback or active-model drift evidence | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes |
| MLR-005 | Promotion/rollback/prediction route behavior is covered by tests, but no eligible production candidate or active model exists to verify those paths operationally | `RUNTIME_VERIFICATION_REQUIRED` | Medium | No additional static defect beyond MLR-001 |
| MLR-007 | The pre-correction production dataset build attempted daily rows across 2,604 issuers and 14,963,373 price rows, ran nearly one hour and was terminated; the monthly/preloaded streamed implementation subsequently completed at production scale with bounded memory | `IMPLEMENTED` | High | No; scale remediation verified |
| MLR-008 | First controlled 1m production training run failed because configured historical fundamental features had zero train-partition coverage; PIT semantics correctly made them unavailable | `IMPLEMENTED` | High | No; effective-feature exclusion was deployed and run #4 verified it |
| MLR-009 | Third controlled 1m production training run exposed NumPy 2.x `int8` label/count arithmetic failure after reaching logistic fitting | `IMPLEMENTED` | High | No; safe-width aggregation was deployed and run #4 completed |
| MLR-010 | Production stock master has no sector values for 2,605 NSE non-benchmark stocks; all-unknown sector is therefore excluded from the effective model schema | `ACCEPTABLE_VARIATION` | Low | No; separate data-enrichment gap, not an ML mapping defect |
| MLR-011 | 3m run #5 exposed fixed percentage bucket allocation collapsing validation after 63-observation label purging | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes; horizon-aware partition correction must be deployed and retried |
| MLR-012 | 6m run #7 could not form leakage-safe train/validation/test buckets because primary NIFTY50 history began around 2025-01-20 and the existing index backfill window was only 365 days; 3m validation remained technically usable but narrowly covered | `RUNTIME_VERIFICATION_REQUIRED` | High | Yes; primary benchmark history deepening must be run and 6m retried |

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
