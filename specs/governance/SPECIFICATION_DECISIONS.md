# Specification Decisions

**Document:** Governance — Specification Decisions  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  
**Authority:** Accepted deviations between `/specs` architectural intent and Version 1.0 implementation  

Related: [`MVP_SCOPE.md`](./MVP_SCOPE.md) · [`VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) · [`DOCUMENT_PRECEDENCE.md`](./DOCUMENT_PRECEDENCE.md) · Audit [`../audit/`](../audit/)

---

## Purpose

Original specifications under `/specs/architecture` and `/specs/engines` define **architectural intent**. They are not rewritten to match code.

This register is the authoritative record of **why Version 1.0 differs** from those documents, and how future work should treat each decision.

**Status values:** Accepted | Deferred | Superseded | Rejected

---

## Decision register

### SD-001 — Sanctum session auth instead of JWT

| Field | Content |
|-------|---------|
| **Category** | Security / API |
| **Original Specification** | Application Architecture & REST API: JWT Bearer authentication |
| **Implemented Behaviour** | Laravel Sanctum cookie/session auth for SPA; `/api/v1` uses `auth:sanctum` |
| **Reason** | Existing Lido Portfolio SPA already used Sanctum; JWT would duplicate auth stacks |
| **Benefits** | CSRF-aligned SPA security; no dual login models; faster MVP |
| **Trade-offs** | Non-SPA API clients cannot use simple Bearer tokens without further work |
| **Future Recommendation** | Keep Sanctum for SPA; optionally add token API later without removing cookies |
| **Status** | Accepted |

---

### SD-002 — Reuse `portfolio_*` physical schema

| Field | Content |
|-------|---------|
| **Category** | Data / Schema |
| **Original Specification** | Logical tables `securities`, `price_bars`, `positions`, etc. |
| **Implemented Behaviour** | Map to `portfolio_stocks`, `portfolio_stock_prices`, `portfolio_holdings`, `portfolio_transactions`; new TOS entities use `portfolio_tos_*` |
| **Reason** | Avoid rewriting the live portfolio ledger and stock master |
| **Benefits** | Single source of truth for holdings/prices; lower migration risk |
| **Trade-offs** | Physical names diverge from domain vocabulary; requires mapping docs |
| **Future Recommendation** | Keep mapping; do not rename production tables for cosmetic alignment |
| **Status** | Accepted |

---

### SD-003 — No dedicated `trading_sessions` table

| Field | Content |
|-------|---------|
| **Category** | Data Engine |
| **Original Specification** | Trading Session entity with holiday calendar |
| **Implemented Behaviour** | Session implied by `price_date` on OHLCV rows |
| **Reason** | Existing price model already session-dated; calendar product deferred |
| **Benefits** | Simpler schema |
| **Trade-offs** | Missing-session detection and holiday awareness weaker |
| **Future Recommendation** | Introduce trading calendar + validation in 1.1+ (see backlog) |
| **Status** | Deferred |

---

### SD-004 — Formal dataset publish / validation gates deferred

| Field | Content |
|-------|---------|
| **Category** | Data Engine |
| **Original Specification** | Import → Validate → Publish; only published datasets consumed |
| **Implemented Behaviour** | Soft status (`synced_today` / date-based `dataset_version`); engines read latest bars |
| **Reason** | Existing sync pipeline ships usable data; full gate is a separate product |
| **Benefits** | MVP unblocked |
| **Trade-offs** | Incomplete/bad bars can reach evaluation |
| **Future Recommendation** | Hard publish gate before discovery (Critical backlog) |
| **Status** | Deferred |

---

### SD-005 — Discovery orchestrates existing PatternScan + Screener services

| Field | Content |
|-------|---------|
| **Category** | Discovery |
| **Original Specification** | Discovery Engine owns patterns/signals/screening as first-class engine capability |
| **Implemented Behaviour** | `DiscoveryEngine` wraps `PatternScanService` and recent screener hits; no separate Discovery Engine Spec file |
| **Reason** | Patterns/screeners already mature in the app |
| **Benefits** | No duplicate SOS; proven algorithms |
| **Trade-offs** | Discovery depends on service APIs and prior screener runs |
| **Future Recommendation** | Keep wrap pattern; optionally add Discovery Spec later documenting ownership |
| **Status** | Accepted |

---

### SD-006 — Evaluation uses existing indicator services + weighted scoring

| Field | Content |
|-------|---------|
| **Category** | Evaluation |
| **Original Specification** | Pluggable rules; market regime; rich indicator set |
| **Implemented Behaviour** | `TechnicalIndicatorService` / `RelativeStrengthService` + configurable weights in `trading_os.php` |
| **Reason** | Deterministic MVP scoring without a rules engine |
| **Benefits** | Explainable, testable, configurable weights |
| **Trade-offs** | No market regime; rules not catalogued as entities |
| **Future Recommendation** | Extract rule modules; add regime in 1.2+ |
| **Status** | Accepted |

---

### SD-007 — Strategy entity deferred

| Field | Content |
|-------|---------|
| **Category** | Domain / Recommendation |
| **Original Specification** | Strategy as isolated methodology with own holdings/rules |
| **Implemented Behaviour** | Portfolio-profile scoped recommendations only |
| **Reason** | Single-trader MVP; Strategy is Stage/roadmap feature |
| **Benefits** | Simpler model and UI |
| **Trade-offs** | No multi-strategy comparison |
| **Future Recommendation** | Introduce Strategy in 2.0 |
| **Status** | Deferred |

---

### SD-008 — Recommendation lifecycle includes user review states

| Field | Content |
|-------|---------|
| **Category** | Recommendation |
| **Original Specification** | Draft → Active → Executed / Expired / Cancelled |
| **Implemented Behaviour** | `pending_review` → `accepted` / `rejected` / `deferred` → `executed` / `cancelled` / `expired` |
| **Reason** | Pipeline Stage 11 (User Review) required Accept before order |
| **Benefits** | Human control; auditable decisions |
| **Trade-offs** | Spec status names diverge |
| **Future Recommendation** | Keep review states; update future engine revisions via governance, not by rewriting v0.1 specs |
| **Status** | Accepted |

---

### SD-009 — Telegram-only notifications

| Field | Content |
|-------|---------|
| **Category** | Notification |
| **Original Specification** | Email, Telegram, Webhook (and more) |
| **Implemented Behaviour** | Telegram via existing `TelegramNotificationService`; history + retry |
| **Reason** | Primary channel already integrated; multi-channel is expansion |
| **Benefits** | Reliable single path; less ops surface |
| **Trade-offs** | No email/webhook; channel selection hardcoded |
| **Future Recommendation** | Channel interface in 1.1/1.2 |
| **Status** | Accepted |

---

### SD-010 — Manual order lifecycle; no broker automation

| Field | Content |
|-------|---------|
| **Category** | Execution |
| **Original Specification** | Stage 1 manual; future broker; Execution owns orders/positions/txs |
| **Implemented Behaviour** | Pending → Executed | Cancelled; fills write legacy ledger/holdings |
| **Reason** | Vision Stage 1 decision support; A10 broker out of MVP |
| **Benefits** | Trust-first; no broker credential risk |
| **Trade-offs** | User must execute externally |
| **Future Recommendation** | Broker adapters in 2.0 (Assisted Execution) |
| **Status** | Accepted |

---

### SD-011 — Positions and ledger remain legacy portfolio tables

| Field | Content |
|-------|---------|
| **Category** | Execution / Schema |
| **Original Specification** | Dedicated `positions` / `transactions` TOS tables |
| **Implemented Behaviour** | `portfolio_holdings` + `portfolio_transactions` + `portfolio_tos_order_transactions` link |
| **Reason** | Holdings calculation and realizations already production-critical |
| **Benefits** | Consistent portfolio math |
| **Trade-offs** | Hybrid: some txs without TOS orders |
| **Future Recommendation** | Keep bridge table; do not fork ledger |
| **Status** | Accepted |

---

### SD-012 — Existing portfolio services retained under engines

| Field | Content |
|-------|---------|
| **Category** | Architecture |
| **Original Specification** | Engines → Repositories → DB; greenfield service boundaries |
| **Implemented Behaviour** | Engines façade over `App\Services\*` (sync, holdings, patterns, Telegram, calculations) |
| **Reason** | Evolve existing monolith; avoid rewrite |
| **Benefits** | Reuse, fewer bugs, faster delivery |
| **Trade-offs** | Dual entry points (legacy controllers + engines) |
| **Future Recommendation** | Gradually route new features through engines only |
| **Status** | Accepted |

---

### SD-013 — No repository / DTO layer in V1.0

| Field | Content |
|-------|---------|
| **Category** | Architecture |
| **Original Specification** | Repositories, immutable DTOs, interface-driven design |
| **Implemented Behaviour** | Eloquent models used directly in engines |
| **Reason** | Laravel pragmatism for MVP velocity |
| **Benefits** | Less boilerplate |
| **Trade-offs** | Persistence coupling; harder isolated unit tests |
| **Future Recommendation** | Introduce repositories for TOS aggregates in 1.2+ if needed |
| **Status** | Deferred |

---

### SD-014 — Additive `/api/v1` with legacy `/api` retained

| Field | Content |
|-------|---------|
| **Category** | API |
| **Original Specification** | Versioned REST as system contract |
| **Implemented Behaviour** | TOS capabilities on `/api/v1`; existing SPA continues on `/api/*` |
| **Reason** | Non-breaking evolution of live app |
| **Benefits** | Zero forced migration of legacy UI |
| **Trade-offs** | Two surfaces; docs must clarify which to use |
| **Future Recommendation** | OpenAPI for v1; migrate TOS-relevant legacy calls gradually |
| **Status** | Accepted |

---

### SD-015 — Application lives under `app/` not `backend/` + nested React

| Field | Content |
|-------|---------|
| **Category** | Structure |
| **Original Specification** | Separate `backend/` and `frontend/` trees; TypeScript SPA layout |
| **Implemented Behaviour** | Monorepo `app/` Laravel root; React under `resources/js` (JSX) |
| **Reason** | Established Lido layout (Jun 2026 rename) |
| **Benefits** | Single deployable unit for cPanel |
| **Trade-offs** | Spec folder diagram outdated; no TS/features/store trees |
| **Future Recommendation** | Do not restructure for cosmetic compliance |
| **Status** | Accepted |

---

### SD-016 — Frontend stack deviations (Query / Grid / Charts / TS)

| Field | Content |
|-------|---------|
| **Category** | Frontend |
| **Original Specification** | TypeScript, TanStack Query, AG Grid, Chart.js/Lightweight Charts |
| **Implemented Behaviour** | JSX, axios pages, existing Bootstrap UI, Recharts elsewhere |
| **Reason** | Match existing SPA patterns |
| **Benefits** | Consistent UX and build |
| **Trade-offs** | Spec checklist not met |
| **Future Recommendation** | Adopt selectively only when a feature needs them |
| **Status** | Accepted |

---

### SD-017 — Pipeline position stage is count-only; shallow position review

| Field | Content |
|-------|---------|
| **Category** | Pipeline / Recommendation |
| **Original Specification** | Stage 8 Position Review: stops, exits, health, allocation |
| **Implemented Behaviour** | Held names → SELL/HOLD by score; pipeline records open position count |
| **Reason** | Time-boxed MVP; still produces SELL/HOLD types |
| **Benefits** | Position-aware recommendations exist |
| **Trade-offs** | Weak exit/allocation analysis |
| **Future Recommendation** | Deepen Position Review in 1.1 (High) |
| **Status** | Deferred |

---

### SD-018 — Execution may set recommendation `executed` status

| Field | Content |
|-------|---------|
| **Category** | Architecture / Ownership |
| **Original Specification** | Only owning engine modifies its entities |
| **Implemented Behaviour** | `ExecutionEngine` updates `TradingRecommendation` to `executed` on fill |
| **Reason** | Simple transactional close of the workflow |
| **Benefits** | Atomic order fill + status |
| **Trade-offs** | Soft ownership violation |
| **Future Recommendation** | Route through `RecommendationEngine::markExecuted()` |
| **Status** | Accepted |

---

### SD-019 — OpenAPI documentation deferred

| Field | Content |
|-------|---------|
| **Category** | Developer Experience |
| **Original Specification** | Generate OpenAPI alongside implementation |
| **Implemented Behaviour** | No OpenAPI; inventory in audit + this governance pack |
| **Reason** | MVP focused on working SPA |
| **Benefits** | — |
| **Trade-offs** | External clients under-documented |
| **Future Recommendation** | OpenAPI in 1.1/1.2 |
| **Status** | Deferred |

---

### SD-020 — Engine layer under `App\Engines` with DailyDecisionPipeline

| Field | Content |
|-------|---------|
| **Category** | Architecture |
| **Original Specification** | Seven engines; pipeline as operational heartbeat |
| **Implemented Behaviour** | Seven engines + Pipeline orchestrator + `portfolio:decision-pipeline` |
| **Reason** | Matches architecture docs closely while wrapping services |
| **Benefits** | Clear ownership entry points; auditable pipeline runs |
| **Trade-offs** | Schedule off by default; sync not embedded in pipeline |
| **Future Recommendation** | Wire post-sync / schedule carefully in 1.1 |
| **Status** | Accepted |

---

### SD-021 — Shared TransactionWriteService for ledger creates

| Field | Content |
|-------|---------|
| **Category** | Execution / Ledger |
| **Original Specification** | Dual entry points OK (legacy + engines) with bridge table |
| **Implemented Behaviour** | `TransactionWriteService` is the single create path for `POST /api/transactions` and TOS order fills; ExecutionEngine adds order link + recommendation `executed` after shared insert |
| **Reason** | Avoid divergent holdings/backfill/snapshot behaviour between Transactions Add and Review Add transaction |
| **Benefits** | Add transaction is a subset of execute; one portfolio truth |
| **Trade-offs** | Update/delete still live only on TransactionController (not required for execute) |
| **Future Recommendation** | Optional “link existing transaction to recommendation” for drift repair |
| **Status** | Accepted |

---

### SD-022 — Actionable vs informational recommendation workflows

| Field | Content |
|-------|---------|
| **Category** | Recommendation / UX |
| **Original Specification** | All recommendations enter user review (Accept / Reject / Defer) before further action |
| **Implemented Behaviour** | **BUY/SELL** are actionable: `pending_review` → review → optional order → execution. **HOLD/WATCH** are informational: auto-`published` on generation; no Accept/Reject/Defer; no orders. Review dashboard metrics and queues are actionable-only; insights have separate counts/outcomes. |
| **Reason** | The User Review workflow exists to approve trading actions. Informational recommendations (HOLD and WATCH) do not require approval because they do not result in an executable action. |
| **Benefits** | Clearer UX; review queue not cluttered with non-trade ideas; matches product intent |
| **Trade-offs** | Historical HOLD/WATCH left in `pending_review` are backfilled to `published` (migration `2026_07_25_000004_*`) |
| **Future Recommendation** | Optional archive / mark-as-read for insights if product needs it |
| **Status** | Accepted |

---

### SD-023 — Recommendation Engine separates Market Opinion from Portfolio Decision

| Field | Content |
|-------|---------|
| **Category** | Recommendation / Architecture |
| **Original Design** | Single recommendation type BUY / SELL / HOLD / WATCH derived mainly from score ± held flag |
| **Reason for Change** | A stock recommendation is not universal; the correct action depends on holdings, allocation, cash/risk limits. MVP testing showed simple BUY/SELL conflated market view with portfolio action. |
| **Implemented Behaviour** | Three stages: (1) Market Opinion — Bullish/Neutral/Bearish + strength + confidence + evidence, portfolio-independent; (2) Portfolio Decision — OPEN_POSITION / INCREASE_POSITION / REDUCE_POSITION / EXIT_POSITION / HOLD_POSITION / WATCH; (3) Execution Plan — sizing toward target allocation for actionable decisions only. UI labels: Buy / Buy More / Sell Partial / Sell All / Hold / Watch. |
| **Benefits** | Matches real portfolio management; enables Review metrics such as “bullish → increase”; clearer Accept/Reject semantics |
| **Migration Impact** | Migration `2026_07_25_000005_*` widens type, adds JSON/allocation columns; maps BUY→OPEN_POSITION, SELL→EXIT_POSITION, HOLD→HOLD_POSITION; API keeps `recommendation_type` and adds `market_opinion` / `execution_plan` |
| **Future Extensions** | Per-symbol target weights, explicit cash, strategy policies |
| **Status** | Accepted |

---

### SD-024 — Undo review decisions and undo executed fills

| Field | Content |
|-------|---------|
| **Category** | Recommendation / Execution |
| **Original Design** | Reject final; executed stays executed even if ledger fill deleted |
| **Reason for Change** | Operators need to correct mistakes without SSH/DB edits |
| **Implemented Behaviour** | (1) `POST /api/v1/recommendations/{id}/reopen` — Accept/Reject/Defer → `pending_review`; audit `reopened`; cancels pending orders. (2) Deleting a Transactions row linked via `portfolio_tos_order_transactions` cancels the order and reopens the recommendation to `pending_review`. Executed cannot use reopen API until fill deleted. |
| **Benefits** | Safe undo path aligned with shared ledger (SD-021) |
| **Status** | Accepted |

---

## Summary table

| ID | Decision | Status |
|----|----------|--------|
| SD-001 | Sanctum vs JWT | Accepted |
| SD-002 | `portfolio_*` / `portfolio_tos_*` schema | Accepted |
| SD-003 | No trading_sessions table | Deferred |
| SD-004 | Formal publish/validation gates | Deferred |
| SD-005 | PatternScan + Screener reuse | Accepted |
| SD-006 | Weighted scoring via existing indicators | Accepted |
| SD-007 | Strategy deferred | Deferred |
| SD-008 | User review recommendation states | Accepted |
| SD-009 | Telegram only | Accepted |
| SD-010 | Manual execution (no broker) | Accepted |
| SD-011 | Legacy holdings/transactions | Accepted |
| SD-012 | Engines wrap existing Services | Accepted |
| SD-013 | No repositories/DTOs | Deferred |
| SD-014 | Dual API surfaces | Accepted |
| SD-015 | `app/` + nested React | Accepted |
| SD-016 | Frontend stack pragmatism | Accepted |
| SD-017 | Shallow Position Review | Deferred |
| SD-018 | Execution updates recommendation status | Accepted |
| SD-019 | OpenAPI deferred | Deferred |
| SD-020 | Engines + DailyDecisionPipeline | Accepted |
| SD-021 | Shared TransactionWriteService | Accepted |
| SD-022 | Actionable vs informational recommendations | Accepted |
| SD-023 | Market Opinion vs Portfolio Decision | Accepted |
| SD-024 | Undo review / undo executed fill | Accepted |

---

## Change control

1. New deviations require a new **SD-xxx** entry (or status change to Superseded/Rejected).  
2. Do **not** rewrite historical architecture/engine specs to erase intent.  
3. Scope of what ships is governed by [`MVP_SCOPE.md`](./MVP_SCOPE.md).  
4. Deferred items are tracked in [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md).
