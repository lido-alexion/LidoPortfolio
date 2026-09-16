# Dashboard — Boundary

**Document:** V2.1 Retrospective BOUNDARY  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companion:** [`DASHBOARD-SPEC.md`](./DASHBOARD-SPEC.md)

---

## 1. What Dashboard owns

| Owns | Notes |
|------|-------|
| Home SPA composition (`/`) | Layout, cards, charts, tables |
| Aggregation request `GET /api/dashboard` | Orchestrates service calls; does not redefine formulas |
| Client presentation cache | `dashboardCache.js` — UX only |
| Display formatting | INR whole, colour bands, cash%, gain braces |
| Operator triggers from home | Refresh, rebuild history (delegates to F015), sync (admin), acknowledge alerts |
| Navigation affordances | Links to Snapshots, Market Depth, Patterns, Calendar, Allocation anchor |

---

## 2. What Dashboard does **not** own

| Does not own | Owner |
|--------------|-------|
| Transaction ledger CRUD / bulk | Transactions / F019 / `TransactionWriteService` |
| Live holdings maintenance | `HoldingsCalculationService` |
| Cash balance / reserve / withdraw semantics | **Cash Management** |
| Snapshot rebuild formulas / table | **F015** |
| Per-stock as-of holdings | **F014** |
| Recommendation preview / generation | TOS / **F137** / Recommendation engine |
| Alert policy definitions / evaluation | **F127** |
| Market Analysis score math | Market Analysis Engine (SD-032) |
| Market depth matrix compute | `MarketDepthService` + `/market-depth` |
| Explorer research analytics | Explorer / `ExploratoryAnalyticsService` |
| Pattern detection rules | Pattern detection services |
| Calendar recurrence / reminders | Calendar subsystem |
| Review outcomes dashboard | `/review` Trading OS Review |

---

## 3. F014 Historical Holdings

- F014 is a **dedicated as-of page/API**.  
- Dashboard does **not** embed F014 and must not treat snapshot or live holdings as F014 SoT.  
- Help may cross-link; no merge.

---

## 4. F015 Portfolio Snapshots

- Dashboard **consumes** `portfolio_growth` and may **trigger** lazy/manual rebuild.  
- Valuation math and persistence remain F015 / `PortfolioSnapshotRebuildService`.  
- See [`PORTFOLIO-SNAPSHOTS-BOUNDARY.md`](./PORTFOLIO-SNAPSHOTS-BOUNDARY.md) § Dashboard.

---

## 5. Cash Management

- Dashboard shows **Cash available** from Cash Management summary.  
- Deposit/withdraw/adjust/reservation UI lives on `/cash`.  
- Dashboard must not invent cash ledgers or reservation rules.

---

## 6. F137 Recommendation Preview

- Preview is a Recommendations / TOS concern.  
- **Not** rendered on home Dashboard.  
- Do not reopen F137 in this pack.

---

## 7. Explorer

- Stock Explorer is a sibling research surface.  
- Dashboard may show **portfolio-level** RS averages from cached metrics; it does not run Explorer scans.

---

## 8. F127 Alerts

- Dashboard lists active alerts and supports acknowledge/clear.  
- Policy authoring and evaluation ownership remain F127 / Settings.

---

## 9. Transactions / ledger

- Ledger is SoT for history and reconstruction.  
- Dashboard live value uses **current holdings derived from ledger**, not ad-hoc client math.  
- Mutations elsewhere invalidate client dashboard cache.

---

## 10. Related but distinct “dashboards”

| Surface | Relationship |
|---------|----------------|
| `/review` Review dashboard | Trading OS outcomes — separate pack later if needed |
| `/api/v1/analytics/dashboard` | Analytics bundle API — not the SPA home loader |
| `/api/v1/data-quality/dashboard` | Admin DQ — out of scope |
| Arch `Dashboard-Specification.md` | Intent document; THIS pack is CURRENT runtime |

---

## 11. Deliberately not owned

- Redesign of Market Depth into home heatmap (historical; not CURRENT)  
- Sector master allocation fill-in  
- Making Dashboard a write path for cash/ledger  
- Personalization beyond existing localStorage prefs  

---

*End of Dashboard boundary.*
