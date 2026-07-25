# Version 1.0 MVP Scope

**Document:** Governance — MVP Scope  
**Version:** 1.0  
**Status:** Active  
**Effective:** 2026-07-25  

Related: [`SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) · [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md) · [`VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) · Demo [`../MVP_DEMO_CHECKLIST.md`](../MVP_DEMO_CHECKLIST.md)

---

## Objectives

Version 1.0 delivers a **Stage 1 decision-support Trading Operating System** inside Lido Portfolio:

1. Transform market data into explainable, ranked opportunities.  
2. Produce BUY / SELL / WATCH / HOLD recommendations with evidence.  
3. Require human Accept / Reject / Defer before recording trades.  
4. Record manual executions against the existing portfolio ledger.  
5. Notify via Telegram and retain delivery history.  
6. Review outcomes and basic performance without manual database edits.

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

- BUY / SELL / WATCH / HOLD generation from evaluation results
- Confidence, priority, risk level, suggested size, expiry, reference price
- Lifecycle: `pending_review` → accept / reject / defer → executed / cancelled / expired
- Recommendation review history (`portfolio_tos_recommendation_reviews`)
- Recommendations UI with Accept / Reject / Defer and detail evidence

### Notification Engine

- Telegram delivery for recommendation events
- Idempotent notification records, retry, history UI (`/notification-history`)
- Optional skip when Telegram not configured (pipeline still completes)

### Execution Engine

- Orders linked to accepted recommendations
- Lifecycle: pending → executed | cancelled
- Fill writes `portfolio_transactions` and updates holdings via existing services
- Order link table `portfolio_tos_order_transactions`
- Positions / transactions query via ExecutionEngine APIs
- Order actions on Recommendations and Review pages

### Review Engine

- Review reports and metrics persistence
- Dashboard: portfolio snapshot, accept/reject counts, recommendation outcomes
- Recent review decisions
- APIs: generate/list/show reviews, dashboard, outcomes
- Review UI (`/review`)

### Frontend

- Five TOS pages in main nav: Candidates, Evaluations, Recommendations, Review, Notify log
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
| Strategy | Strategy entity, multi-strategy isolation/comparison |
| Auth | JWT Bearer (Sanctum used instead) |
| Data | Hard publish/validation gates, trading calendar product, immutable dataset snapshots |
| Discovery | Dedicated Discovery Engine Spec document as a deliverable; full-universe mandatory scan |
| Evaluation | Market regime, ML scoring, pluggable rules engine product |
| Notification | Email, webhook, SMS, push, multi-channel preference center |
| Execution | Broker automation (Zerodha etc.), GTT, partial fills, stop/target orders |
| Review | Full attribution, tax reporting, AI insights, dedicated Reports UI |
| Architecture | Repository/DTO refactor, interface-only engines |
| API | OpenAPI generation, fine-grained RBAC matrix |
| Frontend | Mandatory TypeScript migration, TanStack Query, AG Grid as platform standards |
| Markets | Options, crypto, ETF-specific products (beyond equities already supported) |
| Product | AI assistant, mobile app, Trusted Automation stage |

---

## Success Criteria

Version 1.0 deployment is successful when:

1. Market data can be synced and is visible to discovery/evaluation.  
2. Discovery produces candidates with evidence in the UI.  
3. Evaluation produces ranked, explainable scores.  
4. Pipeline or generate path produces recommendations in `pending_review`.  
5. User can Accept / Reject / Defer with persisted history.  
6. Accepted recommendations can become pending/executed/cancelled orders that update holdings.  
7. Telegram notifications are delivered **or** intentionally skipped with history empty-state.  
8. Review dashboard shows outcomes consistent with the session.  
9. No manual SQL is required for the demo path (`../MVP_DEMO_CHECKLIST.md`).  
10. `TradingOsPipelineTest` passes in CI/local.

---

## Exit Criteria

Version 1.0 is **complete** when all of the following are true:

| # | Criterion |
|---|-----------|
| E1 | Included features above are present in the codebase baseline |
| E2 | Accepted deviations recorded in [`SPECIFICATION_DECISIONS.md`](./SPECIFICATION_DECISIONS.md) |
| E3 | Independent audit pack exists under `specs/audit/` with clarified-MVP verdict **YES** |
| E4 | [`VERSION_1_BASELINE.md`](./VERSION_1_BASELINE.md) is published |
| E5 | Deferred work captured in [`PRODUCT_BACKLOG.md`](./PRODUCT_BACKLOG.md) |
| E6 | Demo checklist can be executed on a configured environment |
| E7 | Original architecture/engine specs remain unmodified as intent (except approved append-only roadmap note) |

**Release posture** for production users is separate: see baseline recommendation (**Internal Testing Only** until soak + hardening).
