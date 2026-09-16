# Explorer / Analytics — Boundary

**Document:** V2.1 Retrospective BOUNDARY  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companion:** [`EXPLORER-ANALYTICS-SPEC.md`](./EXPLORER-ANALYTICS-SPEC.md)

---

## 1. What Explorer / Analytics owns

| Owns | Notes |
|------|-------|
| Explorer research UI + explore API orchestration | `/explorer`, `ExploratoryAnalyticsService` |
| Shared growth / RS math primitives | `StockPriceHistoryService` (+ RS façade) |
| SD-031 category owners | Stock / Portfolio / Market / Evaluation Profile services |
| Stock analytics cache table writes | `portfolio_stock_analytics_cache` |
| Portfolio analytics snapshot cache | `portfolio_analytics_snapshots` (category portfolio) |
| Explorer cache-only contract | No provider fetch on explore path |

---

## 2. What Explorer / Analytics does **not** own

| Does not own | Owner |
|--------------|-------|
| Transaction ledger / holdings qty / cash | Ledger / Cash Management / `TransactionWriteService` |
| Portfolio equity-curve snapshots | **F015** |
| Live Dashboard composition | Dashboard (consumes analytics) |
| Recommendation generate / execute / preview contract | TOS / **F137** (preview service lives under Analytics namespace but product owner is F137) |
| Strategy definition / scoring rules | Strategy configuration + Recommendation engine |
| Screener condition evaluation | Screener services |
| OHLCV ingest / gap fill / CA price repair | Universe sync / F042 / F043 |
| Market depth matrix compute | `MarketDepthService` |
| User Calendar events | User Calendar |
| Indicator Registry consumer cutover | Deferred / V3 |

---

## 3. Dashboard

- Dashboard **consumes** Portfolio Analytics, Market Analytics, and cached RS metrics.  
- Dashboard does **not** own Explorer formulas.  
- See [`DASHBOARD-BOUNDARY.md`](./DASHBOARD-BOUNDARY.md).

---

## 4. Portfolio Snapshots (F015)

- F015 owns daily portfolio_value / invested_value history.  
- Explorer growth is **per-stock** return vs benchmark — not portfolio NAV.  
- Do not use Explorer to answer “what was my portfolio worth on D?”

---

## 5. F137 Recommendation Preview

- Preview endpoint is under `/api/v1/analytics/...` for routing convenience.  
- **Product ownership remains F137** — do not reopen F137 in this pack.  
- Explorer does not call preview; Watchlist research does.

---

## 6. Strategy / Evaluation

- Evaluation Profile is SD-031 category owned by Evaluation Engine façade.  
- Strategy configuration is separate; TD-19 (params ≠ evaluation runtime) is **V3** — document only, do not fix here.  
- Explorer does not score strategies.

---

## 7. Screeners

- Screeners own indicator evaluation for eligibility.  
- May deep-link to Explorer for research.  
- Do not merge Screener indicator engine into Explorer.

---

## 8. Data Quality (F042) / Corporate Action prices (F043)

- DQ guard blocks RS persistence path for unresolved stocks.  
- Explorer reads repaired OHLCV when present (`adjusted_close ?? close`).  
- Do not reopen F042/F043; session-aware closes (WS-A) are intentional.

---

## 9. Universe / Benchmark Sync

- Explorer **depends** on universe + benchmark price sync for cache completeness.  
- Sync ownership stays with universe/benchmark jobs — Explorer only reports cache status.

---

## 10. Deliberately not owned

- Financial mutations  
- On-demand provider backfill from Explorer  
- Replacing F015 growth charts  
- Becoming SoT for holdings RS vs Explorer multi-benchmark (both CURRENT; different consumers)  

---

*End of Explorer / Analytics boundary.*
