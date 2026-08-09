# F014 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** Hardening **delivered** — `F014_COMPLETE_WITH_NON_BLOCKERS`  
**Initiative:** F014 Historical Holdings Reconstruction  
**Related:** [F014-HISTORICAL-HOLDINGS-SPEC.md](./F014-HISTORICAL-HOLDINGS-SPEC.md), [F014-BOUNDARY.md](./F014-BOUNDARY.md), [F014-POLICY-DECISIONS.md](./F014-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| Formal V2 pack + closed PDs | Present |
| Reconstruction + warnings | **DONE** |
| Valuation path (adjusted ?? close) | **DONE** |
| As-of holdings API | **DONE** — `GET /api/portfolio/historical-holdings` |
| Dedicated UI page | **DONE** — `/portfolio/historical-holdings` |
| Help / tests | **DONE** |

### Gap register (post-delivery)

| ID | Topic | Gap |
|----|-------|-----|
| F014-G001–G014 | Engine, API, UI, valuation, warnings | **NO_GAP** / **IMPLEMENTED** |
| F014-G015–G016 | Cash / realized | **OUT_OF_SCOPE** |
| F014-G018 | Export / compare | **DEFERRED** |
| F014-G019–G020 | Help / tests | **NO_GAP** |
| F014-G022 | Perf caching | Engineering optional |

### Remaining non-blockers

- Export / compare charts (deferred)
- Cash as-of / realized as-of (OOS)
- Optional response caching
- No dedicated FE e2e framework

---

*End of F014 gap matrix.*  
*Hardening closed 2026-08-09 → F014_COMPLETE_WITH_NON_BLOCKERS.*
