# F014 Policy Decisions

**Date:** 2026-08-09  
**Status:** Policies **closed**. Spec pack status: **`READY_FOR_IMPLEMENTATION`**  
**Spec:** [F014-HISTORICAL-HOLDINGS-SPEC.md](./F014-HISTORICAL-HOLDINGS-SPEC.md)  
**Boundary:** [F014-BOUNDARY.md](./F014-BOUNDARY.md)  
**Gap matrix:** [F014-IMPLEMENTATION-GAP-MATRIX.md](./F014-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped code behaviour.  
**DECIDED** = approved V2 target (product-owner confirmed 2026-08-09).  
Hardening must implement DECIDED targets; do not treat CURRENT alone as approval when they diverge.

---

## Final policy register

| Decision | Status |
|----------|--------|
| PD-F014-01 Product surface | **DECIDED** — Option B: API + dedicated as-of holdings page |
| PD-F014-02 As-of date inclusivity | **DECIDED** — inclusive `transaction_date <= D` |
| PD-F014-03 Same-day transaction ordering | **DECIDED** — `ORDER BY transaction_date ASC, id ASC` |
| PD-F014-04 Cost basis / fees | **DECIDED** — Option A: fee-exclusive Invested / Avg Buy |
| PD-F014-05 Oversell / inconsistent history | **DECIDED** — structured `warnings[]`; continue reconstruction |
| PD-F014-06 Market price selection | **DECIDED** — latest `price_date <= D`; `adjusted_close ?? close`; one path |
| PD-F014-07 Missing historical price | **DECIDED** — null/unavailable; completeness indicator; no silent zero |
| PD-F014-08 Corporate-action / truth model | **DECIDED** — ledger-truth / rebuildable truth; belief OOS |
| PD-F014-09 Historical cash | **DECIDED** — **OUT_OF_SCOPE** for MVP |
| PD-F014-10 Future dates | **DECIDED** — reject `as_of > today` |
| PD-F014-11 Empty / pre-inception | **DECIDED** — HTTP 200 + empty holdings list |
| PD-F014-12 Historical P&L | **DECIDED** — unrealized **IN** MVP; realized **OUT_OF_SCOPE** |
| PD-F014-13 Weekends / holidays | **DECIDED** — any calendar date ≤ today; prior available price |
| PD-F014-14 Calculation / source of truth | **DECIDED** — on-demand ledger reconstruction; F015 snapshots ≠ F014 SoT |
| PD-F014-15 Timezone / date boundary | **DECIDED** — date-only `YYYY-MM-DD` |
| PD-F014-16 Export | **DECIDED** — **DEFERRED** |
| PD-F014-17 Compare / additional charts | **DECIDED** — **DEFERRED** / **OUT_OF_SCOPE** |
| PD-F014-18 Absorb F015 | **DECIDED** — **OUT_OF_SCOPE** |
| PD-F014-19 Absorb F019 | **DECIDED** — **OUT_OF_SCOPE** |
| PD-F014-20 Performance / caching | **DECIDED** — **NOT_A_POLICY_DECISION** (on-demand MVP; engineering later) |
| PD-F014-21 Help / tests | **DECIDED** — **NOT_A_POLICY_DECISION** (required before F014 complete) |

---

## Summary table

| Decision | CURRENT (code) | Approved V2 target | Status |
|----------|----------------|--------------------|--------|
| PD-01 | Engine only; no as-of API/UI | API + dedicated as-of page (not embedded in live Holdings) | **DECIDED** |
| PD-02 | `transaction_date <= D` | KEEP inclusive | **DECIDED** |
| PD-03 | `date`, then `id` ASC | KEEP | **DECIDED** |
| PD-04 | Historical: fee-exclusive invested; live Holdings same for Avg/Invested | Fee-exclusive Invested / Avg Buy; no new fee-basis model | **DECIDED** |
| PD-05 | Silent oversell skip | Structured `warnings[]`; continue; UI visible | **DECIDED** |
| PD-06 | Snapshot: adjusted preferred; QuoteService close-only fallback | One path: on/before D; `adjusted_close ?? close`; consume F043 | **DECIDED** |
| PD-07 | Snapshots silent-zero | Null/unavailable + completeness; incomplete totals | **DECIDED** |
| PD-08 | Ledger after CA rewrite | Ledger-truth; belief OOS | **DECIDED** |
| PD-09 | No cash-as-of | OOS MVP | **DECIDED** |
| PD-10 | Engine permissive | Reject future as-of | **DECIDED** |
| PD-11 | Empty map | 200 + empty list + empty-state UI | **DECIDED** |
| PD-12 | No as-of realized; unrealized only in rebuild calc | Unrealized in MVP; realized OOS | **DECIDED** |
| PD-13 | Engine any day; F015 weekday rows | Any calendar ≤ today; prior price | **DECIDED** |
| PD-14 | On-demand for detail; snapshots aggregates | On-demand F014; F015 cache ≠ SoT; no new store | **DECIDED** |
| PD-15 | Date-only | KEEP `YYYY-MM-DD` | **DECIDED** |
| PD-16 | None | Deferred | **DECIDED** |
| PD-17 | None | Deferred / OOS | **DECIDED** |
| PD-18 | Separate F015 | Keep separate | **DECIDED** |
| PD-19 | F019 complete separate | Keep separate | **DECIDED** |

---

## PD-F014-01 — Product surface

**Status:** **DECIDED** (Option B)

**Approved target:** Dedicated F014 page + as-of date selector + read-only holdings table + dedicated backing API.

**MVP table SHOULD include:** symbol/name; quantity; Avg Buy / Invested; as-of market price; market value; unrealized P&L; percentage metric consistent with existing portfolio terminology.

**MUST NOT:** embed historical mode into the live Holdings page; add cash, realized P&L, export, or comparison charts unless separately approved (those PDs defer/OOS).

---

## PD-F014-02 — As-of date inclusivity

**Status:** **DECIDED**

**Approved:** Inclusive — include transactions with `transaction_date <=` selected as-of date.

---

## PD-F014-03 — Same-day transaction ordering

**Status:** **DECIDED**

**Approved:** Deterministic `ORDER BY transaction_date ASC, id ASC`. No intraday ordering or timestamps.

---

## PD-F014-04 — Fees / cost basis

**Status:** **DECIDED** (Option A)

**Approved:** Fee-exclusive Invested / Avg Buy — same semantics as live Holdings and F015 `invested_value`. Do **not** add brokerage into Invested / Avg Buy. Do **not** change portfolio-wide cost-basis semantics. Separate fee metric may come later; not F014 MVP requirement.

---

## PD-F014-05 — Oversell / inconsistent history

**Status:** **DECIDED**

**Approved:** Continue reconstructing where possible. Return structured `warnings[]` with affected transaction/stock information. UI must visibly indicate inconsistency. Do **not** silently swallow oversells. Do **not** fail the entire request solely for oversell. Do **not** modify the ledger to “repair” history.

---

## PD-F014-06 — Historical price selection

**Status:** **DECIDED**

**Approved:** Latest available row with `price_date <=` as-of date; prefer `adjusted_close` when available, else `close`. Consume repaired/adjusted OHLCV from F042/F043. **One** consistent F014 price path — do not mix close-only fallback with adjusted elsewhere. Do **not** reopen or modify F042/F043.

---

## PD-F014-07 — Missing historical price

**Status:** **DECIDED**

**Approved:** Do **not** silently treat missing price as zero in F014. Return null/unavailable valuation for that holding. Return explicit completeness indicator / missing-price count. UI must show unavailable values. Portfolio valuation / unrealized totals must indicate **incomplete** when any prices are missing.

**Note:** F015 snapshot rebuild may still silent-zero for aggregate cache (out of F014 change scope unless a future initiative remediates F015). F014 presentation must follow this PD.

---

## PD-F014-08 — Corporate-action / historical truth model

**Status:** **DECIDED**

**Approved:** Ledger-truth / rebuildable truth. Reconstruct today’s currently corrected ledger as of the selected date. Belief / pre-CA time-travel is **OUT_OF_SCOPE**. F042/F043 remain completed and unmodified.

---

## PD-F014-09 — Historical cash

**Status:** **DECIDED** — **OUT_OF_SCOPE** for F014 MVP

No historical cash balance on F014 API or UI.

---

## PD-F014-10 — Future dates

**Status:** **DECIDED**

API validation error for `as_of > today`. UI must prevent / reject future dates.

---

## PD-F014-11 — Empty / pre-inception

**Status:** **DECIDED**

HTTP **200** with empty holdings list. Valid state, not an error. UI empty-state message.

---

## PD-F014-12 — Historical P&L

**Status:** **DECIDED**

- Unrealized P&L **IN** F014 MVP (valuation included).  
- Realized P&L **OUT_OF_SCOPE** for MVP.  
- Do not introduce FIFO-at-D or a new realized-P&L reconstruction engine.

---

## PD-F014-13 — Weekends / holidays

**Status:** **DECIDED**

Any calendar date ≤ today may be selected. No trading-day-only restriction. Weekends/holidays use latest available price on or before the selected date (PD-06).

---

## PD-F014-14 — Calculation / source of truth

**Status:** **DECIDED**

F014 holdings reconstructed **on demand** from the transaction ledger. F015 snapshots remain a rebuildable cache for F015 and are **not** the source of truth for F014 holdings. Do **not** persist a second historical-holdings store in MVP.

---

## PD-F014-15 — Timezone / date boundary

**Status:** **DECIDED**

Date-only `YYYY-MM-DD`, consistent with transaction dates. No intraday timestamps.

---

## PD-F014-16 — Export

**Status:** **DECIDED** — **DEFERRED**

No CSV/export in F014 MVP.

---

## PD-F014-17 — Compare / additional charts

**Status:** **DECIDED** — **DEFERRED** / **OUT_OF_SCOPE**

No side-by-side comparison or new charts in F014 MVP.

---

## PD-F014-18 — Absorb F015

**Status:** **DECIDED** — **OUT_OF_SCOPE**

Keep F014 Historical Holdings and F015 Portfolio Snapshots separate.

---

## PD-F014-19 — Absorb F019

**Status:** **DECIDED** — **OUT_OF_SCOPE**

F019 Bulk CSV Import remains separate and complete.

---

## PD-F014-20 — Performance / caching

**Status:** **DECIDED** — **NOT_A_POLICY_DECISION**

On-demand reconstruction for MVP. Engineering may optimize later with evidence; must not change source-of-truth semantics (PD-14).

---

## PD-F014-21 — Help / tests

**Status:** **DECIDED** — **NOT_A_POLICY_DECISION**

Help sync and adequate automated tests are **implementation requirements** before F014 may be declared complete.

---

## Blocking decisions

**None remaining.** Product policies closed 2026-08-09. Implementation may proceed against DECIDED targets.

---

## Product-owner confirmation log

| Date | Action |
|------|--------|
| 2026-08-09 | Analyst recommendations published (awaiting confirmation) |
| 2026-08-09 | Product owner confirmed PD-F014-01…21 as recorded in this document |

---

*End of F014 policy decisions.*  
*Policies closed → READY_FOR_IMPLEMENTATION.*
