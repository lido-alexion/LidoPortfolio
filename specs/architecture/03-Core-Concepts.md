# 03 -- Core Concepts

  Field            Value
  ---------------- ----------------------------------------
  **Document**     03 -- Core Concepts
  **Version**      0.1
  **Status**       Draft
  **Owner**        Architecture
  **Depends On**   01 -- Vision, 02 -- Guiding Principles

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

## Strategy

**Definition**

An isolated trading methodology with its own configuration, holdings,
transactions, watchlists, rules, recommendations and performance.

**Purpose**

Allow multiple investment approaches to operate independently and be
compared objectively.

**Owner**

Strategy Management.

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

A deterministic numeric value derived from market data.

Examples:

-   EMA
-   SMA
-   ATR
-   Relative Strength
-   RSI

Owner: Evaluation Engine.

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
  Indicators        Evaluation Engine
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
