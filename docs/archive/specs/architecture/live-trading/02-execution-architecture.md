Chapter 1
Execution Philosophy

Chapter 2
Execution Domain Model

Chapter 3
Execution Pipeline

Chapter 4
Execution Run

Chapter 5
Order Basket

Chapter 6
Execution Policies

Chapter 7
Execution Modes

Chapter 8
Broker Abstraction

Chapter 9
Broker Capabilities

Chapter 10
State Machines

Chapter 11
Failure Recovery

Chapter 12
Idempotency

Chapter 13
Future Compatibility

# Chapter 1 – Execution Philosophy

## Purpose

This chapter defines the fundamental philosophy behind the StoX Execution Architecture.

Rather than describing implementation details, this chapter establishes the architectural principles that govern every execution workflow within the Live Trading subsystem.

All subsequent execution-related specifications SHALL conform to the principles defined in this chapter.

---

# Objectives

The Execution Architecture exists to transform investment decisions into broker executions while maintaining complete separation between investment logic, execution logic, security, risk management, and broker integrations.

The architecture is designed to satisfy the following goals:

- Broker independence
- Deterministic execution
- Strong auditability
- Progressive automation
- Safety-first execution
- Extensibility
- Predictable behaviour

---

# Philosophy

Execution is not investing.

Execution is the controlled process of translating an investment decision into one or more broker operations.

Investment decisions determine **what should happen**.

Execution determines **how it should happen**.

The architecture deliberately separates these responsibilities.

---

# Principle 1 – Separation of Decision and Execution

Strategies SHALL never communicate directly with brokers.

Strategies SHALL never decide:

- Broker
- Order Type
- Exchange
- Quantity Rounding
- Retry Behaviour
- Partial Fill Behaviour
- Timeout Behaviour

Strategies only express investment intent.

Examples:

- Open Position
- Increase Position
- Reduce Position
- Exit Position

The Execution subsystem converts these intents into executable broker orders.

---

# Principle 2 – Execution Intent

The primary input to the Execution subsystem is an Execution Intent.

Execution Intent represents a desired portfolio action rather than a broker instruction.

Examples include:

Open Position

Increase Position

Reduce Position

Exit Position

Execution Intent deliberately avoids broker terminology.

Execution Intent does NOT specify:

- BUY
- SELL
- LIMIT
- MARKET
- CNC
- MIS
- Product Type

These are execution concerns.

---

# Principle 3 – Single Responsibility

Every execution component owns exactly one responsibility.

Example responsibilities include:

Recommendation Engine

Produces investment recommendations.

Execution Engine

Coordinates execution workflow.

Risk Engine

Approves or rejects execution.

Execution Policy Engine

Determines execution behaviour.

Broker Adapter

Communicates with external brokers.

Monitoring

Observes execution.

Audit

Records execution.

No component shall perform another component's responsibility.

---

# Principle 4 – Stateless Execution

Execution decisions should be derived from explicit inputs.

Hidden state should be avoided.

Where execution state exists, it shall be explicitly represented by domain objects.

Examples include:

Execution Run

Order Basket

Broker Order

Broker Execution

Trade

Portfolio

---

# Principle 5 – Broker Independence

Execution Architecture shall never assume any specific broker.

All broker-specific behaviour must remain isolated behind Broker Adapters.

Future brokers should be added without modifying execution logic.

---

# Principle 6 – Progressive Automation

Automation is not binary.

Execution shall support progressively increasing levels of automation.

Recommendation Only

↓

Semi-Automatic

↓

Supervised Automatic

↓

Fully Automatic

Each level removes one layer of human interaction.

No level removes Risk Validation or Security Validation.

---

# Principle 7 – Risk Before Broker

Every broker submission SHALL pass Risk Validation.

Risk validation is mandatory.

Risk validation cannot be disabled by Execution Mode.

Risk Policies remain independent of:

- Strategy
- Broker
- Automation

---

# Principle 8 – Security Before Execution

Every security-sensitive operation shall require appropriate authentication.

Examples include:

Connecting Broker

Unlocking Trading Session

Enabling Automation

Changing Execution Mode

Disabling Kill Switch

Approving Orders

Security requirements are defined separately by the Security specification.

---

# Principle 9 – Auditability

Every significant execution event shall be auditable.

Audit history shall include:

- Who
- What
- When
- Why
- Result

Execution must always be explainable after completion.

---

# Principle 10 – Explainability

The system shall always be capable of answering questions such as:

Why was this order created?

Why was this order rejected?

Why was this quantity selected?

Why was this broker chosen?

Why was this trade executed?

Every execution decision shall therefore preserve sufficient context for future explanation.

---

# Principle 11 – Failure Isolation

Failures should remain isolated.

Failure of:

- One Order
- One Broker
- One Portfolio

shall not unnecessarily affect unrelated execution activities.

Execution shall degrade gracefully whenever possible.

---

# Principle 12 – Deterministic Behaviour

Given identical:

- Market Data
- Portfolio
- Strategy
- Recommendation Cycle
- Risk Policy
- Execution Policy

the Execution subsystem shall always produce identical Execution Intents and Broker Orders.

---

# Architectural Summary

The Execution subsystem transforms investment decisions into broker executions without allowing investment logic, risk management, broker communication, security, or monitoring responsibilities to become coupled.

Execution Architecture is therefore a translation layer rather than an investment engine.

# Section 2 – Execution Domain Model

## Purpose

This section defines the primary business entities that participate in the Live Trading subsystem.

These entities represent business concepts rather than implementation objects such as database tables, API payloads, or programming classes.

Every execution workflow shall operate using these domain entities.

---

# Design Principles

The execution domain model follows the following principles.

- Every entity has a single business responsibility.
- Every entity has a well-defined lifecycle.
- Every entity owns its own state.
- Entities communicate only through clearly defined relationships.
- Execution shall always be explainable by traversing these entities.

---

# Domain Model Overview

The Live Trading subsystem consists of the following primary entities.

Recommendation Cycle

↓

Recommendations

↓

Execution Run

↓

Execution Context

↓

Order Basket

↓

Execution Intent

↓

Broker Order

↓

Broker Execution

↓

Portfolio Transaction

↓

Trade

Each entity has a distinct responsibility.

No entity shall duplicate the responsibility of another entity.

---

# Recommendation Cycle

## Purpose

A Recommendation Cycle represents one complete execution of the Strategy Engine for a Portfolio.

The Recommendation Cycle is the boundary between the Decision subsystem and the Execution subsystem.

## Responsibilities

A Recommendation Cycle shall:

- identify eligible securities
- evaluate the active Strategy
- generate Recommendations
- preserve the reasoning behind each Recommendation

The Recommendation Cycle SHALL NOT perform execution.

---

# Recommendation

## Purpose

A Recommendation represents an investment decision.

Examples include:

- Open Position
- Increase Position
- Reduce Position
- Exit Position
- Hold
- Watch

Recommendations are immutable.

Once generated, Recommendations shall never be modified.

Execution consumes Recommendations but never edits them.

---

# Execution Run

## Purpose

An Execution Run represents one complete attempt to execute Recommendations.

An Execution Run groups all execution activities that belong to the same execution session.

Examples:

- Daily scheduled execution
- User initiated execution
- Semi-automatic execution
- Fully automatic execution

## Responsibilities

Execution Run owns:

- execution start
- execution completion
- execution status
- execution statistics
- execution summary

Execution Run coordinates execution but does not execute individual orders.

---

# Execution Context

## Purpose

Execution Context represents the runtime environment in which an Execution Run operates.

Execution Context exists only during execution.

It is not intended to represent permanent business data.

## Typical Context

Execution Context may contain:

- Portfolio
- Active Strategy
- Recommendation Cycle
- Execution Mode
- Risk Policy
- Execution Policy
- Broker
- Trading Session
- User
- Market Snapshot

Execution Context provides information required by downstream execution components.

---

# Order Basket

## Purpose

Order Basket represents the complete set of executable trading actions prepared for one Execution Run.

Order Basket exists independently of broker implementation.

It represents business intent rather than broker requests.

## Responsibilities

Order Basket:

- groups executable actions
- supports user review
- supports approval workflows
- supports editing where permitted
- preserves execution ordering

Order Basket SHALL NOT communicate directly with brokers.

---

# Execution Intent

## Purpose

Execution Intent represents a desired portfolio action.

Execution Intent is intentionally broker-independent.

Examples include:

- Open Position
- Increase Position
- Reduce Position
- Exit Position

Execution Intent SHALL NOT contain broker-specific concepts such as:

- BUY
- SELL
- LIMIT
- MARKET
- Product Type
- Exchange

These decisions belong to later execution stages.

---

# Broker Order

## Purpose

Broker Order represents a broker-compatible instruction generated from one Execution Intent.

Examples include:

- BUY 120 shares
- SELL 50 shares
- LIMIT order
- MARKET order

Broker Orders are broker-specific.

Different brokers may require different Broker Orders for the same Execution Intent.

---

# Broker Execution

## Purpose

Broker Execution represents the broker's response to a submitted Broker Order.

Examples include:

- Accepted
- Filled
- Partially Filled
- Rejected
- Cancelled
- Expired

Broker Execution records what actually happened in the external trading system.

---

# Portfolio Transaction

## Purpose

Portfolio Transaction represents the financial effect of completed Broker Executions on the Portfolio.

Portfolio Transactions update:

- Holdings
- Cash
- Cost Basis
- Position Size
- Portfolio History

Portfolio Transactions are internal accounting records.

They are not broker instructions.

---

# Trade

## Purpose

A Trade represents the complete lifecycle of an investment position.

A Trade begins when a position is first opened.

A Trade ends when the position has been completely closed.

A Trade may contain multiple Transactions.

Example:

Buy

↓

Increase

↓

Partial Exit

↓

Final Exit

↓

Trade Closed

Trade performance is measured over the complete lifecycle rather than individual Transactions.

---

# Entity Relationships

The relationship between entities is illustrated below.

Recommendation Cycle

contains

↓

Recommendations

Execution Run

consumes

↓

Recommendations

Execution Run

creates

↓

Order Basket

Order Basket

contains

↓

Execution Intents

Execution Intents

produce

↓

Broker Orders

Broker Orders

produce

↓

Broker Executions

Broker Executions

produce

↓

Portfolio Transactions

Portfolio Transactions

update

↓

Trades

---

# Entity Ownership

Every entity owns exactly one responsibility.

| Entity                | Owns                     |
| --------------------- | ------------------------ |
| Recommendation Cycle  | Investment decisions     |
| Recommendation        | Portfolio intent         |
| Execution Run         | Execution coordination   |
| Execution Context     | Runtime environment      |
| Order Basket          | Executable work          |
| Execution Intent      | Desired portfolio action |
| Broker Order          | Broker instruction       |
| Broker Execution      | Broker result            |
| Portfolio Transaction | Portfolio accounting     |
| Trade                 | Investment lifecycle     |

No ownership shall overlap.

---

# Design Constraints

The following constraints shall always hold.

- Recommendations remain immutable.
- Execution never modifies investment decisions.
- Broker-specific data appears only after Broker Order creation.
- Portfolio updates occur only after Broker confirmation.
- Every Broker Execution must be traceable to exactly one Execution Intent.
- Every Portfolio Transaction must be traceable to one or more Broker Executions.
- Every Trade must be reconstructable from Portfolio Transactions.

These constraints ensure deterministic, auditable, and explainable execution.

# Section 3 – Execution Pipeline

## Purpose

This section defines the canonical execution pipeline used by the Live Trading subsystem.

Every execution workflow, regardless of Execution Mode, Broker, or Automation Level, SHALL follow this pipeline.

Execution Modes may introduce or remove human interaction points, but they SHALL NOT alter the fundamental execution stages.

---

# Design Principles

The execution pipeline follows these principles:

- Each stage performs exactly one responsibility.
- Each stage has clearly defined inputs and outputs.
- Stages are deterministic.
- Stages are independently testable.
- Failures are isolated to the current stage.
- Every transition is auditable.

---

# Canonical Execution Pipeline

The Live Trading subsystem shall execute work using the following pipeline.

Recommendation Run

↓

Execution Run Created

↓

Execution Context Built

↓

Order Basket Generated

↓

User Review (if applicable)

↓

Execution Policies Applied

↓

Risk Validation

↓

Broker Translation

↓

Broker Submission

↓

Broker Response Processing

↓

Portfolio Update

↓

Notifications

↓

Audit Logging

↓

Monitoring & Metrics

↓

Execution Run Completed

---

# Stage 1 – Recommendation Run

## Input

Completed Recommendation Run.

## Output

Immutable Recommendations.

## Responsibility

The Recommendation subsystem completes its work and hands control to the Execution subsystem.

Execution SHALL NOT modify Recommendations.

---

# Stage 2 – Execution Run Creation

## Input

Recommendations.

## Output

Execution Run.

## Responsibility

Create a new Execution Run.

Assign:

- Run Identifier
- Portfolio
- Strategy
- Execution Mode
- Broker
- Start Time

Execution Run becomes the parent container for every execution artifact generated during this process.

---

# Stage 3 – Build Execution Context

## Input

Execution Run.

## Output

Execution Context.

## Responsibility

Collect all runtime information required by downstream stages.

Typical context includes:

- Portfolio
- Holdings
- Cash
- Broker
- Trading Session
- Risk Policy
- Execution Policy
- Market Snapshot
- User
- Automation Settings

Execution Context exists only during execution.

---

# Stage 4 – Generate Order Basket

## Input

Recommendations
Execution Context

## Output

Order Basket

## Responsibility

Convert executable Recommendations into Execution Intents.

Ignore:

- Hold
- Watch

Generate Execution Intents for:

- Open Position
- Increase Position
- Reduce Position
- Exit Position

No broker-specific decisions occur at this stage.

---

# Stage 5 – User Review

## Purpose

Allow human review where required by the configured Execution Mode.

Possible actions include:

- Approve
- Reject
- Edit Quantity
- Edit Price
- Remove Order
- Cancel Basket

Automatic modes bypass this stage.

---

# Stage 6 – Apply Execution Policies

## Purpose

Execution Policies determine HOW approved Execution Intents should be executed.

Examples include:

- Limit vs Market
- Retry Rules
- Order Splitting
- Time in Force
- Execution Priority
- Order Sequencing

Execution Policies never change investment intent.

---

# Stage 7 – Risk Validation

## Purpose

Evaluate every executable action against configured Risk Policies.

Typical validations include:

- Daily Loss Limits
- Position Size
- Cash Availability
- Maximum Exposure
- Trading Window
- Circuit Filters
- Portfolio Constraints

Any failed validation shall prevent Broker Submission for the affected Execution Intent.

Risk validation shall never be bypassed.

---

# Stage 8 – Broker Translation

## Purpose

Convert broker-independent Execution Intents into broker-specific Broker Orders.

Examples include:

Execution Intent

↓

Open Position

↓

Broker Order

↓

BUY

LIMIT

250 Shares

NSE

Broker Translation is the only stage where broker-specific terminology is introduced.

---

# Stage 9 – Broker Submission

## Purpose

Submit Broker Orders through the Broker Adapter.

Submission shall be:

- auditable
- idempotent
- traceable

Execution Engine shall never communicate directly with broker APIs.

---

# Stage 10 – Broker Response Processing

## Purpose

Process broker responses.

Possible outcomes include:

- Accepted
- Filled
- Partially Filled
- Cancelled
- Rejected
- Expired

Responses update Broker Executions.

Responses do not directly update Portfolio state.

---

# Stage 11 – Portfolio Update

## Purpose

Update internal Portfolio state after broker confirmation.

Possible updates include:

- Holdings
- Cash
- Cost Basis
- Position Size
- Transactions
- Trades

Portfolio updates occur only after successful Broker confirmation.

---

# Stage 12 – Notifications

## Purpose

Generate user notifications for significant execution events.

Examples include:

- Order Executed
- Order Failed
- Risk Violation
- Execution Completed
- Broker Offline

Notifications are informational.

Notifications never imply approval.

---

# Stage 13 – Audit Logging

## Purpose

Record immutable audit events.

Audit shall include:

- User
- Time
- Action
- Result
- Execution Run
- Broker
- Portfolio

Audit records shall never be modified.

---

# Stage 14 – Monitoring

## Purpose

Publish operational metrics.

Examples include:

- Orders Submitted
- Orders Filled
- Rejections
- Success Rate
- Execution Time
- Broker Latency
- Failure Count

Monitoring data supports operational dashboards.

---

# Stage 15 – Execution Completion

Execution Run shall be marked as:

Completed

or

Completed with Warnings

or

Failed

Execution summary shall include:

- Total Recommendations
- Executed Orders
- Failed Orders
- Skipped Orders
- Risk Rejections
- Execution Duration
- Broker Statistics

Execution artifacts remain available for future review.

---

# Pipeline Invariants

The following rules always apply.

Recommendations are immutable.

↓

Execution consumes Recommendations.

↓

Execution creates new artifacts.

↓

Portfolio updates occur only after broker confirmation.

↓

Audit occurs for every significant transition.

↓

Execution Run remains the parent of every execution artifact.

These invariants shall hold regardless of Execution Mode.

---

# Failure Behaviour

Failure of one Execution Intent shall not automatically terminate the Execution Run.

Execution shall continue wherever safe.

Fatal failures shall terminate only the current Execution Run.

Subsequent Execution Runs remain unaffected.

---

# Future Compatibility

The pipeline has been intentionally designed to support future capabilities including:

- Multiple brokers
- Smart routing
- Paper trading
- Dry-run execution
- Order simulations
- Portfolio rebalancing
- Multi-account execution
- Advanced execution algorithms

These capabilities shall integrate by extending pipeline stages rather than replacing the pipeline.

# Section 4 – Order Basket

## Purpose

The Order Basket represents the complete set of executable trading actions prepared for one Execution Run.

It is the primary working object of the Execution subsystem.

The Order Basket bridges the gap between investment decisions and broker execution.

The Order Basket is broker-independent and portfolio-aware.

---

# Objectives

The Order Basket exists to:

- group all executable actions
- provide a review surface for users
- support execution workflows
- preserve execution ordering
- allow risk validation
- allow execution policy evaluation
- generate broker orders
- maintain complete traceability

---

# Design Principles

The Order Basket follows the following principles.

- Broker independent
- Immutable after execution starts
- Traceable
- Deterministic
- Reviewable
- Auditable

---

# Lifecycle

The Order Basket follows this lifecycle.

Draft

↓

Prepared

↓

Reviewed (optional)

↓

Approved

↓

Executing

↓

Completed

or

Cancelled

or

Expired

Once execution begins, the basket becomes read-only.

---

# Basket Composition

An Order Basket contains one or more Execution Intents.

Each Execution Intent represents one desired portfolio action.

Examples include:

- Open Position
- Increase Position
- Reduce Position
- Exit Position

Hold and Watch recommendations SHALL NOT produce Execution Intents.

---

# Basket Metadata

Every Order Basket SHALL record:

- Basket Identifier
- Execution Run
- Portfolio
- Strategy
- Recommendation Run
- Execution Mode
- Broker
- Creation Time
- Approval Time
- Execution Start Time
- Completion Time
- Current Status

This metadata provides complete traceability.

---

# Basket Statistics

The Order Basket SHALL maintain summary statistics including:

- Total Recommendations
- Executable Recommendations
- Total Execution Intents
- Buy Intents
- Sell Intents
- Estimated Capital Required
- Estimated Capital Released
- Net Cash Impact

Statistics are informational and may be recalculated if required.

---

# User Interaction

Depending on Execution Mode, users may perform the following actions before approval:

Approve Basket

Reject Basket

Remove Execution Intent

Modify Quantity

Modify Price

Modify Execution Priority

Split Basket

Merge Baskets (future)

Cancel Basket

The availability of these actions depends on Execution Mode and user permissions.

---

# Editing Rules

Editing is permitted only while the basket is in Draft or Prepared state.

Once Approved:

- Execution Intents become immutable.
- Basket composition cannot change.
- Quantities cannot change.
- Prices cannot change.

Any modification after approval requires generation of a new Order Basket.

---

# Validation

Before approval, the Order Basket SHALL be validated.

Validation includes:

- Duplicate securities
- Cash availability
- Invalid quantities
- Invalid execution intents
- Strategy consistency
- Portfolio consistency

Validation failures prevent approval.

---

# Ordering

The Order Basket preserves execution order.

Execution order may be determined by:

- Strategy priority
- Execution Policy
- Capital dependency
- User preference

Execution order shall remain deterministic.

---

# Dependencies

Execution Intents may declare dependencies.

Examples include:

Sell Position

↓

Release Cash

↓

Buy New Position

Execution Engine SHALL respect declared dependencies.

---

# Capital Awareness

The Order Basket SHALL understand expected capital flow.

Examples:

Expected Cash Required

Expected Cash Released

Expected Portfolio Exposure

Expected Cash Reserve

This information supports execution planning and risk validation.

---

# Execution Behaviour

During execution, the Order Basket coordinates execution progress.

Each Execution Intent maintains its own execution state.

The Basket also maintains an aggregate execution state.

Example:

10 Execution Intents

↓

7 Filled

2 Rejected

1 Pending

↓

Basket Status

Executing

---

# Completion

The Order Basket is considered Completed when every Execution Intent reaches a terminal state.

Terminal states include:

Filled

Rejected

Cancelled

Expired

Partial completion does not complete the basket.

---

# Relationship to Execution Run

One Execution Run may produce one or more Order Baskets.

Examples include:

Large portfolios

↓

Multiple baskets

Multi-broker execution

↓

Separate baskets

Paper trading

↓

Simulation basket

This architecture intentionally allows future expansion.

---

# Relationship to Broker Orders

Execution Intents generate Broker Orders.

One Execution Intent may produce:

- one Broker Order
- multiple Broker Orders
- no Broker Orders

depending on Execution Policies.

---

# Relationship to Portfolio

The Order Basket never modifies Portfolio state directly.

Portfolio changes occur only after Broker confirmation.

This ensures accounting consistency.

---

# Auditability

Every significant Basket event SHALL be recorded.

Examples include:

Basket Created

Basket Approved

Basket Modified

Basket Rejected

Execution Started

Execution Completed

Execution Cancelled

Every event must reference the parent Execution Run.

---

# Future Compatibility

The Order Basket architecture supports future capabilities including:

- Basket splitting
- Basket merging
- Multi-broker execution
- Multi-account execution
- Basket scheduling
- Basket templates
- Rebalancing baskets
- Portfolio migration baskets
- Smart execution baskets

These capabilities extend the existing model rather than replacing it.

# Section 5 – Execution Policy

## Purpose

Execution Policy defines HOW approved Execution Intents shall be translated into executable Broker Orders.

Execution Policies govern execution behaviour without altering the underlying investment decision.

Execution Policies are independent of:

- Investment Strategy
- Risk Policy
- Execution Mode
- Broker Implementation

---

# Objectives

Execution Policies exist to:

- standardize execution behaviour
- enforce execution constraints
- improve execution quality
- support future execution algorithms
- isolate execution decisions from investment decisions

Execution Policies answer the question:

"How should this investment decision be executed?"

---

# Relationship to Other Components

Investment Strategy

↓

creates

↓

Execution Intent

↓

Execution Policy

↓

creates

↓

Broker Order

↓

Broker Adapter

↓

External Broker

Execution Policies SHALL NOT modify the underlying investment intent.

---

# Scope

Execution Policies may determine:

- Order Type
- Execution Priority
- Execution Sequence
- Order Splitting
- Order Grouping
- Quantity Rounding
- Price Selection
- Time-in-Force
- Retry Behaviour
- Timeout Behaviour
- Expiry Behaviour

Execution Policies SHALL NOT determine:

- Which stock to buy
- Which stock to sell
- Position sizing
- Portfolio allocation
- Investment decisions

Those responsibilities belong to other subsystems.

---

# Order Type Policies

Execution Policies may define preferred order types.

Examples include:

- Market
- Limit
- Stop Loss
- Stop Loss Market
- Future broker-supported types

Order type selection may depend upon:

- Market liquidity
- Volatility
- User preferences
- Execution profile

---

# Quantity Policies

Execution Policies may define how quantities are calculated.

Examples include:

- Round to nearest lot size
- Round down
- Round up
- Preserve cash reserve
- Leave minimum cash balance

Quantity Policies shall never violate Risk Policies.

---

# Price Policies

Execution Policies may determine how execution prices are selected.

Examples include:

- Current Market Price
- Last Traded Price
- Best Bid
- Best Ask
- User-defined Offset
- Configurable Limit Buffer

Price Policies shall remain broker-independent.

---

# Sequencing Policies

Execution Policies determine execution order.

Examples include:

Sell Orders

↓

Release Cash

↓

Buy Orders

or

Highest Priority

↓

Lowest Priority

Execution sequencing shall always remain deterministic.

---

# Order Splitting

Execution Policies may divide one Execution Intent into multiple Broker Orders.

Examples include:

Large Quantity

↓

Multiple Broker Orders

Order splitting is intended to support future execution capabilities.

Current implementations may choose not to split orders.

---

# Retry Policies

Execution Policies may define retry behaviour.

Examples include:

- Retry Count
- Retry Delay
- Retry Conditions

Retry behaviour applies only to recoverable failures.

Permanent failures shall not be retried.

---

# Timeout Policies

Execution Policies may define timeout behaviour.

Examples include:

- Cancel after X minutes
- Leave pending until market close
- User intervention required

Timeout handling shall be broker-independent.

---

# Expiry Policies

Execution Policies may determine the validity period of Broker Orders.

Examples include:

- Day Order
- Immediate or Cancel
- Good Till Cancelled (future)
- Broker-supported expiry modes

---

# Execution Profiles

Execution Policies may be grouped into reusable Execution Profiles.

Examples include:

Conservative

Balanced

Aggressive

Each profile represents a predefined collection of Execution Policies.

Profiles simplify user configuration while maintaining consistency.

---

# Policy Evaluation

Execution Policies are evaluated after:

- Order Basket generation
- User review (if applicable)

and before:

- Risk Validation
- Broker Translation

Execution Policies SHALL NOT bypass Risk Policies.

---

# Policy Overrides

Execution Policies may override default execution behaviour.

Overrides shall remain within the limits imposed by:

- Risk Policy
- Trading Permissions
- Broker Capabilities

No override may violate mandatory safety controls.

---

# Broker Independence

Execution Policies shall remain broker-independent.

Broker-specific behaviour belongs exclusively to Broker Adapters.

Execution Policies describe desired execution behaviour rather than broker API parameters.

---

# Future Execution Strategies

Future versions of StoX may introduce Execution Strategies.

Execution Strategies define market execution algorithms.

Examples include:

- TWAP
- VWAP
- Iceberg
- Smart Routing
- Passive Execution
- Opportunistic Execution

Execution Strategies operate beneath Execution Policies.

Execution Policies remain responsible for business rules.

Execution Strategies remain responsible for market execution techniques.

---

# Design Constraints

Execution Policies SHALL:

- remain deterministic
- remain explainable
- remain auditable
- remain broker-independent
- preserve investment intent

Execution Policies SHALL NOT:

- generate investment ideas
- change recommendations
- bypass risk validation
- bypass security validation
- communicate directly with brokers

---

# Future Compatibility

Execution Policies have been designed to support future capabilities including:

- Multiple brokers
- Institutional execution
- Smart order routing
- Portfolio rebalancing
- Basket optimisation
- Advanced execution algorithms

These capabilities shall extend the policy framework without requiring redesign.

# Section 6 – Broker Abstraction

## Purpose

The Broker Abstraction Layer isolates the Live Trading subsystem from broker-specific implementations.

Its purpose is to provide a stable, broker-independent interface through which all trading operations are performed.

No subsystem outside the Broker Abstraction Layer shall communicate directly with broker APIs.

---

# Objectives

The Broker Abstraction Layer exists to:

- isolate broker-specific implementations
- simplify addition of new brokers
- provide a common execution interface
- normalize broker behaviour
- hide API differences
- improve testability
- improve maintainability

---

# Architectural Principles

The Broker Abstraction Layer follows these principles.

## Broker Independence

Higher-level components shall never depend on broker-specific APIs.

---

## Capability Driven

Execution Engine requests capabilities.

Broker implementations decide how to fulfil them.

---

## Normalized Behaviour

Regardless of broker implementation, the rest of StoX observes identical behaviour.

---

## Replaceable

Replacing one broker with another shall not require changes outside the Broker Adapter.

---

# Responsibilities

The Broker Abstraction Layer SHALL:

- authenticate with brokers
- maintain broker sessions
- submit orders
- modify orders
- cancel orders
- fetch execution status
- synchronize positions
- synchronize holdings
- synchronize funds
- normalize broker responses
- report broker health

The Broker Abstraction Layer SHALL NOT:

- perform investment analysis
- evaluate risk
- generate recommendations
- calculate indicators
- manage strategies

---

# Broker Lifecycle

Every Broker Connection follows the same lifecycle.

Configured

↓

Connected

↓

Authenticated

↓

Ready

↓

Executing

↓

Disconnected

↓

Reconnecting

↓

Unavailable

---

# Broker Capabilities

Every supported broker shall declare its supported capabilities.

Examples include:

Authentication

Order Placement

Order Modification

Order Cancellation

Order Status

Position Synchronization

Holdings Synchronization

Funds Synchronization

Margin Information

Historical Orders

Profile Information

Capability discovery enables future broker expansion.

---

# Standard Broker Operations

Every broker shall expose the following logical operations.

Connect

Disconnect

Authenticate

Refresh Session

Submit Orders

Modify Orders

Cancel Orders

Fetch Orders

Fetch Executions

Fetch Holdings

Fetch Positions

Fetch Funds

Fetch Margins

Health Check

The implementation of these operations is broker-specific.

---

# Broker Responses

Broker implementations shall normalize all responses into StoX business objects.

Examples include:

Broker Order

Broker Execution

Broker Holdings

Broker Positions

Broker Funds

Broker Profile

The remainder of StoX shall never consume raw broker payloads.

---

# Session Management

Broker authentication shall be managed exclusively by the Broker Adapter.

Execution Engine shall not manage:

- API tokens
- Session expiry
- Login state
- Authentication refresh

---

# Error Normalization

Broker-specific errors shall be translated into common StoX error categories.

Examples include:

Authentication Failed

Session Expired

Market Closed

Order Rejected

Insufficient Funds

Rate Limited

Network Failure

Broker Offline

This enables consistent handling across all brokers.

---

# Broker Health

Every Broker Adapter shall continuously expose health information.

Examples include:

Connected

Authenticated

Healthy

Degraded

Unavailable

Rate Limited

Maintenance

Health information is consumed by Monitoring.

---

# Broker Limits

Broker implementations shall expose operational limits where available.

Examples include:

Maximum Order Quantity

Maximum Basket Size

Rate Limits

Supported Exchanges

Supported Products

Supported Order Types

Execution Engine shall adapt behaviour accordingly.

---

# Synchronization

Broker Adapters are responsible for synchronizing broker state with StoX.

Synchronization includes:

Holdings

Positions

Funds

Orders

Executions

Synchronization shall never modify broker state.

Synchronization updates only StoX state.

---

# Security Responsibilities

Broker Adapters shall never expose:

- Access Tokens
- Refresh Tokens
- API Secrets
- Authentication Credentials

Credential management belongs to the Security subsystem.

---

# Audit Responsibilities

Every broker interaction shall be auditable.

Examples include:

Broker Connected

Broker Disconnected

Order Submitted

Order Cancelled

Session Refreshed

Synchronization Started

Synchronization Completed

Synchronization Failed

---

# Future Compatibility

The Broker Abstraction Layer is designed to support:

- Multiple brokers
- Simultaneous broker connections
- Broker failover
- Paper brokers
- Simulation brokers
- Historical replay brokers
- International brokers
- Cryptocurrency exchanges

Future broker implementations shall conform to this abstraction rather than extending the Execution Engine.

# Section 7 – Execution State Machine

## Purpose

This section defines the lifecycle states of all execution entities within the Live Trading subsystem.

State machines ensure that execution remains deterministic, auditable, recoverable, and explainable.

Every state transition SHALL be explicitly defined.

No component may transition an entity into an undefined state.

---

# Design Principles

Execution state machines follow these principles.

- Every entity has a finite set of valid states.
- Every transition is deterministic.
- Invalid transitions are rejected.
- State changes are auditable.
- Terminal states cannot transition further.
- Every state has a clearly defined owner.

---

# Execution Hierarchy

Execution consists of several independent state machines.

Execution Run

↓

Order Basket

↓

Execution Intent

↓

Broker Order

↓

Broker Execution

↓

Trade

Each state machine operates independently while maintaining parent-child consistency.

---

# Execution Run States

The Execution Run lifecycle is:

Created

↓

Preparing

↓

Ready

↓

Executing

↓

Completing

↓

Completed

or

Completed With Warnings

or

Failed

or

Cancelled

---

## State Definitions

### Created

Execution Run has been created.

No processing has started.

---

### Preparing

Execution Context is being built.

Order Basket is being generated.

No broker communication has occurred.

---

### Ready

Execution Run is ready for execution.

Waiting for:

- User approval
- Scheduler
- Trading session
- Automation trigger

---

### Executing

Execution Intents are actively being processed.

Broker communication may be occurring.

---

### Completing

Execution has finished.

Portfolio updates and notifications are being finalized.

---

### Completed

Execution finished successfully.

---

### Completed With Warnings

Execution completed but one or more recoverable issues occurred.

Examples:

- One order rejected.
- One order expired.
- Minor synchronization issue.

---

### Failed

Execution terminated due to unrecoverable failure.

---

### Cancelled

Execution was intentionally cancelled before completion.

---

# Order Basket States

Draft

↓

Prepared

↓

Awaiting Approval

↓

Approved

↓

Executing

↓

Completed

or

Cancelled

or

Expired

---

# Execution Intent States

Created

↓

Validated

↓

Risk Approved

↓

Translated

↓

Submitted

↓

Acknowledged

↓

Completed

or

Rejected

or

Cancelled

---

# Broker Order States

Created

↓

Submitted

↓

Accepted

↓

Partially Filled

↓

Filled

or

Rejected

or

Cancelled

or

Expired

Broker Order states reflect broker acknowledgement.

---

# Broker Execution States

Pending

↓

Executing

↓

Completed

or

Rejected

or

Cancelled

Broker Execution reflects the broker's actual execution outcome.

---

# Trade States

Opening

↓

Open

↓

Increasing

↓

Open

↓

Reducing

↓

Open

↓

Closing

↓

Closed

A Trade remains Open until the final position has been exited.

---

# Parent-Child Consistency

Parent entities SHALL remain consistent with child entities.

Example:

Execution Run

↓

contains

↓

10 Execution Intents

If:

8 Completed

1 Rejected

1 Executing

Then:

Execution Run remains

Executing

until all children reach terminal states.

---

# Terminal States

The following states are terminal.

Completed

Rejected

Cancelled

Expired

Closed

Terminal states cannot transition further.

Any additional work requires creation of new entities.

---

# Invalid Transitions

The following examples are invalid.

Completed

↓

Executing

Rejected

↓

Submitted

Cancelled

↓

Approved

Filled

↓

Accepted

Such transitions SHALL be rejected.

---

# Recovery Behaviour

Recoverable failures SHALL preserve state.

Example:

Submitted

↓

Network Failure

↓

Retry

↓

Submitted

State history shall remain visible.

---

# State History

Every entity SHALL maintain complete state history.

Each transition SHALL record:

- Previous State
- New State
- Timestamp
- Initiating Component
- Initiating User (if applicable)
- Reason
- Correlation Identifier

State history is immutable.

---

# State Ownership

Only one component owns each transition.

| Entity           | Owner            |
| ---------------- | ---------------- |
| Execution Run    | Execution Engine |
| Order Basket     | Basket Manager   |
| Execution Intent | Execution Engine |
| Broker Order     | Broker Adapter   |
| Broker Execution | Broker Adapter   |
| Trade            | Portfolio Engine |

No component may modify states owned by another component.

---

# Notifications

State transitions may generate notifications.

Examples:

Execution Started

Execution Completed

Execution Failed

Order Filled

Order Rejected

Notifications are configurable.

---

# Audit

Every state transition SHALL be recorded in the Audit subsystem.

State history forms part of the permanent execution record.

---

# Monitoring

Monitoring metrics SHALL be derived from state transitions.

Examples:

Average execution duration

Broker latency

Failure rate

Success rate

Retry count

Average fill time

---

# Future Compatibility

The state machine has been designed to support:

- Multi-broker execution
- Basket splitting
- Partial executions
- Smart routing
- Paper trading
- Portfolio rebalancing
- Distributed execution

Future capabilities shall extend existing state machines rather than replacing them.

# Section 9 – Consistency, Idempotency & Data Integrity

## Purpose

This section defines the guarantees provided by the Live Trading subsystem to ensure correctness, consistency, and reliability.

The objective is to prevent duplicate execution, preserve data integrity, and ensure that the Portfolio always reflects the broker's actual state.

---

# Design Principles

The subsystem SHALL guarantee:

- deterministic behaviour
- consistent portfolio state
- idempotent execution
- complete traceability
- recoverability
- auditability

These guarantees apply regardless of Execution Mode or Broker implementation.

---

# Source of Truth

The authoritative source for each type of data shall be clearly defined.

| Data                | Source of Truth                 |
| ------------------- | ------------------------------- |
| Recommendations     | Recommendation Engine           |
| Order Basket        | Execution Engine                |
| Broker Order Status | Broker                          |
| Broker Execution    | Broker                          |
| Holdings            | StoX (synchronized with Broker) |
| Cash Balance        | StoX (validated against Broker) |
| Transactions        | StoX                            |
| Trades              | StoX                            |
| Audit Log           | StoX                            |

Whenever discrepancies occur, the reconciliation rules defined by the synchronization process shall determine the final state.

---

# Idempotency

Execution operations SHALL be idempotent.

Repeating the same operation shall never create duplicate financial activity.

Examples include:

- duplicate API requests
- browser refresh
- retry after timeout
- scheduler retry
- broker callback replay

The system SHALL recognize duplicate requests and safely ignore or reconcile them.

---

# Correlation Identifiers

Every execution artifact SHALL have a unique identifier.

Examples include:

- Recommendation Run ID
- Execution Run ID
- Order Basket ID
- Execution Intent ID
- Broker Order ID
- Broker Execution ID
- Transaction ID
- Trade ID

These identifiers provide complete traceability across the execution pipeline.

---

# Referential Integrity

Relationships between execution entities SHALL remain valid.

Examples:

Execution Run

↓

contains

↓

Order Basket

↓

contains

↓

Execution Intent

↓

creates

↓

Broker Order

↓

produces

↓

Broker Execution

↓

creates

↓

Portfolio Transaction

↓

updates

↓

Trade

Broken relationships are considered data integrity failures.

---

# Duplicate Prevention

The subsystem SHALL prevent duplicate execution.

Duplicate detection may use:

- Correlation Identifier
- Broker Order Identifier
- Broker Execution Identifier
- Execution Intent Identifier

Duplicate detection shall occur before broker submission whenever possible.

---

# Portfolio Consistency

Portfolio state shall only change after confirmed Broker Execution.

Examples:

- Holdings
- Cash
- Cost Basis
- Position Size
- Open Trades

Speculative updates SHALL NOT become permanent state.

---

# Event Ordering

Execution events SHALL be processed in chronological order where required.

The subsystem shall preserve causal relationships between events.

Example:

Broker Order Submitted

↓

Broker Accepted

↓

Broker Filled

↓

Portfolio Updated

↓

Notification Sent

Reordering these events is not permitted.

---

# Atomic Operations

Critical operations SHALL be atomic wherever practical.

Examples include:

- creating an Execution Run
- approving an Order Basket
- recording a Broker Execution
- creating Portfolio Transactions
- closing a Trade

Partial updates shall not leave the system in an inconsistent state.

---

# Checkpoints

Long-running execution processes SHALL define explicit checkpoints.

Examples include:

- Execution Context Created
- Order Basket Prepared
- Risk Validation Completed
- Broker Submission Completed
- Portfolio Updated
- Notifications Completed
- Execution Finalized

Recovery SHALL resume from the latest completed checkpoint.

---

# Synchronization

Synchronization shall reconcile StoX state with Broker state.

Synchronization SHALL:

- detect missing executions
- detect missing transactions
- detect stale orders
- detect inconsistent holdings
- detect inconsistent cash balances

Synchronization SHALL never silently discard discrepancies.

---

# Reconciliation

When inconsistencies are detected, the subsystem SHALL create a reconciliation record.

The record shall include:

- affected entity
- expected state
- observed state
- detection time
- resolution status
- resolution method

Reconciliation records are auditable.

---

# Audit Guarantees

Every significant business action SHALL be reconstructable using audit records.

Audit history shall never be deleted automatically.

Administrative archival policies may relocate historical records without losing traceability.

---

# Monitoring Guarantees

Monitoring SHALL expose metrics related to data integrity.

Examples include:

- duplicate prevention count
- synchronization mismatches
- reconciliation backlog
- retry success rate
- execution success rate
- consistency violations

---

# Future Compatibility

The consistency model has been designed to support:

- distributed execution
- queue-based processing
- multiple brokers
- parallel execution
- asynchronous workflows
- event-driven architectures

These capabilities shall preserve the guarantees defined in this specification.
