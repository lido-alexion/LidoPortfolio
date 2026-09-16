# F043 / F042 / F020 Boundary (F043 perspective)

**Date:** 2026-08-09  
**Companion:** [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md)  
**Spec:** [F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](./F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md)

This document restates the approved boundary from the F043 side and corrects one historical wording issue about F020 OHLCV behaviour.

---

## 1. Layer diagram

```text
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  V1 F020    │     │  V2 F042    │     │  V2 F043    │
│  Corporate  │     │  Data       │     │  Price      │
│  Actions    │     │  Quality    │     │  Repair     │
├─────────────┤     ├─────────────┤     ├─────────────┤
│ Ledger apply│     │ Detect      │     │ Discover    │
│ Holdings    │     │ Evidence    │     │ Validate    │
│ Preview/UI  │     │ Resolve     │     │ Preview     │
│ OHLCV on    │     │ Factors     │     │ Apply OHLCV │
│ successful  │     │ Guard       │     │ Audit       │
│ apply*      │     │ Handoff     │     │ Mark done   │
└─────────────┘     └─────────────┘     └─────────────┘
        │                   │                   │
        └───────────────────┴───────────────────┘
                    portfolio_stock_prices
```

\* **Correction:** F020 `CorporateActionService::apply` **does** call `CorporateActionPriceAdjustmentService::adjustHistoricalPrices` and stores `metadata.price_adjustment`. Older boundary text that implied F020 never touches OHLCV is **incorrect relative to current code**. F043 remains the dedicated **ops recovery / formal repair** layer when restatement is missing, incomplete, or driven by F042-accepted factors without a matching applied CA.

---

## 2. F042 responsibilities (must not move into F043)

- Detection (exchange feed + heuristic)
- Evidence append / issue identity
- Accept / reject / auto-accept governance
- Pipeline gating for `pending_review`
- Creating `PriceAdjustmentFactor` with `ohlcv_repair_status = pending`
- **Must not** mutate OHLCV
- **Must not** invoke F043

---

## 3. F043 responsibilities

| Step | Responsibility |
|------|----------------|
| Discover | Pending factors (`pendingOhlcvRepair`) **and** applied F020 CAs needing repair |
| Validate | Divisors, dates, stock, active/pending status, continuity (F020 path) |
| Preview | Non-mutating summary |
| Apply | Mutate historical OHLCV only |
| Audit | CA metadata and/or factor metadata |
| Complete | Set factor `ohlcv_repair_status = completed` |

---

## 4. What F043 MUST NOT do

| Prohibited | Why |
|------------|-----|
| Detect new DQ issues | F042 |
| Accept/reject/auto-accept issues | F042 |
| Change issue status | F042 |
| Create adjustment factors | F042 |
| Re-block pipelines for accepted issues | Would reverse F042 gating policy |
| Mutate transactions / holdings / realizations | F020 |
| Invoke F020 ledger apply as a side effect of repair | Separate product action |
| Silently treat `pending` as `repaired` | Handoff semantics |
| Absorb DualListed NSE purge | Separate ops tool |

---

## 5. Handoff contract (unchanged)

```text
F042 accept
  → PriceAdjustmentFactor (active)
  → metadata: source, detection_method, detection_source,
               ohlcv_repair_status = pending
  → F043 discovers pending factors
  → F043 mutates OHLCV
  → F043 sets ohlcv_repair_status = completed
```

F042 does **not** call F043.  
F043 does **not** call F042 resolution APIs.

---

## 6. Dual input model (CURRENT vs REQUIRED)

## Dual-path input model

| Input | Behaviour |
|-------|-----------|
| Applied `CorporateAction` without matching F042 factor | F020 apply restates OHLCV (unchanged) |
| Active matching `PriceAdjustmentFactor` (pending or completed) | F020 apply **delegates** OHLCV (`deferred_to_factor` metadata); F043 is sole OHLCV writer |
| F043 CA recovery scan with matching factor | `STATUS_DEFERRED_TO_FACTOR` — no CA-path mutation |

Event match: `stock_id` + `effective_ex_date`/`ex_date` + action-type family (`split`↔`split|face_value_split`, `bonus`↔`bonus`) via `PriceAdjustmentFactor::activeOhlcvRepairForEvent`.

---

## 7. Pipeline interaction (design note — do not change F042)

| State | Guard | OHLCV |
|-------|-------|-------|
| DQ `pending_review` | Blocked | Unchanged |
| Accepted + factor `pending` | **Unblocked** | May be wrong |
| Factor `completed` | Unblocked | Restated |

**Accept ≠ repaired.** Ops should run F043 after accept when restatement is needed. Introducing a new “blocked until repaired” state would be a **F042 policy change** and is **out of scope** for F043.

---

## 8. Shared code (allowed)

| Shared | Owner of mutation semantics |
|--------|----------------------------|
| `CorporateActionPriceAdjustmentService` | Shared helper (F020 apply + F043 repair) |
| `portfolio_stock_prices` | Written by sync, F020 apply, F043 repair |
| `PriceAdjustmentFactor` | Written by F042; **read/updated (status)** by F043 |

Sharing the adjustment helper is **not** a boundary violation. F043 owning factor consumption **is** required.

---

## 9. Relationship to existing F042 boundary doc

Prefer this document when resolving F043-side questions. Prefer [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md) for F042-side governance questions. Where they conflict on F020 OHLCV mutation, **trust current `CorporateActionService` code**: F020 does restate prices on apply.

---

*Related: [F042-POLICY-DECISIONS.md](./F042-POLICY-DECISIONS.md), [F042-FINAL-COMPLIANCE-AUDIT.md](./F042-FINAL-COMPLIANCE-AUDIT.md)*
