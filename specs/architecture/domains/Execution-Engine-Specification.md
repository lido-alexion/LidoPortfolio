# Execution Engine Specification

  Field            Value
  ---------------- --------------------------------
  **Document**     Execution Engine Specification
  **Version**      1.1
  **Status**       Active (V1.0 / SD-025 aligned)
  **Owner**        Architecture
  **Depends On**   Recommendation Engine
  **Governance**   SD-010, SD-021, SD-025

------------------------------------------------------------------------

# 1. Introduction

The Execution Engine records and tracks **trade execution** for approved
recommendations. It is **not** the recommendation approval layer
(Recommendation Engine owns Approve / Reject / Defer).

In Version 1.0, execution is manual: the operator records a ledger
transaction (Transactions page / `POST /api/transactions`) against a
recommendation in `pending_execution`. Future versions may fill the same
queue via broker adapters.

There is **no** dedicated Orders page in V1.0. Legacy `/api/v1/orders*`
endpoints remain for compatibility; primary path is recommendation →
transaction.

# 2. Purpose

Convert **approved** recommendations into tracked portfolio transactions
while maintaining auditability, portfolio consistency, and
recommendation↔transaction traceability.

# 3. Goals

The Execution Engine SHALL:

-   Expose / honour the pending-execution queue.
-   Record manual executions as ledger transactions.
-   Support cancel of pending execution (no trade).
-   Link transactions to recommendations (`recommendation_id`).
-   Maintain positions via existing ledger services.
-   Preserve execution audit trails.
-   Support future automated / broker execution on the same queue.

# 4. Non Goals

The Execution Engine SHALL NOT:

-   Approve, reject, or defer recommendations (Recommendation Engine).
-   Generate recommendations or evaluate strategies.
-   Fetch market data or deliver notifications.
-   Require a separate Orders UI for V1.0.

# 5. Responsibilities

-   Pending Execution (approved recommendations awaiting a fill)
-   Transaction Creation (via shared `TransactionWriteService`)
-   Execution Tracking (recommendation completion + links)
-   Position / portfolio updates (through ledger write path)
-   Future Broker Integration seam
-   Execution audit trail

# 6. Domain Model

## Pending Execution

A recommendation with status `pending_execution` (approved, not yet
traded). Attributes of interest: recommendation ID, symbol, side /
execution plan sizing, approved_at, expiry.

## Transaction

Executed trade in the portfolio ledger (`portfolio_transactions`).

Attributes (execution-relevant):

-   Transaction ID
-   Symbol / stock
-   Side, Quantity, Price, Charges, Timestamp
-   `source` — e.g. `manual`, `tos_recommendation`, future `broker`
-   `recommendation_id` — optional FK-style reference (not a merge)

Recommendation and Transaction remain **separate entities**; the
transaction **references** the recommendation.

## Order (legacy / BC)

Optional intent record used by older `/api/v1/orders` paths. Not required
for the primary SD-025 flow. When used, `execute_now` defaults to
**false** so creating an order does not imply an immediate fill.

## Position

Current holding derived from Transactions (existing portfolio model).

# 7. Inputs

-   Recommendations in `pending_execution`
-   User execution actions (Transactions UI / API)
-   Cancel-execution / expire actions
-   Broker confirmations (future)
-   Execution configuration

# 8. Outputs

-   Ledger transactions with optional `recommendation_id` + `source`
-   Updated positions / holdings
-   Recommendation status → `executed` | `cancelled` | `expired`
-   Portfolio / execution events for Review Engine

# 9. Business Workflow

## Pending Execution

1.  Recommendation Engine sets status `pending_execution` on Approve.
2.  Item appears in pending-execution list (Recommendations UI tab /
    `GET .../pending-execution`).

## Transaction Creation (manual)

1.  Operator opens Transactions page (or API).
2.  Creates transaction with `recommendation_id` of a pending-execution
    recommendation (stock must match).
3.  Shared write service updates holdings.
4.  Execution Engine completes recommendation → `executed`, stores
    `executed_transaction_id` / `executed_at`.

## Cancel Execution

1.  Operator cancels pending execution
    (`POST .../cancel-execution`).
2.  Recommendation → `cancelled` (approved decision will not be traded
    in-system). Does **not** mean “Reject” the original review.

## Execution Tracking

-   Query pending / executed / cancelled outcomes.
-   Deleting a linked ledger transaction returns the recommendation to
    `pending_execution` (undo fill).

## Future Broker Integration

Broker adapters SHALL:

1.  Consume the same pending-execution queue.
2.  Create or attach ledger transactions with `source=broker` (or similar)
    and `recommendation_id`.
3.  Not perform recommendation approval.

# 10. Business Rules

**EX-001** Every executed recommendation SHALL reference exactly one
ledger transaction (`recommendation_id` / `executed_transaction_id`).

**EX-002** A transaction MAY reference at most one recommendation.

**EX-003** Position quantities SHALL equal the sum of ledger
transactions (existing portfolio invariant).

**EX-004** Executed ledger transactions SHALL be immutable (corrections
via delete + re-create / undo path).

**EX-005** Every execution SHALL be auditable.

**EX-006** Approval SHALL NOT create a transaction (SD-025).

**EX-007** Only `pending_execution` recommendations MAY be completed by
transaction create or cancelled via cancel-execution.

# 11. State Model

Recommendation execution outcomes (after approval):

`pending_execution` → `executed` | `cancelled` | `expired`

Legacy Order (BC only):

Pending → Executed | Cancelled

# 12. Failure Handling

-   Reject transaction create if recommendation is not pending execution
    or stock mismatch.
-   Preserve portfolio consistency via shared write service.
-   Never leave recommendation `executed` without a linked transaction
    (and vice versa for TOS-linked fills).

# 13. Configuration

-   Trading fees / charges (existing)
-   Future brokerage adapter settings
-   Logging

# 14. Public Interfaces

-   List Pending Execution (via Recommendation / Execution APIs)
-   Complete From Transaction (`recommendation_id` on create)
-   Cancel Pending Execution
-   Query Positions / Transactions
-   Legacy: Record / Execute / Cancel Order (`execute_now` default false)

# 15. Dependencies

Depends on:

-   Recommendation Engine (approved queue)
-   `TransactionWriteService` / portfolio ledger services

Provides services to:

-   Review Engine
-   Transactions Module (UI/API create path)

# 16. Acceptance Criteria

-   Approve never writes a ledger row by itself.
-   Pending-execution queue is listable and actionable.
-   Manual execute via Transactions + `recommendation_id` works.
-   Cancel execution leaves no trade and marks `cancelled`.
-   Positions remain consistent; audit history complete.
-   No new Orders page required for the happy path.

# 17. Future Scope

-   Zerodha / multi-broker adapters on the same queue
-   Automated execution
-   Partial fills, stop-loss, target, GTT

# 18. Implementation Notes for Cursor

-   Keep broker integrations behind an adapter; fill pending_execution.
-   Prefer `POST /api/transactions` + `recommendation_id` over order UI.
-   Do not embed Approve/Reject/Defer in this engine.
-   Preserve shared ledger write (SD-021).
