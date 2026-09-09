# LidoPortfolio / StoX V9 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V9 planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V9-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V8-Wishlist.md` |

## 1. Purpose

V9 currently reserves StoX-side integration with the standalone Telemetry Platform built in V8.

The separation is deliberate: V8 builds the reusable Telemetry product; V9 changes StoX to become a properly instrumented producer/consumer of that product.

## 2. Current V9 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| TBD | StoX Telemetry Platform Integration | Integrate StoX with the standalone Telemetry Platform after the V8 platform is available. Scope includes StoX-side SDK/API integration, product/environment credentials, identity/session/context mapping, operational telemetry, product-usage events, correlation propagation, privacy-safe metadata, delivery/failure isolation, and any StoX-facing analytics/query/deep-link integration deliberately selected during V9 planning. | OPEN |

## 3. Inherited boundary

The Telemetry Platform remains independently deployable and product-independent. StoX must not absorb Telemetry storage, analytics, dashboards or platform administration into its own codebase.

Telemetry failure must not block StoX business workflows. StoX audit/business evidence remains distinct from telemetry.

Detailed StoX integration behavior will be frozen during V9 planning against the completed V8 platform contract and the then-current StoX implementation.
