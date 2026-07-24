# Recommendation Engine Specification

  Field            Value
  ---------------- -------------------------------------
  **Document**     Recommendation Engine Specification
  **Version**      1.0 Draft
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   Evaluation Engine

------------------------------------------------------------------------

# 1. Introduction

The Recommendation Engine converts ranked opportunities into actionable
recommendations. It is the business decision layer of the Trading
Operating System and owns the lifecycle of every recommendation.

# 2. Purpose

Generate explainable, auditable and deterministic recommendations that
users can review and act upon.

# 3. Goals

The Recommendation Engine SHALL:

-   Produce Buy, Hold, Sell and Watch recommendations.
-   Evaluate recommendation policies.
-   Generate confidence and priority.
-   Estimate risk level.
-   Suggest position sizing.
-   Assign expiry.
-   Maintain recommendation history.
-   Preserve full auditability.

# 4. Non Goals

The Recommendation Engine SHALL NOT:

-   Fetch market data.
-   Discover securities.
-   Calculate indicators.
-   Execute trades.
-   Send notifications directly.

# 5. Responsibilities

-   Recommendation Generation
-   Recommendation Lifecycle Management
-   Position Size Suggestion
-   Confidence Assessment
-   Priority Assignment
-   Risk Classification
-   Recommendation Audit Trail

# 6. Domain Model

## Recommendation

Attributes:

-   Recommendation ID
-   Symbol
-   Recommendation Type (Buy/Hold/Sell/Watch)
-   Priority
-   Confidence
-   Risk Level
-   Suggested Position Size
-   Evidence
-   Failed Checks
-   Generated Time
-   Expiry Time
-   Status
-   Version

## Recommendation Status

Draft → Active → Executed

or

Draft → Active → Expired

or

Draft → Active → Cancelled

## Recommendation Batch

Represents one recommendation generation run.

# 7. Inputs

From Evaluation Engine:

-   Ranked Opportunities
-   Scores
-   Evidence
-   Confidence Inputs

Configuration:

-   Recommendation Policies
-   Risk Rules
-   Position Sizing Rules
-   Expiry Rules

# 8. Outputs

-   Recommendation List
-   Recommendation Report
-   Recommendation Events
-   Audit Log

# 9. Business Workflow

1.  Load ranked opportunities
2.  Evaluate recommendation policies
3.  Determine recommendation type
4.  Assign confidence
5.  Assess risk
6.  Suggest position size
7.  Set expiry
8.  Persist recommendations
9.  Publish recommendation events

# 10. Business Rules

**RC-001** Every recommendation SHALL reference exactly one evaluated
opportunity.

**RC-002** Every recommendation SHALL contain supporting evidence.

**RC-003** Every recommendation SHALL have a confidence value.

**RC-004** Every recommendation SHALL have an expiry.

**RC-005** Recommendation generation SHALL be deterministic.

**RC-006** Executed recommendations SHALL become immutable.

**RC-007** Every recommendation change SHALL be auditable.

# 11. State Model

Draft → Active → Executed

Draft → Active → Expired

Draft → Active → Cancelled

# 12. Failure Handling

-   Do not publish partial recommendation sets.
-   Preserve previous active recommendations.
-   Record failures.
-   Publish failure events.

# 13. Configuration

-   Confidence thresholds
-   Risk thresholds
-   Position sizing rules
-   Expiry duration
-   Recommendation policies

# 14. Public Interfaces

-   Generate Recommendations
-   Query Active Recommendations
-   Query Recommendation History
-   Query Recommendation Details

# 15. Dependencies

Depends on:

-   Evaluation Engine

Provides services to:

-   Notification Engine
-   Execution Engine
-   Review Engine

# 16. Acceptance Criteria

-   Every ranked opportunity is processed.
-   Recommendations are deterministic.
-   Evidence accompanies every recommendation.
-   Recommendation history is auditable.
-   State transitions follow defined lifecycle.

# 17. Future Scope

-   Strategy-specific recommendation policies
-   AI-assisted explanations
-   Portfolio-aware recommendations
-   Multi-account recommendations

# 18. Implementation Notes for Cursor

-   Keep recommendation policy separate from evaluation logic.
-   Make policies pluggable.
-   Preserve immutability after execution.
-   Maintain complete audit history.
-   Do not embed notification or execution logic.
