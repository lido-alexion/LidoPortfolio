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

AI Assistant and Instrument Expansion have also been moved to V9 so V7 can focus on foundational analytical capabilities before those broader product expansions.

## 2. Current V7 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-018 | ML Scoring Models | Introduce ML-based scoring capability without silently superseding deterministic Strategy semantics. Exact scoring role, training/evaluation methodology, reproducibility, leakage controls, explainability, and decision-authority boundaries require V7 deliberation. | OPEN |
| V4-FEAT-053 | Fundamental Data Integration & Support | Add first-class ongoing acquisition, storage, normalization, revision/history handling, derived metrics, APIs and product support for company fundamental data so StoX can use fundamental information alongside existing market/technical data. V7 uses a replaceable provider adapter with Yahoo/yfinance as the initial implementation; downstream StoX behavior must remain provider-independent. Initial one-time historical backfill is explicitly outside this epic and tracked separately in V4-FEAT-054. | OPEN |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | One-time population of StoX with available historical quarterly and annual fundamental data before normal incremental V7 operation. The import may be performed using custom/offline scripts rather than the FEAT-053 live fetcher. StoX itself must impose no fixed historical-depth window: the canonical store and downstream analytics must support whatever historical depth is imported. Detailed source, extraction method, mapping and bootstrap procedure can be decided separately when the historical dataset is selected. | OPEN |
| V4-FEAT-055 | StoX Database Table Namespace / Prefix | Audit all database tables owned by StoX and ensure they use a common StoX-specific table-name prefix so they are immediately distinguishable from tables belonging to other applications sharing the same database. Define the canonical prefix, identify every StoX-owned existing and new table, migrate/rename non-conforming tables safely, update ORM/models/migrations/queries/tests/operational scripts as required, and establish the prefix as the naming rule for all future StoX tables. Preserve existing data and application behavior throughout the migration. | OPEN |

## 3. Moved beyond V7

| ID | Feature | Disposition |
|---|---|---|
| V4-FEAT-017 | AI Assistant | **Moved to V9.** AI assistance and authority boundaries will be planned after the V7 analytical-data foundations and V8 standalone Telemetry product. |
| V4-FEAT-019 | ETF / Options / Crypto expansion | **Moved to V9.** Instrument-family expansion remains separate from V7 analytical-data work and may be decomposed by instrument family during V9 planning. |
| V4-FEAT-052 | Standalone Telemetry Platform | **Moved to V8.** It is a separate, independently deployable, product-independent application/product. Canonical architecture remains in `V7-Telemetry-Platform.md` until/if the file is renamed; its version target is V8. |
| TBD | StoX integration with Telemetry Platform | **Planned for V9.** StoX-side instrumentation, identity/context mapping, SDK/API integration, operational telemetry and product-usage telemetry integration are intentionally separate from building the standalone Telemetry product itself. |

## 4. Planning rule

V7 work must not be treated as unfinished V6 scope. V6 implementation/closure can proceed independently while V7 items remain in planning or are implemented later.

The V8 Telemetry Platform and V9 AI, instrument-expansion and StoX-Telemetry integration work are outside the V7 closure gate.
