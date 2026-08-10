# Dashboard — Implementation Gap Matrix

**Document:** V2.1 Retrospective GAP matrix  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companions:** SPEC, BOUNDARY, POLICY  

Statuses: **IMPLEMENTED** | **PARTIAL** | **MISSING** | **TEST GAP** | **DOCUMENTATION GAP** | **TECHNICAL DEBT** | **DEFERRED** | **OOS**

---

## Matrix

| Behaviour / requirement | Current implementation | Status | Evidence | Tests | Risk | Recommended future action |
|-------------------------|------------------------|--------|----------|-------|------|---------------------------|
| Home Dashboard SPA | `DashboardPage` `/` | **IMPLEMENTED** | Routes | Manual / thin API | Low | None |
| Aggregate API | `DashboardController@index` | **IMPLEMENTED** | `/api/dashboard` | Growth + top movers | Low | Broader composition tests optional |
| Live summary metrics | PortfolioCalculationService | **IMPLEMENTED** | Controller | Indirect | Low | Keep ownership |
| Cash available card | CashManagementService summary | **IMPLEMENTED** | UI | Cash suites | Low | None |
| Growth charts from F015 | `portfolio_growth` | **IMPLEMENTED** | Controller + charts | `DashboardGrowthTest` | Low | None |
| Lazy snapshot rebuild | When growth empty | **IMPLEMENTED** | Controller | DashboardGrowthTest | Low | None |
| Top movers toggle | API + localStorage | **IMPLEMENTED** | Card | `DashboardTopMoversTest` | Low | None |
| Portfolio analytics cards | PortfolioAnalyticsService | **IMPLEMENTED** | UI | Sibling analytics | Low | Optional Dashboard assert |
| Market gauges | MarketAnalyticsService | **IMPLEMENTED** | UI | Market analysis suites | Low | None |
| Alerts list/actions | AlertService | **IMPLEMENTED** | UI | Alert suites | Low | None |
| Calendar upcoming card | Calendar API | **IMPLEMENTED** | Separate fetch | Calendar tests | Low | None |
| Pattern signals | Patterns scan API | **IMPLEMENTED** | Separate fetch | Pattern suites | Low | None |
| Client 24h cache | `dashboardCache.js` | **IMPLEMENTED** | Utils | `dashboardCache.test.mjs` | Med (staleness) | Keep invalidation paths documented |
| Inline market_depth heatmap on home | **Removed / not present** | **NOT FOUND** (CURRENT) | No controller field; no MarketDepthTable on page | — | — | Do not treat as defect; fix help drift |
| Help “Stocks Above heatmap” on Dashboard | Help still claims control | **DOCUMENTATION GAP** | `appDocumentation.js` | — | Low confusion | Sync help in a future docs pass (not this pack’s app edit — note only) |
| `implementation.md` says market_depth on dashboard API | Stale vs controller | **DOCUMENTATION GAP** | implementation history vs code | — | Low | Superseded by this pack + future trim |
| Formal V2.1 Dashboard pack | This pack | **DOCUMENTATION GAP** → **addressed** | WS-C B3 | — | — | Maintain with behaviour changes |
| Metric ownership clarity | SPEC matrix | **IMPLEMENTED** (docs) | SPEC §5 | — | Low | Reaffirm in future changes |
| F014/F015/F137 boundaries | BOUNDARY | **IMPLEMENTED** (docs) | BOUNDARY | — | Low | Do not reopen |
| Unused strategy API field | Returned, unused in UI | **TECHNICAL DEBT** | Controller | — | Low | Remove or restore card later |
| Sector allocation | Empty array | **DEFERRED** | PortfolioAnalyticsService | — | Low | Sector master later |
| Full Dashboard E2E / empty states | Thin | **TEST GAP** | — | Med maintainability | Add composition feature tests if regressing |
| Cache invalidation E2E | Partial unit | **TEST GAP** | — | Low | Optional |
| Review dashboard formalization | Separate page | **DEFERRED** pack | `/review` | — | — | Later WS-C item if prioritized |
| Explorer CURRENT pack | Sibling | **DEFERRED** / optional | WS-C B5 | — | — | Separate note or pack |
| Redesign / new widgets | — | **OOS** | — | — | — | V3 / backlog |

---

## Findings (read-only)

| ID | Finding | Severity | Action in this pass |
|----|---------|----------|---------------------|
| **F-DB-1** | Help + some historical notes describe on-Dashboard Stocks Above / `market_depth` on `GET /api/dashboard`; CURRENT code has **neither** — only a link to `/market-depth` | Doc drift | Documented; **not fixed** (no app/help edits in this pass per scope: pack + DOCS/implementation index only) |
| **F-DB-2** | API may still compute `strategy` while UI card is gone | Low TD | Documented |
| **F-DB-3** | Conflicting historical notes on whether Cash available appears on Dashboard; CURRENT UI **does** show Cash available | Doc ambiguity | CURRENT pack clarifies |

**No serious financial correctness defect found.** Dashboard remains a consumer of Cash / live calc / F015 owners.

---

## Gap priority (docs/tests only)

| Priority | Item | Class |
|----------|------|-------|
| P1 | Align help “Stocks Above” with link-only CURRENT (future help sync session) | DOCUMENTATION GAP |
| P2 | Broader Dashboard API composition tests | TEST GAP |
| P3 | Drop unused `strategy` from payload or restore card | TECHNICAL DEBT |
| — | Explorer / Review / Calendar dedicated packs | DEFERRED sibling WS-C |

---

## Confirmation

- Reflects **CURRENT** runtime as of 2026-08-10.  
- Does not reopen F014/F015/F137 or start V3.  
- No application/test/schema/frontend changes in this documentation pass.  

---

*End of gap matrix.*
