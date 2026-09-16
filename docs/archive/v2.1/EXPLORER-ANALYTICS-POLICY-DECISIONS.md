# Explorer / Analytics — Policy Decisions (Retrospective)

**Document:** V2.1 Retrospective POLICY register  
**Date:** 2026-08-10  
**Status:** CURRENT formalization  
**Companions:** SPEC, BOUNDARY, GAP  

Status: **DECIDED** | **CURRENT** | **DECISION_REQUIRED** | **DEFERRED** | **OOS** | **TECHNICAL DEBT**

Do not invent policies. Reference SD-031 / F137 / WS-A where already established.

---

## Register

| ID | Topic | Status | Decision / CURRENT behaviour | Evidence |
|----|-------|--------|------------------------------|----------|
| **EA-01** | Single-owner analytics categories | **CURRENT** / arch DECIDED | Stock / Evaluation / Portfolio / Market each have one owner | Analytics-Architecture-Specification |
| **EA-02** | RS formula | **CURRENT** | `stock_growth% − benchmark_growth%` | `StockPriceHistoryService` |
| **EA-03** | Growth formula | **CURRENT** | Close on-or-before window ends; adjusted ?? close | Same |
| **EA-04** | Explorer cache-only | **CURRENT** | No provider fetch on explore; universe cache status only | `getCachedAnalyticsHistoryStatus` |
| **EA-05** | Explorer periods | **CURRENT** | Always 1/3/6/12 months; no UI period toggle | Controller + page |
| **EA-06** | Explorer benchmark | **CURRENT** | Any enabled IndexCatalog symbol; fallback primary | `resolveBenchmark` |
| **EA-07** | Holdings / Dashboard RS benchmark | **CURRENT** | Primary index only (default NIFTY50) | `RelativeStrengthService::benchmarkStock` |
| **EA-08** | Metric ownership | **CURRENT** | Pages consume owners; do not recompute market/eval scores in UI | Arch §3 + runtime |
| **EA-09** | Data freshness | **CURRENT** | Explorer as-of = today; depends on last sync | Explore payload `as_of` |
| **EA-10** | Stock analytics cache TTL | **CURRENT** | ~6 hours | `StockAnalyticsService` |
| **EA-11** | Portfolio analytics cache TTL | **CURRENT** | ~15 minutes | `PortfolioAnalyticsService` |
| **EA-12** | F137 ownership | **DECIDED** (F137) | Preview is F137; analytics route is transport | F137 BOUNDARY |
| **EA-13** | Indicator framework / Registry | **DEFERRED** / Epic | Registry metadata exists; not Explorer calc path | Indicator Registry notes |
| **EA-14** | Strategy↔Evaluation wiring (TD-19) | **DEFERRED** / **V3** | Known disconnect; do not fix in V2.1 docs pack | WS-C B6 |
| **EA-15** | Classic vs v1 analytics APIs | **TECHNICAL DEBT** | Parallel `/api/analytics/*` and `/api/v1/analytics/*` | routes/api.php |
| **EA-16** | Help drift (period toggles / peers) | **TECHNICAL DEBT** / doc | Help summary incomplete vs CURRENT | `appDocumentation.js` |
| **EA-17** | Manual RS persistence | **CURRENT** / **OOS** to persist | Client-only; not written to StockMetric | Explorer page |
| **EA-18** | Analytics as financial SoT | **OOS** | Ledger/cash/holdings remain SoT | BOUNDARY |

---

## Canonical statements

**Explorer:** Universe-cache stock research vs a chosen benchmark. Not portfolio NAV. Not a write path.

**RS consistency:** Same primitive math; **benchmark selection differs** by consumer (Explorer flexible vs primary-only for persisted metrics/Dashboard).

**SD-031:** Do not invent a fifth analytics category for Explorer — Explorer is a **page** that uses StockPriceHistory math, not a separate ownership silo.

---

## Open items

| ID | Ask | Notes |
|----|-----|-------|
| EA-15 | Deprecate classic analytics routes? | No decision required to document CURRENT |
| EA-16 | Sync help with always-four-periods UX | Future help sync (not this pass’s app edit) |

No blocking `DECISION_REQUIRED` for CURRENT documentation.

---

## Out of scope

- Formula changes  
- On-demand Explorer fetch  
- Indicator Registry Epic 5  
- Fixing TD-19  
- Reopening F137 / F042 / F043  

---

*End of policy register.*
