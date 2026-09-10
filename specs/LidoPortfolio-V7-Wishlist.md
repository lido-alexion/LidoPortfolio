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
| V4-FEAT-018 | ML Scoring Models | Add explainable, point-in-time-safe ML scoring as an additive Strategy/Evaluation input without superseding deterministic decision semantics. V7 supports separate 1m/3m/6m benchmark-relative risk-aware models, broad technical/fundamental/market features, chronological evaluation, statistical + investment-outcome promotion criteria, explicit Admin retraining/promotion/rollback, optional shadow mode, persisted predictions and drift monitoring. Canonical specification: [`V7-ML-Scoring-Models-Specification.md`](V7-ML-Scoring-Models-Specification.md). | DECIDED |
| V4-FEAT-053 | Fundamental Data Integration & Support | Add first-class ongoing acquisition, storage, normalization, revision/history handling, derived metrics, APIs and product support for company fundamental data so StoX can use fundamental information alongside existing market/technical data. V7 uses a replaceable provider adapter with Yahoo/yfinance as the initial implementation; downstream StoX behavior remains provider-independent. Initial one-time historical backfill is explicitly outside this epic and tracked separately in V4-FEAT-054. Canonical specification: [`V7-Fundamental-Data-Integration-Specification.md`](V7-Fundamental-Data-Integration-Specification.md). | DECIDED |
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | One-time population of StoX with available historical quarterly and annual fundamental data before normal incremental V7 operation. The import may be performed using custom/offline scripts rather than the FEAT-053 live fetcher. StoX itself must impose no fixed historical-depth window: the canonical store and downstream analytics must support whatever historical depth is imported. Detailed source, extraction method, mapping and bootstrap procedure can be decided separately when the historical dataset is selected. | OPEN |
| V4-FEAT-055 | StoX Database Table Namespace / Prefix | Audit all database objects owned by StoX and ensure they use the canonical `stox_` prefix so they are immediately distinguishable from objects belonging to other applications sharing the same database. Existing non-conforming StoX objects are migrated in a coordinated cutover with no long-term compatibility aliases, and automated validation prevents future namespace drift. Canonical specification: [`V7-StoX-Database-Namespace-Specification.md`](V7-StoX-Database-Namespace-Specification.md). | DECIDED |

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
