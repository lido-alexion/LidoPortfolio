# Discovery and Screening

---

# 1. Purpose

## Overview

The Discovery and Screening architecture defines the standardized framework for identifying candidate investment opportunities within the StoX Platform.

Its responsibility is to reduce the investable universe into a manageable set of candidates suitable for evaluation by investment strategies.

Discovery identifies opportunities.

Strategies determine whether those opportunities satisfy investment objectives.

---

# Objectives

The Discovery and Screening architecture exists to:

- standardize opportunity discovery
- separate discovery from strategy evaluation
- support reusable screening logic
- simplify candidate selection
- enable deterministic filtering
- preserve traceability
- support future extensibility

---

# Scope

This specification defines:

- discovery architecture
- screening pipeline
- candidate evaluation
- discovery outputs
- platform relationships
- architectural extension

This specification does not define:

- investment methodology
- strategy evaluation
- signal generation
- recommendation generation
- trade execution
- portfolio management

These responsibilities are defined in their respective architectural specifications.

---

# Position within the Platform Architecture

Discovery operates before the Strategy Engine.

The conceptual architecture is:

```text
Market Data
        │
        ▼
Discovery
        │
        ▼
Screening
        │
        ▼
Candidate Set
        │
        ▼
Strategy Engine
```

Discovery reduces the investment universe.

The Strategy Engine evaluates discovered opportunities.

---

# Architectural Responsibility

The Discovery and Screening architecture is responsible for:

- defining investment universes
- identifying candidate securities
- applying screening criteria
- ranking candidates
- producing discovery outputs
- publishing discovery events

The Discovery architecture is not responsible for:

- evaluating investment strategies
- generating Recommendations
- evaluating execution risk
- executing Orders
- managing Portfolios

Discovery prepares opportunities.

Strategies evaluate opportunities.

---

# Platform Relationships

Within the Platform Architecture, Discovery and Screening consists of:

Configuration

- Discovery Policies
- Screening Rules

Registry

- Discovery Registry

Business Engine

- Discovery Engine

Run

- Discovery Run

Artifact

- Candidate Set
- Discovery Result

Event

- Discovery Events

Operational Control

- Discovery Controls

The architecture follows the standardized Platform Architecture patterns.

---

# Guiding Principles

Discovery and Screening follows these principles:

- deterministic discovery
- reusable screening
- modular architecture
- technology independence
- complete traceability
- business transparency
- operational consistency

---

# Success Criteria

A successful Discovery implementation should ensure that:

- candidate identification is deterministic
- screening logic is reusable
- discovery remains independent of investment methodology
- discovery history is preserved
- downstream strategies receive standardized candidate sets
- operational visibility is complete

The architecture described in this specification establishes the standardized opportunity discovery framework for every investment strategy within the StoX Platform.

---

# 2. Discovery Philosophy

## Overview

The Discovery Philosophy establishes the principles governing how investment opportunities are identified before strategy evaluation begins.

Discovery exists to reduce the investment universe through objective business criteria without making investment decisions.

Discovery identifies possibilities.

Strategies determine suitability.

---

# Discovery as a Business Capability

Discovery represents a business capability responsible for locating securities that satisfy predefined screening criteria.

Discovery responsibilities include:

- defining investment universes
- applying screeners
- filtering securities
- ranking candidates
- producing candidate sets

Discovery does not determine whether a security should be purchased.

---

# Separation of Responsibilities

Discovery responsibilities are divided across architectural layers.

Discovery

Responsible for:

- finding candidates
- applying screeners
- reducing investment universe

Strategy Engine

Responsible for:

- evaluating candidates
- applying investment methodology
- determining business outcomes

Recommendation Engine

Responsible for:

- converting business outcomes into Recommendations

Each layer contributes one business responsibility.

---

# Deterministic Discovery

Discovery shall remain deterministic.

Given identical:

- market information
- screening rules
- configuration
- investment universe

Discovery shall always produce identical candidate sets.

Randomness shall not influence discovery unless explicitly modelled.

---

# Reusability

Discovery capabilities should be reusable across:

- strategies
- portfolios
- watchlists
- market segments
- execution modes

Screening logic should be defined once and reused wherever practical.

---

# Explainability

Discovery results should remain explainable.

Operators should understand:

- why a security qualified
- why a security was rejected
- which screening rules matched
- resulting candidate ranking

Discovery shall remain transparent.

---

# Technology Independence

The Discovery architecture defines business concepts.

It does not depend upon:

- database technology
- search engine
- programming language
- execution platform
- analytics engine

Technology remains an implementation decision.

---

# Design Principles

The Discovery Philosophy shall:

- remain deterministic
- remain explainable
- remain reusable
- preserve business separation
- remain technology-independent
- support complete traceability

Discovery identifies opportunities.

Strategies evaluate investment merit.

---

# Summary

The Discovery Philosophy establishes a deterministic, reusable and technology-independent foundation for identifying investment opportunities within the StoX Platform.

By separating candidate identification from strategy evaluation while preserving transparency and complete traceability, the Discovery architecture provides a consistent foundation for every investment methodology implemented within the platform.

---

# 3. Discovery Architecture

## Overview

The Discovery Architecture defines the structural organization of the Discovery Engine and its interactions with surrounding platform capabilities.

Every discovery process follows the same architectural model regardless of investment universe or screening methodology.

---

# Architectural Position

The Discovery Engine occupies the opportunity identification layer of the platform.

The conceptual architecture is:

```text
Market Data
        │
        ▼
Investment Universe
        │
        ▼
Discovery Engine
        │
        ▼
Candidate Set
        │
        ▼
Strategy Engine
```

Discovery transforms the investment universe into standardized candidate sets.

---

# Architectural Components

The Discovery architecture consists of the following platform building blocks.

| Platform Building Block | Discovery Component |
| ----------------------- | ------------------- |
| Configuration           | Discovery Policies  |
| Configuration           | Screening Rules     |
| Registry                | Discovery Registry  |
| Business Engine         | Discovery Engine    |
| Run                     | Discovery Run       |
| Artifact                | Candidate Set       |
| Artifact                | Discovery Result    |
| Event                   | Discovery Events    |
| Operational Control     | Discovery Controls  |

Each component owns one clearly defined business responsibility.

# Discovery Engine

The Discovery Engine is responsible for:

- identifying the investment universe
- applying screening rules
- filtering securities
- ranking candidates
- producing Candidate Sets
- publishing discovery events

The Discovery Engine identifies opportunities.

It does not determine investment decisions.

---

# Discovery Registry

The Discovery Registry maintains the operational metadata associated with Discovery.

Responsibilities include:

- registered universes
- available screeners
- discovery policies
- screening configurations
- operational availability

The Registry provides the authoritative inventory of discovery capabilities.

---

# Discovery Run

Every discovery process produces a Discovery Run.

A Discovery Run records:

- discovery identifier
- execution timestamp
- investment universe
- applied screening rules
- execution duration
- discovery outcome

Discovery Runs support traceability and operational analysis.

---

# Discovery Artifacts

Discovery produces standardized business artifacts.

Examples include:

Candidate Set

Represents securities that passed discovery.

Discovery Result

Represents the complete discovery outcome.

Screening Summary

Represents discovery statistics and operational metadata.

Artifacts preserve the business history of discovery.

---

# Discovery Events

The Discovery architecture publishes standardized business events.

Examples include:

- Discovery Started
- Discovery Completed
- Discovery Failed
- Candidate Identified
- Candidate Rejected
- Screening Completed

Events support downstream processing and operational visibility.

---

# Discovery Controls

Operators may influence discovery behaviour through standardized Operational Controls.

Examples include:

- Start Discovery
- Pause Discovery
- Resume Discovery
- Cancel Discovery
- Rebuild Universe

Operational Controls affect discovery execution.

They do not modify screening logic.

---

# Discovery Flow

The conceptual discovery architecture is:

```text
Investment Universe
        │
        ▼
Discovery Engine
        │
        ▼
Screening Rules
        │
        ▼
Candidate Ranking
        │
        ▼
Candidate Set
```

Every discovery process follows the same architectural flow.

---

# Architectural Principles

The Discovery Architecture shall:

- remain deterministic
- preserve screening independence
- support complete traceability
- remain modular
- remain technology-independent
- support operational scalability

Discovery architecture governs opportunity identification.

Strategy architecture governs investment evaluation.

---

# Summary

The Discovery Architecture provides the standardized structural framework for identifying investment opportunities throughout the StoX Platform.

By organizing discovery into reusable architectural components while separating opportunity identification from investment evaluation, the platform enables scalable, transparent and maintainable candidate discovery.

---

# 4. Discovery Universe

## Overview

The Discovery Universe defines the complete collection of securities eligible for evaluation by the Discovery Engine.

Every discovery process begins by establishing an explicit investment universe before screening rules are applied.

The investment universe defines discovery boundaries.

Screening narrows that universe.

---

# Purpose

The Discovery Universe exists to:

- define discovery scope
- standardize candidate selection
- simplify screening
- preserve consistency
- support governance
- improve operational transparency

Every discovery process shall operate against a defined investment universe.

---

# Universe Definition

The investment universe represents the population of securities considered during discovery.

Typical universes include:

- NSE Equity
- BSE Equity
- NIFTY 50
- NIFTY 100
- NIFTY 500
- Sector Indices
- ETFs
- Mutual Funds
- Custom Watchlists

The architecture remains independent of any specific market.

---

# Universe Sources

Investment universes may originate from:

- exchange listings
- index constituents
- portfolio holdings
- watchlists
- custom business definitions
- dynamically generated universes

Universe generation shall remain independent of screening.

---

# Universe Characteristics

Every investment universe should define:

- universe identifier
- business purpose
- eligible instruments
- market coverage
- update frequency
- ownership

Universe definitions shall remain governed and traceable.

---

# Universe Governance

Investment universes shall remain governed throughout their lifecycle.

Governance typically includes:

- ownership
- approval
- version history
- update policy
- retirement

Universe changes shall remain auditable.

---

# Universe Consistency

During a Discovery Run, the investment universe shall remain logically consistent.

Once discovery begins:

- eligible securities shall remain fixed
- universe definition shall remain immutable
- configuration shall remain unchanged

Consistent universes support deterministic discovery.

---

# Universe Traceability

Every Discovery Run shall record:

- universe identifier
- universe version
- security count
- execution timestamp
- governing policy

Universe history supports reproducibility and operational analysis.

---

# Design Principles

The Discovery Universe shall:

- remain explicitly defined
- remain deterministic
- preserve governance
- support traceability
- remain technology-independent
- support reproducible discovery

The investment universe defines where discovery operates.

Screening determines what survives.

---

# Summary

The Discovery Universe provides the standardized boundary within which opportunity discovery operates throughout the StoX Platform.

By explicitly defining eligible securities, preserving governance and maintaining deterministic execution boundaries, the platform enables consistent and reproducible candidate discovery.

---

# 5. Screening Pipeline

## Overview

The Screening Pipeline defines the standardized sequence of business filters used to progressively reduce the investment universe into a Candidate Set suitable for strategy evaluation.

Each stage removes securities that no longer satisfy discovery requirements.

The pipeline reduces complexity.

Strategies perform business evaluation after discovery completes.

---

# Purpose

The Screening Pipeline exists to:

- standardize screening
- simplify candidate selection
- improve screening reuse
- support modular filtering
- preserve deterministic behaviour
- improve operational visibility

Each screening stage performs one business responsibility.

---

# Pipeline Architecture

The conceptual screening pipeline is:

```text
Investment Universe
        │
        ▼
Eligibility Filters
        │
        ▼
Business Filters
        │
        ▼
Technical Filters
        │
        ▼
Ranking
        │
        ▼
Candidate Set
```

Each stage progressively reduces the number of eligible securities.

---

# Eligibility Filters

Eligibility Filters remove securities that should not participate in discovery.

Typical filters include:

- supported exchange
- supported instrument type
- trading status
- minimum listing age
- data availability

Eligibility establishes the initial candidate population.

---

# Business Filters

Business Filters apply business-oriented discovery criteria.

Examples include:

- market capitalization
- sector
- industry
- average trading volume
- liquidity
- corporate actions

Business Filters identify securities suitable for further evaluation.

---

# Technical Filters

Technical Filters evaluate market behaviour.

Examples include:

- moving averages
- price trends
- volatility
- relative strength
- breakout conditions
- momentum indicators

Technical Filters prepare candidates for strategy evaluation.

---

# Candidate Ranking

After filtering, candidates may be ranked.

Typical ranking criteria include:

- composite score
- relative strength
- momentum score
- liquidity score
- volatility score
- custom ranking models

Ranking determines candidate priority.

It does not determine investment suitability.

---

# Pipeline Independence

Each pipeline stage shall remain independent.

A stage should:

- receive standardized input
- perform one responsibility
- produce standardized output
- avoid modifying previous stages

Pipeline stages should remain reusable across multiple strategies.

---

# Pipeline Configuration

Pipeline behaviour should remain configurable.

Typical configuration includes:

- enabled filters
- filter sequence
- ranking policies
- minimum thresholds
- candidate limits

Configuration should modify pipeline behaviour without changing architecture.

---

# Pipeline Traceability

Every Discovery Run should record:

- pipeline stages executed
- applied filters
- rejected candidate counts
- accepted candidate counts
- ranking information

Pipeline history supports operational analysis and reproducibility.

---

# Design Principles

The Screening Pipeline shall:

- remain deterministic
- remain modular
- support reuse
- preserve traceability
- remain technology-independent
- support extensibility

The pipeline identifies candidate opportunities.

Strategies determine investment decisions.

---

# Summary

The Screening Pipeline provides a modular and deterministic framework for progressively reducing the investment universe into a standardized Candidate Set.

By separating eligibility, business filtering, technical filtering and ranking while preserving complete traceability, the platform enables reusable and maintainable discovery processes.

---

# 6. Candidate Evaluation

## Overview

Candidate Evaluation defines the standardized assessment performed after screening has identified eligible securities.

Its purpose is to organize, enrich and prepare candidates for consumption by the Strategy Engine.

Candidate Evaluation prepares opportunities.

Investment strategies determine business decisions.

---

# Purpose

Candidate Evaluation exists to:

- standardize candidate representation
- enrich candidate information
- simplify downstream evaluation
- preserve consistency
- support traceability
- enable deterministic processing

Candidate Evaluation shall not perform investment strategy evaluation.

---

# Candidate Model

Every candidate should contain standardized business information.

Typical information includes:

- security identifier
- exchange
- instrument type
- discovery source
- ranking score
- screening results
- evaluation timestamp

The Candidate Model shall remain independent of strategy implementation.

---

# Candidate Enrichment

Discovery may enrich candidates using additional business information.

Examples include:

- sector classification
- industry classification
- market capitalization
- liquidity metrics
- volatility measures
- trend indicators

Enrichment provides additional context for downstream strategy evaluation.

---

# Candidate Qualification

A candidate becomes eligible for Strategy Evaluation only after:

- passing all mandatory screening stages
- satisfying discovery policies
- receiving a standardized Candidate Model
- completing enrichment

Qualification completes the Discovery process.

Strategy evaluation begins afterward.

# Candidate Scoring

Candidate Evaluation may calculate standardized scores that assist downstream processing.

Examples include:

- discovery score
- momentum score
- trend score
- liquidity score
- quality score
- composite score

Scores provide relative ordering.

They do not represent investment recommendations.

---

# Candidate Validation

Before a candidate is published, validation should verify:

- required attributes present
- screening completed
- enrichment completed
- ranking available
- discovery policies satisfied

Only validated candidates shall be included in the Candidate Set.

---

# Candidate Traceability

Every candidate shall preserve:

- candidate identifier
- originating Discovery Run
- universe identifier
- applied screening rules
- ranking information
- evaluation timestamp

Candidate history supports reproducibility and operational analysis.

---

# Design Principles

Candidate Evaluation shall:

- remain deterministic
- remain standardized
- preserve candidate consistency
- support downstream processing
- remain technology-independent
- support complete traceability

Candidate Evaluation prepares opportunities.

Strategies determine business outcomes.

---

# Summary

Candidate Evaluation provides a standardized representation of discovery results before they are consumed by the Strategy Engine.

By enriching, validating and standardizing candidate information while preserving deterministic behaviour and complete traceability, the platform enables consistent downstream strategy evaluation.

---

# 7. Discovery Outputs

## Overview

Discovery Outputs define the standardized business artifacts produced by the Discovery Engine after successful completion of the discovery process.

These outputs become the primary inputs consumed by downstream Strategy Evaluation.

Discovery produces opportunities.

It does not produce investment decisions.

---

# Purpose

Discovery Outputs exist to:

- standardize downstream integration
- simplify Strategy Evaluation
- preserve business consistency
- support operational traceability
- enable reusable discovery
- support auditing

Every Discovery Run shall produce standardized outputs.

---

# Output Model

The conceptual output model is:

```text
Discovery Engine
        │
        ▼
Candidate Set
        │
        ▼
Discovery Metadata
        │
        ▼
Discovery Result
        │
        ▼
Strategy Engine
```

Discovery Outputs provide a complete representation of the discovery process.

---

# Candidate Set

The Candidate Set represents the primary output of Discovery.

Typical contents include:

- qualified securities
- candidate identifiers
- ranking information
- screening summaries
- discovery metadata

The Candidate Set becomes the input for Strategy Evaluation.

---

# Discovery Metadata

Discovery Metadata describes the operational characteristics of a Discovery Run.

Typical metadata includes:

- discovery identifier
- execution timestamp
- universe identifier
- screening policy
- execution duration
- candidate count

Metadata supports operational reporting and auditing.

---

# Discovery Result

The Discovery Result represents the complete outcome of a Discovery Run.

Typical information includes:

- Candidate Set
- Discovery Metadata
- execution outcome
- operational summary
- generated events

The Discovery Result provides a standardized business artifact for downstream processing.

---

# Output Consumers

Discovery Outputs may be consumed by:

- Strategy Engine
- Monitoring & Observability
- Audit
- Reporting
- Analytics

Discovery remains the authoritative producer of Candidate Sets.

---

# Output Consistency

Every Discovery Output shall remain internally consistent.

The published output shall represent:

- one investment universe
- one Discovery Run
- one screening configuration
- one execution context

Outputs shall remain immutable after publication.

---

# Output Traceability

Every Discovery Output shall preserve:

- discovery identifier
- Candidate Set identifier
- universe version
- screening configuration
- publication timestamp

Output history supports reproducibility and operational governance.

---

# Design Principles

Discovery Outputs shall:

- remain standardized
- preserve consistency
- support downstream integration
- remain immutable
- remain technology-independent
- support complete traceability

Discovery Outputs represent opportunity identification.

They do not represent investment recommendations.

---

# Summary

Discovery Outputs provide standardized business artifacts describing the complete outcome of every Discovery Run.

By publishing immutable Candidate Sets together with standardized metadata while preserving complete traceability, the Discovery architecture enables reliable downstream strategy evaluation and operational governance.

---

# 8. Platform Relationships

## Overview

The Discovery and Screening architecture collaborates with surrounding platform capabilities through clearly defined architectural boundaries.

Discovery identifies opportunities.

Other platform capabilities consume or provide supporting information.

---

# Purpose

Platform Relationships exist to:

- define architectural boundaries
- minimize subsystem coupling
- clarify business responsibilities
- support independent evolution
- preserve modularity
- enable standardized integration

Each platform capability owns one primary business responsibility.

---

# Upstream Relationships

Discovery consumes business information from upstream platform capabilities.

Primary upstream relationships include:

Market Data

Provides market information.

Configuration

Provides screening policies.

Registry

Provides discovery metadata.

Reference Data

Provides instrument classifications and supporting business information.

Discovery consumes information.

It does not own upstream business data.

# Downstream Relationships

Discovery produces standardized business outputs for downstream platform capabilities.

Primary downstream relationships include:

Strategy Engine

Consumes Candidate Sets for strategy evaluation.

Recommendation Engine

Consumes outputs indirectly through the Strategy Engine.

Monitoring & Observability

Monitors Discovery execution.

Audit

Preserves Discovery history.

Analytics

Consumes Discovery metadata.

Reporting

Produces operational and business reports.

Discovery produces opportunities.

Downstream systems determine investment suitability.

---

# Relationship Boundaries

The Discovery architecture shall not directly perform responsibilities owned by other platform capabilities.

Examples include:

It shall not:

- evaluate investment strategies
- generate Recommendations
- calculate portfolio allocations
- execute Orders
- communicate with brokers
- evaluate execution risk

These responsibilities remain within their respective architectural domains.

---

# Business Information Flow

The conceptual information flow is:

```text
Market Data
        │
        ▼
Investment Universe
        │
        ▼
Discovery
        │
        ▼
Candidate Set
        │
        ▼
Strategy Engine
        │
        ▼
Recommendation Engine
```

Each platform capability contributes one business responsibility.

---

# Operational Relationships

Operationally, Discovery collaborates with:

- Monitoring & Observability
- Operational Playbooks
- Audit
- Configuration Management
- Security

These relationships support governance and operational management rather than business evaluation.

---

# Event Relationships

The Discovery architecture publishes standardized business events.

Examples include:

- Discovery Started
- Discovery Completed
- Discovery Failed
- Universe Loaded
- Candidate Qualified
- Candidate Rejected
- Candidate Set Published

Events promote loose coupling between platform capabilities.

---

# Dependency Principles

Platform dependencies shall remain:

- explicit
- minimal
- directional
- deterministic
- technology-independent

Discovery shall depend only upon published platform contracts.

---

# Design Principles

Platform Relationships shall:

- preserve architectural boundaries
- minimize coupling
- support deterministic information flow
- support independent evolution
- remain technology-independent
- preserve single responsibility

Discovery collaborates with surrounding platform capabilities without assuming their responsibilities.

---

# Summary

The Platform Relationships define how the Discovery architecture integrates with surrounding platform capabilities while preserving clear architectural boundaries and business ownership.

By consuming market information and producing standardized Candidate Sets for downstream strategy evaluation while remaining independent of investment methodology and trade execution, the Discovery architecture serves as the opportunity identification layer of the StoX Platform.

---

# 9. Extension Model

## Overview

The Discovery architecture is designed to evolve through disciplined extension rather than architectural redesign.

Future discovery capabilities should extend existing concepts while preserving deterministic screening, standardized Candidate Sets and architectural separation.

The objective is to continuously improve opportunity discovery without increasing unnecessary architectural complexity.

---

# Extension Philosophy

The Discovery architecture should evolve using the following order of preference.

```text
Reuse Existing Screening Rule

↓

Extend Existing Pipeline Stage

↓

Extend Discovery Components

↓

Extend Candidate Model

↓

Introduce New Architectural Component (Exceptional)
```

Existing architectural abstractions should always be reused wherever practical.

---

# Extending Investment Universes

Future platform versions may introduce additional investment universes.

Examples include:

- international exchanges
- derivatives
- commodities
- cryptocurrencies
- bonds
- alternative assets

New universes shall integrate into the standardized Discovery architecture.

---

# Extending Screening

Future screening capabilities may include:

- multi-stage screening
- adaptive screening
- AI-assisted screening
- statistical filtering
- probabilistic ranking

Screening enhancements shall preserve deterministic Candidate Set production wherever applicable.

---

# Extending Candidate Evaluation

Future candidate capabilities may include:

- additional scoring models
- market regime indicators
- sentiment indicators
- macroeconomic context
- AI-generated insights

Candidate enhancements shall remain independent of investment strategy.

# Extending Operational Capabilities

Future operational capabilities may include:

- distributed discovery
- incremental discovery
- scheduled universe refresh
- discovery orchestration
- intelligent caching

Operational enhancements shall preserve deterministic discovery behaviour.

---

# AI-Assisted Discovery

Future AI capabilities may assist Discovery by providing:

- candidate ranking recommendations
- anomaly detection
- screening optimization
- adaptive universe suggestions
- market opportunity identification

AI may assist Discovery.

Final candidate qualification remains governed by Discovery policies.

---

# Backward Compatibility

Discovery evolution should preserve compatibility wherever practical.

Existing:

- investment universes
- screening rules
- Candidate Sets
- Discovery Results
- Discovery Events

should remain valid after architectural enhancements.

Where incompatible changes are required, migration guidance shall be provided.

---

# Architectural Review

Every significant Discovery enhancement should be reviewed to ensure that it:

- preserves deterministic discovery
- supports screening independence
- preserves architectural boundaries
- remains technology-independent
- supports operational scalability
- aligns with Platform Architecture principles

New discovery concepts should be introduced only when existing abstractions cannot reasonably support the required capability.

---

# Design Principles

Discovery extensions shall:

- remain deterministic
- preserve business separation
- support complete traceability
- favour extension over redesign
- remain technology-independent
- support operational scalability

Discovery architecture should evolve without changing the responsibilities of downstream strategy evaluation.

---

# Summary

The Discovery architecture is designed to evolve through disciplined extension while preserving standardized opportunity identification, reusable screening and deterministic Candidate Set generation.

By extending discovery capabilities without altering the underlying architectural principles, the StoX Platform enables continuous innovation while maintaining consistency, transparency and long-term maintainability.

---

# Appendix A — Canonical Discovery Flows

## Overview

This appendix illustrates the canonical discovery patterns followed by every Discovery Run within the StoX Platform.

These flows demonstrate how investment opportunities are identified, filtered and prepared for Strategy Evaluation while preserving deterministic execution and complete traceability.

Future discovery implementations should follow these architectural patterns wherever practical.

---

# Flow 1 — Standard Discovery

```text
Investment Universe
        │
        ▼
Discovery Engine
        │
        ▼
Screening Pipeline
        │
        ▼
Candidate Ranking
        │
        ▼
Candidate Set
```

Outcome:

- Universe evaluated
- Candidates identified
- Candidate Set produced

---

# Flow 2 — Screening Pipeline

```text
Eligibility
        │
        ▼
Business Filters
        │
        ▼
Technical Filters
        │
        ▼
Ranking
        │
        ▼
Qualified Candidates
```

Outcome:

- Progressive filtering
- Deterministic screening
- Standardized qualification

---

# Flow 3 — Candidate Preparation

```text
Qualified Candidates
        │
        ▼
Enrichment
        │
        ▼
Validation
        │
        ▼
Scoring
        │
        ▼
Candidate Set
```

Outcome:

- Candidate information standardized
- Business context enriched
- Strategy-ready Candidate Set produced

---

# Flow 4 — Platform Integration

```text
Market Data
        │
        ▼
Discovery
        │
        ▼
Strategy Engine
        │
        ▼
Recommendation Engine
```

Outcome:

- Opportunities identified
- Business evaluation enabled
- Architectural boundaries preserved

---

# Canonical Discovery Architecture

```text
Investment Universe
        │
        ▼
Discovery Engine
        │
        ▼
Screening Pipeline
        │
        ▼
Candidate Evaluation
        │
        ▼
Candidate Set
```

Discovery transforms a broad investment universe into standardized candidate opportunities.

---

# Discovery Governance Model

```text
Universe Definition
        │
        ▼
Discovery Policies
        │
        ▼
Screening Rules
        │
        ▼
Candidate Qualification
        │
        ▼
Candidate Publication
```

Every discovery process follows governed and deterministic business rules.

---

# Summary

The canonical discovery flows demonstrate how the StoX Platform identifies investment opportunities through standardized universes, deterministic screening pipelines and reusable Candidate Sets.

By separating opportunity discovery from strategy evaluation while preserving complete traceability and architectural independence, the Discovery architecture provides a scalable and maintainable foundation for all investment strategies.
