# 05 -- Daily Decision Pipeline

  -----------------------------------------------------------------------
  Field                               Value
  ----------------------------------- -----------------------------------
  **Document**                        05 -- Daily Decision Pipeline

  **Version**                         0.1

  **Status**                          Draft

  **Owner**                           Architecture

  **Depends On**                      01 -- Vision, 02 -- Guiding
                                      Principles, 03 -- Core Concepts, 04
                                      -- System Architecture
  -----------------------------------------------------------------------

------------------------------------------------------------------------

# 1. Purpose

This document defines the operational heartbeat of the Trading Operating
System.

It specifies the sequence of business activities performed during a
trading cycle. Every engine, feature and future enhancement must
participate in this pipeline or explicitly justify why it exists outside
it.

------------------------------------------------------------------------

# 2. Design Principles

-   The pipeline is deterministic.
-   Each stage has a single owner.
-   Every stage produces explicit outputs.
-   A stage may consume outputs only from earlier stages.
-   Each stage should be independently executable and testable.
-   Every stage should leave an auditable trail.

------------------------------------------------------------------------

# 3. Pipeline Overview

``` text
Market Data Sync
        │
        ▼
Data Validation
        │
        ▼
Derived Calculations
        │
        ▼
Pattern Discovery
        │
        ▼
Screening
        │
        ▼
Candidate Generation
        │
        ▼
Evaluation & Ranking
        │
        ▼
Position Review
        │
        ▼
Recommendation Generation
        │
        ▼
Notification Delivery
        │
        ▼
User Review
        │
        ▼
Trade Execution
        │
        ▼
Trade Recording
        │
        ▼
Performance Review
```

------------------------------------------------------------------------

# 4. Stage Specifications

## Stage 1 --- Market Data Sync

**Owner:** Data Engine

### Purpose

Acquire the latest market data required for analysis.

### Inputs

-   External market data sources

### Outputs

-   Updated OHLCV data
-   Updated static metadata (when scheduled)

------------------------------------------------------------------------

## Stage 2 --- Data Validation

**Owner:** Data Engine

### Purpose

Ensure market data is complete, consistent and suitable for downstream
processing.

### Outputs

-   Validated dataset
-   Validation log

------------------------------------------------------------------------

## Stage 3 --- Derived Calculations

**Owner:** Evaluation Engine

### Purpose

Calculate deterministic indicators and metrics required by downstream
engines.

### Examples

-   Moving averages
-   Relative Strength
-   ATR
-   RSI
-   Trend metrics

### Outputs

-   Indicator values

------------------------------------------------------------------------

## Stage 4 --- Pattern Discovery

**Owner:** Discovery Engine

### Purpose

Identify deterministic chart patterns and signals.

### Outputs

-   Patterns
-   Signals

------------------------------------------------------------------------

## Stage 5 --- Screening

**Owner:** Discovery Engine

### Purpose

Apply strategy screening rules to the market universe.

### Outputs

-   Screen results

------------------------------------------------------------------------

## Stage 6 --- Candidate Generation

**Owner:** Discovery Engine

### Purpose

Produce candidates worthy of detailed evaluation.

### Outputs

-   Candidate list

------------------------------------------------------------------------

## Stage 7 --- Evaluation & Ranking

**Owner:** Evaluation Engine

### Purpose

Compare candidates using deterministic scoring and risk assessment.

### Outputs

-   Rankings
-   Scores
-   Risk metrics

------------------------------------------------------------------------

## Stage 8 --- Position Review

**Owner:** Recommendation Engine

### Purpose

Review existing positions alongside new opportunities.

Typical considerations include:

-   Stop-loss conditions
-   Exit conditions
-   Position health
-   Allocation impact

### Outputs

-   Position assessments

------------------------------------------------------------------------

## Stage 9 --- Recommendation Generation

**Owner:** Recommendation Engine

### Purpose

Transform evidence into actionable recommendations.

### Recommendation Types

-   BUY
-   SELL
-   WATCH
-   HOLD

Each recommendation must include supporting evidence.

------------------------------------------------------------------------

## Stage 10 --- Notification Delivery

**Owner:** Notification Engine

### Purpose

Notify the user that action or review is required.

Current channel:

-   Telegram

Future channels may be added without changing business logic.

------------------------------------------------------------------------

## Stage 11 --- User Review

**Owner:** User

### Purpose

Review recommendations and supporting evidence within the application.

Possible outcomes:

-   Accept
-   Reject
-   Defer

------------------------------------------------------------------------

## Stage 12 --- Trade Execution

**Owner:** Execution Engine

### Purpose

Execute approved trades.

Current mode:

-   Manual execution via broker

Future mode:

-   Automated execution

------------------------------------------------------------------------

## Stage 13 --- Trade Recording

**Owner:** Execution Engine

### Purpose

Maintain an immutable record of executed trades and position changes.

Outputs:

-   Orders
-   Transactions
-   Updated positions

------------------------------------------------------------------------

## Stage 14 --- Performance Review

**Owner:** Review Engine

### Purpose

Evaluate outcomes and support continuous improvement.

Examples include:

-   Strategy performance
-   Recommendation quality
-   Trade reviews
-   Historical analysis

------------------------------------------------------------------------

# 5. Architectural Rules

-   Stages execute in defined order.
-   Downstream stages must not modify upstream outputs.
-   Recommendations are created only after evaluation.
-   Notifications never create recommendations.
-   Reviews never modify historical facts.

------------------------------------------------------------------------

# 6. Evolution

As automation increases, only the execution stages change.

The analytical stages remain identical, preserving explainability and
user trust.

------------------------------------------------------------------------

# 7. Summary

The Daily Decision Pipeline is the central workflow of the Trading
Operating System. Every business capability should either contribute to
this pipeline, consume its outputs, or improve its effectiveness.
