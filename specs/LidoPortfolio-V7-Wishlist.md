# LidoPortfolio / StoX V7 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V7 product wishlist and planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V7-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V6-Wishlist.md` |

## 1. Purpose

This register records work explicitly moved beyond V6. V7 planning must preserve all frozen V1–V6 behavior unless a later V7 specification explicitly supersedes it.

Items may be refined, decomposed, or reprioritized during V7 planning. `DECIDED` means architecture/product behavior is sufficiently frozen for implementation; it does not imply implementation exists.

## 2. Current V7 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-017 | AI Assistant | Deferred until after V6 safety/UX/platform consolidation. Architecture and decision-authority boundaries require dedicated V7 deliberation. | OPEN |
| V4-FEAT-018 | ML scoring models | Deferred with AI/decision-authority work. Deterministic Strategy semantics remain authoritative until explicitly superseded. | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto expansion | Deferred instrument expansion; likely to be decomposed by instrument family during V7 planning. | OPEN |
| V4-FEAT-052 | Standalone Telemetry Platform | Separate, product-independent telemetry/analytics application. StoX is the first client, not the host product. Core architecture is captured in `V7-Telemetry-Platform.md`. | CORE ARCHITECTURE DECIDED |

## 3. Telemetry positioning

`V4-FEAT-052` is not a StoX-internal feature. The V6 deliberation established that it should be:

- a separate repository/codebase;
- independently deployable;
- reusable by multiple products;
- product-independent at its core;
- exposed through native ingestion/query/management APIs and its own analytics UI;
- lightweight and functional first, with enterprise-level tenancy/federation/governance features deferred.

Canonical detailed specification: [V7-Telemetry-Platform.md](V7-Telemetry-Platform.md).

## 4. Planning rule

V7 work must not be treated as unfinished V6 scope. V6 implementation/closure can proceed independently while V7 items remain in planning or are implemented later.
