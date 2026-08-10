# Dashboard — Policy Decisions (Retrospective)

**Document:** V2.1 Retrospective POLICY register  
**Date:** 2026-08-10  
**Status:** CURRENT formalization  
**Companions:** SPEC, BOUNDARY, GAP  

Status values: **DECIDED** | **CURRENT** | **DECISION_REQUIRED** | **DEFERRED** | **OOS** | **TECHNICAL DEBT**

Do not invent policies. Prefer references to Cash / F014 / F015 packs where already decided.

---

## Register

| ID | Topic | Status | Decision / CURRENT behaviour | Evidence |
|----|-------|--------|------------------------------|----------|
| **DB-01** | Dashboard role | **CURRENT** | Presentation + aggregation home; not ledger SoT | Arch SPEC + runtime |
| **DB-02** | Live headline metrics SoT | **CURRENT** | Live `PortfolioCalculationService` (holdings + quotes) | `DashboardController` |
| **DB-03** | Growth chart SoT | **CURRENT** / F015 | Materialized snapshots; Dashboard charts only | F015 pack; `portfolio_growth` |
| **DB-04** | Metric ownership | **CURRENT** | Services own formulas; Dashboard displays | Ownership matrix in SPEC |
| **DB-05** | Cash on Dashboard | **CURRENT** | Show available investable only; full cash UX on `/cash` | UI + Cash BOUNDARY |
| **DB-06** | Client cache | **CURRENT** | ~24h per user+profile; invalidate on mutation/switch/logout | `dashboardCache.js` |
| **DB-07** | Portfolio switching | **CURRENT** | Active portfolio header scopes all metrics + cache key | Middleware + cache |
| **DB-08** | Empty alerts UX | **CURRENT** | No table headers when empty | DashboardPage |
| **DB-09** | Market depth on home | **CURRENT** | Link to `/market-depth`; no inline heatmap / no `market_depth` on dashboard API | Controller + page inventory |
| **DB-10** | Market gauges | **CURRENT** | Consume Market Analysis Engine payload; no UI recalculation | MarketAnalyticsService |
| **DB-11** | F014 on Dashboard | **OOS** | Dedicated Historical Holdings page | F014 BOUNDARY |
| **DB-12** | F137 on Dashboard | **OOS** | Preview stays on Recommendations / TOS | F137 / Cash BOUNDARY |
| **DB-13** | Explorer on Dashboard | **OOS** | Sibling research page | WS-C inventory |
| **DB-14** | Personalization | **CURRENT** | localStorage: top-mover period, allocation view/metric, diagnostics collapse | Prefs + page |
| **DB-15** | Performance | **CURRENT** / **TECHNICAL DEBT** | Client cache + analytics 15m cache; depth kept off request path | Cache + MarketDepth design |
| **DB-16** | Help / implementation drift (heatmap) | **TECHNICAL DEBT** / **DOCUMENTATION** | Help + some notes still imply on-Dashboard Stocks Above | Finding F-DB-1 |
| **DB-17** | Unused `strategy` payload | **TECHNICAL DEBT** | API may return strategy; UI card removed | Controller vs page |
| **DB-18** | Sector allocation | **DEFERRED** / placeholder | `sector_allocation: []` | PortfolioAnalyticsService |
| **DB-19** | Lazy snapshot rebuild on GET dashboard | **CURRENT** | One rebuild if growth empty but txs exist | `portfolioGrowthSeries` |

---

## Canonical ownership statement

**Status: CURRENT (aligned with F015 BOUNDARY)**

1. Dashboard **orchestrates and presents**.  
2. **Live** portfolio value / invested / XIRR / allocation → `PortfolioCalculationService`.  
3. **Historical growth** → F015 snapshots.  
4. **Cash available** → Cash Management.  
5. **Market health gauges** → Market Analysis Engine.  
6. Dashboard must **not** silently become SoT for any of the above.

---

## Open items

| ID | Ask | Notes |
|----|-----|-------|
| DB-16 | Sync help + stale implementation notes with CURRENT (link-only depth) | Documentation polish — not a product redesign |
| DB-17 | Drop or re-surface strategy card? | No decision required to document CURRENT; UI absent |

No blocking `DECISION_REQUIRED` for describing CURRENT behaviour.

---

## Out of scope

- New widgets / analytics  
- Absorbing F014/F015/F137  
- Hardening Market Depth into Dashboard again without a new decision  
- V3 personalization platform  

---

*End of policy register.*
