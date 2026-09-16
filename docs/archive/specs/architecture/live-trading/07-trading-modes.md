# Trading Modes

---

# 1. Purpose

## Overview

The Trading Modes architecture defines how execution authority is shared between the user and the StoX Platform during live trading.

Its primary objective is to support multiple operating models ranging from fully manual trading to fully automated execution while preserving a consistent execution architecture.

Trading Modes determine **who authorizes execution actions**.

They do not change how Orders are processed or executed.

---

# Objectives

The Trading Modes architecture exists to:

- support multiple operating modes
- define execution authority
- control automation levels
- provide operational flexibility
- enable gradual automation adoption
- preserve execution consistency
- support safe operational control

---

# Scope

This specification defines:

- Trading Mode architecture
- execution authority
- supported operating modes
- mode transitions
- operational controls
- monitoring responsibilities
- extension model

This specification does **not** define:

- trading strategies
- recommendation generation
- risk evaluation
- broker communication
- order lifecycle
- portfolio management

Those responsibilities are defined by their respective architectural specifications.

---

# Position within the Live Trading Architecture

Trading Modes influence the execution workflow after Recommendations and Risk Decisions have been produced.

The conceptual workflow is:

Recommendation

↓

Risk Decision

↓

Trading Mode

↓

Execution Decision

↓

Execution Engine

↓

Broker Integration

↓

Order Lifecycle

Trading Modes determine whether execution proceeds automatically or requires user approval.

---

# Architectural Responsibility

Trading Modes are responsible for:

- determining execution authority
- enforcing automation policies
- supporting execution approvals
- controlling automatic execution
- supporting operational overrides

Trading Modes are not responsible for:

- generating Recommendations
- evaluating Risk
- communicating with brokers
- processing Orders
- updating Portfolios

Those responsibilities remain within their respective subsystems.

---

# Platform Relationships

Within the Platform Architecture, Trading Modes consist of:

Configuration

- Trading Mode Configuration
- Automation Policies

Registry

- Trading Mode Registry

Business Engine

- Trading Mode Engine

Run

- Trading Mode Evaluation Run

Artifact

- Trading Mode Decision Artifact

Event

- Trading Mode Events

Operational Control

- Mode Activation
- Mode Suspension
- Emergency Override

The Trading Modes architecture reuses established Platform Architecture patterns.

---

# Guiding Principles

Trading Modes follow these principles:

- deterministic execution authority
- configuration-driven behaviour
- explicit user control
- operational safety
- complete auditability
- gradual automation adoption

---

# Success Criteria

A successful Trading Modes implementation should ensure that:

- execution authority is always unambiguous
- users understand the current operating mode
- automation behaves predictably
- manual intervention remains available where permitted
- operational overrides function consistently
- transitions between modes remain controlled

The architecture described in this specification establishes a flexible and deterministic framework for operating the StoX Platform across varying levels of trading automation.

# 2. Trading Mode Philosophy

## Overview

The Trading Modes architecture defines how execution authority is distributed between the user and the StoX Platform.

Regardless of the selected Trading Mode, the underlying execution architecture remains unchanged.

Trading Modes influence operational decision making.

They do not alter business logic, execution processing or broker communication.

---

# Separation of Responsibilities

The Live Trading architecture separates execution decisions from execution authority.

Recommendation Engine

Determines what should be traded.

Risk Management

Determines whether execution is permitted.

Trading Mode

Determines who authorizes execution.

Execution Engine

Executes the approved action.

Broker Integration

Communicates with the broker.

Order Lifecycle

Tracks execution until completion.

Each subsystem owns one clearly defined responsibility.

---

# Execution Authority

Execution authority determines whether an execution step requires user approval or platform approval.

Examples include:

User Approval

- approve Buy
- approve Sell
- approve Modification
- approve Cancellation

Platform Approval

- automatic execution
- automatic modification
- automatic cancellation

The selected Trading Mode determines which authority applies.

---

# Consistent Execution Architecture

All Trading Modes use the same execution architecture.

```text
Recommendation
        │
        ▼
Risk Management
        │
        ▼
Trading Mode
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
```

Only the execution authorization step varies.

Everything else remains identical.

---

# Configuration-Driven Behaviour

Trading Modes are entirely configuration driven.

Examples include:

- automation level
- approval requirements
- execution permissions
- cancellation permissions
- modification permissions
- emergency restrictions

Business components remain unaware of the selected Trading Mode.

---

# Deterministic Behaviour

Given identical:

- Recommendation
- Risk Decision
- Trading Mode Configuration

the platform shall always produce the same execution decision.

Trading Modes shall never introduce non-deterministic behaviour.

---

# Operational Safety

Operational safety takes precedence over automation.

Examples include:

- Emergency Stop
- Trading Freeze
- Broker Unavailable
- Market Closed
- Risk Protection Triggered

Operational Controls may override the selected Trading Mode.

Safety mechanisms always have higher priority than automation.

---

# User Transparency

Users shall always understand:

- current Trading Mode
- level of automation
- approvals required
- actions performed automatically
- actions awaiting approval

Automation shall never appear ambiguous.

---

# Broker Independence

Trading Modes remain independent of broker implementation.

Broker capabilities influence execution feasibility.

They do not influence execution authority.

Automation policies remain consistent across all supported brokers.

---

# Explainability

Every execution decision shall be explainable.

Users should understand:

- why execution occurred
- why approval was required
- why execution was delayed
- why execution was blocked

Every decision should be traceable to the active Trading Mode.

---

# Auditability

Trading Mode decisions shall remain permanently recorded.

Typical information includes:

- active Trading Mode
- approval source
- automation decision
- timestamp
- initiating actor
- resulting execution action

Historical Trading Mode decisions support operational analysis and compliance.

---

# Design Principles

Trading Modes shall:

- remain configuration-driven
- remain deterministic
- remain broker-independent
- preserve execution architecture
- support operational safety
- support complete auditability

Trading Modes determine execution authority.

They do not alter execution behaviour.

---

# Summary

The Trading Mode philosophy establishes a clear separation between execution authority and execution processing.

By treating automation as an operational policy layered above the execution architecture, the StoX Platform supports multiple operating models without introducing complexity into the underlying business, broker or Order Lifecycle subsystems.

# 3. Operating Architecture

## Overview

The Operating Architecture defines how Trading Modes integrate with the Live Trading platform.

It provides a standardized framework for evaluating execution authority before the Execution Engine begins processing a Recommendation.

The architecture separates operational policy from execution logic, allowing the platform to support multiple automation levels without altering the underlying execution pipeline.

---

# Architectural Position

Trading Mode evaluation occurs after Risk Management and before the Execution Engine.

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Management
        │
        ▼
Trading Mode Engine
        │
        ▼
Execution Decision
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
```

The Trading Mode Engine decides whether execution:

- proceeds automatically
- waits for user approval
- is blocked by operational policy

---

# Architectural Components

The Trading Modes subsystem consists of the following platform building blocks.

| Platform Building Block | Trading Modes Component        |
| ----------------------- | ------------------------------ |
| Configuration           | Trading Mode Configuration     |
| Configuration           | Automation Policies            |
| Registry                | Trading Mode Registry          |
| Business Engine         | Trading Mode Engine            |
| Run                     | Trading Mode Evaluation Run    |
| Artifact                | Trading Mode Decision Artifact |
| Event                   | Trading Mode Events            |
| Operational Control     | Mode Controls                  |

Each component owns one clearly defined responsibility.

---

# Trading Mode Registry

The Trading Mode Registry is the authoritative source for all Trading Mode definitions.

Its responsibilities include:

- managing Trading Mode definitions
- validating Trading Mode configuration
- version management
- lifecycle management
- publication

The Registry performs no execution decisions.

---

# Trading Mode Engine

The Trading Mode Engine evaluates execution authority.

Its responsibilities include:

- loading the active Trading Mode
- evaluating automation policies
- determining approval requirements
- producing execution decisions
- generating decision artifacts
- publishing Trading Mode events

The engine never evaluates trading opportunities or risk.

---

# Trading Mode Evaluation Run

Every Trading Mode evaluation creates a Trading Mode Evaluation Run.

Typical Runs include:

- Buy evaluation
- Sell evaluation
- Modification evaluation
- Cancellation evaluation

Each Run records:

- evaluated action
- active Trading Mode
- automation decision
- approval outcome
- execution decision

Runs provide operational traceability.

---

# Trading Mode Decision Artifact

Each evaluation produces a Trading Mode Decision Artifact.

Typical information includes:

- Trading Mode
- execution request
- approval requirement
- approval outcome
- automation decision
- timestamp

The Decision Artifact becomes the authoritative record of why execution proceeded or did not proceed.

---

# Trading Mode Events

The Trading Mode subsystem publishes standardized Events.

Examples include:

- Trading Mode Activated
- Trading Mode Changed
- Execution Approved
- User Approval Requested
- User Approval Granted
- User Approval Rejected
- Automatic Execution Started
- Automatic Execution Blocked

Events improve observability and downstream integration.

---

# Operational Controls

Operational Controls influence Trading Mode behaviour.

Examples include:

- Enable Manual Mode
- Enable Assisted Mode
- Enable Semi-Automated Mode
- Enable Fully Automated Mode
- Emergency Stop
- Trading Freeze
- Disable Automation

Operational Controls override automation policies where necessary.

---

# Relationship with Execution Engine

The Trading Mode Engine determines whether execution may proceed.

The Execution Engine performs execution only after receiving a positive execution decision.

Execution behaviour remains identical across all Trading Modes.

---

# Relationship with Risk Management

Risk Management determines whether trading is permitted.

Trading Modes determine who authorizes the permitted trade.

Risk approval remains a prerequisite for Trading Mode evaluation.

---

# Relationship with User Interface

The User Interface displays:

- current Trading Mode
- pending approvals
- execution decisions
- automation status

The User Interface presents Trading Mode decisions.

It does not determine them.

---

# Failure Isolation

Operational failures within Trading Modes shall remain isolated.

Examples include:

- configuration errors
- approval workflow failures
- unavailable notification channels

Failures shall not modify Recommendations, Risk Decisions or existing Orders.

Execution shall remain blocked until a valid Trading Mode decision is available.

---

# Architectural Principles

The Operating Architecture shall:

- remain configuration-driven
- remain deterministic
- preserve execution architecture
- isolate operational policy
- support auditing
- support future automation modes

Trading Modes determine execution authority.

Execution components perform execution.

---

# Summary

The Operating Architecture provides a standardized framework for controlling execution authority within the StoX Platform.

By separating Trading Mode evaluation from execution processing, the platform supports multiple operating models while preserving a single, consistent execution architecture suitable for both manual and fully automated trading.

# 4. Manual Trading Mode

## Overview

Manual Trading Mode provides the highest level of user control by requiring explicit user authorization before every execution action.

In this mode, the StoX Platform performs market analysis, generates Recommendations and evaluates Risk, but no trading action is executed automatically.

The user remains the final decision maker for every execution activity.

---

# Purpose

Manual Trading Mode exists to:

- maximize user control
- support discretionary trading
- build user confidence
- validate platform recommendations
- enable learning and experimentation
- eliminate unintended automation

This mode is intended for users who want decision support without automated execution.

---

# Execution Authority

In Manual Trading Mode, the user authorizes every execution action.

Examples include:

- Buy Order
- Sell Order
- Order Modification
- Order Cancellation

No execution action proceeds without explicit user approval.

---

# Operational Workflow

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
User Approval Required
        │
        ▼
User Decision
        │
        ├────────────► Reject
        │                     │
        │                     ▼
        │              Execution Ends
        │
        └────────────► Approve
                              │
                              ▼
                     Execution Engine
                              │
                              ▼
                     Broker Integration
```

The user remains responsible for initiating every execution.

---

# User Responsibilities

The user is responsible for:

- reviewing Recommendations
- reviewing Risk Decisions
- approving execution
- approving modifications
- approving cancellations

The platform never assumes approval.

---

# Platform Responsibilities

The platform remains responsible for:

- Recommendation generation
- Risk evaluation
- execution preparation
- broker communication
- Order Lifecycle management
- Portfolio updates

Business processing remains unchanged.

Only execution authority differs.

---

# Approval Behaviour

Every execution request shall enter a pending approval state.

Typical approval actions include:

- Approve
- Reject
- Defer

Deferred requests remain pending until:

- approved
- rejected
- expired
- manually withdrawn

Approval workflows shall be configurable.

---

# Order Behaviour

Orders are created only after user approval.

Rejected Recommendations do not produce Orders.

Approved Recommendations proceed through the standard execution architecture.

---

# Notifications

The platform may notify the user when:

- Recommendations are available
- Risk evaluation completes
- approval is required
- approval expires

Notification delivery mechanisms are defined elsewhere.

---

# Safety

Manual Trading Mode provides the greatest operational safety.

Characteristics include:

- no automatic execution
- no automatic modification
- no automatic cancellation
- explicit approval required for every action

Operational Controls may still override user approval.

Examples include:

- Emergency Stop
- Trading Freeze
- Broker Unavailable

---

# Typical Users

Manual Trading Mode is appropriate for:

- new users
- discretionary traders
- learning environments
- testing new strategies
- high-value portfolios
- regulatory review scenarios

It provides maximum transparency and minimum automation.

---

# Events

Typical Events include:

- Approval Requested
- Approval Granted
- Approval Rejected
- Approval Expired
- Execution Started

These Events become part of the permanent execution history.

---

# Auditability

Manual Trading Mode shall record:

- Recommendation Identifier
- Risk Decision
- approval timestamp
- approving user
- approval outcome
- resulting execution decision

Every approval decision shall remain permanently traceable.

---

# Design Principles

Manual Trading Mode shall:

- maximize user control
- require explicit approval
- preserve execution architecture
- remain deterministic
- support auditing
- support operational safety

The platform recommends.

The user decides.

---

# Summary

Manual Trading Mode provides the highest level of user oversight by requiring explicit approval for every execution action.

By separating business analysis from execution authority while preserving the standard execution architecture, the StoX Platform supports discretionary trading with complete transparency, traceability and operational safety.

# 5. Assisted Trading Mode

## Overview

Assisted Trading Mode provides intelligent decision support while preserving user control over trade execution.

In this mode, the StoX Platform continuously performs market analysis, generates Recommendations, evaluates Risk and prepares execution-ready Orders.

The platform assists the user throughout the trading process.

The user remains responsible for approving execution.

---

# Purpose

Assisted Trading Mode exists to:

- reduce manual analysis effort
- accelerate trading decisions
- improve execution readiness
- assist discretionary traders
- increase user productivity
- preserve user authority

The platform prepares.

The user approves.

---

# Execution Authority

In Assisted Trading Mode:

Platform responsibilities:

- market monitoring
- Recommendation generation
- Risk evaluation
- execution preparation
- Order preparation

User responsibilities:

- approve Buy Orders
- approve Sell Orders
- approve Modifications
- approve Cancellations

Execution never begins without user approval.

---

# Operational Workflow

The conceptual workflow is:

```text
Market Analysis
        │
        ▼
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Execution Prepared
        │
        ▼
User Approval
        │
        ├────────────► Reject
        │                     │
        │                     ▼
        │              Execution Ends
        │
        └────────────► Approve
                              │
                              ▼
                     Execution Engine
                              │
                              ▼
                     Broker Integration
```

The platform performs all preparation before requesting approval.

---

# Platform Responsibilities

The platform automatically performs:

- market monitoring
- Recommendation generation
- Risk evaluation
- position sizing
- broker selection
- Order preparation
- execution readiness validation

Execution waits for user authorization.

---

# User Responsibilities

The user reviews:

- Recommendation
- confidence indicators
- Risk Decision
- position sizing
- execution summary

The user decides whether execution should proceed.

---

# Approval Behaviour

Execution approval requests should present sufficient information for informed decision making.

Typical information includes:

Recommendation

- Buy or Sell
- instrument
- strategy

Risk

- risk assessment
- position size
- expected exposure

Execution

- broker
- account
- Order type
- estimated value

Users should not require multiple screens to understand the proposed trade.

---

# Approval Timeout

Approval requests may expire after a configurable period.

Possible outcomes include:

- Approved
- Rejected
- Expired

Expired approvals shall not execute automatically.

A new Recommendation may be required.

---

# Notifications

The platform may notify the user when:

- Recommendation generated
- approval requested
- approval nearing expiration
- Recommendation withdrawn
- market conditions changed

Notification mechanisms are defined elsewhere.

---

# Order Behaviour

Orders are created only after user approval.

Prepared execution requests are not Orders.

Execution preparation does not commit the platform to trading.

---

# Automation Level

Assisted Trading Mode automates:

- analysis
- screening
- Recommendations
- Risk evaluation
- execution preparation

It does not automate:

- Buy execution
- Sell execution
- Order Modification
- Order Cancellation

Final authority always remains with the user.

---

# Safety

Operational Controls remain active.

Examples include:

- Emergency Stop
- Trading Freeze
- Broker Unavailable
- Market Closed
- Risk Protection

Safety controls override user approval when necessary.

---

# Typical Users

Assisted Trading Mode is appropriate for:

- active investors
- swing traders
- momentum traders
- users managing multiple portfolios
- users seeking faster decision making

It provides intelligent assistance while preserving human decision making.

---

# Events

Typical Events include:

- Recommendation Prepared
- Execution Prepared
- Approval Requested
- Approval Granted
- Approval Rejected
- Approval Expired
- Execution Started

These Events become part of the permanent execution history.

---

# Auditability

Assisted Trading Mode shall record:

- Recommendation
- Risk Decision
- prepared execution summary
- approval timestamp
- approving user
- approval outcome
- resulting execution decision

All approval decisions shall remain permanently traceable.

---

# Design Principles

Assisted Trading Mode shall:

- maximize decision support
- preserve user authority
- automate preparation
- avoid automatic execution
- remain deterministic
- support auditing

The platform prepares.

The user decides.

---

# Summary

Assisted Trading Mode combines automated market analysis and execution preparation with explicit user approval before every trade.

By automating the analytical and operational workload while preserving final execution authority, the StoX Platform enables faster and more informed trading decisions without sacrificing user control or operational transparency.

# 6. Semi-Automated Trading Mode

## Overview

Semi-Automated Trading Mode combines automated execution with user-controlled decision points.

In this mode, selected trading actions are executed automatically according to configured automation policies, while other actions continue to require explicit user approval.

The platform and the user jointly participate in the execution process.

Execution authority is determined individually for each supported action.

---

# Purpose

Semi-Automated Trading Mode exists to:

- reduce repetitive manual actions
- accelerate execution
- retain user control over critical decisions
- support incremental automation
- improve operational efficiency
- enable flexible execution policies

This mode provides controlled automation without requiring complete trust in autonomous execution.

---

# Execution Authority

Execution authority is determined by Automation Policies.

Examples include:

Automatically Executed

- Buy Orders
- Sell Orders
- Stop Loss Orders
- Trailing Stop Updates
- Order Modifications

User Approved

- Large Orders
- Portfolio Rebalancing
- Manual Overrides
- Strategy Changes
- Emergency Actions

Execution authority is evaluated independently for each action.

---

# Operational Workflow

The conceptual workflow is:

```text
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Trading Mode Evaluation
        │
        ▼
Automation Policy Evaluation
        │
        ├────────────► Manual Approval Required
        │                     │
        │                     ▼
        │              User Decision
        │
        └────────────► Automatic Execution
                              │
                              ▼
                     Execution Engine
                              │
                              ▼
                     Broker Integration
```

The active Automation Policy determines whether execution proceeds automatically or requires approval.

---

# Automation Policies

Automation Policies define execution authority.

Typical policies include:

Buy Orders

- Manual
- Automatic

Sell Orders

- Manual
- Automatic

Stop Loss

- Manual
- Automatic

Trailing Stop

- Manual
- Automatic

Order Modification

- Manual
- Automatic

Order Cancellation

- Manual
- Automatic

Policies are configurable and may differ between Strategies.

---

# Policy Evaluation

Before execution, the Trading Mode Engine evaluates:

- active Trading Mode
- Automation Policies
- Operational Controls
- Risk Decision
- broker capability

Execution proceeds only if all applicable policies permit it.

---

# Strategy-Specific Automation

Different Strategies may define different automation policies.

Example:

Momentum Strategy

- Auto Buy
- Auto Trailing Stop
- Manual Sell

Swing Strategy

- Manual Buy
- Manual Sell
- Auto Stop Loss

The Trading Mode Engine evaluates the policy associated with the executing Strategy.

---

# Portfolio-Specific Automation

Automation policies may also vary by Portfolio.

Examples include:

Growth Portfolio

- higher automation

Retirement Portfolio

- lower automation

Paper Trading Portfolio

- full automation

Execution authority may therefore differ between Portfolios even within the same Trading Mode.

---

# User Intervention

The user may intervene whenever permitted.

Examples include:

- cancel pending execution
- modify automation policy
- approve blocked execution
- pause automation

User intervention shall always be recorded.

---

# Safety

Operational Controls override Automation Policies.

Examples include:

- Emergency Stop
- Trading Freeze
- Broker Unavailable
- Market Closed
- Capital Protection Triggered

Safety mechanisms always take precedence over automation.

---

# Typical Users

Semi-Automated Trading Mode is appropriate for:

- experienced investors
- active traders
- users managing multiple Strategies
- users seeking faster execution
- users gradually adopting automation

It balances efficiency with user oversight.

---

# Events

Typical Events include:

- Automation Policy Evaluated
- Automatic Execution Approved
- Manual Approval Requested
- Automatic Execution Started
- User Override
- Automation Suspended

These Events become part of the permanent execution history.

---

# Auditability

Semi-Automated Trading Mode shall record:

- active Automation Policy
- evaluated Strategy
- evaluated Portfolio
- execution authority
- approval source
- execution outcome
- user overrides

Every automated execution decision shall remain permanently traceable.

---

# Design Principles

Semi-Automated Trading Mode shall:

- remain policy-driven
- support selective automation
- preserve deterministic behaviour
- support user intervention
- support operational safety
- support complete auditing

Automation is applied per action.

It is never assumed globally.

---

# Summary

Semi-Automated Trading Mode enables selective automation by evaluating configurable Automation Policies for every execution action.

By allowing different Strategies and Portfolios to adopt different automation levels while preserving deterministic execution, operational safety and complete auditability, the StoX Platform provides a flexible path from discretionary trading to fully autonomous operation.

# 7. Fully Automated Trading Mode

## Overview

Fully Automated Trading Mode enables the StoX Platform to execute approved trading actions without requiring user intervention during normal operation.

The platform continuously monitors the market, evaluates Recommendations, applies Risk Management, determines execution authority and executes eligible Orders automatically according to configured automation policies.

Human involvement shifts from operational execution to strategic oversight.

---

# Purpose

Fully Automated Trading Mode exists to:

- enable autonomous execution
- maximize execution speed
- eliminate approval delays
- support continuous market participation
- execute predefined trading strategies consistently
- reduce operational workload

Automation remains governed by platform policies and operational controls.

---

# Execution Authority

In Fully Automated Trading Mode, the platform authorizes all supported execution actions.

Examples include:

- Buy Orders
- Sell Orders
- Stop Loss Orders
- Trailing Stop Updates
- Order Modifications
- Order Cancellations
- Portfolio Rebalancing (if enabled)

User approval is not required during normal execution.

---

# Operational Workflow

The conceptual workflow is:

```text
Market Analysis
        │
        ▼
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Trading Mode Evaluation
        │
        ▼
Automation Policy Evaluation
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
        │
        ▼
Portfolio Updated
```

The complete execution pipeline operates without manual intervention unless interrupted by Operational Controls.

---

# Platform Responsibilities

The platform automatically performs:

- market monitoring
- Recommendation generation
- Risk evaluation
- position sizing
- execution planning
- broker selection
- Order creation
- Order execution
- Order modification
- Order cancellation
- Portfolio updates
- recovery and reconciliation

Automation operates continuously while enabled.

---

# User Responsibilities

The user remains responsible for:

- selecting Strategies
- configuring automation policies
- configuring Risk parameters
- monitoring system health
- reviewing execution history
- responding to operational alerts
- activating or suspending automation

The user manages the platform.

The platform manages execution.

---

# Automation Policies

Fully Automated Trading Mode follows configured Automation Policies.

Typical policies include:

- maximum position size
- daily capital allocation
- maximum concurrent positions
- trading hours
- allowed asset classes
- approved brokers
- approved portfolios

Automation operates only within configured policy boundaries.

---

# Safety

Operational safety always overrides automation.

Examples include:

- Emergency Stop
- Trading Freeze
- Market Closed
- Broker Unavailable
- Risk Protection Triggered
- Daily Loss Limit Reached
- Portfolio Exposure Limit Reached

Safety mechanisms immediately suspend or restrict execution when necessary.

---

# Human Oversight

Although execution is automatic, users remain informed.

Typical information includes:

- executed Orders
- generated Trades
- portfolio changes
- Risk events
- operational alerts
- automation status

Automation shall remain transparent.

---

# Operational Controls

Operational Controls remain available at all times.

Typical controls include:

- Pause Automation
- Resume Automation
- Disable Trading
- Strategy Suspension
- Broker Suspension
- Portfolio Suspension
- Emergency Stop

Operational Controls take immediate effect.

---

# Typical Users

Fully Automated Trading Mode is appropriate for:

- algorithmic traders
- systematic investors
- quantitative strategies
- long-running automated portfolios
- experienced users with validated strategies

It is intended for users who trust the platform to execute within predefined operating boundaries.

---

# Events

Typical Events include:

- Automation Started
- Automation Stopped
- Automatic Execution Initiated
- Automatic Execution Completed
- Strategy Suspended
- Emergency Stop Activated
- Automation Resumed

These Events become part of the permanent operational history.

---

# Auditability

Fully Automated Trading Mode shall record:

- active Trading Mode
- active Automation Policies
- execution decisions
- Strategy
- Portfolio
- operational overrides
- automation interruptions
- execution outcomes

Every automated decision shall remain fully traceable.

---

# Design Principles

Fully Automated Trading Mode shall:

- remain policy-driven
- preserve deterministic execution
- support continuous operation
- prioritize operational safety
- support complete auditing
- maintain user transparency

Automation executes trades.

Operational Controls govern automation.

---

# Summary

Fully Automated Trading Mode enables the StoX Platform to execute trading strategies autonomously while remaining constrained by Risk Management, Automation Policies and Operational Controls.

By separating strategic configuration from operational execution, the platform provides reliable, deterministic and auditable autonomous trading suitable for long-running systematic investment strategies without compromising operational safety or governance.

# 8. Mode Transitions

## Overview

Mode Transitions define how the StoX Platform changes from one Trading Mode to another during operation.

The objective is to ensure that Trading Mode changes are predictable, deterministic and operationally safe without disrupting the integrity of the execution architecture.

Trading Mode changes affect future execution decisions.

They do not alter the lifecycle of existing Orders.

---

# Purpose

Mode Transitions exist to:

- support operational flexibility
- enable controlled automation changes
- preserve execution integrity
- prevent inconsistent behaviour
- support safe operational management
- maintain complete auditability

Every Trading Mode transition shall be explicit and traceable.

---

# Transition Scope

A Trading Mode transition affects:

- future execution decisions
- future approval requirements
- future automation behaviour
- future execution authority

A Trading Mode transition shall not affect:

- active Orders
- completed Orders
- Trade history
- Portfolio state
- Order Lifecycle state

Existing business entities remain unchanged.

---

# Transition Workflow

The conceptual workflow is:

```text
Mode Change Requested
        │
        ▼
Validate Request
        │
        ▼
Operational Safety Check
        │
        ▼
Activate New Trading Mode
        │
        ▼
Publish Mode Changed Event
        │
        ▼
Future Executions Use New Mode
```

The transition applies only after successful validation.

---

# Supported Transitions

The platform supports transitions between all Trading Modes.

Examples include:

```text
Manual
    │
    ├────────► Assisted
    ├────────► Semi-Automated
    └────────► Fully Automated

Assisted
    │
    ├────────► Manual
    ├────────► Semi-Automated
    └────────► Fully Automated

Semi-Automated
    │
    ├────────► Manual
    ├────────► Assisted
    └────────► Fully Automated

Fully Automated
    │
    ├────────► Manual
    ├────────► Assisted
    └────────► Semi-Automated
```

All transitions shall follow the same validation process.

---

# Transition Validation

Before activating a new Trading Mode, the platform shall validate:

- Trading Mode configuration
- Automation Policies
- Operational Controls
- Strategy compatibility
- Broker availability
- user authorization

Transitions that fail validation shall not be activated.

---

# Active Orders

Trading Mode changes shall not modify active Orders.

Examples include:

- Submitted Orders
- Accepted Orders
- Partially Filled Orders

These Orders continue under their existing lifecycle until completion.

New Trading Mode rules apply only to future execution decisions.

---

# Pending Approvals

Trading Mode changes may affect pending approval requests.

Platform behaviour shall be configurable.

Typical options include:

- preserve pending approvals
- cancel pending approvals
- re-evaluate pending approvals

The selected policy shall be applied consistently.

---

# Automation Activation

Transitioning to a more automated mode shall not automatically execute previously pending Recommendations.

Only Recommendations evaluated after the new Trading Mode becomes active shall follow the new execution policy unless explicitly re-evaluated.

This prevents unintended executions immediately after a mode change.

---

# Automation Deactivation

Transitioning to a less automated mode shall immediately stop automatic execution for future actions.

Already executing Orders continue normally through the Order Lifecycle.

No active execution shall be interrupted solely because the Trading Mode changed.

---

# User Notification

Users should be informed when:

- Trading Mode changes
- transition succeeds
- transition fails
- operational restrictions prevent activation

Mode changes should always be visible in the user interface.

---

# Events

Typical Events include:

- Trading Mode Change Requested
- Trading Mode Activated
- Trading Mode Activation Failed
- Automation Enabled
- Automation Disabled

These Events become part of the permanent operational history.

---

# Auditability

Every Trading Mode transition shall record:

- previous Trading Mode
- new Trading Mode
- transition timestamp
- initiating actor
- validation outcome
- activation result

The complete transition history shall remain permanently available.

---

# Design Principles

Mode Transitions shall:

- remain deterministic
- affect future execution only
- preserve active Orders
- preserve Order Lifecycle integrity
- support operational safety
- support complete auditing

Changing a Trading Mode changes platform behaviour.

It does not rewrite execution history.

---

# Summary

Mode Transitions provide a controlled mechanism for changing the operational behaviour of the StoX Platform.

By applying new Trading Modes only to future execution decisions while preserving active Orders and historical records, the platform ensures predictable behaviour, operational safety and complete auditability throughout changes in automation level.

# 9. Operational Controls

## Overview

Operational Controls provide administrators and users with mechanisms to immediately influence Trading Mode behaviour without modifying business logic or execution architecture.

These controls support operational safety, emergency response and controlled automation management.

Detailed Operational Control architecture is defined in:

**specs/platform/09-operational-control-framework.md**

This section defines only Trading Mode specific controls.

---

# Purpose

Operational Controls exist to:

- protect trading operations
- suspend automation
- support emergency intervention
- control execution authority
- improve operational resilience
- maintain safe trading environments

Operational Controls override Trading Mode behaviour when necessary.

---

# Control Hierarchy

Operational Controls are evaluated before Trading Mode policies.

The conceptual hierarchy is:

```text
Emergency Controls
        │
        ▼
Operational Controls
        │
        ▼
Trading Mode
        │
        ▼
Automation Policies
        │
        ▼
Execution Engine
```

Higher-priority controls always override lower-priority controls.

---

# Trading Mode Controls

Typical Trading Mode controls include:

Mode Controls

- Activate Manual Mode
- Activate Assisted Mode
- Activate Semi-Automated Mode
- Activate Fully Automated Mode

Automation Controls

- Pause Automation
- Resume Automation
- Disable Automatic Execution
- Enable Automatic Execution

Execution Controls

- Suspend New Orders
- Resume New Orders
- Cancel Pending Approvals
- Prevent New Approvals

These controls affect future execution decisions only.

---

# Emergency Controls

Emergency controls immediately override all Trading Modes.

Examples include:

- Emergency Stop
- Trading Freeze
- Strategy Suspension
- Broker Suspension
- Portfolio Suspension
- Account Suspension

Emergency controls take effect immediately.

---

# Scope of Control

Operational Controls may be applied at different scopes.

Platform

- affect all trading

Broker

- affect one broker

Broker Account

- affect one trading account

Portfolio

- affect one portfolio

Strategy

- affect one strategy

User

- affect one user

The selected scope determines which execution requests are affected.

---

# Behaviour During Suspension

When execution is suspended:

- no new Orders are created
- no new automatic executions begin
- pending Recommendations remain unchanged
- completed Orders remain unchanged
- active Orders continue through their lifecycle unless explicitly cancelled

Operational suspension does not rewrite execution history.

---

# Manual Overrides

Authorized users may override automation.

Examples include:

- manually approve blocked execution
- manually reject execution
- temporarily disable automation
- temporarily enable automation

Manual overrides shall always be recorded.

---

# Interaction with Trading Modes

Operational Controls always take precedence over Trading Modes.

Example:

```text
Trading Mode

↓

Fully Automated

↓

Emergency Stop

↓

Execution Blocked
```

Automation shall never bypass Operational Controls.

---

# Notifications

Users and administrators should be notified when:

- automation paused
- automation resumed
- Emergency Stop activated
- Trading Mode overridden
- execution blocked

Notification delivery is defined elsewhere.

---

# Events

Typical Events include:

- Automation Paused
- Automation Resumed
- Trading Suspended
- Trading Resumed
- Emergency Stop Activated
- Manual Override Applied
- Operational Control Released

These Events become part of the permanent operational history.

---

# Auditability

Operational Controls shall record:

- control type
- scope
- initiating actor
- activation timestamp
- deactivation timestamp
- affected Trading Mode
- affected execution requests

Every Operational Control action shall remain permanently traceable.

---

# Design Principles

Operational Controls shall:

- override Trading Modes
- remain deterministic
- preserve execution integrity
- support emergency intervention
- support auditing
- remain independent of execution architecture

Operational Controls govern platform behaviour.

Trading Modes govern execution authority.

---

# Summary

Operational Controls provide the mechanisms required to safely manage Trading Modes during normal operation and emergency situations.

By separating operational intervention from execution logic while allowing controls to override automation policies, the StoX Platform maintains predictable behaviour, operational resilience and complete auditability across all supported Trading Modes.

# 11. Extension Model

## Overview

The Trading Modes subsystem is designed to evolve through extension rather than architectural redesign.

New operating models, automation capabilities and execution policies should be introduced by extending existing architectural components while preserving the established execution architecture.

The objective is to enable increasingly sophisticated trading automation without impacting Recommendation generation, Risk Management, Broker Integration or the Order Lifecycle.

---

# Extension Philosophy

Trading Modes should evolve using the following order of preference.

```text
Reuse Existing Trading Mode

↓

Extend Automation Policies

↓

Extend Trading Mode Configuration

↓

Extend Trading Mode Engine

↓

Introduce New Architectural Component (Exceptional)
```

Existing architectural abstractions should always be reused where practical.

---

# Extending Trading Modes

Future platform versions may introduce additional operating modes.

Examples include:

- Paper Trading
- Simulation Mode
- Advisor Mode
- Collaborative Trading
- Institutional Trading
- AI-Assisted Trading
- Read-Only Monitoring

New modes should reuse the existing execution architecture.

Only execution authority and operational behaviour should differ.

---

# Extending Automation Policies

Automation Policies may evolve independently of Trading Modes.

Examples include:

- action-specific policies
- instrument-specific policies
- portfolio-specific policies
- strategy-specific policies
- time-based policies
- market-condition policies

Policy evaluation shall remain deterministic.

---

# Extending Approval Workflows

Future approval capabilities may include:

- multi-user approval
- delegated approval
- hierarchical approval
- approval escalation
- approval expiration policies
- approval reminders

Approval enhancements shall not alter the execution architecture.

---

# Extending Operational Controls

Future Operational Controls may include:

- scheduled automation windows
- maintenance windows
- trading calendars
- regional restrictions
- regulatory restrictions
- portfolio-specific controls

Operational Controls continue to override Trading Modes.

---

# Multi-User Operation

Future platform versions may support multiple operational roles.

Examples include:

- Portfolio Owner
- Portfolio Manager
- Trader
- Risk Officer
- Compliance Officer
- Read-Only Observer

Execution authority may be distributed across authorized roles while preserving deterministic approval workflows.

---

# AI-Assisted Operation

Future AI capabilities may assist operational decision making.

Examples include:

- approval recommendations
- automation suggestions
- policy optimization
- anomaly detection
- execution scheduling
- workload balancing

AI may provide recommendations.

Final execution authority shall remain governed by the active Trading Mode unless an explicitly AI-governed operating mode is introduced.

---

# Cross-Platform Operation

Future versions may coordinate execution across:

- multiple brokers
- multiple broker accounts
- multiple portfolios
- multiple strategies
- multiple geographic regions

Trading Mode evaluation shall remain independent of execution location.

---

# Future Governance Models

The governance model may evolve to support:

- regulatory approval workflows
- institutional governance
- organizational policy enforcement
- delegated execution authority
- compliance validation

Governance extensions should integrate through Automation Policies and Operational Controls.

---

# Backward Compatibility

Architectural evolution should preserve compatibility wherever practical.

Existing:

- Trading Modes
- Automation Policies
- Trading Mode Decisions
- operational history
- approval history

should remain valid after architectural enhancements.

Where incompatible changes are required, migration guidance shall be provided.

---

# Architectural Review

Every significant Trading Modes enhancement should be reviewed to ensure that it:

- preserves execution architecture
- preserves deterministic behaviour
- maintains operational safety
- supports complete auditability
- aligns with Platform Architecture principles
- avoids duplication of operational policy

New architectural concepts should be introduced only when existing abstractions cannot reasonably support the required capability.

---

# Design Principles

Trading Modes extensions shall:

- remain configuration-driven
- remain policy-driven
- preserve execution architecture
- support deterministic behaviour
- support operational safety
- favour extension over redesign

Architectural evolution should strengthen operational flexibility without increasing execution complexity.

---

# Summary

The Trading Modes subsystem is designed to evolve through disciplined extension while preserving the platform's deterministic execution architecture.

By extending Automation Policies, approval workflows and operational controls without altering the execution pipeline, the StoX Platform can support increasingly sophisticated operating models while maintaining consistency, governance and long-term maintainability.

---

# Appendix A — Canonical Trading Mode Flows

## Overview

This appendix illustrates the canonical operating patterns of the Trading Modes subsystem.

These flows demonstrate how execution authority changes across different Trading Modes while preserving a single execution architecture.

Future implementations should follow these architectural patterns wherever practical.

---

# Flow 1 — Manual Trading

The user manually approves every execution.

```text
Market Analysis
        │
        ▼
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Approval Requested
        │
        ▼
User Approves
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
```

Outcome:

- User authorizes execution
- One approval required
- Standard execution architecture used

---

# Flow 2 — Assisted Trading

The platform prepares execution before requesting approval.

```text
Market Analysis
        │
        ▼
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Execution Prepared
        │
        ▼
Approval Requested
        │
        ▼
User Approves
        │
        ▼
Execution Engine
```

Outcome:

- Platform performs preparation
- User provides final approval
- Faster execution

---

# Flow 3 — Semi-Automated Trading

Automation Policy determines execution authority.

```text
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Automation Policy Evaluation
        │
        ├────────────► Manual Approval
        │                     │
        │                     ▼
        │              User Decision
        │
        └────────────► Automatic Execution
                              │
                              ▼
                     Execution Engine
```

Outcome:

- Authority determined per action
- Mixed automation
- Policy-driven execution

---

# Flow 4 — Fully Automated Trading

Execution proceeds automatically.

```text
Market Analysis
        │
        ▼
Recommendation
        │
        ▼
Risk Approved
        │
        ▼
Trading Mode Evaluation
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
```

Outcome:

- No user approval
- Fully automatic execution
- Complete audit history maintained

---

# Flow 5 — Trading Mode Transition

The user changes the active Trading Mode.

```text
Mode Change Requested
        │
        ▼
Configuration Validation
        │
        ▼
Operational Safety Check
        │
        ▼
Trading Mode Activated
        │
        ▼
Future Executions Updated
```

Outcome:

- Existing Orders unaffected
- Future executions follow the new Trading Mode
- Transition recorded

---

# Flow 6 — Automation Suspended

An Operational Control suspends automation.

```text
Fully Automated
        │
        ▼
Emergency Stop
        │
        ▼
Automation Suspended
        │
        ▼
Execution Blocked
```

Outcome:

- Automatic execution halted
- Active Orders continue normally
- Operational event recorded

---

# Flow 7 — Manual Override

A user overrides the configured automation behaviour.

```text
Automatic Execution
        │
        ▼
Manual Override
        │
        ▼
Override Validation
        │
        ▼
Execution Continues
```

Outcome:

- Override recorded
- Execution continues under validated authority
- Audit trail preserved

---

# Flow 8 — Approval Expiration

User approval is not received before expiration.

```text
Recommendation
        │
        ▼
Approval Requested
        │
        ▼
Approval Timeout
        │
        ▼
Approval Expired
```

Outcome:

- No Order created
- No execution performed
- Recommendation may be regenerated

---

# Flow 9 — Policy Evaluation Failure

Automation Policy cannot determine execution authority.

```text
Execution Request
        │
        ▼
Policy Evaluation
        │
        ▼
Evaluation Failure
        │
        ▼
Execution Blocked
```

Outcome:

- No execution
- Operational alert generated
- Failure recorded for audit

---

# Flow 10 — Complete Trading Mode Lifecycle

The complete lifecycle of an execution decision.

```text
Recommendation
        │
        ▼
Risk Management
        │
        ▼
Trading Mode Evaluation
        │
        ▼
Automation Policy Evaluation
        │
        ▼
Execution Decision
        │
        ▼
Execution Engine
        │
        ▼
Broker Integration
        │
        ▼
Order Lifecycle
        │
        ▼
Portfolio Updated
```

Every execution follows this conceptual lifecycle regardless of the selected Trading Mode.

---

# Canonical Trading Mode Architecture

The Trading Modes subsystem follows a consistent architectural pattern.

```text
Trading Mode Configuration
        │
        ▼
Trading Mode Registry
        │
        ▼
Trading Mode Engine
        │
        ▼
Automation Policies
        │
        ▼
Execution Decision
        │
        ▼
Execution Engine
```

The execution architecture remains unchanged across all Trading Modes.

---

# Governance Hierarchy

Execution authority follows a well-defined governance hierarchy.

```text
Emergency Controls
        │
        ▼
Operational Controls
        │
        ▼
Trading Mode
        │
        ▼
Automation Policies
        │
        ▼
Risk Management
        │
        ▼
Execution Engine
```

Higher-priority controls always override lower-priority decisions.

---

# Summary

The canonical flows presented in this appendix demonstrate how Trading Modes control execution authority while preserving a single, deterministic execution architecture.

By separating operational policy from execution processing and applying consistent governance across Manual, Assisted, Semi-Automated and Fully Automated operation, the StoX Platform supports progressive automation without compromising safety, predictability or auditability.
