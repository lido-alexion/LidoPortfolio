# Explorer / Analytics — Retrospective CURRENT Specification

**Document:** V2.1 Retrospective CURRENT Spec  
**Location:** `docs/v2.1/EXPLORER-ANALYTICS-SPEC.md`  
**Date:** 2026-08-10  
**Status:** CURRENT (runtime formalization — not a new feature)  
**Related:** Arch intent [`Analytics-Architecture-Specification.md`](../../specs/architecture/portfolio/Analytics-Architecture-Specification.md); Dashboard [`DASHBOARD-SPEC.md`](./DASHBOARD-SPEC.md); F137 [`docs/v2/F137-BOUNDARY.md`](../v2/F137-BOUNDARY.md); WS-C [`WS-C-SHADOW-FEATURE-INVENTORY.md`](./WS-C-SHADOW-FEATURE-INVENTORY.md) B5  

**No new F-number.** Do not redesign formulas, indicators, or start V3.

---

## 1. Purpose

Formalize **CURRENT** Explorer and Analytics surfaces so:

- metric ownership stays single-owner (SD-031)  
- Explorer remains **universe-cache research**, not financial SoT  
- consumers (Dashboard, Watchlist, Evaluation, Screeners) do not silently redefine RS/growth  

This pack covers:

1. **Stock Explorer** (`/explorer`, `POST /api/analytics/explore`)  
2. **SD-031 Analytics Architecture** owners + `/api/v1/analytics/*`  
3. **Shared RS/growth math** (`StockPriceHistoryService`, `RelativeStrengthService`)  
4. Related consumers (Dashboard RS/market, Watchlist research, MetricsUpdate)

---

## 2. Current capability

| Area | Status |
|------|--------|
| Explorer UI: symbol + multi-index benchmark, deep links | **IMPLEMENTED** |
| Growth % / RS for 1M, 3M, 6M, 1Y from OHLCV cache | **IMPLEMENTED** |
| Bar chart + 12M normalized gain line chart | **IMPLEMENTED** |
| Manual RS fallback (client-side, 6M) when analyze fails/incomplete | **IMPLEMENTED** |
| Cache-only Explorer (no on-demand provider fetch) | **IMPLEMENTED** |
| Rate limit `analytics-explore` 20/min | **IMPLEMENTED** |
| Stock Analytics (beta, vol, drawdown, SMA, RS…) + 6h cache | **IMPLEMENTED** |
| Portfolio Analytics (diversification, cash util, averages) + ~15m cache | **IMPLEMENTED** |
| Market Analytics façade → Market Analysis Engine | **IMPLEMENTED** |
| Evaluation Profile API | **IMPLEMENTED** |
| F137 Recommendation Preview + Watchlist research bundle | **IMPLEMENTED** (owned by F137; consumed here as sibling) |
| Classic `GET /api/analytics/portfolio|stocks/{id}` | **IMPLEMENTED** (legacy parallel to v1) |
| Explorer period toggle UI | **NOT FOUND** — all four periods always requested |
| Explorer as portfolio valuation / cash SoT | **NOT IMPLEMENTED** (correctly) |
| Live peer comparison matrix in Explorer | **NOT FOUND** |

---

## 3. User workflows (CURRENT)

### 3.1 Open Stock Explorer

1. Operator opens **Market → Stock Explorer** (`/explorer`).  
2. Optional deep link: `/explorer?symbol=RELIANCE&benchmark=NIFTY50` auto-runs analysis.  
3. Load `GET /api/indexes` for benchmark `<select>` (NSE/BSE groups; primary flagged).  
4. Stock autocomplete searches **both** exchanges (no exchange toggle).  
5. **Run Analysis** → `POST /api/analytics/explore` with `periods: [1,3,6,12]`.  
6. UI shows: latest closes, period start-close tables, four RS cards, comparison bar chart, 1Y normalized % gain chart.  
7. Incomplete cache / validation failure → toast + optional **Manual Relative Strength Input** (6M only, in-browser; not persisted).

### 3.2 Watchlist research (analytics consumer)

1. Watchlist stock detail loads research bundle: Stock Analytics + Evaluation Profile + Recommendation Preview (`GET /api/v1/analytics/stocks/{id}/research`).  
2. Explorer remains a separate deep-link target for growth/RS charts.

### 3.3 Dashboard / Metrics consumers

1. Dashboard uses Portfolio + Market analytics + holdings RS from `StockMetric` (primary benchmark).  
2. `MetricsUpdateService` refreshes `portfolio_stock_metrics` RS via `RelativeStrengthService::calculateForStock` (may **ensure** history / fetch — distinct from Explorer cache-only path).

---

## 4. Data loading & calculation flow (Explorer)

```text
StockExplorerPage
  ├─ GET /api/indexes → benchmark options
  └─ POST /api/analytics/explore
        └─ ExploratoryAnalyticsService::analyze
              ├─ StockValidationService (local master only, allowProvider=false)
              ├─ IndexCatalogService::ensureIndexStock(benchmark)
              ├─ getCachedAnalyticsHistoryStatus (stock + benchmark) — NO provider fetch
              ├─ for each period 1/3/6/12:
              │     growth%, benchmark growth%, RS = growth − bench growth
              │     period_closes (on-or-before closes)
              ├─ chart rows + normalized_gain_chart (12M)
              └─ tracking flags (portfolio vs exploratory) for active profile
```

| Kind | Examples |
|------|----------|
| **Live calculation** | Growth/RS computed on request from stored OHLCV |
| **Cached market data** | `portfolio_stock_prices` from universe/benchmark sync |
| **Derived analytics** | RS difference; normalized gain series; chart payloads |
| **External fetch on Explorer path** | **None** (cache-only) |
| **Service caches (SD-031)** | Stock analytics 6h; portfolio analytics ~15m; market engine daily snapshot |

---

## 5. Metric ownership matrix

| Metric | Owner / service | Data source | Primary consumers |
|--------|-----------------|-------------|-------------------|
| Period growth % | `StockPriceHistoryService::getGrowthPercentage` | OHLCV closes (adjusted ?? close), session-aware on-or-before | Explorer; RS math |
| Relative Strength (period) | `StockPriceHistoryService::getRelativeStrength` (+ `RelativeStrengthService` façade) | Stock growth − benchmark growth | Explorer; MetricsUpdate → `StockMetric`; Evaluation; Dashboard RS table |
| Primary benchmark stock | `IndexCatalogService` / `RelativeStrengthService::benchmarkStock` | Config primary (default NIFTY50) | Holdings RS, Dashboard, MetricsUpdate |
| Explorer selectable benchmark | `IndexCatalogService` + Explorer analyze | Any enabled index | Explorer only |
| Normalized gain series | `StockPriceHistoryService::getNormalizedGainSeries` | OHLCV session days | Explorer line chart |
| Stock Analytics (vol, beta, SMA, DD, …) | `StockAnalyticsService` | OHLCV + optional StockMetric RS | Watchlist / v1 stock API |
| Evaluation scores | Evaluation Engine via `EvaluationProfileService` | Engine + OHLCV/indicators | Watchlist research |
| Portfolio Analytics | `PortfolioAnalyticsService` | Live holdings calc + cash + metrics | Dashboard, v1 portfolio |
| Market Analytics | `MarketAnalyticsService` → Market Analysis Engine | Benchmark OHLCV + engine persistence | Dashboard gauges |
| Recommendation Preview | `RecommendationPreviewService` (**F137**) | Pipeline decide (no persist) | Watchlist research |
| Portfolio value / invested / XIRR | `PortfolioCalculationService` | Holdings + ledger | Dashboard / classic analytics — **not Explorer** |
| Equity curve history | **F015** snapshots | Snapshot table | Dashboard growth — **not Explorer** |
| Screener indicators | Screener `TechnicalIndicatorService` | OHLCV | Screeners — sibling indicator path |
| Indicator Registry | Metadata registry (Epic 1) | Catalogues | Docs/discovery — **not** Explorer runtime calc |

**Rule:** Analytics / Explorer are **calculation and research layers**, not source of financial truth (ledger, cash, holdings).

---

## 6. Relative Strength & growth formulas (CURRENT)

- **Growth %** = `(close_end − close_start) / close_start × 100`  
  - `close_start` / `close_end` = on-or-before target dates (`adjusted_close ?? close`).  
- **RS** = `stock_growth% − benchmark_growth%` (simple difference).  
- Explorer periods: **1, 3, 6, 12** months.  
- Persisted holdings metrics: **1m / 3m / 6m** vs **primary** benchmark only.  
- DQ: `RelativeStrengthService::calculateForStock` returns nulls when stock is DQ-blocked.

---

## 7. Filters / universe / profile

| Concern | CURRENT |
|---------|---------|
| Stock universe for Explorer | Local stock master validation; prices from universe cache |
| Benchmark filter | User-selected enabled index |
| Portfolio scope | Active portfolio used for **tracking flags** only; metrics are stock-level |
| Strategy data | Not used by Explorer analyze |
| Empty / error | 422 + user message if symbol invalid; incomplete cache yields null metrics / manual fallback |

---

## 8. APIs (CURRENT)

### Explorer / classic

| Method | Path | Role |
|--------|------|------|
| POST | `/api/analytics/explore` | Explorer analyze |
| GET | `/api/indexes` | Benchmark list for Explorer |
| GET | `/api/analytics/portfolio` | Classic live portfolio calc |
| GET | `/api/analytics/stocks/{stock}` | Classic holding + RS + xirr |

### SD-031 v1

| Method | Path | Owner payload |
|--------|------|---------------|
| GET | `/api/v1/analytics/portfolio` | Portfolio Analytics |
| GET | `/api/v1/analytics/market` | Market Analytics |
| GET | `/api/v1/analytics/dashboard` | Portfolio + Market bundle |
| GET | `/api/v1/analytics/stocks/{stock}` | Stock Analytics |
| GET | `/api/v1/analytics/stocks/{stock}/evaluation-profile` | Evaluation Profile |
| GET | `/api/v1/analytics/stocks/{stock}/recommendation-preview` | **F137** |
| GET | `/api/v1/analytics/stocks/{stock}/research` | Stock + Eval + Preview |

---

## 9. Frontend inventory

| Path | Role |
|------|------|
| `pages/StockExplorerPage.jsx` | Explorer SPA |
| `utils/explorerLinks.js` | Deep-link helpers (Screener/Watchlist) |
| Watchlist research panel | Consumes v1 research (not Explorer page) |
| Indices / Market Depth pages | Sibling market research (out of Explorer core UI) |

---

## 10. Current limitations

1. Explorer depends on universe/benchmark sync freshness — stale cache → null/incomplete RS.  
2. Help text may still imply period toggles / peer compare; CURRENT always runs four periods.  
3. Dual classic vs v1 analytics routes can confuse API consumers.  
4. Explorer RS can use non-primary benchmarks while Dashboard/StockMetric RS stay primary — intentional CURRENT split.  
5. Manual RS is client-only and not written to metrics tables.  
6. Indicator Registry not wired into Explorer calculations.

---

## 11. Explicit out of scope

- Changing RS/growth formulas  
- On-demand provider fetch from Explorer  
- Making Explorer own portfolio valuation  
- Reopening F042/F043/F137  
- Wiring Indicator Registry Epic 5 into Explorer (V3)  
- Redesigning Market Depth / Indices as part of this pack  

---

## 12. Test coverage summary

| Area | Coverage |
|------|----------|
| Explore growth/RS from cache | `ExplorerAnalyticsTest` |
| Selected index benchmark | Same |
| RS service construction / DQ | `RelativeStrengthServiceTest` (thin) |
| Growth/RS unit math | `StockPriceHistoryServiceTest` |
| Benchmark sync | `BenchmarkPriceSyncServiceTest` |
| Stock Analytics | `StockAnalyticsServiceTest` |
| F137 preview | `F137RecommendationPreviewTest` (sibling) |
| Manual RS UI | **TEST GAP** (frontend) |
| Classic vs v1 parity | **TEST GAP** |

See GAP matrix.
