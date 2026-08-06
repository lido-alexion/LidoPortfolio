# Specification Traceability Matrix

**Audit date:** 2026-07-25  
**Scope:** All documents under `/specs`  
**Implementation root:** `app/` (Laravel + React)  
**Clarifications applied:** `specs/IMPLEMENTATION_PROGRESS.md` assumptions A1–A13 (accepted deviations)

**Status legend:** Complete | Partially Complete | Not Implemented | Intentionally Deferred

---

## How MVP was judged

Mandatory MVP is defined by:

1. `specs/architecture/engines/Implementation-Roadmap.md` §9 Success Criteria  
2. `specs/MVP_DEMO_CHECKLIST.md` acceptance walkthrough  
3. Accepted scope reductions in `IMPLEMENTATION_PROGRESS.md` (Sanctum, `portfolio_*`, Telegram-only, Strategy deferred, formal Data publish gates deferred)

Full written specs contain aspirational / Stage-2+ requirements. Those are marked **Intentionally Deferred** when they conflict with A1–A13, not as MVP blockers.

---

## 1. `architecture/platform/01-Vision.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Decision-support platform (Stage 1) | Complete | Engines + UI review/execution flow | None |
| Explainable recommendations | Complete | Evaluation evidence + recommendation detail UI | None |
| Decision/outcome history | Complete | `portfolio_tos_recommendation_reviews`, Review dashboard | None |
| Multi-strategy experimentation | Intentionally Deferred | A12 — no Strategy entity | Acceptable for MVP |
| Broker automation | Intentionally Deferred | Manual order lifecycle only (A10) | Acceptable |
| Not a black-box / not autonomous bot | Complete | User Accept required before order | None |

---

## 2. `architecture/platform/02-Guiding-Principles.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Trust over automation | Complete | Pending review → Accept → order | None |
| Explainability (why, passed/failed, evidence, rank) | Complete | Evaluation Details modal; recommendation evidence | Ranking of alternatives shown via score/rank |
| Deterministic behaviour | Complete | Weighted scoring; `test_evaluation_scores_are_deterministic` | None |
| Human remains in control | Complete | Accept/Reject/Defer; cancel order | None |
| Engine-oriented design | Complete | `app/app/Engines/*` | UI pages are thin API consumers |
| Single source of truth | Partially Complete | Holdings/tx via existing services; TOS wrappers | Some dual paths (legacy `/api` + `/api/v1`) — acceptable |
| Incremental evolution Stage 1 | Complete | Manual execution | None |

---

## 3. `architecture/platform/03-Core-Concepts.md`

| Concept | Status | Evidence | Deviation |
|---------|--------|----------|-----------|
| Strategy | Intentionally Deferred | A12 | Acceptable |
| Universe / Stock / Market Data | Complete | `portfolio_stocks`, `portfolio_stock_prices`, DataEngine | Physical names differ |
| Indicator | Complete | `TechnicalIndicatorService` via EvaluationEngine | Owned in Services, used by Evaluation |
| Pattern / Signal | Complete | `PatternScanService` via DiscoveryEngine | No dedicated Discovery Engine Spec file (A4) |
| Rule | Partially Complete | Hard-coded evaluation rules in engine | Not a reusable rules catalog entity |
| Candidate | Complete | `portfolio_tos_candidates` | None |
| Ranking | Complete | `rank` on evaluation results | None |
| Evidence | Complete | JSON on candidates/results/recommendations | Owner split: Discovery/Evaluation write; Recommendation aggregates |
| Recommendation BUY/SELL/WATCH/HOLD | Complete | `RecommendationEngine::decideType` | Status model enhanced (see Rec Engine) |
| Position / Transaction | Complete | `portfolio_holdings`, `portfolio_transactions` + TOS orders | Reused tables (A2) |
| Alert | Partially Complete | Legacy alert system exists; TOS uses notifications | Alert concept not owned by Notification Engine for TOS |
| Notification (Telegram primary) | Complete | NotificationEngine + Telegram | Email/webhook deferred (A9) |
| Review | Complete | ReviewEngine + `/review` | None |

---

## 4. `architecture/platform/04-System-Architecture.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Seven engines with ownership | Complete | Data, Discovery, Evaluation, Recommendation, Notification, Execution, Review | Pipeline orchestrator extra — OK |
| Dependency flow Data→…→Review | Complete | DailyDecisionPipeline stage order | ExecutionEngine updates recommendation status (mild cross-write) — see Architecture report |
| Notification never creates recommendations | Complete | NotificationEngine only delivers | None |
| Review never modifies upstream | Complete | Read-only metrics/reports | None |
| Broker / Telegram outputs | Partially Complete | Telegram yes; broker automation no | Intentional Stage 1 |

---

## 5. `architecture/platform/05-Daily-Decision-Pipeline.md`

| Stage | Status | Evidence | Deviation |
|-------|--------|----------|-----------|
| 1 Market Data Sync | Complete | Existing daily sync + DataEngine::triggerImport | Pipeline stage reads status; does not always sync |
| 2 Data Validation | Intentionally Deferred | A13 formal gates | Soft `published` ≈ synced_today |
| 3 Derived Calculations | Partially Complete | Done inside EvaluationEngine, not separate stage | Acceptable collapse |
| 4 Pattern Discovery | Complete | DiscoveryEngine + PatternScanService | None |
| 5 Screening | Complete | Screener hits within lookback | Depends on prior screener runs |
| 6 Candidate Generation | Complete | Candidates persisted | None |
| 7 Evaluation & Ranking | Complete | EvaluationEngine | None |
| 8 Position Review | Partially Complete | Held→SELL/HOLD by score; pipeline only counts positions | No stop-loss / exit-condition assessment |
| 9 Recommendation Generation | Complete | RecommendationEngine | None |
| 10 Notification Delivery | Complete | Telegram when configured | Skippable |
| 11 User Review Accept/Reject/Defer | Complete | UI + API | Spec status names differ |
| 12 Trade Execution | Complete | Manual via ExecutionEngine | Not broker API |
| 13 Trade Recording | Complete | Orders + transactions + holdings | None |
| 14 Performance Review | Complete | ReviewEngine generate + dashboard | Strategy comparison deferred |

**Auditable trail:** `portfolio_tos_pipeline_runs.stages_json` — Complete.

---

## 6. `architecture/platform/06-Engine-Overview.md`

| Engine | Status | Evidence |
|--------|--------|----------|
| Data | Partially Complete | Wrap of existing sync; formal publish incomplete (A13) |
| Discovery | Complete | DiscoveryEngine |
| Evaluation | Partially Complete | Scoring/ranking yes; market regime no |
| Recommendation | Complete | Lifecycle + evidence |
| Notification | Partially Complete | Telegram only (A9) |
| Execution | Complete (Stage 1) | Manual orders; broker deferred |
| Review | Complete | Reports + dashboard + outcomes |

---

## 7. `engines/Application-Architecture-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| React + Bootstrap + Router | Complete | SPA under `resources/js/src` | JS not TypeScript |
| TanStack Query / AG Grid | Not Implemented | Direct axios in pages | Acceptable reuse of existing SPA patterns |
| Chart.js / Lightweight Charts | Partially Complete | Recharts used elsewhere | Not TOS-specific |
| PHP MVC + Engines | Complete | Controllers → Engines → Models/Services | Folder is `app/` not `backend/` |
| JWT auth | Intentionally Deferred | Sanctum session (A1) | **Accepted** |
| Repositories layer | Not Implemented | Eloquent in engines | Deviation — should remain documented; fix optional |
| Frontend features/store/layouts/types | Not Implemented | Flat pages/components | Acceptable for MVP |
| Structured logging fields | Partially Complete | PortfolioLoggerService used | Request ID / engine fields inconsistent |
| Background jobs list | Partially Complete | Pipeline command + existing sync/notify schedules | Discovery/eval not separate cron jobs (pipeline covers) |
| Unit + engine + API tests | Partially Complete | Feature test `TradingOsPipelineTest` | No Vitest; thin unit coverage of engines |
| OpenAPI | Not Implemented | — | Future |
| GoDaddy/cPanel deploy | Complete | Existing deploy skill/scripts | TOS migrations need upload |

---

## 8. `engines/Data-Engine-Specification.md`

| Requirement | Status | Evidence | Notes |
|-------------|--------|----------|-------|
| Acquire historical/daily OHLCV | Complete | Existing NSE/Yahoo/AV sync | Via wrapped services |
| Stock master / metadata | Complete | `portfolio_stocks` | — |
| Validate datasets / missing sessions / duplicates | Intentionally Deferred | A13 | Soft status only |
| Publish trusted datasets | Intentionally Deferred | A13 | `dataset_version` = latest price date string |
| Import history | Partially Complete | `SyncRun` / imports API | Not full Import Job state machine |
| Trading calendar product | Intentionally Deferred | — | — |
| DR-001…DR-008 business rules | Partially Complete | Uniqueness via existing schema; formal publish DR-004/008 deferred | — |
| Public: Trigger Import, Dataset Status, Import History | Complete | DataEngine + `/api/v1/*` | Trading Calendar API missing |
| Acceptance: invalid never published | Intentionally Deferred | A13 | Risk if bad data syncs |

**Backend:** `app/app/Engines/Data/DataEngine.php`  
**APIs:** `/api/v1/securities`, `/price-bars`, `/dataset/status`, `/imports`  
**Tables:** `portfolio_stocks`, `portfolio_stock_prices`, `portfolio_sync_runs`  
**UI:** Legacy holdings/dashboard sync (no dedicated TOS Data page)

---

## 9. Discovery (no dedicated engine spec — A4)

| Requirement | Status | Evidence |
|-------------|--------|----------|
| Pattern detection | Complete | PatternScanService scopes holdings/watchlist |
| Screening | Complete | Recent ScreenerRunHit merge |
| Candidate generation | Complete | DiscoveryEngine::run |
| Membership fallback | Complete | Holdings/watchlist candidates |
| Candidates UI | Complete | `/candidates` |

**Tables:** `portfolio_tos_discovery_runs`, `portfolio_tos_candidates`  
**APIs:** `POST /discovery/runs`, `GET /candidates`

---

## 10. `engines/Evaluation-Engine-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Indicators SMA/ATR/RS/volume | Complete | TechnicalIndicatorService + RelativeStrengthService | EMA/52w distance partial via available indicators |
| Rule evaluation + failed rules | Complete | passed_rules / failed_rules | Hard-coded, not pluggable |
| Market regime assessment | Not Implemented | — | Future / A8 scope |
| Scoring + ranking + confidence | Complete | EvaluationEngine | Deterministic sort tie-break by candidate id |
| Evidence | Complete | evidence JSON | — |
| EV-001…EV-006 | Complete | One result per candidate; reproducible test | Published dataset gate soft |
| Query evaluation history | Partially Complete | Latest results list; runs stored but no rich history UI | Acceptable |
| Evaluations UI | Complete | `/evaluations` | — |

**Tables:** `portfolio_tos_evaluation_runs`, `portfolio_tos_evaluation_results`

---

## 11. `engines/Recommendation-Engine-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| BUY/HOLD/SELL/WATCH | Complete | decideType | — |
| Confidence, priority, risk, size, expiry | Complete | Model fields | `max_position_pct` config unused |
| Evidence + failed checks | Complete | — | — |
| RC-001…RC-007 | Complete | Linked to evaluation_result; executed immutable for review | — |
| State: Draft→Active→Executed | Partially Complete | Uses `pending_review` / `accepted` / `rejected` / `deferred` / `executed` / `cancelled` / `expired` | **Acceptable** — implements pipeline Stage 11 |
| User review workflow | Complete | RecommendationReview + UI | Beyond original Rec state model |
| Generate / query APIs | Complete | + review endpoints | — |

**Tables:** `portfolio_tos_recommendations`, `portfolio_tos_recommendation_reviews`  
**UI:** `/recommendations`

---

## 12. `engines/Notification-Engine-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Deliver recommendation notifications | Complete | notifyRecommendations | Requires Telegram config |
| Multiple channels (email/webhook) | Intentionally Deferred | A9 Telegram only | Accepted |
| Retries | Complete | retry API + max_retries | — |
| History | Complete | history + `/notification-history` | — |
| Idempotency NT-002 | Complete | idempotency_key | — |
| Channel interface abstraction | Partially Complete | Hardcoded telegram | Config lists channels but code does not iterate |
| System notifications (non-rec) | Partially Complete | Type field exists; pipeline focuses on recs | — |

---

## 13. `engines/Execution-Engine-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Record manual executions | Complete | recordOrder / executeOrder | — |
| Order lifecycle Pending→Executed/Cancelled | Complete | + cancel | Rejected status unused |
| Positions + transactions | Complete | Holdings + portfolio_transactions + order link | Positions table = holdings (A2) |
| EX-001 transaction↔order | Partially Complete | Link via `portfolio_tos_order_transactions`; legacy txs without orders remain | Acceptable hybrid |
| Broker integration | Intentionally Deferred | A10 | — |
| APIs create/list orders, positions, txs | Complete | + execute/cancel extras | Beyond REST draft |
| UI order actions | Complete | Recommendations + Review pages | No dedicated Orders page |

---

## 14. `engines/Review-Engine-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| Recommendation outcomes | Complete | reference vs current price | — |
| Portfolio metrics | Complete | value, XIRR via PortfolioCalculationService | — |
| Win rate / profit factor / expectancy | Complete | ReviewEngine::generate metrics | — |
| Drawdown / execution quality depth | Partially Complete | Limited metric set | — |
| Reports + list/show APIs | Complete | review_reports / review_metrics | No dedicated Reports UI |
| Dashboard UI | Complete | `/review` | — |
| Strategy comparison | Intentionally Deferred | A12 | — |
| RV-001…RV-005 immutability | Complete | Observational | — |

---

## 15. `engines/REST-API-Specification.md`

| Requirement | Status | Evidence | Deviation |
|-------------|--------|----------|-----------|
| `/api/v1` versioning | Complete | routes/api.php | — |
| Envelope success/error | Complete | ApiEnvelope | — |
| JWT Bearer | Intentionally Deferred | Sanctum (A1) | Accepted |
| All module endpoints in §5 | Complete | Implemented | — |
| Extras: review decision, order execute/cancel, dashboard, pipeline | Complete | Added for MVP | Spec lag (positive) |
| Pagination/sort/filter | Partially Complete | Some list endpoints paginate; many return arrays | — |
| Idempotency keys on import/generate/retry | Partially Complete | Notifications yes; import/generate soft | — |
| OpenAPI | Not Implemented | — | — |
| RBAC roles | Partially Complete | Sanctum user + active.portfolio | No fine-grained roles on v1 |

---

## 16. `engines/Database-Schema-Specification.md`

| Spec table | Physical | Status |
|------------|----------|--------|
| securities | `portfolio_stocks` | Complete (mapped) |
| trading_sessions | — | Intentionally Deferred / not separate |
| price_bars | `portfolio_stock_prices` | Complete (mapped) |
| import_jobs | `portfolio_sync_runs` (approx) | Partially Complete |
| discovery_runs / candidates | `portfolio_tos_*` | Complete |
| evaluation_* | `portfolio_tos_*` | Complete |
| recommendations | `portfolio_tos_recommendations` | Complete (+ reviews table) |
| notifications | `portfolio_tos_notifications` | Complete |
| orders | `portfolio_tos_orders` | Complete |
| transactions (TOS) | `portfolio_tos_order_transactions` + `portfolio_transactions` | Complete (split) |
| positions | `portfolio_holdings` | Complete (mapped) |
| review_reports / metrics | `portfolio_tos_*` | Complete |
| pipeline_runs | `portfolio_tos_pipeline_runs` | Extra (OK) |

---

## 17. `engines/System-Domain-Model.md`

| Rule | Status | Notes |
|------|--------|-------|
| DM-001 one owner | Partially Complete | Execution updates Recommendation status |
| DM-002 owner-only modify | Partially Complete | Same |
| DM-003 consume via interfaces | Partially Complete | Engines call Eloquent of other domains | Acceptable pragmatism |
| Strategy/Watchlist/Portfolio as future | Complete | Watchlist/Portfolio already exist as legacy | Spec “future” already present |

---

## 18. `engines/Implementation-Roadmap.md`

| Milestone | Status | Notes |
|-----------|--------|-------|
| M0 Bootstrap | Complete | Existing app; Sanctum not JWT |
| M1 Data Engine | Partially Complete | Import works; formal validation deferred (A13) |
| M2 Discovery | Complete | — |
| M3 Evaluation | Complete | Market regime out |
| M4 Recommendation | Complete | + user review |
| M5 Notification | Partially Complete | Telegram only (A9); email/webhook deferred |
| M6 Execution | Complete | Manual |
| M7 Review | Complete | Dashboard + reports API |
| §9 MVP success criteria | Complete | See MVP_VERDICT.md |

---

## 19. `IMPLEMENTATION_PROGRESS.md` / `MVP_DEMO_CHECKLIST.md`

| Item | Status |
|------|--------|
| Pass 1 T0–T15 | Complete |
| Pass 2 M1–M9 | Complete |
| Demo steps 1–7 | Complete (code/UI present; runtime Telegram optional) |

---

## Summary counts (major requirements)

| Status | Approx. count |
|--------|---------------|
| Complete | Majority of MVP workflow requirements |
| Partially Complete | Data validation depth, position review, market regime, repos, pagination, multi-channel abstraction, TypeScript/frontend structure |
| Intentionally Deferred | JWT, Strategy, broker automation, email/webhook, formal dataset publish, trading calendar product |
| Not Implemented | OpenAPI, Vitest/E2E UI tests, TanStack Query/AG Grid as specified, dedicated Discovery Engine Spec document |

**Correct-now vs accept:** Accepted deviations (A1–A13) should not be “corrected” for MVP. Optional corrections: wire `pipeline.run_after_daily_sync`, enforce `max_position_pct`, deepen Position Review, add OpenAPI, introduce repository interfaces later.
