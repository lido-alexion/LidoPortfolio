# F019 Policy Decisions

**Date:** 2026-08-09  
**Status:** Policies **closed**. Initiative delivery: **COMPLETE** (`F019_COMPLETE_WITH_NON_BLOCKERS`)  
**Spec:** [F019-BULK-CSV-IMPORT-SPEC.md](./F019-BULK-CSV-IMPORT-SPEC.md)  
**Boundary:** [F019-BOUNDARY.md](./F019-BOUNDARY.md)  
**Gap matrix:** [F019-IMPLEMENTATION-GAP-MATRIX.md](./F019-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped code behaviour.  
**DECIDED** = approved V2 target (may differ from CURRENT).  
Hardening must implement DECIDED targets; do not treat CURRENT alone as approval when they diverge.

Product-owner confirmation recorded 2026-08-09 for PD-F019-01, 02/03, 05, 06, 14, 15 (and PD-18 elevated by PD-01).

---

## Final policy register

| Decision | Status |
|----------|--------|
| PD-F019-01 Batch atomicity | **DECIDED** — all-or-nothing per CSV import (Option B); bulk backend commit required |
| PD-F019-02 Duplicate import of the same CSV | **DECIDED** — Option E′ (with PD-03) |
| PD-F019-03 Duplicate / retry identity | **DECIDED** — Option E′ batch + row identity; no economic fingerprint block |
| PD-F019-04 CSV schema / column set | **DECIDED** — keep CURRENT four-column contract |
| PD-F019-05 Date column in CSV | **DECIDED** — no Date column; per-row review dates default today |
| PD-F019-06 Row ordering | **DECIDED** — preserve CSV/review order; never silent chronological reorder |
| PD-F019-07 Supported transaction types | **DECIDED** — buy/sell only |
| PD-F019-08 Fees treatment | **DECIDED** — keep client fee calculator + server trusts fees |
| PD-F019-09 Exchange / symbol resolution | **DECIDED** — keep CURRENT (default NSE; resolver on save) |
| PD-F019-10 Overwrite / modify existing via import | **DECIDED** — create-only; no overwrite mode |
| PD-F019-11 Preview before import | **DECIDED** — keep parse & review step |
| PD-F019-12 Max rows / size limits | **DEFERRED** |
| PD-F019-13 Quoted CSV / broker formats | **DEFERRED** / **OUT_OF_SCOPE** for broker formats |
| PD-F019-14 Ledger + holdings/realizations + cash atomicity | **DECIDED** — Option B (V1 shared-path remediation required by F019) |
| PD-F019-15 Re-save / retry UX | **DECIDED** — all-or-nothing retry semantics (no partial committed subset) |
| PD-F019-16 Historical date boundaries | **DECIDED** — keep non-future only; no min history date |
| PD-F019-17 Delete/reverse imported rows | **DECIDED** — use existing transaction CRUD |
| PD-F019-18 Dedicated bulk API | **DECIDED** — required (elevated by PD-F019-01) |
| PD-F019-19 F014 absorption | **DECIDED** — F014 out of F019 |
| PD-F019-20 Paste vs file upload | **DECIDED** — keep paste-first CURRENT; file upload not required |
| PD-F019-21 Help accuracy | **NOT_A_POLICY_DECISION** — docs sync task |
| PD-F019-22 PHP/feature test depth | **NOT_A_POLICY_DECISION** — engineering quality |

---

## Summary table

| Decision | CURRENT (code) | Approved V2 target | Status |
|----------|----------------|--------------------|--------|
| PD-F019-01 | Row-by-row partial success | **All-or-nothing** per CSV import; bulk backend commit; client undo ≠ atomicity | **DECIDED** |
| PD-F019-02/03 | Re-Save / re-import can duplicate; no batch id | **Batch ID + stable row identity** for one import/retry lifecycle; new import may repeat same data; **no** economic-fingerprint blocking | **DECIDED** |
| PD-F019-04 | Stock, Qty, Avg Price, Type | Keep | **DECIDED** |
| PD-F019-05 | No Date column; per-row date default today | Keep; server future-date validation authoritative | **DECIDED** |
| PD-F019-06 | Paste/review order | Preserve order; never silent chronological reorder; optional warnings later | **DECIDED** |
| PD-F019-07 | buy/sell only | Keep | **DECIDED** |
| PD-F019-08 | Client fees | Keep | **DECIDED** |
| PD-F019-09 | NSE default; resolve on save | Keep | **DECIDED** |
| PD-F019-10 | Create only | Keep | **DECIDED** |
| PD-F019-11 | Review UI | Keep | **DECIDED** |
| PD-F019-12 | No max rows | Soft limit later | **DEFERRED** |
| PD-F019-13 | No quotes; no broker CSV | Defer quotes; broker OOS | **DEFERRED** / **OUT_OF_SCOPE** |
| PD-F019-14 | Create then cash (not one DB txn) | **Atomic financial unit:** ledger + holdings/realizations + cash on shared write path; OHLCV/snapshots after commit must not undo consistency | **DECIDED** |
| PD-F019-15 | Partial success; re-Save duplicates | All-or-nothing UX: zero rows on failure; correct & retry batch; transport retry same batch OK; completed batch not re-submittable | **DECIDED** |
| PD-F019-16 | Non-future only | Keep | **DECIDED** |
| PD-F019-17 | Manual delete | Keep | **DECIDED** |
| PD-F019-18 | No bulk endpoint | **Required** bulk commit API | **DECIDED** |
| PD-F019-19 | No F014 UI | Keep separation | **DECIDED** |
| PD-F019-20 | Paste textarea | Keep | **DECIDED** |

---

## PD-F019-01 — Batch atomicity

**Status:** **DECIDED** (Option B)

**CURRENT:** Sequential `POST /transactions`. Failures do not roll back earlier successes.

**Approved target:** **All-or-nothing per CSV import.** A **bulk backend commit** path is required. Client-side compensation/undo is **not** atomicity.

---

## PD-F019-02 / PD-F019-03 — Duplicate semantics (Option E′)

**Status:** **DECIDED** (Option E′)

**CURRENT:** Re-import / re-Save can create duplicate ledger and cash effects. No import batch identity.

**Approved target:**

- Use an **import batch ID** and **stable row identity** for one import/retry lifecycle.  
- Do **not** use economic-field fingerprints as a **blocking** deduplication mechanism.  
- A **new** import is allowed to contain the same transaction data as a previous import.

---

## PD-F019-04 — CSV schema

**Status:** **DECIDED**

**Approved target:** Keep CURRENT four positional columns as the V2 baseline contract.

---

## PD-F019-05 — Date in CSV

**Status:** **DECIDED**

**CURRENT / approved:** Keep the current CSV contract with **no Date column**. Each reviewed row has an **editable** transaction date, **defaulting to today**. Server-side **future-date** validation remains authoritative.

---

## PD-F019-06 — Ordering

**Status:** **DECIDED**

**CURRENT / approved:** **Preserve CSV/review order.** Never silently reorder transactions chronologically. Optional chronological warnings may be added later.

---

## PD-F019-07 — Supported types

**Status:** **DECIDED**

**Approved:** Buy and sell only. Reject other types at parse. Corporate actions / dividends / transfers remain out of F019 CSV.

---

## PD-F019-08 — Fees

**Status:** **DECIDED**

**Approved:** Keep client-side fee calculation from Settings fee components; request includes `fees`; aligned with single-entry form (V1).

---

## PD-F019-09 — Symbol / exchange

**Status:** **DECIDED**

**Approved:** Default exchange NSE on review; user may switch BSE; server `StockResolverService` validates/persists. No ISIN column in baseline CSV.

---

## PD-F019-10 — Overwrite / replace

**Status:** **DECIDED**

**Approved:** Import is **create-only**. No CSV mode replaces or updates existing transactions.

---

## PD-F019-11 — Preview

**Status:** **DECIDED**

**Approved:** Keep mandatory parse & review before save (no silent direct-to-ledger from paste).

---

## PD-F019-12 — Max rows / size

**Status:** **DEFERRED**

**CURRENT:** No explicit limit (browser/API practical limits only). Optional soft cap may be added later.

---

## PD-F019-13 — Quoted CSV / broker formats

**Status:** **DEFERRED** (quoted fields) / **OUT_OF_SCOPE** (broker-specific multi-column statements)

---

## PD-F019-14 — Ledger + holdings/realizations + cash atomicity

**Status:** **DECIDED** (Option B)

**CURRENT:** Ledger create (and holdings/realizations/snapshots side effects) can commit before cash apply; insufficient cash can fail after ledger insert (shared single-entry path).

**Approved target:** The **shared** transaction write path must atomically maintain the financial unit comprising:

1. ledger transaction  
2. holdings / realizations  
3. cash  

This is a **V1 shared-path defect remediation required by F019**, not an F019-only fork.

After the financial unit commits, **OHLCV backfill** and **snapshot** work must **not** cause the already-committed financial transaction to become inconsistent. Exact best-effort/retry mechanics are an **implementation detail** unless a further policy is raised.

---

## PD-F019-15 — Re-save / retry UX

**Status:** **DECIDED**

**CURRENT:** Partial success leaves committed rows; Save all can re-post successes.

**Approved target:** Import is **all-or-nothing**. There is **no** partially committed successful subset to retry.

- Validation/business failure → **zero** committed rows; user corrects the batch and retries it.  
- Transport failure after a **server rollback** → may retry the **same batch** safely.  
- A **successfully completed** batch **cannot** be submitted again.

---

## PD-F019-16 — Historical date boundaries

**Status:** **DECIDED**

**Approved:** Keep server/client **non-future** rule only. No additional minimum trade date for F019 baseline.

---

## PD-F019-17 — Delete / reverse

**Status:** **DECIDED**

**Approved:** Mistaken imports are corrected via existing transaction edit/delete (cash reverse on delete). No F019 bulk-undo requirement in baseline.

---

## PD-F019-18 — Dedicated bulk API

**Status:** **DECIDED** (elevated by PD-F019-01)

**Approved:** A bulk backend commit path **is required** for all-or-nothing CSV import. Client sequential `POST /transactions` is not the DECIDED persistence model.

---

## PD-F019-19 — F014

**Status:** **DECIDED**

F014 historical holdings remains a separate initiative. F019 must not absorb it.

---

## PD-F019-20 — Paste vs upload

**Status:** **DECIDED**

Keep paste textarea as primary input. File `<input type=file>` not required for V2 baseline.

---

## PD-F019-21 / PD-F019-22 — Help / tests

**Status:** **NOT_A_POLICY_DECISION**

Engineering/docs tasks during hardening (fix “upload” wording; add API-path / bulk / atomicity tests per gap matrix).

---

## Blocking decisions

**None remaining** for product policy. Hardening may implement DECIDED targets (especially PD-F019-01, 14, 02/03, 15, 18).

---

## Product-owner confirmation log

| Date | Action |
|------|--------|
| 2026-08-09 | Analyst recommendations published (awaiting confirmation) |
| 2026-08-09 | Product owner confirmed: PD-01 B, PD-14 B, PD-02/03 E′, PD-15 all-or-nothing UX, PD-05 keep CURRENT dates, PD-06 preserve order |

---

*End of F019 policy decisions.*  
*Policies closed 2026-08-09 → READY_FOR_IMPLEMENTATION.*
