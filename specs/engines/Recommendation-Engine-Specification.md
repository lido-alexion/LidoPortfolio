# Recommendation Engine Specification

  Field            Value
  ---------------- -------------------------------------
  **Document**     Recommendation Engine Specification
  **Version**      1.1
  **Status**       Active (V1.0 implementation aligned)
  **Owner**        Architecture
  **Depends On**   Evaluation Engine
  **Governance**   SD-022, SD-023

------------------------------------------------------------------------

# 1. Introduction

The Recommendation Engine converts ranked evaluation opportunities into
**portfolio-aware** recommendations. It is the business decision layer of
the Trading Operating System and owns the lifecycle of every
recommendation.

A market signal is **not** a universal trade instruction. The same
evaluation score can produce different portfolio actions depending on
holdings, allocation, and risk limits.

# 2. Purpose

Generate explainable, auditable and deterministic recommendations that
separate:

1.  **Market Opinion** — what the engine believes about the security
2.  **Portfolio Decision** — what this portfolio should do
3.  **Execution Plan** — how to size the trade (actionable only)

# 3. Goals

The Recommendation Engine SHALL:

-   Produce Market Opinion (direction, strength, confidence, evidence).
-   Produce Portfolio Decisions (OPEN / INCREASE / REDUCE / EXIT /
    HOLD / WATCH).
-   Produce Execution Plans for actionable decisions only.
-   Respect configured risk and position-size limits.
-   Optimise toward target allocation rather than always full buy/sell.
-   Assign priority and expiry.
-   Maintain recommendation history and auditability.

# 4. Non Goals

The Recommendation Engine SHALL NOT:

-   Fetch market data.
-   Discover securities.
-   Calculate indicators (Evaluation Engine).
-   Execute trades (Execution Engine).
-   Send notifications directly.

# 5. Responsibilities

-   Stage 1 — Market Opinion generation (portfolio-independent)
-   Stage 2 — Portfolio Decision generation (portfolio-dependent)
-   Stage 3 — Execution Plan generation (actionable only)
-   Recommendation lifecycle (pending_review / published / …)
-   User review recording for actionable decisions
-   Recommendation audit trail

# 6. Domain Model

## Market Opinion

Attributes:

-   Direction: Bullish | Neutral | Bearish
-   Strength: Weak | Moderate | Strong | Very Strong
-   Confidence
-   Supporting evidence (rules, indicators)

MUST NOT use portfolio holdings or allocation.

## Portfolio Decision (recommendation_type)

Internal enums:

-   OPEN_POSITION
-   INCREASE_POSITION
-   REDUCE_POSITION
-   EXIT_POSITION
-   HOLD_POSITION
-   WATCH

UI labels (examples):

-   OPEN_POSITION → Buy
-   INCREASE_POSITION → Buy More
-   REDUCE_POSITION → Sell Partial
-   EXIT_POSITION → Sell All
-   HOLD_POSITION → Hold
-   WATCH → Watch

## Execution Plan

Generated only for OPEN / INCREASE / REDUCE / EXIT.

Attributes (as applicable):

-   Suggested target / suggested allocation %
-   Suggested quantity / investment amount
-   Suggested % of position to sell / shares to sell
-   Position after execution
-   Risk explanation
-   Side (buy / sell)

## Recommendation

Attributes:

-   Recommendation ID, Symbol
-   Portfolio Decision (`recommendation_type`)
-   Market Opinion (JSON)
-   Execution Plan (JSON, nullable)
-   Current / Target / Suggested allocation %
-   Reasoning
-   Priority, Confidence, Risk Level
-   Evidence, Failed Checks
-   Status, Version, Generated / Expiry Time

## Recommendation Status

Actionable path:

`pending_review` → `accepted` | `rejected` | `deferred` → (orders) →
`executed` / `cancelled` / `expired`

Informational path:

`published` → `expired` / `cancelled` (superseded)

# 7. Inputs

From Evaluation Engine:

-   Ranked Opportunities, Scores, Evidence, Confidence

From Portfolio:

-   Holdings, current allocation %, portfolio value
-   Configured target / max position % and risk bands

# 8. Outputs

-   Recommendation list with opinion + decision + plan
-   Recommendation events for Notification Engine
-   Audit log

# 9. Business Workflow

1.  Load ranked opportunities
2.  **Stage 1** — Compute Market Opinion (ignore portfolio)
3.  Load holdings / allocation for the active portfolio
4.  **Stage 2** — Compute Portfolio Decision
5.  **Stage 3** — If actionable, compute Execution Plan toward target
    allocation within max position size
6.  Set status (`pending_review` vs `published`)
7.  Persist recommendations
8.  Publish recommendation events

# 10. Business Rules

**RC-001** Every recommendation SHALL reference exactly one evaluated
opportunity.

**RC-002** Every recommendation SHALL contain Market Opinion evidence.

**RC-003** Every recommendation SHALL have a confidence value.

**RC-004** Every recommendation SHALL have an expiry.

**RC-005** Recommendation generation SHALL be deterministic given the
same evaluation inputs, portfolio state, and config.

**RC-006** Executed recommendations SHALL become immutable.

**RC-007** Every recommendation change SHALL be auditable.

**RC-008** Market Opinion SHALL be portfolio-independent.

**RC-009** Portfolio Decision SHALL consider holdings and allocation.

**RC-010** Execution Plan SHALL exist only for actionable decisions
(OPEN / INCREASE / REDUCE / EXIT).

**RC-011** Portfolio actions SHALL respect configured max position /
risk limits.

**RC-012** Informational decisions (HOLD_POSITION / WATCH) SHALL NOT
require Accept/Reject/Defer and SHALL NOT create orders.

# 11. State Model

See §6 Recommendation Status.

# 12. Failure Handling

-   Do not publish partial recommendation sets for a batch.
-   Supersede prior open `pending_review` / `published` / `deferred`
    items when a new batch is generated.
-   Record failures; publish failure events.

# 13. Configuration

-   Score thresholds (bullish / watch / bearish)
-   Default and max position %
-   Allocation band around target
-   Risk ATR thresholds
-   Expiry duration

# 14. Public Interfaces

-   Generate Recommendations
-   Query Open / All Recommendations
-   Query Recommendation Details (opinion, decision, plan)
-   Record Review (actionable only)
-   Query Recommendation / Review History

# 15. Dependencies

Depends on:

-   Evaluation Engine
-   Portfolio calculation (holdings / allocation)

Provides services to:

-   Notification Engine
-   Execution Engine
-   Review Engine

# 16. Acceptance Criteria

-   Every ranked opportunity is processed through all three stages.
-   Market Opinion does not depend on holdings.
-   Portfolio Decision changes when holdings / allocation change.
-   Execution Plan present iff actionable.
-   Evidence and reasoning accompany every recommendation.
-   History remains auditable (including legacy BUY/SELL/HOLD/WATCH
    records mapped to the new model).

# 17. Future Scope

-   Strategy-specific policies
-   Explicit cash balance and per-symbol target weights in settings
-   AI-assisted explanations
-   Multi-account recommendations

# 18. Implementation Notes for Cursor

-   Keep Market Opinion logic free of portfolio inputs.
-   Reuse `PortfolioCalculationService` for allocation; do not
    reimplement holdings math.
-   Persist opinion and plan as JSON; keep `recommendation_type` as the
    portfolio action enum.
-   Map order side from portfolio action (buy vs sell).
-   Do not embed notification or execution logic.
