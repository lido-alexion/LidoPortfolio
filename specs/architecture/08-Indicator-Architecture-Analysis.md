# Indicator Architecture Analysis (As-Built)

**Date:** 2026-07-30  
**Mode:** As-built analysis (historical snapshot of code at analysis time)  
**Purpose:** Complete understanding of the current indicator architecture before designing an Indicator Registry framework  
**Up:** [../README.md](../README.md) · [../../DOCS.md](../../DOCS.md) · [implementation.md](../../implementation.md)

**Target design (accepted):** [09-Indicator-Registry.md](./09-Indicator-Registry.md) · [../engines/Indicator-Registry-Specification.md](../engines/Indicator-Registry-Specification.md) · [SD-033](../governance/SPECIFICATION_DECISIONS.md)

**Related decisions:** [SD-028](../governance/SPECIFICATION_DECISIONS.md) (fixed supported-indicator catalogue, not plugins), [SD-033](../governance/SPECIFICATION_DECISIONS.md) (unified Indicator Registry — evolve, don’t rewrite), [SD-027](../governance/SPECIFICATION_DECISIONS.md) / [SD-030](../governance/SPECIFICATION_DECISIONS.md) (Strategy scoring + Screener eligibility), [SD-031](../governance/SPECIFICATION_DECISIONS.md) / [SD-032](../governance/SPECIFICATION_DECISIONS.md) (Analytics / Market Analysis).

> **Note:** Section 13 recommendations below were the analysis-time proposal. They are **superseded as the authoritative design** by SD-033 and the Indicator Registry Specification. This file remains the as-built baseline.

---

## Headline finding

There is **no unified Indicator Registry**. Two parallel hardcoded catalogues coexist:

| Catalogue | File | Role |
|-----------|------|------|
| **A — Screener primaries** | `app/app/Services/Screener/ScreenerCatalog.php` | Technical indicators for Screener condition trees |
| **B — Strategy composites** | `app/app/Engines/Strategy/SupportedIndicators.php` | 0–100 scoring factors for Strategy / Recommendation |

Calculation of OHLCV-based values lives mainly in **`TechnicalIndicatorService`** (procedural `match` on string IDs — not classes). Strategy UI parameters are largely **not wired** into Evaluation calculation (see §10).

---

## 1. Current indicator architecture

### 1.1 Where are indicators defined?

1. **Screener / technical:** `ScreenerCatalog::indicators()` — hardcoded PHP array (id, label, params, min_bars, needs_volume).
2. **Strategy scoring:** `SupportedIndicators::definitions()` — hardcoded PHP array (key, category, display_name, description, weights, min/max, parameters schema).
3. **Market composites:** Built inline in `MarketAnalysisEngine` (trend / momentum / volatility / risk / sentiment / phase) — not in either catalogue.
4. **Stock analytics metrics:** Built in `StockAnalyticsService` (SMA50/200, trend_strength, HV, etc.) — descriptive, separate from Strategy scores.

### 1.2 Where are they calculated?

| Layer | Calculator |
|-------|------------|
| Primaries / derived series | `App\Services\Screener\TechnicalIndicatorService` (`evaluate`, `evaluateSeries`) |
| Relative strength (raw) | `App\Services\RelativeStrengthService` (+ `StockPriceHistoryService`) |
| Strategy composite scores (0–100) | `App\Engines\Evaluation\EvaluationEngine::evaluateCandidate` |
| Weighted Strategy overall | `App\Services\StrategyConfigurationService::score` |
| Market composites | `App\Engines\Market\MarketAnalysisEngine` (uses TI on benchmark) |
| Stock descriptive analytics | `App\Services\Analytics\StockAnalyticsService` (own SMA/vol helpers + RS) |

### 1.3 Is there an Indicator interface / base class?

**No.** No `IndicatorInterface`, no abstract base class, no per-indicator classes.

### 1.4 Individual classes or functions?

**Neither plugins nor one-class-per-indicator.** Implementations are **private/public methods** inside `TechnicalIndicatorService`, selected by string id via `match ($id)`. Composites are **inline scoring logic** in `EvaluationEngine`.

### 1.5 How are indicators registered? Is there a Registry already?

- Registration = **append to hardcoded catalogue arrays** + implement computation in the matching service.
- There is **no** Indicator Registry that indexes both catalogues, dependencies, or consumers.
- Closest analogues:
  - `ScreenerCatalog` (Screener meta)
  - `SupportedIndicators` (Strategy catalogue; comment explicitly says “Not a plugin framework”)

### 1.6 How are indicators identified?

| Mechanism | Used? |
|-----------|-------|
| String names / IDs | **Yes** (`rsi`, `momentum_score`, …) |
| PHP class constants | **Yes** on `SupportedIndicators` (map to strings) |
| Enums | **No** |
| Numeric DB IDs | **No** for indicator identity |
| Legacy aliases | **Yes** (`momentum` → `momentum_score`, etc. via `SupportedIndicators::aliases()`) |

### 1.7 High-level component diagram

```text
┌─────────────────────┐
│ portfolio_stock_    │
│ prices (OHLCV)      │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐     ┌──────────────────────────┐
│ TechnicalIndicator  │◄────│ ScreenerCatalog (meta)   │
│ Service (match id)  │     └──────────────────────────┘
└─────────┬───────────┘
          │
    ┌─────┼──────────────┬──────────────────┐
    ▼     ▼              ▼                  ▼
 Screener  Evaluation   MarketAnalysis   (StockAnalytics
 Evaluation Engine      Engine            reuses RS / own SMA)
    │         │
    │         ▼
    │   indicator_scores (0–100)
    │   + raw indicators in evidence
    │         │
    │         ▼
    │   SupportedIndicators + StrategyConfigurationService::score
    │         │
    │         ▼
    └────► RecommendationGenerationPipeline
              (consumes facts; does not recalculate TI)
```

---

## 2. Complete indicator inventory

### 2.A Screener / `TechnicalIndicatorService` (32)

| Name | Internal ID | Category | Type | Parameters | Used by |
|------|-------------|----------|------|------------|---------|
| Close | `close` | Price | Primary | — | Screener, Evaluation, Market, Minervini |
| Open | `open` | Price | Primary | — | Screener |
| High | `high` | Price | Primary | — | Screener |
| Low | `low` | Price | Primary | — | Screener |
| Volume | `volume` | Volume | Primary | — | Screener |
| % Change | `change_pct` | Price | Primary | `period` | Screener |
| Highest high (N) | `high_n` | Price | Primary | `period` | Screener |
| Lowest low (N) | `low_n` | Price | Primary | `period` | Screener |
| 52-week high | `high_52w` | Price | Primary | — | Screener, Minervini |
| 52-week low | `low_52w` | Price | Primary | — | Screener, Minervini |
| Range % (H-L)/C | `range_pct` | Price | Primary | — | Screener |
| SMA | `sma` | Trend | Primary | `period` | Screener, Evaluation, Market, Minervini |
| EMA | `ema` | Trend | Primary | `period` | Screener |
| Price vs SMA % | `price_vs_sma_pct` | Trend | Derived | `period` | Screener, Evaluation, Exit, Market |
| Price vs EMA % | `price_vs_ema_pct` | Trend | Derived | `period` | Screener |
| SMA spread % | `sma_spread_pct` | Trend | Derived | `fast`, `slow` | Screener, Minervini |
| EMA spread % | `ema_spread_pct` | Trend | Derived | `fast`, `slow` | Screener |
| RSI | `rsi` | Momentum | Primary | `period` | Screener, Evaluation, Market |
| ROC % | `roc` | Momentum | Primary | `period` | Screener, Market |
| Stochastic %K | `stoch_k` | Momentum | Primary | `period` | Screener |
| Stochastic %D | `stoch_d` | Momentum | Derived | `period`, `smooth` | Screener |
| MACD line | `macd` | Momentum | Derived | `fast`, `slow` | Screener, Market |
| MACD signal | `macd_signal` | Momentum | Derived | `fast`, `slow`, `signal` | Screener, Market |
| MACD histogram | `macd_hist` | Momentum | Derived | `fast`, `slow`, `signal` | Screener |
| ATR | `atr` | Volatility | Primary | `period` | Screener, Evaluation, Market, Exit, Rec risk |
| Bollinger mid | `bb_mid` | Volatility | Primary | `period` | Screener |
| Bollinger upper | `bb_upper` | Volatility | Derived | `period`, `mult` | Screener |
| Bollinger lower | `bb_lower` | Volatility | Derived | `period`, `mult` | Screener |
| Bollinger %B | `bb_pct_b` | Volatility | Derived | `period`, `mult` | Screener |
| Bollinger width % | `bb_width_pct` | Volatility | Derived | `period`, `mult` | Screener |
| Volume SMA | `volume_sma` | Volume | Primary | `period` | Screener |
| Volume / Vol SMA | `volume_ratio` | Volume | Derived | `period` | Screener, Evaluation |

> **Type note:** “Derived” means composed from other series inside `TechnicalIndicatorService` (e.g. MACD from EMAs), but still a first-class Screener catalogue ID.

### 2.B Strategy `SupportedIndicators` (8 composites)

| Name | Internal ID | Category | Type | Parameters (schema) | Used by |
|------|-------------|----------|------|---------------------|---------|
| Relative Strength | `relative_strength` | Momentum | Composite | `lookback_days`, `benchmark` *(UI only today)* | Evaluation → Strategy → Rec / Exit / Dashboard / Stock Analytics |
| Momentum Score | `momentum_score` | Momentum | Composite | `rsi_period` *(UI only; calc uses `trading_os`)* | Evaluation → Strategy → Rec / Dashboard |
| Trend Score | `trend_score` | Trend | Composite | `sma_fast`, `sma_slow` *(UI only)* | Evaluation → Strategy → Rec / Exit / Dashboard |
| Breakout Score | `breakout_score` | Trend | Composite | — | Evaluation (pattern count) → Strategy → Rec |
| Volume Score | `volume_score` | Volume | Composite | `volume_sma_period` *(UI only)* | Evaluation → Strategy → Rec |
| Market Regime | `market_regime` | Market | Composite | — | Evaluation **stub = 50** → Strategy → Rec |
| Sector Strength | `sector_strength` | Market | Composite | — | Evaluation **stub = 50** → Strategy → Rec |
| Risk Score | `risk_score` | Risk | Composite | `atr_period` *(UI only)* | Evaluation → Strategy (max gate) → Rec |

**Legacy aliases:** `momentum` → `momentum_score`, `trend` → `trend_score`, `pattern_bonus` → `breakout_score`, `volume` → `volume_score`, `risk` → `risk_score`.

### 2.C Market Analysis composites (benchmark-level)

| Name | Internal key | Depends on |
|------|--------------|------------|
| Market Trend | `trend` | sma20, sma50, sma200, price_vs_sma200 |
| Market Momentum | `momentum` | rsi, macd, macd_signal, roc |
| Market Volatility | `volatility` | HV, atr, atr_pct |
| Market Risk | `risk` | volatility, drawdown, trend, momentum |
| Sentiment | `sentiment` | weighted trend, momentum, breadth, risk, volatility |
| Market Phase | `phase` | trend, momentum, drawdown, volatility, risk, sentiment |

### 2.D Stock Analytics metrics (descriptive)

| Metric | Source | Storage |
|--------|--------|---------|
| `sma_50` / `sma_200` | Local SMA in `StockAnalyticsService` | `portfolio_stock_analytics_cache` (~6h) |
| `trend_strength` | Close vs SMA50/200 heuristic | Cache |
| `relative_strength` | `StockMetric` or `RelativeStrengthService` | `portfolio_stock_metrics` + cache |
| `historical_volatility_pct` | Log-return stdev × √252 | Cache |
| `beta` (proxy) | Vol / 16 heuristic | Cache |
| `distance_52w_*` | Bars high/low | Cache |

### 2.E Alerts

Alerts do **not** use Screener/Strategy indicators. They use holding/price fields via `HoldingFieldRegistry` (`latest_close`, `gain_loss_percent`, etc.).

---

## 3. Indicator metadata

### 3.1 Does metadata exist?

**Yes, but split** across the two catalogues — not a full Indicator Framework model.

### 3.2 ScreenerCatalog fields

- `id`, `label` (display name)
- `params[]`: `id`, `label`, `default`, `min`, `max`, `step`
- `min_bars` / `min_bars_fn`
- `needs_volume`
- Global `OPERATORS` (not per-indicator)

**Consumed by:** Screener editor UI via `GET /api/screeners/meta` → `ScreenerCatalog::meta()`.

### 3.3 SupportedIndicators fields

- `key`, `display_name`, `description`
- `category`
- `default_enabled`, `default_weight`
- `default_minimum` / `default_maximum`, `supports_maximum`
- `parameters` schema (`type`, `label`, `default`)

**Consumed by:** Strategy page + `StrategyConfigurationService::normalizeConfig` / catalogue API.

### 3.4 Missing today

Units, decimal precision, supported comparison operators **per indicator**, visibility flags, versioning, dependency declarations, consumer declarations.

---

## 4. Dependency analysis

Dependencies are **logical** (hardcoded in Evaluation / Market engines), not declared in a registry.

### 4.1 Strategy composite dependency graph

```text
rsi ──────────────────────► momentum_score ──┐
close + sma(fast/slow) ───► trend_score ─────┤
volume_ratio ─────────────► volume_score ────┤
atr + close (atr_pct) ────► risk_score ──────┼──► Strategy overall (weighted)
discovery patterns[] ─────► breakout_score ──┤
RS 3m (RelativeStrength) ─► relative_strength┤
(none — stub 50) ─────────► market_regime ───┤
(none — stub 50) ─────────► sector_strength ─┘
```

### 4.2 Mapping logic (EvaluationEngine)

| Composite | Depends on | Mapping (summary) |
|-----------|------------|-------------------|
| `momentum_score` | `rsi` | RSI 45–70 → 100; >70 → 55; <30 → 35; else 50 |
| `trend_score` | close, sma_fast, sma_slow | Stack aligned → 100; above fast → 60; else 20 |
| `relative_strength` | RS 3m ratio | ≥1.05 → 100; ≥1.0 → 70; else 30 |
| `volume_score` | `volume_ratio` | ≥1.2 → 100; ≥0.8 → 60; else 30 |
| `breakout_score` | discovery `patterns[]` | `min(100, 40 + 20×count)` |
| `risk_score` | `atr_pct = atr/close×100` | `clamp(atr_pct×10, 0, 100)` |
| `market_regime` | *(none)* | Hardcoded 50 |
| `sector_strength` | *(none)* | Hardcoded 50 |

### 4.3 Market composites

See §2.C — built in `MarketAnalysisEngine` from TI outputs on the primary benchmark.

---

## 5. Screener integration

### How does Screener discover indicators?

| Mechanism | Used? |
|-----------|-------|
| Hardcoded list | **Yes** — `ScreenerCatalog::indicators()` |
| Configuration file | No (beyond catalogue PHP) |
| Unified registry | No |
| Reflection | No |
| Enums | No |
| Database | No |

### How does Screener know available indicators / operators / params?

1. **Available indicators:** `ScreenerCatalog::indicatorIds()` exposed in meta API.
2. **Operators:** Fixed global list — `gt`, `gte`, `lt`, `lte`, `eq` (`ScreenerCatalog::OPERATORS`).
3. **Parameter types:** Numeric with min/max/step; validated by `ScreenerDefinitionValidator` against catalogue param definitions.
4. **Runtime:** `ScreenerEvaluationService` builds `TechnicalIndicatorService::withBars()` engines (stock + optional index entities) and evaluates condition trees / series for backtests.

Factory Minervini eligibility (`MinerviniTrendTemplateScreener`) is a predefined Screener definition using catalogue IDs (`sma`, `sma_spread_pct`, `high_52w`, `low_52w`, `close`).

---

## 6. Dashboard integration

Dashboard widgets **do not** call `TechnicalIndicatorService` directly.

| Widget area | Source | Recalc vs load |
|-------------|--------|----------------|
| Portfolio analytics cards (avg momentum / trend / RS) | `PortfolioAnalyticsService` | Averages Evaluation evidence + StockMetric RS; persist analytics cache |
| Market gauges (trend, momentum, vol, risk, sentiment, phase) | `MarketAnalysisEngine` / `MarketAnalyticsService` | Compute from benchmark OHLCV via TI if stale; persist `portfolio_tos_market_analytics` |
| Market depth “above SMA” | `MarketDepthService` | Own SMA checks; snapshot tables |
| Frontend | `dashboardCache.js` | LocalStorage cache of dashboard payload |

---

## 7. Stock details page

There is **no** dedicated Stock Details page that reuses Screener indicator IDs end-to-end.

Closest surfaces:

| Surface | API / component | How values are obtained |
|---------|-----------------|-------------------------|
| Watchlist research | `/v1/analytics/stocks/{id}/research` | Stock Analytics + Evaluation Profile + Recommendation Preview |
| Stock Explorer | `/analytics/explore` | Manual RS windows |
| Evaluation Profile | `EvaluationProfileService` | Reads **persisted** `EvaluationResult.evidence` — not live Screener recalc |

Backend shares TI / RS services, but UI paths are **independent** of Screener condition evaluation.

---

## 8. Recommendation Engine

### How does Recommendation access indicators?

It **does not calculate** technical indicators itself.

**Dependency flow:**

```text
Discovery (Screener hits + patterns)
    → EvaluationEngine (raw indicators + indicator_scores)
        → StrategyConfigurationService::score (weights / min-max gates)
            → RecommendationGenerationPipeline
                (thresholds, exits, market gates, capital allocation)
                    → TradingRecommendation (persisted)
```

| Question | Answer |
|----------|--------|
| Calculate itself? | No |
| Shared services? | Indirectly via Evaluation facts |
| Query DB indicator tables? | Reads EvaluationResult evidence JSON |
| Use Screener outputs? | Eligibility via Screener hit sets (SD-030); scoring via Evaluation |

Exit rules (`ExitStrategyEvaluator`) reuse `indicator_scores` and raw `indicators` (`atr_pct`, `price_vs_sma_pct`, etc.) from Evaluation evidence.

---

## 9. Data storage

| Indicator class | On demand | In-memory cache | DB / materialised | Historical |
|-----------------|-----------|-----------------|-------------------|------------|
| Screener primaries | Yes per run | TI memo per bars instance | Hits store match evidence, not full series | Backtest series in-process |
| Strategy composites | At Evaluation run | — | `EvaluationResult.evidence` JSON | Per evaluation run |
| Relative strength (raw) | Yes (+ ensure history) | — | `portfolio_stock_metrics.relative_strength_3m` | 1m/3m/6m points |
| Stock analytics | On cache miss | — | `portfolio_stock_analytics_cache` (~6h) | Latest payload |
| Market analytics | If stale/missing | — | `portfolio_tos_market_analytics` | Snapshot history API |
| Market depth SMA flags | Batch | — | Depth snapshot tables | Retention window |

**Examples:**

- Screener run: compute RSI live → compare condition → store hit row (not RSI time series).
- Evaluation: compute RSI → map to `momentum_score` → store both in evidence JSON.
- Dashboard momentum gauge: load latest market analytics snapshot (may recompute if stale).

---

## 10. Configurability

### Configurable today

| Property | Where |
|----------|-------|
| Screener condition params (period, mult, …) | Per-screen JSON definition |
| Strategy weights, enable flags, min/max gates | Strategy `config_json` |
| Strategy thresholds / exit rule values | Strategy config |
| Evaluation periods (rsi/sma/atr/vol) | `config/trading_os.php` → `evaluation.*` |
| Market sentiment weights | `trading_os.market_analysis.sentiment_weights` |

### Gap — appears configurable but unused (architectural bug — tracked separately)

Strategy indicator **`parameters`** (`rsi_period`, `lookback_days`, `sma_fast`/`sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`) are:

- Defined in `SupportedIndicators`
- Editable on Strategy UI
- Persisted in strategy JSON

…but **`EvaluationEngine` reads periods from `trading_os.evaluation`**, not from Strategy parameters.

**Tracking:** [TD-19](../audit/TECHNICAL_DEBT.md) · [PB-054](../governance/PRODUCT_BACKLOG.md). **Explicitly out of scope** for Indicator Registry Phases 1–3 (SD-033 §16).

Also: `trading_os.evaluation.weights` is loaded in Evaluation but **unused** (informational ranking uses equal-weight mean of catalogue scores). Weighted scoring belongs to Strategy `score()`.

---

## 11. Current folder structure (relevant)

```text
app/app/
├── Services/
│   ├── Screener/
│   │   ├── TechnicalIndicatorService.php   ← primary calculator
│   │   ├── ScreenerCatalog.php             ← Screener meta / registry-like list
│   │   ├── ScreenerEvaluationService.php
│   │   ├── ScreenerDefinitionValidator.php
│   │   ├── ScreenerRunService.php
│   │   ├── ScreenerService.php
│   │   └── ScreenerBacktestService.php
│   ├── Analytics/
│   │   ├── StockAnalyticsService.php
│   │   ├── PortfolioAnalyticsService.php
│   │   ├── EvaluationProfileService.php
│   │   ├── MarketAnalyticsService.php
│   │   └── MarketDepthService.php
│   ├── Alerts/
│   │   └── HoldingFieldRegistry.php        ← not technical indicators
│   ├── RelativeStrengthService.php
│   └── StrategyConfigurationService.php
├── Engines/
│   ├── Strategy/
│   │   ├── SupportedIndicators.php         ← Strategy catalogue
│   │   ├── FactoryMomentumStrategy.php
│   │   ├── MinerviniTrendTemplateScreener.php
│   │   └── ExitStrategyEvaluator.php
│   ├── Evaluation/
│   │   └── EvaluationEngine.php            ← composite score facts
│   ├── Recommendation/
│   │   ├── RecommendationEngine.php
│   │   └── RecommendationGenerationPipeline.php
│   └── Market/
│       └── MarketAnalysisEngine.php
└── config/
    └── trading_os.php                      ← evaluation / market periods & weights

app/resources/js/src/
├── pages/
│   ├── ScreenerEditorPage.jsx / ScreenersPage.jsx
│   ├── StrategyPage.jsx
│   ├── DashboardPage.jsx
│   └── StockExplorerPage.jsx
└── components/
    └── WatchlistResearchPanel.jsx
```

---

## 12. Extensibility analysis

| Task | Effort today | Requires code change? | Config-driven? |
|------|--------------|----------------------|----------------|
| Add Screener primary | Medium | Yes: Catalog + TI `match` arms + tests | Params only after ship |
| Add Strategy composite | High | Yes: SupportedIndicators + Evaluation mapping + aliases/docs | Weights/gates only |
| Tune RSI period in Screener | Low | No (condition JSON) | Yes |
| Tune RSI period in Strategy UI | Broken link | Would need Evaluation to read Strategy params | UI saves; calc ignores |
| Add comparison operator | Medium | Yes: OPERATORS + evaluator | No |
| User-defined formula indicator | Not supported | New framework (SD-028 deferred plugins) | No |

### Architectural limitations

1. Dual catalogues with overlapping concepts (`rsi` vs `momentum_score`) and no shared dependency graph.
2. Procedural match-dispatch — harder to isolate/version a single indicator.
3. Strategy parameters not connected to Evaluation calculation (config illusion).
4. Market/Stock analytics sometimes reimplement SMA/vol instead of always using TI.
5. SD-028 rejects plugin runtime — any registry must stay **release-shipped**.
6. No consumer tracking — unclear blast radius when changing an indicator.
7. Alerts are orthogonal (holding fields), not part of the indicator system.

---

## 13. Design recommendations (evolve, don’t rewrite)

> **Superseded for authority:** Implement against [Indicator-Registry-Specification.md](../engines/Indicator-Registry-Specification.md) and [09-Indicator-Registry.md](./09-Indicator-Registry.md) (SD-033). The table below is retained as the analysis-time sketch that informed that design.

**Guiding principle:** Unify **metadata and registration** around the two existing catalogues; keep `TechnicalIndicatorService` as the primary calculator; keep `SupportedIndicators` as the scoring façade. Introduce a thin Indicator Registry that **indexes** both — do not replace Screener JSON or Strategy JSON.

| Phase | What | Why |
|-------|------|-----|
| **0 — Wire params** | EvaluationEngine reads `SupportedIndicators` parameters from active Strategy (fallback `trading_os`) | Fixes largest gap before framework work |
| **1 — Registry façade** | `IndicatorRegistry` listing primary (ScreenerCatalog) + composite (SupportedIndicators) with type, category, deps, consumers | Single discovery API without moving calculation |
| **2 — Declare dependencies** | Add `depends_on[]` to composite definitions (and derived Screener IDs) | DAG tooling + safe change analysis |
| **3 — Enrich metadata** | Units, precision, visibility, version; keep operators global for Screener V1 | UI/docs consistency |
| **4 — Extract calculators** | Optional `IndicatorCalculator` interface behind TI match — migrate hot paths one-by-one | Testability without big-bang rewrite |
| **5 — Consumer registry** | Annotate Screener / Evaluation / Market / Analytics / Exit / Rec as consumers | Blast-radius + docs generation |
| **6 — Versioning** | Tie composite formula version to Strategy version / Evaluation evidence `schema_version` | Explainability & backtest integrity |
| **Later — Strategy indicators** | User-defined composites as weighted formulas over **registered primaries only** (still no free plugins) | Respects SD-028 while enabling custom strategies |

### What to keep

- ScreenerCatalog + TechnicalIndicatorService as the OHLCV engine
- SupportedIndicators + Evaluation facts + Strategy weights as the scoring layer
- Screener-as-eligibility (SD-030)
- Release-shipped catalogue (SD-028)

### What not to do yet

- Plugin loader / EAV indicator tables
- Replace `StrategyConfigurationService` scoring
- Merge Market Analysis sentiment composites into `SupportedIndicators` without a clear category boundary (they operate on **benchmark**, not stock candidates)

---

## Source files (primary)

- `app/app/Engines/Strategy/SupportedIndicators.php`
- `app/app/Services/Screener/ScreenerCatalog.php`
- `app/app/Services/Screener/TechnicalIndicatorService.php`
- `app/app/Engines/Evaluation/EvaluationEngine.php`
- `app/app/Services/StrategyConfigurationService.php`
- `app/app/Engines/Recommendation/RecommendationGenerationPipeline.php`
- `app/app/Engines/Market/MarketAnalysisEngine.php`
- `app/app/Services/Analytics/StockAnalyticsService.php`
- `app/app/Services/RelativeStrengthService.php`
- `app/config/trading_os.php`
