# Execution Engine Specification

  Field            Value
  ---------------- --------------------------------
  **Document**     Execution Engine Specification
  **Version**      1.0 Draft
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   Recommendation Engine

------------------------------------------------------------------------

# 1. Introduction

The Execution Engine records and manages the execution of
recommendations. In Version 1, execution is user-driven and the engine
acts as the system of record for portfolio transactions. Future versions
may support broker integrations and automated execution.

# 2. Purpose

Convert accepted recommendations into tracked portfolio transactions
while maintaining complete auditability and portfolio consistency.

# 3. Goals

The Execution Engine SHALL:

-   Record manual executions.
-   Support future automated execution.
-   Maintain positions.
-   Maintain transaction history.
-   Calculate realized and unrealized position state.
-   Preserve complete execution audit trails.

# 4. Non Goals

The Execution Engine SHALL NOT:

-   Generate recommendations.
-   Evaluate trading strategies.
-   Fetch market data.
-   Deliver notifications.

# 5. Responsibilities

-   Order Recording
-   Transaction Management
-   Position Management
-   Portfolio Updates
-   Execution Audit Trail

# 6. Domain Model

## Order

Represents a user intent to buy or sell.

Attributes:

-   Order ID
-   Recommendation ID (optional)
-   Symbol
-   Side (Buy/Sell)
-   Quantity
-   Order Type
-   Status
-   Created Time
-   Executed Time

## Transaction

Represents an executed trade.

Attributes:

-   Transaction ID
-   Order ID
-   Execution Price
-   Quantity
-   Charges
-   Timestamp

## Position

Represents the current holding for a security.

Attributes:

-   Symbol
-   Quantity
-   Average Cost
-   Current Status
-   Opened Time
-   Closed Time (optional)

# 7. Inputs

-   Recommendations
-   User execution actions
-   Broker confirmations (future)
-   Execution configuration

# 8. Outputs

-   Updated Positions
-   Transaction History
-   Portfolio Events
-   Execution Reports

# 9. Business Workflow

1.  Select recommendation
2.  Record execution intent
3.  Validate request
4.  Create order
5.  Record transaction
6.  Update position
7.  Publish execution event

# 10. Business Rules

**EX-001** Every transaction SHALL belong to exactly one order.

**EX-002** Every order SHALL reference at most one recommendation.

**EX-003** Position quantities SHALL always equal the sum of executed
transactions.

**EX-004** Executed transactions SHALL be immutable.

**EX-005** Every execution SHALL be auditable.

# 11. State Model

Order:

Created → Pending → Executed

or

Created → Cancelled

or

Created → Rejected

# 12. Failure Handling

-   Reject invalid executions.
-   Preserve portfolio consistency.
-   Record validation failures.
-   Never create partial transactions.

# 13. Configuration

-   Supported order types
-   Brokerage settings
-   Trading fees
-   Default position sizing
-   Logging

# 14. Public Interfaces

-   Record Order
-   Record Transaction
-   Query Positions
-   Query Orders
-   Query Transactions

# 15. Dependencies

Depends on:

-   Recommendation Engine

Provides services to:

-   Review Engine

# 16. Acceptance Criteria

-   Orders are recorded correctly.
-   Positions remain consistent.
-   Transactions are immutable.
-   Audit history is complete.
-   Portfolio state is reproducible.

# 17. Future Scope

-   Zerodha API integration
-   Multi-broker support
-   Automated execution
-   Partial fills
-   Stop-loss and target orders
-   GTT orders

# 18. Implementation Notes for Cursor

-   Keep broker integrations behind an adapter interface.
-   Separate order, transaction and position services.
-   Preserve immutability of executed trades.
-   Design for future multi-broker support.
-   Do not embed recommendation logic in this engine.
