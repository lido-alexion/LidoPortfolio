# Cash Management — Policy Decisions (Retrospective)

**Document:** V2.1 Retrospective POLICY register  
**Date:** 2026-08-10  
**Status:** CURRENT formalization  
**Companion:** [`CASH-MANAGEMENT-SPEC.md`](./CASH-MANAGEMENT-SPEC.md), [`CASH-MANAGEMENT-BOUNDARY.md`](./CASH-MANAGEMENT-BOUNDARY.md)

**Rules:** Do not invent decisions. Mark ambiguous items `DECISION_REQUIRED`. Reference WS-B / SD-026 / F019 where already decided.

Status values: **DECIDED** | **CURRENT** | **DECISION_REQUIRED** | **DEFERRED** | **OOS** | **TECHNICAL DEBT**

---

## Register

| ID | Topic | Status | Decision / CURRENT behaviour | Evidence |
|----|-------|--------|------------------------------|----------|
| **CM-01** | Cash balance source of truth | **CURRENT** | Cached `portfolio_cash_accounts.balance` updated only via `post()`; ledger is append-only audit with `balance_after` | `CashManagementService::post` |
| **CM-02** | Ledger vs balance | **CURRENT** | Mutations must go through `post()`; balance not recomputed on read from full ledger scan | Same |
| **CM-03** | Deposit semantics | **CURRENT** | Amount &gt; 0; whole rupees at Cash API; remarks optional; entry date ≤ today | `CashController`, `deposit` |
| **CM-04** | Withdrawal semantics | **CURRENT** | Amount &gt; 0; cannot exceed **available investable** (= balance − reserved); remarks optional | `withdraw` |
| **CM-05** | Adjustment semantics | **CURRENT** | Signed non-zero; whole rupees at API; **no** available gate; remarks optional | `adjust` |
| **CM-06** | Adjustment reason required? | **DECISION_REQUIRED** | Arch SPEC table says reason required; §6 + runtime treat remarks optional | Spec contradiction vs code |
| **CM-07** | Negative balance | **CURRENT** | Rejected (`post` validation); balance floored at 0 | `post()` |
| **CM-08** | Trade cash timing | **CURRENT** | Buy/sell cash posts inside financial unit with ledger + holdings | `TransactionWriteService` |
| **CM-09** | Transaction update cash | **DECIDED** (WS-B D1) | Reverse prior trade cash + apply new economics in one DB txn | WS-B §0; `updateFinancialUnit` |
| **CM-10** | Transaction delete cash | **DECIDED** (WS-B D2) | Reverse + TOS revert + delete + holdings in one DB txn | WS-B §0; `deleteFinancialUnit` |
| **CM-11** | Soft cash reservation | **DECIDED** (WS-B D4 PO) | Reserved does **not** block ordinary/manual/F019 buys if **balance** sufficient; reserved remains workflow state; withdraw/approve still use available | WS-B PO; `FinancialIntegrityHardeningTest` D4 |
| **CM-12** | Available cash terminology | **CURRENT** | `available_investable_cash = max(0, balance − reserved)` for summary, withdraw, approve, capital allocation — **not** the gate for ordinary buys | Service + generation pipeline |
| **CM-13** | Reserve on approve | **CURRENT** | Buy-side actionable only; fails if suggested amount &gt; available | `reserveForApproval` |
| **CM-14** | Release / convert | **CURRENT** | Cancel/expire/reopen → release; execute → convert (amount recorded; balance already reduced by buy post) | Lifecycle + ExecutionEngine |
| **CM-15** | Manual fill atomicity | **DECIDED** (WS-B D3) | Create financial unit + complete recommendation in one outer DB txn when `recommendation_id` set | `TransactionController::store` |
| **CM-16** | Execution overwrite | **DECIDED** (WS-B D5) | Already executed by tx X → reject different tx Y; same X idempotent | `completeRecommendationFromTransaction` |
| **CM-17** | Profile isolation | **CURRENT** | Cash scoped to `activePortfolio()`; cross-user 404 | Middleware + tests |
| **CM-18** | Cash statement | **CURRENT** | Recent ledger list (limit ≤ 100); no export | `ledger` API |
| **CM-19** | Historical cash-as-of | **OOS** | Not in CURRENT Cash; F014 deferred cash-as-of | F014 OOS; inventory |
| **CM-20** | Broker reconciliation | **OOS** / **DEFERRED** | Internal operator cash only (SD-026 §7 future) | Arch SPEC |
| **CM-21** | Hard reservation | **OOS** | Explicitly not converting to hard holds | WS-B D4 PO |
| **CM-22** | Duplicate cash movements | **CURRENT** | Each `post` is a new ledger row; bulk `batch_id` idempotent for imports; no deposit idempotency key | Runtime |
| **CM-23** | Correction / undo | **CURRENT** | Adjust or opposite movement; trade edit/delete | Runtime |
| **CM-24** | Concurrency | **CURRENT** / **TECHNICAL DEBT** | Account `lockForUpdate` in `post`; soft reserve vs buy race not multi-writer hardened | Audit WS-B |
| **CM-25** | Precision | **CURRENT** | Storage 4 dp; Cash API whole rupees | Controller + schema |
| **CM-26** | F019 financial unit | **DECIDED** (PD-F019-14) | Shared create path atomic ledger+holdings+cash | F019 POLICY |
| **CM-27** | Reconstruct balance from ledger alone | **NOT REQUIRED CURRENT** | Operational SoT is account balance; recomputation not implemented | — |

---

## Soft reservation (canonical statement)

**Status: DECIDED (Product Owner via WS-B, 2026-08-10)**

1. Reservations are **soft**: approving a buy sets recommendation reservation fields; **cash account balance is not reduced**.  
2. **Ordinary / manual / F019 buys** succeed when **actual cash balance** can fund the trade (balance after delta ≥ 0), even if that consumes capital shown as reserved.  
3. **Withdrawals** and **recommendation approve** continue to use **available investable cash** (balance − reserved).  
4. Capital allocation at generation uses available investable cash.  
5. Do **not** implement hard escrow without a new product decision.

---

## Open items

| ID | Ask | Notes |
|----|-----|-------|
| CM-06 | Should adjust **require** non-empty reason? | Align arch table vs runtime/UI |
| — | Should help text explicitly say manual buys use balance, not available? | Documentation polish (TECHNICAL DEBT / help sync) |

No other blocking `DECISION_REQUIRED` items for documenting CURRENT behaviour.

---

## Out of scope (do not decide here)

- Broker cash import  
- Hard reservation redesign  
- Cash-as-of analytics  
- Multi-currency  
- Absorbing F014/F015/F019 product ownership  
