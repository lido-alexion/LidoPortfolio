# V2.1 Workstream B — Financial Integrity & Authorization Audit

**Date:** 2026-08-10  
**Status:** **HARDENING COMPLETE** (audit + implementation)  
**Phase:** V2.1 Product Hardening  
**Predecessor:** WS-A COMPLETE ([`WS-A-TEST-BASELINE-CLEANUP.md`](./WS-A-TEST-BASELINE-CLEANUP.md)); programme audit [`V2.1-PRODUCT-HARDENING-AUDIT.md`](./V2.1-PRODUCT-HARDENING-AUDIT.md)  
**Constraint:** Do not reopen V2 initiatives; do not invent feature IDs; do not start V3.

---

## 0. Implementation resolution (2026-08-10)

| ID | Resolution |
|----|------------|
| **WSB-D1** | **FIXED** — `TransactionWriteService::update` / `updateFinancialUnit`: reverse prior cash → update ledger → holdings/realizations → apply new cash in one `DB::transaction`. Controller delegates to write service. |
| **WSB-D2** | **FIXED** — `TransactionWriteService::delete` / `deleteFinancialUnit`: cash reverse + optional TOS revert callback + ledger delete + holdings/realizations in one `DB::transaction`. Snapshots post-commit. |
| **WSB-D4** | **PO DECISION: SOFT reservation preserved** — Manual/F019 buys use **balance** (not available). Reserved cash remains workflow state for approve/withdraw/capital allocation. Regression tests lock soft semantics. **No hard-reservation conversion.** |
| **WSB-D5** | **FIXED** — `completeRecommendationFromTransaction` rejects already-executed recommendations when `executed_transaction_id` differs; same tx id remains idempotent. |
| **WSB-D3** | **CLOSED (low-risk)** — Manual fill with `recommendation_id` now runs `createFinancialUnit` + `completeRecommendationFromTransaction` inside one outer `DB::transaction` in `TransactionController::store`. |

### Tests added / changed

- **New:** `app/tests/Feature/FinancialIntegrityHardeningTest.php` (13 tests) — D1/D2/D4/D5 + AuthZ scoping  
- **Updated:** `app/tests/Feature/TransactionUpdateTest.php` — create via write service + cash seed; assert cash after update/delete  

### Regression results

| Suite | Result |
|-------|--------|
| Targeted financial / update / bulk / pipeline filters | Passed during development |
| Full `php -d memory_limit=512M vendor/bin/phpunit` | **692 / 692 passed**, 0 failed, 0 errors, 0 risky |

### Remaining non-blockers

- Retrospective Cash SPEC/BOUNDARY/POLICY pack → **WS-C**  
- Spec table vs §6 “adjustment reason required” wording → **WS-C**  
- Broader dedicated `CashManagementTest` for deposit/adjust edge cases → optional P2  
- No schema changes required  

### Confirmation

- F019 create/bulk contract unchanged (shared write path extended for update/delete)  
- No V2 initiative reopened; no new feature IDs; no V3 work  

---

## 1. Executive summary (audit baseline)

Cash Management (SD-026) and the F019 financial write unit are **real and largely coherent** for the primary create path (UI create, bulk import, TOS order execute). Soft cash reservations exist on recommendation rows and correctly gate **approve** and **withdraw**. Profile isolation for cash/transactions relies on `activePortfolio()` + scoped route binding and appears sound for cross-user access.

The green suite from WS-A **did not prove** financial integrity end-to-end. The audit found **concrete correctness defects** on secondary paths (now fixed above) and test gaps (partially closed by `FinancialIntegrityHardeningTest`).

| Severity | Finding | Post-hardening |
|----------|---------|----------------|
| **DEFECT** | Transaction **update** without cash sync | **FIXED (WSB-D1)** |
| **DEFECT** | Transaction **delete** non-atomic | **FIXED (WSB-D2)** |
| **DEFECT (policy)** | Soft reservations not enforced on buys | **CONFIRMED intentional (WSB-D4 PO)** |
| **DEFECT (edge)** | Execution overwrite with different tx id | **FIXED (WSB-D5)** |
| **TEST GAP** | Thin cash/reservation coverage | **Improved** (WS-B suite); more optional |
| **SPEC GAP** | No V2-style cash pack | **WS-C candidate** |

---

## 2. Cash Management lifecycle

### 2.1 Runtime inventory

| Artifact | Path |
|----------|------|
| Service | `app/app/Services/CashManagementService.php` |
| Controller | `app/app/Http/Controllers/Api/CashController.php` |
| Models | `CashAccount`, `CashLedgerEntry` |
| Tables | `portfolio_cash_accounts`, `portfolio_cash_ledger_entries` |
| Spec | `specs/architecture/portfolio/Cash-Management-Specification.md` |
| UI | `/cash` → `CashManagementPage.jsx` |
| V2 pack | **None** |

### 2.2 Source of truth

| Concept | SoT | Notes |
|---------|-----|-------|
| Cash balance | Cached `portfolio_cash_accounts.balance` | Updated only via `CashManagementService::post()` under `lockForUpdate` |
| Ledger audit | `portfolio_cash_ledger_entries` | Append-only; types: deposit, withdrawal, adjustment, buy, sell |
| Reserved cash | **Derived** | Sum of `TradingRecommendation.reserved_amount` where status ∈ pending_execution\|accepted **and** `reservation_status=reserved` |
| Available investable | `max(0, balance − reserved)` | Used by withdraw + approve |

Balance is **not** recomputed from ledger on read. Ledger + balance stay consistent only if all mutations go through `post()`.

### 2.3 Path matrix

| Path | Mechanism | Atomic w/ ledger+holdings? | Negative cash? | Double-apply risk | Profile scoped? |
|------|-----------|----------------------------|----------------|-------------------|------------------|
| **A. Deposit** | `CashController` → `deposit` → `post(+)` | N/A (cash-only) | Rejected by `post` | Separate posts = separate entries (intentional) | `activePortfolio()` |
| **B. Withdraw** | `withdraw` gated by **available** | N/A | Rejected | Same | Yes |
| **C. BUY transaction** | `TransactionWriteService::createFinancialUnit` → `applyTradeTransaction` | **Yes** on create | Rejected if balance &lt; 0 | Create unit is once; update path desyncs (see defects) | Yes |
| **D. SELL transaction** | Same with `TYPE_SELL` | **Yes** on create | N/A (credit) | Same | Yes |
| **E. Failed BUY** | `post` throws → outer create/bulk rolls back | Yes (create/bulk) | Prevented | N/A | Yes |
| **F. Failed SELL** | Holdings/oversell validation may fail before/during unit | Create yes | N/A | N/A | Yes |
| **G. Transaction rollback** | Create/bulk: DB rollback | Yes | N/A | N/A | Yes |
| **H. Bulk CSV** | `BulkTransactionImportService::commit` outer txn + per-row `createFinancialUnit` | **Yes** (F019) | Preflight on **balance** (not available) | `batch_key` idempotent | Yes |
| **I. TOS / execution** | Manual fill: create then `completeRecommendationFromTransaction`; order path: same outer txn as convert | Create unit yes; convert **after** create on manual path | Balance check only | Partial race (see §3) | Yes |
| **J. Reservation** | Soft fields on recommendation; no cash debit | Approve in lifecycle txn | N/A | Approve checks available | Yes |
| **K. Portfolio switch** | `X-Profile-Id` / `X-Portfolio-Id` / `portfolio_id` | N/A | N/A | N/A | Same-user switch; cross-user 404 |

### 2.4 Answers to audit questions

| Question | Finding |
|----------|---------|
| Can cash become negative unintentionally? | **No** via `post()` (balance floored / rejected). Available display floors at 0 even if reserved &gt; balance after soft overspend. |
| Can cash be applied twice? | Create path: one buy/sell ledger entry per transaction. **Update** does not re-apply cash (worse: never adjusts). Delete reverse then recreate can apply again intentionally. |
| Failed op leave partial financial state? | **Create/bulk: no.** **Delete: yes risk** (non-atomic steps). **Update: silent desync.** |
| Retry duplicate economic effect? | Bulk `batch_key` protected. Manual retries create new txs. |
| Manual adjustments distinguishable? | Yes — `entry_type=adjustment`, optional reason, `user_id`, `entry_date`. Spec table says reason required; §6 and API treat remarks as optional (**SPEC GAP**). |
| Scoped to active portfolio? | **Yes** — no client `profile_id` body on cash APIs. |
| Concurrent safety? | Account `lockForUpdate` in `post`. Soft reservations have no row lock across approve vs buy race. Sufficient for single-operator MVP; not multi-writer hardened. |

---

## 3. Reservation / pending-trade lifecycle

### 3.1 Do reservations exist?

**Yes — soft reservations**, not a separate cash hold table.

Fields on `portfolio_tos_recommendations` (migration `2026_07_25_000007_*`):

- `reserved_amount`, `reservation_status` (`none|reserved|released|converted`), `reserved_at`, `executed_amount`
- Snapshot fields at generation: `cash_balance_at_generation`, `reserved_cash_at_generation`, `available_cash_at_generation`

**Balance is not reduced** when cash is reserved. Reserved is only subtracted when computing **available** and when gating **withdraw** / **approve**.

### 3.2 Trace

```text
Generate (pipeline)
  → records cash snapshot fields; reserved_amount=0

Approve (RecommendationLifecycleService::recordReview)
  → status=pending_execution
  → reserveForApproval (same DB::transaction)
  → fails if suggested amount > availableInvestableCash

Pending Execution UI (/transactions/pending)

Execute via POST /api/transactions + recommendation_id
  → TransactionWriteService::create (financial unit + cash buy/sell)
  → THEN ExecutionEngine::completeRecommendationFromTransaction
       → convertReservation + status=executed
  (two-phase: money commits before reservation convert)

Execute via ExecutionEngine::executeOrder
  → createFinancialUnit + convertReservation in ONE DB::transaction (stronger)

Cancel / markExpired / reopen
  → releaseReservation inside lifecycle DB::transaction

Delete linked fill
  → reverseTradeTransaction
  → revertLinkedFillBeforeTransactionDelete (may reserveForApproval again)
  → delete + holdings recalc
```

Sell-side approvals **do not** reserve cash (`requiresCashReservation()` is buy-side only).

### 3.3 Lifecycle questions

| Question | Answer |
|----------|--------|
| Reservations exist? | **Yes** (soft, on recommendation) |
| Reserved / available calc? | Sum reserved pending buys; available = max(0, balance − reserved) |
| Cancel releases? | **Yes** (`releaseReservation`) |
| Failed execution releases? | Manual fill: if create fails, no convert. If create succeeds and complete fails, cash already applied — reservation may remain until retry/complete (**edge**) |
| Successful execution consumes once? | Convert sets status converted + reserved_amount=0; same tx id is idempotent |
| Duplicate execution possible? | Controller blocks non-pending; **edge DEFECT** if already executed with different `executed_transaction_id` (complete path can overwrite) |
| Profile isolation? | `forProfile` + lifecycle profile_id checks |
| Stale reservations? | `expireStale()` only touches pre-approval statuses (`STALE_OPEN_STATUSES`), **not** pending_execution — **NO GAP** for reserved cash math. Manual `markExpired` releases. |

### 3.4 Soft reservation vs buys (policy defect)

`applyTradeTransaction` / bulk cash simulation check **balance ≥ 0**, **not** available investable cash.

**Consequence:** Operator can Approve buy A (reserves ₹X), then manually buy or bulk-import spends that reduce balance below reserved — available goes to 0; withdraw blocked; but economic over-commitment vs pending intent is possible.

Spec emphasizes available for approve/withdraw/capital allocation. It does **not** explicitly forbid manual buys against reserved cash. Classify as **DEFECT (policy mismatch)** pending product confirmation:

- If soft hold is intentional → DOCUMENTATION/SPEC GAP (document “soft reservation”) + TEST GAP  
- If reserved must be hard → DEFECT fix: gate buy/bulk on available (or debit on reserve)

**Recommendation for WS-B implementation review:** Prefer documenting current soft behaviour in WS-C unless product requires hard holds in V2.1 (hard holds are a behaviour change — treat carefully).

---

## 4. Financial atomicity findings

### 4.1 Create path (strength)

`TransactionWriteService` PD-F019-14:

1. Outer `DB::transaction` → insert + holdings/realizations + cash `post` (nested savepoint)  
2. Post-commit: OHLCV backfill + snapshot rebuild (best-effort; must not undo money)

Bulk: outer txn + per-row `createFinancialUnit`; preflight simulation; `batch_key` idempotency.

**Create / bulk:** ledger + holdings + cash stay aligned on success/failure. **NO GAP** for that path (covered by `BulkTransactionImportTest`).

### 4.2 Derived vs financial state

| Layer | Integrity requirement |
|-------|----------------------|
| Financial (ledger, cash, holdings qty/cost, realizations) | Must be atomic on create |
| Derived (OHLCV, portfolio snapshots) | Best-effort after commit — failure ≠ cash corruption |

### 4.3 Defects / risks

| ID | Finding | Class | Impact |
|----|---------|-------|--------|
| **WSB-D1** | `TransactionController::update` updates ledger + holdings/realizations **only** — **no cash reverse/re-apply** | **DEFECT** | Edit price/qty/type → cash balance wrong vs ledger economics |
| **WSB-D2** | `TransactionController::destroy` sequence not wrapped in one `DB::transaction` | **DEFECT** | Failure after `reverseTradeTransaction` can leave cash reversed with ledger row still present (or mid TOS revert) |
| **WSB-D3** | Manual fill: cash create commits **before** `convertReservation` | **TECHNICAL DEBT** / edge | Rare incomplete TOS state if complete throws after create |
| **WSB-D4** | Soft reserved cash spendable by buys/bulk | **DEFECT (policy)** | Over-commit vs pending approvals |
| **WSB-D5** | `completeRecommendationFromTransaction` when already `executed` with **different** tx id skips the throw and may overwrite | **DEFECT** | Duplicate/wrong execution linkage |

Corporate-action bonus inserts via write path without cash are intentional (zero cash delta).

---

## 5. Profile / AuthZ findings

### 5.1 Model

`ResolveActivePortfolio` middleware:

1. Authenticated user required for portfolio resolution  
2. Profile id from `X-Profile-Id` | `X-Portfolio-Id` | `portfolio_id` query  
3. Must belong to current user → else **404**  
4. Else default portfolio  
5. Sets `request.attributes.active_portfolio` → `\activePortfolio()`

Cash, transactions, holdings, snapshots, historical holdings, bulk import, TOS recommendation ops use **active portfolio only** — no body `profile_id` for money writes.

### 5.2 Checks

| Scenario | Result | Class |
|----------|--------|-------|
| Same user, different profiles | Header switches portfolio — **by design** | **NO GAP** |
| Different users, foreign profile id | 404 | **NO GAP** |
| Direct transaction id of another user | Route binding scoped to active portfolio → 404 (`TransactionUpdateTest`) | **NO GAP** |
| Missing profile header | Default portfolio | **NO GAP** |
| Invalid profile header | 404 | **NO GAP** |
| Admin cross-portfolio cash access | **Not found** on cash/tx paths | **NO GAP** |
| Recommendation id foreign to profile | Validation / `forProfile` | **NO GAP** (covered in store) |

### 5.3 Residual AuthZ gaps

| ID | Finding | Class |
|----|---------|-------|
| **WSB-A1** | No automated cash API cross-profile tests | **TEST GAP (P1)** |
| **WSB-A2** | Snapshot/holdings isolation tested partially; cash summary not | **TEST GAP (P1)** |
| **WSB-A3** | F144 note AuthZ etc. remain V2 non-blockers — out of WS-B money core | **TECHNICAL DEBT** / optional |

**No RBAC/multi-tenant expansion recommended.** Existing profile model is adequate if regression tests are added.

---

## 6. Test coverage matrix

| Invariant | Existing coverage | Gap class |
|-----------|-------------------|-----------|
| Create: ledger+cash atomic / insufficient cash rolls back | `BulkTransactionImportTest` | **NO GAP** (create) |
| Bulk all-or-nothing / batch idempotency | `BulkTransactionImportTest` | **NO GAP** |
| Approve reserves; cash API shows reserved | `TradingOsPipelineTest` | **Partial** — expand assertions |
| Cancel/expire releases reserved → available restored | Indirect only | **TEST GAP (P0/P1)** |
| Withdraw ≤ available (cannot drain reserved) | **Missing** | **TEST GAP (P0)** |
| Deposit / adjust / cannot go negative | **Missing** dedicated | **TEST GAP (P1)** |
| Adjust reason/audit fields | **Missing** | **TEST GAP (P2)** / SPEC |
| Approve fails when amount &gt; available | Service logic; thin feature assert | **TEST GAP (P1)** |
| Soft reservation vs manual buy | **Missing** | **TEST GAP (P0)** (documents current or desired) |
| Update transaction keeps cash synced | `TransactionUpdateTest` asserts price only — **cash not checked** | **TEST GAP** proving **WSB-D1** |
| Delete atomicity cash+ledger+TOS | Delete tests without cash seed/assert | **TEST GAP** for **WSB-D2** |
| Concurrent double-execute | **Missing** | **TEST GAP (P2)** / hard under SQLite |
| Cross-profile cash isolation | **Missing** | **TEST GAP (P1)** |
| Dedicated CashManagement unit/feature suite | **Absent** | **TEST GAP (P0)** |

---

## 7. Defects found (stop-before-fix)

Per instructions: **no code changes** until review. Proposed fixes are recommendations only.

### WSB-D1 — Transaction update ignores cash

| Field | Detail |
|-------|--------|
| Path | `PUT /api/transactions/{id}` → `TransactionController::update` |
| Impact | Changing price/qty/fees/type desynchronizes cash balance vs economic ledger |
| Proposed fix | Reverse prior trade cash (if buy/sell entry exists) + re-apply under one `DB::transaction` with holdings/realizations; or forbid economic field edits and force delete+recreate |
| Tests | Feature: deposit → buy → update price → assert cash delta; type flip buy↔sell |

### WSB-D2 — Transaction delete non-atomic

| Field | Detail |
|-------|--------|
| Path | `DELETE /api/transactions/{id}` |
| Impact | Partial failure after cash reverse leaves inconsistent money vs ledger |
| Proposed fix | Wrap reverse + TOS revert + delete + holdings/realizations in one `DB::transaction`; keep snapshot rebuild post-commit |
| Tests | Force failure mid-path (mock) or assert all-or-nothing with cash seeded txs |

### WSB-D3 — Manual execute two-phase convert

| Field | Detail |
|-------|--------|
| Path | `TransactionController::store` then `completeRecommendationFromTransaction` |
| Impact | Low probability incomplete reservation convert |
| Proposed fix | Align with `executeOrder`: convert inside same txn as create when `recommendation_id` present (via write service extension) |
| Class | Prefer **TECHNICAL DEBT** unless reproduceable; elevate if product requires |

### WSB-D4 — Soft reservation not enforced on buys

| Field | Detail |
|-------|--------|
| Path | `applyTradeTransaction`, bulk cash simulation |
| Impact | Pending reserved capital can be spent |
| Proposed fix | **Product decision first.** Options: (A) document soft holds; (B) gate buys on available when any reservations exist; (C) hard debit on reserve |
| Tests | Approve reserve → manual buy attempting to spend reserved → expect 422 **if** hard policy |

### WSB-D5 — Complete overwrite when already executed

| Field | Detail |
|-------|--------|
| Path | `ExecutionEngine::completeRecommendationFromTransaction` |
| Impact | Wrong `executed_transaction_id` / double completion edge |
| Proposed fix | If status=executed and tx id differs → throw validation; never overwrite |
| Tests | Complete twice with different transaction ids → second fails |

---

## 8. Hardening candidates (WS-B implementation)

Ordered for a future implementation pass (after review):

1. **Regression suite first** — `CashManagementTest` / feature tests for deposit, withdraw≤available, negative reject, approve reserve, cancel release (even before product fixes) — **TEST GAP**  
2. **WSB-D1** update cash sync or edit lock — **DEFECT**  
3. **WSB-D2** atomic delete — **DEFECT**  
4. **WSB-D5** executed overwrite guard — **DEFECT**  
5. **WSB-D4** product decision + tests/docs — **DEFECT/policy**  
6. Optional **WSB-D3** unify manual fill txn — **TECHNICAL DEBT**  
7. Cross-profile cash AuthZ tests — **TEST GAP**

Do **not** redesign capital allocation, recommendation generation, or introduce hard multi-writer concurrency framework in V2.1.

---

## 9. Retrospective-spec candidates (WS-C)

| Candidate | Why |
|-----------|-----|
| **Cash Management** V2.1 CURRENT pack (SPEC/BOUNDARY/POLICY/GAP) | Highest-value informal critical surface; arch spec exists but no BOUNDARY/POLICY discipline |
| **Reservation / pending-execution** semantics (soft vs hard) | Clarify intended hold model |
| **Portfolio snapshots (F015)** vs ledger/cash | Distinct from F014; snapshot rebuild post-commit |
| **Dashboard financial figures** | Value/cash/growth composition |
| Spec fix: Cash-Management table “reason required” vs §6 “remarks optional” | Internal contradiction |

**Do not create these packs in WS-B audit.**

---

## 10. Explicit V2.1 vs V3 boundary

| In V2.1 (WS-B / WS-C) | Out of V2.1 → V3 / backlog |
|-----------------------|----------------------------|
| Fix proven cash/ledger desync (update/delete) | Hard dataset publish gates |
| Atomic delete; executed overwrite guard | Broker cash reconciliation |
| Tests for money invariants + AuthZ | Multi-channel notifications |
| Document soft reservation **or** product-approved hard hold | Deep Position Review |
| Retrospective Cash/Snapshots packs (WS-C) | F014 cash-as-of / export |
| | Strategy→Evaluation param wiring |
| | New feature IDs / new capabilities |

---

## 11. Recommended implementation order

After human review of §7:

| Step | Work | Class |
|------|------|-------|
| B1 | Add dedicated cash + reservation regression tests (document current soft behaviour explicitly in tests) | TEST GAP |
| B2 | Fix **WSB-D1** (update cash) | DEFECT |
| B3 | Fix **WSB-D2** (atomic delete) | DEFECT |
| B4 | Fix **WSB-D5** (executed overwrite) | DEFECT |
| B5 | Product decision on **WSB-D4**; implement or document | DEFECT/policy |
| B6 | Optional unify manual fill convert (**WSB-D3**) | TECHNICAL DEBT |
| B7 | Hand Cash/Snapshots retrospective packs to **WS-C** | SPEC GAP |
| B8 | Re-run full suite (`512M`); update WS-B completion note | Validation |

---

## 12. Confirmation

| Item | Status |
|------|--------|
| Application code modified | **No** |
| Tests / schema / frontend modified | **No** |
| V2 initiative packs modified | **No** |
| New feature IDs | **None** |
| V3 work started | **No** |
| Severe defects found requiring review before fix | **Yes** — WSB-D1, WSB-D2, WSB-D4 (policy), WSB-D5 |

---

*Index: `DOCS.md` §3.M · Living note: `implementation.md`*
