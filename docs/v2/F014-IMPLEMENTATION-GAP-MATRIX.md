# F014 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** Policies **closed** — **`READY_FOR_IMPLEMENTATION`**. Product surface **not shipped**; gaps remain **MISSING** / **PARTIAL** until coded.  
**Initiative:** F014 Historical Holdings Reconstruction  
**Related:** [F014-HISTORICAL-HOLDINGS-SPEC.md](./F014-HISTORICAL-HOLDINGS-SPEC.md), [F014-BOUNDARY.md](./F014-BOUNDARY.md), [F014-POLICY-DECISIONS.md](./F014-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| Formal V2 pack | Present |
| Product policies | **Closed** (2026-08-09) |
| Reconstruction engine | **PARTIAL** vs DECIDED (warnings missing) |
| As-of holdings API | **MISSING** |
| As-of holdings UI | **MISSING** |
| Help / F014 tests | **MISSING** / thin |
| F019 prerequisite | **COMPLETE** |

### Classification legend

| Label | Meaning |
|-------|---------|
| `NO_GAP` | CURRENT matches DECIDED |
| `IMPLEMENTED` | Behaviour exists and matches DECIDED |
| `PARTIAL` | Exists but incomplete vs DECIDED |
| `MISSING` | DECIDED target not implemented |
| `DEFERRED` | Explicitly postponed |
| `OUT_OF_SCOPE` | Explicitly excluded |

**Note:** Closing a policy does **not** mark implementation gaps DONE.

---

## Gap register

| ID | Area | Topic | CURRENT | DECIDED target | Gap | Priority | Likely files | Tests |
|----|------|-------|---------|----------------|-----|----------|--------------|-------|
| F014-G001 | Docs | Formal pack + closed PDs | Pack present; PDs closed | Keep in sync | **NO_GAP** (docs) | — | `docs/v2/F014-*` | — |
| F014-G002 | Engine | Inclusivity / order / fee-exclusive cost | Matches PD-02/03/04 | KEEP | **NO_GAP** (semantics) | — | `PortfolioHistoricalHoldingsService` | Unit thin |
| F014-G003 | Engine | Ledger-only (not holdings table) | Correct | KEEP | **NO_GAP** | — | Same | — |
| F014-G004 | Engine | Oversell handling | Silent skip | `warnings[]` + continue | **PARTIAL** | P0 | Historical service (+ façade) | Missing |
| F014-G005 | API | Dedicated as-of endpoint | None | Profile-scoped read API | **MISSING** | P0 | New controller / routes | Missing |
| F014-G006 | API | Future as_of reject | N/A | Validation error | **MISSING** | P0 | Controller validation | Missing |
| F014-G007 | API | Empty → 200 + [] | N/A | PD-11 | **MISSING** | P0 | Controller | Missing |
| F014-G008 | API | Valuation + unrealized + completeness | N/A | PD-06/07/12 | **MISSING** | P0 | Service + controller | Missing |
| F014-G009 | Price | One adjusted ?? close path | Snapshot yes; QuoteService close-only | Unify for F014 | **PARTIAL** | P0 | New F014 price helper or reuse rebuild index logic | Missing |
| F014-G010 | Price | No silent zero | Snapshots still zero | F014 null + incomplete | **MISSING** (F014 path) | P0 | F014 valuation | Missing |
| F014-G011 | UI | Dedicated page + date picker + table | None | PD-01 columns | **MISSING** | P0 | New page/component, nav, routes | Missing FE |
| F014-G012 | UI | Warnings visibility | N/A | Banner / indicator | **MISSING** | P0 | F014 page | — |
| F014-G013 | UI | Incomplete valuation UX | N/A | PD-07 | **MISSING** | P0 | F014 page | — |
| F014-G014 | UI | Not embedded in live Holdings | N/A | Dedicated page only | **NO_GAP** (constraint) | — | — | — |
| F014-G015 | Cash | Cash as-of | None | OOS | **OUT_OF_SCOPE** | — | — | — |
| F014-G016 | P&L | Realized as-of | None | OOS | **OUT_OF_SCOPE** | — | — | — |
| F014-G017 | F015 | Snapshots as SoT | Aggregates only | Not F014 SoT | **NO_GAP** (boundary) | — | — | — |
| F014-G018 | Export / compare | — | None | Deferred/OOS | **DEFERRED** / **OUT_OF_SCOPE** | — | — | — |
| F014-G019 | Help | F014 topic | F015 only | Accurate F014 help | **MISSING** | P1 | `appDocumentation.js` | — |
| F014-G020 | Tests | Critical path coverage | Thin unit + F015 | Feature/API + engine warnings | **PARTIAL** / **MISSING** | P0 | New Feature tests | Thin |
| F014-G021 | F042/F043 | Consume prices | Indirect | Consume only; no modify | **NO_GAP** | — | — | — |
| F014-G022 | Perf | Caching | None | Engineering optional | **NO_GAP** (policy) | P3 | — | — |

---

## Performance (unchanged)

On-demand full scan: **SHOULD** monitor; not a MUST blocker. Must not change SoT (PD-14/20).

---

## Implementation blockers

**None for policy.** Remaining work is engineering against DECIDED targets (see recommended order in readiness report).

---

*End of F014 gap matrix.*  
*READY_FOR_IMPLEMENTATION — gaps remain until code ships.*
