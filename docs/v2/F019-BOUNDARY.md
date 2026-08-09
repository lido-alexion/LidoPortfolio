# F019 / Ledger / F014 / Cash Boundary

**Date:** 2026-08-09  
**Status:** Hardening delivered — `F019_COMPLETE_WITH_NON_BLOCKERS`  
**Purpose:** Prevent scope bleed between bulk CSV import, canonical ledger writes, cash, snapshots, corporate actions, transaction CRUD, and F014 historical holdings.  
**Related:** [F019-BULK-CSV-IMPORT-SPEC.md](./F019-BULK-CSV-IMPORT-SPEC.md), [F019-POLICY-DECISIONS.md](./F019-POLICY-DECISIONS.md), [F019-IMPLEMENTATION-GAP-MATRIX.md](./F019-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram

```text
┌──────────────────────────────────────────────────────────────────┐
│ V2 F019 — Bulk CSV Import                                        │
│  CSV paste parse · review UI · bulk commit orchestration         │
│  batch ID + row identity (PD-F019-02/03/15)                      │
└───────────────────────────────┬──────────────────────────────────┘
                                │ Bulk validate + all-or-nothing commit
                                │ (PD-F019-01 / PD-F019-18)
                                ▼
┌──────────────────────────────────────────────────────────────────┐
│ F019 bulk commit API (new) + shared financial write unit         │
│  For each row in one DB transaction (batch):                     │
│    ledger + holdings/realizations + cash  (PD-F019-14)           │
│  After financial unit commits: OHLCV / snapshots (best-effort;   │
│    must not reverse financial consistency)                       │
└───────────────┬─────────────────────────────┬────────────────────┘
                │                             │
                ▼                             ▼
┌───────────────────────────┐   ┌─────────────────────────────────┐
│ SD-021 Ledger write path  │   │ V1 Cash (SD-026)                │
│ (remediated for PD-14)    │   │ inside same financial unit      │
└───────────────────────────┘   └─────────────────────────────────┘

┌───────────────────────────┐   ┌─────────────────────────────────┐
│ V1 Transaction CRUD       │   │ V1 F020 Corporate actions       │
│ single add/edit/delete    │   │ split/bonus — NOT F019 CSV      │
│ (also uses PD-14 unit)    │   └─────────────────────────────────┘
└───────────────────────────┘

┌───────────────────────────┐
│ V2 F014 (downstream)      │
│ Historical holdings UI    │
└───────────────────────────┘
```

**CURRENT (pre-hardening):** Client sequential `POST /api/transactions` with cash applied after ledger create — **not** DECIDED.

---

## 2. F019 owns

| Capability |
|------------|
| CSV text contract for bulk paste (columns, parse errors) |
| Review-table UX before persistence |
| Bulk commit orchestration (batch ID, row identity, all-or-nothing UX) |
| F019-specific help/docs/tests for import behaviour |
| Bulk validate/commit API surface (PD-F019-18) |

---

## 3. F019 does **not** own

| Capability | Owner |
|------------|--------|
| Canonical ledger insert / holdings / realizations | Shared write path (SD-021) — **PD-14 remediates** cash into this unit |
| Cash balance posting for trades | Same financial unit as ledger (PD-14); not a separate post-success step |
| Stock master validation / create-on-resolve | `StockResolverService` / validation services |
| Fee component configuration | Settings / fee calculator (shared with single form) |
| Single-row add/edit/delete UI | Existing Transactions CRUD (must share PD-14 unit) |
| Corporate action apply / OHLCV repair | F020 / F042 / F043 |
| As-of holdings reconstruction UI | **F014** |
| Snapshot / OHLCV product | Post-commit side effects; must not break financial unit |

---

## 4. Boundary rules

1. **Batch persistence uses the DECIDED bulk commit API** — not client-orchestrated partial `POST /transactions` loops.  
2. **PD-F019-14** remediates the **shared** create path (V1 defect). F019 must not invent a private write stack that leaves single-entry inconsistent.  
3. **Client compensation is not atomicity** (PD-F019-01).  
4. **F014 is separate:** importing ≠ as-of holdings UI.  
5. **Corporate actions** remain out of the CSV type system.  
6. **Profile authorization** remains V1 active-portfolio scoping.  
7. **Delete/reverse** of mistaken imports uses V1 transaction CRUD (PD-17).

---

## 5. F019 ↔ F014

| Concern | F019 | F014 |
|---------|------|------|
| Write transactions | Yes (bulk) | No |
| CSV / bulk entry | Yes | No |
| As-of holdings query UI | No | Yes |
| Sequencing | Harden first | After F019 |

---

## 6. F019 ↔ cash / funds

| Concern | Rule |
|---------|------|
| Does import affect cash? | **Yes** — inside the atomic financial unit |
| Insufficient cash | Must fail the financial unit (and the whole batch if in bulk commit) with **no** orphan ledger row |
| TOS cash reservation redesign | **OUT_OF_SCOPE** |

---

## 7. F019 ↔ existing transaction CRUD

| Concern | Rule |
|---------|------|
| Same fee model / buy-sell types | Keep aligned |
| PD-14 financial unit | Applies to single create as well as bulk rows |
| Bulk edit/delete via CSV | Not in scope |

---

## 8. Explicit non-goals

- Broker multi-format statement import  
- Silent chronological reordering  
- Economic-fingerprint blocking dedupe  
- Absorbing F014  

---

## 9. Agent / implementer checklist

1. Read policies (especially PD-01, 14, 02/03, 15, 18) before coding.  
2. Implement PD-14 on the shared path before or with bulk commit.  
3. Do not start F014 in this initiative.  
4. Do not reopen completed F003/F005/F042/F043/F127.

---

*End of F019 boundary.*
