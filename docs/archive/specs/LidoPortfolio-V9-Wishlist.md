# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |

## 1. Purpose

V9 currently contains later StoX product expansion that follows the V7 analytical-data work and V8 standalone Telemetry Platform.

The Telemetry separation remains deliberate: V8 builds the reusable Telemetry product; V9 changes StoX to become a properly instrumented producer/consumer of that product.

## 2. Current V9 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-017 | AI Assistant | Introduce an AI-assisted StoX experience after earlier platform, safety, analytical-data and ML foundations are established. Architecture, tool/data access, explainability, interaction model and decision-authority boundaries require dedicated V9 deliberation. AI must not silently override deterministic Strategy behavior or bypass artifact/versioning and execution safeguards. | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto Expansion | Expand StoX beyond its current equity-centric instrument model. ETF, Options and Crypto have materially different market-data, pricing, lifecycle, accounting, settlement, risk and execution needs and may be decomposed into separate V9 epics during planning. | OPEN |
| TBD | StoX Telemetry Platform Integration | Integrate StoX with the standalone Telemetry Platform after the V8 platform is available. Scope includes StoX-side SDK/API integration, product/environment credentials, identity/session/context mapping, operational telemetry, product-usage events, correlation propagation, privacy-safe metadata, delivery/failure isolation, and any StoX-facing analytics/query/deep-link integration deliberately selected during V9 planning. | OPEN |

## 3. Inherited boundary

The Telemetry Platform remains independently deployable and product-independent. StoX must not absorb Telemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

AI/ML additions must preserve inherited deterministic, explainable Strategy behavior unless a later explicit product decision supersedes it. Instrument expansion must preserve existing equity behavior non-breakingly unless an instrument-specific specification explicitly changes a shared abstraction.

Detailed V9 behavior will be frozen during V9 planning against the completed V7/V8 foundations and the then-current StoX implementation.
