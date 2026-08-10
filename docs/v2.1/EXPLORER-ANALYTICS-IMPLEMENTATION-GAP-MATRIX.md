# Explorer / Analytics — Implementation Gap Matrix

**Document:** V2.1 Retrospective GAP matrix  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companions:** SPEC, BOUNDARY, POLICY  

Statuses: **IMPLEMENTED** | **PARTIAL** | **MISSING** | **TEST GAP** | **DOCUMENTATION GAP** | **TECHNICAL DEBT** | **DEFERRED** | **OOS**

---

## Matrix

| Behaviour / requirement | Current implementation | Status | Evidence | Tests | Risk | Recommended future action |
|-------------------------|------------------------|--------|----------|-------|------|---------------------------|
| Explorer UI + explore API | StockExplorerPage + ExploratoryAnalyticsService | **IMPLEMENTED** | Routes + service | `ExplorerAnalyticsTest` | Low | None |
| Cache-only explore | getCachedAnalyticsHistoryStatus | **IMPLEMENTED** | Service | Feature tests | Low | Keep contract |
| Multi-index benchmark | IndexCatalog resolve | **IMPLEMENTED** | Service | ExplorerAnalyticsTest | Low | None |
| Growth / RS primitives | StockPriceHistoryService | **IMPLEMENTED** | Service | Unit + Explorer | Low | None |
| Manual RS fallback | Client-side 6M | **IMPLEMENTED** | Page | **TEST GAP** | Low | Optional FE test |
| Stock Analytics owner | StockAnalyticsService + cache | **IMPLEMENTED** | SD-031 | StockAnalyticsServiceTest | Low | None |
| Portfolio / Market analytics | Portfolio + Market services | **IMPLEMENTED** | Dashboard + v1 | Sibling suites | Low | None |
| Evaluation Profile API | EvaluationProfileService | **IMPLEMENTED** | v1 route | Engine tests | Low | None |
| F137 preview under analytics routes | RecommendationPreviewService | **IMPLEMENTED** | F137 pack | F137 tests | Low | Do not reopen |
| Formal V2.1 Explorer pack | This pack | **DOCUMENTATION GAP** → **addressed** | WS-C B5 | — | — | Maintain |
| Help accuracy (periods/peers) | Help claims period toggles | **DOCUMENTATION GAP** | appDocumentation | — | Low confusion | Future help sync |
| Classic vs v1 dual APIs | Both registered | **TECHNICAL DEBT** | api.php | **TEST GAP** parity | Med for API consumers | Document; optional deprecate later |
| Explorer vs primary-benchmark RS | Split intentional | **CURRENT** | SPEC EA-06/07 | Covered | Low | Keep documented |
| Indicator Registry → Explorer | Not wired | **DEFERRED** | Registry Epic | — | — | V3 |
| TD-19 Strategy/Evaluation | Known disconnect | **DEFERRED** / V3 | WS-C | — | — | Do not fix here |
| On-demand provider from Explorer | Explicitly avoided | **OOS** to add | Explore path | — | — | Preserve cache-only |
| Formula redesign | — | **OOS** | — | — | — | — |
| Market Depth / Indices formal pack | Sibling surfaces | **DEFERRED** optional | Separate pages | — | — | Later WS-C if needed |

---

## Findings (read-only)

| ID | Finding | Severity | Action in this pass |
|----|---------|----------|---------------------|
| **F-EA-1** | Help implies period toggles / peer compare; CURRENT always analyzes 1/3/6/12 with one symbol | Doc drift | Documented; no help file edit (pack + index only) |
| **F-EA-2** | Parallel classic and v1 analytics endpoints | TD | Documented |
| **F-EA-3** | Explorer multi-benchmark RS ≠ persisted StockMetric primary RS | Operator clarity | Documented as CURRENT intentional |

**No serious financial defect.** Analytics does not mutate ledger/cash. WS-A already stabilized Explorer/RS fixture drift.

---

## Gap priority (docs/tests only)

| Priority | Item | Class |
|----------|------|-------|
| P1 | Maintain this pack when RS/Explorer contracts change | DOCUMENTATION |
| P2 | Sync Explorer help topic with CURRENT UX | DOCUMENTATION GAP |
| P2 | Manual RS / classic-vs-v1 tests | TEST GAP |
| — | Indicator Registry cutover / TD-19 | DEFERRED V3 |

---

## Confirmation

- Reflects **CURRENT** runtime as of 2026-08-10.  
- No application/test/schema/frontend changes.  
- No V3; F014/F015/F137/F042/F043 not reopened.  

---

*End of gap matrix.*
