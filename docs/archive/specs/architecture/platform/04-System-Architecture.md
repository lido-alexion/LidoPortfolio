# 04 -- System Architecture

  -----------------------------------------------------------------------
  Field                               Value
  ----------------------------------- -----------------------------------
  **Document**                        04 -- System Architecture

  **Version**                         0.1

  **Status**                          Draft

  **Owner**                           Architecture

  **Depends On**                      01 -- Vision, 02 -- Guiding
                                      Principles, 03 -- Core Concepts
  -----------------------------------------------------------------------

------------------------------------------------------------------------

# 1. Purpose

This document defines the high-level business architecture of the
Trading Operating System.

It describes **what major business engines exist, what each engine owns,
and how they collaborate**.

This document intentionally excludes implementation details such as
programming languages, databases, APIs, UI frameworks and deployment.

------------------------------------------------------------------------

# 2. Architectural Philosophy

The application is composed of **business engines**, not UI modules.

Each engine has:

-   A single responsibility
-   Clear ownership
-   Well-defined inputs
-   Well-defined outputs
-   No overlapping business logic

User interfaces consume engines. Engines never depend on user
interfaces.

------------------------------------------------------------------------

# 3. Architectural Diagram

``` text
                 Market Data
                      │
                      ▼
               ┌──────────────┐
               │ Data Engine  │
               └──────────────┘
                      │
          ┌───────────┴───────────┐
          ▼                       ▼
┌──────────────────┐   ┌──────────────────────┐
│ Discovery Engine │   │ Market Analysis      │
└──────────────────┘   │ Engine (benchmark)   │
          │            └──────────┬───────────┘
   Candidates                     │
          │                       │ Market Analytics /
          ▼                       │ Sentiment / Phase
┌──────────────────┐              │
│ Evaluation Engine│              │
└────────┬─────────┘              │
         │                        │
   Stock scores                   │
         │                        │
         └───────────┬────────────┘
                     ▼
         ┌─────────────────────────┐
         │ Recommendation Engine   │
         └─────────────────────────┘
             │                │
             ▼                ▼
   Notification Engine   Execution Engine
             │                │
             ▼                ▼
        Telegram         Broker / Trades
                  \      /
                   ▼    ▼
              Review Engine
```

Market Analysis Engine is orthogonal to Evaluation: market-level vs stock-level.
Dashboard, Strategy, and Portfolio Analytics also consume Market Analysis outputs
directly (not only via Recommendation).

------------------------------------------------------------------------

# 4. Engine Responsibilities

## Data Engine

Owns all market data.

Responsibilities:

-   Market data acquisition
-   Historical storage
-   Static metadata
-   Data quality

Outputs:

-   Clean market dataset

------------------------------------------------------------------------

## Discovery Engine

Discovers opportunities.

Responsibilities:

-   Screeners
-   Pattern detection
-   Signal generation
-   Candidate generation

Outputs:

-   Candidates
-   Signals
-   Patterns

------------------------------------------------------------------------

## Evaluation Engine

Evaluates discovered opportunities **at the stock level**.

Responsibilities:

-   Indicator calculation (per security)
-   Rule evaluation
-   Risk analysis (per security)
-   Ranking
-   Scoring

Outputs:

-   Rankings
-   Scores
-   Risk metrics

Does **not** own market-wide regime, sentiment, or phase (see Market Analysis Engine).

------------------------------------------------------------------------

## Market Analysis Engine

Analyses benchmark index OHLCV and produces reusable **market-level** analytics.

Responsibilities:

-   Trend / momentum / volatility / risk / drawdown / breadth (market)
-   Market Sentiment (0–100, weighted, explainable)
-   Market Phase (categorical, deterministic)
-   Persist historical snapshots

Outputs:

-   Market Analytics payload
-   Sentiment + Phase + explainability
-   Consumer helpers (`allocation_multiplier`, `new_entry_allowed`)

Does **not** know portfolios or recommendations. Consumed by Recommendation,
Strategy, Portfolio Analytics, and Dashboard.

------------------------------------------------------------------------

## Recommendation Engine

Transforms analysis into decisions.

Responsibilities:

-   BUY
-   SELL
-   WATCH
-   HOLD
-   Evidence generation
-   Consume Market Analysis outputs for sizing / entry gates (never recalculate)
-   Recommendation lifecycle

Outputs:

-   Recommendations

------------------------------------------------------------------------

## Notification Engine

Communicates important events.

Responsibilities:

-   Notification policies
-   Telegram delivery
-   Alert scheduling
-   Notification history

Outputs:

-   Notifications

------------------------------------------------------------------------

## Execution Engine

Owns trade execution.

Responsibilities:

-   Manual execution recording
-   Broker integration
-   Order lifecycle
-   Position management
-   Transaction history

Outputs:

-   Positions
-   Transactions
-   Orders

------------------------------------------------------------------------

## Review Engine

Supports continuous improvement.

Responsibilities:

-   Performance review
-   Strategy comparison
-   Recommendation audit
-   Trade review
-   Historical analytics

Outputs:

-   Reports
-   Insights

------------------------------------------------------------------------

# 5. Dependency Rules

Allowed dependency flow:

Data → Discovery → Evaluation → Recommendation → Notification /
Execution → Review

Reverse dependencies are not permitted.

Review Engine never modifies upstream engines.

Notification Engine never creates recommendations.

Execution Engine never evaluates strategies.

------------------------------------------------------------------------

# 6. Ownership Rules

Every business object has one owner.

Examples:

-   Candidates → Discovery Engine
-   Rankings → Evaluation Engine
-   Recommendations → Recommendation Engine
-   Notifications → Notification Engine
-   Orders → Execution Engine

------------------------------------------------------------------------

# 7. Cross-Cutting Concerns

The following concerns apply to all engines:

-   Logging
-   Configuration
-   Explainability
-   Auditing
-   Error handling
-   Versioning

These are supporting capabilities, not business engines.

------------------------------------------------------------------------

# 8. Future Evolution

Future engines may be introduced without violating existing ownership
boundaries.

Examples:

-   Knowledge Engine
-   AI Assistant (non-decision support)
-   Portfolio Analytics Engine
-   Options Engine

New engines must define unique responsibilities and ownership.

------------------------------------------------------------------------

# 9. Summary

The Trading Operating System is organised around independent business
engines with explicit ownership boundaries. This architecture promotes
clarity, deterministic behaviour, testability and long-term
maintainability while remaining independent of implementation
technology.
