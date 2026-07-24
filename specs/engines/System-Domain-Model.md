# System Domain Model

  Field          Value
  -------------- ---------------------
  **Document**   System Domain Model
  **Version**    1.0 Draft
  **Status**     Draft

------------------------------------------------------------------------

# 1. Purpose

This document defines the canonical business entities of the Trading
Operating System, their ownership, relationships and lifecycle.

Each business entity SHALL have exactly one owning engine.

------------------------------------------------------------------------

# 2. Ownership

  Entity              Owner
  ------------------- -----------------------
  Security            Data Engine
  Trading Session     Data Engine
  Price Bar           Data Engine
  Market Dataset      Data Engine
  Candidate           Discovery Engine
  Evaluation Result   Evaluation Engine
  Recommendation      Recommendation Engine
  Notification        Notification Engine
  Order               Execution Engine
  Transaction         Execution Engine
  Position            Execution Engine
  Review Report       Review Engine

------------------------------------------------------------------------

# 3. Entity Relationships

``` text
Security
│
├── Price Bars
│
├── Candidate
│
├── Evaluation Result
│
├── Recommendation
│
├── Order
│
├── Transaction
│
└── Position

Recommendation
│
├── Notification
├── Order
└── Review Report
```

------------------------------------------------------------------------

# 4. Core Entity Definitions

## Security

Represents a tradable instrument.

Primary Key: - Security ID

Natural Key: - Exchange + Symbol

Owned By: - Data Engine

------------------------------------------------------------------------

## Price Bar

One OHLCV record for one Security on one Trading Session.

Unique Key: (Security ID, Trading Session)

Owned By: - Data Engine

------------------------------------------------------------------------

## Candidate

A Security that satisfies discovery rules.

Owned By: - Discovery Engine

Relationship: - References one Security.

------------------------------------------------------------------------

## Evaluation Result

Analytical assessment of a Candidate.

Relationship: - References one Candidate.

Owned By: - Evaluation Engine

------------------------------------------------------------------------

## Recommendation

Actionable trading decision.

Relationship: - References one Evaluation Result.

Owned By: - Recommendation Engine

------------------------------------------------------------------------

## Order

User intent to execute.

Relationship: - References zero or one Recommendation.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Transaction

Executed trade.

Relationship: - References one Order.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Position

Current holding derived from Transactions.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Notification

Delivery record for recommendations or system events.

Owned By: - Notification Engine

------------------------------------------------------------------------

## Review Report

Historical performance analysis.

Owned By: - Review Engine

------------------------------------------------------------------------

# 5. Lifecycle

Market Data

Security → Price Bar → Candidate → Evaluation Result → Recommendation →
Order → Transaction → Position → Review Report

------------------------------------------------------------------------

# 6. Ownership Rules

DM-001 Every entity SHALL have exactly one owner.

DM-002 Only the owning engine MAY create or modify its entity.

DM-003 Other engines SHALL consume entities through published
interfaces.

DM-004 Cross-engine updates SHALL occur through events or service
interfaces.

DM-005 Historical entities SHALL be immutable once finalized.

------------------------------------------------------------------------

# 7. Event Flow

Data Published → Candidate Generated → Evaluation Completed →
Recommendation Generated → Notification Sent → Order Executed → Position
Updated → Review Generated

------------------------------------------------------------------------

# 8. Future Extensions

-   Strategy
-   Watchlist
-   Portfolio
-   Broker Account
-   Corporate Actions
-   Dividend
-   Split
-   User Preferences

------------------------------------------------------------------------

# 9. Implementation Notes for Cursor

-   Map each entity to a dedicated persistence model.
-   Keep ownership boundaries strict.
-   Avoid circular dependencies between engines.
-   Build APIs around aggregate roots owned by each engine.
-   Preserve referential integrity across relationships.
