# StoX V8 ML Feature Engineering, Model Training & Validation Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-057 — ML Feature Engineering, Model Training & Validation |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready / depends on V4-FEAT-054 |
| **Owner** | Product / Architecture |
| **Canonical path** | `docs/archive/specs/V8-ML-Feature-Engineering-Training-Validation-Specification.md` |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` |
| **Depends on** | V4-FEAT-054 Historical Fundamental Data Bootstrap for final fundamental-inclusive training campaign |
| **Operational companion** | V4-FEAT-056 ML Lifecycle Automation, Deployment & Operations |
| **Absorbs** | Former V4-FEAT-058 and V4-FEAT-059 |
| **Primary implementation agent** | Codex |

---

## 1. Purpose

V4-FEAT-057 upgrades StoX ML from the narrow V7 baseline into a governed, reproducible, point-in-time-safe feature-engineering, training and validation system.

The epic answers one research question:

> Which point-in-time-safe information should StoX models use for 1m, 3m and 6m horizons, and does a resulting candidate show stable out-of-sample improvement without weakening StoX's deterministic investment authority?

The canonical flow is:

```text
versioned feature registry
    -> PIT / coverage / quality eligibility
    -> horizon-aware candidate feature sets
    -> leakage-safe dataset construction
    -> bounded model-family + hyperparameter challengers
    -> primary classifier + secondary return regressor
    -> repeated chronological validation
    -> probability calibration
    -> paired active-model + deterministic-baseline comparison
    -> promotion-eligibility evidence
```

FEAT-057 produces candidate models and eligibility evidence. It does not own recurring scheduling, deployment, rollback or live operational monitoring; those belong to FEAT-056.

StoX remains a personal-grade investment application. The implementation should prefer correctness, reproducibility and useful signal over enterprise-scale ML platform complexity.

---

## 2. Scope

### 2.1 In scope

- A code-defined, metadata-rich feature registry.
- Immutable feature-set versions.
- A balanced initial candidate universe of approximately 45 features.
- Common core plus horizon-specific feature applicability.
- Conservative mandatory core plus optional challenger pool.
- Point-in-time-safe historical dataset construction.
- Active-stock-only training universe at training time.
- Horizon-specific sampling for 1m, 3m and 6m models.
- Coverage, missingness, outlier and redundancy handling.
- Explicit market-regime, market-breadth and sector-relative context.
- Deterministic graded chart/pattern features.
- Separate global model per horizon, with sector-aware features rather than separate sector models.
- Logistic Regression as mandatory interpretable baseline.
- Gradient-boosted trees as primary nonlinear challenger.
- At most one additional lightweight tree family when it contributes distinct evidence.
- Small, bounded, versioned hyperparameter search.
- Primary binary success classifier plus secondary benchmark-relative return regressor.
- Probability calibration.
- Multiple leakage-safe chronological validation windows.
- Regime-stratified validation.
- Paired candidate-vs-active and candidate-vs-deterministic-baseline evidence.
- Horizon-specific calibrated promotion thresholds.
- Model-appropriate explainability behind one StoX abstraction.
- Training-time drift reference baselines for FEAT-056.
- Investor-facing success probability + expected benchmark-relative return.
- ML outputs as optional Screener eligibility/filter operands.

### 2.2 Out of scope

- Intraday/minute-derived features.
- Neural networks.
- Broad AutoML or large model-family grids.
- Random cross-validation.
- Synthetic oversampling such as SMOTE by default.
- Separate sector-specific models in V8.
- Separate market-regime-specific models in V8.
- Brokerage, taxes, slippage or fill simulation.
- Portfolio allocation or portfolio-level backtesting.
- ML-based Strategy conditions, Strategy scoring or Strategy weighting.
- Automatic BUY/SELL authority derived from ML.
- Scheduled retraining, deployment, rollback, lifecycle notifications and live drift monitoring; these belong to FEAT-056.
- External macro/FII/DII/global-data expansion unless separately specified.

---

## 3. Frozen product and architecture decisions

The following decisions are authoritative. Implementation must not reopen them unless a genuine blocker is found.

| Decision | Frozen choice |
|---|---|
| 057-01 | Evaluate a small model-family set: Logistic Regression baseline + gradient-boosted trees; optional third lightweight tree family only if distinct evidence warrants it. |
| 057-02 | Common governed feature core + horizon-specific additions. |
| 057-03 | Broad curated candidate feature universe of roughly 30-50 features; initial target approximately 45. |
| 057-04 | Tiered minimum historical-coverage policy by core/horizon/sector/experimental class; exact numeric thresholds are evidence-driven implementation configuration. |
| 057-05 | Model-aware missing handling: logistic median+flags, boosting native missing where supported, explicit unknown categoricals, never coerce missing financial facts to zero. |
| 057-06 | Conservative feature-specific clipping/winsorization, learned from training partition only. |
| 057-07 | Hybrid architect-governed candidate universe + evidence-based redundancy/coverage/stability/OOS pruning. |
| 057-08 | Multiple rolling/expanding chronological validation windows with per-window and aggregate evidence. |
| 057-09 | Promotion requires absolute quality + relative improvement + stability; no candidate passes because of one favorable holdout. |
| 057-10 | Same gate types across horizons, but horizon-specific calibrated threshold values. |
| 057-11 | Gate first, then composite evidence; when effectively equivalent, prefer the simpler/current model. |
| 057-12 | One global model per horizon with sector-specific features; no separate sector models in V8. |
| 057-13 | No intraday/minute-derived features in FEAT-057. |
| 057-14 | Training universe is stocks currently active/eligible in StoX at training time. |
| 057-15 | Sampling is horizon-specific: 1m weekly, 3m biweekly or monthly based on evidence, 6m monthly. |
| 057-16 | Primary target remains binary benchmark-relative risk-aware success; return magnitude is secondary evidence. |
| 057-17 | Deterministic patterns are represented as graded numeric features, not only binary flags. |
| 057-18 | Global models use explicit regime features and regime-stratified validation; no separate regime models. |
| 057-19 | Build explicit PIT-safe historical market-breadth and sector-context datasets. |
| 057-20 | Candidate vs active comparison is paired on identical rows/windows/horizons/regime slices where feasible. |
| 057-21 | Replacement requires meaningful improvement; tiny gains are treated as noise/equivalence. |
| 057-22 | Model-appropriate explainability behind one StoX abstraction; no requirement for full SHAP everywhere. |
| 057-23 | Feature-set definitions are first-class immutable versions with formula/PIT/preprocessing/dependency metadata and definition hash. |
| 057-24 | Material feature changes create explicit challenger feature-set versions; no live per-feature Admin toggles. |
| 057-25 | Small bounded challenger campaign, normally active configuration plus 2-3 challengers per horizon. |
| 057-26 | Small bounded versioned hyperparameter search; final test evidence is not used for tuning. |
| 057-27 | Calibrate probability using held-out validation evidence; Platt/sigmoid or isotonic according to evidence/sample size. |
| 057-28 | Model-aware class-imbalance handling; weighting preferred, no synthetic oversampling by default. |
| 057-29 | Ignore transaction costs in FEAT-057 validation. |
| 057-30 | Evaluate at stock/model level; no portfolio simulation in FEAT-057. |
| 057-31 | FEAT-057 persists training-time drift baselines; FEAT-056 owns live drift monitoring. |
| 057-32 | Feature formulas live in code; structured registry metadata describes them; no runtime formula language. |
| 057-33 | Shared governed candidate universe with model-family-specific effective selected subsets. |
| 057-34 | Deterministic StoX baseline is mandatory evidence but not an unconditional universal hard gate; material/persistent inferiority can disqualify a candidate. |
| 057-35 | Freeze the initial V8 candidate feature catalogue in this architecture/spec rather than leaving it to implementation. |
| 057-36 | Balanced multi-family catalogue spanning fundamentals, technicals, risk, volume, patterns, market/breadth and sector context. |
| 057-37 | Architect-defined horizon applicability profiles for features. |
| 057-38 | Each horizon uses mandatory core features plus an optional challenger pool. |
| 057-39 | Core is conservative/small/stable; richer features remain challengers until they prove repeatable value. |
| 057-40 | Train a separate secondary regression model for expected benchmark-relative return magnitude. |
| 057-41 | Regression is a supporting promotion safeguard, not the primary gate; it cannot rescue a weak classifier. |
| 057-42 | Investor-facing output shows calibrated success probability plus expected benchmark-relative return. |
| 057-43 | Full ML outlook appears in stock detail; compact ML summary appears in discovery/Screener rows. |
| 057-44 | ML outputs may be used as optional Screener eligibility/filter operands only. |
| 057-45 | Strategies cannot reference ML outputs directly; Strategy behavior remains deterministic. |

---

## 4. Existing V7 foundation to preserve

Implementation must evolve rather than discard the V7 ML foundation where it remains correct:

- separate 1m/3m/6m model horizons;
- PIT-safe feature construction principles;
- adjusted-close labels where required for corporate-action-safe outcomes;
- horizon-aware label purging across dataset boundaries;
- persisted model/version metadata and artifacts;
- candidate/rejected/active/retained lifecycle concepts consumed by FEAT-056;
- active model prediction/explanation interfaces where compatible;
- model artifact integrity checks;
- deterministic StoX baseline as an explicit comparison reference;
- additive/non-authoritative ML semantics.

The V7 baseline feature set is intentionally superseded by the richer V8 registry and candidate catalogue, but historical metadata and compatibility should remain auditable.

---

## 5. Model horizons and sampling

Train distinct models for:

- `1m`
- `3m`
- `6m`

Each horizon owns its own effective feature subset, model family, hyperparameters, calibration object and evidence thresholds.

Initial sampling policy:

| Horizon | Initial sampling policy |
|---|---|
| 1m | Weekly observations |
| 3m | Biweekly by default; monthly is permitted if validation shows better independence/history quality |
| 6m | Monthly observations |

Sampling must retain full horizon-aware label purging. Daily sampling is not part of V8.

---

## 6. Training universe

The V8 training universe is deliberately limited to stocks that are **currently active/eligible in StoX at training time**.

Historical rows for included stocks must still obey point-in-time feature and label semantics.

This is an explicit product trade-off: StoX accepts some survivorship-bias risk in exchange for a simpler and more relevant personal-grade training universe. The limitation must be documented in candidate evidence so downstream interpretation remains honest.

---

## 7. Feature registry and feature-set versioning

Create a code-defined registry, conceptually `MlFeatureRegistry`, containing at minimum:

- stable `feature_id`;
- investor/developer description;
- feature family;
- formula and formula version;
- data dependencies;
- PIT availability semantics;
- eligible horizons;
- sector applicability;
- core/challenger classification;
- historical coverage class;
- missing-value policy;
- outlier treatment;
- normalization/scaling needs;
- definition hash.

Material changes create immutable feature-set versions, e.g.:

```text
stox-ml-features-v8-1
stox-ml-features-v8-2
```

Every trained candidate must reference the exact feature-set version and persist the exact effective feature subset actually used.

---

## 8. Initial V8 candidate feature catalogue

The initial catalogue targets approximately 45 features. Exact registry names may be normalized during implementation, but the semantic feature set below is frozen.

### 8.1 Growth

- revenue YoY
- revenue 3Y CAGR
- EPS YoY
- EPS 3Y CAGR
- net-income YoY
- net-income 3Y CAGR

### 8.2 Profitability

- ROE
- ROIC where trustworthy inputs exist
- operating margin
- EBITDA margin
- net margin

### 8.3 Balance-sheet / quality

- debt/equity
- net-debt/equity
- current ratio
- interest coverage
- operating cash flow / net income
- free-cash-flow margin

### 8.4 Valuation / shareholder return

- P/E
- P/B
- EV/EBITDA
- EV/EBIT
- dividend yield
- payout ratio

### 8.5 Trend / momentum

- 1m price return
- 3m price return
- 6m price return
- 12m price return
- 3m benchmark-relative strength
- 6m benchmark-relative strength
- price vs MA20
- price vs MA50
- price vs MA200
- medium-term trend slope/persistence

### 8.6 Volatility / risk

- ATR percentage / normalized ATR
- 20-day realised volatility
- 60-day realised volatility
- recent drawdown
- volatility contraction/expansion

### 8.7 Volume / liquidity

- relative volume
- volume trend
- turnover/liquidity proxy

### 8.8 Deterministic chart/pattern features

- breakout distance percentage
- consolidation width percentage
- volume-confirmation ratio
- candle-strength / body-to-range ratio
- distance from support/resistance
- pattern age

All pattern features must be deterministic, formula-defined and point-in-time safe. FEAT-057 does not introduce raw OHLCV sequence discovery.

### 8.9 Market context

- NIFTY/benchmark trend
- NIFTY/benchmark volatility regime
- market breadth above moving-average threshold(s)
- breadth momentum/deterioration
- cross-sectional return dispersion

### 8.10 Sector context

- sector-relative strength
- sector momentum/rank
- sector categorical identity

### 8.11 Banks / NBFCs where reliable

Where FEAT-054 supplies trustworthy point-in-time data:

- GNPA ratio
- NNPA ratio
- capital adequacy ratio
- NIM or equivalent net-interest profitability proxy

Sector-specific absence must not be silently imputed as zero.

---

## 9. Horizon applicability profile

Each feature registry entry declares eligible horizons. The initial emphasis is:

| Feature family | 1m | 3m | 6m |
|---|---:|---:|---:|
| Short momentum / breakout / volume | High | Medium | Low |
| Medium trend / relative strength | High | High | Medium |
| Volatility / regime | High | High | Medium |
| Growth / profitability | Low-Medium | High | High |
| Balance-sheet quality | Low | Medium | High |
| Valuation | Low-Medium | Medium | High |
| Sector context | Medium | High | High |
| Long-history CAGR | Low | Medium | High |

This table guides eligibility; it does not force a feature into the final effective model.

---

## 10. Core and challenger feature policy

Each horizon defines:

1. a small **core** of stable foundational signals; and
2. a broader **challenger pool** that must prove incremental value.

The core should emphasize broad trend/relative strength, volatility, basic profitability/quality, benchmark regime and sector context.

Detailed valuation, advanced cash-flow quality, breadth variants, pattern-strength features, sector-relative variants and bank/NBFC-specific metrics should normally begin as challengers.

Core status never overrides PIT safety, missing-history limits or minimum coverage.

---

## 11. Point-in-time safety and data availability

No training row may contain information unavailable at its reference timestamp.

At minimum:

- fundamentals use FEAT-054 availability semantics, not period-end alone;
- later filing revisions unavailable at the reference date cannot leak backward;
- market/breadth/sector context is reconstructed from contemporaneous data only;
- labels never leak into feature construction;
- preprocessing parameters are fitted on training data only;
- corporate-action treatment must remain consistent with the V7 PIT/label contract.

A feature that cannot be historically reconstructed honestly must be excluded rather than approximated with present-day knowledge.

---

## 12. Coverage, missing values and outliers

### 12.1 Coverage

Use tiered minimum historical coverage classes:

- high for shared/core features;
- medium-high for horizon-specific challengers;
- sector-relative thresholds within the applicable sector subset;
- lower experimental allowance only when repeated OOS evidence is stable.

Exact numeric thresholds are versioned implementation configuration derived from observed V8 data rather than frozen arbitrarily here.

### 12.2 Missing values

- Logistic Regression: training-partition median imputation plus explicit missingness flags.
- Gradient boosting: native missing-value handling where supported.
- Categoricals: explicit unknown/missing state.
- Financial absence: never convert to zero.
- Entirely unavailable train-only features: exclude from the effective candidate schema and record the exclusion reason.

### 12.3 Outliers

Apply conservative, feature-specific clipping/winsorization only where economically justified, especially for growth, leverage and valuation tails.

Thresholds must be estimated using training data only and then applied unchanged to validation/test rows. Naturally bounded indicators should not be clipped merely for uniformity.

---

## 13. Redundancy and effective feature selection

Selection is hybrid rather than fully automatic.

Order of evidence should include:

1. semantic validity and PIT eligibility;
2. historical coverage and missingness;
3. redundancy/correlation screening;
4. model-family-specific coefficient/importance evidence;
5. stability across validation windows;
6. incremental out-of-sample contribution;
7. horizon usefulness.

A shared governed candidate universe is used, but Logistic Regression and gradient boosting may retain different effective subsets.

The exact selected subset must be persisted for every candidate model.

---

## 14. Model families and bounded tuning

### 14.1 Mandatory interpretable baseline

Train Logistic Regression for every horizon/campaign unless a genuine dataset failure prevents it.

### 14.2 Primary nonlinear challenger

Train a mature lightweight gradient-boosted tree implementation compatible with StoX deployment/runtime constraints.

### 14.3 Optional third family

A third lightweight tree ensemble may be introduced only if it provides materially distinct evidence. It is not required for implementation readiness.

### 14.4 Hyperparameter search

Use a small bounded versioned search.

Typical search dimensions:

- Logistic: regularization strength and type where supported;
- Boosting: learning rate, depth/leaves, estimator count, regularization and minimum sample/leaf controls.

Do not use final test evidence to tune hyperparameters.

Every campaign persists the search-space version and selected parameters.

---

## 15. Labels and targets

### 15.1 Primary target

The authoritative ML target remains binary:

```text
benchmark_relative_risk_aware_success
```

The classifier output is calibrated success probability and remains the primary StoX ML score.

### 15.2 Secondary target

For each horizon/configuration, train a separate lightweight regression model for:

```text
expected_benchmark_relative_return
```

This output is supporting evidence and investor context.

It cannot rescue a classifier that fails primary quality gates. Materially poor or unstable regression evidence may prevent a candidate from being promotion-eligible.

---

## 16. Class imbalance and calibration

Use model-aware weighting rather than synthetic-data generation by default.

- Logistic: balanced or evidence-calibrated class weighting.
- Boosting: native class/sample weighting or equivalent.
- PR-AUC remains first-class alongside ROC-AUC because prevalence matters.

Calibrate classifier probability on held-out validation evidence after base fitting.

Permitted initial methods:

- Platt/sigmoid;
- isotonic when sample size supports it.

Persist:

- calibration method/version;
- reliability/calibration metrics;
- reliability buckets/curve data;
- calibrated vs uncalibrated quality.

---

## 17. Chronological validation contract

Use repeated leakage-safe chronological rolling or expanding windows.

The implementation must choose window count/spacing based on available history and horizon while ensuring 6m is not starved of usable validation evidence.

For each window persist at minimum:

- train/validation/test date bounds;
- row/stock counts;
- class prevalence;
- ROC-AUC;
- PR-AUC;
- calibration evidence;
- benchmark-relative realized return evidence;
- regression error/directional evidence;
- downside/drawdown behavior;
- feature coverage;
- effective feature subset;
- model family/configuration;
- regime slice metrics.

Persist aggregate mean/median/dispersion and worst-window evidence where meaningful.

No random train/test split is permitted for authoritative promotion evidence.

---

## 18. Regime, breadth and sector context

Construct explicit PIT-safe historical context rather than using present-day classifications retrospectively where that would leak information.

Initial market context may include:

- percentage of eligible universe above moving-average thresholds;
- advance/decline breadth where reconstructable;
- cross-sectional momentum distribution;
- breadth deterioration/improvement;
- benchmark volatility/trend regime;
- sector relative strength;
- sector dispersion/rank.

Validate candidates overall and across meaningful regime slices such as:

- stronger/bullish periods;
- weak/bearish periods;
- high-volatility periods;
- low-volatility periods.

A candidate must not depend entirely on one favorable regime.

---

## 19. Candidate comparison and promotion eligibility evidence

Compare candidates on identical historical rows/windows/horizons/regime slices wherever feasible.

Evidence must cover:

1. absolute statistical quality;
2. probability calibration;
3. repeated-window stability;
4. benchmark-relative investment outcomes;
5. regression evidence;
6. downside/drawdown behavior;
7. current active ML model comparison;
8. deterministic StoX baseline comparison;
9. regime dependence;
10. model complexity.

The deterministic StoX baseline is mandatory evidence but is not a simplistic universal `candidate_metric > baseline_metric` hard gate.

Material and persistent inferiority to the deterministic baseline may disqualify a candidate. Small mixed differences may still be acceptable when the candidate clears absolute gates and meaningfully improves the active ML model.

Thresholds are horizon-specific and calibrated from repeated historical evidence. Thresholds must never be reduced merely to make a preferred candidate pass.

When surviving candidates are effectively equivalent, retain the current or simpler model.

---

## 20. Explainability

Expose one StoX explanation contract with model-specific implementation.

### Logistic Regression

- standardized coefficient direction/magnitude;
- local coefficient contribution where useful.

### Gradient boosting

- global feature importance;
- lightweight local contribution approach where feasible in the selected library/runtime.

Persist and expose:

- top positive contributors;
- top negative contributors;
- feature rank/importance;
- importance stability across validation windows where practical.

Full SHAP infrastructure is not required in V8.

---

## 21. Investor-facing outputs

### 21.1 Stock detail / research surface

For each supported horizon show, where fresh and available:

- calibrated success probability;
- expected benchmark-relative return;
- top positive contributors;
- top negative contributors;
- concise explanatory/non-guarantee wording;
- model/version context only where useful for transparency rather than clutter.

Example:

```text
3-month ML outlook
Success probability: 68%
Expected benchmark-relative return: +4.2%
```

Do not convert ML output into an automatic BUY/SELL label.

### 21.2 Discovery / Screener rows

Expose a compact representation such as:

```text
3m ML 68% · Exp +4.2%
```

The exact visual treatment may be refined during implementation without changing semantics.

---

## 22. Screener integration

ML outputs may be used as optional Screener eligibility/filter operands, e.g.:

```text
ml_success_probability_3m >= 0.65
ml_expected_relative_return_6m >= 0.05
```

Rules:

- ML conditions are filter/eligibility conditions only;
- ML does not automatically contribute to Screener ranking/scoring;
- missing required ML output causes the condition to fail;
- stale ML output causes the condition to fail according to the configured freshness contract;
- existing Screener condition architecture should be reused rather than creating a parallel ML-only rule engine.

---

## 23. Strategy boundary

Strategies must not reference ML outputs directly in V8.

```text
ML
  -> research context
  -> stock detail
  -> discovery/Screener eligibility filters

Strategy
  -> deterministic logic only
```

ML must not alter Strategy conditions, scores, weights, order behavior or execution authority unless a future explicitly approved feature changes this boundary.

---

## 24. Drift-reference responsibility

FEAT-057 owns training-time reference baselines, including where practical:

- feature distributions;
- feature missingness;
- class prevalence;
- raw/calibrated score distributions;
- sector mix;
- regime mix.

FEAT-056 owns production/live monitoring against those frozen references and any operational response.

---

## 25. Reproducibility and audit contract

A candidate must persist enough metadata to explain what was trained and why it passed or failed.

At minimum persist/reference:

- horizon;
- training universe definition;
- dataset cutoff/snapshot identity;
- sampling-policy version;
- label-definition version;
- feature-set version/hash;
- configured and effective feature subsets;
- preprocessing/outlier policy version;
- model family;
- hyperparameter-search version and selected parameters;
- random seed;
- calibration method/version;
- chronological windows;
- per-window and aggregate metrics;
- active-model comparison;
- deterministic-baseline comparison;
- promotion-gate outcomes;
- training-time drift reference version;
- artifact hash/integrity metadata.

For a fixed dataset snapshot, configuration and seed, training should be deterministic within the guarantees of the selected libraries.

---

## 26. FEAT-054 dependency

Technical/market/pattern feature implementation may begin before FEAT-054 completes.

However, the final fundamental-inclusive V8 feature-selection and training campaign must use the completed FEAT-054 historical fundamental dataset so that growth, profitability, quality, valuation and bank/NBFC features have trustworthy PIT history.

FEAT-057 therefore remains **implementation-ready but dependency-gated** for its final consolidated training outcome.

---

## 27. Boundary with FEAT-056

### FEAT-057 owns

- feature registry and feature-set versions;
- dataset/sampling/label construction contract;
- feature coverage/redundancy/selection;
- model-family and bounded tuning definitions;
- classifier/regressor training;
- calibration;
- repeated chronological validation;
- paired active/baseline comparisons;
- candidate eligibility evidence;
- training-time drift references;
- investor-facing ML semantics and Screener operand semantics.

### FEAT-056 owns

- scheduled/manual queued run orchestration;
- run progress/state UX;
- same-horizon concurrency control;
- deployment/promotion workflow;
- automatic/manual promotion policy execution;
- retained versions and rollback;
- lifecycle notifications and operational failure alerts;
- production drift/model-health monitoring;
- operational Admin controls.

FEAT-056 must consume FEAT-057 evidence; it must not reimplement or bypass the validation contract.

---

## 28. Implementation guidance for unresolved technical parameters

The following are deliberately not PO decisions and must be selected empirically during implementation, persisted as versioned configuration, and covered by tests/evidence:

- numeric coverage thresholds by feature class;
- exact correlation/redundancy cutoff;
- clipping/winsorization quantiles;
- exact 3m biweekly-vs-monthly choice;
- rolling-window count and spacing;
- optional third model family;
- concrete boosting library;
- hyperparameter ranges;
- calibration method per candidate;
- minimum meaningful-improvement margins;
- horizon-specific absolute gate values;
- model freshness limit for Screener conditions.

These choices must follow the frozen principles in this document and must not silently alter product semantics.

---

## 29. Initial acceptance criteria

1. A versioned registry exists for every V8 ML feature and records formula/PIT/dependency/horizon/sector/core-challenger metadata.
2. The initial balanced feature catalogue is implemented without intraday/minute-derived inputs.
3. Training can build leakage-safe 1m, 3m and 6m datasets for currently active/eligible StoX stocks.
4. Sampling follows the frozen horizon-specific cadence policy and performs label-horizon purging.
5. Fundamental features use FEAT-054 PIT availability rather than period-end-only assumptions.
6. Missing financial values are never silently converted to zero.
7. Logistic Regression is trained as the mandatory interpretable baseline.
8. Gradient boosting is trained as the primary nonlinear challenger.
9. Hyperparameter tuning is bounded, versioned and excludes final test evidence.
10. Primary classifier and secondary benchmark-relative-return regressor are both trained/evaluated.
11. Class probabilities are calibrated and calibration evidence is persisted.
12. Authoritative evaluation uses repeated chronological windows rather than one favorable holdout or random CV.
13. Per-window and aggregate statistical, return, downside and regime evidence is persisted.
14. Candidate comparison with the active ML model is paired over comparable historical rows/windows where feasible.
15. Deterministic StoX baseline comparison is persisted as mandatory evidence.
16. Candidate eligibility applies absolute quality, meaningful-improvement, stability and regime-dependence rules.
17. Tiny gains do not force model replacement; equivalent candidates retain the current/simpler model.
18. Model explanations expose useful positive/negative contributors through a common StoX contract.
19. Stock detail can show success probability and expected benchmark-relative return without representing them as guaranteed outcomes or BUY/SELL authority.
20. Discovery/Screener rows can show compact ML outlook where fresh.
21. Screeners can use ML probability/expected-return as optional filter operands; unavailable/stale required values fail the condition.
22. Strategies cannot consume ML outputs directly.
23. Training-time drift reference distributions are persisted for FEAT-056.
24. Every candidate records enough dataset/feature/model/calibration/window/gate metadata for reproducibility and audit.
25. FEAT-056 can consume candidate eligibility evidence without recalculating a separate promotion model.
26. The final fundamental-inclusive campaign is not declared complete until FEAT-054 provides the required historical fundamental coverage.

---

## 30. Definition of done

V4-FEAT-057 is complete when StoX can reproducibly produce and evaluate 1m/3m/6m ML candidates from the frozen V8 feature architecture, generate calibrated and repeated chronological evidence, compare those candidates fairly against both the active ML model and deterministic StoX baseline, expose the approved investor/Screener semantics, and hand one authoritative promotion-eligibility result to FEAT-056.

Completion does **not** require a challenger to beat the incumbent. A correctly rejected candidate with complete evidence is a valid successful training outcome.
