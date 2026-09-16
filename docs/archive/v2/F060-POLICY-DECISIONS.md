# F060 Policy Decisions — Shared Screener Import

**Date:** 2026-08-09  
**Status:** Product policies **closed**; AuthZ hardening **delivered**  
**Delivery:** **`F060_COMPLETE_WITH_NON_BLOCKERS`**  
**Readiness (pre-implement):** was `READY_FOR_IMPLEMENTATION`  
**Spec:** [F060-SHARED-SCREENER-IMPORT-SPEC.md](./F060-SHARED-SCREENER-IMPORT-SPEC.md)  
**Boundary:** [F060-BOUNDARY.md](./F060-BOUNDARY.md)  
**Gap matrix:** [F060-IMPLEMENTATION-GAP-MATRIX.md](./F060-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = behaviour after F060 hardening (2026-08-09).  
**DECIDED** = approved V2 target (product-owner 2026-08-09) — **implemented**.

Residual non-blocking (unchanged; not newly decided): **PD-F060-10**, **PD-F060-22**.

---

## How to read this register

| Status | Meaning |
|--------|---------|
| **DECIDED** | Product owner closed; implementation matches |
| **DECISION_REQUIRED** | Still open — residual non-blocking only |
| **OUT_OF_SCOPE** | Not owned by F060 |
| **NOT_A_POLICY_DECISION** | Engineering/docs quality |

---

## Final policy register (summary)

| Decision | Status | Implemented? |
|----------|--------|--------------|
| PD-F060-01 Sharing audience | **DECIDED** — same user only | **Yes** |
| PD-F060-02 Ownership locus | **DECIDED** — profile_id; user via profile→user | **Yes** |
| PD-F060-03 Meaning of `is_shared` | **DECIDED** — same-user other profiles | **Yes** |
| PD-F060-04 Who may set/clear `is_shared` | **DECIDED** — owning profile; share ≠ write | **Yes** |
| PD-F060-05 Discoverability | **DECIDED** — same-user only | **Yes** |
| PD-F060-06 Shared definition exposure | **DECIDED** — name + definition/conditions | **Yes** |
| PD-F060-07 Extra ownership metadata | **DECIDED** — strip beyond PD-06 | **Yes** |
| PD-F060-08 Import = independent copy | **DECIDED** | **Yes** |
| PD-F060-09 Source linkage | **DECIDED** — no sync | **Yes** |
| PD-F060-10 Watchlist/schedule/telegram remap | **DECISION_REQUIRED** (non-blocking) | CURRENT preserved |
| PD-F060-11 Name collision `(1)`, `(2)`, … | **DECIDED** | **Yes** |
| PD-F060-12 Destination active profile | **DECIDED** | **Yes** |
| PD-F060-13 Cross-profile same-user | **DECIDED** | **Yes** |
| PD-F060-14 Source lifecycle vs copies | **DECIDED** | **Yes** |
| PD-F060-15 Owner-only write/delete | **DECIDED** | **Yes** |
| PD-F060-16 / 30 Registry ↔ classic | **DECIDED** | **Yes** |
| PD-F060-17 Private denial | **DECIDED** | **Yes** |
| PD-F060-18 / 27 Eligibility/Discovery | **DECIDED** — same-user shared | **Yes** (+ BacktestSimulationEngine) |
| PD-F060-19 / 25 / 28 / 32 OOS | **OUT_OF_SCOPE** | N/A |
| PD-F060-20 No admin exception | **DECIDED** | **Yes** |
| PD-F060-21 Copy-only | **DECIDED** | **Yes** |
| PD-F060-22 404 vs 403 | **DECISION_REQUIRED** (non-blocking) | CURRENT 404 preserved |
| PD-F060-23 / 24 Docs/tests | **NOT_A_POLICY_DECISION** | Synced |
| PD-F060-26 / 29 Unshare + copy lifecycle | **DECIDED** | **Yes** |
| PD-F060-31 Cross-user denial all paths | **DECIDED** | **Yes** |

---

## Residual DECISION_REQUIRED (non-blocking)

| ID | Topic | Guidance |
|----|-------|----------|
| PD-F060-10 | Import remap | Keep CURRENT (watchlist→holdings; schedule/telegram off) |
| PD-F060-22 | 404 vs 403 | Keep CURRENT 404 |

---

## Implementation note (2026-08-09)

- Scopes: `Screener::sharedVisibleTo`, `Screener::ownedOrSameUserShared` (profile→`user_id`).
- Classic + registry + eligibility + Discovery + backtest pin paths hardened.
- `formatShared` / `projectShared` limited to name + definition (+ id / minimal registry flags).
- `uniqueNameForProfile` starts at `(1)`.
- Tests: `F060SharedScreenerAuthzTest`, updated `ScreenerRegistryApiTest` / `ScreenerTest`.
