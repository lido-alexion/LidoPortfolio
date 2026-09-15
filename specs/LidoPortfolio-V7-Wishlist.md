# LidoPortfolio / StoX V7 Wishlist

| Field | Value |
|---|---|
| **Document type** | Canonical V7 product wishlist and planning register |
| **Created** | 2026-09-09 |
| **Status** | IMPLEMENTED / DEPLOYED; FORMAL CLOSURE RECONCILIATION IN PROGRESS |
| **Canonical path** | `specs/LidoPortfolio-V7-Wishlist.md` |
| **Predecessor** | `specs/LidoPortfolio-V6-Wishlist.md` |

## 1. Purpose

This register records StoX work explicitly moved beyond V6. V7 planning and implementation preserve all frozen V1–V6 behavior unless a V7 specification explicitly supersedes it.

The standalone Telemetry Platform previously listed here has been moved to V8 because it is a separate application/product rather than a StoX-internal feature. StoX integration with that platform is planned separately for V9.

AI Assistant and Instrument Expansion have also been moved to V9 so V7 can focus on foundational analytical capabilities before those broader product expansions.

As of the successful `stoxla.in` VPS production deployment on 2026-09-13, the production release includes the V7 Fundamental Data and ML Scoring implementations. Their implementation and automated verification are complete; detailed production functional acceptance may still be recorded separately where useful.

## 2. Current V7 backlog

| ID | Feature | Scope / rationale | Status |
|---|---|---|---|
| V4-FEAT-018 | ML Scoring Models | Add explainable, point-in-time-safe ML scoring as an additive Strategy/Evaluation input without superseding deterministic decision semantics. V7 supports separate 1m/3m/6m benchmark-relative risk-aware models, broad technical/fundamental/market features, chronological evaluation, statistical + investment-outcome promotion criteria, explicit Admin retraining/promotion/rollback, optional shadow mode, persisted predictions and drift monitoring. Canonical specification: [`V7-ML-Scoring-Models-Specification.md`](V7-ML-Scoring-Models-Specification.md). | IMPLEMENTED / DEPLOYED |
| V4-FEAT-053 | Fundamental Data Integration & Support | Add first-class ongoing acquisition, storage, normalization, revision/history handling, derived metrics, APIs and product support for company fundamental data so StoX can use fundamental information alongside existing market/technical data. V7 uses a replaceable provider adapter with Yahoo/yfinance as the initial implementation; downstream StoX behavior remains provider-independent. Initial one-time historical backfill is explicitly outside this epic and tracked separately in V4-FEAT-054, now planned for V8. Canonical specification: [`V7-Fundamental-Data-Integration-Specification.md`](V7-Fundamental-Data-Integration-Specification.md). | IMPLEMENTED / DEPLOYED |
| V4-FEAT-055 | StoX Database Table Namespace / Prefix | Originally introduced because StoX shared a database with other applications. New V7 objects use `stox_` and validation prevents new V7 namespace drift. After migration to the dedicated StoX VPS/database, the shared-database problem no longer exists; a full legacy `portfolio_*` rename is unnecessary and intentionally not performed. Canonical closure record: [`V7-StoX-Database-Namespace-Specification.md`](V7-StoX-Database-Namespace-Specification.md). | CLOSED / SUPERSEDED BY ARCHITECTURE CHANGE |

## 2.1 Implementation and deployment snapshot

Updated 2026-09-15 after reconciliation against current `master` and the successful VPS production deployment.

- V4-FEAT-053 has implementation coverage for canonical `stox_` fundamental tables, Yahoo provider isolation, normalized fact storage, immutable revisions, point-in-time reads, derived metrics/freshness handling, Admin APIs, scheduled incremental processing and Admin UI.
- V4-FEAT-018 has implementation coverage for 1m/3m/6m model lifecycle, chronological point-in-time metadata, explicit Admin retrain/promote/rollback, promotion thresholds, persisted predictions, concise explanations and additive Evaluation/Strategy evidence that does not supersede deterministic decision semantics.
- The V7 implementation commit predates the successful 2026-09-13 production deployment. The deployed production release therefore contains FEAT-018 and FEAT-053.
- The production deployment pipeline passed the full MySQL migration/seed gate, backend tests, OpenAPI validation, frontend tests, TypeScript validation, production build, release packaging, VPS activation and HTTPS health check.
- V4-FEAT-055 is closed at its current state. New V7 objects retain `stox_`; existing V1–V6 `portfolio_*` objects are intentionally preserved because StoX now has a dedicated production database.
- FEAT-054 remains in V8 and no V8 Telemetry, V9 AI Assistant or V9 instrument-expansion work is included in V7.

## 3. Moved beyond V7

| ID | Feature | Disposition |
|---|---|---|
| V4-FEAT-054 | Historical Fundamental Data Bootstrap | **Moved to V8.** One-time population of StoX with available historical quarterly and annual fundamental data is deferred until the historical dataset/source is prepared and selected. |
| V4-FEAT-017 | AI Assistant | **Moved to V9.** AI assistance and authority boundaries will be planned after the V7 analytical-data foundations and V8 standalone Telemetry product. |
| V4-FEAT-019 | ETF / Options / Crypto expansion | **Moved to V9.** Instrument-family expansion remains separate from V7 analytical-data work and may be decomposed by instrument family during V9 planning. |
| V4-FEAT-052 | Standalone Telemetry Platform | **Moved to V8.** It is a separate, independently deployable, product-independent application/product. Canonical architecture remains in `V7-Telemetry-Platform.md` until/if the file is renamed; its version target is V8. |
| TBD | StoX integration with Telemetry Platform | **Planned for V9.** StoX-side instrumentation, identity/context mapping, SDK/API integration, operational telemetry and product-usage telemetry integration are intentionally separate from building the standalone Telemetry product itself. |

## 4. V7 closure posture

V7 no longer contains an open implementation epic:

- FEAT-018 is implemented and deployed.
- FEAT-053 is implemented and deployed.
- FEAT-055 is closed because its motivating shared-database architecture has been superseded.

Any remaining work is documentation/acceptance reconciliation rather than unfinished V7 feature implementation. V8 Telemetry and historical fundamental bootstrap, and V9 AI, instrument expansion and StoX-Telemetry integration, remain outside V7.
