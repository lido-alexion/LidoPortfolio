# Data Engine Specification

  Field            Value
  ---------------- -------------------------------
  **Document**     Data Engine Specification
  **Version**      1.0 Draft
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   Architecture Documents 01--06

------------------------------------------------------------------------

# 1. Introduction

The Data Engine is the foundational engine of the Trading Operating
System.

Its responsibility is to acquire, maintain and publish trusted market
data for all downstream engines.

The Data Engine is intentionally isolated from business decisions. It
neither evaluates stocks nor generates recommendations. Its sole
responsibility is ensuring that every downstream engine operates on
accurate, complete and deterministic market data.

# 2. Purpose

The purpose of the Data Engine is to establish a **single source of
truth** for all market-related information used by the Trading Operating
System.

Every downstream calculation, recommendation and trade decision depends
on the correctness of this engine.

# 3. Goals

The Data Engine SHALL:

-   Acquire historical OHLCV market data.
-   Acquire daily OHLCV updates.
-   Maintain a canonical market dataset.
-   Maintain stock master information.
-   Maintain static metadata.
-   Validate incoming datasets.
-   Detect missing trading sessions.
-   Detect duplicate records.
-   Publish trusted datasets.
-   Maintain complete import history.
-   Preserve deterministic behaviour.

# 4. Non Goals

The Data Engine SHALL NOT:

-   Calculate indicators.
-   Detect chart patterns.
-   Evaluate trading rules.
-   Generate candidates.
-   Generate rankings.
-   Generate recommendations.
-   Execute trades.
-   Send notifications directly (except via system events).

# 5. Responsibilities

The Data Engine owns the complete lifecycle of market data.

It is responsible for:

-   Market Universe
-   Security Master
-   Historical Market Data
-   Static Metadata
-   Trading Calendar
-   Import History
-   Data Quality

# 6. Domain Model

## Security

Represents one tradable instrument.

Lifecycle:

Created → Active → Suspended (optional) → Delisted

## Trading Session

Represents one market day.

Contains:

-   Date
-   Exchange
-   Open Status
-   Close Status

## Price Bar

Represents one OHLCV record.

Unique Key:

(Security, Trading Session)

## Market Dataset

Represents the complete validated collection of market information
available after a successful import.

Only validated datasets are visible to downstream engines.

## Import Job

Lifecycle:

Created → Running → Validated → Published → Archived

or

Created → Running → Failed

# 7. Inputs

External:

-   Historical OHLCV Provider
-   Daily OHLCV Provider
-   Static Metadata Imports
-   Trading Holiday Calendar

Internal:

-   Engine Configuration
-   Import Schedules

# 8. Outputs

-   Trusted Market Dataset
-   Stock Master
-   Trading Calendar
-   Metadata
-   Import Status
-   Data Quality Report

System Events:

-   MarketDataUpdated
-   ImportCompleted
-   ImportFailed
-   MetadataUpdated

# 9. Business Workflow

1.  Start Import
2.  Acquire Raw Data
3.  Validate Format
4.  Validate Trading Sessions
5.  Detect Missing Days
6.  Detect Duplicate Records
7.  Normalize
8.  Persist
9.  Validate Dataset
10. Publish Dataset
11. Generate Import Report
12. Publish Completion Event

# 10. Business Rules

**DR-001** Every security shall have exactly one active identity.

**DR-002** A trading session shall be unique per exchange and date.

**DR-003** A price bar shall be unique per security per trading session.

**DR-004** Invalid datasets shall never be published.

**DR-005** Historical market data shall be immutable except through an
explicit reconciliation process.

**DR-006** Every published dataset shall be reproducible.

**DR-007** Every import shall be auditable.

**DR-008** Every downstream engine shall consume only published
datasets.

# 11. State Model

Import Job:

-   Created
-   Running
-   Validating
-   Published
-   Archived

Failure Path:

-   Created
-   Running
-   Failed

# 12. Failure Handling

If acquisition or validation fails:

-   Abort import.
-   Preserve previous published dataset.
-   Record failure.
-   Publish failure event.

Partial datasets must never be exposed.

# 13. Configuration

-   Import Schedule
-   Supported Exchanges
-   Metadata Refresh Interval
-   Maximum Retry Count
-   Validation Rules
-   Retention Policy

# 14. Public Interfaces

Business capabilities:

-   Trigger Import
-   Publish Dataset
-   Query Dataset Status
-   Query Trading Calendar
-   Query Metadata Version
-   Query Import History

# 15. Dependencies

Depends on:

-   External Market Data Providers

Consumed by:

-   Discovery Engine
-   Evaluation Engine
-   Recommendation Engine
-   Review Engine

# 16. Acceptance Criteria

The Data Engine is complete when:

-   Historical imports succeed.
-   Daily imports succeed.
-   Invalid datasets never reach downstream engines.
-   Duplicate records are prevented.
-   Missing sessions are detected.
-   Import history is maintained.
-   Every dataset is version identifiable.
-   All business rules are satisfied.

# 17. Future Scope

-   Multiple providers
-   Automatic reconciliation
-   Corporate actions
-   Intraday data
-   ETFs
-   Mutual funds
-   Futures & Options
-   Crypto

# 18. Implementation Notes for Cursor

-   Keep the Data Engine isolated from trading logic.
-   Do not implement recommendation logic here.
-   Implement incrementally.
-   Add comprehensive logging.
-   Make components independently testable.
-   Treat all business rules as mandatory requirements.
