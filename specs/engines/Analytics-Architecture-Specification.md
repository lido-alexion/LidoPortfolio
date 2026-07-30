# Analytics Architecture Specification

| Field | Value |
|-------|-------|
| **Document** | Analytics Architecture |
| **Version** | 1.0 |
| **Status** | Active (SD-031) |
| **Owner** | Architecture |

---

# 1. Purpose

Every analytical metric belongs to **exactly one category** with a
**single owner**. Every primary page answers **one user question**.

---

# 2. Categories & Ownership

``` text
┌─────────────────────┐     ┌──────────────────────┐
│  Stock Analytics    │     │  Evaluation Profile  │
│  StockAnalyticsSvc  │     │  Evaluation Engine   │
└─────────────────────┘     └──────────────────────┘
┌─────────────────────┐     ┌──────────────────────┐
│ Portfolio Analytics │     │  Market Analytics    │
│ PortfolioAnalytics  │     │  MarketAnalyticsSvc  │
└─────────────────────┘     └──────────────────────┘
```

| Category | Owner | Examples |
|----------|-------|----------|
| Stock Analytics | `StockAnalyticsService` | Beta, volatility, RS, drawdown, liquidity |
| Evaluation Profile | Evaluation Engine (via `EvaluationProfileService`) | Momentum/Trend/Breakout/Volume/Risk scores |
| Portfolio Analytics | `PortfolioAnalyticsService` | Portfolio score, diversification, cash utilisation, averages |
| Market Analytics | `MarketAnalyticsService` → **Market Analysis Engine** (SD-032) | Sentiment, phase, trend, momentum, volatility, risk, drawdown, breadth |

Recommendation Preview is produced by Recommendation Engine /
`RecommendationPreviewService` (not a fifth analytics category — a
decision preview).

---

# 3. Page Responsibilities

| Page | Question | Primary data |
|------|----------|--------------|
| **Dashboard** | How is my portfolio & the market performing? | Portfolio Analytics + Market Analytics |
| **Watchlist** | Should I invest in this stock? | Stock Analytics + Evaluation Profile + Recommendation Preview |
| **Portfolio** (`/holdings`) | How should I manage existing holdings? | Positions, performance, stops, recommendations |
| **Discovery** (`/candidates`) | Which stocks deserve attention today? | Screeners, candidates, rankings |

Rules:

- Dashboard never focuses on individual stock analytics.
- Discovery never shows portfolio statistics.
- Watchlist is the stock research workspace.
- Evaluation scores are never recalculated by pages — reuse Evaluation Engine outputs.

---

# 4. Caching

- `portfolio_analytics_snapshots` — portfolio & market JSON snapshots per profile
- `portfolio_stock_analytics_cache` — per-stock Stock Analytics cache

Avoid recalculating expensive market analytics on every request — Market Analysis
Engine persists daily snapshots in `portfolio_tos_market_analytics`.

---

# 5. APIs (`/api/v1/analytics/*`)

| Path | Owner payload |
|------|---------------|
| `GET .../analytics/portfolio` | Portfolio Analytics |
| `GET .../analytics/market` | Market Analytics (engine façade) |
| `GET .../analytics/dashboard` | Portfolio + Market bundle |
| `GET .../analytics/stocks/{id}` | Stock Analytics |
| `GET .../analytics/stocks/{id}/evaluation-profile` | Evaluation Profile |
| `GET .../analytics/stocks/{id}/recommendation-preview` | Recommendation Preview |
| `GET .../analytics/stocks/{id}/research` | Watchlist research bundle |

Dedicated market APIs: `/api/v1/market-analysis*` — see
[`Market-Analysis-Engine-Specification.md`](./Market-Analysis-Engine-Specification.md).

Legacy `GET /api/analytics/portfolio` and `/api/analytics/stocks/{id}` remain for BC.

---

# 6. Indicator Registry (SD-033)

Stock Analytics fields that are descriptive (distances from 52w, beta, HV,
liquidity ratings, etc.) are classified as Registry type **Metric**.

Evaluation Profile scores remain **Composites** owned by Evaluation Engine.
Market Analytics blocks (trend/momentum/…) are **market-level Composites** owned
by Market Analysis Engine.

All three remain discoverable through the Indicator Registry for Admin UI,
documentation, and consumer capability queries — without changing SD-031 ownership
boundaries.

Spec: [Indicator-Registry-Specification.md](./Indicator-Registry-Specification.md).
