# Dashboard — Retrospective CURRENT Specification

**Document:** V2.1 Retrospective CURRENT Spec  
**Location:** `docs/v2.1/DASHBOARD-SPEC.md`  
**Date:** 2026-08-10  
**Status:** CURRENT (runtime formalization — not a new feature)  
**Related:** Arch intent [`specs/architecture/portfolio/Dashboard-Specification.md`](../../specs/architecture/portfolio/Dashboard-Specification.md); F015 [`PORTFOLIO-SNAPSHOTS-SPEC.md`](./PORTFOLIO-SNAPSHOTS-SPEC.md); Cash [`CASH-MANAGEMENT-SPEC.md`](./CASH-MANAGEMENT-SPEC.md); WS-C [`WS-C-SHADOW-FEATURE-INVENTORY.md`](./WS-C-SHADOW-FEATURE-INVENTORY.md)

**No new F-number.** Do not reopen F014 / F015 / F137. Do not redesign Dashboard.

---

## 1. Purpose

Formalize the **CURRENT** home Dashboard (`/` + `GET /api/dashboard`) so presentation, data sources, and calculation ownership stay synchronized.

Dashboard answers: **How is my active portfolio and the market performing right now?**  
It is a **presentation and aggregation surface**, not the ledger SoT and not the owner of financial write semantics.

---

## 2. Current capability

| Area | Status |
|------|--------|
| Route `/` — `DashboardPage.jsx` | **IMPLEMENTED** |
| Aggregate API `GET /api/dashboard` | **IMPLEMENTED** |
| Live portfolio summary (value, invested, P&L, XIRR) | **IMPLEMENTED** (live calc) |
| Cash available card (available investable) | **IMPLEMENTED** (Cash Management summary) |
| Top gainer/loser (all-time / latest day) | **IMPLEMENTED** |
| Positions / diversification / avg RS cards | **IMPLEMENTED** (Portfolio Analytics) |
| Market Health + collapsible market gauges | **IMPLEMENTED** (Market Analysis Engine via Market Analytics) |
| Alerts table (acknowledge / clear all) | **IMPLEMENTED** (AlertService / F127 policies) |
| Upcoming calendar (next ~31 days) | **IMPLEMENTED** (separate Calendar API) |
| Pattern signals on holdings | **IMPLEMENTED** (separate Patterns scan API) |
| Relative Strength + Allocation tables/charts | **IMPLEMENTED** |
| Portfolio Growth + Unrealized P/L charts (365d) | **IMPLEMENTED** (F015 snapshots) |
| Manual rebuild history + View snapshots | **IMPLEMENTED** (triggers F015 APIs) |
| Admin Sync prices for today | **IMPLEMENTED** |
| Client cache (~24h, per user+profile) | **IMPLEMENTED** |
| Inline Stocks Above / market_depth heatmap on Dashboard | **NOT FOUND in CURRENT UI/API payload** — dedicated `/market-depth` page; gauge title links there |
| Active Strategy summary card on Dashboard | **NOT FOUND in CURRENT UI** (API may still return `strategy`) |
| Embedded F014 Historical Holdings | **NOT IMPLEMENTED** |
| Embedded F137 Recommendation Preview | **NOT IMPLEMENTED** |
| Embedded Explorer research | **NOT IMPLEMENTED** |
| Review Engine dashboard (`/review`) | **DISTINCT page** — not this pack’s UI |

---

## 3. User workflow (CURRENT)

### 3.1 Open Dashboard

1. Operator opens `/` with an authenticated session and active portfolio (`X-Profile-Id`).  
2. Client checks `dashboardCache` for `(userId, profileId)` within 24h TTL.  
3. **Cache hit:** render cached dashboard + pattern rows immediately; still loads calendar upcoming.  
4. **Cache miss / force refresh:** parallel:
   - `GET /api/dashboard`
   - `GET /api/patterns/scan?scope=holdings&actionable_only=true`
   - `GET /api/calendar/upcoming` (separate)

### 3.2 Summary cards

Rendered from dashboard payload (live services), with light **display** formatting only (INR whole, colour bands, cash %).

### 3.3 Portfolio growth

- Series from `portfolio_growth` (F015 snapshot rows, last 365).  
- Unrealized series = display derivative `portfolio_value − invested_value` per point.  
- Empty + transactions exist → server may **lazy-rebuild** snapshots once inside `DashboardController`.  
- Operator may **Rebuild history** → `POST /api/portfolio/rebuild-history` then refetch.

### 3.4 Analytics widgets

- Portfolio analytics cards (positions, diversification bar, avg RS) from `portfolio_analytics`.  
- Market Health + gauges from `market_analytics` (Market Analysis Engine).  
- Diagnostics panel collapsed by default (session preference).

### 3.5 Refresh behaviour

| Action | Effect |
|--------|--------|
| Refresh dashboard | Clear client cache; refetch dashboard + patterns + calendar |
| Portfolio / transaction mutation events | Invalidate cache (`notifyPortfolioDashboardRefresh` / portfolio changed) |
| Acknowledge / clear alerts, sync, rebuild | Clear cache + force refetch |
| Portfolio switch | Different cache key; reload |
| Logout | Clear all dashboard caches |

### 3.6 Empty states

| Widget | Empty behaviour |
|--------|-----------------|
| Alerts | Card body “No active alerts” (no table headers) |
| Patterns | Empty message + Patterns guide link |
| RS / Allocation | Empty messages |
| Growth | Empty chart until snapshots exist (lazy rebuild may fill) |
| Cash available | Card omitted if value invalid/NaN |

### 3.7 Multi-portfolio

All metrics scoped to `activePortfolio()`. Switching portfolios changes API header and cache key.

---

## 4. Data loading sequence (CURRENT)

```text
DashboardPage
  ├─ [optional] read localStorage dashboard cache (presentation only)
  ├─ GET /api/dashboard
  │     ├─ PortfolioCalculationService::calculateForProfile   → live holdings
  │     ├─ topMovers / dailyChange
  │     ├─ portfolioGrowthSeries (F015 rows; maybe lazy rebuild)
  │     ├─ CashManagementService::summary
  │     ├─ PortfolioAnalyticsService::forProfile (15m DB cache optional)
  │     ├─ MarketAnalyticsService::summary → MarketAnalysisEngine
  │     ├─ AlertService::getActiveForProfile
  │     ├─ RelativeStrengthService benchmark + StockMetric RS
  │     └─ admin: DailyMarketSyncService::status
  ├─ GET /api/patterns/scan (holdings, actionable)
  └─ GET /api/calendar/upcoming
```

**Sibling (not loaded by home Dashboard UI):** `GET /api/v1/analytics/dashboard` — portfolio+market analytics bundle only.

---

## 5. Metric ownership matrix (critical)

| Metric / widget | Calculation owner | Data source | Dashboard responsibility |
|-----------------|-------------------|-------------|--------------------------|
| Portfolio value | `PortfolioCalculationService` | Live `portfolio_holdings` × latest closes | Display |
| Invested value | Same | Holdings invested amounts | Display |
| Unrealized / realized / total G/L | Same (+ holdings realized) | Live holdings | Display (+ % brace formatting) |
| XIRR | `XirrService` via PortfolioCalculation | Ledger cashflows + live terminal value | Display |
| Cash available | **Cash Management** | `available_investable_cash` | Display + cash% of (cash+portfolio) colour band |
| Top movers | `PortfolioCalculationService::topMovers` | Live holdings + OHLCV day change | Display + localStorage period toggle |
| Daily change | PortfolioCalculation (today/yesterday **snapshots**) | F015 rows | Display if present |
| Positions count / oversized | Portfolio Analytics (+ holdings alloc) | Live holdings | Display + scroll link |
| Diversification score | Portfolio Analytics (HHI-based) | Live allocation % | Display via `PercentGradientBar` |
| Avg RS (3M) | Portfolio Analytics / StockMetric | Cached RS metrics | Display |
| Allocation table/donut | PortfolioCalculation holdings map | Live | Display (+ local prefs table/visual) |
| Relative Strength table | StockMetric via dashboard payload | Cached metrics vs NIFTY50 | Display |
| Portfolio growth / invested history | **F015** `PortfolioSnapshotRebuildService` | `portfolio_portfolio_snapshots` | Chart + trigger rebuild/view |
| Unrealized history line | Display derivative of F015 series | Snapshots | Chart only |
| Market sentiment / phase / gauges | **Market Analysis Engine** via MarketAnalyticsService | Engine persistence / compute | Display (no UI recalculation) |
| Alerts | **F127** AlertService / policies | `portfolio_alerts` | List + acknowledge/clear actions |
| Pattern signals | PatternScanService | Cached OHLCV | Table + links to Patterns guide |
| Calendar upcoming | Calendar services | Calendar events | Card + link to `/calendar` |
| Market depth heatmap | **MarketDepthService** (dedicated page) | Settings cache / depth snapshots | **Link only** on CURRENT Dashboard |
| Historical holdings as-of | **F014** | Ledger on demand | **Not on Dashboard** |
| Recommendation preview | **F137** | Preview service | **Not on Dashboard** |
| Explorer charts/RS research | Explorer / ExploratoryAnalytics | OHLCV | **Not on Dashboard** |
| Strategy card | StrategyConfigurationService | Strategy config | API may return; **UI not shown** |

**Rule:** Dashboard must not become the owner of financial calculations. Formatting, colour bands, and client cache are presentation concerns only.

---

## 6. Live vs snapshot vs cached

| Kind | Examples |
|------|----------|
| **Live calculation** | Portfolio value, invested, allocation, XIRR, top movers (all-time), cash summary |
| **Snapshot-derived** | `portfolio_growth`, unrealized history chart, `daily_change` |
| **Service/DB cached** | Portfolio analytics snapshot table (~15m); market analysis engine latest; RS in `portfolio_stock_metrics`; market depth warm cache (consumed on `/market-depth`) |
| **Client cached** | Entire dashboard+patterns payload ~24h (`dashboardCache.js`) |
| **External/provider** | Only indirectly via prior daily sync / OHLCV — Dashboard request path does not fetch brokers |

---

## 7. APIs (CURRENT)

| Method | Path | Role |
|--------|------|------|
| GET | `/api/dashboard` | Home aggregate payload |
| GET | `/api/patterns/scan` | Pattern signals (Dashboard companion call) |
| GET | `/api/calendar/upcoming` | Upcoming events card |
| POST | `/api/portfolio/rebuild-history` | Operator-triggered F015 rebuild |
| POST | `/api/sync/daily` | Admin price sync |
| POST | `/api/alerts/{id}/acknowledge`, `/api/alerts/expire-all` | Alert actions |
| GET | `/api/v1/analytics/dashboard` | Analytics-only bundle (not primary SPA home loader) |
| GET | `/api/market-depth` | Dedicated Market Depth page |

---

## 8. Frontend inventory

| Path | Role |
|------|------|
| `pages/DashboardPage.jsx` | Home composition |
| `components/DashboardTopMoverCard.jsx` | Gainer/loser |
| `components/DashboardAllocationCard.jsx` | Allocation table/visual |
| `components/calendar/CalendarDayEventsDialog.jsx` (`DashboardCalendarCard`) | Upcoming events |
| `utils/dashboardCache.js` | Client cache |
| `utils/dashboardPrefs.js` | Allocation view prefs |
| `tests/js/dashboardCache.test.mjs` | Cache unit tests |

Distinct: `ReviewDashboardPage.jsx` → `/review` (Trading OS review), not home Dashboard.

---

## 9. Profile / AuthZ

- Requires auth; data via `activePortfolio()`.  
- Admin-only: sync status + Sync prices button.  
- Alert acknowledge/clear scoped to own profile alerts.

---

## 10. Current limitations

1. Help still describes an on-Dashboard **Stocks Above heatmap**; CURRENT UI only links to `/market-depth`.  
2. Some `implementation.md` historical notes still mention `market_depth` on `GET /api/dashboard`; **CURRENT controller does not attach it**.  
3. Thin dedicated PHPUnit coverage (growth + top movers).  
4. Client 24h cache can show stale numbers until refresh/invalidation.  
5. `strategy` may be computed server-side but unused in CURRENT UI.  
6. Sector allocation in Portfolio Analytics remains empty placeholder (`sector_allocation: []`).

---

## 11. Explicit out of scope

- Redesigning Dashboard layout or adding widgets  
- Making Dashboard own ledger/cash/snapshot formulas  
- Absorbing F014 / F015 / F137 / Explorer into Dashboard  
- Starting V3 analytics expansions  
- Review dashboard redesign  

---

## 12. Test coverage summary

| Area | Coverage |
|------|----------|
| Growth lazy rebuild | `DashboardGrowthTest` |
| Top movers payload | `DashboardTopMoversTest` |
| Client cache helpers | `dashboardCache.test.mjs` |
| Market analytics / depth / cash / alerts | Covered in **sibling** suites, not a dedicated Dashboard composition suite |
| Full widget empty-state matrix | **TEST GAP** |
| Cache invalidation E2E | **TEST GAP** (partial unit) |

See GAP matrix.
