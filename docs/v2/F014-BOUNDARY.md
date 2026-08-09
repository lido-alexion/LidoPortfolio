# F014 / Ledger / F015 / F019 / F020 / F042 / F043 Boundary

**Date:** 2026-08-09  
**Status:** Policies closed — **`READY_FOR_IMPLEMENTATION`** (product surface not yet shipped)  
**Purpose:** Prevent scope bleed between as-of holdings reconstruction, live holdings, snapshot equity history, CSV import, corporate actions, and market-data repair.  
**Related:** [F014-HISTORICAL-HOLDINGS-SPEC.md](./F014-HISTORICAL-HOLDINGS-SPEC.md), [F014-POLICY-DECISIONS.md](./F014-POLICY-DECISIONS.md), [F014-IMPLEMENTATION-GAP-MATRIX.md](./F014-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram

```text
┌──────────────────────────────────────────────────────────────────┐
│ V2 F014 — Historical Holdings (DECIDED MVP)                      │
│  Dedicated page · as-of date · read-only table · dedicated API   │
│  On-demand ledger reconstruction + valuation + unrealized        │
│  warnings[] for inconsistent history                             │
└───────────────────────────────┬──────────────────────────────────┘
                                │ Reads / replays (no ledger writes)
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ V1 transaction ledger (portfolio_transactions)                   │
│  Written by: CRUD, F019 bulk, F020 CA, TOS fills                 │
└───────────────┬─────────────────────────────┬────────────────────┘
                │                             │
                ▼                             ▼
┌───────────────────────────┐   ┌─────────────────────────────────┐
│ PortfolioHistorical       │   │ HoldingsCalculationService      │
│ HoldingsService (+ F014   │   │ → live portfolio_holdings       │
│ warnings hardening)       │   │ (NOT F014 historical source)    │
└─────────────┬─────────────┘   └─────────────────────────────────┘
              │ used by F015 too
              ▼
┌───────────────────────────┐   ┌─────────────────────────────────┐
│ F015 Snapshot rebuild     │   │ portfolio_stock_prices          │
│ aggregates cache (≠ F014  │   │ F043-repaired / adjusted_close  │
│ holdings SoT)             │   │ (consume only)                  │
└───────────────────────────┘   └─────────────────────────────────┘
```

---

## 2. F014 owns

| Capability |
|------------|
| Dedicated as-of holdings API and page (PD-01) |
| DECIDED reconstruction / valuation / unrealized / warnings semantics |
| F014 help and tests for that surface |
| Clear distinction from F015 Portfolio Snapshots |

---

## 3. F014 does **not** own

| Capability | Owner |
|------------|--------|
| Transaction create / bulk CSV | **F019** / CRUD |
| Live holdings maintenance | V1 `HoldingsCalculationService` |
| Daily portfolio/invested snapshot materialization + equity curve | **F015** |
| Cash as-of | OOS F014 MVP (V1 cash remains current-only for now) |
| Corporate action apply | **F020** |
| Data quality / price repair | **F042 / F043** |
| Alert policies / Telegram | **F127** |
| Realized P&L as-of, export, compare charts | Deferred / OOS |

---

## 4. Boundary rules

1. **F019 = write/import; F014 = read/reconstruct/present.**  
2. **F015 = aggregate time series cache; F014 = per-stock cross-section on D.** Do not merge. Snapshots are **not** F014 SoT (PD-14).  
3. **Ledger is reconstruction source.** Never use today’s `portfolio_holdings` for historical qty.  
4. **F020 may rewrite ledger; F043 may adjust OHLCV.** F014 uses ledger-truth + adjusted prices (PD-08, PD-06). No belief time-travel.  
5. **Do not modify F042/F043/F019/F127** for F014.  
6. **Do not embed** as-of mode into live Holdings page (PD-01).  
7. **Profile auth** = V1 active portfolio (F003/F005).

---

## 5. F014 ↔ F019 / F015 / cash / P&L

| Peer | Rule |
|------|------|
| F019 | Complete; F014 consumes resulting ledger only |
| F015 | Separate product; rebuildable cache for aggregates only |
| Cash | Not in F014 MVP |
| Unrealized | In F014 MVP with valuation |
| Realized | Not in F014 MVP |

---

## 6. Explicit non-goals

- Absorbing F019 or F015  
- Belief / pre-CA time-travel  
- Silent-zero missing prices in F014 UI/API  
- Fee-inclusive Invested/Avg Buy  
- RBAC / multi-tenant as-of  
- Second persisted historical-holdings store  

---

## 7. Agent / implementer checklist

1. Implement DECIDED PDs only; do not reopen closed policies.  
2. Extend `PortfolioHistoricalHoldingsService` for warnings; dedicated API + page.  
3. One price path (adjusted ?? close).  
4. Do not start F060/F137 in this initiative.  
5. Help + tests required before declaring F014 complete.

---

*End of F014 boundary.*
