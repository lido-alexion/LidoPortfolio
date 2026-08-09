# F014 — Historical Holdings Reconstruction Specification

**Date:** 2026-08-09  
**Status:** **`F014_COMPLETE_WITH_NON_BLOCKERS`** (policies closed; MVP delivered 2026-08-09)  
**Initiative:** Portfolio History & Import (Phase 3) — after F019 (`F019_COMPLETE_WITH_NON_BLOCKERS`)  
**Related:** [F014-BOUNDARY.md](./F014-BOUNDARY.md), [F014-POLICY-DECISIONS.md](./F014-POLICY-DECISIONS.md), [F014-IMPLEMENTATION-GAP-MATRIX.md](./F014-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped behaviour.  
**DECIDED** = approved V2 target (product-owner decisions closed — see policy register).

---

## 1. Purpose

Deliver **as-of / historical holdings reconstruction and presentation**: show open equity positions and as-of valuation metrics for a chosen past date, reconstructed from the canonical transaction ledger.

F014 is analytics / presentation. It is **not** live Holdings, F015 equity-curve snapshots, F019 import, F020 CA apply, or F042/F043 repair.

---

## 2. Scope

### In scope (DECIDED MVP)

| Area | Policy |
|------|--------|
| Dedicated as-of holdings **API** | PD-F014-01 |
| Dedicated as-of holdings **page** (date selector + read-only table) | PD-F014-01 |
| Ledger replay: inclusive ≤ D; order date, id | PD-02, PD-03 |
| Fee-exclusive Invested / Avg Buy | PD-04 |
| Structured oversell `warnings[]` | PD-05 |
| Valuation: price on/before D; `adjusted_close ?? close` | PD-06 |
| Missing prices: null + completeness; incomplete totals | PD-07 |
| Unrealized P&L (+ consistent % terminology) | PD-12 + PD-01 |
| Reject future as-of; empty = 200 | PD-10, PD-11 |
| Any calendar day ≤ today | PD-13 |
| On-demand reconstruction | PD-14 |
| Date-only `YYYY-MM-DD` | PD-15 |
| Tests + contextual help before “complete” | PD-21 |

### Explicitly out of scope / deferred

| Area | Policy |
|------|--------|
| Cash as-of | PD-09 OOS MVP |
| Realized P&L as-of / FIFO-at-D | PD-12 OOS |
| Export CSV | PD-16 deferred |
| Compare / new charts | PD-17 deferred/OOS |
| Embed into live Holdings page | PD-01 |
| Absorb F015 / F019 | PD-18, PD-19 |
| Belief / pre-CA time-travel | PD-08 OOS |
| Persist second holdings history store | PD-14 |
| Modify F042/F043/F019/F127 | Boundaries |

---

## 3. Actors

| Actor | Capability |
|-------|------------|
| Authenticated portfolio user | View as-of holdings for **active** portfolio |
| Admin | No special cross-portfolio F014 privilege |

---

## 4. CURRENT implementation inventory

| Layer | Artefact | Role | Gap vs DECIDED |
|-------|----------|------|----------------|
| Reconstruction | `PortfolioHistoricalHoldingsService::holdingsAsOf` | Inclusive replay; fee-exclusive cost; silent oversell skip | Needs warnings (PD-05); expose via product API |
| Snapshot valuation | `PortfolioSnapshotRebuildService` | Uses engine; adjusted prices; silent-zero missing; persists aggregates | F015 only; F014 must not use silent-zero (PD-07) |
| API / UI as-of holdings | — | — | **MISSING** (PD-01) |
| Live holdings | `HoldingsCalculationService` | Current positions; fee-exclusive invested | Do not use as historical qty source |
| Cash | `CashManagementService` | Current only | OOS for F014 |
| F015 UI | Portfolio Snapshots page | Equity curve | Keep separate |

### CURRENT workflow (substrate)

```text
Ledger writes → F015 rebuild uses holdingsAsOf + prices → snapshot aggregates
```

F014 MVP adds a **user-facing** as-of cross-section on demand (not via snapshot rows as SoT).

---

## 5. DECIDED reconstruction & presentation semantics

| Topic | DECIDED |
|-------|---------|
| Source | Transaction ledger for active profile; **not** `portfolio_holdings`; **not** F015 snapshot rows as SoT |
| Inclusivity | `transaction_date <= as_of` |
| Ordering | `transaction_date ASC, id ASC` |
| Buy / sell cost | Fee-exclusive invested / avg (price × qty) |
| Oversell | Skip inconsistent sell for qty math; emit `warnings[]`; continue |
| Open only | Squared-off stocks omitted |
| Price | Latest `price_date <= as_of`; `adjusted_close ?? close`; one path |
| Missing price | null valuation fields; completeness flag/count; totals marked incomplete |
| Unrealized | market − invested where price available; incomplete when any missing |
| Realized / cash | OOS |
| Future as_of | Reject |
| Empty | 200 + `[]` |
| CA | Ledger-truth (ledger as corrected today) |
| Date | `YYYY-MM-DD` |

---

## 6. Requirements

### MUST

| ID | Requirement |
|----|-------------|
| F014-R001 | F014 SHALL scope to the authenticated user’s **active** portfolio profile. |
| F014-R002 | Open-position reconstruction SHALL use the **transaction ledger**, not today’s `portfolio_holdings`. |
| F014-R003 | F014 SHALL NOT invent a parallel write path for transactions, holdings, or cash. |
| F014-R004 | F014 SHALL NOT absorb F019 import/create orchestration. |
| F014-R005 | F014 SHALL NOT replace F015 snapshot materialization / equity-curve product. |
| F014-R006 | MVP SHALL provide a dedicated as-of **API** and dedicated **page** (PD-01); MUST NOT embed into live Holdings. |
| F014-R007 | As-of inclusivity SHALL be `transaction_date <= as_of` (PD-02). |
| F014-R008 | Same-day order SHALL be `transaction_date ASC, id ASC` (PD-03). |
| F014-R009 | Invested / Avg Buy SHALL be fee-exclusive (PD-04). |
| F014-R010 | Oversells SHALL produce structured `warnings[]` and visible UI indication; reconstruction continues (PD-05). |
| F014-R011 | Market prices SHALL use one path: latest on/before as_of, `adjusted_close ?? close` (PD-06). |
| F014-R012 | Missing prices SHALL be null/unavailable with completeness indicator; totals marked incomplete; no silent zero (PD-07). |
| F014-R013 | Truth model SHALL be ledger-truth; belief time-travel MUST NOT be implemented (PD-08). |
| F014-R014 | Cash as-of MUST NOT appear in F014 MVP (PD-09). |
| F014-R015 | Future as_of MUST be rejected (PD-10). |
| F014-R016 | Empty / pre-inception MUST return 200 + empty list (PD-11). |
| F014-R017 | Unrealized P&L MUST be in MVP; realized P&L MUST NOT (PD-12). |
| F014-R018 | Any calendar date ≤ today MAY be selected; price on/before D (PD-13). |
| F014-R019 | Reconstruction SHALL be on-demand from ledger; F015 snapshots MUST NOT be F014 SoT; no second holdings store (PD-14). |
| F014-R020 | as_of SHALL be date-only `YYYY-MM-DD` (PD-15). |
| F014-R021 | Automated tests SHALL cover DECIDED critical API/reconstruction behaviours before claiming complete. |
| F014-R022 | Contextual help SHALL accurately describe F014 (distinct from F015) before claiming complete. |

### SHOULD

| ID | Requirement |
|----|-------------|
| F014-R040 | Reuse / extend `PortfolioHistoricalHoldingsService` rather than duplicating replay. |
| F014-R041 | MVP table columns: symbol/name, qty, Avg Buy, Invested, as-of price, market value, unrealized P&L, consistent %. |
| F014-R042 | Avoid surprise network price fetches on the request path when local OHLCV already exists. |

### COULD

| ID | Requirement |
|----|-------------|
| F014-R060 | Response caching (must not change SoT — PD-20). |
| F014-R061 | Separate as-of fees column (not required; PD-04). |

### MUST NOT

| ID | Requirement |
|----|-------------|
| F014-R050 | MUST NOT use only current `portfolio_holdings` for historical qty. |
| F014-R051 | MUST NOT treat F015 snapshot aggregates as per-stock as-of SoT. |
| F014-R052 | MUST NOT implement export, compare charts, cash as-of, realized as-of, F019 features, or F015 merge in MVP. |
| F014-R053 | MUST NOT modify F042/F043/F019/F127 except unavoidable shared-path regressions with documentation. |

---

## 7. Acceptance criteria

| ID | Criterion |
|----|-----------|
| F014-AC001 | As-of D returns open positions with inclusive ≤ D and date,id order |
| F014-AC002 | Reconstruction ignores live Holding row state (ledger-driven) |
| F014-AC003 | Txs after D excluded |
| F014-AC004 | Active-profile scoping on F014 API |
| F014-AC005 | Dedicated page + API deliver per-stock as-of holdings with DECIDED columns |
| F014-AC006 | Valuation uses adjusted ?? close on/before D; missing → null + incomplete totals |
| F014-AC007 | Oversell yields warnings[] and visible UI; request still succeeds with partial reconstruction |
| F014-AC008 | Future as_of rejected; empty day returns 200 + [] |
| F014-AC009 | No cash, realized, export, or compare charts in MVP |
| F014-AC010 | Help distinguishes F014 from F015 |
| F014-AC011 | Automated tests cover DECIDED critical paths |

---

## 8. Determinism / security / boundary

Same ledger + DECIDED rules + same D → same open holdings (warnings deterministic for same ledger). Ledger edits/CA rewrites change as-of results (rebuildable truth). Sanctum + active portfolio only.

V1 owns ledger writes, live holdings, F015, F020, OHLCV. V2 F014 owns as-of product surface and DECIDED semantics.

---

## 9. Dependencies

| Dependency | Status |
|------------|--------|
| Transaction ledger | Done |
| F019 | COMPLETE |
| F015 | Sibling — do not merge |
| F020 / F042 / F043 | Consume only |
| F003 / F005 | Done |
| Product policies | **Closed** |

---

## 10. Open decisions

**None.** See [F014-POLICY-DECISIONS.md](./F014-POLICY-DECISIONS.md).

---

*End of F014 specification.*
