# Review Engine Specification

  Field            Value
  ---------------- -----------------------------
  **Document**     Review Engine Specification
  **Version**      1.0 Draft
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   Execution Engine

------------------------------------------------------------------------

# 1. Introduction

The Review Engine closes the feedback loop of the Trading Operating
System. It measures outcomes, evaluates the quality of recommendations
and executions, and provides insights for continuous improvement without
changing historical records.

# 2. Purpose

Provide an objective assessment of trading performance, recommendation
quality and portfolio behaviour.

# 3. Goals

The Review Engine SHALL:

-   Track recommendation outcomes.
-   Evaluate execution quality.
-   Measure portfolio performance.
-   Calculate trading metrics.
-   Produce review reports.
-   Maintain complete historical analysis.

# 4. Non Goals

The Review Engine SHALL NOT:

-   Generate recommendations.
-   Execute trades.
-   Modify historical transactions.
-   Change evaluation results.

# 5. Responsibilities

-   Recommendation Outcome Tracking
-   Portfolio Performance Analysis
-   Trade Performance Analysis
-   Metrics Calculation
-   Historical Reporting
-   Review Audit Trail

# 6. Domain Model

## Review Run

Created → Running → Completed / Failed

## Performance Metric

Examples:

-   Win Rate
-   Average Gain
-   Average Loss
-   Profit Factor
-   Expectancy
-   Portfolio Return
-   Drawdown

## Review Report

Attributes:

-   Report ID
-   Review Period
-   Portfolio Metrics
-   Recommendation Metrics
-   Execution Metrics
-   Generated Time

# 7. Inputs

-   Recommendations
-   Orders
-   Transactions
-   Positions
-   Market Data

# 8. Outputs

-   Review Reports
-   Performance Dashboards
-   Portfolio Statistics
-   Recommendation Effectiveness Reports
-   Review Events

# 9. Business Workflow

1.  Collect historical data
2.  Calculate portfolio metrics
3.  Calculate recommendation metrics
4.  Evaluate execution quality
5.  Generate review report
6.  Publish review event

# 10. Business Rules

**RV-001** Reviews SHALL use immutable historical records.

**RV-002** Historical reports SHALL be reproducible.

**RV-003** Metrics SHALL clearly define their calculation methodology.

**RV-004** Reviews SHALL never modify source data.

**RV-005** Every generated report SHALL be auditable.

# 11. State Model

Created → Running → Completed

or

Created → Running → Failed

# 12. Failure Handling

-   Preserve previous reports.
-   Record calculation failures.
-   Do not publish partial reports.

# 13. Configuration

-   Review frequency
-   Reporting period
-   Metric definitions
-   Benchmark configuration
-   Logging

# 14. Public Interfaces

-   Generate Review
-   Query Reports
-   Query Performance Metrics
-   Query Portfolio Statistics

# 15. Dependencies

Depends on:

-   Data Engine
-   Recommendation Engine
-   Execution Engine

Provides services to:

-   User Interface
-   Analytics
-   Future AI Assistant

# 16. Acceptance Criteria

-   Reports are reproducible.
-   Metrics are auditable.
-   Historical records remain immutable.
-   Portfolio statistics are internally consistent.

# 17. Future Scope

-   Strategy comparison
-   Benchmark comparison
-   Attribution analysis
-   Tax reporting
-   AI-generated insights

# 18. Implementation Notes for Cursor

-   Keep calculations deterministic.
-   Separate metric calculations into independent modules.
-   Never modify historical data.
-   Make every metric individually testable.
-   Design reports for future dashboard integration.
