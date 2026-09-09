# LidoPortfolio / StoX V7 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V7 product wishlist and planning register |
| **Created** | 2026-09-09 |
| **Status** | EARLY PLANNING |
| **Canonical path** | `specs/LidoPortfolio-V7-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V6-Wishlist.md` |

## 1. Purpose

This register records StoX work explicitly moved beyond V6. V7 planning must preserve all frozen V1–V6 behavior unless a later V7 specification explicitly supersedes it.

Items may be refined, decomposed, or reprioritized during V7 planning. `DECIDED` means architecture/product behavior is sufficiently frozen for implementation; it does not imply implementation exists.

The standalone Telemetry Platform previously listed here has been moved to V8 because it is a separate application/product rather than a StoX-internal feature. StoX integration with that platform is planned separately for V9.

## 2. Current V7 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-017 | AI Assistant | Deferred until after V6 safety/UX/platform consolidation. Architecture and decision-authority boundaries require dedicated V7 deliberation. | OPEN |
| V4-FEAT-018 | ML scoring models | Deferred with AI/decision-authority work. Deterministic Strategy semantics remain authoritative until explicitly superseded. | OPEN |
| V4-FEAT-019 | ETF / Options / Crypto expansion | Deferred instrument expansion; likely to be decomposed by instrument family during V7 planning. | OPEN |

## 3. Moved beyond V7

| ID | Feature | Disposition |
|---|---|---|
| V4-FEAT-052 | Standalone Telemetry Platform | **Moved to V8.** It is a separate, independently deployable, product-independent application/product. Canonical architecture remains in `V7-Telemetry-Platform.md` until/if the file is renamed; its version target is V8. |
| TBD | StoX integration with Telemetry Platform | **Planned for V9.** StoX-side instrumentation, identity/context mapping, SDK/API integration, operational telemetry and product-usage telemetry integration are intentionally separate from building the standalone Telemetry product itself. |

## 4. Planning rule

V7 work must not be treated as unfinished V6 scope. V6 implementation/closure can proceed independently while V7 items remain in planning or are implemented later.

The V8 Telemetry Platform and V9 StoX-Telemetry integration are likewise outside the V7 closure gate.
