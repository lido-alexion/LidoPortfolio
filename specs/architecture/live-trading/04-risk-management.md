# Risk Management

---

# 1. Purpose

## Overview

The Risk Management architecture defines how StoX protects trading capital during live trading.

Its primary objective is to ensure that every trading decision is evaluated against configurable risk constraints before any order reaches an external broker.

Risk Management is a mandatory platform capability.

No live order shall bypass risk evaluation.

---

# Objectives

The Risk Management subsystem exists to:

- protect trading capital
- enforce configurable risk limits
- prevent accidental or excessive exposure
- support disciplined portfolio management
- reduce operational mistakes
- provide deterministic and explainable trade approval
- support both semi-automatic and fully automatic trading

---

# Scope

This specification defines:

- Risk Management architecture
- Risk evaluation workflow
- Risk policies
- Position sizing
- Portfolio exposure controls
- Cash allocation rules
- Capital protection mechanisms
- Emergency controls
- Risk decision lifecycle
- Risk reporting
- Integration with Execution Engine

This specification does **not** define:

- Trading Strategies
- Recommendation generation
- Broker APIs
- Portfolio analytics
- Order execution protocols
- Market analysis algorithms

Those topics are covered by their respective architectural specifications.

---

# Position within the Live Trading Architecture

Risk Management is positioned between Recommendation approval and Order execution.

Every order proposed for execution must first satisfy all applicable risk rules.

The conceptual flow is:

Recommendation

↓

Review (optional)

↓

Risk Evaluation

↓

Execution

↓

Broker

Risk approval is mandatory regardless of whether trading is performed manually, semi-automatically or fully automatically.

---

# Architectural Responsibility

Risk Management is responsible for determining whether a proposed trade is acceptable from a capital protection perspective.

It is **not** responsible for determining whether a trade is profitable.

Strategy determines opportunity.

Risk Management determines acceptability.

Execution performs the trade.

This separation preserves clear architectural responsibilities.

---

# Platform Relationships

Within the Platform Architecture, Risk Management consists of the following building blocks:

Configuration

- Risk Policies

Registry

- Risk Policy Registry

Business Engine

- Risk Evaluation Engine

Run

- Risk Evaluation Run

Artifact

- Risk Decision

Event

- Risk Approved
- Risk Rejected
- Risk Warning

Operational Control

- Trading Freeze
- Emergency Stop
- Maximum Exposure Lock

Risk Management reuses the Platform Architecture rather than introducing new architectural concepts.

---

# Guiding Principles

The Risk Management subsystem follows these principles:

- Capital preservation before return maximization.
- Every trade must be explainable.
- Risk evaluation must be deterministic.
- Risk policies must be configurable.
- Risk evaluation must be repeatable.
- Historical decisions must remain auditable.
- Risk Management must remain independent of broker implementation.

---

# Success Criteria

A successful Risk Management implementation should ensure that:

- every order is evaluated consistently
- identical inputs always produce identical decisions
- all rejected trades include clear explanations
- all approved trades remain traceable
- every decision is fully auditable
- platform operators can confidently enable increasing levels of automation while maintaining appropriate safeguards

The architecture described in this specification provides the foundation for safe, explainable and scalable live trading within the StoX Platform.

# 2. Risk Management Philosophy

## Overview

The primary objective of Risk Management is not to maximize returns.

Its primary objective is to preserve trading capital by ensuring that every trade remains within acceptable and configurable risk limits.

The Strategy determines whether a trading opportunity exists.

Risk Management determines whether that opportunity should be acted upon.

A strategy may identify an excellent trading opportunity.

Risk Management may still reject that trade if it violates the configured risk constraints.

---

# Separation of Responsibilities

The Live Trading architecture deliberately separates the responsibilities of different platform components.

Strategy

Determines _what_ should be traded.

Recommendation Engine

Determines _which_ opportunities satisfy the strategy.

Review Engine

Determines whether a recommendation should proceed for execution.

Risk Management

Determines whether the proposed trade is acceptable.

Execution Engine

Places approved orders with the broker.

Each subsystem has one clearly defined responsibility.

---

# Capital Preservation

Capital preservation always takes precedence over return maximization.

The platform should reject profitable opportunities if executing them would expose the portfolio to unacceptable risk.

Examples include:

- insufficient cash
- excessive portfolio concentration
- position size exceeding configured limits
- sector overexposure
- account-level risk limits
- operational restrictions

Missing an opportunity is preferable to violating configured risk controls.

---

# Configuration-Driven Risk

All risk behaviour should be configurable.

Risk rules should be expressed through reusable Risk Policies rather than embedded directly into application code.

Examples include:

- Maximum allocation per position
- Maximum sector exposure
- Minimum cash reserve
- Maximum simultaneous positions
- Daily trading limits
- Portfolio exposure limits

The Risk Evaluation Engine interprets these policies.

It does not define them.

---

# Deterministic Decisions

Risk evaluation must be deterministic.

Given identical:

- portfolio state
- market data
- recommendation
- cash position
- configured policies

the platform should always produce the same risk decision.

This enables:

- reproducible backtesting
- explainable approvals
- reliable testing
- complete auditability

---

# Explainable Decisions

Every risk decision should include a clear explanation.

Users should always understand:

- why a trade was approved
- why a trade was rejected
- which policy influenced the decision
- which thresholds were evaluated
- which portfolio conditions were considered

Risk decisions should never appear arbitrary.

---

# Layered Risk Evaluation

Risk should be evaluated in multiple independent layers.

Typical layers include:

Portfolio Risk

Examples:

- available cash
- portfolio concentration
- diversification
- sector exposure

Position Risk

Examples:

- position size
- allocation percentage
- stop-loss distance

Operational Risk

Examples:

- maintenance mode
- broker availability
- emergency stop
- trading suspension

Compliance Risk

Examples:

- policy validation
- execution permissions
- account restrictions

Each layer contributes independently to the final risk decision.

---

# Fail-Safe Philosophy

When uncertainty exists, the safer decision should be preferred.

Examples include:

- missing market data
- unavailable broker
- incomplete portfolio state
- invalid policy configuration
- unexpected calculation errors

Unless explicitly configured otherwise, failures should result in rejection rather than execution.

---

# Independence from Brokers

Risk Management should remain independent of broker implementation.

Risk decisions should be completed before any broker interaction occurs.

This ensures:

- consistent behaviour across brokers
- repeatable backtesting
- provider independence
- simplified testing

Broker-specific constraints may exist, but they should not replace platform-level risk evaluation.

---

# Automation Readiness

The same Risk Management architecture should support all trading modes.

Manual

Human reviews recommendations and executes trades.

Semi-Automatic

Human approves execution after successful risk evaluation.

Fully Automatic

Execution proceeds automatically after successful risk evaluation.

The architecture remains identical.

Only the approval workflow changes.

---

# Long-Term Philosophy

Risk Management exists to ensure disciplined execution over long periods rather than maximizing short-term gains.

The objective is to build confidence that automated execution can safely evolve from:

Manual

↓

Semi-Automatic

↓

Fully Automatic

without changing the underlying risk architecture.

As automation increases, the importance of consistent, deterministic and explainable risk evaluation also increases.

Risk Management therefore serves as the primary safety mechanism of the Live Trading platform.

# 3. Risk Architecture

## Overview

The Risk Management subsystem is a configuration-driven business capability responsible for evaluating proposed trades before execution.

It operates as an independent architectural subsystem within the Live Trading domain.

Its purpose is to evaluate whether a proposed trade satisfies all configured risk constraints and to produce a deterministic Risk Decision.

The subsystem never executes trades.

It only determines whether execution is permitted.

---

# Architectural Position

Within the Live Trading architecture, Risk Management sits between Recommendation approval and Order execution.

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Review (optional)
        │
        ▼
Risk Evaluation
        │
        ▼
Risk Decision
        │
        ▼
Execution Engine
        │
        ▼
Broker Connector
```

No order may reach the Execution Engine without first completing Risk Evaluation successfully.

---

# Architectural Components

The Risk Management subsystem consists of the following platform building blocks.

| Platform Building Block | Live Trading Component |
| ----------------------- | ---------------------- |
| Configuration           | Risk Policies          |
| Registry                | Risk Policy Registry   |
| Business Engine         | Risk Evaluation Engine |
| Run                     | Risk Evaluation Run    |
| Artifact                | Risk Decision          |
| Event                   | Risk Evaluation Events |
| Operational Control     | Trading Controls       |

The subsystem introduces no new architectural building blocks.

It reuses the Platform Architecture defined in `07-Platform-Architecture.md`.

---

# Risk Policy Registry

The Risk Policy Registry is the authoritative source for all Risk Policies.

Its responsibilities include:

- storing Risk Policies
- version management
- validation
- publication
- lifecycle management

The Registry does not evaluate risk.

---

# Risk Evaluation Engine

The Risk Evaluation Engine performs the actual evaluation.

Its responsibilities include:

- loading Risk Policies
- collecting evaluation inputs
- evaluating every applicable policy
- aggregating policy outcomes
- producing a Risk Decision
- generating execution evidence

The Risk Evaluation Engine does not modify Recommendations.

It only evaluates them.

---

# Risk Evaluation Run

Every evaluation creates a Risk Evaluation Run.

The Run records:

- Recommendation evaluated
- portfolio state
- cash position
- policy versions
- evaluation timestamps
- evaluation outcome
- generated Risk Decision

Every evaluation remains historically traceable.

---

# Risk Decision Artifact

The output of Risk Evaluation is a Risk Decision Artifact.

Typical outcomes include:

- Approved
- Rejected
- Approved with Warnings

The Risk Decision becomes the authoritative input for the Execution Engine.

Execution should not reinterpret or override the decision.

---

# Risk Events

Risk Evaluation publishes Events describing significant outcomes.

Examples include:

- Risk Evaluation Started
- Risk Evaluation Completed
- Risk Approved
- Risk Rejected
- Risk Warning Generated
- Policy Evaluation Failed

Events improve monitoring, auditability and operational visibility.

---

# Inputs

Risk Evaluation may consume information from multiple platform components.

Typical inputs include:

- Recommendation
- Portfolio Holdings
- Available Cash
- Open Positions
- Existing Orders
- Active Risk Policies
- Operational Controls
- Market Data
- User Configuration

The exact input set depends on the configured Risk Policies.

---

# Outputs

Risk Evaluation produces:

Primary Output

- Risk Decision

Supporting Outputs

- Evaluation Details
- Policy Results
- Warnings
- Audit Information
- Events

These outputs become available to downstream platform components.

---

# Independence

Risk Management remains independent of:

- broker implementation
- execution mechanism
- trading mode
- user interface
- scheduling mechanism

Whether trading is:

- Manual
- Semi-Automatic
- Fully Automatic

the Risk Evaluation process remains identical.

Only downstream execution changes.

---

# Failure Behaviour

Risk Evaluation follows a fail-safe approach.

If evaluation cannot be completed with sufficient confidence, the proposed trade should be rejected.

Typical reasons include:

- missing portfolio data
- missing market data
- invalid policy configuration
- evaluation errors
- unavailable dependencies

No execution should proceed with an incomplete Risk Decision.

---

# Architectural Principles

The Risk Management subsystem shall:

- remain deterministic
- remain configuration-driven
- remain explainable
- remain independent of broker implementation
- remain fully auditable
- remain reusable across trading modes
- evaluate rather than execute
- fail safely

These principles apply to all future extensions of the subsystem.

---

# Summary

The Risk Management subsystem provides an independent, deterministic and configuration-driven evaluation layer between Recommendation generation and Order execution.

By separating risk evaluation from execution, the Live Trading architecture ensures that every trade is assessed consistently, remains fully explainable and can evolve independently of brokers, strategies and execution workflows.

# 4. Risk Evaluation Pipeline

## Overview

The Risk Evaluation Pipeline defines the sequence of activities performed by the Risk Evaluation Engine when assessing a proposed trade.

The pipeline provides a deterministic and repeatable process for evaluating every recommendation against the configured Risk Policies before execution.

Every recommendation follows the same evaluation pipeline regardless of:

- trading strategy
- broker
- trading mode
- market conditions

This guarantees consistent risk decisions across the platform.

---

# Pipeline Principles

The Risk Evaluation Pipeline shall be:

- deterministic
- sequential
- explainable
- auditable
- configuration-driven
- fail-safe

Each stage shall complete successfully before the next stage begins.

If any stage produces a rejection, the evaluation terminates immediately.

---

# Canonical Pipeline

The conceptual evaluation pipeline is:

```text
Recommendation
        │
        ▼
Load Evaluation Context
        │
        ▼
Load Risk Policies
        │
        ▼
Validate Inputs
        │
        ▼
Portfolio Risk Evaluation
        │
        ▼
Position Risk Evaluation
        │
        ▼
Operational Risk Evaluation
        │
        ▼
Compliance Evaluation
        │
        ▼
Aggregate Results
        │
        ▼
Generate Risk Decision
        │
        ▼
Publish Events
        │
        ▼
Execution Engine
```

Every stage contributes to the final Risk Decision.

---

# Stage 1 — Load Evaluation Context

The Risk Evaluation Engine first gathers all information required for evaluation.

Typical inputs include:

- Recommendation
- Strategy
- Portfolio
- Holdings
- Cash Balance
- Existing Orders
- Open Positions
- Current Market Data
- Operational Controls

The collected information forms the Evaluation Context for the remainder of the pipeline.

---

# Stage 2 — Load Risk Policies

The engine retrieves all applicable Risk Policies from the Risk Policy Registry.

Examples include:

- Position Size Policy
- Exposure Policy
- Cash Allocation Policy
- Diversification Policy
- Trading Restriction Policy

Only published policy versions participate in evaluation.

The Run records every policy version used.

---

# Stage 3 — Validate Inputs

Before evaluating risk, the engine validates that all required inputs are available and internally consistent.

Validation includes checks such as:

- portfolio exists
- recommendation is valid
- cash balance available
- market data available
- policy configuration valid
- required operational services available

Validation failures terminate the evaluation immediately.

---

# Stage 4 — Portfolio Risk Evaluation

Portfolio-level policies are evaluated.

Examples include:

- total portfolio exposure
- cash reserve
- diversification
- sector concentration
- maximum simultaneous positions

These policies evaluate the portfolio as a whole rather than an individual trade.

---

# Stage 5 — Position Risk Evaluation

Position-specific policies evaluate the proposed trade.

Examples include:

- position size
- allocation percentage
- stop-loss validation
- expected exposure
- maximum position value

These policies assess the individual recommendation independently of the broader portfolio.

---

# Stage 6 — Operational Risk Evaluation

Operational controls are evaluated before execution is permitted.

Typical checks include:

- trading enabled
- maintenance mode
- broker availability
- emergency stop
- execution permissions

Operational restrictions always take precedence over trading opportunities.

---

# Stage 7 — Compliance Evaluation

Compliance checks verify that execution satisfies all platform governance requirements.

Examples include:

- policy validation
- account eligibility
- approval requirements
- automation mode restrictions

Future regulatory or organizational rules may be incorporated into this stage.

---

# Stage 8 — Aggregate Results

The engine combines the results of all evaluation stages.

Possible outcomes include:

- Approved
- Approved with Warnings
- Rejected

The aggregation process records:

- passed policies
- failed policies
- warnings
- supporting evidence

Aggregation does not modify individual policy results.

---

# Stage 9 — Generate Risk Decision

The engine produces a Risk Decision Artifact.

The decision includes:

- overall outcome
- evaluation timestamp
- evaluated recommendation
- policy results
- warnings
- rejection reasons
- execution eligibility

This Artifact becomes the authoritative input to the Execution Engine.

---

# Stage 10 — Publish Events

The engine publishes Events describing the completed evaluation.

Examples include:

- Risk Approved
- Risk Rejected
- Risk Warning Generated
- Risk Evaluation Completed

These Events improve monitoring, auditability and downstream integration.

---

# Pipeline Termination

The pipeline terminates under one of the following conditions.

Approved

The recommendation may proceed to execution.

Approved with Warnings

Execution may continue, but warnings are recorded.

Rejected

Execution is prohibited.

Failed

Evaluation could not be completed safely.

Failure is treated as a rejection unless explicitly configured otherwise.

---

# Explainability

Every stage of the pipeline contributes evidence to the final Risk Decision.

Users should be able to determine:

- which stages executed
- which policies passed
- which policies failed
- why the final decision was reached

The pipeline should never produce unexplained outcomes.

---

# Design Principles

The Risk Evaluation Pipeline shall:

- execute sequentially
- remain deterministic
- remain configuration-driven
- support auditing
- support replay
- support future extension
- fail safely

New evaluation stages should extend the pipeline rather than replacing existing stages.

---

# Summary

The Risk Evaluation Pipeline provides a structured, deterministic and explainable workflow for assessing every proposed trade before execution.

By evaluating recommendations through successive layers of portfolio, position, operational and compliance checks, the pipeline ensures that only trades satisfying all configured risk constraints are permitted to proceed to execution.

# 5. Risk Policies

## Overview

Risk Policies define the configurable rules that govern trade approval within the Live Trading subsystem.

The Risk Evaluation Engine interprets these policies during evaluation.

Risk Policies define constraints.

They do not perform evaluation.

This separation allows risk behaviour to evolve without modifying the Risk Evaluation Engine.

---

# Policy Architecture

Risk Policies are Configuration artifacts managed by the Risk Policy Registry.

Every published policy is:

- versioned
- immutable
- reusable
- auditable
- explainable

The Risk Evaluation Engine loads the applicable policy versions at the beginning of every Risk Evaluation Run.

Historical Runs always reference the policy versions that governed the decision.

---

# Policy Categories

Risk Policies are grouped into logical categories.

Each category evaluates one aspect of trading risk.

The categories are independent and may evolve separately.

---

# Portfolio Policies

Portfolio Policies evaluate the portfolio as a whole.

Typical examples include:

- Maximum portfolio exposure
- Minimum cash reserve
- Maximum simultaneous positions
- Diversification requirements
- Sector concentration limits
- Industry concentration limits
- Market capitalization allocation
- Strategy allocation limits

These policies ensure the portfolio remains balanced and adequately diversified.

---

# Position Policies

Position Policies evaluate the proposed trade.

Typical examples include:

- Maximum position size
- Minimum position size
- Maximum allocation percentage
- Position value limits
- Stop-loss requirement
- Risk-to-reward validation
- Average price constraints

These policies determine whether an individual position is acceptable.

---

# Cash Management Policies

Cash Management Policies govern capital utilization.

Examples include:

- Minimum available cash
- Maximum deployable capital
- Cash buffer requirements
- Partial allocation rules
- Capital reservation rules

These policies prevent excessive capital deployment.

---

# Exposure Policies

Exposure Policies evaluate aggregate investment exposure.

Examples include:

- Total equity exposure
- Sector exposure
- Industry exposure
- Single-stock exposure
- Theme exposure
- Correlated position exposure

These policies reduce concentration risk.

---

# Operational Policies

Operational Policies evaluate whether trading is operationally permitted.

Examples include:

- Trading enabled
- Market open validation
- Broker availability
- Emergency stop
- Maintenance mode
- Trading session restrictions

Operational Policies always take precedence over investment opportunities.

---

# Automation Policies

Automation Policies govern execution according to the configured trading mode.

Examples include:

- Manual approval required
- Semi-automatic approval rules
- Automatic execution eligibility
- Automation maturity requirements
- Maximum automation level

These policies ensure that execution respects the configured operational mode.

---

# Compliance Policies

Compliance Policies enforce governance requirements.

Examples include:

- Mandatory review completion
- User authorization
- Account eligibility
- Trading restrictions
- Policy consistency validation

Future regulatory requirements may extend this category.

---

# Policy Composition

Multiple Risk Policies participate in every evaluation.

A typical evaluation may include:

```text
Portfolio Policy
        │
        ▼
Position Policy
        │
        ▼
Cash Policy
        │
        ▼
Exposure Policy
        │
        ▼
Operational Policy
        │
        ▼
Compliance Policy
```

The Risk Evaluation Engine evaluates every applicable policy before generating the final Risk Decision.

---

# Policy Evaluation

Each Risk Policy produces one of the following outcomes:

- Passed
- Failed
- Warning
- Not Applicable

The Risk Evaluation Engine aggregates these individual outcomes into a single Risk Decision.

Individual policy outcomes remain visible for audit and explainability.

---

# Policy Versioning

Every Risk Policy shall support versioning.

Published policies are immutable.

When a policy changes:

- a new version is created
- historical versions remain available
- historical Risk Evaluation Runs continue referencing the original policy version

This guarantees reproducibility and auditability.

---

# Policy Traceability

Every Risk Decision should identify:

- evaluated policies
- policy versions
- evaluation outcomes
- failed policies
- warning policies

Users should always be able to understand which policies influenced the final decision.

---

# Design Principles

Risk Policies shall:

- remain declarative
- remain configuration-driven
- remain reusable
- remain versioned
- remain explainable
- remain independent of implementation

Risk Policies define constraints.

The Risk Evaluation Engine performs evaluation.

---

# Anti-Patterns

Risk Policies should never:

- execute trades
- modify portfolio state
- communicate with brokers
- generate recommendations
- duplicate execution logic
- contain implementation-specific code

Their responsibility is to define configurable risk constraints.

---

# Summary

Risk Policies provide the configurable governance layer of the Live Trading subsystem.

By expressing risk constraints as reusable, versioned and auditable configurations, the platform enables consistent and deterministic risk evaluation while allowing trading rules to evolve independently of the Risk Evaluation Engine.

# 6. Position Sizing

## Overview

Position Sizing determines the quantity or value of a trade after it has successfully passed Risk Evaluation.

Its objective is to determine an appropriate investment size while respecting all applicable Risk Policies.

Position Sizing is part of the Risk Management subsystem.

It determines trade size.

It does not determine whether a trade should occur.

---

# Purpose

Position Sizing exists to ensure that approved trades are executed using an appropriate allocation of capital.

Its objectives include:

- preserving capital
- maintaining portfolio balance
- controlling concentration risk
- supporting diversification
- enforcing allocation policies
- ensuring repeatable sizing decisions

---

# Architectural Position

Position Sizing occurs after policy evaluation but before order generation.

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Position Sizing
        │
        ▼
Risk Decision
        │
        ▼
Execution Engine
```

Only recommendations that satisfy all mandatory Risk Policies proceed to Position Sizing.

---

# Inputs

Position Sizing may consider:

Portfolio State

- available cash
- existing holdings
- current portfolio value
- invested capital
- unrealized profit/loss

Recommendation

- symbol
- proposed action
- entry price
- stop-loss
- strategy

Risk Policies

- maximum allocation
- minimum allocation
- position limits
- exposure limits

Market Information

- latest price
- liquidity
- lot size
- trading constraints

---

# Outputs

Position Sizing produces:

- recommended investment amount
- recommended quantity
- expected portfolio allocation
- expected remaining cash
- sizing explanation

These outputs become part of the Risk Decision Artifact.

---

# Position Sizing Methods

The architecture supports multiple sizing methodologies.

Examples include:

Fixed Capital

Every trade receives a fixed investment amount.

Percentage Allocation

Investment is determined as a percentage of available capital.

Risk-Based Allocation

Investment is determined using the acceptable monetary risk of the trade.

Volatility-Based Allocation

Investment varies according to market volatility.

Strategy-Specific Allocation

Strategies may define their preferred sizing methodology through configuration.

Future methodologies may be introduced without changing the architecture.

---

# Policy Integration

Position Sizing must respect all applicable Risk Policies.

Typical constraints include:

- maximum allocation
- minimum allocation
- maximum exposure
- available cash
- diversification rules
- sector limits

Position Sizing may reduce an allocation to satisfy Risk Policies.

It shall never violate them.

---

# Cash Awareness

Position Sizing must always consider available cash.

Typical behaviours include:

- full allocation when sufficient cash exists
- reduced allocation when cash is limited
- rejection if minimum investment requirements cannot be met

Position Sizing shall never assume unlimited capital.

---

# Existing Positions

When a recommendation affects an existing holding, Position Sizing should consider:

- current position size
- average purchase price
- remaining allocation capacity
- portfolio concentration
- existing exposure

This enables support for:

- increasing positions
- reducing positions
- partial exits
- complete exits

---

# Explainability

Every sizing decision should be explainable.

Users should be able to understand:

- recommended investment amount
- recommended quantity
- governing Risk Policies
- limiting factors
- allocation calculations

Position Sizing should never produce unexplained quantities.

---

# Deterministic Behaviour

Given identical:

- Recommendation
- Portfolio State
- Risk Policies
- Market Information

Position Sizing shall always produce identical results.

This guarantees:

- reproducibility
- backtesting consistency
- auditability
- predictable execution

---

# Future Extensions

The architecture allows future support for:

- volatility-adjusted sizing
- AI-assisted sizing
- Kelly Criterion
- ATR-based sizing
- portfolio optimization algorithms
- broker-specific constraints

These enhancements should extend Position Sizing without altering the surrounding Risk Evaluation architecture.

---

# Design Principles

Position Sizing shall:

- remain deterministic
- remain configuration-driven
- preserve capital
- respect all Risk Policies
- remain explainable
- remain broker-independent

Position Sizing determines trade size.

It does not determine trade eligibility.

---

# Summary

Position Sizing converts an approved trading opportunity into a concrete investment allocation.

By separating trade approval from trade sizing, the Live Trading architecture maintains clear responsibility boundaries while ensuring that capital is allocated consistently, safely and explainably across all trading modes.

# 7. Cash Management

## Overview

Cash Management governs how available capital is allocated, reserved and consumed during live trading.

Its primary objective is to ensure that the platform never attempts to allocate more capital than is safely available.

Cash Management operates independently of trading strategies and brokers.

It provides a consistent view of trading capital for the Risk Evaluation Engine and the Execution Engine.

---

# Purpose

Cash Management exists to:

- prevent over-allocation of capital
- maintain sufficient liquidity
- reserve capital for pending orders
- support deterministic position sizing
- enable accurate portfolio valuation
- prepare the platform for future multi-broker support

Cash Management represents the authoritative source of trading capital within the Live Trading subsystem.

---

# Architectural Position

Cash Management participates in Risk Evaluation before Position Sizing.

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Cash Management
        │
        ▼
Position Sizing
        │
        ▼
Exposure Evaluation
        │
        ▼
Risk Decision
```

Cash availability directly influences the quantity that may be purchased.

---

# Cash Components

The platform distinguishes between different categories of capital.

Typical categories include:

Available Cash

Capital immediately available for new trades.

Reserved Cash

Capital committed to pending orders.

Invested Capital

Capital currently deployed in open positions.

Realized Profit/Loss

Profit or loss resulting from completed trades.

Unrealized Profit/Loss

Current valuation changes of open positions.

Future versions may introduce additional capital categories such as margin and collateral.

---

# Cash Allocation

Cash Management is responsible for determining how much capital may be allocated to a proposed trade.

Allocation decisions consider:

- available cash
- reserved cash
- minimum cash reserve
- portfolio allocation limits
- pending transactions

The resulting allocation is supplied to Position Sizing.

---

# Cash Reservation

Before submitting an order, the required capital should be reserved.

Reservation prevents multiple simultaneous orders from consuming the same capital.

Typical lifecycle:

```text
Available Cash
        │
        ▼
Reserved Cash
        │
        ▼
Invested Capital
```

If an order is cancelled or rejected, the reserved amount returns to Available Cash.

---

# Cash Release

Cash may be released under several conditions.

Examples include:

- order cancellation
- order rejection
- partial execution
- completed sell transactions

Cash balances should always reflect the current trading state.

---

# Relationship with Position Sizing

Position Sizing depends on Cash Management.

Position Sizing determines:

- desired investment amount

Cash Management determines:

- whether that amount is actually available

If sufficient capital is unavailable, Position Sizing may reduce the allocation or the Risk Evaluation Engine may reject the recommendation according to the applicable Risk Policies.

---

# Relationship with Execution

Execution consumes cash.

Cash Management records the financial impact of successful executions.

Typical sequence:

```text
Cash Reserved

↓

Order Submitted

↓

Order Filled

↓

Cash Invested
```

Execution should not directly calculate cash availability.

Cash Management remains the authoritative source.

---

# Future Extensions

The architecture supports future capabilities including:

- multiple broker accounts
- multiple currencies
- margin trading
- collateral management
- leverage
- derivatives
- settlement tracking

These capabilities extend Cash Management without altering the surrounding Risk Management architecture.

---

# Design Principles

Cash Management shall:

- remain deterministic
- remain broker-independent
- preserve capital consistency
- support auditability
- prevent over-allocation
- provide a single source of truth for available trading capital

---

# Summary

Cash Management provides the authoritative view of trading capital within the Live Trading subsystem.

By managing available, reserved and invested capital independently of trading strategies and execution mechanisms, it enables safe position sizing, consistent risk evaluation and reliable trade execution across all supported trading modes.

# 8. Exposure Management

## Overview

Exposure Management governs how trading capital is distributed across the portfolio.

Its objective is to prevent excessive concentration in individual securities, sectors, industries, asset classes or trading strategies.

Unlike Cash Management, which determines whether sufficient capital exists, Exposure Management determines whether allocating that capital would create unacceptable portfolio risk.

Exposure Management evaluates the portfolio as a whole rather than individual trades in isolation.

---

# Purpose

Exposure Management exists to:

- reduce concentration risk
- improve diversification
- manage portfolio balance
- limit correlated positions
- control aggregate portfolio risk
- support long-term capital preservation

Exposure Management evaluates portfolio composition rather than trading opportunity quality.

---

# Architectural Position

Exposure Management follows Position Sizing.

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Cash Management
        │
        ▼
Position Sizing
        │
        ▼
Exposure Management
        │
        ▼
Risk Decision
```

Exposure calculations use the proposed position size determined by Position Sizing.

---

# Exposure Dimensions

Exposure may be evaluated across multiple dimensions.

Typical dimensions include:

Position Exposure

- individual stock allocation
- individual position value

Sector Exposure

- banking
- IT
- pharmaceuticals
- energy

Industry Exposure

- software
- private banks
- FMCG
- automobiles

Strategy Exposure

- Momentum
- Swing
- Value
- Growth

Market Capitalization Exposure

- Large Cap
- Mid Cap
- Small Cap

Future platform versions may introduce additional exposure dimensions.

---

# Concentration Management

The platform should prevent excessive concentration.

Examples include:

- excessive investment in a single stock
- excessive investment in one sector
- excessive allocation to one strategy
- excessive dependence on correlated positions

The objective is to reduce portfolio vulnerability to a single market event.

---

# Diversification

Diversification is achieved through configurable Exposure Policies.

Examples include:

- maximum allocation per stock
- maximum allocation per sector
- minimum number of holdings
- strategy diversification
- market-cap diversification

The architecture defines the mechanism.

Individual thresholds remain configuration-driven.

---

# Correlation Awareness

Future versions of the platform may evaluate correlation between holdings.

Examples include:

- highly correlated stocks
- ETFs tracking similar indices
- companies within the same business group
- correlated sectors

Correlation-aware exposure provides more realistic portfolio risk assessment than simple allocation limits.

Correlation analysis is optional for Version 1.

---

# Existing Position Evaluation

Exposure Management evaluates the portfolio after applying the proposed trade.

The evaluation considers:

- current holdings
- proposed position size
- expected portfolio allocation
- expected sector allocation
- expected strategy allocation

The objective is to evaluate the resulting portfolio rather than the current portfolio alone.

---

# Interaction with Risk Policies

Exposure Management evaluates Exposure Policies defined in the Risk Policy Registry.

Typical policy categories include:

- position limits
- sector limits
- diversification rules
- strategy allocation rules
- concentration rules

The evaluation produces individual policy outcomes that contribute to the final Risk Decision.

---

# Interaction with Position Sizing

Position Sizing determines the proposed allocation.

Exposure Management validates whether that allocation is acceptable.

If exposure limits would be exceeded, the platform may:

- reduce the allocation (if permitted by policy)
- generate a warning
- reject the trade

The exact behaviour is determined by the applicable Risk Policies.

---

# Explainability

Every exposure decision should be explainable.

Users should understand:

- current portfolio exposure
- projected exposure after execution
- governing Exposure Policies
- exceeded limits
- diversification impact

Exposure calculations should never produce unexplained results.

---

# Future Extensions

The architecture supports future capabilities including:

- correlation matrices
- beta-weighted exposure
- volatility-adjusted exposure
- factor exposure
- geographic exposure
- currency exposure
- ESG exposure

These capabilities extend Exposure Management without altering the surrounding architecture.

---

# Design Principles

Exposure Management shall:

- evaluate the resulting portfolio
- remain configuration-driven
- remain deterministic
- support explainability
- support auditability
- encourage diversification
- remain independent of broker implementation

Exposure Management governs portfolio composition.

It does not evaluate trading opportunities.

---

# Summary

Exposure Management ensures that approved trades maintain an appropriately diversified and balanced portfolio.

By evaluating the impact of proposed trades across multiple exposure dimensions, the platform reduces concentration risk while remaining fully configuration-driven and explainable.

# 9. Capital Protection

## Overview

Capital Protection defines the mechanisms that safeguard trading capital against significant losses arising from market movements, operational failures or unexpected trading conditions.

Unlike Risk Evaluation, which determines whether a trade should be executed, Capital Protection continuously safeguards the portfolio throughout its lifecycle.

Capital Protection operates before, during and after trade execution.

Its primary objective is to ensure long-term preservation of trading capital.

---

# Purpose

Capital Protection exists to:

- preserve trading capital
- limit catastrophic losses
- reduce portfolio drawdowns
- prevent uncontrolled exposure
- support disciplined trading
- provide emergency safeguards

Capital preservation always takes precedence over maximizing returns.

---

# Architectural Position

Capital Protection operates across the entire trading lifecycle.

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Execution
        │
        ▼
Position Monitoring
        │
        ▼
Capital Protection
        │
        ▼
Portfolio
```

Unlike other Risk Management components, Capital Protection remains active after order execution.

---

# Protection Layers

Capital Protection is implemented through multiple independent layers.

Examples include:

Trade-Level Protection

- stop-loss validation
- maximum trade loss
- trailing stop management

Portfolio-Level Protection

- maximum portfolio drawdown
- capital preservation thresholds
- exposure reduction

Operational Protection

- emergency stop
- trading suspension
- broker failure handling

Each layer operates independently while contributing to overall portfolio safety.

---

# Capital Protection Policies

Typical policy categories include:

- maximum daily loss
- maximum weekly loss
- maximum portfolio drawdown
- minimum portfolio value
- maximum realized loss
- maximum unrealized loss
- capital preservation threshold

The architecture defines the policy categories.

Specific thresholds remain configuration-driven.

---

# Drawdown Management

The platform should monitor portfolio drawdowns.

Typical measurements include:

- current drawdown
- maximum historical drawdown
- percentage drawdown
- recovery progress

Future Risk Policies may define actions when drawdown thresholds are exceeded.

---

# Position Monitoring

Capital Protection continues monitoring open positions after execution.

Typical monitoring includes:

- unrealized loss
- stop-loss status
- trailing stop status
- abnormal price movement
- exposure changes

Position monitoring enables timely intervention when configured thresholds are exceeded.

---

# Progressive Protection

Protection measures may increase progressively as portfolio risk increases.

Examples include:

Normal Operation

↓

Warning

↓

Restricted Trading

↓

Trading Suspension

↓

Emergency Stop

The progression is governed by configurable Capital Protection Policies.

---

# Emergency Response

Capital Protection may trigger emergency actions when necessary.

Examples include:

- disable new buy orders
- suspend live trading
- require manual approval
- activate emergency stop
- notify administrators

Emergency actions should be fully auditable.

---

# Interaction with Operational Controls

Capital Protection may activate Operational Controls.

Examples include:

- Trading Freeze
- Read-Only Mode
- Emergency Stop
- Broker Disable

Operational Controls provide the mechanism through which Capital Protection influences platform behaviour.

---

# Interaction with Execution

Execution should consult Capital Protection before processing new trades.

Execution should also respond appropriately if Capital Protection activates restrictions during live operation.

This ensures that capital preservation remains effective even after trading has begun.

---

# Explainability

Every Capital Protection action should be explainable.

Users should understand:

- which protection mechanism activated
- why it activated
- which policy triggered it
- affected portfolio state
- resulting operational action

Capital Protection should never perform unexplained interventions.

---

# Future Extensions

The architecture supports future capabilities including:

- volatility-aware protection
- market regime detection
- AI-assisted protection
- dynamic drawdown limits
- adaptive stop-loss management
- broker-specific safeguards

Future enhancements should extend existing protection mechanisms rather than introducing parallel architectures.

---

# Design Principles

Capital Protection shall:

- preserve capital before maximizing returns
- remain configuration-driven
- remain deterministic
- support explainability
- support auditability
- integrate with Operational Controls
- remain independent of broker implementation

Capital Protection safeguards the portfolio.

It does not replace Risk Evaluation or trading strategy.

---

# Summary

Capital Protection provides the final defensive layer of the Live Trading architecture.

By continuously monitoring portfolio health and activating configurable safeguards when necessary, it ensures that trading remains disciplined, explainable and resilient under both normal and exceptional market conditions.

# 10. Risk Decision Lifecycle

## Overview

The Risk Decision Lifecycle defines how a Risk Decision is created, progresses through its lifecycle and is ultimately consumed by downstream components.

A Risk Decision represents the authoritative outcome of a Risk Evaluation Run.

It is the only output that may be consumed by the Execution Engine when determining whether a trade may proceed.

The Execution Engine shall never independently evaluate risk.

---

# Purpose

The Risk Decision Lifecycle exists to:

- standardize risk outcomes
- provide deterministic execution decisions
- simplify downstream processing
- improve explainability
- support auditing
- support future automation

The lifecycle defines the evolution of the Risk Decision itself rather than the trade.

---

# Risk Decision States

Every Risk Decision progresses through a well-defined lifecycle.

```text
Created
        │
        ▼
Evaluating
        │
        ▼
Completed
        │
        ├────────────► Approved
        │
        ├────────────► Approved with Warnings
        │
        ├────────────► Rejected
        │
        └────────────► Failed
```

The lifecycle terminates once one of the terminal outcomes is reached.

---

# State Definitions

### Created

The Risk Evaluation Run has been initialized.

No policy evaluation has yet occurred.

---

### Evaluating

Risk Policies are actively being evaluated.

Portfolio state, cash availability, exposure and operational constraints are examined.

The decision is not yet available.

---

### Completed

Evaluation has finished successfully.

A terminal decision has been produced.

---

### Approved

All mandatory Risk Policies passed successfully.

The recommendation is eligible for execution.

Execution remains subject to the configured trading mode.

---

### Approved with Warnings

Mandatory policies passed.

One or more advisory policies generated warnings.

Execution is permitted.

Warnings should be presented to the user and recorded for audit purposes.

---

### Rejected

One or more mandatory policies failed.

Execution is prohibited.

The rejection reasons shall be recorded.

---

### Failed

Risk Evaluation could not be completed safely.

Examples include:

- missing portfolio state
- unavailable market data
- invalid policy configuration
- internal evaluation failure

Failed evaluations shall be treated as rejected unless explicitly configured otherwise.

---

# Decision Contents

Every Risk Decision should contain:

Decision Information

- decision identifier
- evaluation timestamp
- originating Recommendation
- originating Risk Evaluation Run

Evaluation Summary

- overall decision
- evaluated policy count
- passed policy count
- failed policy count
- warning count

Supporting Evidence

- evaluated policies
- policy versions
- rejection reasons
- warnings
- explanatory messages

Execution Information

- approved investment amount
- approved quantity
- execution eligibility

---

# Consumption

The Risk Decision is consumed by:

- Execution Engine
- User Interface
- Audit subsystem
- Reporting subsystem
- Monitoring subsystem
- Future AI services

Every consumer receives the same authoritative decision.

Consumers should not reinterpret policy outcomes.

---

# Immutability

Once completed, a Risk Decision is immutable.

Historical decisions shall never be modified.

If portfolio conditions change, a new Risk Evaluation Run shall be performed.

The existing Risk Decision remains part of the historical audit trail.

---

# Expiration

A Risk Decision represents the portfolio state at a specific point in time.

Implementations may define an expiration policy.

Typical reasons for expiration include:

- significant market movement
- portfolio modification
- cash balance changes
- policy updates
- elapsed time

Expired decisions should trigger a new Risk Evaluation.

Historical decisions remain available for audit.

---

# Explainability

Every Risk Decision shall provide sufficient information to explain:

- why the decision was reached
- which policies influenced the outcome
- which constraints were violated
- why execution was permitted or denied

Users should never receive unexplained approvals or rejections.

---

# Auditability

Every Risk Decision shall remain traceable to:

- Recommendation
- Risk Evaluation Run
- Risk Policies
- Policy Versions
- Portfolio Snapshot
- Evaluation Timestamp

This information enables complete historical reconstruction of the evaluation.

---

# Design Principles

Risk Decisions shall:

- remain deterministic
- remain immutable
- remain explainable
- remain auditable
- remain implementation-independent
- represent the authoritative outcome of Risk Evaluation

The Risk Decision is the contract between Risk Management and Execution.

---

# Summary

The Risk Decision Lifecycle standardizes how risk evaluation outcomes are created, communicated and consumed throughout the Live Trading subsystem.

By providing a single authoritative and immutable decision, the platform ensures consistent execution behaviour, simplifies downstream processing and supports explainable, auditable and automation-ready trading.

# 11. Risk Artifacts

## Overview

Risk Artifacts are the persistent outputs produced by the Risk Management subsystem.

They capture the outcome of Risk Evaluation and provide a permanent, auditable record of every risk assessment performed by the platform.

Risk Artifacts are business records.

They are not execution processes.

---

# Purpose

Risk Artifacts exist to:

- preserve Risk Evaluation outcomes
- support execution decisions
- enable historical analysis
- provide audit evidence
- improve explainability
- support reporting
- support future AI-assisted analysis

Every completed Risk Evaluation Run should produce one or more Risk Artifacts.

---

# Artifact Types

The Risk Management subsystem may produce several categories of Artifacts.

Primary Artifacts

- Risk Decision

Supporting Artifacts

- Policy Evaluation Results
- Position Sizing Result
- Exposure Assessment
- Cash Allocation Summary
- Capital Protection Assessment

Future versions may introduce additional Risk Artifact types.

---

# Risk Decision Artifact

The Risk Decision Artifact represents the authoritative outcome of Risk Evaluation.

Typical information includes:

- Decision Identifier
- Evaluation Timestamp
- Recommendation Identifier
- Decision Outcome
- Execution Eligibility
- Approved Quantity
- Approved Investment Amount

The Risk Decision Artifact serves as the contractual input to the Execution Engine.

---

# Policy Evaluation Artifact

Policy Evaluation Artifacts record the outcome of every evaluated Risk Policy.

Typical information includes:

- Policy Identifier
- Policy Version
- Evaluation Result
- Supporting Evidence
- Warning Messages
- Failure Reasons

These Artifacts improve explainability and simplify troubleshooting.

---

# Position Sizing Artifact

The Position Sizing Artifact records how the proposed investment amount was determined.

Typical information includes:

- recommended investment amount
- recommended quantity
- allocation percentage
- limiting policies
- available cash
- remaining cash

This Artifact enables complete transparency into sizing decisions.

---

# Exposure Assessment Artifact

The Exposure Assessment Artifact records the portfolio exposure resulting from the proposed trade.

Typical information includes:

- current exposure
- projected exposure
- concentration analysis
- diversification metrics
- exposure policy outcomes

This Artifact supports portfolio analysis and future optimization.

---

# Cash Allocation Artifact

The Cash Allocation Artifact records how trading capital was allocated during evaluation.

Typical information includes:

- available cash
- reserved cash
- invested capital
- proposed allocation
- remaining balance

Future implementations may integrate this Artifact with the Portfolio subsystem.

---

# Capital Protection Artifact

Capital Protection Artifacts record any protective actions or warnings generated during evaluation.

Examples include:

- drawdown warning
- capital protection trigger
- trading restriction
- emergency recommendation

These Artifacts provide operational visibility into portfolio protection.

---

# Relationship with Runs

Every Risk Artifact shall reference:

- Risk Evaluation Run
- Recommendation
- Portfolio Snapshot
- Policy Versions

This enables complete traceability.

---

# Relationship with Execution

Execution consumes the Risk Decision Artifact.

Execution should never reinterpret individual Policy Evaluation Artifacts.

The Risk Decision remains the authoritative execution contract.

---

# Relationship with Reporting

Reporting systems may aggregate Risk Artifacts to produce:

- approval statistics
- rejection analysis
- policy effectiveness
- exposure trends
- capital utilization reports
- portfolio risk reports

Historical Risk Artifacts remain immutable.

---

# Relationship with Audit

Risk Artifacts form part of the permanent audit trail.

Users should always be able to determine:

- what was evaluated
- what decision was reached
- why the decision was reached
- which policies influenced the outcome
- when evaluation occurred

Historical Risk Artifacts should never be modified.

---

# Lifecycle

Risk Artifacts generally follow a simple lifecycle.

```text
Created
        │
        ▼
Published
        │
        ▼
Referenced
        │
        ▼
Archived
```

Artifacts do not possess complex execution lifecycles.

They represent completed business outcomes.

---

# Design Principles

Risk Artifacts shall:

- remain immutable
- remain traceable
- remain explainable
- remain reusable
- remain implementation-independent
- support historical analysis

Artifacts represent completed facts.

They do not participate in execution.

---

# Summary

Risk Artifacts provide the permanent business record of Risk Management activities.

By preserving Risk Evaluation outcomes, policy results and supporting evidence, they enable explainable execution, comprehensive auditing, historical reporting and future analytical capabilities while maintaining clear separation from execution logic.

# 12. Monitoring and Reporting

## Overview

The Monitoring and Reporting subsystem provides operational visibility into the behaviour and effectiveness of Risk Management.

Its objective is to ensure that Risk Evaluation remains observable, measurable and auditable throughout the lifecycle of the Live Trading platform.

Monitoring focuses on the operational health of the subsystem.

Reporting focuses on historical analysis and business insights.

---

# Objectives

Monitoring and Reporting exist to:

- monitor Risk Evaluation health
- identify operational issues
- measure policy effectiveness
- provide historical analysis
- support auditing
- support troubleshooting
- improve confidence in automated trading

Every Risk Evaluation should contribute operational and analytical information.

---

# Monitoring Architecture

Monitoring operates continuously while Risk Management is active.

The conceptual flow is:

```text
Risk Evaluation Run
        │
        ▼
Risk Artifacts
        │
        ▼
Monitoring Metrics
        │
        ▼
Operational Dashboard
```

Monitoring should observe the subsystem without influencing its behaviour.

---

# Operational Metrics

The platform should capture operational metrics including:

Evaluation Metrics

- evaluations started
- evaluations completed
- evaluations failed
- evaluation duration
- average evaluation time

Decision Metrics

- approvals
- approvals with warnings
- rejections
- failures

Policy Metrics

- policy evaluation count
- policy failures
- policy warnings
- most frequently triggered policies

Execution Metrics

- approved trades executed
- approved trades cancelled
- rejected trades prevented

Operational metrics should be available for dashboard visualization.

---

# Reporting

Reporting provides historical summaries of Risk Management activity.

Typical reports include:

- approval history
- rejection history
- policy effectiveness
- exposure trends
- cash utilization
- portfolio concentration
- capital protection events

Reports should support configurable date ranges.

---

# Explainability Reports

Every Risk Decision should be explainable through reporting.

Typical report contents include:

- evaluated recommendation
- policy outcomes
- warnings
- rejection reasons
- approved allocation
- exposure analysis
- evaluation timeline

These reports improve transparency and user confidence.

---

# Policy Effectiveness

The platform should support analysis of individual Risk Policies.

Examples include:

- number of evaluations
- number of approvals
- number of rejections
- warning frequency
- policy utilization

This information helps refine Risk Policies over time.

---

# Dashboard

The Risk Management dashboard may present:

Operational Status

- subsystem health
- active policies
- evaluation throughput
- recent failures

Business Metrics

- approval rate
- rejection rate
- capital utilization
- average allocation
- average exposure

Portfolio Metrics

- current exposure
- available cash
- reserved cash
- protection status

The dashboard should provide both operational and business visibility.

---

# Alerts

Monitoring may generate alerts when abnormal conditions occur.

Examples include:

- repeated evaluation failures
- unusually high rejection rate
- policy configuration errors
- unavailable dependencies
- emergency controls activated

Alerts improve operational awareness without interrupting normal execution.

---

# Audit Reporting

Audit reports should support complete reconstruction of historical decisions.

Users should be able to determine:

- what recommendation was evaluated
- when evaluation occurred
- which policies participated
- why the decision was reached
- who initiated the evaluation
- what execution followed

Audit reports should remain immutable.

---

# Performance Monitoring

The subsystem should monitor:

- evaluation latency
- policy evaluation latency
- throughput
- resource utilization
- failure rates

Performance metrics support future optimization without affecting architectural behaviour.

---

# Future Extensions

The architecture supports future capabilities including:

- real-time dashboards
- AI-assisted anomaly detection
- predictive risk analytics
- policy optimization recommendations
- enterprise reporting
- compliance reporting

These enhancements extend Monitoring and Reporting without changing the Risk Evaluation architecture.

---

# Design Principles

Monitoring and Reporting shall:

- remain passive observers
- avoid influencing execution
- support explainability
- support auditing
- support historical analysis
- remain implementation-independent

Operational visibility should never alter Risk Evaluation outcomes.

---

# Summary

Monitoring and Reporting provide operational and analytical visibility into the Risk Management subsystem.

By continuously observing Risk Evaluation behaviour and preserving historical decision information, the platform enables reliable operation, effective troubleshooting, continuous improvement and confident progression toward fully automated trading.

# 13. Extension Model

## Overview

The Risk Management subsystem is designed to evolve through extension rather than architectural redesign.

Future capabilities should integrate with the existing Risk Management architecture by introducing new Risk Policies, evaluation modules or analytical capabilities while preserving the established execution model.

The objective is to ensure that Risk Management remains scalable, maintainable and adaptable to changing trading requirements.

---

# Extension Philosophy

Risk Management should evolve by extending existing architectural components.

Preferred order of evolution:

```text
Reuse Existing Policy

↓

Extend Existing Policy Category

↓

Introduce New Policy Category

↓

Extend Risk Evaluation Pipeline

↓

Introduce New Architectural Component (Exceptional)
```

The existing architecture should be reused wherever practical.

---

# Extending Risk Policies

New trading requirements should first be addressed through additional Risk Policies.

Examples include:

- ESG investment rules
- Tax-aware allocation
- Market regime restrictions
- Strategy-specific exposure rules
- Country allocation limits
- Currency exposure limits

The Risk Evaluation Engine should require no modification unless the policy introduces fundamentally new evaluation behaviour.

---

# Extending the Evaluation Pipeline

Additional evaluation stages may be inserted into the Risk Evaluation Pipeline.

Examples include:

```text
Portfolio Evaluation

↓

Liquidity Evaluation

↓

Volatility Evaluation

↓

Position Evaluation

↓

Operational Evaluation
```

New stages should have:

- a clearly defined responsibility
- deterministic behaviour
- explainable outputs
- independent evaluation logic

Pipeline extensions should preserve the overall execution model.

---

# Extending Position Sizing

Future sizing methodologies may be introduced.

Examples include:

- volatility-based sizing
- ATR-based sizing
- Kelly Criterion
- equal-risk contribution
- AI-assisted sizing

The Position Sizing interface should remain stable while allowing multiple sizing implementations.

---

# Extending Exposure Management

Future exposure dimensions may include:

- correlation exposure
- factor exposure
- beta exposure
- geographic exposure
- currency exposure
- ESG exposure

Exposure calculations should remain modular and independently configurable.

---

# Extending Capital Protection

Future protection mechanisms may include:

- adaptive stop-losses
- portfolio circuit breakers
- volatility-triggered restrictions
- AI-assisted capital preservation
- dynamic exposure reduction

Protection mechanisms should integrate through existing Capital Protection interfaces.

---

# Extending Automation

Risk Management should support increasing levels of trading automation.

Examples include:

Manual

↓

Semi-Automatic

↓

Fully Automatic

↓

Autonomous Portfolio Management

The underlying Risk Evaluation architecture should remain unchanged.

Only approval workflows and execution behaviour should evolve.

---

# AI Integration

Future AI capabilities may assist Risk Management.

Examples include:

- risk explanations
- anomaly detection
- policy recommendations
- exposure analysis
- portfolio optimization
- scenario simulation

AI may provide recommendations.

Final Risk Decisions should remain deterministic and governed by configured Risk Policies unless an explicitly AI-driven operating mode is introduced in the future.

---

# Multi-Broker Support

The architecture supports future execution across multiple brokers.

Risk Management should remain broker-independent.

Broker-specific constraints should be introduced through broker capability models rather than embedded into Risk Evaluation logic.

---

# Future Asset Classes

The architecture should support future asset classes including:

- ETFs
- Mutual Funds
- Futures
- Options
- Commodities
- Cryptocurrencies

Where asset classes introduce additional risk dimensions, those should extend existing policy categories rather than replacing them.

---

# Backward Compatibility

Future enhancements should preserve compatibility wherever practical.

Existing:

- Risk Policies
- Risk Decisions
- Risk Artifacts
- Evaluation Runs

should remain valid after architectural extensions.

Where incompatible changes are unavoidable, migration guidance should be provided.

---

# Architectural Review

Every significant extension should be reviewed to ensure that it:

- preserves architectural consistency
- maintains deterministic behaviour
- supports explainability
- remains configuration-driven
- avoids duplication
- respects Platform Architecture principles

New architectural concepts should only be introduced when existing abstractions cannot reasonably model the required capability.

---

# Summary

The Risk Management subsystem is intended to evolve through disciplined extension rather than continual redesign.

By extending existing policies, evaluation stages and analytical capabilities while preserving the established architecture, StoX can support increasingly sophisticated risk management without compromising maintainability, explainability or long-term architectural stability.

---

# Appendix A — Canonical Risk Flows

## Overview

This appendix illustrates the canonical execution patterns of the Risk Management subsystem.

These flows are normative examples intended to demonstrate how the architectural components collaborate during risk evaluation.

Future implementations should follow these patterns wherever practical.

---

# Flow 1 — Successful Risk Evaluation

The recommendation satisfies all applicable Risk Policies.

```text
Recommendation
        │
        ▼
Load Evaluation Context
        │
        ▼
Load Risk Policies
        │
        ▼
Portfolio Evaluation
        │
        ▼
Cash Evaluation
        │
        ▼
Position Sizing
        │
        ▼
Exposure Evaluation
        │
        ▼
Capital Protection
        │
        ▼
Risk Decision
        │
        ▼
Approved
        │
        ▼
Execution Engine
```

Outcome:

- Trade approved
- Risk Decision Artifact created
- Risk Approved Event published

---

# Flow 2 — Risk Rejection

One or more mandatory Risk Policies fail.

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Policy Failure
        │
        ▼
Risk Decision
        │
        ▼
Rejected
        │
        ▼
Execution Blocked
```

Outcome:

- No broker communication
- Risk Decision Artifact created
- Risk Rejected Event published

---

# Flow 3 — Insufficient Cash

The recommendation is valid but sufficient trading capital is unavailable.

```text
Recommendation
        │
        ▼
Cash Evaluation
        │
        ▼
Available Cash Check
        │
        ▼
Insufficient Capital
        │
        ▼
Risk Decision
        │
        ▼
Rejected
```

The resulting decision should clearly identify the cash constraint responsible for the rejection.

---

# Flow 4 — Exposure Limit Exceeded

The proposed trade would exceed configured exposure limits.

```text
Recommendation
        │
        ▼
Position Sizing
        │
        ▼
Exposure Evaluation
        │
        ▼
Exposure Policy Failure
        │
        ▼
Risk Decision
        │
        ▼
Rejected
```

The Risk Decision should identify the violated Exposure Policy.

---

# Flow 5 — Approved with Warnings

Mandatory policies pass while advisory policies generate warnings.

```text
Recommendation
        │
        ▼
Risk Evaluation
        │
        ▼
Policy Warnings
        │
        ▼
Risk Decision
        │
        ▼
Approved with Warnings
        │
        ▼
Execution Engine
```

Warnings remain attached to the Risk Decision for user review and audit purposes.

---

# Flow 6 — Operational Protection

Trading is temporarily unavailable.

```text
Recommendation
        │
        ▼
Operational Evaluation
        │
        ▼
Trading Disabled
        │
        ▼
Risk Decision
        │
        ▼
Rejected
```

Examples include:

- Maintenance Mode
- Emergency Stop
- Trading Freeze
- Broker Unavailable

Operational restrictions always take precedence over trading opportunities.

---

# Flow 7 — Capital Protection Trigger

The portfolio has exceeded configured protection thresholds.

```text
Portfolio Monitoring
        │
        ▼
Capital Protection
        │
        ▼
Protection Policy Triggered
        │
        ▼
Operational Control Activated
        │
        ▼
Trading Suspended
```

Examples include:

- maximum drawdown exceeded
- maximum daily loss exceeded
- emergency capital preservation threshold reached

---

# Flow 8 — Complete Risk Evaluation Lifecycle

The complete lifecycle of a recommendation.

```text
Recommendation
        │
        ▼
Risk Evaluation Run
        │
        ▼
Policy Evaluation
        │
        ▼
Cash Management
        │
        ▼
Position Sizing
        │
        ▼
Exposure Management
        │
        ▼
Capital Protection
        │
        ▼
Risk Decision Artifact
        │
        ▼
Risk Event
        │
        ▼
Execution Engine
```

Every successful evaluation follows this conceptual lifecycle.

---

# Canonical Risk Architecture

The Risk Management subsystem follows a consistent architectural pattern.

```text
Risk Policies
        │
        ▼
Risk Policy Registry
        │
        ▼
Risk Evaluation Engine
        │
        ▼
Risk Evaluation Run
        │
        ▼
Risk Decision Artifact
        │
        ▼
Risk Events
```

Supporting components contribute during evaluation.

```text
                Cash Management
                       │
                       ▼

Recommendation → Risk Evaluation → Risk Decision

                       ▲
                       │
                Position Sizing

                       ▲
                       │
               Exposure Management

                       ▲
                       │
              Capital Protection
```

The Risk Evaluation Engine coordinates these components to produce a single authoritative Risk Decision.

---

# Summary

The canonical flows presented in this appendix demonstrate how Risk Management evaluates recommendations, applies configurable policies, protects trading capital and communicates deterministic execution decisions.

Future enhancements should extend these execution patterns rather than introducing alternative architectural models, ensuring consistency across all Live Trading capabilities.
