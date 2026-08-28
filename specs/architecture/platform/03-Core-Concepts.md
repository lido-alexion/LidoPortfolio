# 03 -- Core Concepts

**Document:** 03 -- Core Concepts  
**Version:** 0.2  
**Status:** Draft (updated 2026-07-30: Trading Artifact / Screener / Indicator Registry vocabulary)  
**Owner:** Architecture  
**Depends On:** 01 -- Vision, 02 -- Guiding Principles  

------------------------------------------------------------------------

# 1. Purpose

This document establishes the canonical business vocabulary for the
Trading Operating System.

Every specification, design discussion, implementation and test case
shall use the terminology defined here. New concepts should be
introduced only by extending this document.

------------------------------------------------------------------------

# 2. Modelling Principles

-   Every concept has exactly one owner.
-   Every concept has one clear responsibility.
-   Every concept has a lifecycle.
-   Every concept must be created by exactly one engine.
-   Concepts are independent of implementation technology.

------------------------------------------------------------------------

# 3. Core Concepts

## Trading Artifact

**Definition**

A reusable, versioned, validated trading definition with shared metadata,
lifecycle, dependencies, and optional import/export packaging. First-class
types: **Indicator**, **Screener**, **Strategy**.

**Purpose**

Provide one AI-friendly framework for discovery, registry management,
validation, and future sharing — without redesigning type-specific cores
(`definition_json`, `config_json`, Indicator Registry bodies).

**Owner**

Architecture / shared platform (Trading Artifact Framework — SD-034).

See [../indicators/11-Trading-Artifact-Framework.md](../indicators/11-Trading-Artifact-Framework.md).

------------------------------------------------------------------------

## Strategy

**Definition**

An investment philosophy encoded as a **Strategy artifact**: scoring,
portfolio rules, capital allocation, exits, thresholds, and eligibility
**sources** (Screener references). At runtime a portfolio **binds** to one
active Strategy artifact version.

Historically described as an isolated methodology with holdings and
performance; holdings/transactions remain portfolio ledger concepts — the
reusable definition is the Strategy artifact (`config_json`).

**Purpose**

Allow investment approaches to be configured, reused, forked (including
former “Strategy Templates”), compared, and explained objectively.

**Owner**

Strategy Configuration / Strategy Artifact Registry (under Trading Artifact
Framework).

------------------------------------------------------------------------

## Screener

**Definition**

An eligibility **Screener artifact**: a deterministic condition tree
(`definition_json`) that selects stocks. Does not rank, allocate, or
recommend.

**Purpose**

Reusable candidate selection for Discovery, Strategies, Watchlists, Alerts,
and future automation — sole eligibility engine (SD-030).

**Owner**

Screener module / Screener Artifact Registry (under Trading Artifact
Framework).

------------------------------------------------------------------------

## Universe

**Definition**

The complete collection of securities eligible for analysis.

**Owner**

Data Engine.

------------------------------------------------------------------------

## Stock

A tradable security identified by a unique exchange symbol.

Owner: Data Engine.

------------------------------------------------------------------------

## Market Data

Historical and current OHLCV information together with imported static
metadata.

Owner: Data Engine.

------------------------------------------------------------------------

## Indicator

A deterministic numeric value or documented descriptive measure derived from
market data or from other indicators. Indicators are typed as:

- **Primary** — calculated directly from market data (or a dedicated service)
- **Composite** — calculated from declared dependencies (other indicators)
- **Metric** — descriptive analytics (discoverable; not always screenable/scorable)

Examples (Primary): EMA, SMA, ATR, RSI, Relative Strength (raw).  
Examples (Composite): Momentum Score, Trend Score, Liquidity Score.  
Examples (Metric): Distance from 52-week High, Beta, Historical Volatility.

**Metadata / discovery owner (target):** Indicator Registry (SD-033).  
**Calculation owners (unchanged):** `TechnicalIndicatorService` / related
services (Primaries); Evaluation Engine (stock Strategy Composites); Market
Analysis Engine (market-level Composites); Analytics services (Metrics).

See [../indicators/09-Indicator-Registry.md](../indicators/09-Indicator-Registry.md) and
[../domains/Indicator-Registry-Specification.md](../domains/Indicator-Registry-Specification.md).

------------------------------------------------------------------------

## Indicator Registry

The single source of truth for indicator **metadata and discovery** (identity,
type, category, dependencies, parameters, capabilities, consumers, version).
It does not replace calculation engines. Entries are release-shipped (SD-028).

**Framework role:** Indicator specialization of the Trading Artifact Framework
(SD-034). Cross-cutting package/dependency/AI catalogue concerns are shared;
indicator calculation non-goals remain as in SD-033.

Owner: Architecture / shared platform module (Indicator Registry).

------------------------------------------------------------------------

## Artifact Registry

Umbrella registry API over Indicator, Screener, and Strategy artifact
registries: list/search, validate, import/export, dependency resolution.

Owner: Architecture / Trading Artifact Framework (SD-034). Umbrella
`ArtifactRegistry` (list/validate/import/export) **shipped**. Remaining TAF
(immutable published versions, sharing, extra AI draft UX) is **V5 V4-FEAT-008**.

------------------------------------------------------------------------

## Pattern

A deterministic chart formation identified from indicators and price
action.

Examples:

-   VCP
-   Cup & Handle
-   Double Bottom

Owner: Discovery Engine.

------------------------------------------------------------------------

## Signal

A single business rule becoming true.

Examples:

-   Price crossed EMA.
-   Breakout confirmed.
-   Volume expansion detected.

Signals are facts, not decisions.

Owner: Discovery Engine.

------------------------------------------------------------------------

## Rule

A deterministic condition evaluated against market data.

Rules are reusable building blocks for screening, ranking and
recommendations.

Owner: Evaluation Engine.

------------------------------------------------------------------------

## Candidate

A stock that satisfies the minimum entry criteria for a strategy.

Candidates represent opportunities awaiting evaluation.

Owner: Discovery Engine.

------------------------------------------------------------------------

## Ranking

A relative ordering of candidates based on deterministic scoring.

Ranking compares opportunities but does not recommend action.

Owner: Evaluation Engine.

------------------------------------------------------------------------

## Evidence

The complete collection of facts supporting a recommendation.

Evidence may include:

-   Rules passed
-   Rules failed
-   Indicators
-   Patterns
-   Signals
-   Risk metrics

Owner: Recommendation Engine.

------------------------------------------------------------------------

## Recommendation

An actionable decision produced after evaluating all available evidence.

Types include:

-   BUY
-   SELL
-   WATCH
-   HOLD

Every recommendation must be explainable.

Owner: Recommendation Engine.

------------------------------------------------------------------------

## Position

An active investment currently held by a strategy.

Owner: Execution Engine.

------------------------------------------------------------------------

## Transaction

A historical buy or sell event.

Transactions are immutable once recorded.

Owner: Execution Engine.

------------------------------------------------------------------------

## Alert

A notable event requiring attention.

Alerts are facts.

Owner: Notification Engine.

------------------------------------------------------------------------

## Notification

A delivery of information through one or more communication channels.

Telegram is the primary notification channel.

Notifications communicate events; they do not make decisions.

Owner: Notification Engine.

------------------------------------------------------------------------

## Execution

The act of placing an order with a broker.

Execution may be manual or automated depending on system maturity.

Owner: Execution Engine.

------------------------------------------------------------------------

## Review

A retrospective analysis of decisions, trades and strategy performance.

Owner: Review Engine.

------------------------------------------------------------------------

# 4. Concept Relationships

Market Data → Indicators → Patterns → Signals → Candidates → Rankings →
Evidence → Recommendations → Notifications / Executions → Reviews

------------------------------------------------------------------------

# 5. Ownership Matrix

  Concept           Owner
  ----------------- -----------------------
  Market Data       Data Engine
  Trading Artifacts Artifact Registry (umbrella; SD-034)
  Indicators        Indicator Registry (meta) / Evaluation & TI (calc)
  Screeners         Screener module
  Strategies        Strategy Configuration
  Patterns          Discovery Engine
  Signals           Discovery Engine
  Rules             Evaluation Engine
  Candidates        Discovery Engine
  Rankings          Evaluation Engine
  Evidence          Recommendation Engine
  Recommendations   Recommendation Engine
  Notifications     Notification Engine
  Executions        Execution Engine
  Reviews           Review Engine

------------------------------------------------------------------------

# 6. Summary

This document defines the ubiquitous language of the Trading Operating
System. Future specifications shall use these definitions consistently.
Business concepts must not be redefined elsewhere.
