# Version 1.0 MVP Scope

**Document:** Governance — MVP Scope  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  
**Scope freeze amended:** 2026-08-09 (SD-035)

Related: [`./SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) · [`./PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md) · [`./VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) · [`./ARCHITECTURE_REPOSITORY_GOVERNANCE.md`](./ARCHITECTURE_REPOSITORY_GOVERNANCE.md) · Demo [`../../MVP_DEMO_CHECKLIST.md`](../../MVP_DEMO_CHECKLIST.md)

---

## Objectives

Version 1.0 delivers a **Stage 1 decision-support Trading Operating System** inside Lido Portfolio:

1. Transform market data into explainable, ranked opportunities.  
2. Produce position-aware recommendations (Market Opinion + Portfolio
   Decision + Ranking + Capital Allocation + Execution Plan).  
3. Require human **Approve** / Reject / Defer before a trade may be
   recorded **for actionable portfolio decisions**; HOLD_POSITION / WATCH
   are informational and auto-published. Approval does **not** execute
   (SD-025).  
4. Support **delayed / manual execution**: pending-execution queue,
   execute later via Transactions, cancel pending execution, and
   recommendation↔transaction traceability (`recommendation_id`).  
5. Track **cash balance**, **reserved cash**, and **available investable
   cash**; allocate capital portfolio-wide on generation (SD-026).  
6. Notify via Telegram and retain delivery history.  
7. Review outcomes and basic performance without manual database edits.

Version 1.0 is **not** automated brokerage, multi-strategy isolation, or a greenfield rewrite.

---

## Included Features

### Data Engine

- Security master via `portfolio_stocks`
- OHLCV via `portfolio_stock_prices` and existing sync providers
- Dataset status / soft `dataset_version` (latest price date)
- Trigger/query import status through DataEngine and `/api/v1`
- Reuse of existing daily / universe sync UX for market data refresh

### Discovery Engine

- Discovery runs persisted (`portfolio_tos_discovery_runs`)
- Candidates from patterns, recent screener hits, holdings/watchlist membership
- Evidence JSON on candidates
- Candidates UI (`/candidates`) with filters and evidence viewer
- APIs: `POST /discovery/runs`, `GET /candidates`

### Evaluation Engine

- Evaluation runs and ranked results
- Indicators via existing technical / relative-strength services
- Configurable weighted scoring (`config/trading_os.php`)
- Passed/failed rules and evidence on results
- Deterministic ranking (covered by feature tests)
- Evaluations UI (`/evaluations`)

### Recommendation Engine

- Five-stage model: **Market Opinion** → **Portfolio Decision** →
  **Ranking** → **Capital Allocation** → **Trade gen** (SD-023 / SD-026)
- Portfolio actions: OPEN / INCREASE / REDUCE / EXIT / HOLD_POSITION / WATCH (UI: Buy / Buy More / Sell Partial / Sell All / Hold / Watch)
- **Portfolio-wide capital allocation** vs available investable cash;
  pluggable `CapitalAllocationStrategy` (default ScorePriority);
  unfunded OPEN/INCREASE demoted to WATCH
- **Actionable** decisions: Approve / Reject / Defer; Approve → `pending_execution` (not an immediate trade) (SD-025)
- Buy Approve **reserves** cash; cancel/expire/reopen **releases**; execute **converts** (SD-026)
- **Informational** (HOLD_POSITION / WATCH) auto-published; no pending execution
- Position-aware: uses holdings + allocation toward configured target / max position %
- Review history for actionable decisions
- Recommendations UI: **Trade recommendations** vs **Market insights**; **Pending Execution** tab

### Cash Management (SD-026)

- Cash account + ledger per portfolio profile
- Deposit / withdraw / adjust (`reason` required); buy/sell posts from trades
- Derived reserved cash from pending-execution buy reservations
- APIs: `GET /api/cash`, ledger, deposit, withdraw, adjust

### Notification Engine

- Telegram delivery for recommendation events
- Idempotent notification records, retry, history UI (`/notification-history`)
- Optional skip when Telegram not configured (pipeline still completes)

### Execution Engine

- Pending-execution queue for approved actionable recommendations
- Manual execute via **Transactions** page / `POST /api/transactions` with `recommendation_id`
- Cancel pending execution (recommendation → `cancelled`) without Reject semantics
- Traceability: transaction `source` + `recommendation_id`; recommendation stores executed link
- Shared ledger write via `TransactionWriteService` (SD-021)
- Legacy `/api/v1/orders*` retained; `execute_now` defaults false; **no new Orders page**
- Positions / transactions query via ExecutionEngine APIs

### Review Engine

- Review reports and metrics persistence
- Dashboard: portfolio snapshot; **actionable** accept/reject/pending/executed counts; separate **informational** published/expired counts
- Separate actionable vs insight outcome tables
- Recent review decisions for BUY/SELL only
- Recent review decisions
- APIs: generate/list/show reviews, dashboard, outcomes
- Review UI (`/review`)

### Strategy Configuration (SD-027 / SD-028 / SD-029)

- Fixed supported-indicator catalogue (not plugins)
- Versioned Strategy config: scoring, thresholds, portfolio rules,
  capital allocation, cash rules, exit strategy, recommendation behaviour
- **Eligibility via Screeners only** (SD-030) — Strategy references Screener IDs
- **Factory Minervini Strategy** (default seed) + Minervini Trend Template Screener
- Enabled scoring weights must sum to exactly **100** (auto-normalised on save)
- Strategy UI (`/strategy`) — one editable strategy; Save in place
- APIs: `/api/v1/strategy*`, `PUT /strategy/screeners`

### Auth & Account Lifecycle (SD-035)

- Self-service **password reset** (token link flow; Sanctum session auth unchanged)
- Does **not** include JWT, SSO, advanced RBAC, or multi-tenant identity architecture

### Portfolio Ledger (SD-035)

- **Corporate actions** — split and bonus handling, corporate-action sync, apply via UI; maintains holdings/ledger correctness for position-aware recommendations
- Does **not** include Data Quality Center (detection/resolution), corporate-action **price repair** ops tooling, or hard data publish gates (see deferred items below)

### Screener Authoring (SD-035)

- **Screener backtesting** — historical weekday hit matrix in the screener editor; validates screener eligibility rules referenced by Strategy (SD-030)
- Current capability only (resumable chunked backtest, persisted hit matrix)
- Does **not** include benchmark comparison, historical market gates in backtest, intraday simulation, or fees/slippage enhancements beyond current implementation

### Strategy Research (SD-035)

- **Strategy backtesting** — historical paper-portfolio simulation of the pinned active strategy (`BacktestSimulationEngine`, `/backtests`, resumable chunked runs, equity curve and statistics for the current implementation)
- Does **not** include market gates in strategy backtest, benchmark comparison, intraday stop-loss simulation, or advanced fees/slippage unless already supported in the shipped code

### Frontend

- TOS pages in main nav: Discovery, Evaluations, Recommendations, Strategy,
  Cash, Review, Notifications
- Sanctum-authenticated SPA consumption of `/api/v1`
- Legacy pages retained for holdings, watchlists, screeners, sync, analytics

### Backend

- Engine layer under `App\Engines\*`
- `DailyDecisionPipeline` + `portfolio:decision-pipeline` command
- Additive REST `/api/v1` (`TradingOsController`, ApiEnvelope)
- Config `trading_os.php`
- Migrations for `portfolio_tos_*` tables
- Feature tests: `TradingOsPipelineTest`

### Infrastructure

- Existing Laravel + MySQL + cPanel deploy model
- Optional scheduled pipeline when `TRADING_OS_PIPELINE_SCHEDULE=true`
- Logging via existing PortfolioLoggerService

---

## Explicitly Excluded Features

Intentionally **out of Version 1.0** (see backlog for targeting):

| Area | Excluded |
|------|----------|
| Strategy | Multi-strategy isolation / A-B comparison product (single active strategy + factory baseline ships) |
| Auth | JWT Bearer (Sanctum used instead) |
| Data | Hard publish/validation gates, trading calendar product, immutable dataset snapshots |
| Discovery | Dedicated Discovery Engine Spec document as a deliverable; full-universe mandatory scan |
| Evaluation | Market regime, ML scoring, pluggable rules engine product |
| Notification | Email, webhook, SMS, push, multi-channel preference center |
| Execution | Broker automation (Zerodha etc.), GTT, partial fills, stop/target orders; broker cash reconciliation |
| Cash | Alternate capital optimisers beyond ScorePriority (future pluggable strategies) |
| Review | Full attribution, tax reporting, AI insights, dedicated Reports UI |
| Architecture | Repository/DTO refactor, interface-only engines |
| Indicators | Unified Indicator Registry **full** delivery (SD-033; Epics 1–2 metadata/façades landed; Admin UI + later phases PB-055+); Liquidity/Tradability calculators (PB-057); Strategy-param→Evaluation wiring (PB-054) |
| Artifacts | Trading Artifact Framework **implementation** (SD-034 design accepted; PB-058+ — Screener/Strategy artifact registries, packages, AI catalogue) |
| API | OpenAPI generation, fine-grained RBAC matrix |
| Frontend | Mandatory TypeScript migration, TanStack Query, AG Grid as platform standards |
| Markets | Options, crypto, ETF-specific products (beyond equities already supported) |
| Product | AI assistant, mobile app, Trusted Automation stage |

The following **shipped capabilities** are explicitly **deferred to V2 / future** (SD-035). They remain in the codebase but are **not** part of frozen V1 scope:

| ID | Capability | Notes |
|----|------------|-------|
| F003 | User invite flow | Platform admin onboarding |
| F005 | Session management (list/revoke) | Admin security; partial UI |
| F014 | Historical holdings reconstruction | Standalone analytics; distinct from V1 portfolio snapshots (F015) |
| F019 | Bulk CSV import | Transaction data-entry convenience |
| F042 | Data quality detection/resolution | Data Quality Center admin subsystem |
| F043 | Corporate action price repair | Ops repair tooling (F020 core corporate actions remain V1) |
| F060 | Shared screener import | Cross-portfolio screener sharing |
| F127 | Portfolio alerts (non-TOS) | Alert policies parallel to TOS Telegram notifications |
| F137 | Recommendation preview API | Strategy analysis / preview tooling |
| F143 | In-app contextual help | Documentation UX layer |
| F144 | Knowledge Board | Separate Knowledge product area |

---

## Success Criteria

Version 1.0 deployment is successful when:

1. Market data can be synced and is visible to discovery/evaluation.  
2. Discovery produces candidates with evidence in the UI.  
3. Evaluation produces ranked, explainable scores.  
4. Pipeline or generate path produces recommendations in `pending_review`
   (capital allocation respects available investable cash).  
5. User can Approve / Reject / Defer with persisted history (Approve → pending execution; buy Approve reserves cash).  
6. Pending-execution items can be executed manually (Transactions + `recommendation_id`), cancelled, or left delayed; holdings + cash update on execute.  
7. Telegram notifications are delivered **or** intentionally skipped with history empty-state.  
8. Review dashboard shows outcomes consistent with the session.  
9. Fresh install includes active factory Momentum Strategy — no manual strategy configuration required to generate recommendations.  
10. No manual SQL is required for the demo path (`../MVP_DEMO_CHECKLIST.md`).  
11. `TradingOsPipelineTest` passes in CI/local.

---

## Exit Criteria

Version 1.0 is **complete** when all of the following are true:

| # | Criterion |
|---|-----------|
| E1 | Included features above are present in the codebase baseline |
| E2 | Accepted deviations recorded in [`./SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) |
| E3 | Independent audit pack exists under `specs/architecture/audit/` with clarified-MVP verdict **YES** |
| E4 | [`./VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) is published |
| E5 | Deferred work captured in [`./PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md) |
| E6 | Demo checklist can be executed on a configured environment |
| E7 | Original architecture/engine specs remain unmodified as intent (except approved append-only roadmap note) |

**Release posture** for production users is separate: see baseline recommendation (**Internal Testing Only** until soak + hardening).
