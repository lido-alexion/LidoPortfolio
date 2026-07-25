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
├── Transaction (reference via recommendation_id — not merged)
├── Order (legacy / optional BC)
└── Review Report
```

**SD-025:** Recommendation and Transaction are separate entities. A
transaction **references** a recommendation (`recommendation_id`); they
are not the same record. Approval lives on Recommendation; fill lives on
Transaction.
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

Portfolio-aware decision composed of:

- Market Opinion (direction, strength, confidence, evidence)
- Portfolio Decision (OPEN / INCREASE / REDUCE / EXIT / HOLD / WATCH)
- Execution Plan (actionable decisions only)

Relationship: - References one Evaluation Result.

May be referenced by zero or one completed Transaction
(`recommendation_id` on the transaction).

Flow: Market Opinion → Portfolio Decision → Execution Plan (optional) →
User Approval → Pending Execution → Transaction (optional)

Owned By: - Recommendation Engine

------------------------------------------------------------------------

## Order

Legacy / optional intent record (BC APIs). Not required for V1.0 manual
execution path (SD-025).

Relationship: - References zero or one Recommendation.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Transaction

Executed trade in the portfolio ledger.

Attributes (TOS-relevant): `source`, `recommendation_id` (nullable).

Relationship:

- Derived holdings / Position
- Optionally references one Recommendation (does not absorb it)

Owned By: - Execution Engine (writes via shared ledger services; legacy
Transactions Module is the primary UI/API for create)

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
(Pending Execution) → Transaction → Position → Review Report

(Order is optional/legacy and not on the primary path — SD-025.)

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
Recommendation Generated → Notification Sent → Recommendation Approved →
Transaction Recorded → Position Updated → Review Generated

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
