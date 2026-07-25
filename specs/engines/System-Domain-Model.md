# System Domain Model

  Field          Value
  -------------- ---------------------
  **Document**   System Domain Model
  **Version**    1.1
  **Status**     Active (SD-025 / SD-026 / SD-027 aligned)

------------------------------------------------------------------------

# 1. Purpose

This document defines the canonical business entities of the Trading
Operating System, their ownership, relationships and lifecycle.

Each business entity SHALL have exactly one owning engine.

------------------------------------------------------------------------

# 2. Ownership

  Entity              Owner
  ------------------- -----------------------
  Security            Data Engine
  Trading Session     Data Engine
  Price Bar           Data Engine
  Market Dataset      Data Engine
  Candidate           Discovery Engine
  Evaluation Result   Evaluation Engine
  Strategy            Strategy Configuration
  Strategy Version    Strategy Configuration
  Recommendation      Recommendation Engine
  Cash Account        Cash Management (service)
  Cash Ledger Entry   Cash Management (service)
  Notification        Notification Engine
  Order               Execution Engine
  Transaction         Execution Engine
  Position            Execution Engine
  Review Report       Review Engine

------------------------------------------------------------------------

# 3. Entity Relationships

``` text
Security
│
├── Price Bars
│
├── Candidate
│
├── Evaluation Result
│
├── Recommendation
│
├── Order
│
├── Transaction
│
└── Position

Recommendation
│
├── Notification
├── Transaction (reference via recommendation_id — not merged)
├── Order (legacy / optional BC)
├── Cash Reservation fields (reserved_amount / reservation_status)
└── Review Report

Portfolio Profile
│
├── Cash Account (1:1)
└── Cash Ledger Entries (1:N)
```

**SD-025:** Recommendation and Transaction are separate entities. A
transaction **references** a recommendation (`recommendation_id`); they
are not the same record. Approval lives on Recommendation; fill lives on
Transaction.

**SD-026:** Cash Account holds ledger-backed balance. Reserved cash is
derived from Recommendation reservation fields, not a second balance.
------------------------------------------------------------------------

# 4. Core Entity Definitions

## Security

Represents a tradable instrument.

Primary Key: - Security ID

Natural Key: - Exchange + Symbol

Owned By: - Data Engine

------------------------------------------------------------------------

## Price Bar

One OHLCV record for one Security on one Trading Session.

Unique Key: (Security ID, Trading Session)

Owned By: - Data Engine

------------------------------------------------------------------------

## Candidate

A Security that satisfies discovery rules.

Owned By: - Discovery Engine

Relationship: - References one Security.

------------------------------------------------------------------------

## Evaluation Result

Analytical assessment of a Candidate.

Relationship: - References one Candidate.

Owned By: - Evaluation Engine

------------------------------------------------------------------------

## Recommendation

Portfolio-aware decision composed of:

- Market Opinion (direction, strength, confidence, evidence)
- Portfolio Decision (OPEN / INCREASE / REDUCE / EXIT / HOLD / WATCH)
- Ranking + Capital Allocation (portfolio-wide vs available cash)
- Execution Plan (funded actionable decisions only)

Cash / reservation fields (SD-026):

- `suggested_allocation_amount`
- `reserved_amount`, `reservation_status`, `reserved_at`
- `cash_balance_at_generation`, `reserved_cash_at_generation`,
  `available_cash_at_generation`
- `executed_amount`

Relationship: - References one Evaluation Result.

May be referenced by zero or one completed Transaction
(`recommendation_id` on the transaction).

Flow: Market Opinion → Portfolio Decision → Ranking → Capital Allocation
→ Trade gen → User Approval (reserve) → Pending Execution → Transaction
(convert / cash post) (optional)

Owned By: - Recommendation Engine

------------------------------------------------------------------------

## Cash Account

One cash balance per Portfolio Profile (`portfolio_cash_accounts`).

Attributes: `profile_id` (unique), `balance`.

Owned By: - CashManagementService (not a separate engine)

------------------------------------------------------------------------

## Cash Ledger Entry

Append-only cash movement (`portfolio_cash_ledger_entries`).

Attributes: `entry_type` (deposit / withdrawal / adjustment / buy /
sell), signed `amount`, `balance_after`, `reason`, optional
`transaction_id` / `recommendation_id` / `user_id`.

Relationship: - Belongs to one Cash Account / Profile; may reference
Transaction and Recommendation.

Owned By: - CashManagementService

------------------------------------------------------------------------

## Order

Legacy / optional intent record (BC APIs). Not required for V1.0 manual
execution path (SD-025).

Relationship: - References zero or one Recommendation.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Transaction

Executed trade in the portfolio ledger.

Attributes (TOS-relevant): `source`, `recommendation_id` (nullable).

Relationship:

- Derived holdings / Position
- Optionally references one Recommendation (does not absorb it)

Owned By: - Execution Engine (writes via shared ledger services; legacy
Transactions Module is the primary UI/API for create)

------------------------------------------------------------------------

## Position

Current holding derived from Transactions.

Owned By: - Execution Engine

------------------------------------------------------------------------

## Notification

Delivery record for recommendations or system events.

Owned By: - Notification Engine

------------------------------------------------------------------------

## Review Report

Historical performance analysis.

Owned By: - Review Engine

------------------------------------------------------------------------

# 5. Lifecycle

Market Data

Security → Price Bar → Candidate → Evaluation Result → Recommendation →
(Pending Execution) → Transaction → Position → Review Report

(Order is optional/legacy and not on the primary path — SD-025.)

------------------------------------------------------------------------

# 6. Ownership Rules

DM-001 Every entity SHALL have exactly one owner.

DM-002 Only the owning engine MAY create or modify its entity.

DM-003 Other engines SHALL consume entities through published
interfaces.

DM-004 Cross-engine updates SHALL occur through events or service
interfaces.

DM-005 Historical entities SHALL be immutable once finalized.

------------------------------------------------------------------------

# 7. Event Flow

Data Published → Candidate Generated → Evaluation Completed →
Recommendation Generated → Notification Sent → Recommendation Approved →
Transaction Recorded → Position Updated → Review Generated

------------------------------------------------------------------------

# 8. Future Extensions

-   Strategy
-   Watchlist
-   Portfolio
-   Broker Account
-   Corporate Actions
-   Dividend
-   Split
-   User Preferences

------------------------------------------------------------------------

# 9. Implementation Notes for Cursor

-   Map each entity to a dedicated persistence model.
-   Keep ownership boundaries strict.
-   Avoid circular dependencies between engines.
-   Build APIs around aggregate roots owned by each engine.
-   Preserve referential integrity across relationships.

# SD-030 Domain Additions

| Entity | Owner | Notes |
|--------|-------|-------|
| Screener | Screener module | Eligibility definitions (definition_json) |
| ScreenerRun / Hit | Screener module | Eligibility results |
| StrategyScreener | Strategy Configuration | Version ? Screener reference (no condition copy) |
| Exit rules | Strategy Version config | Declarative exit on holdings |

Relationship: Strategy Version **references** Screener(s). Screener conditions are never duplicated into Strategy tables.

# Analytics entities (SD-031)

| Entity / cache | Owner |
|----------------|-------|
| Stock Analytics payload | StockAnalyticsService |
| Evaluation Profile (from EvaluationResult) | Evaluation Engine |
| Portfolio Analytics snapshot | PortfolioAnalyticsService |
| Market Analytics snapshot | MarketAnalyticsService → Market Analysis Engine |
| Recommendation Preview | Recommendation Engine |

Tables: `portfolio_analytics_snapshots`, `portfolio_stock_analytics_cache`.

# Market Analysis entities (SD-032)

| Entity | Owner | Notes |
|--------|-------|-------|
| MarketAnalyticsSnapshot | Market Analysis Engine | Table `portfolio_tos_market_analytics` |
| Sentiment / Phase / Trend / Momentum / Volatility / Risk / Breadth / Drawdown | Engine payload blocks | Logical entities in `payload_json` + columns for phase/sentiment |
| History | Same table | One row per `(benchmark_stock_id, as_of_date)` |

Relationship: Evaluation = stock facts; Market Analysis = market facts;
Recommendation / Strategy / Portfolio Analytics / Dashboard consume both.
