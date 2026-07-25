# Cash Management Specification

  Field            Value
  ---------------- -------------------------------------
  **Document**     Cash Management Specification
  **Version**      1.0
  **Status**       Active (V1.0 / SD-026)
  **Owner**        Architecture
  **Depends On**   Portfolio Profile, Recommendation Engine
  **Governance**   SD-026

------------------------------------------------------------------------

# 1. Introduction

Cash Management tracks **portfolio cash balance**, **reserved cash** for
approved buy recommendations, and **available investable cash**. It is
the capital constraint for Recommendation Engine capital allocation
(SD-026).

Owned operationally by `CashManagementService`. Reservation fields live
on Recommendation; reserved cash is **derived**, not a separate balance
column.

# 2. Purpose

Provide a ledger-backed cash account per portfolio profile so generation
and approval can reason about real capital without over-committing cash.

# 3. Core Concepts

## Cash Balance

Ledger-backed balance on `portfolio_cash_accounts.balance`. Updated only
via cash ledger posts (deposit, withdrawal, adjustment, buy, sell).

## Reserved Cash

Sum of `reserved_amount` on recommendations in `pending_execution`
(or legacy `accepted`) with `reservation_status = reserved`.

Reserved cash is **not** deducted from the cash account balance until
execution converts the reservation into a buy ledger entry.

## Available Investable Cash

``` text
available_investable_cash = max(0, cash_balance − reserved_cash)
```

Used by Capital Allocation when generating recommendations.

# 4. Cash Ledger

Append-only entries in `portfolio_cash_ledger_entries`. Each entry stores
signed `amount`, `balance_after`, optional `reason`, and optional links
to transaction / recommendation / user.

| Entry type   | Sign     | When |
|--------------|----------|------|
| `deposit`    | +        | Manual deposit |
| `withdrawal` | −        | Manual withdrawal |
| `adjustment` | + or −   | Manual correction (**reason required**) |
| `buy`        | −        | Buy transaction applied to cash |
| `sell`       | +        | Sell transaction applied to cash |

Insufficient balance (resulting balance &lt; 0) is rejected.

# 5. Reservation Lifecycle

Applies to **buy-side** actionable recommendations (OPEN / INCREASE):

| Transition | Effect |
|------------|--------|
| **Approve** → `pending_execution` | `reserveForApproval` — set `reserved_amount`, `reservation_status=reserved`, `reserved_at`. Fails if amount exceeds available investable cash. |
| **Execute** (ledger buy) | `convertReservation` — `reservation_status=converted`; cash ledger `buy` posts; `executed_amount` set. |
| **Cancel / Expire / Reopen** | `releaseReservation` — `reservation_status=released`; reserved cash no longer counted. |

Sell-side approvals do not reserve cash.

# 6. Deposits, Withdrawals, Adjustments

- **Deposit** — amount &gt; 0 (whole rupees in UI); remarks optional; `transaction_date` /
  `entry_date` optional (defaults to today; not future).
- **Withdrawal** — amount &gt; 0; **cannot exceed available investable cash**;
  remarks optional; date as above.
- **Adjustment** — non-zero signed whole-rupee amount; remarks optional; date as above.

# 7. Future Broker Reconciliation

V1.0 cash is **internal** (operator-maintained). Future broker adapters
MAY reconcile broker cash balances into this ledger via adjustments or
dedicated import entries. Reservation and available-cash semantics remain
the same; broker fills convert reservations as today.

# 8. Tables

## `portfolio_cash_accounts`

| Column       | Notes |
|--------------|-------|
| `id`         | PK |
| `profile_id` | Unique FK → `portfolio_profiles` |
| `balance`    | Decimal(18,4), default 0 |
| timestamps   | |

## `portfolio_cash_ledger_entries`

| Column               | Notes |
|----------------------|-------|
| `id`                 | PK |
| `profile_id`         | FK |
| `entry_type`         | deposit / withdrawal / adjustment / buy / sell |
| `amount`             | Signed |
| `balance_after`      | After post |
| `reason`             | Nullable string (optional remarks) |
| `entry_date`         | Business date (defaults to today) |
| `transaction_id`     | Nullable |
| `recommendation_id`  | Nullable |
| `user_id`            | Nullable |
| `created_at`         | |

Recommendation cash/reservation columns (on `portfolio_tos_recommendations`):
`suggested_allocation_amount`, `reserved_amount`, `reservation_status`,
`reserved_at`, `cash_balance_at_generation`, `reserved_cash_at_generation`,
`available_cash_at_generation`, `executed_amount`.

# 9. Public APIs

Legacy `/api` (Sanctum; active portfolio):

  Method   Endpoint                Description
  -------- ----------------------- --------------------------------
  GET      /api/cash               Summary (balance, reserved, available); `?include_reservations=1` adds reservation rows
  GET      /api/cash/reservations  Active reservation details only
  GET      /api/cash/ledger        Recent ledger entries (`limit` 1–100)
  POST     /api/cash/deposit       `{ amount, remarks?, reason?, transaction_date? }`
  POST     /api/cash/withdraw      `{ amount, remarks?, reason?, transaction_date? }` (≤ available cash)
  POST     /api/cash/adjust        `{ amount, remarks?, reason?, transaction_date? }`

Summary shape:

``` json
{
  "cash_balance": 0,
  "reserved_cash": 0,
  "available_investable_cash": 0,
  "reservations": []
}
```

`reservations` is present only when requested. Each item includes
`recommendation_id`, `symbol`, `portfolio_action` / `ui_label`,
`reserved_amount`, optional qty/price, and `reserved_at`.

# 9.1 UI

- **Dashboard** — shows **Available Cash** only (link to Cash management).
- **Cash** tab (`/cash`) — balance / reserved / available, deposit /
  withdraw / adjust (whole-rupee `NumberInput`, optional remarks,
  transaction date via calendar), reservation details, and cash account statement.

# 10. Dependencies

- Portfolio Profile (one cash account per profile)
- Recommendation Engine (allocation + reserve/release/convert)
- Execution / Transactions (buy/sell cash posts; convert on execute)

# 11. Acceptance Criteria

- Balance equals last ledger `balance_after` for the account.
- Available cash excludes reserved buy approvals.
- Approve buy fails when suggested amount exceeds available cash.
- Cancel/expire/reopen releases reserved cash.
- Execute posts buy/sell ledger entries and converts reservation.
