# Cash Management — Retrospective CURRENT Specification

**Document:** V2.1 Retrospective CURRENT Spec  
**Location:** `docs/v2.1/CASH-MANAGEMENT-SPEC.md`  
**Date:** 2026-08-10  
**Status:** CURRENT (runtime formalization — not a new feature)  
**Related:** SD-026 architecture [`specs/architecture/portfolio/Cash-Management-Specification.md`](../../specs/architecture/portfolio/Cash-Management-Specification.md); WS-B [`WS-B-FINANCIAL-AUTHZ-AUDIT.md`](./WS-B-FINANCIAL-AUTHZ-AUDIT.md); inventory [`WS-C-SHADOW-FEATURE-INVENTORY.md`](./WS-C-SHADOW-FEATURE-INVENTORY.md)  

**Not an F-number.** Descriptive V2.1 pack only. Do not reopen F019/F014/F137.

---

## 1. Purpose

Formalize the **CURRENT** Cash Management runtime so operators, tests, and future hardening share one authoritative description of:

- portfolio cash balance and ledger  
- soft recommendation reservations  
- integration with the transaction financial unit (post–WS-B)  
- profile isolation  

This documents **what ships today**, not a redesign.

---

## 2. Current capability

| Area | Status |
|------|--------|
| Per-profile cash account | **IMPLEMENTED** |
| Append-only cash ledger (deposit/withdrawal/adjustment/buy/sell) | **IMPLEMENTED** |
| Deposit / withdraw / adjust APIs + Cash UI | **IMPLEMENTED** |
| Trade cash apply on create/update/delete | **IMPLEMENTED** (WS-B D1/D2) |
| Soft reservations on approve (buy-side) | **IMPLEMENTED** (WS-B D4 PO) |
| Reserved / available summary + reservation drilldown | **IMPLEMENTED** |
| Cash statement (recent ledger) | **IMPLEMENTED** (limit 1–100) |
| Cash-as-of historical reconstruction | **NOT IMPLEMENTED** (F014 OOS) |
| Broker reconciliation / auto import of broker cash | **NOT IMPLEMENTED** |
| Hard cash holds (debit on reserve) | **NOT IMPLEMENTED** (explicitly rejected) |
| Dedicated undo of deposit/withdraw rows | **NOT IMPLEMENTED** (use adjust or opposite movement) |

---

## 3. User workflows (CURRENT)

### 3.1 Cash page (`/cash`)

1. Operator opens **Cash** (active portfolio).  
2. UI loads `GET /api/cash?include_reservations=1` and `GET /api/cash/ledger?limit=100`.  
3. Shows **balance**, **reserved**, **available investable**, expandable reservation list, statement.  
4. Deposit / Withdraw / Adjust: whole-rupee `NumberInput`, optional remarks, transaction date (calendar, ≤ today).  
5. Success refreshes summary/ledger and notifies dashboard refresh.

### 3.2 Initial cash / deposit

- `POST /api/cash/deposit` → `CashManagementService::deposit` → `post(+amount, TYPE_DEPOSIT)`.  
- Amount must be &gt; 0; API forces whole rupees.  
- Remarks optional. Entry date optional (defaults today).

### 3.3 Withdrawal

- `POST /api/cash/withdraw` → gated by **`availableInvestableCash`** (= balance − reserved), not raw balance.  
- Soft reservation therefore **does** reduce how much can be withdrawn while buys remain pending.

### 3.4 Manual adjustment

- `POST /api/cash/adjust` with signed non-zero whole-rupee amount.  
- **No** available-cash gate (can reduce balance even if reserved &gt; remaining available, subject to balance ≥ 0 after post).  
- Remarks optional in CURRENT runtime (architecture table historically said “reason required” — see POLICY).

### 3.5 BUY / SELL transactions

- Create via `TransactionWriteService::createFinancialUnit` → `applyTradeTransaction`.  
- Buy: cash delta `−(qty×price + fees)`.  
- Sell: cash delta `+(qty×price − fees)`.  
- Gate: resulting **balance ≥ 0** (not available). Soft reserved cash may be spent by ordinary buys.

### 3.6 Transaction UPDATE (post–WS-B)

- `TransactionWriteService::updateFinancialUnit`: reverse prior buy/sell cash entry → update ledger → holdings/realizations → apply new cash — **one DB transaction**.  
- Insufficient cash → entire update rolls back.

### 3.7 Transaction DELETE (post–WS-B)

- `TransactionWriteService::deleteFinancialUnit`: reverse cash → optional TOS revert → delete ledger → holdings/realizations — **one DB transaction**.  
- Snapshots rebuild post-commit (best-effort).

### 3.8 F019 bulk

- Preflight simulates cash against **balance** (not available).  
- Commit uses shared `createFinancialUnit` per row inside outer batch transaction.  
- Soft reservation does **not** block bulk buys if balance suffices (WS-B D4).

### 3.9 Recommendation reservation / approval / execution

| Step | Behaviour |
|------|-----------|
| Generate | Snapshot fields `cash_*_at_generation`; no reserve |
| Approve buy (OPEN/INCREASE) | `reserveForApproval` if suggested amount ≤ **available**; soft fields on recommendation |
| Manual fill + `recommendation_id` | Create financial unit + `completeRecommendationFromTransaction` in one outer txn (WS-B D3); `convertReservation` |
| Cancel / markExpired / reopen | `releaseReservation` |
| Delete linked fill | Cash reverse + may re-reserve on revert to pending |

### 3.10 Insufficient / negative cash

- `post()` rejects if `balance + signedAmount < −0.0001`.  
- Balance stored as `max(0, newBalance)`.  
- **Negative account balance: not allowed.**

### 3.11 Profile switching / AuthZ

- All cash APIs use `\activePortfolio()` only.  
- Cross-user foreign profile header → 404.  
- Same-user other portfolio → switch by design.  
- No admin cash bypass found.

### 3.12 Cash statement / history

- `GET /api/cash/ledger` — recent entries ordered by `entry_date` desc, `id` desc.  
- **NOT FOUND:** full pagination, export, cash-as-of report, reconstruct balance solely from ledger without account row (balance is cached; ledger is audit trail).

### 3.13 Reconciliation / correction

- Operator uses **Adjust** or opposite deposit/withdraw.  
- Trade corrections: edit/delete transaction (cash reverse/reapply).  
- **NOT FOUND:** automated broker cash sync.

---

## 4. Cash lifecycle (diagram)

```text
ensureAccount(profile)
    │
    ├─ deposit / withdraw / adjust ──► post() ──► ledger row + balance
    │
    ├─ trade create/update/delete ──► apply / reverse ──► post()
    │
    └─ recommendation approve ──► reserved_amount (soft; no balance debit)
            │
            ├─ execute ──► convertReservation + buy ledger post
            └─ cancel/expire/reopen ──► releaseReservation
```

---

## 5. Balance calculation (CURRENT)

| Concept | Formula / source |
|---------|------------------|
| **Cash balance** | Cached `portfolio_cash_accounts.balance`; updated only via `CashManagementService::post()` under `lockForUpdate` |
| **Reserved cash** | Sum `reserved_amount` where recommendation `pending_execution|accepted` **and** `reservation_status=reserved` |
| **Available investable** | `max(0, balance − reserved)` |

**Important terminology (soft reservation):**

- **Available** is used for **withdraw**, **approve**, and **capital allocation**.  
- **Ordinary/manual/F019 buys** use **balance ≥ 0** after the trade delta — they are **not** blocked solely because reserved &gt; 0 (WS-B D4 PO).

Help copy that says available funds “new recommendations” is aligned with allocation; it must not be read as blocking manual buys.

---

## 6. Cash movements

| `entry_type` | Sign | Trigger |
|--------------|------|---------|
| `deposit` | + | Cash UI / API |
| `withdrawal` | − | Cash UI / API (≤ available) |
| `adjustment` | ± | Cash UI / API; also reverse-on-delete posts adjustment opposite of buy/sell |
| `buy` | − | Trade apply |
| `sell` | + | Trade apply |

Ledger is **append-only** (no update/delete of ledger rows in CURRENT).

---

## 7–9. Deposits, withdrawals, adjustments

See §3.2–3.4. Precision: API rounds to whole rupees for all three ops; service stores decimal(18,4).

---

## 10. Trade integration

Owned mutation path: **`TransactionWriteService`** (create / update / delete financial units).  
Cash Management supplies `applyTradeTransaction` / `reverseTradeTransaction` / `post`.

Post–WS-B invariants:

- Create/update/delete keep ledger ↔ holdings/realizations ↔ cash consistent inside one DB transaction.  
- OHLCV / snapshots remain post-commit best-effort.

---

## 11. Reservation integration

- Soft fields on `portfolio_tos_recommendations` — **not** a separate reservation table.  
- Does **not** debit `portfolio_cash_accounts` on reserve.  
- Lifecycle: reserve → convert | release (see BOUNDARY / POLICY).

---

## 12. Atomicity

| Path | Boundary |
|------|----------|
| Manual deposit/withdraw/adjust | Single `post()` DB transaction + account lock |
| Trade create/update/delete | Outer write-service DB transaction (cash nested savepoint) |
| F019 bulk | Outer batch txn + per-row financial unit |
| Approve + reserve | Lifecycle DB transaction |
| Manual fill + complete | Outer store txn (WS-B D3) |

---

## 13. Profile / AuthZ

- One cash account per `portfolio_profiles` row (`profile_id` unique).  
- Cascade delete with profile.  
- Active portfolio middleware; transaction route binding scoped to active profile.

---

## 14. Error behaviour

| Condition | Result |
|-----------|--------|
| Deposit/withdraw amount ≤ 0 | 422 |
| Withdraw &gt; available | 422 |
| Adjust amount = 0 | 422 |
| Non-whole rupee from Cash API | 422 |
| Trade would make balance &lt; 0 | 422; financial unit rolls back |
| Update insufficient cash | 422; no partial ledger/cash |
| Foreign profile cash | 404 via middleware |

---

## 15. Precision / rounding

- Account/ledger: decimal 18,4.  
- Service posts round signed amounts to 4 dp; available/reserved round to 4 dp.  
- Cash UI API: whole-rupee amounts.  
- Trade deltas: `qty×price` then ± fees, round 4 dp.

---

## 16. Current limitations

1. Balance is cached — not re-derived on every read from ledger (integrity assumes all mutations use `post`).  
2. Soft reservation can leave `reserved &gt; balance` after aggressive buys; available floors at 0.  
3. Ledger list capped (100); no export.  
4. No cash-as-of / F014 cash column.  
5. Adjust reason not enforced.  
6. No dedicated `CashManagementTest` class (coverage via feature suites).  
7. Concurrent multi-writer beyond account lock not hardened for multi-operator.

---

## 17. Explicit out of scope (CURRENT product)

- Hard reservations  
- Broker cash sync / Zerodha  
- Cash-as-of historical holdings (F014 OOS)  
- Multi-currency  
- New notification channels for cash events  
- Absorbing F019 import orchestration or F014 reconstruction  

---

## 18. Test coverage summary

| Area | Coverage (CURRENT) |
|------|--------------------|
| Deposit (API/service seed) | Used widely; thin dedicated asserts |
| Withdraw ≤ available + soft buy | `FinancialIntegrityHardeningTest` (D4) |
| Adjust | **TEST GAP** (dedicated) |
| Buy/sell create cash | Bulk + write-service tests |
| Update/delete cash sync + atomicity | WS-B `FinancialIntegrityHardeningTest`, `TransactionUpdateTest` |
| Bulk cash rollback / balance gate | `BulkTransactionImportTest` |
| Reserve on approve / cancel release | `TradingOsPipelineTest`, D4 tests |
| Insufficient cash | Bulk + D1 update test |
| Profile isolation | D1 update 404; D5 cross-profile complete |
| Execution overwrite | D5 tests |

See GAP matrix for detail.

---

## Runtime inventory (quick reference)

| Layer | Paths |
|-------|-------|
| UI | `CashManagementPage.jsx` → `/cash` |
| API | `CashController` — `/api/cash*` |
| Service | `CashManagementService` |
| Models | `CashAccount`, `CashLedgerEntry` |
| Migrations | `2026_07_25_000007_*`, `2026_07_25_000008_cash_ledger_entry_date.php` |
| Trade | `TransactionWriteService`, `TransactionController` |
| Reservation | `RecommendationLifecycleService` |
| Help | `appDocumentation.js` topic `cash` |
