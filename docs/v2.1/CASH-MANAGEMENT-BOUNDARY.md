# Cash Management — Boundary

**Document:** V2.1 Retrospective BOUNDARY  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companion:** [`CASH-MANAGEMENT-SPEC.md`](./CASH-MANAGEMENT-SPEC.md)

---

## 1. What Cash Management owns

| Owns | Notes |
|------|-------|
| Per-profile cash account balance | `portfolio_cash_accounts` |
| Cash ledger posts | deposit, withdrawal, adjustment, buy, sell |
| Deposit / withdraw / adjust APIs and Cash UI contract | `/api/cash*`, `/cash` |
| Derived **reserved** and **available investable** summaries | From recommendation reservation fields |
| Reservation **amount math** in summaries / withdraw gate | Does not own recommendation status machine |
| `applyTradeTransaction` / `reverseTradeTransaction` / `post` | Called by write path |
| Soft-reservation **semantics documentation** (with WS-B PO) | Soft = no balance debit on reserve |

---

## 2. What Cash Management does **not** own

| Does not own | Owner |
|--------------|-------|
| Ledger transaction insert/update/delete orchestration | `TransactionWriteService` / `TransactionController` |
| Holdings quantity / avg cost / realizations | `HoldingsCalculationService`, `TransactionRealizationService` |
| F019 CSV parse, batch_id, row validation, import UI | F019 / `BulkTransactionImportService` |
| F014 as-of holdings reconstruction | F014 |
| F015 snapshot rows / equity curve persistence | Snapshot rebuild services |
| Recommendation generate / approve / cancel / expire / reopen | Recommendation lifecycle / engine |
| Preview without persist | F137 |
| Portfolio alert policies | F127 |
| Shared screener AuthZ | F060 |
| OHLCV / DQ / CA price repair | Data / F042 / F043 |
| Broker adapters | OOS / V3 |

---

## 3. TransactionWriteService

**Owns:** Canonical financial unit for trades:

- create / update / delete  
- ordering: ledger + holdings/realizations + cash  
- outer DB transaction boundaries (post–WS-B)  
- post-commit OHLCV/snapshots (non-financial)

**Delegates to Cash:** apply / reverse trade cash effects.

Cash Management must not invent a second trade write path.

---

## 4. F019 Bulk CSV Import

**Owns:** Import orchestration, batch identity, preflight, all-or-nothing commit of **creates**.

**Uses:** Shared `createFinancialUnit` (cash included).

**Does not own:** Cash account schema, deposit/withdraw UI, reservation lifecycle.

Preflight cash simulation uses **balance** (aligned with soft buys).

---

## 5. F014 Historical Holdings

**Owns:** As-of open positions from transaction ledger + valuation.

**Does not own:** Cash-as-of, cash ledger replay as portfolio cash history (explicitly OOS in F014).

Cash Management documentation must **not** claim F014 shows historical cash.

---

## 6. F015 Portfolio Snapshots

**Owns:** Aggregate portfolio value time series / rebuild after transactions.

**Relationship:** Snapshot rebuild is post-commit relative to cash/ledger unit; snapshot failure must not undo cash.

Cash Management does not own snapshot tables.

---

## 7. Recommendation execution / TOS

**Owns:** Recommendation status, reviews, pending execution, convert/release reservation fields, execution completion rules (incl. WS-B D5 overwrite guard).

**Uses cash:** `availableInvestableCash` on approve; convert does not itself post cash (buy ledger post does).

---

## 8. Reservation subsystem

**Location:** Fields on `portfolio_tos_recommendations` + lifecycle methods.

**Nature:** Soft workflow reservation (WS-B D4 PO).

| Component | Responsibility |
|-----------|----------------|
| Lifecycle | Set/clear `reserved_amount`, `reservation_status` |
| CashManagementService | Sum reserved for display/withdraw/approve gates |
| Buys / F019 | Ignore reserved for sufficiency; use balance |

---

## 9. F060 / F127 / F137

| ID | Boundary note |
|----|---------------|
| F060 | No cash coupling |
| F127 | Alerts may display portfolio context; do not mutate cash |
| F137 | Preview must not reserve or post cash |

---

## 10. Deliberate non-ownership summary

Cash Management is **not**:

- a second ledger of stock trades  
- historical holdings analytics  
- import product  
- hard escrow  
- broker reconciliation engine  

Integrations are **call-outs** to shared services, not absorption of those domains.
