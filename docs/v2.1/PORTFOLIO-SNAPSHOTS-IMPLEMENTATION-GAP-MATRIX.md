# Portfolio Snapshots (F015) — Implementation Gap Matrix

**Document:** V2.1 Retrospective GAP matrix  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companions:** SPEC, BOUNDARY, POLICY  

Statuses: **IMPLEMENTED** | **PARTIAL** | **MISSING** | **TEST GAP** | **DOCUMENTATION GAP** | **TECHNICAL DEBT** | **DEFERRED** | **OOS**

---

## Matrix

| Behaviour / requirement | Current implementation | Status | Evidence | Tests | Risk | Recommended future action |
|-------------------------|------------------------|--------|----------|-------|------|---------------------------|
| Materialized daily portfolio/invested | `portfolio_portfolio_snapshots` | **IMPLEMENTED** | Model + migration | Rebuild/API tests | Low | None |
| Rebuild from ledger + prices | `PortfolioSnapshotRebuildService` | **IMPLEMENTED** | Service | `PortfolioSnapshotRebuildTest` | Low | None |
| Post-commit after tx CRUD | `TransactionWriteService` | **IMPLEMENTED** | Soft-fail create/update | Indirect | Low | Document asymmetry PS-17 |
| Manual rebuild API/UI | Controller + Snapshots/Dashboard | **IMPLEMENTED** | Routes + pages | Rebuild API tests | Low | None |
| Daily cron today refresh | `storeSnapshot` in DailyMarketDataJob | **IMPLEMENTED** | Job | Job mocks elsewhere | Low | Optional dedicated assert |
| Dashboard growth 365d | `portfolioGrowthSeries` | **IMPLEMENTED** | DashboardController | `DashboardGrowthTest` | Low | None |
| Lazy rebuild when empty | Same | **IMPLEMENTED** | Controller | DashboardGrowthTest | Low | None |
| Profile-scoped GET snapshots | History controller | **IMPLEMENTED** | `activePortfolio()` | `PortfolioSnapshotApiTest` | Low | Optional cross-user explicit |
| Weekend exclusion | TradingCalendar + purge | **IMPLEMENTED** | Service | Rebuild weekend tests | Low | None |
| F014 boundary clarity | F014 pack + this pack | **IMPLEMENTED** (docs) | F014-BOUNDARY; this BOUNDARY | — | Low | Keep both packs in sync on behaviour change |
| Formal F015 V2.1 pack | This pack | **DOCUMENTATION GAP** → **addressed** | WS-C B2 | — | — | Maintain with behaviour changes |
| Help topic portfolio-snapshots | `appDocumentation.js` | **IMPLEMENTED** | Help | — | Low | Keep synced |
| Persist per-holding snapshot detail | Computed then discarded | **CURRENT** / **OOS** to add | `persistSnapshot` | — | — | Use F014 for drilldown |
| Cash in valuation history | Absent | **OOS** | SPEC | — | — | Do not add via F015 silently |
| Missing-price silent-zero | F015 zeros; F014 differs | **CURRENT** | SPEC PS-04/05 | Rebuild warnings counted | Medium (operator surprise) | Document only unless PO changes |
| Soft-fail snapshot after financial commit | Default true | **IMPLEMENTED** | Write service | **TEST GAP** explicit soft-fail | Low | Add regression if hardening |
| PHPUnit skip create/update rebuild | Guard on create/update | **TECHNICAL DEBT** | Write service | — | Low (tests) | Harmonize delete vs create for tests |
| Bulk import N rebuilds | Per-row post-commit | **TECHNICAL DEBT** | Bulk import | — | Perf | Optional single rebuild after batch |
| Snapshot retention/archival | None | **DEFERRED** / **MISSING** policy | — | — | Low storage | Decide only if needed |
| Snapshot export | None | **OOS** / **MISSING** | — | — | — | Backlog if requested |
| API inventory in V2.1 pack | This SPEC §9 | **DOCUMENTATION GAP** → **addressed** | Was only in implementation.md | — | — | Keep SPEC synced |
| Dashboard owns calculations | Dashboard consumes only | **IMPLEMENTED** boundary | BOUNDARY §8 | — | Low | Future Dashboard pack should reaffirm |
| Merge F014/F015 | Forbidden | **OOS** | F014 | — | — | Do not implement |

---

## Findings (read-only)

| ID | Finding | Severity | Action in this pass |
|----|---------|----------|---------------------|
| F-PS-1 | F015 silent-zero vs F014 null/incomplete for missing prices | Operator clarity / known contrast | Documented (PS-04/05); **not** a new defect to fix here |
| F-PS-2 | Create/update skip snapshot rebuild in PHPUnit; delete does not | Test asymmetry / TD | Documented (PS-17); no code change |
| F-PS-3 | Bulk import may trigger multiple full-range rebuilds | Performance TD | Documented (PS-18); no code change |

**No serious financial correctness defect discovered that requires STOP-and-fix.** Snapshot soft-fail and rebuildable-cache semantics are intentional CURRENT behaviour post ledger/cash commit.

---

## Gap priority (docs/tests only — do not implement features here)

| Priority | Item | Class |
|----------|------|-------|
| P1 | Maintain this pack when rebuild/valuation behaviour changes | DOCUMENTATION |
| P2 | Explicit soft-fail regression test | TEST GAP |
| P2 | Optional cross-user snapshot isolation test | TEST GAP |
| P3 | End-of-batch single rebuild for F019 | TECHNICAL DEBT |
| — | Cash-as-of / holdings JSON / merge F014 | OOS |

---

## Confirmation

- Matrix reflects **CURRENT** runtime as of 2026-08-10.  
- Does not reopen F014/F015 initiatives or start V3.  
- No application changes in this documentation pass.  

---

*End of gap matrix.*
