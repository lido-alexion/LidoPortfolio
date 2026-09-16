# Market Analysis Engine Specification

| Field | Value |
|-------|-------|
| **Document** | Market Analysis Engine Specification |
| **Version** | 1.0 |
| **Status** | Active (SD-032) |
| **Owner** | Architecture |
| **Depends On** | Data Engine (OHLCV), TechnicalIndicatorService, IndexCatalog / RelativeStrength benchmark |

---

# 1. Purpose

The Market Analysis Engine is the **single source of truth** for market-level
analytics derived from benchmark index OHLCV.

It answers: **What is the current market environment?**

It does **not** evaluate individual stocks, manage portfolios, or issue
recommendations.

``` text
Daily Index OHLCV
        │
        ▼
Market Analysis Engine
        │
        ▼
Market Analytics (trend, momentum, volatility, risk, drawdown, breadth)
        │
        ├── Market Sentiment (0–100, explainable components)
        └── Market Phase (categorical, deterministic)
                │
                ▼
Consumed by Dashboard · Recommendation · Strategy · Portfolio Analytics
```

---

# 2. Ownership Boundary

| Engine | Scope |
|--------|-------|
| **Evaluation Engine** | Stock-level facts / scores |
| **Market Analysis Engine** | Market-level facts / scores |
| **Recommendation Engine** | Decisions consuming both |

Neither Evaluation nor Market Analysis knows about portfolio management or
recommendation generation.

---

# 3. Inputs

- Benchmark OHLCV (`portfolio_stock_prices` for primary index, V1: one
  benchmark via `IndexCatalogService::primaryBenchmarkStock()`, typically NIFTY50)
- Shared indicators via `TechnicalIndicatorService` (SMA, RSI, ATR, MACD, ROC,
  price vs SMA) — **no duplicated indicator math**

---

# 4. Outputs

Persisted snapshot (`portfolio_tos_market_analytics`) + API payload:

| Block | Contents |
|-------|----------|
| Trend | label, direction, strength, quality, distance from 200 DMA |
| Momentum | score, label, direction, RSI/MACD/ROC evidence |
| Volatility | HV, ATR, ATR%, ADR, Low→Extreme label |
| Risk | raw + safety score, Low→Extreme, gap/vol/DD/trend-failure flags |
| Drawdown | current, max, recovery, distance ATH / 52w high |
| Breadth V1 | index-proxy A/D and % above DMA placeholders (constituent V2 later) |
| Sentiment | 0–100 + label + weighted component scores |
| Phase | Strong Bull … Recovery (deterministic rules) |
| Consumer helpers | `allocation_multiplier`, `new_entry_allowed` |

---

# 5. Sentiment (0–100)

Configurable weights (`config/trading_os.php` → `market_analysis.sentiment_weights`):

| Contributor | Default max points |
|-------------|-------------------|
| Trend | 25 |
| Momentum | 20 |
| Breadth | 20 |
| Risk (safety) | 20 |
| Volatility (calm) | 15 |

Components are persisted for explainability (e.g. Trend 24/25).

---

# 6. Market Phase Rules (deterministic)

Evaluated in order; first match wins:

1. **Capitulation** — DD ≥ 20% + Extreme vol + weak momentum  
2. **Strong Bull** — trend ≥ 80 + momentum ≥ 65 + DD < 5% + sentiment ≥ 75  
3. **Bull** — trend ≥ 60 + sentiment ≥ 60 + DD < 8%  
4. **Pullback** — trend ≥ 55 + DD 5–12% + cooling momentum  
5. **Correction** — DD 12–20% + weakened trend  
6. **Bear** — trend ≤ 35 + sentiment ≤ 40  
7. **Recovery** — stabilising mid-band trend/momentum/sentiment  
8. **Consolidation** — sideways + contained risk, or default  

Phase is never entered manually. Every phase response includes
`explainability.phase_rule` and factor reasons.

---

# 7. Service & APIs

- Engine: `App\Engines\Market\MarketAnalysisEngine`
- Façade: `App\Services\Analytics\MarketAnalyticsService` (SD-031 consumers)

| Method | Path |
|--------|------|
| Latest | `GET /api/v1/market-analysis` |
| Sentiment | `GET /api/v1/market-analysis/sentiment` |
| Phase | `GET /api/v1/market-analysis/phase` |
| History / timeline | `GET /api/v1/market-analysis/history` · `/timeline` |
| Explainability | `GET /api/v1/market-analysis/explainability` |

Also exposed via `GET /api/v1/analytics/market` (bundle for Dashboard).

---

# 8. Consumer Contracts

- **Recommendation** — reads `allocation_multiplier`, `new_entry_allowed`,
  optional Strategy `market_gates`; never recalculates market metrics.
- **Strategy** — optional `market_gates` (min sentiment, allowed phases,
  max raw risk).
- **Portfolio Analytics** — attaches `market_context` for alignment notes.
- **Dashboard** — dedicated Market Analytics section + explainability.

---

# 9. V1 Constraints / Non-goals

**In scope:** one benchmark, deterministic calc, sentiment, phase, trend,
momentum, volatility, risk, drawdown, explainability, dashboard.

**Out of scope:** AI/ML forecasting, news/social sentiment, macro, fundamentals.
Designed so these can be added as additional contributors later without redesign.

---

# 10. Migration

Table: `portfolio_tos_market_analytics` (migration `2026_07_26_000013_*`).
Snapshots keyed by `(benchmark_stock_id, as_of_date)`.

---

# 11. Indicator Registry (SD-033)

Market Analysis outputs (Trend, Momentum, Volatility, Risk, Sentiment, Phase,
and presentation aggregates such as Market Health) are registered as
**Composites** with `market_level=true`.

Underlying SMA/RSI/ATR/MACD/ROC remain **Primaries** calculated via
`TechnicalIndicatorService`.

The Registry provides metadata, dependency trees, and formula explanations for
Admin / docs; it does **not** replace MarketAnalysisEngine calculation or
sentiment weight config in `trading_os.php`.

Spec: [./Indicator-Registry-Specification.md](./Indicator-Registry-Specification.md).
