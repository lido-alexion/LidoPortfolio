# Recommendation Engine Specification

  Field            Value
  ---------------- -------------------------------------
  **Document**     Recommendation Engine Specification
  **Version**      1.3
  **Status**       Active (V1.0 / SD-025 / SD-026 aligned)
  **Owner**        Architecture
  **Depends On**   Evaluation Engine, Cash Management
  **Governance**   SD-022, SD-023, SD-025, SD-026

------------------------------------------------------------------------

# 1. Introduction

The Recommendation Engine converts ranked evaluation opportunities into
**portfolio-aware** recommendations. It is the business decision layer of
the Trading Operating System and owns the lifecycle of every
recommendation.

A market signal is **not** a universal trade instruction. The same
evaluation score can produce different portfolio actions depending on
holdings, allocation, **available investable cash**, and risk limits.

# 2. Purpose

Generate explainable, auditable and deterministic recommendations that
separate:

1.  **Market Opinion** — what the engine believes about the security
2.  **Portfolio Decision** — what this portfolio should do
3.  **Ranking** — score / confidence / priority across the batch
4.  **Capital Allocation** — portfolio-wide funding vs available cash
5.  **Trade Recommendation** — funded execution plans only

# 3. Goals

The Recommendation Engine SHALL:

-   Produce Market Opinion (direction, strength, confidence, evidence).
-   Produce Portfolio Decisions (OPEN / INCREASE / REDUCE / EXIT /
    HOLD / WATCH).
-   Rank actionable opportunities before allocating capital.
-   Allocate available investable cash across the batch (pluggable
    strategy; default score-priority).
-   Produce Execution Plans for **funded** actionable decisions.
-   Demote unfunded OPEN / INCREASE to WATCH (with allocation evidence).
-   Respect configured risk and position-size limits.
-   Reserve cash on buy approval; release on cancel/expire/reopen;
    convert on execute (SD-026).
-   Assign priority and expiry.
-   Maintain recommendation history and auditability.

# 4. Non Goals

The Recommendation Engine SHALL NOT:

-   Fetch market data.
-   Discover securities.
-   Calculate indicators (Evaluation Engine).
-   Own the cash ledger balance (CashManagementService).
-   Execute trades (Execution Engine).
-   Send notifications directly.

# 5. Responsibilities

-   Stage 1 — Market Opinion generation (portfolio-independent)
-   Stage 2 — Portfolio Decision generation (portfolio-dependent)
-   Stage 3 — Ranking (score / confidence / priority)
-   Stage 4 — Capital Allocation (portfolio-wide vs available cash)
-   Stage 5 — Trade Recommendation Generation (funded plans)
-   Recommendation lifecycle (approval ≠ execution — SD-025)
-   Cash reservation lifecycle (approve → reserve; execute → convert;
    cancel/expire/reopen → release — SD-026)
-   User review / approval recording for actionable decisions
-   Pending-execution queue handoff to Execution Engine
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

## Capital Allocation (SD-026)

Portfolio-wide pass over buy drafts using **available investable cash**
(cash balance − reserved cash).

-   Interface: `CapitalAllocationStrategy`
-   Default: `ScorePriorityCapitalAllocator` (score-weighted share of
    pool, whole-share rounding, leftover greedy by score)
-   Snapshot at generation: `cash_balance_at_generation`,
    `reserved_cash_at_generation`, `available_cash_at_generation`
-   Unfunded OPEN / INCREASE → demoted to WATCH;
    `evidence.capital_allocation.status = unfunded`

## Execution Plan

Generated only for funded OPEN / INCREASE / REDUCE / EXIT.

Attributes (as applicable):

-   Suggested target / suggested allocation % / amount
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
-   `suggested_allocation_amount`, capital snapshot fields
-   `reserved_amount`, `reservation_status`, `reserved_at`,
    `executed_amount`
-   Reasoning
-   Priority, Confidence, Risk Level
-   Evidence, Failed Checks
-   Status, Version, Generated / Expiry Time

Version **3** snapshots cash at generation (SD-026).

## Recommendation Status (SD-025)

Lifecycle separates **Recommendation Approval**, **Trade Execution**, and
**Recommendation Completion**.

### Recommendation Approval (this engine)

Actionable:

`pending_review` → `pending_execution` (Approved) | `rejected` | `deferred`

Review API decisions: `approved` | `accepted` (BC alias → approved) |
`rejected` | `deferred`.

Informational:

`published` on generation (no approval).

### Trade Execution (Execution Engine / Transactions)

`pending_execution` means approved and waiting for a ledger transaction
(manual via Transactions page, or future broker fill). Approval does
**not** create a trade. Buy approvals **reserve** cash until execute /
release.

### Recommendation Completion

From `pending_execution`:

-   `executed` — transaction recorded with `recommendation_id`
-   `cancelled` — operator cancelled pending execution
-   `expired` — past expiry / explicit expire

Also: `rejected` / `deferred` may later `expired` or be superseded;
informational `published` → `expired` / `cancelled` (superseded).

# 7. Inputs

From Evaluation Engine:

-   Ranked Opportunities, Scores, Evidence, Confidence

From Portfolio:

-   Holdings, current allocation %, portfolio value
-   Configured target / max position % and risk bands

From Cash Management:

-   Cash balance, reserved cash, available investable cash

# 8. Outputs

-   Recommendation list with opinion + decision + plan (+ allocation
    evidence)
-   Cash summary snapshot for the generation batch
-   Recommendation events for Notification Engine
-   Audit log

# 9. Business Workflow

1.  Load ranked opportunities
2.  Load cash summary (available investable cash)
3.  **Stage 1** — Compute Market Opinion (ignore portfolio)
4.  Load holdings / allocation for the active portfolio
5.  **Stage 2** — Compute Portfolio Decision
6.  **Stage 3** — Rank actionable drafts (score / confidence / priority)
7.  **Stage 4** — Capital Allocation across buy drafts vs available cash
8.  **Stage 5** — Persist Trade Recommendations (funded plans; demote
    unfunded buys to WATCH)
9.  Set status (`pending_review` vs `published`)
10. Publish recommendation events

# 10. Business Rules

**RC-001** Every recommendation SHALL reference exactly one evaluated
opportunity.

**RC-002** Every recommendation SHALL contain Market Opinion evidence.

**RC-003** Every recommendation SHALL have a confidence value.

**RC-004** Every recommendation SHALL have an expiry.

**RC-005** Recommendation generation SHALL be deterministic given the
same evaluation inputs, portfolio state, cash state, and config.

**RC-006** Executed recommendations SHALL become immutable.

**RC-007** Every recommendation change SHALL be auditable.

**RC-008** Market Opinion SHALL be portfolio-independent.

**RC-009** Portfolio Decision SHALL consider holdings and allocation.

**RC-010** Execution Plan SHALL exist only for funded actionable
decisions (OPEN / INCREASE / REDUCE / EXIT).

**RC-011** Portfolio actions SHALL respect configured max position /
risk limits.

**RC-012** Informational decisions (HOLD_POSITION / WATCH) SHALL NOT
require Approve/Reject/Defer and SHALL NOT enter pending execution.

**RC-013** Approving a recommendation SHALL NOT create a transaction
or broker order (SD-025). Status becomes `pending_execution` only.

**RC-014** Completing execution (ledger write) SHALL mark the
recommendation `executed` and link via `recommendation_id`.

**RC-015** Capital Allocation SHALL use available investable cash
(balance − reserved), not raw balance (SD-026).

**RC-016** Unfunded OPEN / INCREASE SHALL be demoted to WATCH with
capital-allocation evidence.

**RC-017** Approving a buy SHALL reserve cash; approve SHALL fail if
the amount exceeds available investable cash.

**RC-018** Cancel, expire, and reopen SHALL release an active
reservation; execute SHALL convert it.

# 11. State Model

See §6 Recommendation Status.

Summary (actionable):

`pending_review` → `pending_execution` | `rejected` | `deferred` →
`executed` | `cancelled` | `expired`

Reservation (buy): `none` → `reserved` → `converted` | `released`

# 12. Failure Handling

-   Do not publish partial recommendation sets for a batch.
-   Supersede prior open `pending_review` / `pending_execution` /
    `published` / `deferred` items when a new batch is generated.
-   Record failures; publish failure events.
-   Reject buy approval when reservation would exceed available cash.

# 13. Configuration

-   Score thresholds (bullish / watch / bearish)
-   Default and max position %
-   Allocation band around target
-   Risk ATR thresholds
-   Expiry duration
-   Capital allocator strategy binding (default ScorePriority)

# 14. Public Interfaces

-   Generate Recommendations
-   Query Open / All Recommendations
-   Query Recommendation Details (opinion, decision, plan, capital)
-   Record Review / Approval (actionable only; reserve on buy approve)
-   List Pending Execution
-   Cancel Pending Execution / Expire (release reservation)
-   Reopen (release reservation when leaving pending_execution)
-   Query Recommendation / Review History

# 15. Dependencies

Depends on:

-   Evaluation Engine
-   Market Analysis Engine (sentiment / phase / allocation helpers ? never recalculated here)
-   Strategy Configuration (including optional `market_gates`)
-   Portfolio calculation (holdings / allocation)
-   CashManagementService (balance / reserved / available)

Provides services to:

-   Notification Engine
-   Execution Engine
-   Review Engine

# 16. Acceptance Criteria

-   Every ranked opportunity is processed through stages 1?5.
-   Market Opinion does not depend on holdings.
-   Market analytics are consumed from Market Analysis Engine, not recomputed.
-   Portfolio Decision changes when holdings / allocation change.
-   Capital Allocation respects available investable cash and reserved
    cash from prior approvals.
-   Execution Plan present iff funded and actionable.
-   Evidence and reasoning accompany every recommendation (including
    `evidence.market_analysis` when available).
-   History remains auditable (including legacy BUY/SELL/HOLD/WATCH
    records mapped to the new model).

# 17. Future Scope

-   Strategy-specific policies
-   Alternate capital optimisers (risk parity, sector caps, ML)
-   Per-symbol target weights in settings
-   AI-assisted explanations
-   Multi-account recommendations

# 18. Implementation Notes for Cursor

-   Keep Market Opinion logic free of portfolio inputs.
-   Reuse `PortfolioCalculationService` for allocation; do not
    reimplement holdings math.
-   Inject `CapitalAllocationStrategy`; default
    `ScorePriorityCapitalAllocator`.
-   Persist opinion and plan as JSON; keep `recommendation_type` as the
    portfolio action enum.
-   Map order side from portfolio action (buy vs sell).
-   Do not embed notification or cash-ledger posting in generation;
    reservation metadata only until Execution / CashManagementService
    posts buy/sell entries.

------------------------------------------------------------------------

# 19. Architectural Evolution (SD-027)

The Recommendation Engine **consumes Strategy Configuration** instead of
hardcoded weights:

1. Load active Strategy Version.
2. Score evaluation factor facts via Strategy weights ? overall score +
   factor breakdown.
3. Apply Strategy thresholds / portfolio rules / behaviour flags.
4. Capital allocation (strategy mode + score bands + tie-break).
5. Persist `strategy_version_id`, `strategy_score`, and
   `evidence.factor_breakdown`.

See [Strategy-Configuration-Specification.md](./Strategy-Configuration-Specification.md)
and governance **SD-027**. Configuration section �13 thresholds remain
as legacy intent; runtime source of truth is Strategy `config_json`.

# 20. Architectural Clarification (SD-030)

Recommendation Engine workflow:

1. Resolve candidate stocks from Strategy eligibility Screeners (recent run hits; union of enabled sources).
2. Score eligible stocks via Strategy scoring model using Evaluation facts.
3. Rank, apply Portfolio Rules, Capital Allocation, generate BUY recommendations.
4. Evaluate holdings via Strategy Exit Strategy; generate SELL recommendations.

Explainability evidence includes Screener PASS/FAIL, scoring breakdown, portfolio decision, capital allocation, and exit status.

The engine SHALL NOT execute Screener condition trees.
