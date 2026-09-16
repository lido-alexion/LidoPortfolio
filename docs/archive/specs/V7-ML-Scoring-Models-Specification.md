# StoX V7 — ML Scoring Models Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-018 — ML Scoring Models |
| **Status** | DECIDED |
| **Version target** | V7 |
| **Owner** | Architecture / StoX |
| **Purpose** | Add controlled, explainable ML scoring as an additive input to StoX's deterministic Strategy/Evaluation architecture. |

---

## 1. Product boundary

ML is **additive, not authoritative**.

- Deterministic Screener, Strategy and Evaluation semantics remain the primary decision framework.
- ML may contribute a score and an optional hard gate inside Strategy configuration.
- ML must never silently override deterministic eligibility, gates, scoring or recommendation authority.
- Recommendations continue to be produced through the existing StoX Strategy/Evaluation pipeline rather than by an ML model directly.

## 2. Prediction objective

V7 ML predicts **benchmark-relative, risk-aware future success**, not raw return and not the existing deterministic Strategy score.

The first supported prediction horizons are:

- 1 month
- 3 months
- 6 months

Each horizon is trained and evaluated separately. A Strategy explicitly selects the horizon it consumes.

The target is a risk-adjusted/success-labelled outcome whose primary meaning is whether the stock outperforms an appropriate benchmark over the horizon subject to downside/drawdown constraints. The exact label formula and thresholds are implementation configuration, but must be deterministic, versioned and reproducible.

## 3. Benchmark selection

Benchmark selection is contextual and deterministic.

- Use a broad-market benchmark by default.
- Use a sector/index benchmark where that is materially more appropriate to the stock's opportunity set.
- Benchmark mapping rules used for training and inference are versioned with the model/data configuration.

## 4. Model granularity and model families

V7 uses **one production model per prediction horizon**, not separate sector-specific models.

Sector/industry/market context is represented through features rather than separate model families.

Preferred V7 model families are interpretable tabular models, including:

- logistic regression as a transparent baseline;
- tree-based/gradient-boosting models where they provide stronger validated performance.

Neural-network/deep-learning models are outside the initial V7 scope.

## 5. Feature scope

The model may use a combined feature set from:

- technical indicators;
- fundamental indicators/metrics;
- market context;
- sector/industry context.

Feature acquisition must use existing StoX domain abstractions/registries/services where available and must not establish a new direct-table-query architecture.

### 5.1 Point-in-time correctness

Every training/inference feature must respect information availability as-of the observation/evaluation date. No future data may leak into earlier training rows, validation rows, test rows, or historical inference.

Fundamental-data revisions and availability semantics follow the V7 Fundamental Data Integration specification.

### 5.2 Missing features

Training rows may contain partial feature availability. V7 may use controlled missing-value handling and/or model families that natively handle missingness.

- Rows are not discarded wholesale merely because one configured feature is missing.
- Missingness itself may be retained as a signal where appropriate.
- The missing-value strategy is stored with the model version.

Live inference may still produce a prediction when fundamental features are missing or stale if the active model's declared preprocessing/inference contract supports those missing inputs.

This exception applies **only to ML feature handling**. It does not make stale/missing fundamental values eligible for deterministic Screener/Strategy/Exit rules.

### 5.3 Feature selection

Controlled automatic feature selection and regularisation are allowed.

- Weak/redundant features may be removed during training.
- The final selected feature set is stored with the model version.
- Retraining may select a materially different feature set without requiring a separate approval step, provided the resulting candidate passes all promotion criteria.

## 6. Training universe

Training uses a **broad eligible NSE universe**, not only current holdings, watchlists or currently configured Screener universes.

Eligibility requires sufficient data quality/history and basic market eligibility/liquidity safeguards.

Historical training includes securities that were valid/eligible at the time even if they are now delisted or inactive, to reduce survivorship bias.

### 6.1 Minimum history

A stock must have sufficient history before contributing training observations.

- Minimum history is horizon-aware.
- Longer-horizon models require more history than shorter-horizon models.
- Exact thresholds are configuration/implementation defaults rather than frozen PO constants.

## 7. Labels and corporate actions

Forward-return/success labels use corporate-action-adjusted return semantics so splits, bonuses and similar actions do not create artificial gains/losses.

Dividend treatment must be consistent with the selected adjusted-return methodology.

The same return methodology must be applied consistently across training, validation, test and investment-outcome evaluation.

## 8. Class imbalance

Class imbalance must be handled explicitly where present, using techniques appropriate to the chosen model such as class weighting or balanced sampling.

Evaluation must always report the underlying class distribution. Accuracy alone is not sufficient evidence of model quality.

## 9. Training and retraining lifecycle

Retraining is **Admin-triggered only** in V7.

- No automatic scheduled retraining.
- Admin may retrain one selected horizon at a time.
- A `retrain all horizons` action may also be provided as a convenience.
- Training can use all eligible historical data available up to an Admin-configurable cutoff date.
- The cutoff date, training universe definition, feature/preprocessing configuration, label definition and model hyperparameters are stored with the resulting model version.

Each training run creates a **candidate model version**. It never silently replaces an active production model.

## 10. Time-aware validation and leakage prevention

Random train/test splitting across time is prohibited.

Training/validation/test partitions must be chronological and point-in-time correct.

A simple chronological train/validation/test split is sufficient for normal V7 retraining. Multi-window walk-forward validation may be supported/configured for deeper assessment but is not mandatory on every run.

Leakage controls must cover at least:

- temporal ordering;
- feature availability dates;
- fundamental restatements/revisions;
- benchmark data;
- label horizon separation;
- any preprocessing/normalisation fitted only on the training partition.

## 11. Candidate evaluation

Promotion eligibility requires **both statistical quality and investment-outcome quality** on unseen data.

### 11.1 Statistical measures

Evaluation should include suitable measures such as:

- ROC-AUC;
- PR-AUC;
- precision/recall;
- calibration;
- class distribution and confusion-style diagnostics where useful.

### 11.2 Investment-outcome measures

Evaluation should include measures such as:

- benchmark-relative realised return;
- hit/success rate;
- drawdown/downside behaviour;
- other risk-adjusted outcome metrics appropriate to the configured target.

### 11.3 Baselines

A candidate must demonstrate meaningful improvement over **both**:

1. a naive/statistical baseline; and
2. the current deterministic StoX Strategy/Evaluation baseline measured over a comparable historical test period.

Minimum improvement thresholds are Admin-configurable with sensible defaults.

Clearing promotion thresholds makes a candidate **eligible for promotion**; it does not activate the model automatically.

## 12. Promotion, active models and rollback

Promotion is explicit and Admin-controlled.

- Exactly one production model is active per prediction horizon.
- Strategies choose a horizon, not an arbitrary model version.
- Promoting a new version replaces the active production version for that horizon.
- Older versions are retained for audit, reproducibility and rollback.
- Admin may immediately roll back to a retained prior model version for the same horizon without retraining.

No automatic promotion is allowed in V7.

## 13. Shadow mode

Candidate models may optionally run in **shadow mode** against live data before promotion.

- Shadow predictions are generated/stored for evaluation.
- They must not affect Strategy, Evaluation or Recommendation decisions.
- Shadow mode is optional; historical evaluation alone may be sufficient for promotion if all eligibility criteria are met.

## 14. Strategy integration

ML is represented as a registered Strategy scoring factor through the existing indicator/scoring architecture.

A Strategy may configure:

- ML horizon: 1m / 3m / 6m;
- ML score weight;
- optional minimum ML score gate;
- optional minimum confidence threshold.

### 14.1 Weighting

The ML score contributes to the Strategy's existing weighted score when its configured eligibility/confidence requirements are satisfied.

### 14.2 Minimum ML score

If configured, a minimum ML score is a **hard Strategy gate**. Failing it causes the Strategy to fail for that stock.

### 14.3 Confidence

ML outputs expose confidence/uncertainty information.

- Minimum confidence is configured per Strategy.
- If configured and not met, the ML signal does not contribute and cannot satisfy the ML gate.
- Confidence semantics/calibration method are versioned with the model.

## 15. Prediction persistence and evidence

ML predictions used or evaluated by StoX are persisted rather than recomputed later using whichever model is currently active.

Persist at least:

- stock/instrument identity;
- as-of/evaluation timestamp;
- prediction horizon;
- model version;
- score/probability;
- confidence/uncertainty metadata;
- benchmark/context used;
- key explanation metadata;
- shadow/production status where relevant.

Historical Recommendations/Evaluations must reference the prediction/model version that actually existed at the time.

## 16. Explainability

Investor-facing explanation is concise:

- ML score;
- selected horizon;
- confidence;
- top positive contributors;
- top negative contributors.

Detailed diagnostics belong in Admin/model-management surfaces, including model metadata, complete feature sets, training configuration, evaluation metrics, promotion history and drift information.

## 17. Live model health and drift

StoX tracks model age and live performance degradation.

Live performance is evaluated using rolling windows, with V7 supporting windows such as 3, 6 and 12 months.

If performance deteriorates or the model becomes operationally old:

- surface an Admin warning;
- recommend review/retraining;
- do not automatically retrain;
- do not automatically deactivate the model.

## 18. Admin model-management surface

Admin capabilities should include, at minimum:

- view active model per horizon;
- view retained versions and status;
- trigger retraining for one horizon or all horizons;
- choose training cutoff date/configuration;
- inspect training/evaluation status and failures;
- review statistical and investment-outcome metrics;
- compare against naive and deterministic StoX baselines;
- inspect selected features and model metadata;
- configure promotion thresholds;
- optionally enable/run shadow evaluation;
- explicitly promote an eligible candidate;
- roll back to a prior retained version;
- inspect model age, rolling live performance and drift warnings.

## 19. Reproducibility requirements

Each model version must retain enough metadata to reproduce or explain the training/evaluation run, including where applicable:

- horizon;
- training cutoff and partition dates;
- universe/eligibility definition;
- target/label definition version;
- benchmark mapping version;
- feature candidate set;
- selected feature set;
- preprocessing/missing-value strategy;
- model family and hyperparameters;
- imbalance-handling configuration;
- random seed(s) where relevant;
- evaluation metrics and baseline results;
- creation/promotion/rollback timestamps.

## 20. Non-goals for V7

V7 does not include:

- ML as the sole/primary recommendation authority;
- autonomous trading;
- automatic model promotion;
- automatic scheduled retraining;
- automatic deactivation on drift;
- deep-learning/neural-network model families as a requirement;
- sector-specific production model fleets;
- arbitrary user-supplied runtime models/plugins;
- Strategy selection of arbitrary historical model versions.

## 21. Acceptance principles

FEAT-018 is complete only when:

1. deterministic StoX decision semantics remain authoritative and backwards compatible;
2. at least the 1m/3m/6m horizon model lifecycle is supported;
3. training/evaluation is point-in-time safe and chronological;
4. statistical and investment-outcome evaluation plus both required baselines are available;
5. candidate, promotion, active-version, rollback and optional shadow lifecycle is implemented;
6. Strategy can explicitly consume ML score/weight/gate/confidence without bypassing existing Evaluation architecture;
7. predictions and model versions are persisted for historical reproducibility;
8. Admin can manage training, evaluation, promotion, rollback, thresholds and drift review;
9. Investor-facing ML explanations remain concise and understandable;
10. no automated ML action can silently change live decision authority.
