# 06 -- Engine Overview

  Field            Value
  ---------------- -------------------------------
  **Document**     06 -- Engine Overview
  **Version**      0.1
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   01--05 Architecture Documents

------------------------------------------------------------------------

# 1. Purpose

This document provides a high-level overview of every business engine
within the Trading Operating System.

Its purpose is to define ownership boundaries. Detailed behaviour will
be specified in each engine's dedicated specification.

------------------------------------------------------------------------

# 2. Engine Design Principles

Every engine shall:

-   Own a single business capability.
-   Own the lifecycle of its business concepts.
-   Publish outputs without knowledge of downstream consumers.
-   Avoid duplicate business logic.
-   Be independently testable.

------------------------------------------------------------------------

# 3. Engine Summary

  ------------------------------------------------------------------------------
  Engine           Primary Responsibility                      Owns
  ---------------- ------------------------------------------- -----------------
  Data Engine      Acquire and maintain market data            Market Data

  Discovery Engine Discover trading opportunities              Patterns,
                                                               Signals,
                                                               Candidates

  Evaluation       Evaluate and score opportunities            Indicators,
  Engine                                                       Rankings, Scores,
                                                               Rules

  Recommendation   Convert analysis into decisions             Evidence,
  Engine                                                       Recommendations

  Notification     Deliver information to the user             Notifications
  Engine                                                       

  Execution Engine Execute and record trades                   Orders,
                                                               Positions,
                                                               Transactions

  Review Engine    Analyse outcomes                            Reviews,
                                                               Performance
                                                               Analytics
  ------------------------------------------------------------------------------

------------------------------------------------------------------------

# 4. Data Engine

## Mission

Provide a trusted, validated market dataset for every downstream engine.

## Inputs

-   External market data
-   Static reference data

## Outputs

-   Validated market dataset

## Consumers

All downstream engines.

------------------------------------------------------------------------

# 5. Discovery Engine

## Mission

Identify opportunities from the market universe.

## Responsibilities

-   Pattern detection
-   Signal generation
-   Screening
-   Candidate generation

## Outputs

-   Candidates
-   Patterns
-   Signals

------------------------------------------------------------------------

# 6. Evaluation Engine

## Mission

Measure opportunity quality objectively.

## Responsibilities

-   Indicator calculation
-   Rule evaluation
-   Risk assessment
-   Ranking
-   Scoring

## Outputs

-   Rankings
-   Scores
-   Risk metrics

------------------------------------------------------------------------

# 7. Recommendation Engine

## Mission

Produce explainable trading decisions.

## Responsibilities

-   Recommendation generation
-   Evidence aggregation
-   Recommendation lifecycle

## Outputs

-   BUY
-   SELL
-   WATCH
-   HOLD

------------------------------------------------------------------------

# 8. Notification Engine

## Mission

Ensure the user is informed at the appropriate time through the
appropriate channel.

## Responsibilities

-   Notification policy
-   Delivery
-   History

Current channel:

-   Telegram

------------------------------------------------------------------------

# 9. Execution Engine

## Mission

Manage the complete trading lifecycle.

## Responsibilities

-   Order management
-   Position management
-   Transaction recording
-   Broker integration

------------------------------------------------------------------------

# 10. Review Engine

## Mission

Enable continuous improvement.

## Responsibilities

-   Strategy review
-   Trade review
-   Recommendation review
-   Performance analysis

------------------------------------------------------------------------

# 11. Engine Interaction Rules

-   Data flows only downstream.
-   Engines communicate through owned business concepts.
-   Engines do not modify concepts owned by other engines.
-   User interfaces orchestrate engine outputs but do not contain
    business logic.
-   Review is observational and never alters historical facts.

------------------------------------------------------------------------

# 12. Engine Roadmap

The engines should be specified and implemented in the following order:

1.  Data Engine
2.  Discovery Engine
3.  Evaluation Engine
4.  Recommendation Engine
5.  Notification Engine
6.  Execution Engine
7.  Review Engine

Each engine specification shall include:

-   Overview
-   Responsibilities
-   Inputs
-   Outputs
-   Workflow
-   Business Rules
-   State Model
-   Configuration
-   Acceptance Criteria
-   Future Scope

------------------------------------------------------------------------

# 13. Summary

The Trading Operating System is composed of independent business engines
with clearly defined ownership boundaries. These engines form the stable
core of the application and provide the foundation for all future
features and implementations.

------------------------------------------------------------------------

# 14. Cross-cutting: Indicator Registry (SD-033)

Indicator **metadata and discovery** are unified in the Indicator Registry
(see [../indicators/09-Indicator-Registry.md](../indicators/09-Indicator-Registry.md) and
[../engines/Indicator-Registry-Specification.md](../engines/Indicator-Registry-Specification.md)).

Engines retain calculation ownership:

| Concern | Owner |
|---------|-------|
| Primary OHLCV indicators | Data path + `TechnicalIndicatorService` (used by Screener / Evaluation / Market) |
| Stock Strategy composite facts | Evaluation Engine |
| Market-level composites | Market Analysis Engine |
| Descriptive Metrics | Analytics services (SD-031) |
| Metadata / Admin discovery | Indicator Registry |

This is an evolution of existing catalogues — not a new business engine in the
pipeline sense.

------------------------------------------------------------------------

# 15. Cross-cutting: Trading Artifact Framework (SD-034)

Reusable definitions — **Indicators**, **Screeners**, **Strategies** — share a
common artifact envelope (metadata, lifecycle, versioning, validation,
import/export, dependencies) under the Trading Artifact Framework.

See [../indicators/11-Trading-Artifact-Framework.md](../indicators/11-Trading-Artifact-Framework.md) and
[../engines/Trading-Artifact-Framework-Specification.md](../engines/Trading-Artifact-Framework-Specification.md).

| Concern | Owner |
|---------|-------|
| Shared envelope / package I/O / dep graph | Artifact Registry (umbrella) |
| Indicator metadata | Indicator Registry (SD-033 specialization) |
| Screener definitions (`definition_json`) | Screener module + Screener Artifact Registry |
| Strategy definitions (`config_json`) | Strategy Configuration + Strategy Artifact Registry |
| Runtime eligibility / scoring / calc | Unchanged engine/service owners |

Not a pipeline stage engine — a platform framework over existing capabilities.
“Strategy Template” is absorbed as factory/imported Strategy artifacts.
