# Platform Architecture

---

# 1. Purpose

## Overview

The Platform Architecture defines the fundamental architectural principles, abstractions, and building blocks of StoX.

It serves as the constitutional document of the StoX architecture repository.

Every subsystem within StoX shall conform to the concepts defined by this specification.

Examples include:

- Portfolio Management
- Indicators
- Screeners
- Strategies
- Recommendation Engine
- Review Engine
- Execution Engine
- Backtesting
- Live Trading
- Notifications
- Integrations
- Future AI capabilities

This document intentionally defines platform-level concepts rather than feature-specific behavior.

---

# Objectives

The Platform Architecture exists to:

- define a common architectural language
- establish reusable platform abstractions
- eliminate duplicated architectural concepts
- promote consistency across specifications
- improve maintainability
- enable predictable system evolution
- provide implementation-independent guidance

---

# Scope

This specification defines:

- Platform Design Philosophy
- Universal Building Blocks
- Relationships between Building Blocks
- Platform-wide Architectural Principles
- Naming Standards
- Extension Model
- Cross-cutting Architectural Concerns

This specification does **not** define:

- Business Rules
- Trading Strategies
- Indicator Calculations
- Database Schemas
- REST APIs
- User Interface Design
- Broker Implementations
- Technology Choices

Those topics belong to their respective domain specifications.

---

# Position within the Architecture Repository

The Platform Architecture is the highest-level architectural specification within the StoX repository.

All domain-specific specifications shall inherit and reuse the concepts defined here.

Examples include:

- Portfolio Architecture
- Indicator Architecture
- Live Trading
- Recommendation Engine
- Review Engine
- Notification Engine
- Future AI modules

Domain specifications shall reference Platform concepts instead of redefining them.

---

# Intended Audience

This document is intended for:

- Software Architects
- Technical Leads
- Implementation Engineers
- AI-assisted Development Tools
- Technical Reviewers
- Future Contributors

Readers are expected to understand this document before implementing or extending any architectural domain.

---

# Relationship with Other Specifications

This specification defines reusable architectural concepts.

Other specifications define concrete implementations of those concepts.

For example:

Platform Architecture

↓

Business Engine

↓

Recommendation Engine

↓

Recommendation Run

↓

Recommendation Artifact

The Platform Architecture explains what a Business Engine is.

The Recommendation Engine specification explains how one particular Business Engine behaves.

---

# Platform Philosophy

StoX is designed as a configuration-driven decision platform.

The platform does not embed business logic directly into application code wherever practical.

Instead, business behavior is defined through reusable configurations interpreted by reusable platform components.

Examples include:

- Indicators
- Screeners
- Strategies
- Policies
- Alert Rules
- Execution Rules
- Notification Rules

This philosophy enables:

- explainability
- configurability
- extensibility
- AI-assisted authoring
- deterministic behavior

---

# Universal Lifecycle

Every major subsystem within StoX follows the same conceptual lifecycle.

Definition

↓

Execution

↓

Result

Examples include:

Indicator

↓

Indicator Calculation

↓

Indicator Value

---

Screener

↓

Screening Run

↓

Candidate List

---

Strategy

↓

Strategy Evaluation

↓

Recommendations

---

Backtest

↓

Backtest Run

↓

Backtest Report

---

Execution

↓

Execution Run

↓

Trades

---

Notification

↓

Notification Run

↓

Notification History

This lifecycle forms the conceptual foundation of the StoX platform.

Subsequent sections of this document define the reusable building blocks that participate in this lifecycle.

---

# Architectural Independence

The Platform Architecture is intentionally technology-independent.

It defines architectural concepts rather than implementation technologies.

Examples:

Preferred:

- Business Engine
- Run
- Registry
- Connector
- Artifact

Avoid:

- Laravel Queue
- PHP Class
- MySQL Table
- Redis Cache
- REST Controller

Technology choices may evolve over time.

Architectural concepts should remain stable.

---

# Single Source of Truth

Every reusable architectural concept shall have exactly one authoritative specification.

Examples include:

- Registry
- Policy
- Business Engine
- Run
- Artifact
- Connector
- Event
- Operational Control

This document serves as the authoritative source for platform-wide concepts.

Domain specifications shall reference this document instead of redefining those concepts.

---

# Guiding Principle

The Platform Architecture favors reusable abstractions over feature-specific implementations.

Whenever a new subsystem is introduced, architects should first determine whether it can be expressed using the existing platform building blocks.

Only when a genuinely new abstraction is required should the Platform Architecture itself be extended.

This principle preserves architectural consistency while allowing the platform to evolve over time.

# 2. Platform Design Philosophy

## Overview

StoX is designed as a configurable decision platform rather than a traditional hard-coded business application.

Instead of embedding business logic directly into application code, StoX separates reusable platform capabilities from configurable business definitions.

This separation enables new business behavior to be introduced primarily through configuration rather than software development.

The result is a platform that is:

- configurable
- deterministic
- explainable
- reusable
- extensible
- AI-friendly

---

# Platform Philosophy

The platform follows a simple architectural principle:

> **The platform provides capabilities. Configurations define behaviour.**

Platform capabilities remain relatively stable.

Business behaviour evolves through configuration.

Examples include:

| Platform Capability   | Configuration         |
| --------------------- | --------------------- |
| Indicator Engine      | Indicator Definition  |
| Screener Engine       | Screener Definition   |
| Strategy Engine       | Strategy Definition   |
| Recommendation Engine | Recommendation Policy |
| Execution Engine      | Execution Policy      |
| Notification Engine   | Notification Policy   |

The platform executes configurations.

Configurations do not implement execution logic.

---

# Separation of Responsibilities

Every platform component has a single primary responsibility.

Examples:

Configuration

Defines _what_ should happen.

Engine

Defines _how_ configured behaviour is executed.

Run

Represents one execution instance.

Artifact

Represents the output of an execution.

Connector

Interacts with external systems.

Policy

Defines constraints and decision rules.

Registry

Stores reusable definitions.

Operational Control

Controls platform behaviour during operation.

This separation keeps responsibilities independent and reusable.

---

# Configuration Driven Architecture

Configurations are first-class citizens within StoX.

Business behaviour should, wherever practical, be expressed as configuration instead of application code.

Examples include:

- Indicators
- Screeners
- Strategies
- Alert Policies
- Risk Policies
- Execution Policies
- Notification Policies
- Portfolio Settings

Configurations should remain declarative.

They describe desired behaviour without containing execution logic.

---

# Deterministic Behaviour

The platform is designed to produce deterministic outcomes.

Given:

- identical configurations
- identical market data
- identical execution context

the platform should produce identical results.

This principle enables:

- repeatable backtesting
- reproducible recommendations
- explainable decisions
- reliable testing
- AI validation

Random or non-deterministic behaviour should be avoided unless explicitly documented.

---

# Explainability

Every platform decision should be explainable.

The platform should be capable of answering questions such as:

- Why was this stock recommended?

- Which rule caused this rejection?

- Which indicator produced this value?

- Which policy blocked this execution?

- Why was this notification sent?

Platform decisions should be traceable through configuration, execution history, and generated artifacts.

---

# Composition over Specialization

The platform prefers composing simple reusable components rather than creating highly specialized implementations.

Examples:

Instead of creating:

- Breakout Recommendation Engine
- Momentum Recommendation Engine
- Swing Recommendation Engine

the platform provides:

Recommendation Engine

-

Strategy Definition

-

Policy

Different behaviours emerge through composition.

---

# Convention over Customization

Platform behaviour should follow consistent conventions wherever possible.

Architectural concepts should have:

- one lifecycle
- one terminology
- one ownership model
- one execution model

Consistency reduces complexity and improves maintainability.

---

# Platform Independence

The Platform Architecture intentionally avoids dependence on specific implementation technologies.

It defines architectural abstractions rather than software frameworks.

Examples:

Preferred:

- Business Engine
- Registry
- Connector
- Artifact
- Policy

Avoid:

- Laravel Service
- PHP Trait
- MySQL Trigger
- React Component

Technology may evolve.

Architectural principles should remain stable.

---

# Extensibility

New business capabilities should be introduced by extending existing platform abstractions whenever possible.

Examples:

A future AI Optimizer should become another Business Engine.

A future Broker should become another Connector.

A future Market Data Provider should become another Connector.

A future Asset Class should introduce new Configurations rather than requiring architectural redesign.

The preferred evolution path is:

Extend

rather than

Replace.

---

# Platform Consistency

Every subsystem should follow the same architectural vocabulary.

Definitions are created.

Definitions are executed.

Executions create Runs.

Runs generate Artifacts.

Events communicate changes.

Policies govern behaviour.

Operational Controls influence execution.

This consistency enables every subsystem to behave in a predictable manner regardless of its business purpose.

---

# Design Goals

The long-term goals of the Platform Architecture are:

- Maximize reuse of architectural concepts
- Minimize duplicated logic
- Keep business behaviour configurable
- Preserve deterministic execution
- Support explainable decision making
- Enable AI-assisted authoring
- Support incremental platform evolution
- Maintain implementation independence
- Provide a stable architectural foundation for future capabilities

These principles should guide every future architectural decision within StoX.

# 3. Universal Platform Building Blocks

## Overview

The StoX Platform is constructed from a small set of reusable architectural building blocks.

These building blocks are intentionally generic and technology-independent.

Every subsystem within StoX is composed by combining these building blocks in different ways.

Examples include:

- Indicator Evaluation
- Screening
- Strategy Evaluation
- Recommendation Generation
- Review
- Backtesting
- Live Trading
- Notifications
- Future AI modules

Regardless of business purpose, every subsystem follows the same architectural vocabulary.

This consistency allows the platform to evolve without introducing new architectural patterns for every feature.

---

# Building Blocks

The StoX Platform consists of the following universal building blocks.

| Building Block       | Primary Responsibility                       |
| -------------------- | -------------------------------------------- |
| Configuration        | Defines business behaviour                   |
| Registry             | Stores reusable configurations               |
| Policy               | Governs behaviour and constraints            |
| Business Engine      | Executes business logic                      |
| Orchestration Engine | Coordinates multiple Business Engines        |
| Run                  | Represents a single execution instance       |
| State Machine        | Tracks execution lifecycle                   |
| Artifact             | Represents execution output                  |
| Connector            | Integrates external systems                  |
| Event                | Communicates significant platform activity   |
| Operational Control  | Controls platform behaviour during operation |

These concepts are reused consistently throughout the platform.

---

# Conceptual Platform Model

Every subsystem follows the same conceptual flow.

```text
Configuration
        │
        ▼
Registry
        │
        ▼
Business Engine
        │
        ▼
Run
        │
        ▼
Artifact
```

The following components influence or support this flow.

```text
                 Policy
                    │
                    ▼

Configuration → Registry → Business Engine → Run → Artifact

        ▲              ▲             ▲          ▲
        │              │             │          │
 Connector         Events      State Machine  Audit

                    │
                    ▼

          Operational Controls

                    │
                    ▼

               Monitoring
```

This model represents logical relationships rather than implementation dependencies.

---

# Responsibilities

Each building block has a clearly defined responsibility.

No building block should perform responsibilities belonging to another.

Examples:

Configurations define behaviour.

Registries manage reusable definitions.

Policies constrain behaviour.

Business Engines execute business logic.

Runs represent execution.

Artifacts preserve execution results.

Connectors communicate with external systems.

Events communicate significant changes.

Operational Controls influence runtime behaviour.

Maintaining clear responsibility boundaries improves reuse, consistency and maintainability.

---

# Platform Composition

Platform features emerge through composition of building blocks rather than specialised implementations.

Example:

Strategy Evaluation

↓

Strategy Configuration

-

Strategy Registry

-

Strategy Engine

-

Strategy Run

-

Recommendation Artifact

Another example:

Live Trading

↓

Execution Policy

-

Execution Engine

-

Execution Run

-

Broker Connector

-

Trade Artifact

Although these represent different business capabilities, they are assembled using the same architectural vocabulary.

---

# Cross-Cutting Concerns

Some architectural concepts apply across every subsystem.

These include:

- Security
- Audit
- Monitoring
- Logging
- Permissions
- Operational Controls

These concerns are not owned by any individual Business Engine.

Instead, they influence platform behaviour as a whole.

---

# Platform Consistency

Every new architectural capability introduced into StoX should first attempt to reuse the existing platform building blocks.

Only when an existing abstraction cannot reasonably express the new capability should a new building block be introduced.

Introducing new building blocks should be considered an architectural decision and must be documented accordingly.

This principle ensures that the Platform Architecture remains compact, understandable and extensible over time.

---

# Subsequent Sections

The remainder of this document defines each building block individually.

Each definition describes:

- Purpose
- Responsibilities
- Lifecycle
- Relationships
- Ownership
- Design Principles
- Usage Guidelines

Every future architecture specification should reference these definitions rather than redefining them.

## 3.1 Configuration

### Overview

A Configuration is the fundamental building block of the StoX Platform.

It defines **what** the platform should do without defining **how** it is executed.

Configurations are declarative.

They describe business behaviour, decision logic, parameters, thresholds, and relationships, while delegating execution responsibility to Business Engines.

Configurations are intended to be portable, reusable, versionable, and machine-readable.

They represent business knowledge rather than executable software.

---

### Purpose

Configurations exist to separate business behaviour from application code.

Instead of embedding business rules into implementations, StoX stores those rules as reusable configurations interpreted by the platform.

This enables:

- configuration-driven behaviour
- deterministic execution
- reusable business definitions
- AI-assisted authoring
- simplified maintenance
- easier testing
- explainable decision making

---

### Characteristics

Every Configuration should be:

- Declarative
- Versionable
- Reusable
- Portable
- Deterministic
- Explainable
- Validatable
- Serializable

Configurations should never depend on implementation details.

---

### Configuration Ownership

Every Configuration belongs to exactly one architectural domain.

Examples include:

| Domain        | Configuration        |
| ------------- | -------------------- |
| Indicators    | Indicator Definition |
| Screeners     | Screener Definition  |
| Strategies    | Strategy Definition  |
| Live Trading  | Execution Policy     |
| Notifications | Notification Policy  |
| Risk          | Risk Policy          |
| Portfolio     | Portfolio Settings   |

The owning domain defines the configuration schema.

The platform provides the mechanisms for storing, validating, and executing those configurations.

---

### Configuration Lifecycle

Configurations typically progress through the following lifecycle.

Draft

↓

Validation

↓

Approval

↓

Registration

↓

Execution

↓

Versioning

↓

Retirement

Different domains may introduce additional lifecycle stages, but the overall lifecycle should remain consistent.

---

### Configuration Identity

Every Configuration should possess a stable identity.

Typical identifying attributes include:

- Identifier
- Name
- Description
- Version
- Owner
- Status
- Creation Timestamp
- Last Modified Timestamp

Additional metadata may be introduced by specific domains.

---

### Configuration Types

Examples of Configuration types include:

- Indicator Definitions
- Screener Definitions
- Strategy Definitions
- Alert Policies
- Risk Policies
- Execution Policies
- Notification Policies
- Portfolio Preferences

Future platform capabilities should introduce new Configuration types rather than introducing new architectural patterns.

---

### Relationship with Registries

Configurations are stored within Registries.

A Registry manages the lifecycle of Configurations but does not execute them.

Execution responsibility belongs to Business Engines.

---

### Relationship with Business Engines

Business Engines consume Configurations.

Configurations do not execute themselves.

A single Configuration may be executed many times under different execution contexts.

For example:

One Strategy Definition

↓

Many Strategy Runs

Similarly:

One Indicator Definition

↓

Thousands of Indicator Calculations

The Configuration remains unchanged while executions vary.

---

### Relationship with Runs

Runs represent individual executions of Configurations.

One Configuration may produce zero, one, or many Runs.

Runs record execution-specific information.

Configurations remain immutable during execution.

---

### Relationship with Artifacts

Configurations define expected behaviour.

Artifacts capture execution results.

Configurations are inputs.

Artifacts are outputs.

The two should remain clearly separated.

---

### Versioning

Configurations should support versioning.

Historical executions should remain associated with the Configuration version that produced them.

Modifying a Configuration should not invalidate historical Runs or Artifacts.

---

### Design Principles

Configurations should satisfy the following principles:

- Business-focused
- Implementation-independent
- Declarative
- Reusable
- Deterministic
- Human-readable
- Machine-readable
- AI-authorable
- Backward-compatible where practical

---

### Anti-Patterns

Configurations should never:

- contain executable code
- access databases directly
- call external systems
- maintain runtime state
- modify other Configurations
- perform business execution

Those responsibilities belong to other platform building blocks.

---

### Summary

Configurations define business intent.

They represent reusable, declarative definitions that are interpreted by Business Engines to produce deterministic platform behaviour.

Every configurable capability within StoX ultimately begins as a Configuration.

## 3.2 Registry

### Overview

A Registry is the authoritative repository for a specific type of Configuration.

Its primary responsibility is to manage the complete lifecycle of reusable business definitions.

Registries do not execute business logic.

They exist to organize, validate, version, discover and govern Configurations.

Every Configuration type within StoX should have exactly one owning Registry.

---

### Purpose

Registries provide a centralized mechanism for managing reusable platform definitions.

They ensure that Configurations are:

- uniquely identifiable
- version controlled
- discoverable
- reusable
- validated
- governed
- auditable

By separating storage from execution, Registries allow Business Engines to focus exclusively on execution.

---

### Registry Responsibilities

A Registry is responsible for:

- storing Configurations
- assigning identities
- maintaining versions
- validating definitions
- publishing approved definitions
- retiring obsolete definitions
- supporting discovery
- enforcing ownership
- maintaining metadata

A Registry is **not** responsible for executing Configurations.

Execution belongs exclusively to Business Engines.

---

### Registry Ownership

Each Registry owns exactly one Configuration type.

Examples include:

| Registry               | Configuration Type       |
| ---------------------- | ------------------------ |
| Indicator Registry     | Indicator Definitions    |
| Screener Registry      | Screener Definitions     |
| Strategy Registry      | Strategy Definitions     |
| Policy Registry        | Policies                 |
| Notification Registry  | Notification Definitions |
| Future Broker Registry | Broker Definitions       |

This ownership model prevents ambiguity and duplication.

---

### Registry Lifecycle

A Registry manages Configurations throughout their lifecycle.

Typical lifecycle:

Configuration Created

↓

Validation

↓

Approval

↓

Published

↓

Execution

↓

Revision

↓

Versioning

↓

Retirement

The Registry governs this lifecycle but does not perform execution.

---

### Registry Identity

Every Registry should possess:

- Unique Identifier
- Name
- Description
- Configuration Type
- Owner
- Version
- Status

These properties describe the Registry itself rather than the Configurations it contains.

---

### Registry Metadata

Registries should maintain metadata describing each Configuration.

Typical metadata includes:

- Name
- Description
- Category
- Tags
- Version
- Owner
- Status
- Creation Date
- Last Modified
- Approval Status

Business domains may introduce additional metadata where appropriate.

---

### Relationship with Configurations

Configurations are stored within Registries.

The Registry provides governance.

The Configuration provides business behaviour.

The Registry never modifies business intent.

It manages the Configuration rather than interpreting it.

---

### Relationship with Business Engines

Business Engines consume Configurations from Registries.

A Business Engine may execute many Configurations from the same Registry.

Example:

Strategy Registry

↓

Strategy Definition

↓

Strategy Engine

↓

Strategy Run

The Registry provides the Definition.

The Engine performs execution.

---

### Relationship with Runs

Registries do not create Runs.

Runs are created by Business Engines.

A Run records which Configuration version was executed.

Historical Runs should remain traceable to the originating Configuration version.

---

### Relationship with Artifacts

Artifacts should reference the originating Configuration.

This enables complete traceability:

Configuration

↓

Run

↓

Artifact

Historical Artifacts remain valid even when newer Configuration versions exist.

---

### Version Management

Registries should support multiple Configuration versions.

Historical versions should remain available for:

- audit
- backtesting
- reproducibility
- historical analysis

New versions should not invalidate historical executions.

---

### Discovery

Registries should support efficient discovery of Configurations.

Typical discovery mechanisms include:

- identifier
- name
- category
- tags
- owner
- status
- version
- capability

Discovery improves reuse and reduces duplication.

---

### Governance

Registries should enforce governance policies such as:

- ownership
- approval
- publication
- version control
- retirement
- access permissions

Governance ensures platform consistency without affecting execution behaviour.

---

### Design Principles

Registries should be:

- authoritative
- reusable
- discoverable
- version-aware
- deterministic
- auditable
- implementation-independent

Registries should never contain execution logic.

---

### Anti-Patterns

Registries should never:

- execute Configurations
- calculate business results
- perform external integrations
- maintain execution state
- contain business workflows
- implement business policies

These responsibilities belong to other platform building blocks.

---

### Summary

Registries are the authoritative homes for reusable Configurations.

They govern the lifecycle of business definitions while delegating execution to Business Engines.

Every reusable Configuration within StoX should have one clearly defined Registry responsible for its ownership, governance and discoverability.

## 3.3 Policy

### Overview

A Policy is a specialized type of Configuration that governs how platform components behave under specific conditions.

Unlike Definitions, which describe business intent, Policies describe constraints, limits, decision criteria and operational rules.

Policies influence execution without performing execution themselves.

They provide the governance layer between business definitions and platform execution.

---

### Purpose

Policies exist to ensure that platform behaviour remains:

- controlled
- consistent
- configurable
- auditable
- explainable

Instead of embedding operational rules directly into Business Engines, StoX expresses those rules as reusable Policies.

This separation allows operational behaviour to evolve independently of execution logic.

---

### Policy Characteristics

Every Policy should be:

- Declarative
- Versionable
- Explainable
- Deterministic
- Reusable
- Auditable
- Configurable

Policies should define constraints rather than implementation logic.

---

### Relationship with Configuration

Policy is a specialization of Configuration.

Configuration represents the general concept.

Policy represents one category of Configuration concerned with governance and decision rules.

Examples include:

- Execution Policy
- Risk Policy
- Notification Policy
- Allocation Policy
- Portfolio Policy

Policies inherit all characteristics of Configurations while introducing governance-specific behaviour.

---

### Responsibilities

Policies define:

- execution constraints
- approval requirements
- risk limits
- allocation rules
- notification behaviour
- operational thresholds
- validation criteria
- compliance requirements

Policies do not perform execution.

They only influence execution performed by Business Engines.

---

### Policy Ownership

Every Policy belongs to exactly one architectural domain.

Examples include:

| Domain       | Policy              |
| ------------ | ------------------- |
| Live Trading | Execution Policy    |
| Risk         | Risk Policy         |
| Notification | Notification Policy |
| Portfolio    | Allocation Policy   |
| Governance   | Approval Policy     |

Each domain owns its Policy schema and business semantics.

---

### Relationship with Business Engines

Business Engines consult Policies while performing execution.

Policies never invoke Business Engines.

Example:

Strategy Engine

↓

Execution Policy

↓

Recommendation Run

The Engine executes.

The Policy governs execution decisions.

---

### Relationship with Runs

A Run should record the Policy version that governed its execution.

This enables complete traceability.

Historical executions remain reproducible even if Policies evolve over time.

---

### Relationship with Artifacts

Artifacts should record the Policies that influenced their creation.

Examples include:

- Risk Report
- Recommendation Set
- Trade Execution
- Notification History

This enables explainability and auditability.

---

### Versioning

Policies should support versioning.

Historical Runs should remain associated with the Policy version active at the time of execution.

Policy revisions should never invalidate historical execution records.

---

### Policy Categories

Typical categories include:

Business Policies

Examples:

- Strategy Allocation
- Portfolio Allocation
- Review Requirements

Operational Policies

Examples:

- Execution Policy
- Retry Policy
- Scheduling Policy

Risk Policies

Examples:

- Maximum Exposure
- Position Limits
- Stop Loss Rules

Notification Policies

Examples:

- Delivery Channels
- Escalation Rules
- Frequency Limits

Future domains may introduce additional Policy categories without modifying the Platform Architecture.

---

### Design Principles

Policies should:

- express business constraints
- remain declarative
- avoid execution logic
- remain reusable
- support versioning
- be explainable
- support deterministic behaviour

---

### Anti-Patterns

Policies should never:

- execute business logic
- calculate results
- call external systems
- maintain execution state
- replace Business Engines
- duplicate Configuration Definitions

Policies govern behaviour.

They do not perform behaviour.

---

### Summary

Policies are specialized Configurations that define the rules governing platform execution.

They separate operational governance from execution logic, allowing Business Engines to remain reusable while keeping business constraints configurable, versionable and auditable.

## 3.4 Business Engine

### Overview

A Business Engine is the platform component responsible for executing business behaviour defined by Configurations.

Business Engines interpret reusable Configurations, apply applicable Policies, execute business logic and produce platform Artifacts.

Business Engines contain the execution intelligence of the StoX Platform.

They execute business capabilities.

They do not define business behaviour.

Business behaviour is defined by Configurations.

---

### Purpose

Business Engines exist to separate execution from business definition.

Instead of implementing different software for every investment methodology, StoX provides reusable Business Engines capable of executing many different Configurations.

This enables:

- configuration-driven execution
- reuse
- deterministic behaviour
- explainability
- AI-assisted authoring
- simplified maintenance

---

### Responsibilities

A Business Engine is responsible for:

- loading Configurations
- validating execution prerequisites
- applying Policies
- creating Runs
- executing business logic
- generating Artifacts
- publishing Events
- reporting execution outcomes

A Business Engine should never permanently own business definitions.

Definitions belong to Registries.

---

### Relationship with Configuration

Business Engines consume Configurations.

Configurations describe desired behaviour.

Business Engines execute that behaviour.

One Business Engine may execute many different Configurations.

Example:

Recommendation Engine

↓

Strategy A

Strategy B

Strategy C

The Engine remains unchanged.

Only the Configuration changes.

---

### Relationship with Registry

Business Engines retrieve Configurations from Registries.

Registries manage lifecycle.

Business Engines perform execution.

These responsibilities remain intentionally separated.

---

### Relationship with Policy

Business Engines consult applicable Policies during execution.

Policies influence behaviour.

Policies never perform execution.

Business Engines remain responsible for applying Policy decisions consistently.

---

### Relationship with Runs

Every Business Engine execution creates a Run.

A Run represents one execution instance.

Business Engines may execute thousands of Runs while remaining stateless between executions wherever practical.

---

### Relationship with Artifacts

Business Engines generate Artifacts.

Artifacts represent the persistent outputs of execution.

Examples include:

- Indicator Values
- Candidate Lists
- Recommendation Sets
- Trade Decisions
- Backtest Reports
- Notification Records

Business Engines create Artifacts.

They do not own their lifecycle after creation.

---

### Relationship with Events

Business Engines publish Events describing significant execution milestones.

Examples include:

- Run Started
- Run Completed
- Run Failed
- Artifact Generated
- Validation Failed

Events enable loose coupling between platform components.

---

### Relationship with Connectors

Business Engines may interact with external systems through Connectors.

Business Engines should never communicate directly with external services.

Examples:

Execution Engine

↓

Broker Connector

Notification Engine

↓

Notification Connector

Market Analysis Engine

↓

Market Data Connector

This separation improves maintainability and testability.

---

### Relationship with Operational Controls

Operational Controls may influence Business Engine behaviour.

Examples include:

- Pause execution
- Maintenance mode
- Kill switch
- Read-only mode

Business Engines should respect active Operational Controls.

---

### Characteristics

Every Business Engine should be:

- Stateless wherever practical
- Deterministic
- Explainable
- Observable
- Auditable
- Configurable
- Reusable
- Independent of specific Configurations

Business Engines should avoid retaining business state between executions unless explicitly required.

---

### Examples

Typical Business Engines include:

- Indicator Engine
- Screener Engine
- Strategy Engine
- Recommendation Engine
- Review Engine
- Execution Engine
- Backtest Engine
- Notification Engine

Future platform capabilities should introduce new Business Engines only when existing Engines cannot reasonably support the required capability.

---

### Design Principles

Business Engines should:

- execute business behaviour
- consume Configurations
- apply Policies
- create Runs
- produce Artifacts
- publish Events
- remain implementation-independent
- remain reusable

Business Engines should not embed business definitions directly.

---

### Anti-Patterns

Business Engines should never:

- permanently store Configurations
- replace Registries
- define Policies
- directly integrate with external systems
- bypass Operational Controls
- maintain long-term business state
- duplicate execution logic already provided by another Business Engine

Each Business Engine should have one clearly defined responsibility.

---

### Summary

Business Engines execute the business capabilities of the StoX Platform.

They transform reusable Configurations into deterministic business outcomes by applying Policies, creating Runs, generating Artifacts and publishing Events.

Business Engines represent the execution layer of the Platform Architecture while remaining independent of individual business definitions.

## 3.5 Orchestration Engine

### Overview

An Orchestration Engine coordinates the execution of one or more Business Engines to accomplish a larger business objective.

Unlike Business Engines, an Orchestration Engine does not implement business logic itself.

Its responsibility is to determine:

- what should execute
- when it should execute
- in what order
- under what conditions
- how execution progress should be managed

Business Engines perform work.

Orchestration Engines coordinate work.

---

### Purpose

Complex business processes often require multiple Business Engines to work together.

Examples include:

- Strategy Evaluation
- Backtesting
- Live Trading
- Daily Market Processing
- AI-assisted Portfolio Review

Rather than embedding orchestration logic inside Business Engines, StoX separates orchestration into its own architectural component.

This improves:

- modularity
- reuse
- scalability
- maintainability
- observability

---

### Responsibilities

An Orchestration Engine is responsible for:

- coordinating Business Engines
- sequencing execution
- managing dependencies
- tracking workflow progress
- managing execution phases
- handling retries
- recovering from failures
- monitoring completion
- publishing workflow events

An Orchestration Engine should not perform business calculations itself.

---

### Relationship with Business Engines

Business Engines execute individual business capabilities.

Orchestration Engines combine those capabilities into larger workflows.

Example:

Backtest Orchestration

↓

Recommendation Engine

↓

Review Engine

↓

Execution Engine

↓

Reporting Engine

Each Business Engine remains independent.

The Orchestration Engine coordinates their execution.

---

### Relationship with Policies

Orchestration behaviour may be influenced by Policies.

Examples include:

- Retry Policy
- Scheduling Policy
- Timeout Policy
- Failure Policy
- Approval Policy

Policies determine orchestration behaviour without implementing workflow logic.

---

### Relationship with Runs

An Orchestration Engine may create:

- one parent Run
- multiple child Runs

Example:

Backtest Run

↓

Recommendation Run

↓

Review Run

↓

Execution Run

↓

Reporting Run

This hierarchical structure enables detailed monitoring and auditability.

---

### Relationship with State Machines

Every orchestrated workflow progresses through a State Machine.

Typical workflow states include:

- Created
- Waiting
- Running
- Paused
- Completed
- Failed
- Cancelled

Business Engines manage their own execution states.

The Orchestration Engine manages the workflow state.

---

### Relationship with Artifacts

Business Engines generate Artifacts.

The Orchestration Engine may aggregate those Artifacts into higher-level results.

Example:

Recommendation Artifacts

↓

Trade Artifacts

↓

Performance Artifacts

↓

Backtest Report

The Orchestration Engine coordinates Artifact production but should avoid modifying Artifacts produced by Business Engines.

---

### Relationship with Events

Orchestration Engines publish workflow-level Events.

Examples include:

- Workflow Started
- Phase Completed
- Retry Initiated
- Workflow Paused
- Workflow Cancelled
- Workflow Completed

These Events provide visibility into long-running business processes.

---

### Relationship with Operational Controls

Operational Controls influence workflow execution.

Examples include:

- Pause Workflow
- Resume Workflow
- Cancel Workflow
- Maintenance Mode
- Emergency Stop

The Orchestration Engine should respect active Operational Controls throughout workflow execution.

---

### Characteristics

Every Orchestration Engine should be:

- deterministic
- observable
- resumable
- fault-tolerant
- auditable
- modular
- implementation-independent

Where practical, workflows should support interruption and later resumption without losing progress.

---

### Examples

Examples of Orchestration Engines include:

- Daily Market Processing
- Backtest Orchestrator
- Live Trading Workflow
- Portfolio Evaluation Pipeline
- Future AI Workflow Coordinator

Future orchestration capabilities should reuse the same architectural principles.

---

### Design Principles

An Orchestration Engine should:

- coordinate rather than calculate
- delegate rather than duplicate
- monitor rather than execute
- aggregate rather than replace
- remain independent of business logic

Business logic belongs inside Business Engines.

Workflow logic belongs inside Orchestration Engines.

---

### Anti-Patterns

An Orchestration Engine should never:

- implement business calculations
- duplicate Business Engine functionality
- permanently store Configurations
- bypass Policies
- ignore Operational Controls
- directly communicate with external systems when a Connector exists

Its responsibility is coordination, not execution.

---

### Summary

An Orchestration Engine coordinates multiple Business Engines to accomplish complex business objectives.

It manages workflow sequencing, execution dependencies, progress tracking and recovery while delegating business execution to specialized Business Engines.

By separating workflow coordination from business execution, the StoX Platform remains modular, scalable and extensible.

## 3.6 Run

### Overview

A Run represents a single execution instance of a Business Engine or an Orchestration Engine.

Runs capture the complete execution context of a business operation, including the Configuration used, Policies applied, execution state, generated Artifacts and execution outcome.

Runs are immutable historical records of execution.

They provide the foundation for:

- traceability
- auditability
- reproducibility
- monitoring
- reporting
- analytics

Every significant execution performed by the StoX Platform should be represented by a Run.

---

### Purpose

Runs exist to separate execution history from execution logic.

Business Engines execute business behaviour.

Runs record what actually happened.

This separation enables:

- historical analysis
- deterministic replay
- debugging
- auditing
- performance measurement
- execution reporting

---

### Characteristics

Every Run should be:

- uniquely identifiable
- immutable after completion
- deterministic
- auditable
- observable
- timestamped
- traceable

A Run represents an execution event, not a reusable business definition.

---

### Responsibilities

A Run is responsible for recording:

- execution identity
- execution context
- Configuration version
- Policy versions
- execution timestamps
- execution state
- generated Artifacts
- execution metrics
- execution outcome
- execution errors

Runs should not contain business logic.

---

### Run Identity

Every Run should possess a stable identity.

Typical attributes include:

- Run Identifier
- Run Type
- Status
- Started At
- Completed At
- Duration
- Owner
- Parent Run (optional)

Additional metadata may be introduced by specific domains.

---

### Relationship with Configuration

Every Run executes exactly one primary Configuration.

Examples:

Strategy Run

↓

Strategy Definition

Indicator Run

↓

Indicator Definition

Execution Run

↓

Execution Policy

Historical Runs should always reference the Configuration version that produced them.

---

### Relationship with Policy

Runs record the Policies that governed execution.

Examples include:

- Risk Policy
- Execution Policy
- Retry Policy
- Notification Policy

This enables deterministic replay and auditability.

---

### Relationship with Business Engine

Business Engines create Runs.

A single Business Engine may create many thousands of Runs during its lifetime.

The Business Engine performs execution.

The Run records execution.

---

### Relationship with Orchestration Engine

Orchestration Engines may create hierarchical Runs.

Example:

Backtest Run

↓

Recommendation Run

↓

Review Run

↓

Execution Run

↓

Reporting Run

This hierarchy enables complete visibility into complex workflows.

---

### Relationship with State Machine

Every Run progresses through a State Machine.

Typical states include:

Created

↓

Queued

↓

Running

↓

Completed

or

Running

↓

Failed

or

Running

↓

Cancelled

The exact State Machine is defined separately.

---

### Relationship with Artifacts

Runs generate Artifacts.

A single Run may produce:

- zero Artifacts
- one Artifact
- multiple Artifacts

Examples:

Recommendation Run

↓

Recommendation List

Execution Run

↓

Trades

Backtest Run

↓

Backtest Report

Runs own the relationship to Artifacts but do not own the Artifact lifecycle.

---

### Relationship with Events

Runs publish Events describing significant execution milestones.

Examples include:

- Run Created
- Run Started
- Run Progress Updated
- Run Completed
- Run Failed
- Run Cancelled

These Events enable monitoring and orchestration.

---

### Relationship with Monitoring

Monitoring systems observe Runs.

Typical metrics include:

- execution duration
- throughput
- failure rate
- retry count
- completion rate
- average execution time

Runs provide the primary unit of operational observability.

---

### Relationship with Audit

Runs form the foundation of the audit trail.

Every significant business decision should be traceable to the Run that produced it.

Historical Runs should never be modified after completion.

---

### Parent-Child Relationships

Runs may form hierarchies.

Examples:

Daily Trading Run

↓

Recommendation Run

↓

Review Run

↓

Execution Run

↓

Notification Run

Parent-child relationships improve traceability while preserving subsystem independence.

---

### Design Principles

Runs should:

- represent one execution instance
- remain immutable after completion
- capture complete execution context
- support deterministic replay
- support auditing
- support monitoring
- remain implementation-independent

---

### Anti-Patterns

Runs should never:

- contain reusable business definitions
- replace Configurations
- implement business logic
- directly execute external integrations
- modify completed execution history
- maintain unrelated business state

Runs record execution.

They do not perform execution.

---

### Summary

A Run represents one execution instance within the StoX Platform.

Runs capture the complete execution history of business operations, providing the foundation for traceability, auditability, monitoring, reporting and deterministic analysis.

Every significant execution performed by the platform should be represented by a Run.

## 3.7 State Machine

### Overview

A State Machine defines the permitted lifecycle of a Run.

It specifies:

- the possible execution states
- valid state transitions
- transition rules
- terminal states
- exceptional states

A State Machine does not perform execution.

It governs the progression of execution.

Every Run within the StoX Platform shall progress according to a well-defined State Machine.

---

### Purpose

State Machines exist to ensure that execution progresses in a predictable, auditable and deterministic manner.

Rather than allowing arbitrary status changes, the platform explicitly defines the legal lifecycle of every execution.

This enables:

- deterministic behaviour
- simplified monitoring
- auditability
- failure recovery
- resumable execution
- operational visibility

---

### Responsibilities

A State Machine is responsible for:

- defining execution states
- validating state transitions
- preventing invalid transitions
- identifying terminal states
- exposing execution progress
- enabling workflow recovery

A State Machine does not execute business logic.

---

### Relationship with Runs

Every Run owns exactly one State Machine.

The State Machine governs the lifecycle of that Run.

The Run records its current state.

The State Machine defines which states are valid.

---

### Generic Run Lifecycle

The default lifecycle for a Run is:

```text
Created

↓

Validated

↓

Queued

↓

Running

↓

Completed
```

Alternative terminal paths include:

```text
Running

↓

Failed
```

```text
Running

↓

Cancelled
```

```text
Validated

↓

Rejected
```

Specific Business Engines may introduce additional intermediate states where justified.

---

### State Transition Rules

Every transition should satisfy the following principles:

- deterministic
- explicit
- validated
- auditable

Transitions should never occur implicitly.

Every transition should have an identifiable cause.

Examples include:

- Policy evaluation
- User action
- Completion of execution
- External event
- Operational Control

---

### Terminal States

Terminal states represent completed execution lifecycles.

Typical terminal states include:

- Completed
- Failed
- Cancelled
- Rejected

Once a Run reaches a terminal state, no further execution should occur.

Historical records remain immutable.

---

### Failure Handling

Failures are valid execution outcomes.

A failed Run remains a completed historical record.

Failure information should include:

- failure reason
- timestamp
- originating component
- related Events
- recovery information where available

Failures should not invalidate historical auditability.

---

### Resumable Execution

Some Run types may support resumption.

Examples include:

- Backtesting
- Long-running workflows
- Live Trading orchestration

Resumable Runs should restart from a valid checkpoint rather than repeating completed work.

Whether a Run is resumable is determined by its owning Business Engine or Orchestration Engine.

---

### Relationship with Events

Every state transition should publish an Event.

Examples include:

- Run Queued
- Run Started
- Run Paused
- Run Resumed
- Run Completed
- Run Failed

These Events provide real-time operational visibility.

---

### Relationship with Monitoring

Monitoring systems observe state transitions.

Typical metrics include:

- queue time
- execution duration
- completion rate
- failure rate
- retry count
- average processing time

Monitoring derives operational health from Run state progression.

---

### Relationship with Operational Controls

Operational Controls may influence state transitions.

Examples include:

Pause

Running

↓

Paused

Resume

Paused

↓

Running

Emergency Stop

Running

↓

Cancelled

Maintenance Mode

Queued

↓

Waiting

Operational Controls should never violate the integrity of the State Machine.

---

### Relationship with Audit

Every state transition should be auditable.

Audit records should include:

- previous state
- new state
- transition timestamp
- initiating actor
- transition reason

Complete state history should remain available for historical analysis.

---

### Design Principles

State Machines should be:

- deterministic
- explicit
- finite
- auditable
- observable
- implementation-independent

State transitions should never depend on undocumented behaviour.

---

### Anti-Patterns

State Machines should never:

- execute business logic
- modify Configurations
- generate Artifacts
- communicate with external systems
- bypass Policies
- allow undefined transitions

Their responsibility is governance of execution lifecycle only.

---

### Summary

State Machines define the legal lifecycle of Runs.

They ensure that execution progresses through well-defined, deterministic and auditable states, enabling reliable monitoring, recovery and operational governance across the StoX Platform.

## 3.8 Artifact

### Overview

An Artifact is the persistent output produced by a Run.

Artifacts represent the business results of execution.

Unlike Configurations, which define intended behaviour, Artifacts capture the actual outcome of execution.

Artifacts form the primary information consumed by users, downstream Business Engines and external systems.

Artifacts are immutable historical records of business outcomes.

---

### Purpose

Artifacts exist to preserve the results of platform execution.

They enable:

- decision making
- reporting
- auditing
- historical analysis
- downstream processing
- AI-assisted analysis
- business traceability

Artifacts separate execution results from execution logic.

---

### Characteristics

Every Artifact should be:

- persistent
- immutable
- version-aware
- traceable
- auditable
- explainable
- deterministic
- reusable

Artifacts represent facts.

They do not represent future intentions.

---

### Responsibilities

Artifacts are responsible for recording:

- business outcomes
- execution results
- calculated values
- generated recommendations
- completed decisions
- execution evidence
- supporting metadata

Artifacts do not execute business logic.

Artifacts do not own workflow.

Artifacts do not modify Configurations.

---

### Relationship with Runs

Artifacts are produced by Runs.

Every Artifact should be traceable to the Run that created it.

Example:

Recommendation Run

↓

Recommendation Artifact

Another example:

Execution Run

↓

Trade Artifact

Historical Artifacts should always retain their originating Run reference.

---

### Relationship with Business Engines

Business Engines create Artifacts.

Business Engines do not permanently own them.

After creation, Artifacts become independent business records.

---

### Relationship with Configurations

Artifacts should reference the Configuration version responsible for their creation.

Example:

Strategy Definition v3

↓

Strategy Run

↓

Recommendation Artifact

This relationship enables complete explainability.

---

### Relationship with Policies

Artifacts should record the Policies that materially influenced their creation.

Examples include:

- Risk Policy
- Execution Policy
- Notification Policy
- Allocation Policy

This enables reproducibility and auditability.

---

### Relationship with Events

Artifact creation typically generates Events.

Examples include:

- Recommendation Created
- Trade Executed
- Report Generated
- Notification Delivered

Events communicate Artifact availability.

Artifacts remain the permanent business record.

---

### Artifact Categories

Typical Artifact categories include:

Calculation Artifacts

Examples:

- Indicator Values
- Technical Scores
- Market Metrics

Decision Artifacts

Examples:

- Recommendations
- Review Decisions
- Risk Assessments

Execution Artifacts

Examples:

- Trades
- Orders
- Execution Results

Reporting Artifacts

Examples:

- Backtest Reports
- Performance Reports
- Analytics Reports

Communication Artifacts

Examples:

- Notifications
- Alerts
- Messages

Future domains may introduce additional Artifact categories.

---

### Artifact Lifecycle

Artifacts generally progress through a simple lifecycle.

Created

↓

Published

↓

Referenced

↓

Archived

Unlike Runs, Artifacts usually do not possess complex execution state machines.

Artifacts primarily represent completed business outcomes.

---

### Artifact Identity

Every Artifact should possess:

- Artifact Identifier
- Artifact Type
- Originating Run
- Status
- Created Timestamp
- Owner (where applicable)

Additional metadata may be introduced by individual domains.

---

### Traceability

Every Artifact should support complete traceability.

Users should be able to determine:

- which Run created it
- which Configuration was executed
- which Policy governed execution
- when it was created
- which Business Engine produced it

Traceability is fundamental to explainability and auditing.

---

### Design Principles

Artifacts should:

- represent business outcomes
- remain immutable after publication
- support deterministic analysis
- remain independent of implementation technology
- support downstream reuse
- preserve historical accuracy

Artifacts should never require business logic to understand their meaning.

---

### Anti-Patterns

Artifacts should never:

- execute business logic
- modify Configurations
- replace Runs
- initiate external integrations
- maintain workflow state
- contain executable behaviour

Artifacts are business outputs.

They are not business processes.

---

### Examples

Examples of Artifacts include:

- Indicator Value
- Candidate List
- Recommendation
- Review Decision
- Trade
- Notification
- Backtest Report
- Portfolio Snapshot
- Market Snapshot
- Analytics Report

Every business capability within StoX ultimately exists to create one or more Artifacts.

---

### Summary

Artifacts are the persistent business outputs of the StoX Platform.

They capture the outcomes of execution in a reusable, traceable and immutable form.

Artifacts provide the foundation for user interaction, reporting, analytics, auditing and downstream business processing.

## 3.9 Connector

### Overview

A Connector is the platform component responsible for communicating with external systems.

Connectors isolate the StoX Platform from implementation details of third-party services, external applications, market data providers, brokers, messaging systems and AI providers.

Business Engines interact only with Connectors.

They never communicate directly with external systems.

This separation ensures that external integrations remain replaceable without affecting platform architecture.

---

### Purpose

Connectors provide a standardized mechanism for interacting with external dependencies.

They exist to:

- isolate implementation details
- simplify external integrations
- improve maintainability
- improve testability
- support multiple providers
- reduce coupling

The platform should remain independent of any specific external vendor.

---

### Responsibilities

A Connector is responsible for:

- communicating with external systems
- translating platform requests into provider-specific formats
- translating provider responses into platform models
- handling authentication
- handling provider-specific errors
- managing provider capabilities
- exposing a stable platform interface

A Connector should not implement business logic.

---

### Connector Ownership

Every Connector owns exactly one integration responsibility.

Examples include:

| Connector                | Responsibility              |
| ------------------------ | --------------------------- |
| Broker Connector         | Trading APIs                |
| Market Data Connector    | Market data providers       |
| Notification Connector   | Email, Telegram, WhatsApp   |
| AI Connector             | AI providers                |
| Authentication Connector | External identity providers |
| Storage Connector        | External storage services   |

A Connector should never own multiple unrelated integration domains.

---

### Relationship with Business Engines

Business Engines consume Connector services.

Business Engines should remain unaware of provider-specific implementation details.

Example:

Execution Engine

↓

Broker Connector

↓

Zerodha

Another example:

Notification Engine

↓

Notification Connector

↓

Telegram

Business Engines remain unchanged when providers change.

---

### Relationship with Policies

Connector behaviour may be governed by Policies.

Examples include:

- Retry Policy
- Timeout Policy
- Rate Limit Policy
- Provider Selection Policy
- Failover Policy

Policies determine connector behaviour without embedding provider-specific logic into Business Engines.

---

### Relationship with Runs

Connector interactions should be associated with the originating Run.

This enables complete traceability.

Example:

Execution Run

↓

Broker Connector

↓

Order Placement

If an external interaction fails, the failure should remain traceable to the originating Run.

---

### Relationship with Events

Connectors should publish Events describing significant interactions.

Examples include:

- Provider Connected
- Provider Disconnected
- Request Sent
- Response Received
- Authentication Failed
- Provider Timeout

These Events improve observability without exposing provider implementation details.

---

### Relationship with Operational Controls

Operational Controls may influence Connector behaviour.

Examples include:

- Disable Trading
- Disable Notifications
- Maintenance Mode
- Read-Only Mode

Connectors should respect active Operational Controls before communicating with external systems.

---

### Characteristics

Every Connector should be:

- replaceable
- implementation-independent
- provider-independent
- testable
- observable
- fault-tolerant
- reusable

A Connector should expose stable platform behaviour even if provider implementations evolve.

---

### Provider Abstraction

The platform communicates with Connectors.

Connectors communicate with Providers.

Example:

Execution Engine

↓

Broker Connector

↓

Zerodha

or

↓

Upstox

or

↓

Angel One

The Business Engine remains unchanged regardless of provider.

---

### Connector Categories

Typical Connector categories include:

Market Data Connectors

Examples:

- NSE
- BSE
- Yahoo Finance

Broker Connectors

Examples:

- Zerodha
- Upstox
- Angel One

Notification Connectors

Examples:

- Email
- Telegram
- WhatsApp

AI Connectors

Examples:

- OpenAI
- Gemini
- WatsonX

Infrastructure Connectors

Examples:

- Cloud Storage
- Identity Providers
- Logging Platforms

Future integrations should extend existing Connector categories where practical.

---

### Design Principles

Connectors should:

- isolate external systems
- expose stable interfaces
- avoid business logic
- support provider replacement
- support testing through abstraction
- remain implementation-independent

---

### Anti-Patterns

Connectors should never:

- implement business rules
- evaluate Strategies
- calculate Recommendations
- create Trades
- replace Business Engines
- own Configurations

Their responsibility is communication, not decision making.

---

### Summary

Connectors isolate the StoX Platform from external systems.

They provide stable, replaceable integration points that enable Business Engines to communicate with external providers without depending on provider-specific implementation details.

This separation preserves modularity, portability and long-term maintainability.

## 3.10 Event

### Overview

An Event represents a significant occurrence within the StoX Platform.

Events communicate that **something has happened**.

They do not describe what should happen next.

Events enable loosely coupled communication between independent platform components without creating direct dependencies.

Events are informational.

They do not execute business logic.

---

### Purpose

Events exist to improve modularity by allowing platform components to react to significant occurrences without tightly coupling those components together.

This enables:

- loose coupling
- extensibility
- observability
- monitoring
- notifications
- auditing
- future integrations

Business components should communicate through Events whenever practical.

---

### Responsibilities

An Event is responsible for describing:

- what occurred
- when it occurred
- where it occurred
- who initiated it (where applicable)
- the originating Run
- relevant contextual information

Events should never contain business execution logic.

---

### Event Characteristics

Every Event should be:

- immutable
- timestamped
- traceable
- observable
- lightweight
- implementation-independent

Events describe completed occurrences.

They do not represent commands or requests.

---

### Relationship with Runs

Most Events originate from Runs.

Examples include:

Run Created

Run Started

Run Completed

Run Failed

Run Cancelled

Every Event should be traceable to the originating Run whenever applicable.

---

### Relationship with Business Engines

Business Engines publish Events during execution.

Typical examples include:

- Recommendation Generated
- Trade Executed
- Indicator Calculated
- Review Completed

Business Engines should publish Events rather than directly invoking unrelated platform components.

---

### Relationship with Artifacts

Artifact creation commonly results in Events.

Example:

Recommendation Artifact Created

↓

Recommendation Available Event

Another example:

Trade Artifact Created

↓

Trade Executed Event

Events announce the existence of Artifacts.

Artifacts remain the permanent business record.

---

### Relationship with Connectors

Connector interactions may generate Events.

Examples include:

- Broker Connected
- Broker Disconnected
- Market Data Updated
- Notification Delivered
- Authentication Failed
- External Provider Timeout

These Events improve operational visibility while remaining independent of provider implementation.

---

### Relationship with Operational Controls

Operational Controls may generate Events.

Examples include:

- Maintenance Mode Enabled
- Trading Disabled
- Emergency Stop Activated
- Platform Resumed

These Events communicate operational changes across the platform.

---

### Event Categories

Typical Event categories include:

Execution Events

Examples:

- Run Started
- Run Completed
- Run Failed

Business Events

Examples:

- Recommendation Generated
- Review Approved
- Trade Executed
- Portfolio Rebalanced

Operational Events

Examples:

- Maintenance Enabled
- Trading Disabled
- Kill Switch Activated

Integration Events

Examples:

- Broker Connected
- Notification Sent
- Data Provider Updated

Audit Events

Examples:

- Configuration Published
- Policy Modified
- User Authenticated

Future architectural domains may introduce additional Event categories.

---

### Event Consumers

Events may be consumed by:

- Monitoring systems
- Notification Engine
- Audit services
- Operational dashboards
- AI services
- External integrations
- Future platform capabilities

The publisher of an Event should remain unaware of its consumers.

---

### Event Ordering

Events should represent the chronological sequence of significant platform activity.

Where ordering is important, Events should preserve execution order for the originating Run.

Consumers should not rely on global ordering across unrelated Runs.

---

### Event Retention

Platform policies determine how long Events are retained.

Events intended for operational monitoring may have different retention requirements than Events required for auditing.

Retention policies should remain configurable.

---

### Design Principles

Events should:

- describe facts
- remain immutable
- avoid business logic
- support loose coupling
- support observability
- remain implementation-independent

Events should communicate occurrences rather than intentions.

---

### Anti-Patterns

Events should never:

- execute business logic
- modify Configurations
- invoke Business Engines directly
- replace Artifacts
- represent user commands
- contain provider-specific implementation details

Events communicate information.

They do not coordinate execution.

---

### Examples

Examples of Events include:

- Indicator Calculated
- Screening Completed
- Recommendation Generated
- Recommendation Approved
- Trade Executed
- Notification Delivered
- Backtest Completed
- Market Data Updated
- User Authenticated
- Emergency Stop Activated

---

### Summary

Events communicate significant occurrences throughout the StoX Platform.

They provide a loosely coupled communication mechanism that enables monitoring, auditing, notifications and future extensibility without introducing unnecessary dependencies between platform components.

## 3.11 Operational Control

### Overview

Operational Controls govern the runtime behaviour of the StoX Platform.

They provide mechanisms for enabling, disabling, pausing, restricting or modifying platform operation without changing business Configurations or implementation code.

Operational Controls are intended for operational management rather than business decision making.

They allow administrators to safely influence platform behaviour during normal operation, maintenance, incident response and emergency situations.

---

### Purpose

Operational Controls exist to provide safe and controlled operation of the platform.

They enable operators to:

- pause execution
- resume execution
- restrict functionality
- respond to incidents
- perform maintenance
- protect capital
- manage operational risk

Operational Controls should affect platform behaviour immediately without requiring software deployment or Configuration changes.

---

### Responsibilities

Operational Controls are responsible for:

- enabling platform capabilities
- disabling platform capabilities
- pausing execution
- resuming execution
- restricting operations
- protecting critical functionality
- coordinating maintenance
- supporting emergency response

Operational Controls should not define business behaviour.

Business behaviour remains the responsibility of Configurations and Policies.

---

### Characteristics

Every Operational Control should be:

- centrally managed
- immediately effective
- auditable
- observable
- reversible where appropriate
- implementation-independent
- configurable

Operational Controls should always have a clearly defined scope.

---

### Scope

Operational Controls may operate at different levels.

Examples include:

Platform-wide

- Maintenance Mode
- Read-Only Mode
- Emergency Stop

Subsystem

- Disable Live Trading
- Disable Notifications
- Disable AI Services

Engine

- Pause Recommendation Engine
- Pause Execution Engine

Connector

- Disable Broker Connector
- Disable Market Data Connector

Future platform capabilities may introduce additional scopes.

---

### Relationship with Business Engines

Business Engines must respect active Operational Controls.

Examples include:

Trading Disabled

↓

Execution Engine refuses new executions.

Maintenance Mode

↓

Recommendation Engine suspends scheduled Runs.

Read-Only Mode

↓

Portfolio Engine rejects modifications.

Operational Controls influence execution behaviour without modifying Business Engine implementations.

---

### Relationship with Orchestration Engines

Orchestration Engines should monitor Operational Controls throughout workflow execution.

Examples:

Pause

↓

Workflow waits safely.

Resume

↓

Workflow continues.

Emergency Stop

↓

Workflow terminates safely.

Operational Controls should support graceful interruption whenever practical.

---

### Relationship with Runs

Operational Controls may influence Run progression.

Examples include:

Queued

↓

Waiting

Running

↓

Paused

Running

↓

Cancelled

Operational Controls should never corrupt Run history.

All interruptions should remain auditable.

---

### Relationship with Events

Operational Controls publish Events whenever their state changes.

Examples include:

- Maintenance Mode Enabled
- Maintenance Mode Disabled
- Trading Enabled
- Trading Disabled
- Emergency Stop Activated
- Emergency Stop Cleared

These Events improve operational visibility across the platform.

---

### Relationship with Audit

Every Operational Control action should be auditable.

Audit information should include:

- control activated
- activation timestamp
- initiating actor
- affected scope
- reason
- deactivation timestamp (where applicable)

Operational decisions should remain historically traceable.

---

### Categories

Typical Operational Controls include:

Platform Controls

Examples:

- Maintenance Mode
- Read-Only Mode
- Safe Mode

Execution Controls

Examples:

- Pause Execution
- Resume Execution
- Cancel Pending Runs

Trading Controls

Examples:

- Disable Live Trading
- Disable Order Submission
- Disable Broker Connectivity

Communication Controls

Examples:

- Disable Notifications
- Disable Webhooks
- Disable External Messaging

Emergency Controls

Examples:

- Kill Switch
- Capital Protection Mode
- Incident Response Mode

Future operational capabilities should extend these categories rather than introducing new architectural patterns.

---

### Design Principles

Operational Controls should:

- remain independent of business logic
- support immediate effect
- remain centrally managed
- support auditing
- support monitoring
- minimize operational risk
- avoid modifying business definitions

Operational Controls influence execution.

They do not redefine business behaviour.

---

### Anti-Patterns

Operational Controls should never:

- modify Configurations
- replace Policies
- execute Business Logic
- alter historical Runs
- modify Artifacts
- bypass auditing

Their responsibility is operational governance only.

---

### Examples

Examples of Operational Controls include:

- Maintenance Mode
- Read-Only Mode
- Pause Recommendation Generation
- Pause Live Trading
- Resume Processing
- Disable Broker Connectivity
- Disable Notifications
- Disable Webhooks
- Emergency Stop
- Trading Kill Switch

---

### Summary

Operational Controls provide centralized governance over the runtime behaviour of the StoX Platform.

They allow operators to safely manage platform operation during routine administration, maintenance and emergency situations while preserving the integrity of business definitions, execution history and architectural consistency.

# 4. Relationships Between Building Blocks

## Overview

The StoX Platform is composed of a small number of reusable architectural building blocks.

Individually, each building block has a clearly defined responsibility.

Collectively, they form a complete execution platform capable of supporting diverse business capabilities while maintaining consistency, extensibility and explainability.

This section describes how these building blocks interact to deliver business outcomes.

---

# Architectural Relationships

The platform follows a layered execution model.

```text
Business Knowledge

    Configuration
          │
          ▼
      Registry
          │
          ▼

Execution

  Business Engine
          │
          ▼
         Run
          │
          ▼
    State Machine
          │
          ▼

Business Outcomes

      Artifact
          │
          ▼
        Event
```

The following components influence this execution flow without becoming part of the execution chain.

```text
Policy
    │
    ├──────────────► Business Engine

Connector
    │
    ├──────────────► Business Engine

Operational Control
    │
    ├──────────────► Business Engine
    ├──────────────► Orchestration Engine
    └──────────────► Runs

Orchestration Engine
    │
    ├──────────────► Business Engines
```

---

# Primary Execution Flow

Every business capability follows the same conceptual lifecycle.

```text
Configuration

↓

Registry

↓

Business Engine

↓

Run

↓

State Machine

↓

Artifact

↓

Event
```

This lifecycle represents the canonical execution model of the StoX Platform.

Every major subsystem should conform to this model wherever practical.

---

# Configuration Relationships

Configurations define business behaviour.

Configurations:

- are governed by Registries
- are consumed by Business Engines
- remain immutable during execution
- are referenced by Runs
- are traceable from Artifacts

Configurations never perform execution.

---

# Registry Relationships

Registries own reusable Configurations.

Registries:

- manage Configuration lifecycle
- validate Configurations
- publish Configurations
- expose Configurations to Business Engines

Registries never execute business logic.

---

# Policy Relationships

Policies influence execution.

Policies:

- govern Business Engines
- constrain execution
- define operational rules
- influence decision making

Policies do not perform execution.

---

# Business Engine Relationships

Business Engines execute Configurations.

Business Engines:

- retrieve Configurations
- apply Policies
- create Runs
- generate Artifacts
- publish Events
- communicate through Connectors

Business Engines remain independent of external providers.

---

# Orchestration Relationships

Orchestration Engines coordinate Business Engines.

They:

- sequence execution
- monitor workflow progress
- coordinate multiple Runs
- aggregate execution outcomes

Business Engines remain independently executable.

---

# Run Relationships

Runs capture execution history.

Runs:

- belong to Business Engines
- reference Configurations
- record Policy versions
- progress through State Machines
- produce Artifacts
- publish Events

Runs form the primary unit of auditability.

---

# State Machine Relationships

State Machines govern Run progression.

They:

- validate transitions
- expose execution progress
- support recovery
- preserve deterministic execution

State Machines never execute business logic.

---

# Artifact Relationships

Artifacts preserve business outcomes.

Artifacts:

- originate from Runs
- reference Configurations
- reference Policies
- support reporting
- support analytics
- support downstream processing

Artifacts remain immutable after publication.

---

# Connector Relationships

Connectors isolate external systems.

Connectors:

- communicate with providers
- translate requests
- translate responses
- publish integration Events

Business Engines remain provider-independent.

---

# Event Relationships

Events communicate completed occurrences.

Events:

- originate from Runs
- announce Artifact creation
- improve observability
- support monitoring
- support auditing
- support integrations

Events never represent intentions or commands.

---

# Operational Control Relationships

Operational Controls govern runtime behaviour.

Operational Controls may influence:

- Business Engines
- Orchestration Engines
- Runs
- Connectors

Operational Controls never modify Configurations.

---

# Traceability Model

Every business outcome should be completely traceable.

```text
Artifact

↑

Run

↑

Business Engine

↑

Configuration

↑

Registry
```

Additional traceability should include:

- Policy versions
- Events
- Operational Controls
- Audit records

A user should always be able to determine:

- what executed
- why it executed
- when it executed
- which Configuration was used
- which Policies were applied
- which Business Engine performed execution
- which Artifact was produced

---

# Separation of Responsibilities

Each building block has exactly one primary responsibility.

| Building Block       | Responsibility                     |
| -------------------- | ---------------------------------- |
| Configuration        | Defines behaviour                  |
| Registry             | Governs definitions                |
| Policy               | Governs execution                  |
| Business Engine      | Executes business logic            |
| Orchestration Engine | Coordinates Business Engines       |
| Run                  | Records execution                  |
| State Machine        | Governs execution lifecycle        |
| Artifact             | Preserves business outcomes        |
| Connector            | Integrates external systems        |
| Event                | Communicates completed occurrences |
| Operational Control  | Governs runtime operation          |

Maintaining these responsibility boundaries is essential to preserving the modularity and long-term maintainability of the platform.

---

# Architectural Principles

The relationships described in this section establish the canonical interaction model for the StoX Platform.

Future architectural capabilities should reuse these existing relationships wherever practical.

Introducing new relationship types should be considered an architectural change and documented through the repository governance process.

The objective is to evolve the platform by extending its existing architectural model rather than introducing parallel models for individual features.

# 5. Platform Principles

## Overview

The Platform Principles define the architectural rules that guide the design and evolution of the StoX Platform.

Unlike implementation guidelines, these principles describe the long-term architectural philosophy of the platform.

Every future architectural decision should be evaluated against these principles.

Where trade-offs are necessary, deviations should be explicitly documented through the architecture governance process.

---

# Principle 1 — Configuration over Code

Business behaviour should be expressed as reusable Configurations rather than embedded directly into application code wherever practical.

Examples include:

- Indicator Definitions
- Screener Definitions
- Strategy Definitions
- Policies
- Notification Rules
- Risk Rules

The platform provides execution capabilities.

Configurations define business behaviour.

Benefits:

- easier maintenance
- AI-assisted authoring
- deterministic execution
- reduced implementation effort
- improved reuse

---

# Principle 2 — Separation of Responsibilities

Every architectural building block shall have one clearly defined responsibility.

Examples:

Configuration

Defines business behaviour.

Registry

Manages reusable definitions.

Business Engine

Executes business behaviour.

Run

Records execution.

Artifact

Represents business outcomes.

Responsibilities should not overlap.

---

# Principle 3 — Single Source of Truth

Every reusable architectural concept shall have exactly one authoritative specification.

Examples include:

- Run
- Registry
- Policy
- Connector
- Artifact
- Event

Future specifications should reference existing concepts rather than redefining them.

---

# Principle 4 — Reuse over Duplication

The platform should evolve by composing existing building blocks rather than creating specialized implementations.

Preferred:

Recommendation Engine

-

Strategy Definition

-

Recommendation Policy

Avoid:

Momentum Recommendation Engine

Swing Recommendation Engine

Breakout Recommendation Engine

Composition reduces duplication and simplifies maintenance.

---

# Principle 5 — Deterministic Execution

Given identical:

- Configurations
- Policies
- Input Data
- Execution Context

the platform should produce identical results.

Deterministic execution enables:

- reproducible backtesting
- explainable recommendations
- reliable testing
- auditability

---

# Principle 6 — Explainability

Every significant business outcome should be explainable.

Users should be able to determine:

- what happened
- why it happened
- which Configuration was used
- which Policies were applied
- which Run performed execution

Platform behaviour should never depend on undocumented logic.

---

# Principle 7 — Traceability

Every business outcome should support complete traceability.

A user should be able to navigate:

Artifact

↓

Run

↓

Business Engine

↓

Configuration

↓

Registry

This traceability enables:

- auditing
- debugging
- reporting
- compliance

---

# Principle 8 — Loose Coupling

Platform components should communicate through well-defined architectural interfaces.

Examples include:

- Registries
- Connectors
- Events
- Artifacts

Business Engines should not directly depend on unrelated platform components.

Loose coupling improves extensibility and simplifies maintenance.

---

# Principle 9 — Extensibility

New capabilities should extend the existing Platform Architecture rather than introducing alternative architectural patterns.

Before introducing a new architectural concept, architects should determine whether the capability can be expressed using existing building blocks.

Only genuinely new abstractions should extend the Platform Architecture.

---

# Principle 10 — Technology Independence

Platform Architecture should describe architectural concepts rather than implementation technologies.

Preferred:

- Business Engine
- Connector
- Run
- Registry

Avoid:

- PHP
- Laravel
- MySQL
- React

Technology choices may evolve.

Architectural concepts should remain stable.

---

# Principle 11 — Operational Safety

The platform shall support safe operation through Operational Controls.

Examples include:

- Maintenance Mode
- Read-Only Mode
- Emergency Stop
- Trading Disable

Operational Controls should influence execution without modifying business definitions.

---

# Principle 12 — Audit by Design

Auditability should be considered a fundamental platform capability rather than an optional feature.

Every significant operation should be attributable to:

- a Configuration
- a Run
- a Business Engine
- a Policy
- a User or System Actor
- a Timestamp

Historical records should remain immutable.

---

# Principle 13 — Evolution through Extension

Platform evolution should occur by extending existing abstractions rather than replacing them.

Future capabilities should integrate naturally with:

- Configurations
- Registries
- Business Engines
- Runs
- Artifacts
- Connectors
- Events

Architectural consistency should be preserved over time.

---

# Principle 14 — Architecture before Implementation

The Platform Architecture defines the desired architectural model.

Implementation should conform to the architecture.

Implementation constraints should not redefine architectural concepts.

Where implementation limitations exist, they should be documented separately rather than altering the Platform Architecture.

---

# Summary

These Platform Principles establish the architectural philosophy of StoX.

Every future specification should align with these principles to ensure that the platform remains:

- consistent
- reusable
- explainable
- deterministic
- extensible
- implementation-independent

Adhering to these principles enables the platform to evolve while preserving architectural integrity and long-term maintainability.

# 6. Naming Standards

## Overview

Consistent terminology is fundamental to maintaining a coherent architecture.

The StoX Platform defines a canonical vocabulary for architectural concepts.

Every specification should use these terms consistently.

Alternative terminology should be avoided unless explicitly defined by a domain specification.

---

# Naming Principles

The platform follows the following naming principles:

- One concept shall have one preferred name.
- One preferred name shall represent one concept.
- Architectural terminology shall remain stable over time.
- Business terminology may evolve independently.
- Domain specifications shall reuse platform terminology wherever practical.

---

# Canonical Architectural Terms

The following terms have platform-wide meaning.

| Term                 | Definition                                                                            |
| -------------------- | ------------------------------------------------------------------------------------- |
| Configuration        | Declarative business definition that describes desired behaviour.                     |
| Registry             | Authoritative repository for Configurations.                                          |
| Policy               | Configuration governing execution behaviour and constraints.                          |
| Business Engine      | Component responsible for executing business capabilities.                            |
| Orchestration Engine | Component responsible for coordinating Business Engines.                              |
| Run                  | One execution instance.                                                               |
| State Machine        | Governs the lifecycle of a Run.                                                       |
| Artifact             | Persistent business output produced by a Run.                                         |
| Connector            | Component responsible for external integrations.                                      |
| Event                | Immutable notification describing a completed occurrence.                             |
| Operational Control  | Runtime control influencing platform behaviour without changing business definitions. |

These definitions are authoritative throughout the architecture repository.

---

# Naming Conventions

Architectural components should follow consistent naming.

Examples:

- Strategy Engine
- Recommendation Engine
- Execution Engine

- Strategy Registry
- Indicator Registry

- Strategy Run
- Recommendation Run

- Recommendation Artifact
- Trade Artifact

Names should clearly communicate responsibility.

---

# Preferred Terminology

Use:

- Run

Avoid:

- Job
- Process
- Task
- Worker

---

Use:

- Artifact

Avoid:

- Result
- Output Object
- Response Record
- Generated Data

---

Use:

- Configuration

Avoid:

- Definition File
- Rule File
- JSON Document

---

Use:

- Business Engine

Avoid:

- Processor
- Executor
- Manager
- Handler

---

Use:

- Connector

Avoid:

- Client
- Gateway
- Service Wrapper

unless a specific implementation requires those names internally.

---

# Domain Terminology

Business domains may introduce domain-specific terminology.

Examples:

Recommendation

Trade

Portfolio

Order

Position

Indicator

Screening

These terms supplement platform terminology.

They should never redefine platform concepts.

---

# Reserved Architectural Vocabulary

The following terms are reserved for platform architecture and should not be repurposed with unrelated meanings:

- Configuration
- Registry
- Policy
- Business Engine
- Orchestration Engine
- Run
- State Machine
- Artifact
- Connector
- Event
- Operational Control

---

# Documentation Standards

All future specifications should:

- use canonical platform terminology
- avoid introducing synonyms for existing concepts
- reference existing platform definitions
- define new terminology only when introducing genuinely new architectural concepts

When new terminology is required, it should be added to the platform glossary and referenced by future specifications.

---

# Summary

Consistent terminology improves communication between architects, implementation engineers, reviewers and AI-assisted development tools.

By maintaining one preferred term for each architectural concept, the StoX Platform preserves clarity, reduces ambiguity and improves long-term maintainability.

# 7. Extension Model

## Overview

The StoX Platform is designed for long-term evolution.

New business capabilities should extend the existing Platform Architecture rather than introducing new architectural patterns.

This section defines the principles for extending the platform while preserving consistency, maintainability and architectural integrity.

---

# Extension Philosophy

Platform evolution should occur through extension rather than modification.

Existing architectural concepts should remain stable.

New capabilities should be introduced by composing or extending existing building blocks.

The preferred evolution path is:

Reuse

↓

Extend

↓

Introduce New Abstraction (only if necessary)

---

# Preferred Extension Process

Before introducing a new architectural concept, architects should evaluate whether the requirement can be expressed using existing platform building blocks.

The recommended decision process is:

```text
New Capability

↓

Can an existing Configuration represent it?

↓

Yes → Reuse

↓

No

↓

Can an existing Business Engine execute it?

↓

Yes → Extend

↓

No

↓

Can an existing architectural abstraction be generalized?

↓

Yes → Refactor

↓

No

↓

Introduce a new architectural building block
```

New building blocks should be considered exceptional rather than routine.

---

# Extending Configurations

New business capabilities should first attempt to introduce new Configuration types.

Examples include:

- AI Strategy Definition
- Tax Policy
- Portfolio Optimization Policy
- Risk Model Definition

Adding new Configuration types should not require changes to the Platform Architecture.

---

# Extending Registries

Every new Configuration type should have one owning Registry.

Examples include:

- AI Registry
- Broker Registry
- Tax Rule Registry
- Portfolio Model Registry

Registries should follow the same lifecycle and governance model defined by the Platform Architecture.

---

# Extending Business Engines

When a genuinely new business capability cannot be implemented using an existing Business Engine, a new Business Engine may be introduced.

Examples include:

- AI Optimization Engine
- Tax Calculation Engine
- Portfolio Rebalancing Engine

New Business Engines should:

- consume Configurations
- create Runs
- produce Artifacts
- publish Events
- respect Policies
- respect Operational Controls

---

# Extending Connectors

New external systems should be integrated by introducing additional Connectors.

Examples include:

- New Broker Connector
- New AI Connector
- New Messaging Connector
- New Market Data Connector

Existing Business Engines should remain unchanged.

---

# Extending Artifacts

New business capabilities may introduce new Artifact types.

Examples include:

- Optimization Report
- Tax Report
- AI Recommendation
- Portfolio Snapshot
- Compliance Report

Artifacts should continue following the platform lifecycle and traceability model.

---

# Extending Policies

New operational requirements should generally be implemented through Policies.

Examples include:

- Tax Policy
- Allocation Policy
- AI Governance Policy
- Compliance Policy

Policies should influence execution without embedding business logic into Business Engines.

---

# Extending Operational Controls

Operational capabilities may evolve independently.

Examples include:

- AI Disable
- Broker Freeze
- Portfolio Lock
- Emergency Read-Only Mode

Operational Controls should remain implementation-independent.

---

# Architectural Compatibility

Every new architectural capability should answer the following questions.

Can it be represented by an existing Configuration?

Does it belong to an existing Registry?

Can an existing Business Engine execute it?

Does it create Runs?

Does it produce Artifacts?

Does it publish Events?

Does it require a Connector?

Does it respect Operational Controls?

If the answer to most of these questions is "yes", the capability naturally fits within the existing Platform Architecture.

---

# Introducing New Building Blocks

A new architectural building block should only be introduced when all of the following conditions are satisfied:

- existing abstractions cannot reasonably model the capability
- the concept has platform-wide applicability
- the concept has a clearly defined responsibility
- the concept introduces long-term architectural value
- the concept does not overlap existing responsibilities

New building blocks should be rare.

Each new building block should be documented through the architecture governance process.

---

# Backward Compatibility

Platform evolution should preserve compatibility wherever practical.

New capabilities should avoid invalidating:

- existing Configurations
- existing Runs
- existing Artifacts
- existing Registries
- existing Policies

Where breaking changes are unavoidable, migration guidance should be provided.

---

# Architectural Review

Every significant architectural extension should undergo review.

The review should evaluate:

- architectural consistency
- responsibility boundaries
- reuse opportunities
- traceability
- operational impact
- governance implications

Major architectural changes should be documented as Architecture Decision Records (ADRs).

---

# Summary

The StoX Platform is intended to evolve through disciplined extension rather than continual architectural redesign.

By reusing existing building blocks and introducing new abstractions only when necessary, the platform can continue growing while preserving consistency, maintainability and long-term architectural stability.

---

# Appendix A — Canonical Platform Flows

## Overview

This appendix demonstrates how the architectural building blocks defined in this specification interact to implement real platform capabilities.

These examples are illustrative rather than exhaustive.

Their purpose is to provide a common mental model for architects, implementation engineers and AI-assisted development tools.

Every future platform capability should, wherever practical, follow one of these canonical patterns.

---

# Example 1 — Indicator Calculation

The platform evaluates an Indicator Definition and produces an Indicator Value.

```text
Indicator Definition
        │
        ▼
Indicator Registry
        │
        ▼
Indicator Engine
        │
        ▼
Indicator Run
        │
        ▼
Indicator State Machine
        │
        ▼
Indicator Value Artifact
        │
        ▼
Indicator Calculated Event
```

Responsibilities:

- Configuration defines the calculation.
- Registry manages the definition.
- Business Engine performs the calculation.
- Run records execution.
- State Machine governs execution lifecycle.
- Artifact stores calculated values.
- Event announces completion.

---

# Example 2 — Stock Screening

The platform evaluates a Screener and produces a Candidate List.

```text
Screener Definition
        │
        ▼
Screener Registry
        │
        ▼
Screener Engine
        │
        ▼
Screening Run
        │
        ▼
Candidate List Artifact
        │
        ▼
Screening Completed Event
```

The resulting Candidate List may be consumed by downstream Business Engines.

---

# Example 3 — Strategy Evaluation

The platform evaluates a Strategy and generates Recommendations.

```text
Strategy Definition
        │
        ▼
Strategy Registry
        │
        ▼
Strategy Engine
        │
        ▼
Strategy Run
        │
        ▼
Recommendation Artifact
        │
        ▼
Recommendation Generated Event
```

Applicable Policies may include:

- Allocation Policy
- Risk Policy
- Recommendation Policy

---

# Example 4 — Backtesting

Backtesting demonstrates orchestration across multiple Business Engines.

```text
Backtest Configuration
        │
        ▼
Backtest Orchestration Engine
        │
        ├──────────────► Recommendation Engine
        │
        ├──────────────► Review Engine
        │
        ├──────────────► Execution Engine
        │
        └──────────────► Reporting Engine
                            │
                            ▼
                    Backtest Report Artifact
                            │
                            ▼
                   Backtest Completed Event
```

The Orchestration Engine coordinates execution.

Individual Business Engines remain independent.

---

# Example 5 — Live Trading

Live Trading executes approved Recommendations using an external Broker.

```text
Execution Policy
        │
        ▼
Execution Engine
        │
        ▼
Execution Run
        │
        ▼
Broker Connector
        │
        ▼
Broker API
        │
        ▼
Trade Artifact
        │
        ▼
Trade Executed Event
```

Operational Controls may interrupt execution before Broker communication occurs.

---

# Example 6 — Notification Delivery

The platform delivers Notifications using external communication providers.

```text
Notification Policy
        │
        ▼
Notification Engine
        │
        ▼
Notification Run
        │
        ▼
Notification Connector
        │
        ▼
Email / Telegram / WhatsApp
        │
        ▼
Notification Artifact
        │
        ▼
Notification Delivered Event
```

The Notification Engine remains independent of communication providers.

---

# Example 7 — Complete Decision Pipeline

The following illustrates a complete end-to-end investment decision.

```text
Market Data
        │
        ▼
Indicator Engine
        │
        ▼
Indicator Artifacts
        │
        ▼
Screener Engine
        │
        ▼
Candidate Artifacts
        │
        ▼
Strategy Engine
        │
        ▼
Recommendation Artifacts
        │
        ▼
Review Engine
        │
        ▼
Approved Recommendations
        │
        ▼
Execution Engine
        │
        ▼
Trade Artifacts
        │
        ▼
Notification Engine
        │
        ▼
Notifications
```

Every stage follows the architectural lifecycle defined by the Platform Architecture.

---

# Canonical Execution Pattern

Although business capabilities differ, every Business Engine follows the same execution model.

```text
Configuration
        │
        ▼
Registry
        │
        ▼
Business Engine
        │
        ▼
Run
        │
        ▼
State Machine
        │
        ▼
Artifact
        │
        ▼
Event
```

Cross-cutting concerns apply throughout execution.

```text
                     Policy
                        │
                        ▼

Configuration → Business Engine → Run → Artifact → Event

        ▲                 ▲            ▲
        │                 │            │
   Connector      Operational Control  Audit
```

This execution model represents the canonical architectural pattern of the StoX Platform.

Future capabilities should conform to this model wherever practical.

---

# Summary

The examples presented in this appendix are intended to illustrate how the platform building blocks collaborate to implement business capabilities.

New features should extend these canonical patterns rather than introducing alternative architectural models.

Maintaining these common execution patterns preserves consistency, explainability, traceability and long-term architectural stability across the StoX Platform.
