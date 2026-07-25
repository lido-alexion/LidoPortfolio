# Evaluation Engine Specification

  Field            Value
  ---------------- ---------------------------------
  **Document**     Evaluation Engine Specification
  **Version**      1.0 Draft
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   Discovery Engine

------------------------------------------------------------------------

# 1. Introduction

The Evaluation Engine analyzes every candidate produced by the Discovery
Engine and transforms it into a ranked opportunity based on objective,
deterministic rules. It is the analytical core of the Trading Operating
System.

# 2. Purpose

Evaluate each candidate using technical, quantitative and business rules
to produce explainable scores and rankings for the Recommendation
Engine.

# 3. Goals

The Evaluation Engine SHALL:

-   Calculate all required indicators.
-   Evaluate every candidate consistently.
-   Produce reproducible scores.
-   Rank candidates.
-   Record supporting evidence.
-   Record failed rules.
-   Generate confidence metrics.

# 4. Non Goals

The Evaluation Engine SHALL NOT:

-   Discover securities.
-   Fetch market data.
-   Generate buy/sell recommendations.
-   Execute trades.
-   Notify users.

# 5. Responsibilities

-   Indicator Calculation
-   Rule Evaluation
-   Relative Strength Analysis
-   Market Regime Assessment
-   Risk Assessment
-   Opportunity Scoring
-   Candidate Ranking
-   Evidence Generation

# 6. Domain Model

## Evaluation Run

Lifecycle:

Created → Running → Completed / Failed

## Evaluation Result

Attributes:

-   Symbol
-   Overall Score
-   Rank
-   Confidence
-   Passed Rules
-   Failed Rules
-   Evidence
-   Evaluation Timestamp

## Indicator

Computed metrics such as:

-   SMA
-   EMA
-   ATR
-   Relative Strength
-   Volume Ratio
-   52 Week High Distance

## Score

A normalized value representing candidate quality.

# 7. Inputs

From Discovery Engine:

-   Candidate List

From Data Engine:

-   Published Dataset
-   Metadata

Configuration:

-   Indicator Parameters
-   Scoring Weights
-   Ranking Rules

# 8. Outputs

-   Ranked Opportunities
-   Evaluation Report
-   Indicator Snapshot
-   Evidence Report
-   Evaluation Event

# 9. Business Workflow

1.  Load candidates
2.  Calculate indicators
3.  Evaluate rules
4.  Assess market regime
5.  Compute scores
6.  Rank opportunities
7.  Generate evidence
8.  Publish ranked list

# 10. Business Rules

**EV-001** Every candidate SHALL be evaluated exactly once per
evaluation run.

**EV-002** Indicator calculations SHALL use only published datasets.

**EV-003** Every score SHALL be reproducible.

**EV-004** Every ranking SHALL be deterministic.

**EV-005** Every evaluation SHALL include explainable evidence.

**EV-006** Failed rules SHALL be retained for audit.

# 11. State Model

Evaluation Run:

Created → Running → Completed

or

Created → Running → Failed

# 12. Failure Handling

-   Abort evaluation if required data is unavailable.
-   Preserve previous published rankings.
-   Publish failure event.
-   Never publish incomplete rankings.

# 13. Configuration

-   Indicator Parameters
-   Scoring Weights
-   Ranking Strategy
-   Confidence Thresholds
-   Logging Level

# 14. Public Interfaces

-   Trigger Evaluation
-   Query Ranked Opportunities
-   Query Evaluation History
-   Query Indicator Results

# 15. Dependencies

Depends on:

-   Data Engine
-   Discovery Engine

Provides services to:

-   Recommendation Engine

# 16. Acceptance Criteria

-   Every candidate receives an evaluation.
-   Rankings are deterministic.
-   Evidence is generated for every result.
-   Scores are reproducible.
-   Failed evaluations are auditable.

# 17. Future Scope

-   Machine-learning scoring models
-   Adaptive weighting
-   Multi-timeframe evaluation
-   Fundamental factor integration

# 18. Implementation Notes for Cursor

-   Separate indicator calculation from scoring logic.
-   Make every evaluation rule independently testable.
-   Keep scoring configurable.
-   Avoid embedding recommendation logic.
-   Preserve complete auditability for every score.

------------------------------------------------------------------------

# 19. Architectural Evolution (SD-027 / SD-028)

**Effective with Strategy Configuration:** The Evaluation Engine
**SHALL NOT** apply Strategy weights or produce recommendation
decisions. It **SHALL** emit measurable scores for the **fixed
supported indicator catalogue** (Relative Strength, Momentum Score,
Trend Score, Breakout Score, Volume Score, Market Regime, Sector
Strength, Risk Score) plus raw indicators.

Weighted overall scores, thresholds, and portfolio actions belong to
Strategy Configuration + Recommendation Engine. See
[`Strategy-Configuration-Specification.md`](./Strategy-Configuration-Specification.md)
and governance **SD-027** / **SD-028**.

Historical sections above that mention “Scoring Weights” inside
Evaluation describe original intent and are superseded for
implementation by SD-027/SD-028.

# Architectural Clarification (SD-027 / SD-030)

The Evaluation Engine calculates **objective facts** only (indicator scores, ATR, RSI, MAs, 52w high/low, etc.).

It SHALL NOT:

- Filter candidates via Screener conditions (Screeners own eligibility).
- Apply Strategy weights (Strategy / Recommendation own scoring).
- Generate trade recommendations.

Pipeline: Evaluation facts ? Screeners (eligibility) ? Strategy scoring ? Recommendations.

# Evaluation Profile ownership (SD-031)

Evaluation Engine is the sole producer of Evaluation Profile metrics (momentum, trend, breakout, volume, risk, sector strength, market alignment, overall score).

UI surfaces (Watchlist research, Portfolio averages) MUST read Evaluation Engine outputs via EvaluationProfileService � they MUST NOT recompute these scores independently.
