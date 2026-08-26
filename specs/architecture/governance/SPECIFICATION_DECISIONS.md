# Specification Decisions

**Document:** Governance — Specification Decisions  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  
**Authority:** Accepted deviations between `/specs` architectural intent and Version 1.0 implementation  

Related: [`./MVP_SCOPE.md`](./MVP_SCOPE.md) · [`./VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) · [`./DOCUMENT_PRECEDENCE.md`](./DOCUMENT_PRECEDENCE.md) · [`./ARCHITECTURE_REPOSITORY_GOVERNANCE.md`](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) · Audit [`../audit/`](../audit/)

---

## Purpose

Original specifications under `/specs/architecture` (including `domains/`) define **architectural intent**. They are not rewritten to match code. They are also not rewritten to duplicate concepts already owned by an Approved specification — see [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §7 and Golden Rule.

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
| **Status** | **Superseded by SD-027** (Strategy Configuration implemented) |

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
| **Implemented Behaviour** | (1) `POST /api/v1/recommendations/{id}/reopen` — Approve/Reject/Defer → `pending_review`; audit `reopened`. (2) Deleting a Transactions row linked by `recommendation_id` (or legacy order link) returns the recommendation to `pending_execution` (SD-025). Executed cannot use reopen API until fill deleted. |
| **Benefits** | Safe undo path aligned with shared ledger (SD-021) |
| **Status** | Accepted |

---

### SD-025 — Recommendation Approval separated from Trade Execution

| Field | Content |
|-------|---------|
| **Category** | Recommendation / Execution |
| **Original Design** | Accept implied readiness to trade; Accept + Execute often combined; `accepted` status sat between review and fill; orders were the primary execution surface |
| **Reason for Change** | Approval (“I agree with this portfolio decision”) is not the same as execution (“I placed / recorded this trade”). Operators often approve now and trade later (or never). Coupling them forced premature ledger writes and confused UX |
| **Benefits** | Clear Approve vs Execute; delayed/manual execution; pending-execution queue; cancel without rejecting the decision; recommendation↔transaction traceability; no new Orders page |
| **Future Broker Integration** | Broker adapters will fill the same pending-execution queue (create/link transactions); approval remains Recommendation Engine; execution remains Execution Engine + ledger |
| **Migration Impact** | Migration `2026_07_25_000006_*`: `accepted` → `pending_execution`; recommendations gain approval/cancel/execute metadata; `portfolio_transactions` gain `source` + `recommendation_id`. Review decision `approved` (BC alias `accepted`) |
| **Implemented Behaviour** | Actionable: `pending_review` → Approve → `pending_execution` → manual `POST /api/transactions` with `recommendation_id` → `executed`, or Cancel execution → `cancelled`, or Expire → `expired`. Reject/Defer unchanged. Informational path unchanged (`published`). Legacy `/api/v1/orders*` retained; `execute_now` defaults **false** |
| **Status** | Accepted |

---

### SD-026 — Recommendation Engine enhanced with portfolio-wide Capital Allocation

| Field | Content |
|-------|---------|
| **Category** | Recommendation / Cash |
| **Original Design** | Per-symbol Execution Plan toward target / max position %; no portfolio cash account; no reserved cash; generation did not optimise across the batch against a cash pool |
| **Reason** | Without cash and portfolio-wide allocation, buy suggestions could over-commit capital and ignore prior approved-but-unexecuted buys. Operators need deposit/withdraw/adjust, and generation must fund the best opportunities first within available investable cash |
| **Benefits** | Real capital constraint; reserved cash prevents double-spend of pending buys; pluggable allocators; auditable cash ledger; demote unfunded OPEN/INCREASE to WATCH with evidence |
| **Reserved Cash Model** | Cash **balance** is ledger-backed (`portfolio_cash_accounts`). **Reserved cash** = sum of `reserved_amount` on `pending_execution` buys with `reservation_status=reserved`. **Available investable cash** = max(0, balance − reserved). Approve buy → reserve; execute → convert + cash buy post; cancel/expire/reopen → release. Reserved is **not** deducted from balance until execution |
| **Future Optimisation Algorithms** | Replace or compose `CapitalAllocationStrategy` (default `ScorePriorityCapitalAllocator`) with risk-parity, sector/concentration caps, Kelly-style, or ML-assisted allocators without changing reservation semantics |
| **Migration Considerations** | Migration `2026_07_25_000007_*`: create `portfolio_cash_accounts` + `portfolio_cash_ledger_entries`; add recommendation capital/reservation columns. Existing profiles start at balance 0 until deposit/adjust. Recommendation generation version=3 snapshots cash |
| **Implemented Behaviour** | `CashManagementService` + `/api/cash*` APIs; `RecommendationEngine` stages Ranking → Capital Allocation → Trade gen; unfunded buys → WATCH (`evidence.capital_allocation.status=unfunded`); Pending Execution UI shows reserved/available cash |
| **Status** | Accepted |

---

### SD-027 — Recommendation logic externalised into Strategy Configuration

| Field | Content |
|-------|---------|
| **Category** | Architecture / Recommendation / Evaluation |
| **Original Design** | Evaluation Engine applied hardcoded/config weights into a final score; Recommendation Engine used `trading_os.recommendation` thresholds and position % from PHP config. Strategy entity was deferred (SD-007) |
| **Reason for Change** | Investment philosophy was tightly coupled to engine code/config files. Operators could not tune factors, thresholds, or allocation rules without deployments. Historical recommendations could not be attributed to a named strategy version |
| **Benefits** | Strategy is editable in UI; Recommendation Engine becomes a generic executor; Evaluation emits factor facts only; every recommendation stores strategy version + factor breakdown for explainability and future backtesting; config-driven factors avoid schema churn |
| **Migration Impact** | Migration `2026_07_26_000009_*`: `portfolio_tos_strategies`, `portfolio_tos_strategy_versions`, recommendation `strategy_version_id` + `strategy_score`. First generate/API call seeds Default Strategy v1 from legacy `trading_os.php` values. Existing recommendations without strategy_version remain readable |
| **Future Extensions** | Multiple strategies per profile; A/B activation; richer scoring curves; sector/theme caps; backtests against version snapshots *(note: the **currently shipped** strategy backtesting product is formally V1 per **SD-035**; this bullet refers to **future** enhancements beyond that implementation)* |
| **Spec** | [`../domains/Strategy-Configuration-Specification.md`](../domains/Strategy-Configuration-Specification.md) |
| **Supersedes** | SD-007 (Strategy deferred) for V1.1+ — Strategy is now implemented |
| **Status** | Accepted |

---

### SD-028 — Adopt Fixed Supported Indicator Catalogue Instead of Plugin-Based Indicator Framework

| Field | Content |
|-------|---------|
| **Category** | Architecture / Strategy |
| **Original Proposal** | Fully generic / plugin-style indicator framework (arbitrary factor keys, user-defined indicators, extensible without releases) |
| **Reason for Simplification** | Product goal is a configurable **momentum trading OS**, not a generic trading/indicator platform. Plugin/EAV frameworks add complexity without user value for V1 |
| **Benefits** | Simple catalogue; clear UI (no Add Indicator); Evaluation/Strategy/Recommendation stay maintainable; adding an indicator = evaluation logic + catalogue entry + config exposure |
| **Trade-offs** | New indicators require an application release; users cannot invent custom formulas |
| **Future Expansion Strategy** | Extend catalogue via **Indicator Registry** (SD-033) and Evaluation measurements in releases; keep Strategy JSON versioning; still no plugin runtime |
| **Relationship** | Clarifies / constrains SD-027 Strategy Configuration; extended by **SD-033** |
| **Status** | Accepted |

---

### SD-029 — Factory Strategy with User-Customisable Versions

| Field | Content |
|-------|---------|
| **Category** | Architecture / Strategy |
| **Original Design** | Empty or legacy-config-seeded strategy requiring operators to tune every weight, threshold, and rule before usable recommendations |
| **Decision (amended 2026-07-29)** | Ship one **default Minervini Strategy** per portfolio (Minervini Trend Template eligibility + momentum scoring defaults). **Save updates that strategy in place** — no version fork, no Duplicate, no protected factory copy. Internal `portfolio_tos_strategy_versions` row still holds `config_json` for FK stability (`strategy_version_id` on recommendations) but the UI exposes a single editable strategy |
| **Why opinionated defaults** | Installation must be immediately usable after market data import — empty strategy configuration is a barrier, not flexibility. Defaults are starting points; all values remain editable |
| **Weight integrity** | Enabled indicator weights must sum to exactly **100** after save. UI shows the live total; when the total is not 100, Save auto-normalises enabled weights proportionally (largest-remainder, 2 d.p.). Optional **Normalise now** previews the scaled values. Save is blocked only when no enabled factor has a positive weight |
| **Migration Impact** | Migration `2026_07_26_000010_*`: `is_factory`, `factory_key`, `duplicated_from_id`, `version_label` retained for seed idempotency. Seeder `FactoryMomentumStrategySeeder`; `ensureActive` seeds default when no active strategy. `POST /strategy/duplicate` removed |
| **Spec** | [`../domains/Strategy-Configuration-Specification.md`](../domains/Strategy-Configuration-Specification.md) § Default Strategy |
| **Relationship** | Extends SD-027 / SD-028 |
| **Status** | Accepted (amended) |

---

### SD-030 — Strategies Consume Screeners Instead of Owning Eligibility Rules

| Field | Content |
|-------|---------|
| **Category** | Architecture / Strategy / Screener |
| **Original Design** | Strategy Configuration included indicator min/enable gates that acted as a second eligibility / filtering layer alongside the existing Screener module (JSON condition trees, runs, hits) |
| **Problem** | Two independent “rule engines” solved the same problem (which stocks qualify). Duplication invited drift, confused ownership, and pushed Recommendation toward re-evaluating market rules |
| **Decision** | **Screeners** are the sole eligibility engine. **Strategies** reference one or more Screeners by ID (`eligibility_sources` / `portfolio_tos_strategy_screeners`). Strategy owns scoring, portfolio rules, capital allocation, exits, and thresholds only. Recommendation Engine consumes Screener hits + Evaluation facts — it never executes Screener condition logic |
| **Benefits** | One eligibility engine; Screener reuse across Discovery/Strategy/alerts; clearer explainability (Screener PASS + scoring + exit); simpler Strategy UI (no condition editor) |
| **Migration Impact** | Migration `2026_07_26_000011_*`; factory **Minervini Trend Template** Screener; factory Momentum Strategy links it; existing Screeners unchanged; Strategy indicator gates remain **scoring** gates only |
| **Future Extensibility** | Watchlists/alerts/automation reuse same Screeners; optional normalized Condition entities later without changing Strategy→Screener reference model |
| **Spec** | [`../domains/Screener-Specification.md`](../domains/Screener-Specification.md), [`../domains/Strategy-Configuration-Specification.md`](../domains/Strategy-Configuration-Specification.md) |
| **Status** | Accepted |

---

### SD-031 — Analytics Ownership Model

| Field | Content |
|-------|---------|
| **Category** | Architecture / Product IA |
| **Reason** | Analytical metrics were scattered across Dashboard, Watchlist, Holdings, Explorer, and TOS pages without clear ownership, causing duplication and mixed page purposes |
| **Decision** | Four categories with single owners: **Stock Analytics** (`StockAnalyticsService`), **Evaluation Profile** (Evaluation Engine), **Portfolio Analytics** (`PortfolioAnalyticsService`), **Market Analytics** (`MarketAnalyticsService`). Pages answer one question each: Dashboard (portfolio+market), Watchlist (research), Portfolio/Holdings (manage positions), Discovery (find opportunities) |
| **Benefits** | Single source of truth per metric; clearer UX; cacheable market/portfolio snapshots; Evaluation scores never recalculated ad hoc in UI |
| **Migration Impact** | Migration `2026_07_26_000012_*` cache tables; APIs under `/api/v1/analytics/*`; Dashboard/Watchlist/Holdings/Discovery UI updated; legacy `/api/analytics/*` retained |
| **Future Extensibility** | Sector performance, pairwise correlation, richer beta vs index; deeper Portfolio page recommendation columns |
| **Spec** | [`../portfolio/Analytics-Architecture-Specification.md`](../portfolio/Analytics-Architecture-Specification.md) |
| **Status** | Accepted |

---

### SD-032 — Introduce Market Analysis Engine

| Field | Content |
|-------|---------|
| **Category** | Architecture / Engine |
| **Reason** | Experienced traders evaluate the overall market before stocks; the app analysed only individual securities, leaving market regime/sentiment duplicated or missing across Recommendation, Strategy, Portfolio Analytics, and Dashboard |
| **Decision** | Add a dedicated **Market Analysis Engine** that analyses benchmark index OHLCV into reusable market analytics (trend, momentum, volatility, risk, drawdown, breadth), continuous **Market Sentiment** (0–100 with weighted components), and categorical **Market Phase** (deterministic rules). Evaluation Engine remains stock-level; Recommendation / Strategy / Portfolio Analytics / Dashboard **consume** market outputs and never recalculate them. V1: one primary benchmark |
| **Architecture** | `MarketAnalysisEngine` ← OHLCV + shared `TechnicalIndicatorService` → persist `portfolio_tos_market_analytics` → façade `MarketAnalyticsService` → APIs `/api/v1/market-analysis*` and `/api/v1/analytics/market` |
| **Interaction with Evaluation** | Orthogonal: Evaluation = stock facts; Market Analysis = market facts; neither knows portfolios/recommendations |
| **Interaction with Recommendation** | Recommendation applies `allocation_multiplier`, `new_entry_allowed`, and optional Strategy `market_gates` (min sentiment, allowed phases, max raw risk) |
| **Benefits** | Single market source of truth; explainable phase/sentiment; consistent sizing and entry gates; Dashboard market section |
| **Migration Impact** | Migration `2026_07_26_000013_*`; Dashboard Market Analytics UI; Strategy Market Gates section; Portfolio Analytics `market_context` |
| **Future Extensibility** | Multiple benchmarks; constituent breadth V2; optional news/macro contributors without redesign |
| **Spec** | [`../domains/Market-Analysis-Engine-Specification.md`](../domains/Market-Analysis-Engine-Specification.md) |
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
| SD-007 | Strategy deferred | **Superseded by SD-027** |
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
| SD-025 | Approval separated from execution | Accepted |
| SD-026 | Cash management + portfolio-wide capital allocation | Accepted |
| SD-027 | Strategy Configuration externalises recommendation logic | Accepted |
| SD-028 | Fixed supported indicator catalogue (not plugins) | Accepted |
| SD-029 | Factory Momentum Strategy + protected / duplicate | Accepted |
| SD-030 | Strategies consume Screeners (single eligibility engine) | Accepted |
| SD-031 | Analytics Ownership Model (four categories / page questions) | Accepted |
| SD-032 | Introduce Market Analysis Engine | Accepted |
| SD-033 | Unified Indicator Registry (evolve dual catalogues) | Accepted (design) |
| SD-034 | Trading Artifact Framework (Indicator / Screener / Strategy artifacts) | Accepted (design) |
| SD-035 | Formal V1 scope freeze (ambiguous capabilities resolved) | Accepted |

---

### SD-033 — Unified Indicator Registry (Evolve Dual Catalogues; Preserve Calculators)

| Field | Content |
|-------|---------|
| **Category** | Architecture / Indicators |
| **Date** | 2026-07-30 |
| **Original state** | Dual hardcoded catalogues (`ScreenerCatalog` + `SupportedIndicators`); metadata duplicated; no dependency/consumer model; no Admin registry UI; Metrics (Stock Analytics) outside catalogues |
| **Decision** | Introduce a **unified Indicator Registry** as the single source of truth for indicator **metadata and discovery**. Preserve `TechnicalIndicatorService` (primary calc), `EvaluationEngine` (composite facts), `StrategyConfigurationService::score` (weights). Keep `ScreenerCatalog` and `SupportedIndicators` as **façades** that derive from / sync with the Registry. Formalize types: **Primary**, **Composite**, **Metric**. Formalize categories, expanded metadata, declared dependencies, consumer discovery, versioning, Admin Indicator Registry UI, formula explanation (docs only). Continue **SD-028** — no plugins, no user formula engine, release-shipped indicators only. |
| **Planned indicators (metadata first)** | Primaries: Average Daily Turnover, Relative Turnover, Gap Frequency, Gap Fill Ratio, Circuit Frequency, Circuit Risk, Average Daily Volume. Composites: Liquidity Score, Tradability Score. Calculation formulas deferred until a dedicated implementation release. |
| **Out of scope for Registry phases 1–3** | Wiring Strategy UI indicator parameters into EvaluationEngine (uses `trading_os.php` today). Tracked separately as **TD-19** / **PB-054**. |
| **Benefits** | One discovery API; dependency trees; consumer clarity; Admin visibility; safer extensibility without rewrite |
| **Trade-offs** | Migration cost to point façades at Registry; temporary dual-read period |
| **Spec** | [`../domains/Indicator-Registry-Specification.md`](../domains/Indicator-Registry-Specification.md) · [`../indicators/09-Indicator-Registry.md`](../indicators/09-Indicator-Registry.md) · as-built [`../indicators/08-Indicator-Architecture-Analysis.md`](../indicators/08-Indicator-Architecture-Analysis.md) |
| **Relationship** | Extends SD-028 (fixed catalogue → fixed Registry entries); complements SD-027/030/031/032; specialized under **SD-034** Trading Artifact Framework |
| **Status** | **Accepted** (design / specification). Implementation phased via PRODUCT_BACKLOG (PB-055+) |

---

### SD-034 — Trading Artifact Framework (Indicators, Screeners, Strategies)

| Field | Content |
|-------|---------|
| **Category** | Architecture / Platform |
| **Date** | 2026-07-30 |
| **Original state** | Indicator Registry designed (SD-033); Screeners as DB + `definition_json`; Strategies as portfolio-bound `config_json`; informal “Strategy Template” idea for reuse |
| **Decision** | Introduce a **Trading Artifact Framework** as the shared model for reusable, versioned, validated, importable/exportable, AI-friendly trading definitions. First-class types: **Indicator**, **Screener**, **Strategy**. Absorb “Strategy Templates” into Strategy artifacts (`origin=factory\|imported\|fork`). Preserve Screener `definition_json` and Strategy `config_json` as definition cores. Preserve SD-028 (no plugins) and SD-030 (Strategy references Screeners). Indicator Registry remains the Indicator specialization. Umbrella Artifact Registry owns cross-type catalogue, package I/O, dependency resolution, validation orchestration. |
| **Lifecycle** | `draft → active → deprecated → archived`; validate-before-activate; AI/imported drafts must not auto-activate |
| **Versioning** | Immutable published versions; integer revisions internally; V1 Strategy Save-in-place retained as portfolio-binding compatibility mode |
| **Import/Export** | Portable JSON packages (`schema_version`); bundle Screeners + Strategy; reference Indicators by slug/version (no executable payloads) |
| **Out of scope for design acceptance** | Any implementation/code; marketplace; autonomous AI trading; redesign of Screener condition DSL |
| **Benefits** | One reuse/share/AI model; clearer registries; safer generation; future sharing without rewrite |
| **Trade-offs** | Migration to bindings/versions; temporary dual UX (Save-in-place vs library) |
| **Spec** | [`../indicators/11-Trading-Artifact-Framework.md`](../indicators/11-Trading-Artifact-Framework.md) · [`../domains/Trading-Artifact-Framework-Specification.md`](../domains/Trading-Artifact-Framework-Specification.md) |
| **Relationship** | Extends SD-027/028/030/033; does not supersede Indicator Registry — specializes it under a common envelope |
| **Status** | **Accepted** (design / specification). Implementation phased via PRODUCT_BACKLOG (PB-058+) |

---

### SD-035 — Formal V1 Scope Freeze (Resolve Ambiguous Capabilities)

| Field | Content |
|-------|---------|
| **Category** | Governance / Product scope |
| **Date** | 2026-08-09 |
| **Original state** | Post-implementation audit (2026-08-09) recorded **15 capabilities** as `V1_SCOPE_AMBIGUOUS` because they were implemented but neither explicitly included nor excluded in `MVP_SCOPE.md`. Formal V1 scope counted **115** governance-aligned required capabilities |
| **Decision** | Product-owner approval **freezes V1 scope** by promoting **four** ambiguous capabilities to formal V1 and deferring **eleven** to V2/future. This resolves the ambiguity; it does **not** authorize new implementation |
| **Formally included in V1** | **F004** Password reset — basic account lifecycle (Sanctum unchanged; not JWT/SSO). **F020** Corporate actions — core split/bonus handling and ledger correctness (not F043 price-repair tooling). **F058** Screener backtesting — hit-matrix validation in screener editor for Strategy eligibility screeners (SD-030). **F093** Strategy backtesting — **currently shipped** historical paper-portfolio simulation (`BacktestSimulationEngine`, `/backtests`); supersedes treating this only as an SD-027 “future extension”; future enhancements (benchmark comparison, market gates in backtest, intraday simulation, advanced fees/slippage) remain V2 |
| **Formally deferred to V2 / future** | **F003** User invite flow; **F005** Session management; **F014** Historical holdings reconstruction; **F019** Bulk CSV import; **F042** Data quality detection/resolution; **F043** Corporate action price repair; **F060** Shared screener import; **F127** Portfolio alerts (non-TOS); **F137** Recommendation preview API; **F143** In-app contextual help; **F144** Knowledge Board |
| **Rationale** | Capabilities were already implemented; ambiguity was a **documentation/governance** gap, not a missing-work gap. Promotion describes **current** shipped behaviour only |
| **Implementation impact** | **None** — no code, schema, API, UI, or test changes required by this decision |
| **Audit relationship** | [2026-08-09 feature coverage audit](../../../docs/audits/2026-08-09-feature-coverage/) preserved as pre-decision baseline; [V1-SCOPE-DECISION.md](../../../docs/audits/2026-08-09-feature-coverage/V1-SCOPE-DECISION.md) records the approved decision |
| **Spec** | [`./MVP_SCOPE.md`](./MVP_SCOPE.md) § Included Features (SD-035) and § Deferred to V2 / Future |
| **Status** | **Accepted** (product-owner decision) |

---

### Post-V3 product decisions (not SD-xxx)

V4 Product Owner rules **V4-SPEC-001 through V4-SPEC-006** are recorded in [`../../LidoPortfolio-V4-Wishlist.md`](../../LidoPortfolio-V4-Wishlist.md) §4 (`DECIDED` 2026-08-26; **not implemented**). This register remains the V1.0 intent-vs-implementation SD log. Do **not** copy those six rules here as new SD rows.

---

## Change control

1. New deviations require a new **SD-xxx** entry (or status change to Superseded/Rejected).  
2. Do **not** rewrite historical architecture/engine specs to erase intent. Additive evolution docs (e.g. Indicator Registry, Trading Artifact Framework) are allowed **when they specialize or extend** without creating a second definition of an existing Approved concept — see [ARCHITECTURE_REPOSITORY_GOVERNANCE.md](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §7.  
3. Scope of what ships is governed by [`./MVP_SCOPE.md`](./MVP_SCOPE.md).  
4. Deferred items are tracked in [`./PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md).
5. New specifications MUST obey the repository Golden Rule: reference the canonical Approved concept; do not redefine it.
