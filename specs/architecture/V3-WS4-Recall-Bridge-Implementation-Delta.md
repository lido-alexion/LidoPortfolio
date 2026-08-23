# V3 WS4 — Recall / Capital Resolution / Recall Bridge Loan — Implementation Delta

| Field | Value |
|-------|-------|
| **Status** | Phase 1–3B-2 **implemented**; **gap-closure** (2026-08-21): live capital-resolution wiring, auto bridge lender, immediate fulfilment chain, §6.15 normal-loan good-faith, POST recalls workflow |
| **Spec version** | [`LidoPortfolio-V3-Specification.md`](../LidoPortfolio-V3-Specification.md) **v0.28** (2026-08-21) |
| **Supersedes informal names** | “Soft Loan” → **Recall Bridge Loan**; “Return on stock sale” → **Proceeds from stock sale** |
| **Related** | WS4 Steps 1–7 already shipped (normal lending substrate through voluntary repayment) |

This document is the implementation-ready delta for the next coding pass. It does **not** implement code.

---

## 1. Authoritative rules (summary)

See V3 §6.0–§6.16 and §17.1. Frozen DEP IDs:

| ID | Topic |
|----|--------|
| **DEP-CAPITAL-PRIORITY** | Own → recall → borrow; close rec at actual funded amount |
| **DEP-RECALL-SIZE** | Full = entire outstanding OK; partial = ₹5,000 multiples; not OD-06 |
| **DEP-RECALL-IMMEDIATE-75** | Target 100% of `R`; min immediate 75% of `R`; settle max ≤100% |
| **DEP-RECALL-BRIDGE** | Recall-fulfil only; 10% liquidatable stock cushion; not recallable |
| **DEP-RECALL-FOLLOWUP** | One active recall; non-cancellable; cooldown `floor(period/2)` |
| **DEP-SALE-PROCEEDS** | Proceeds terminology; ~1-day settlement delay |

---

## 2. Reusable existing implementation

| Area | Reuse |
|------|--------|
| Tables/models | `CapitalRequest`, `CapitalLoan`, `CapitalLoanReturn` |
| Accounting | `PortfolioCapitalAccountingService` (`lent_capital` / `borrowed_capital` from `outstanding`) |
| Normal loan create | `CapitalRequestApprovalService`, eligibility + OD-08 ranking |
| Lifecycle | `RecommendationLendingCoordinator`, capital_status path |
| Execution | `CommittedLendingExecutionAmounts`, `ExecutionEngine` fill path |
| Voluntary repay | `CapitalLoanRepaymentService` (any amount ≤ outstanding; lockForUpdate) |
| Partial loan sizing | `PartialLendingAmountCalculator` (normal loans only) |
| Weakest ranking (when built) | OD-16 formula already specified; not coded yet |

Do **not** casually change WS2 accounting formulas or invent a second physical cash pool.

---

## 3. Missing implementation (build next)

### Done (Phase 1–3B-2 + gap-closure)

1. Capital resolution orchestrator (Phase 1) **wired into** `RecommendationLendingCoordinator::syncAfterGenerated` (§6.16).
2–5. Recall domain, sizing, immediate settlement, bridge loans (Phase 1) + **auto bridge lender selection**.
6. Liquidation OD-16 + 0.5% buffer (Phase 2) **chained** from `RecallImmediateSettlementService::apply`.
7. Proceeds settlement delay (Phase 2).
8. Good-faith **bridge** + **normal CapitalLoan** repayment + `portfolio:process-recall-settlements` job.
9. APIs / UI / notifications / help; POST `/capital/recalls` runs full settlement/fulfilment workflow.

### Still open (unrelated / undecided)

10. Optional cash-ledger entry type for loan/recall/bridge movements (undecided).
11. `UnfundedLendingAmountCalculator` (explicitly out of scope for recall gap-closure).

---

## 4. Required schema changes (do not implement yet)

Conceptual additions (exact table names TBD in coding pass):

- `loan_kind` (or separate bridge table): `normal` vs `recall_bridge`
- `capital_recalls` (or equivalent): `loan_id`, `recall_amount`, `kind` (full|partial), `state`, timestamps, `outstanding_recall_amount`
- Unique constraint: at most one active recall per portfolio
- Follow-up cooldown cursor (`last_recall_completed_at` or similar)
- Bridge loan rows: link to `recall_id`; no `min_recall_at`; no ₹5,000 constraint
- Settlement-delay records for expected proceeds availability
- Portfolio recall period already planned; ensure dynamic eligibility reads **current** effective period (do not freeze `min_recall_at` solely from commit-time period if that would contradict dynamic rule — prefer compute from `committed_at` + current effective period)

Existing `min_recall_at` on `CapitalLoan` may become derived/cache or unused once dynamic eligibility is authoritative.

---

## 5. Required services / APIs / jobs

| Component | Responsibility |
|-----------|----------------|
| `CapitalResolutionService` (new) | Own → recall → borrow orchestration for a recommendation |
| `RecallService` (new) | Initiate, state transitions, one-active, non-cancel, cooldown |
| `RecallImmediateSettlementService` (new) | 75%/100% evaluation; settle max available |
| `RecallBridgeLoanService` (new) | Eligibility (cash-first shortfall + 10% cushion); create/repay |
| `RecallLiquidationService` (new) | Weakest-position sells + 0.5% buffer + sequential |
| `SaleProceedsAvailabilityService` (new) | Model ~1-day delay before proceeds usable |
| Extend `CapitalLoanRepaymentService` | Hook auto good-faith repay; distinguish recall settlement vs voluntary |
| Jobs | Daily/session capital resolution; pending recall settlement when proceeds land; bridge repay |
| APIs | List recalls; recall detail/state; bridge loans; portfolio recall settings; lender pending notice |

---

## 6. Required UI changes

- Portfolio settings: recall period override (already specified); remove obsolete “auto-return toggle as primary recall gate” framing — recalls are automated rule-driven.
- Recommendations: show capital-resolution outcome (own / recalled / borrowed / actual invested); do not leave rec open for residual shortfall.
- Lending panel: recall lifecycle, pending/held, bridge loan lines, proceeds settlement status.
- Cash/accounting views: distinguish physical cash, strategy allocation, normal loans, recalls, bridge loans, proceeds from stock sale.

---

## 7. Test scenarios (must cover)

1. Capital priority: own 15k + recall 4k on 20k need → invest 19k and close; later 1k does not reopen.
2. Full recall of non-₹5k outstanding (e.g. 12k) allowed.
3. Partial recall must be ₹5k blocks; algorithm never emits invalid partial.
4. Immediate settle at exactly 75% with own+bridge; settle 90% when 90% available (not clip to 75%).
5. Below 75%: pending_held; no tiny partial; no tiny bridge; liquidation path.
6. Bridge eligibility uses shortfall after own cash; cushion stock ≥ X×1.10; max eligible under cushion.
7. Bridge cannot fund investment; cannot be recalled.
8. One active recall; cancel rejected; follow-up only after complete + `floor(period/2)`.
9. Changing portfolio recall period does not restart loan clocks; eligibility uses current period.
10. Sale proceeds not immediately available; ~1 day delay modelled.
11. Normal repay: 12k outstanding → repay 12k (not 15k); never over principal.
12. Weakest-position sequential liquidation with 0.5% buffer; excess proceeds stay with borrower.
13. Manual / semi / auto execution modes: capital resolution precedes trade path.

---

## 8. Genuine contradictions found (pre-v0.28 vs finalized rules)

| Old (v0.27 / code comments) | New (v0.28) |
|-----------------------------|-------------|
| Lending/recall “never automatic”; default user approval for recall | Lending/recall/bridge/repay are **automated rule-driven**; trade modes remain Manual/Semi/Auto |
| Recall amount sized by OD-06 (`×1.01` ceil ₹5k) | **DEP-RECALL-SIZE**: full may be entire outstanding; partial ₹5k; not OD-06 |
| Spec said repayments “in atomic blocks” | Normal repayment **not** ₹5k multiples (already matched by Step 7 code) |
| Optional auto-return toggle as governance default-off | Good-faith automated repayment/recall lifecycle; toggle framing removed from primary product rule |
| No bridge / 75% / pending_held / proceeds delay | First-class in §6.11–§6.14 |

UNFUNDED loan sizing remains an **unrelated** open implementation gap (`UnfundedLendingAmountCalculator` throws) — not resolved by this deliberation.

---

## 9. Suggested coding sequence

1. Schema for recall + bridge + loan_kind
2. RecallService + eligibility/cooldown
3. Immediate settlement + bridge
4. Pending liquidation + proceeds delay
5. Wire CapitalResolutionService into recommendation pipeline
6. Auto voluntary repay hooks
7. APIs → UI → notifications → help (`appDocumentation.js`) → tests
