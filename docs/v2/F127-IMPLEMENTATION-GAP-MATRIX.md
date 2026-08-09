# F127 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** **COMPLETE** (`F127_COMPLETE_WITH_NON_BLOCKERS`) — PD-F127-07 expire→evaluate delivered; sold-holding expiry corrected  
**Initiative:** Monitoring & Alerts (F127)  
**Related:** [F127-PORTFOLIO-ALERTS-SPEC.md](./F127-PORTFOLIO-ALERTS-SPEC.md), [F127-BOUNDARY.md](./F127-BOUNDARY.md), [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| F127 code framework | **Shipped** + hardened |
| F127 formal V2 pack | **Present** |
| F127 product policies | **Closed** |
| F127 hardening | **DONE** — expire→evaluate; holding_closed mid-recalc fix; tests; help |

### Classification legend

| Label | Meaning |
|-------|---------|
| `NO_GAP` | CURRENT matches DECIDED |
| `PARTIAL` | Non-blocking polish |
| `DEFERRED` | Postponed |
| `OUT_OF_SCOPE` | Excluded |

---

## Gap register

| ID | Area | Topic | Gap | Priority |
|----|------|-------|-----|----------|
| F127-G001–G003 | Docs / policy | Pack + decisions | **NO_GAP** | — |
| F127-G004 | Lifecycle | expire→evaluate | **NO_GAP** (DailyMarketDataJob) | — |
| F127-G005–G006 | Digest / re-arm | Keep CURRENT=DECIDED | **NO_GAP** | — |
| F127-G007–G010 | Universe / model / CRUD / auth | Preserved | **NO_GAP** | — |
| F127-G011 | Tests | AC010 + lifecycle | **NO_GAP** (`AlertLifecycleOrderingTest`) | — |
| F127-G012 | FE automated tests | None | **DEFERRED** | P3 |
| F127-G013 | `notifications_enabled` UI | Missing toggle | **PARTIAL** non-blocker | P2 |
| F127-G014 | Non-numeric condition UX | Optional | **PARTIAL** non-blocker | P2 |
| F127-G015 | F127 in notification history | TOS only | **DEFERRED** | P3 |
| F127-G016–G018 | Soft DQ / channels / TOS | As decided | **NO_GAP** / **OUT_OF_SCOPE** | — |
| F127-G019–G025 | Schedule / expire / scope | Preserved + holding_closed fix | **NO_GAP** | — |
| F127-G026 | `is_system` | Unused | **DEFERRED** | — |
| F127-G027 | Help sync | Updated | **NO_GAP** | — |
| F127-G028–G030 | Freq / level / `is_sent` | Preserved | **NO_GAP** | — |

---

## Recommended order (historical)

1. ~~PD-F127-07~~ **DONE**  
2. ~~Tests AC010~~ **DONE**  
3. ~~Help sync~~ **DONE**  
4. Optional non-blockers remain (G013/G014/G012)  
5. Compliance audit may follow separately  

---

*End of F127 gap matrix.*  
*Hardening closed 2026-08-09 → F127_COMPLETE_WITH_NON_BLOCKERS.*
