# Database Schema Specification

  Field          Value
  -------------- -------------------------------
  **Document**   Database Schema Specification
  **Version**    1.0 Draft
  **Status**     Draft

------------------------------------------------------------------------

# 1. Purpose

Define the logical database schema for the Trading Operating System. The
schema is organized around engine ownership. Each table SHALL have
exactly one owning engine.

------------------------------------------------------------------------

# 2. Design Principles

-   One owner per table.
-   Use surrogate primary keys (BIGINT or UUID).
-   Enforce referential integrity with foreign keys.
-   Prefer immutable historical records.
-   Store timestamps in UTC.
-   Soft delete only where business requires it.

------------------------------------------------------------------------

# 3. Tables by Engine

## Data Engine

### securities

-   id (PK)
-   exchange
-   symbol
-   isin
-   company_name
-   sector
-   industry
-   listing_status
-   created_at
-   updated_at

### trading_sessions

-   id (PK)
-   exchange
-   trading_date
-   is_holiday

Unique: (exchange, trading_date)

### price_bars

-   id (PK)
-   security_id (FK)
-   trading_session_id (FK)
-   open
-   high
-   low
-   close
-   volume
-   adjusted_close

Unique: (security_id, trading_session_id)

### import_jobs

-   id (PK)
-   started_at
-   completed_at
-   status
-   source
-   dataset_version

------------------------------------------------------------------------

## Discovery Engine

### discovery_runs

-   id
-   dataset_version
-   started_at
-   completed_at
-   status

### candidates

-   id
-   discovery_run_id
-   security_id
-   evidence
-   created_at

------------------------------------------------------------------------

## Evaluation Engine

### evaluation_runs

-   id
-   started_at
-   completed_at
-   status

### evaluation_results

-   id
-   evaluation_run_id
-   candidate_id
-   score
-   confidence
-   rank
-   evidence
-   created_at

------------------------------------------------------------------------

## Recommendation Engine

### recommendations

-   id
-   evaluation_result_id
-   recommendation_type
-   priority
-   confidence
-   risk_level
-   suggested_position_size
-   status
-   expires_at
-   created_at

------------------------------------------------------------------------

## Notification Engine

### notifications

-   id
-   recommendation_id
-   channel
-   recipient
-   status
-   delivered_at
-   created_at

------------------------------------------------------------------------

## Execution Engine

### orders

-   id
-   recommendation_id
-   side
-   quantity
-   status
-   created_at

### transactions

-   id
-   order_id
-   execution_price
-   quantity
-   charges
-   executed_at

### positions

-   id
-   security_id
-   quantity
-   average_cost
-   status
-   updated_at

------------------------------------------------------------------------

## Review Engine

### review_reports

-   id
-   period_start
-   period_end
-   generated_at

### review_metrics

-   id
-   report_id
-   metric_name
-   metric_value

------------------------------------------------------------------------

# 4. Relationship Summary

Security → Price Bars → Candidate → Evaluation Result → Recommendation →
Order → Transaction → Position

Recommendation → Notification

Review Report → Review Metrics

------------------------------------------------------------------------

# 5. Indexing Guidelines

Create indexes for:

-   exchange + symbol
-   trading_date
-   recommendation status
-   order status
-   transaction date
-   security_id
-   foreign keys

------------------------------------------------------------------------

# 6. Constraints

-   No duplicate OHLCV records.
-   Executed transactions are immutable.
-   Recommendations cannot reference deleted evaluations.
-   Positions are derived from transactions.
-   Historical datasets are read-only after publication.

------------------------------------------------------------------------

# 6b. Market Analysis Engine (SD-032)

### portfolio_tos_market_analytics

-   id (PK)
-   benchmark_stock_id (FK → portfolio_stocks)
-   as_of_date
-   market_phase
-   sentiment_score
-   sentiment_label
-   payload_json (full analytics blocks)
-   explainability_json
-   computed_at
-   unique (benchmark_stock_id, as_of_date)

Logical blocks inside payload (not separate tables in V1): Trend,
Momentum, Volatility, Risk, Breadth, Drawdown, Sentiment components.

------------------------------------------------------------------------

# 7. Versioning

Schema changes SHALL use database migrations. Backward compatibility
SHOULD be maintained whenever practical.

------------------------------------------------------------------------

# 8. Implementation Notes for Cursor

-   Use migrations for every schema change.
-   Keep engine ownership boundaries intact.
-   Add foreign keys and indexes from day one.
-   Never duplicate business entities across engines.
