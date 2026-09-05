# implementation.md

Living reference for Lido Portfolio. **Update this file whenever code changes.**  
`design_doc.md` was removed (May 2026); requirements come from user chat + this file.

## Agent / documentation policy (May 2026)

- Do not use or recreate `design_doc.md` or removed phase/report/spec files.
- **Canonical docs:** `implementation.md` (technical), **`debugging.md`** (production debug hooks & agent runbook), `README.md` (quick start + **Features** overview), **`deploy/DEPLOY.md`** (production deploy & updates), **`.cursor/skills/deploy-cpanel/SKILL.md`** (agent deploy workflow), `DEPLOYMENT_VALIDATION_PLAN.md`, `portfolio-history-rebuild-report.md`, `app/API_DOCUMENTATION.md`, **`app/openapi/v1.json`** (`/api/v1` OpenAPI 3.0.3).
- Cursor rule `.cursor/rules/Always-update-implementation-details-in-implementation-md-file.mdc` enforces: read this file first; update it after code changes.
- Cursor rule `.cursor/rules/Keep-contextual-help-docs-in-sync.mdc` enforces keeping in-app contextual help (`appDocumentation.js` + routing links) updated for every feature add/change/delete.
- Persistent instructions across sessions: project rules in `.cursor/rules/` (`alwaysApply: true`) + optional User Rules in Cursor Settings.

## Architecture Notes

- Backend scaffold is Laravel (PHP) with API-first structure and service-layer business logic.
- Frontend: React + Bootstrap (Vite build served by Laravel).
- Web app runs as a React SPA mounted from Laravel view `resources/views/app.blade.php`. Favicon: `public/favicon.ico` with `<link rel="icon" href="{APP_PATH}/favicon.ico">` in `app.blade.php`; production copy at `public_html/portfolio/favicon.ico` (included in `prepare-upload.ps1` staging).
- API auth is **session-based** (Sanctum SPA cookies), not Bearer tokens in `localStorage`.

## Trading Operating System (Jul 2026)

Specs under `specs/` define a seven-engine decision platform. Implementation evolves the **existing** Lido Portfolio app (no greenfield rewrite). Progress: `specs/IMPLEMENTATION_PROGRESS.md`. Demo acceptance: `specs/MVP_DEMO_CHECKLIST.md`.

**MVP status:** Complete for clarified workflow (data → discovery → evaluation → recommendation → user **approval** → **pending execution** → manual/broker trade → review). Sanctum, `portfolio_*` tables, Screener/Pattern services, Telegram-only notifications accepted.

### V5 FEAT-039 execution coordinator (in progress, 2026-09-05)

- Actionable Recommendations persist an immutable IST generation anchor, first/second eligible NSE sessions, Day #2 cutoff, target amount, capital-resolved capacity, remaining gap, and internal/external progress.
- Submission is Investor/Kite-account scoped and idempotent. Scheduled cycles coordinate all Automatic portfolios; an authenticated Semi-Automatic action adds its explicitly selected approved intent to the same account cycle. Opposing same-symbol intent is internally matched first, then residual SELLs are submitted before BUYs in oldest-first order. Manual, unapproved, foreign-user, and unselected Semi-Automatic intent never enters the cycle.
- Internal matches write paired, zero-fee, no-cash ledger transactions plus a durable provisional transfer record. A guarded finalizer waits for residual orders to become terminal, then restates both ledger sides and Recommendation progress to residual WAVG fill price; when there is no fill it waits for the official session close.
- External fills reduce the monetary gap; completing one incremental broker order does not close a partially fulfilled Recommendation. New order quantity is floored from the remaining gap at the stored reference close and bounded by remaining capital.
- Before every residual BUY placement, the broker adapter refreshes the Investor account's current equity `available.live_balance` (falling back to documented `net`) and further floors quantity to those shared Kite funds. An unavailable funds response blocks the attempt; StoX never assumes pending SELL proceeds.
- Kite's documented structured `MarginException` maps to `BROKER_INSUFFICIENT_FUNDS`. BUY placement retries at most twice at successive whole-share floors of 95%; there is no message parsing and no retry for other or ambiguous failures.
- `tos:expire-execution-windows` atomically expires unresolved gaps after Day #2 cutoff while preserving in-flight Order Lifecycle.
- New FEAT-039 rows submit only on their frozen first or second eligible NSE session and only inside the configured 09:15–15:30 IST primary-order window; weekends and active Trade Holidays are rejected by the same calendar gate. Legacy rows remain compatible during migration.
- Execution-mode changes now reconcile unsubmitted FEAT-039 intent: switching to Manual cancels it with an audited reason, while Automatic→Semi-Automatic invalidates the automatic approval and returns it to review. Recommendations with an in-flight broker order stay under Order Lifecycle.
- Every FEAT-039 attempt revalidates the current stock and owning Strategy before matching or broker submission. An inactive stock, disabled/archived Strategy, or Strategy/profile ownership mismatch cancels unsubmitted intent with an audited reason and releases its reservation; an in-flight broker order remains governed by Order Lifecycle.
- A materially different target generated for the same Strategy and symbol atomically supersedes prior live intent across Strategy versions, links old→new for audit, and releases the old reservation. An unchanged target does not reset the lifetime.
- Recommendation API/detail UX exposes the immutable Recommendation date, first eligible date, expiry instant, authoritative target, capital-ready amount, internal/external execution, and remaining gap; it does not present this as user-selected future scheduling.
- After Day #1 closes, an unresolved approval or Kite-readiness blocker creates at most one approaching-expiry in-app/Telegram notification naming the real Day #2 cutoff. Atomic Day #2 expiry creates one separate informational notification with the fulfilled/unfulfilled decomposition; idempotency prevents notification storms.
- The minute lifecycle job re-derives still-future Day #1/Day #2 dates from the canonical Calendar, so newly corrected future Trade Holidays roll execution and expiry. The generation anchor and any eligible date whose IST day has begun are immutable.

**Independent audit (2026-07-25):** Full freeze audit vs `/specs` lives in [`specs/architecture/audit/`](specs/architecture/audit/). Verdict: clarified MVP **YES** (~90%); release posture **Ready for Internal Testing Only** — see `specs/architecture/audit/MVP_VERDICT.md`.

**Governance (2026-07-25):** Bridge between architectural intent and V1.0 code — [`specs/architecture/governance/`](specs/architecture/governance/). Precedence: [`DOCUMENT_PRECEDENCE.md`](specs/architecture/governance/DOCUMENT_PRECEDENCE.md). Baseline: [`VERSION_1_BASELINE.md`](specs/architecture/governance/VERSION_1_BASELINE.md). Do not rewrite original `/specs` architecture or engine docs to match implementation; record decisions as SD-xxx instead.

**V1 scope freeze (2026-08-09, SD-035):** Product-owner decision promotes four previously ambiguous capabilities to formal V1 — **F004** password reset, **F020** corporate actions (core; not F043 repair), **F058** screener backtesting, **F093** strategy backtesting (current shipped simulation). Eleven capabilities deferred to V2/future: F003, F005, F014, F019, F042, F043, F060, F127, F137, F143, F144. Authoritative scope: [`MVP_SCOPE.md`](specs/architecture/governance/MVP_SCOPE.md). Decision record: [`docs/audits/2026-08-09-feature-coverage/V1-SCOPE-DECISION.md`](docs/audits/2026-08-09-feature-coverage/V1-SCOPE-DECISION.md).

**Documentation map:** Repository-wide reading order and file tree — [`DOCS.md`](DOCS.md). Specs subtree — [`specs/README.md`](specs/README.md). Architecture hub (domain folders) — [`specs/architecture/README.md`](specs/architecture/README.md). For full ingest: requirements/architecture → governance → audit → this file. New Markdown docs must be indexed in `DOCS.md` (rule: `.cursor/rules/Keep-DOCS-md-ingestion-tree-updated.mdc`).

## V3 product-surface closure — Discovery nav + strategy lifecycle (2026-08-26)

**Status:** **DONE**. Product-surface / strategy-lifecycle only. Does **not** redesign WS2–WS4, §34.3–§34.4, OD-10/17, §10.4/§10.5, B4, or broker.

Post-deployment verification found Discovery still in the Market sidebar and no practical Create / Archive path for a second strategy, so multiple enabled strategies were not usable from the UI.

### A. Discovery navigation

- Catalog entry `id: 'discovery'` (`/candidates`) remains for direct routes, breadcrumbs, and pipeline inspection.
- **Removed from the left Market sidebar:** `showInSidebar: false`, `favouriteEligible: false` (not a runtime hide).
- Market still lists Stock Explorer, Patterns, Indices, Calendar, Market Depth.
- Recommendations toolbar no longer promotes Discovery as a workflow tab.

### B. Multi-strategy user journey

`CREATE → ENABLE → EDIT/SELECT → RUN CONCURRENTLY → ARCHIVE`

- **Create:** `POST /api/v1/strategy-registry` with `{name, description}` (no JSON) clones the factory/default configuration via `StrategyArtifactRegistry::createFromDefault`. Stores a **draft** (`STATUS_DRAFT`), unique slug, `origin=user`, no `factory_key`. UI **Create Strategy** on Strategy Registry and the Strategy editor opens the new draft in the editor.
- **Enable:** existing `POST /api/v1/strategy-registry/{id}/activate` — non-exclusive.
- **Edit/select:** editor selector + `?strategy_id=` / `PUT` `strategy_id`. Edit is available for every registry row.
- **Archive:** existing `POST /api/v1/strategy-registry/{id}/archive` (status transition, not physical delete). UI label is **Archive**. Archiving one strategy does not change siblings. **Cannot archive the last enabled strategy** (API 422 + UI disable) so `ensureActive` does not silently resurrect the factory.
- Registry is the management surface: all portfolio strategies, status, Create, Edit, Enable, Archive, allocation %. Copy no longer implies exclusive activation.
- JSON import/validate remains for authored packs; it is not required to create a normal strategy.

### Tests / docs

- PHP: `StrategyRegistryApiTest` create-from-default, two distinct records both enabled, edit B does not modify A, archive sibling, last-enabled archive rejected.
- JS: `tests/js/v3ProductSurface.test.mjs` + `strategyPageWsPsA.test.mjs` (WS-PS-A intact).
- Help: `appDocumentation.js` Create Strategy / Archive / Discovery-not-in-sidebar. Sidebar spec notes Discovery as an internal route.

## V3 §5.7 configurable lending limits (2026-08-26)

**Status:** **DONE**. Narrow AFL portfolio caps only. Does **not** redesign OD-19/20/21/24, lender ranking, or loan approval beyond reading already-capped `available_for_lending`.

### Ambiguity resolution

- Two optional portfolio settings (either or both; blank = no cap of that kind):
  - `max_lending_pct_of_unused` — percent of **unused_allocation** (0–100).
  - `max_lending_absolute` — absolute ₹ ceiling (≥ 0).
- Default both empty → current behaviour (full surplus after OD-24, then ₹5k floor) — backward compatible.
- Apply order: `raw = max(0, unused − retained − committed)`; then `if pct set: raw = min(raw, unused × pct/100)`; then `if abs set: raw = min(raw, abs)`; then `available_for_lending = FloorToRupee5000::floor(raw)`.
- Lent is already excluded via deployed/unused (do not subtract again).

### Surfaces

- `ProfileSettingsService::DEFAULTS` + `SettingsController` validation + Settings → Portfolio `NumberInput`s + help citing §5.7.
- Cap applied in `PortfolioCapitalAccountingService::snapshot()`.
- Tests: `V3CapitalLendingAccountingTest` (pct 50 / abs 10000 / both / empty); `ProfileSettingsTest` persistence/validation.
- `appDocumentation.js` Settings controls/concepts synced.

## Production migrate fix — import-batch index name (2026-08-26)

**Symptom:** `cpanel-migrate.php` failed with MySQL `1059 Identifier name too long` on  
`portfolio_transaction_import_batch_items_batch_id_sort_order_index` (68 chars; MySQL max 64).

**Fix:** `2026_08_09_180001_create_portfolio_transaction_import_batches_tables.php` now uses short explicit index names (`ptib_profile_batch_idx`, `ptibi_batch_row_uq`, `ptibi_batch_sort_idx`) and drops leftover empty tables from the failed run before recreate so migrate can be re-run safely.

## V3 §30 notification hooks + §19 SuccessCriteriaEvaluator (2026-08-26)

**Status:** Telegram domain + recommendation notify extended. No B4 banner. No lending formula redesign.

### §30 events (Telegram via `NotificationEngine` / `RecallNotificationService`)

| Event | Type | Emitter |
|-------|------|---------|
| Capital required (UNFUNDED / PARTIAL / AWAITING_LENDER BUY) | `capital_required` | `RecommendationLendingCoordinator::syncAfterGenerated` |
| Partial capital resolution (shortfall with actual > 0) | `capital_resolution_partial` | `CapitalResolutionService` (existing) |
| Lending commitment | `lending_commitment` | `CapitalRequestApprovalService::approve` |
| Lending failure (reject / revalidation) | `lending_failure` | `CapitalRequestApprovalService` |
| Capital committed / execution ready | `capital_committed` | `RecommendationLendingCoordinator::markCapitalCommitted` |
| Recall requested / pending-held / settlement | existing | `RecallService` (Phase 3B-2) |
| Recall Bridge Loan created / repaid | existing | `RecallBridgeLoanService` |
| Proceeds from stock sale applied | existing | `ProceedsApplicationService` |
| OPEN/INCREASE/REDUCE/EXIT recommendations | `recommendation` | `notifyRecommendations` (HOLD/WATCH still skipped) |
| EXIT with portfolio SL / trailing / horizon | `recommendation` (+ attribution line) | `NotificationMessageComposer::recommendationMessage` |

Unfunded OPEN/INCREASE remain `isActionable()` and **must not** inherit HOLD/WATCH Telegram skip. Messages include capital status / exit attribution when present.

### §19 opportunity-cost / success flags

- Setting `opportunity_cost_rate` remains portfolio source of truth (default `0.12`).
- `App\Services\Ranking\SuccessCriteriaEvaluator` implements §19 (positive return ∧ NIFTY beat ∧ period opportunity-cost threshold with OD-02 `T_years = calendar_days/365`).
- **Persisted on closed backtest trades** (`portfolio_backtest_trades`):
  - `benchmark_return_pct` (nullable decimal; same percent convention as `return_pct`)
  - `is_success` (nullable boolean; null for open / missing NIFTY bars)
- Wiring: `BacktestTradeSuccessAttacher` runs inside `BacktestPersistenceService::persistDayResults` — resolves NIFTY50 simple return over buy→sell from `portfolio_stock_prices` via `IndexCatalogService::primaryBenchmarkStock()`, converts trade `return_pct` percent → fraction for the evaluator, then stores flags. Profile from `BacktestRun.profile_id` (fallback strategy→profile). Does **not** change `ReturnQualityRankingService` / OD-23 / WS3 formulas.

### Tests / help

- `tests/Feature/Notification/Section30RecommendationNotifyTest.php` — UNFUNDED OPEN notifies; HOLD/WATCH skip; capital_required domain; EXIT SL attribution.
- `tests/Unit/Ranking/SuccessCriteriaEvaluatorTest.php` — §19 criteria + profile rate.
- `tests/Unit/Backtest/BacktestTradeSuccessFlagPersistenceTest.php` — closed trade persists `is_success` / `benchmark_return_pct`; open stays null.
- `appDocumentation.js` notifications + recommendations + opportunity-cost help synced.

**Ops:** Re-upload that migration file under `lidoportfolio/database/migrations/`, then re-run `cpanel-migrate.php?token=...`.

## V3 closure — capital badges, registry allocation, portfolio max position (2026-08-26)

**Status:** **DONE**. Presentation + existing API wiring + ceiling tighten only. Does **not** redesign WS2 capital formulas, WS3 ranking/allocator, WS4 lending engines, §34.3 calculators, OD-10/17, or §10.4/§10.5.

### A. Recommendations capital status (V3 §7 / §29)

- List **Capital** column for OPEN/INCREASE: UNFUNDED → “Capital required”, Awaiting lender, Partially funded, quieter Funded badge.
- API already serialized `capital_allocation_status`; now also exposes `capital_request_id` from capital_allocation meta.
- `RecommendationLenderActions` calls existing `GET/POST /v1/capital/requests/{id}/lenders|approve|reject` from detail and expandable list action.
- Helpers in `capitalRecallUi.js` (+ JS tests).

### B. Strategy Registry allocation % editor (§29)

- Enabled rows show `allocation_pct`; **Allocation** section with editable % + live sum; Save uses same `PUT /v1/capital/allocations` as Cash.
- Cash page editor kept (dual surface OK).

### C. `portfolio_max_position_pct` enforcement (§3.5)

- `RecommendationGenerationPipeline::prepareContext` sets  
  `effective max_pct = min(strategy max_position_size_pct, portfolio_max_position_pct if set, 100/max_holdings if set)`  
  before market-gate multiplier.
- Feature: `V3RecommendationGenerationTest::test_portfolio_max_position_pct_tightens_effective_max_pct`.

### Docs

`appDocumentation.js` — Recommendations capital badges/lender; Strategy Registry Allocation; Settings max-position copy; Cash dual-surface note.

## V3 strict closure — OD-16 window UI + DailyMarketDataJobTest (2026-08-26)

**Status:** **DONE**. Does **not** redesign WS2–WS4, §34.3, OD-10/17, or `WeakestPositionRanker` ranking formula.

### 1. OD-16 Strategy UI (`weakest_position_window_days`)

- Engine already reads top-level `config_json.weakest_position_window_days` via `WeakestPositionRanker::evaluationWindowDays` (default **90**).
- **Factory:** `FactoryMomentumStrategy` seeds `weakest_position_window_days => 90`.
- **UI:** Strategy → Portfolio Rules — “Weakest-position evaluation window (calendar days)” next to hard max holdings; persists via existing `PUT /v1/strategy` into the same top-level key.
- **Validation:** positive integer ≥ 1 when set; empty → `null` (engine default 90). `validateWeakestPositionWindowDays` in `strategyPageRules.js`.
- **Docs / tests:** `appDocumentation.js` Portfolio Rules help; `strategyPageWsPsA.test.mjs` control assertion. Closes former V4-UX-001 as a V3 surface.

### 2. V4-BUG-004 — `DailyMarketDataJobTest` RefreshDatabase pollution

- Removed `RefreshDatabase` from `tests/Feature/DailyMarketDataJobTest.php` (pure Mockery; no DB). Avoids full-suite `Target class [db.schema] does not exist` container pollution.

## V3 closure — hard max_holdings + legacy strategy cash % retirement (2026-08-26)

**Status:** **DONE**. Does **not** redesign WS2 capital formulas, WS3 ranking/allocator core, WS4 lending, §34.3 calculators, or §34.4 cooldown/entry engines.

### Hard `max_holdings` (V3 §3.5)

- **Factory:** `FactoryMomentumStrategy` seeds `portfolio_rules.max_holdings = 10`.
- **UI:** Strategy Portfolio Rules — **Hard maximum holdings** next to recommended minimum; positive integer ≥1 when set; empty allowed on save (persist null).
- **Normalize:** `portfolio_rules` merge already pass-throughs `max_holdings`.
- **Generation (`RecommendationGenerationPipeline`):**
  - Counts strategy-owned holdings with qty > 0.
  - At draft time: if at capacity, demote candidate OPEN → WATCH (INCREASE of owned names unchanged).
  - After ranking: `enforceMaxHoldingsOpenCap` keeps only remaining slots of ranked OPENs; surplus → WATCH.
  - `allocateCapital` also skips OPEN that would exceed the cap (alongside `max_new_positions_per_cycle`).
  - Sizing: `effective max_pct = min(configured max_position_size_pct, 100/max_holdings)` when `max_holdings > 0` (then market-gate multiplier).

### Legacy strategy cash % retirement (V3 §21 / §25)

- **Factory:** no longer seeds `min_cash_reserve_pct` / `max_cash_deployment_pct`.
- **Backtest (`SimulationDayProcessor`):** no longer subtracts/caps available cash by those V1 strategy percentages.
- **Pipeline:** removed unused `$minCashReservePct` / `$maxCashDeployPct` assignments.

### Tests

`tests/Feature/V3RecommendationGenerationTest.php` (`test_max_holdings_blocks_new_open_but_allows_increase`); ExitStrategyEvaluator ignored-keys suite; `tests/js/strategyPageWsPsA.test.mjs` max-holdings control.

## WS-PS-A — V3 Strategy Editor Information Architecture (2026-08-26)

**Status:** **DONE**. Product-surface correction only (V3 §21 / §29). Does **not** reopen WS2–WS4, §34.3 calculators, §34.4 entry/cooldown engines, OD-10, OD-17, or §10.4/§10.5.

### Implemented

- **Multi-strategy editor:** Strategy page selector loads portfolio strategies from `GET /v1/strategy-registry` and switches via existing `?strategy_id=` / `PUT` `strategy_id`. Replaced “one strategy per portfolio” copy. Enablement remains non-exclusive.
- **Strategy Registry discoverability:** Sidebar Trading → **Strategy Registry** (`showInSidebar: true`). Registry Enable/import wording uses Enable / multiple enabled (no exclusive “Select to activate”).
- **Removed Strategy UI:** Cash Management tab; Portfolio Rules **Minimum Cash Reserve %** and **Maximum Cash Deployment %**; Exit Strategy **Maximum Loss** and V1 **Trailing Stop** proxy.
- **Kept:** Market Gates, horizon, first_entry_pct, eligibility/scoring/thresholds, strategy-specific exits (MA/RS/trend/score/ATR/screener), conviction bands, recommended min holdings, hard max holdings (see V3 closure note above), position size / exposure / behaviour toggles. BUY cooldown shown as read-only copy (OD-11; not configurable).
- **Runtime:** `ExitStrategyEvaluator` **ignores** legacy `max_loss` / `trailing_stop` (V3 §14–§15 / §21 / §25). They are omitted from the exit-rule catalogue (`defaultRules` / `mergeWithDefaults`). Portfolio SL/trailing calculators and §13.2 precedence order are unchanged.

### Explicitly not in this workstream

- Discovery nav, opportunity-cost UI, minimum actionable BUY UI, disable/archive API, WS2–WS4, B4, broker. (OD-16 window UI delivered in V3 strict closure. Discovery nav + Create/Archive UI delivered in the later V3 product-surface closure.)

### Tests

`tests/Unit/Strategy/ExitStrategyEvaluatorIgnoredAccountKeysTest.php`; `tests/js/strategyPageWsPsA.test.mjs`; help test updated for ignored legacy exits.

## V3 closure — archive + portfolio settings (2026-08-26)

**Status:** **DONE**. Closure of residual V3 §3.1 / §28–§29 portfolio settings surfaces. Does **not** redesign WS2–WS4, §34.3 calculators, OD-10/17, or §10.4/§10.5.

### 1. Strategy archive (V3 §3.1)

- `StrategyRegistrySupport::archive` / `StrategyArtifactRegistry::archive` / `POST /api/v1/strategy-registry/{id}/archive`
- Sets `TradingStrategy::STATUS_ARCHIVED` without changing siblings; keeps `active_version_id` for historical attribution
- Strategy Registry UI: **Archive** on enabled rows with confirm copy; Enable remains for non-enabled; Edit stays for enabled
- Feature: `StrategyRegistryApiTest::test_archive_sets_archived_and_activate_of_another_does_not_unarchive`

### 2–4. Portfolio Settings (OD-12, opportunity-cost, max position, OD-06 display)

Profile keys (via `ProfileSettingsService::DEFAULTS` + Settings validate/update):

| Key | Default | Notes |
|-----|---------|--------|
| `minimum_actionable_buy_amount` | `''` (platform ₹5,000) | Already consumed by `MinimumActionableAmountResolver` |
| `opportunity_cost_rate` | `'0.12'` | Decimal fraction; UI shows %; §19 success via `SuccessCriteriaEvaluator`; closed backtest trades persist `benchmark_return_pct` + `is_success` |
| `portfolio_max_position_pct` | `''` | Optional; tighter of strategy vs portfolio (§3.5) |

Settings → Portfolio also shows a display-only reservation policy card: Atomic block ₹5,000 · Execution margin 1% (OD-06).

### 5. BUY cooldown copy

Strategy → Portfolio Rules: muted “BUY cooldown: 1 calendar day (OD-11; not configurable).”

### Tests / docs

`StrategyRegistryApiTest` archive case; `strategyPageWsPsA.test.mjs` settings/registry assertions; `appDocumentation.js` Settings + Strategy Registry + Portfolio Rules help.

## V3 technical-debt closure — schedulerTimestamp (2026-08-26)

**Status:** **FIXED** (V4-BUG-001 closed). Does not touch frozen WS2–WS4 / §34 / OD-* engines.

### Root cause

`formatSchedulerTimestamp` originally used a deterministic ops contract (`en-GB`, 24-hour, seconds, shortOffset + IANA in parentheses). A later Settings/sync-UI commit (`adae02a`) switched it to 12-hour AM/PM without seconds and dropped the IANA paren label, while `schedulerTimestamp.test.mjs` still asserted the original contract — producing environment-looking “locale flake” failures (`5:00 AM GMT+5:30` vs `05:00:04 (Asia/Kolkata)`).

### Fix

Restored the original deterministic contract in `app/resources/js/src/utils/schedulerTimestamp.js`:
- fixed locale `en-GB`
- 24-hour clock with seconds
- always append `(<IANA timezone>)`
- pad hour/minute/second to 2 digits

Tests assert the contract and reject AM/PM drift. Full `npm run test:js` green (107/107).

### Docs

Removed from V4 known-bug / V3 closure carry-forward lists.

## V3 STRICT CLOSURE — register-to-implementation pass (2026-08-26)

**Verdict:** **V3 STRICTLY COMPLETE**.

### Standard (product owner)

V3 complete only when: all V3 requirements + V3-related designed features implemented; **zero** known V3 bugs; **zero** open V3 technical debt / UX polish from the V3 audits; historical register items reconciled; docs consistent; regressions green. V4 may hold only genuine new V4 functionality or genuine unspecified product/spec decisions.

### Implemented / fixed in this pass

| Item | Action |
|------|--------|
| OD-16 weakest-position window Strategy UI (was V4-UX-001) | Strategy Portfolio Rules control; factory seed 90; persists `config.weakest_position_window_days` |
| V4-BUG-004 DailyMarketDataJobTest | Removed unused `RefreshDatabase` (pure Mockery) |
| Build break `navigation.js` | Restored missing `{` on Strategies nav entry (Vite production build) |
| V4 register rewrite | Open V3 bugs/TD/UX/HIST removed from active backlog; folded into FEAT/SPEC or closed |

### Genuine V4 remainder

See [`specs/LidoPortfolio-V4-Wishlist.md`](specs/LidoPortfolio-V4-Wishlist.md): **18** active V4 features (FEAT-001, 002, 005, 006, 009–011, 014, 021–029, 032), all **COMPLETE**, and **SPEC-001–006 (DECIDED 2026-08-26)**. **SPEC-001, SPEC-002, SPEC-003, SPEC-004, and SPEC-005 implemented 2026-08-28**. SPEC-006 remains unimplemented. **Eighteen** former V4 FEAT IDs are V5-deferred (prioritization, not implemented), including FEAT-008 (2026-08-28) and FEAT-012, 013, 015 (2026-09-02). The 2026-08-26 split created a historical **22**-item active V4 register. See that file §3.

## Final V3 Closure Audit (2026-08-26)

**Verdict:** **V3 STRICTLY COMPLETE**.

Completion criterion (product owner, 2026-08-26 strict pass): every normative V3 requirement is implemented; every V3-related designed surface from the audits is closed; **zero** known V3 bugs; **zero** open V3 technical debt / UX polish carried in the V4 register; historical items archived; docs consistent; regressions green. V4 holds only genuine new functionality or genuine unspecified product/spec decisions.

### Closure pass implemented (2026-08-26)

| ID | Area | Classification | Resolution |
|----|------|----------------|------------|
| V3-GAP-01 | Strategy disable/archive (§3.1) | A | `POST …/archive` + Registry Archive UI; siblings unchanged |
| V3-GAP-02 | Min actionable BUY override (OD-12) | A | Settings Portfolio control → existing resolver |
| V3-GAP-03 | Hard `max_holdings` (§3.5) | A | Strategy field + generation OPEN gate + 1/N sizing |
| V3-GAP-04 | Opportunity-cost rate (§19/§28/§29) | A | Portfolio setting + `SuccessCriteriaEvaluator` + persist `is_success` on closed backtest trades |
| V3-GAP-05 | Portfolio caps / lending policy | A/B | `portfolio_max_position_pct`; §5.7 `max_lending_pct_of_unused` / `max_lending_absolute`; OD-06 policy display |
| V3-GAP-06 | Legacy strategy cash % | A | Stop factory seed; stop backtest apply; dead pipeline assigns removed |
| V3-GAP-07 | Discovery nav | C | **Removed from Market sidebar** (`showInSidebar: false`); `/candidates` remains |
| V3-GAP-08 | Allocation % editor on Registry (§29) | A | Registry sum-100 editor (Cash editor kept) |
| V3-GAP-09 | Recs capital_status list + lender UX | A | List badges + select/approve/reject via existing APIs |
| V3-GAP-10 | BUY cooldown display | B | Read-only OD-11 copy on Strategy Portfolio Rules |
| V3-GAP-16 | Docs status claims | A | Status flipped to **V3 COMPLETE** |
| V3-GAP-17 | §30 notifications | A | Capital required / lending commitment-failure / committed hooks; HOLD/WATCH still skip |

### Workstream status (audit)

| Workstream | Status |
|------------|--------|
| WS2 capital accounting | COMPLETE (lending-limit cap is a narrow §5.7 addition; OD-19/20/21/24 formulas unchanged) |
| WS3 ranking / allocator / generation | COMPLETE (`is_success` evidence attached; ranking magnitude formulas unchanged) |
| WS4 lending / recall / bridge / capital priority / zero-own UNFUNDED | COMPLETE |
| §34.3 portfolio SL / trailing / exit precedence | COMPLETE |
| §34.4 staggered entry / cooldown / target | COMPLETE |
| OD-10 CA parent-owner quantity | COMPLETE |
| OD-17 history depth / charts | COMPLETE |
| §10.4 adoption / §10.5 backfill | COMPLETE |
| §29 product surfaces (registry, settings, capital badges, archive, **create-from-default**, Discovery-not-in-sidebar) | COMPLETE |

### True V4 / out-of-V3 only (not V3 gaps)

Tracked in [`specs/LidoPortfolio-V4-Wishlist.md`](specs/LidoPortfolio-V4-Wishlist.md) (strict rewrite 2026-08-26; Product Owner V4/V5 split 2026-08-26):

- **SPEC-001–006:** **DECIDED** 2026-08-26. **SPEC-001 implemented 2026-08-28** (same-stock WAVG merge). **SPEC-002 implemented 2026-08-28** (rights are not a CA; exercise = normal purchase). **SPEC-003 implemented 2026-08-28** (split/bonus restatement of qty/cost/avg/stop/trailing; rupee target preserved). **SPEC-004 implemented 2026-08-28** (cash-ledger LOAN / RECALL / BRIDGE with signed amounts). **SPEC-005 implemented 2026-08-28** (explicit cross-owner SELL attribution). SPEC-006 remains a frozen product rule, **not implemented**. See wishlist §4.
- **SPEC-007:** **DECIDED** 2026-08-27 (per-portfolio execution modes manual / semi_automatic / automatic; per-user admin automated-execution entitlement; authenticator TOTP required for automated broker submission; Zerodha/Kite first broker; recommendation `executed` remains a ledger fill). Implemented by **V4-FEAT-001**.
- **Active V4 FEAT (18, all COMPLETE; V4 closed 2026-09-02):** **V4-FEAT-021 COMPLETE** (2026-08-26); **V4-FEAT-022 COMPLETE** (2026-08-27); **V4-FEAT-005 COMPLETE** (2026-08-27); **V4-FEAT-006 COMPLETE** (2026-08-27); **V4-FEAT-023 COMPLETE** (2026-08-27); **V4-FEAT-024 COMPLETE** (2026-08-27); **V4-FEAT-025 COMPLETE** (2026-08-27); **V4-FEAT-026 COMPLETE** (2026-08-27); **V4-FEAT-027 COMPLETE** (2026-08-27) — split Trading OS HTTP controllers + shared React fetch hooks; **V4-FEAT-028 COMPLETE** (2026-08-27) — structured TOS logging + list pagination consistency; **V4-FEAT-029 COMPLETE** (2026-08-27) — pluggable Evaluation factor rules; **V4-FEAT-032 COMPLETE** (2026-08-27) — TOS aggregate repository boundary; **V4-FEAT-001 COMPLETE** (2026-08-27) — live execution modes, TOTP, Zerodha adapter; **V4-FEAT-002 COMPLETE** (2026-08-28) — GTT Target / Stop-Loss; **V4-FEAT-010 COMPLETE** (2026-08-28) — unattended production pipeline + Automatic execution via Laravel `schedule:run`; **V4-FEAT-009 COMPLETE** (2026-08-28) — Review reports list/detail UI over existing Review APIs; **V4-FEAT-014 COMPLETE** (2026-08-28) — Backtest history Duplicate as a new simulation from stored inputs + current Strategy; **V4-FEAT-011 VERIFIED COMPLETE** (2026-09-02) — Stocks admin SPA. FEAT-008 moved to V5 on 2026-08-28; FEAT-012, 013, and 015 moved to V5 on 2026-09-02.
- **V5-deferred FEAT (18 at deferral; current status lives in the V5 register):** B4 banner, notification channels, indicator-registry cutover, **TAF remaining phases (FEAT-008)**, admin force-logout, cash-as-of/export/compare, tax/attribution/benchmarks, mobile/AI/ML/markets/replay, CI/secrets deploy, Discovery/Evaluation UX polish, TS/grid migration, optional token API. FEAT-030 was subsequently completed on 2026-09-04; shipped TAF registries/I/O stay shipped.

**Closed in V3 (not V4):** OD-16 Strategy window UI; schedulerTimestamp; DailyMarketDataJobTest; max_position enforcement; all former open V3 bug/TD/UX/HIST active rows.

### Documentation / config cleanup in this pass

- Spec header Implementation Status + Last Updated
- `DOCS.md` V3 section (was “not started” / “no code yet”)
- WS4 recall-bridge delta stale “not coded” / Unfunded calculator notes
- OD-17 status line → DONE; CLI re-arm message; unused numeric `target_history_days` config key removed from campaign completion path

## V4-FEAT-021 — Strategy indicator parameters → EvaluationEngine (2026-08-26)

**Status:** **COMPLETE**. PO rule: for catalogue keys `rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`, a valid Strategy value overrides the global Evaluation configuration; otherwise the existing global/default is used. Scoring weights are unchanged.

### Mechanism

`App\Engines\Evaluation\EvaluationParameterResolver` reads Strategy `config_json` factor `parameters` (does **not** load Strategy rows, does **not** call `normalizeConfig()` so absent keys stay absent). Valid positive integers override period keys; a catalogue-enabled index symbol overrides `benchmark`; otherwise globals from `TradingOsConfig::evaluation()`. Missing lookback keeps the historical 3-month RS path vs primary/resolved benchmark.

Callers pass the already-resolved array into `EvaluationEngine::run($profile, $discoveryRun, $evaluationConfig)`. Pipeline groups enabled strategies by parameter fingerprint and runs one evaluation per group, then generates recommendations for those strategy ids. Standalone `POST /api/v1/evaluation/runs` uses `ensureActive` + the first active Strategy’s resolved params. Backtest `AsOfFactorScorer` receives the same resolved **period** config; its RS path remains a bar proxy (lookback/benchmark not applied there).

### Tests

`tests/Unit/EvaluationParameterResolverTest.php`; `tests/Feature/EvaluationParameterOverrideTest.php`; `StrategyConfigurationServiceTest::test_score_weights_ignore_indicator_period_parameters`.

### Explicitly not in this feature

Broker/GTT, market_regime, dataset gates, OpenAPI, repos, SPEC-001–006, Evaluation weight redesign, UI redesign, migrations. Liquidity composites were later wired in V4-FEAT-006 without changing Evaluation.

## V4-FEAT-022 — Hard dataset publish gate (2026-08-27)

**Status:** **COMPLETE**. **PO clarification (same feature, not a new ID):** Discovery is allowed when the required market dataset was successfully synced within the previous 24 hours of the pipeline run. On Monday the window is 72 hours. Comparison is timestamps vs the pipeline execution instant, not calendar dates. Holiday / exchange-calendar freshness is out of scope (V5). This supersedes the first-pass “synced today” / `published === true` gate.

### Mechanism

`DailyDecisionPipeline::run()` still records `DataEngine::datasetStatus()` (inspection). The gate is `Pipeline\DatasetFreshnessGate`:

1. Pipeline timestamp = `now()` at the start of `DailyDecisionPipeline::run()` (same instant written to `PipelineRun.started_at`). The gate uses this in-memory instant, not a datetime round-tripped from the database.
2. Last successful sync = `DailyMarketSyncService::lastSuccessfulSyncAt()` (`last_daily_market_sync_at`, ISO-8601). Legacy incomplete values containing `;processed=` are ignored. `markIncomplete()` no longer overwrites that key.
3. Age in seconds = `max(0, pipeline_ts − synced_ts)`.
4. Allowed window: **72 hours** if that instant is Monday in the cron/sync timezone (`cron_timezone`, default `Asia/Kolkata`); otherwise **24 hours**. Saturday/Sunday stay 24 hours.
5. Allow iff `age <= window` (inclusive). Missing timestamp → block.

- Allowed → `publish_gate.allowed=true`, then Discovery → Evaluation → Recommendations as before.
- Blocked → do not call Discovery/Evaluation/Recommendation. Persist the run as `failed` with `error_message` plus `stages.publish_gate.reason = dataset_not_fresh`, then throw `DomainException` (`DATASET_NOT_FRESH`, 422).

`DataEngine::datasetStatus()['published']` remains “synced today” for dashboard/status APIs. The pipeline **does not** gate on that boolean (a Friday success can still allow Monday’s run if age ≤ 72h).

### Gated vs not gated

**Gated (daily decision pipeline entry points):** `DailyDecisionPipeline::run()` — artisan `portfolio:decision-pipeline` (manual/scheduled/post-sync) and `POST /api/v1/pipeline/run`.

**Not gated:** standalone Discovery/Evaluation/Recommendation APIs, backtests, historical simulation, `GET /api/v1/dataset/status` (inspection only).

### Tests

`tests/Unit/DatasetFreshnessGateTest.php`; `tests/Feature/DatasetPublishGateTest.php`; `DailyMarketSyncTest` last-success timestamp preservation; existing `TradingOsPipelineTest` still marks a recent successful sync.

### Explicitly not in this feature

Holiday / trading-calendar freshness (V5), DataEngine redesign, completeness scores, extra freshness rules, broker, SPEC-001–006, FEAT-021 scoring-parameter changes. Daily-sync skip-if-already-synced-today is unchanged. Dataset versioning is V4-FEAT-023 (separate; does not change this gate).

## V4-FEAT-005 — Evaluation market regime (2026-08-27)

**Status:** **COMPLETE**. Frozen PO rule: MarketAnalysisEngine is the source of categorical `market_regime` (Bullish / Neutral / Bearish via existing `regimeFromPhase()`). Evaluation keeps its 0–100 factor model. Mapping: Bullish→100, Neutral→50, Bearish→0. Sentiment is not the score. No new phase or regime calculation.

### Mechanism

`EvaluationEngine::run()` calls `MarketAnalysisEngine::latest()` **once per evaluation run** (not per candidate). `MarketRegimeScoreMapper` maps only those three labels. Factor key `market_regime` receives the numeric score (existing Strategy/equal-weight contract). Evidence also stores categorical `market_regime` and numeric `market_regime_score`.

Unavailable Market Analysis still returns `market_regime = Neutral` from the engine; the mapper yields 50. Recommendation continues to call `latest()` later in the same daily pipeline; the engine’s existing ~4 hour snapshot cache avoids a second compute when the as-of date matches. Snapshot lookup uses `whereDate` so SQLite datetime storage still hits that cache (same 4-hour policy).

FEAT-021 parameter resolution and FEAT-022 freshness gating are unchanged. Evaluation / Strategy scoring weights are unchanged (`trading_os.evaluation.weights`; Strategy catalogue default weight 5 for this factor).

### Backtest

`AsOfFactorScorer` still stubs `market_regime` at 50.0. Using `latest()` there would leak current market regime into historical as-of dates. Historical Market Analysis is out of scope.

### Tests

`tests/Unit/Evaluation/MarketRegimeScoreMapperTest.php`; `tests/Feature/EvaluationMarketRegimeTest.php`.

### Explicitly not in this feature

New phases, phase-specific scores, sentiment-as-regime, backtest historical regime, FEAT-006 liquidity, Strategy weight redesign, FEAT-021/022 behaviour changes.

## V4-FEAT-006 — Liquidity & Tradability calculators (2026-08-27)

**Status:** **COMPLETE**. PO rule: keep the existing composite formulas and complete their runtime wiring. Do not redesign formulas, retune thresholds, change component weights, or invent new metrics.

### Runtime gap that was fixed

`LiquidityTradabilityCalculator` already implemented the seven primaries and the two composite methods. `TechnicalIndicatorService` already calculated the primaries. `liquidity_score` and `tradability_score` were Registry-defined but hit `compute()` / `computeSeries()` `default` (null). They are now dispatched like other indicator ids.

### Mechanism

OHLCV → `TechnicalIndicatorService` → primary series (`relative_turnover`, `average_turnover`, `average_volume` / gap + circuit primaries) → `LiquidityTradabilityCalculator::liquidityScore` / `tradabilityScore` per bar → `liquidity_score` / `tradability_score`.

Authoritative formulas remain only in `LiquidityTradabilityCalculator` (Registry `formula_explanation` documents them). Missing/insufficient inputs still return `null` (mean of available mapped components; no invented 0/50/100). Existing caps (e.g. RT×50 clamped 0–100; log maps; tradability clamp01) are unchanged.

Screener picker is unchanged (`screenable: false`). Evaluation / Strategy / Recommendation scoring were **not** changed. FEAT-005 / FEAT-021 / FEAT-022 are untouched.

### Tests

`tests/Unit/Screener/LiquidityTradabilityRuntimeTest.php`; `tests/Unit/Indicators/LiquidityTradabilityCalculatorTest.php` (null/cap assertions).

### Explicitly not in this feature

Formula redesign, Evaluation weights, Strategy scoring, new Screener filters, FEAT-001/002/005/008/009/015/021–023/025–029/032, SPEC-001–006.

## V4-FEAT-023 — Immutable dataset versioning (2026-08-27)

**Status:** **COMPLETE**. A successful market-dataset sync produces an identifiable immutable version. Downstream Discovery/decision-pipeline runs record which version they consumed. Later syncs create a new version and do not mutate earlier identity. Failed/incomplete syncs do not create or activate a successful version. FEAT-022 freshness is unchanged (last successful sync timestamp; 24h weekday / 72h Monday in `cron_timezone`; inclusive; no holiday calendar).

### Why the date-string was not enough

`DataEngine::currentDatasetVersion()` previously returned `ohlcv-{max(price_date)}` (or `ohlcv-none`). That label is not unique per successful sync, and it can be recomputed from live OHLCV after later mutations. Historical `DiscoveryRun.dataset_version` stored that string, but it was not a published snapshot identity (PB-002 / TD-13).

### Mechanism

`DailyMarketSyncService::markSuccessful()` delegates to `recordSuccessfulSyncAt()`:

1. Write the existing success settings (`last_daily_market_sync_date`, `_success`, `_at`) — same freshness timestamp FEAT-022 uses.
2. `DatasetVersionLedger::recordSuccessfulSync()` inserts one row into `portfolio_tos_dataset_versions` and points `last_successful_dataset_version_key` at the new `version_key`.

`version_key` format: `ds-{YmdHis}-{YYYYMMDD|none}` in the sync timezone. Same-instant collisions append `-2`, `-3`, …. Descriptive columns (`latest_price_date`, `price_bars`, `securities_active`) are captured at insert time and then frozen. The Eloquent model rejects update/delete.

`markIncomplete()` still does not overwrite `last_daily_market_sync_at` and does not insert or retarget a version.

`DataEngine::currentDatasetVersion()` returns the current ledger key (`none` if none exists). `DiscoveryEngine::run()` continues to stamp `DiscoveryRun.dataset_version` from that value. Evaluation/Recommendation are not given their own version columns; they remain attributable via `discovery_run_id`.

The daily decision pipeline still gates on `DatasetFreshnessGate` timestamps. A version row does not make a stale dataset admissible. `datasetStatus()['published']` remains “synced today” for inspection.

This is **identity/attribution**, not a copy of OHLCV bars. Live `portfolio_stock_prices` rows remain upserted by later syncs. Reconstructing exact historical bars from a version key is out of scope.

### Tests

`tests/Feature/DatasetVersioningTest.php` (create, immutability, distinct later version, failed/incomplete create none, DiscoveryRun stamp, historical link preserved, FEAT-022 stale/OHLCV bypass still blocked). Existing `DatasetFreshnessGateTest`, `DatasetPublishGateTest`, `DailyMarketSyncTest` remain green.

### Explicitly not in this feature

OHLCV snapshot copies, content hashes, generic versioning framework, DataEngine redesign, holiday/exchange calendar (V5), FEAT-021/022 behaviour changes, broker/Strategy/Evaluation/UI, SPEC-001–006.

## V4-FEAT-024 — Recommendation `markExecuted` ownership (2026-08-27)

**Status:** **COMPLETE**. Architecture cleanup (SD-018 / TD-02 / PB-014). Execution still orchestrates the fill; Recommendation owns writing `executed` status.

### Old ownership

`ExecutionEngine` wrote `TradingRecommendation` `status=executed`, `executed_at`, and `executed_transaction_id` directly inside `completeRecommendationFromTransaction` and `executeOrder`, immediately after `RecommendationEngine::convertReservation()`. Controllers (`POST /api/transactions`) and APIs were unchanged entry points and still are.

### New ownership

`RecommendationLifecycleService::markExecuted($recommendation, $transaction)` converts the reservation and writes the executed fields. `RecommendationEngine::markExecuted()` is the façade. `ExecutionEngine` calls that method inside the existing fill `DB::transaction` (order status, lending `recordExecution`, transaction source still Execution-owned). Preconditions (pending execution, security match, same-tx idempotency, already-executed-by-other-tx, `canExecuteManually`, lending `assertCanExecute`) stay on ExecutionEngine so error messages and API contracts are unchanged.

`markExecuted` does **not** open its own transaction, so it remains part of the caller’s atomic fill unit (including TransactionController’s create+complete wrapper).

Revert-on-delete (`revertLinkedFillBeforeTransactionDelete`) still returns a rec to `pending_execution` from ExecutionEngine — inverse of fill, out of this feature’s scope.

### Tests

`tests/Feature/RecommendationMarkExecutedTest.php`. Existing `FinancialIntegrityHardeningTest`, `RecommendationLendingExecutionTest`, `TradingOsPipelineTest` remain green.

### Explicitly not in this feature

Broker automation, advanced orders, API redesign, formula changes, dataset freshness/versioning, Evaluation, SPEC-001–006.

## V4-FEAT-025 — OpenAPI for `/api/v1` (2026-08-27)

**Status:** **COMPLETE**. Machine-readable contract for the existing `/api/v1` surface. Documentation only — no API redesign, no behaviour change, no Swagger UI.

### Canonical document

- OpenAPI **3.0.3** at [`app/openapi/v1.json`](app/openapi/v1.json) (Laravel app root; regenerated with live `/api/v1` routes, including V4-FEAT-002 protections).
- Regenerated from live routes: `php artisan openapi:v1` (from `app/`). `--check` fails if the file is missing or stale.
- Builder: `App\Support\OpenApi\V1DocumentBuilder` + overlays `V1OperationOverlays`. Paths come from Laravel’s router (`api/v1` prefix). Overlay keys that are not live routes fail tests.
- Delivery copy: `app/public/docs/openapi-v1.json` (written by `openapi:v1`; also copied after `scripts/generate-static-docs.mjs` wipes `public/docs/`). No dedicated HTTP endpoint; no Swagger UI (the repo has no existing interactive API explorer).

### What it documents

All authenticated `/api/v1` routes (Sanctum SPA cookie `laravel_session` + `active.portfolio`). Header `X-Profile-Id` (alias `X-Portfolio-Id` / query `portfolio_id`). Success/error `ApiEnvelope` plus Laravel `{message, errors}` 422. Admin indicator routes marked `x-stox-admin` with 403.

V4 behaviour appears only where it is already on the wire: dataset `dataset_version` (FEAT-023), pipeline `DATASET_NOT_FRESH` (FEAT-022), evaluation Strategy parameter resolution + market_regime 100/50/0 (FEAT-021 / FEAT-005), order execute → `markExecuted` (FEAT-024). Login and ledger fill remain on legacy `/api` and are **not** v1 paths.

POST/PUT bodies without a source-derived overlay are documented as optional JSON objects with `additionalProperties: true` (no invented fields). Default success status is 200 unless an overlay records the controller’s actual 201/202/405.

### Tests / validation

`tests/Feature/OpenApiV1ContractTest.php`: file exists, parses, OpenAPI 3.0.3, live route keys ≡ spec keys, no non-`/api/v1` paths, Sanctum + admin flags, V4 spot-checks, overlay keys ⊆ live routes, `openapi:v1 --check`. Structural validation is `V1DocumentBuilder::assertValidDocument` (no OpenAPI Composer package in the project).

### Explicitly not in this feature

API redesign, Swagger UI, documenting `/api` (non-v1) as v1, a GET endpoint solely to serve the spec, SPEC-001–006.

## V4-FEAT-026 — Vitest / E2E smoke for TOS UI (2026-08-27)

**Status:** **COMPLETE**. Smoke coverage only — not a frontend-framework migration and not comprehensive UI tests.

Existing `node --test tests/js/*.test.mjs` helpers stay. New **Vitest + jsdom + Testing Library** suite covers TOS pages with mocked `/api/v1` (fixtures match `TradingOsPresenter` / ApiEnvelope). One **Playwright Chromium** path mounts the real `App` via a Vite harness and intercepts APIs (no Laravel, no live market, no secrets).

### Commands (from `app/`)

```powershell
npm run test:js:tos      # Vitest TOS smoke
npm run test:e2e:tos     # one Playwright path (first time: npx playwright install chromium)
npm run test:js          # existing node:test files + Vitest TOS smoke
npm run test:js:unit     # node:test files only
```

### Coverage

- Shell: session-restore copy; authenticated header/sidebar + Recommendations chrome.
- Recommendations: loading, INFY fixture, empty, API error toast, Review → Approve, pipeline `DATASET_NOT_FRESH` 422.
- Discovery (`/candidates`): candidate list, empty, Evidence modal.
- Playwright: Chromium Recommendations Review → Approve.

### Explicitly not in this feature

Full page coverage, replacing node:test, Swagger, live backend E2E, SPEC-001–006.

## V4-FEAT-027 — Split TradingOsController / shared React hooks (2026-08-27)

**Status:** **COMPLETE**. Internal maintainability refactor. **API wire contract unchanged** (same URLs, methods, auth, status codes, envelopes, validation errors). Not a product redesign; no Redux/Zustand/TanStack Query/TypeScript/repository layer.

### Backend

The former `App\Http\Controllers\Api\V1\TradingOsController` monolith is replaced by engine-aligned thin adapters under `App\Http\Controllers\Api\V1\TradingOs\`:

| Controller | Routes |
|------------|--------|
| `DataController` | securities, price-bars, dataset/status, imports |
| `DiscoveryController` | discovery runs, candidates |
| `EvaluationController` | evaluation runs, evaluations |
| `RecommendationController` | recommendations (generate/list/show/review/reopen/cancel/expire/history/pending-execution) |
| `NotificationController` | notifications list/retry |
| `ExecutionController` | orders, v1 transactions list, positions |
| `ReviewController` | reviews, review dashboard/outcomes |
| `PipelineController` | `POST /pipeline/run` |

Shared HTTP mapping (candidate/evaluation/recommendation/position JSON) lives in `TradingOsPresenter` so split controllers do not duplicate wire shape. `TradingOsHttp::validationError()` maps `ValidationException` to the existing `VALIDATION_ERROR` envelope. Controllers still call existing engines/services; Evaluation still locally maps `RuntimeException` → `EVALUATION_PRECONDITION` 422. `routes/api.php` path strings are unchanged.

OpenAPI was regenerated (`php artisan openapi:v1`, still 122 operations). Overlay keys and HTTP paths are the same; default operation descriptions now name the split controller classes (not a wire change).

### Frontend

Did not invent a second query library. Adopted existing TD-014 hooks:

- `useApiGet` + `runApiMutation` on Discovery (`CandidatesPage`), Pending Execution, Review dashboard, and Notification history (Recommendations already used them).
- `utils/tosEnvelope.js` (`tosList` / `tosData` / `tosMeta`) for the repeated `{success, data, meta}` unwrap.

### Tests

`tests/Feature/TradingOsControllerSplitTest.php` (route → controller mapping, Sanctum + `active.portfolio`, representative success/validation/404/`EVALUATION_PRECONDITION`/`DATASET_NOT_FRESH` envelopes). FEAT-026 Vitest/Playwright smoke updated/extended (Discovery loading/error). Commands: `npm run test:js:tos`, `npm run test:e2e:tos`, `npm run test:js`.

## V4-FEAT-028 — Structured logging / pagination consistency (2026-08-27)

**Status:** **COMPLETE**. Platform hardening (was V4-TD-010/011, original audit TD-17/TD-18). **No product redesign.** Does not add a logging framework, observability stack, repository layer (V4-FEAT-032), or TanStack Query/TypeScript (V5-FEAT-035). FEAT-021–027 semantics are unchanged.

### Structured logging

Reuse `App\Services\PortfolioLoggerService` (Laravel `Log` channels). New helper:

`event(string $engine, string $event, string $level, string $message, array $context = [])`

Convention:

- Stable **event** names (dot-separated) and **engine** class-ish identifiers in context — not interpolated into the message.
- Identifiers (`profile_id`, `pipeline_run_id`, `discovery_run_id`, `evaluation_run_id`, `dataset_version`, `recommendation_id`, `report_id`, counts) are context fields.
- Failures use `exception` (message string) at `error`; per-candidate evaluation issues at `warning`.
- Do not log request/response bodies, secrets, tokens, or auth headers.
- `sanitizeContext` redacts sensitive **keys** (password, token, secret, authorization, cookie, api_key, access_token, refresh_token, bot_token, csrf, xsrf, and `*_token` suffixes) then applies the existing JSON regex redaction.

Applied TOS/platform events (existing log sites only; not “log everything”):

| Event | Engine | Level |
|-------|--------|-------|
| `pipeline.completed` / `pipeline.failed` | DailyDecisionPipeline | info / error |
| `pipeline.command_started` / `finished` / `skipped` | RunDecisionPipelineCommand (scheduler channel) | info |
| `discovery.completed` / `discovery.failed` | DiscoveryEngine | info / error |
| `evaluation.completed` / `failed` / `candidate_failed` | EvaluationEngine | info / error / warning |
| `recommendation.generated` / `reviewed` / `execution_cancelled` / `reopened` | RecommendationEngine | info |
| `execution.ledger_completed` / `order_pending` / `order_executed` / `order_cancelled` / `fill_reverted` | ExecutionEngine | info |
| `notification.delivery_failed` | NotificationEngine | error |
| `data.import_requested` | DataEngine | info |
| `review.generated` | ReviewEngine | info |

Pipeline completion logs counts/ids, **not** the full `stages_json` blob. Daily market sync job logging remains `SyncLogService` / `SystemLogService`.

### Pagination

Helper: `App\Support\TradingOsPagination`.

- **Request:** `page` (default 1), `pageSize` (alias `per_page`). Invalid/zero page size → endpoint default.
- **Meta:** `{page, pageSize, total, lastPage}` (additive on the existing `ApiEnvelope`).
- **Max page size:** 200, except price-bars **500**.
- **DB-level** `paginate()` — not PHP slice after `get()`.
- **Defaults** match the previous implicit first-page caps: recommendations 100, orders 50, transactions 100, notifications 50, reviews 20, securities 50, price-bars 100.

**Paginated (normalized):** `GET /api/v1/securities`, `/price-bars`, `/recommendations`, `/orders`, `/transactions`, `/notifications`, `/reviews`.

**Not paginated (intentionally bounded / extra meta):** `/candidates` (latest discovery run + rank sort), `/evaluations` (latest evaluation run), `/positions` (open holdings), `/recommendations/pending-execution` (`meta.cash`; cap 100), review dashboard/outcomes.

**Compatibility:** `data` remains an array of items. Default page 1 + former cap ≈ the previous truncated first page. Extra meta keys are additive. Empty paginated lists now include pagination meta (candidates still `meta: {}`). SPA `tosList` still reads `data`.

OpenAPI 3.0.3 updated (`EnvelopePaginated` / `PaginationMeta`; `php artisan openapi:v1`).

### Tests

- `tests/Unit/TradingOsPaginationTest.php`, `tests/Unit/PortfolioLoggerServiceTest.php` (event keys + nested token redaction)
- `tests/Feature/TradingOsPaginationConsistencyTest.php` (default/page/max/empty/filter/auth)
- `TradingOsControllerSplitTest` empty-list meta; `TradingOsPipelineTest` `pipeline.completed` context; `OpenApiV1ContractTest`

### Explicitly not in this feature

Universal pagination of every `/api/v1` list (capital/analytics/registries), candidate DB pagination-by-rank, a generic repository layer, frontend page-size pickers, request/response body logging.

## V4-FEAT-029 — Pluggable Evaluation rules modules (2026-08-27)

**Status:** **COMPLETE**. Internal architecture refactor (was V4-TD-012). **Scoring behaviour unchanged.** Does not retune formulas, weights, Strategy parameter semantics (V4-FEAT-021), market-regime mapping (V4-FEAT-005), `sector_strength` stub, API envelopes, or backtest as-of scoring.

### Abstraction

- `EvaluationFactorRule` — `key()` + `evaluate(EvaluationFactorContext): EvaluationFactorResult`
- `EvaluationFactorContext` — immutable, already-resolved inputs (indicators, RS, run-level regime score, pattern count). Rules do not query the DB or Market Analysis.
- `EvaluationFactorResult` — numeric score, pass/fail tags, optional legacy aliases (`momentum`, `trend`, `pattern_bonus`, `volume`, `risk`)
- `EvaluationFactorRuleSet` — ordered list from the container

### Registration

`App\Providers\EvaluationServiceProvider` tags default rule classes (`evaluation.factor_rules`) and binds `EvaluationFactorRuleSet` as a singleton. Tag order is the pass/fail tag order (trend → momentum → RS → volume → breakout → regime → sector stub → risk).

To add a future factor: create a stateless rule class, append it to `EvaluationServiceProvider::defaultRuleClasses()`, keep the catalogue key stable. Do not add a switch in `EvaluationEngine`.

### Current modules (`Engines/Evaluation/Rules/`)

| Class | Factor key | Notes |
|-------|------------|-------|
| `TrendScoreRule` | `trend_score` | SMA stack; alias `trend` |
| `MomentumScoreRule` | `momentum_score` | RSI bands; alias `momentum` |
| `RelativeStrengthRule` | `relative_strength` | 1.05 / 1.0 thresholds |
| `VolumeScoreRule` | `volume_score` | volume ratio; null is score 50 with **no** fail tag; alias `volume` |
| `BreakoutScoreRule` | `breakout_score` | discovery pattern count; alias `pattern_bonus` |
| `MarketRegimeRule` | `market_regime` | uses context score only (Bullish 100 / Neutral 50 / Bearish 0) |
| `SectorStrengthRule` | `sector_strength` | still stub **50** |
| `RiskScoreRule` | `risk_score` | ATR% × 10 capped 0–100; alias `risk` |

### What stays in `EvaluationEngine`

Run/candidate lifecycle, discovery precondition (`RuntimeException` → API `EVALUATION_PRECONDITION` 422), data-quality skip, insufficient-history skip, **one** `MarketAnalysisEngine::latest()` per run, bar load, **one** indicator/RS snapshot per candidate, equal-weight mean of registered catalogue facts (not Strategy weights — those remain unused here as before), evidence assembly, persistence, ranking.

`trading_os.evaluation.weights` is still not applied inside Evaluation (list rank is equal-weight mean of factor facts). Strategy scoring continues to apply catalogue weights later.

### Backtest

`AsOfFactorScorer` stays separate: historical bars only, RS proxy, breakout/regime/sector stubs at 50, **no** `latest()`. Live rules must not run inside as-of simulation.

### Tests

`tests/Unit/Evaluation/EvaluationFactorRulesTest.php`; `EvaluationFactorRuleSetTest.php`; `tests/Feature/EvaluationFactorRulesArchitectureTest.php`; `AsOfFactorScorerHistoricalSafetyTest.php`; existing `EvaluationParameterOverrideTest`, `EvaluationMarketRegimeTest`, `DataQualityEvaluationGatingTest`.

### Explicitly not in this feature

Repository layer (V4-FEAT-032, completed separately), plugin filesystem loading, DB-backed rule registry, formula/weight changes, replacing `sector_strength`, wiring live Market Analysis into backtest, controller rewrite, OpenAPI changes.

## V4-FEAT-032 — Repository layer for TOS aggregates (2026-08-27)

**Status:** **COMPLETE**. Internal maintainability refactor (was V4-TD-015). **No product or API behavior change.** Not a generic repository framework, not CQRS, not a domain-model rewrite, not a repository-per-model migration. V5-FEAT-035 (TanStack Query / TypeScript) remains separate.

### Boundary

Concrete classes under `App\Repositories\Tos` (constructor-injected; no `BaseRepository`, no repository interfaces, no extra service-provider tagging). Repositories own query construction, scopes, DB-level `paginate()`, and simple lookups. Engines/services keep orchestration, validation, lifecycle, calculations, and side effects.

| Class | Aggregate | Callers |
|-------|-----------|---------|
| `MarketDataRepository` | Securities list/search, price-bars, dataset inspection counts, recent close rows for Evaluation | `DataEngine`, `DatasetVersionLedger` (counts only), `EvaluationEngine::loadBars` |
| `DiscoveryCandidateRepository` | Latest completed discovery, candidate list/filter/search, candidates for a run | `DiscoveryEngine`, `EvaluationEngine` |
| `EvaluationResultRepository` | Latest completed evaluation run id, ranked result list | `EvaluationEngine` |
| `RecommendationQueryRepository` | Profile list/filter/paginate, open-for-review, pending-execution, find, review history | `RecommendationLifecycleService` |
| `NotificationQueryRepository` | History paginate, find by profile, find by idempotency key | `NotificationEngine` |
| `ExecutionQueryRepository` | Orders paginate/find, transactions paginate, open positions | `ExecutionEngine` |
| `ReviewReportRepository` | Review-report paginate/find | `ReviewEngine` |

Pagination still uses `TradingOsPagination` (`page` / `pageSize`, meta `{page,pageSize,total,lastPage}`, max 200, price-bars 500, DB `paginate()`). Repositories clamp via `TradingOsPagination::clampPage` / `clampPageSize` — not a second pagination implementation.

### Intentionally still in engines/services

- `DatasetVersionLedger` insert-only version identity (FEAT-023); historical rows stay immutable.
- Discovery sourcing (watchlists, screeners, patterns, holdings) and PHP rank `usort` in `DiscoveryEngine::listCandidates`.
- Evaluation factor rules (must not query DB/repos), `MarketAnalysisEngine::latest()` once per run, scoring, `EvaluationResult` persistence, `RuntimeException` precondition.
- `RecommendationLifecycleService::expireStale()` and all status transitions / reservations / `markExecuted` (FEAT-024).
- Recommendation generation pipeline queries and rec `create`.
- Execution create/execute/cancel, holdings recalculate-then-list, ledger writes.
- Review dashboard/outcomes aggregations and report generate.
- Notification send/retry and `TosNotification::create`.
- Data import trigger and `SyncRun` history.

### Tests

`tests/Feature/Tos/TosRepositoryQueryTest.php` (filter/sort/scope/pagination/joins/not-found/engine delegation including expire-stale). Regression: DatasetVersioning, DatasetFreshness, EvaluationParameter/MarketRegime/Factor, RecommendationMarkExecuted, OpenApiV1Contract, TradingOsPipeline, pagination, controller split.

### Explicitly not in this feature

Generic `BaseRepository` / CRUD framework, repository-per-model, DTOs, CQRS, event sourcing, OpenAPI/API changes, frontend query-library migration.

## V4-SPEC-001 — Same-stock adoption weighted-average merge (2026-08-28)

**Status:** **DECIDED** product rule, **implemented**. Not a new V4-FEAT row. SPEC-003/004/005 and FEAT-002 are unchanged.

When an unmanaged holding is adopted into a Strategy that already owns the same stock, `HoldingAdoptionService` no longer 422s. Unmanaged buys are attributed with HOLD_POSITION (OD-11). `HoldingsCalculationService` recalculates owner lots so quantity and total cost combine into the destination Strategy row. Final `avg_buy_price` is 2 decimal places, half-up, from unrounded combined cost / quantity. Destination `target_amount` is preserved (OD-12). Destination ownership episode / first-buy date is preserved when destination fills predate the unmanaged lot (OD-15). No new BUY transaction, cash movement, or lending consumption.

If the unmanaged lot’s first buy is **earlier** than the destination episode, attributing those fills onto the destination owner can extend first-buy backward (it does **not** reset to today). That edge is not a new tax-lot rule.

Non-conflict adopt (destination does not own the symbol) still initializes target from invested cost.

### Files

- `app/Services/Ownership/HoldingAdoptionService.php`
- `app/Http/Controllers/Api/HoldingController.php` (API shape unchanged)
- `resources/js/src/pages/HoldingsPage.jsx`, `resources/js/src/data/appDocumentation.js`
- `tests/Feature/HoldingAdoptionTest.php`

### Tests

`tests/Feature/HoldingAdoptionTest.php` (worked example 50@1000 + 100@1200 → 150 / 170000 / 1133.33; inputs not rounded first; target preserved; other owners untouched; zero qty; cross-strategy; idempotent; foreign portfolio).

## V4-SPEC-004 — Cash-ledger LOAN / RECALL / BRIDGE (2026-08-28)

**Status:** **DECIDED** product rule, **implemented**. Not a new V4-FEAT row. SPEC-006 and related FEAT rows stay OPEN.

Dedicated cash-ledger types `loan`, `recall`, and `bridge` sit on the existing `portfolio_cash_ledger_entries` table and go through `CashManagementService::post()`. Amount is **signed**: positive enters trading cash, negative leaves. There are no `LOAN_IN` / `LOAN_OUT` (etc.) types. Notes are human context only.

Intra-portfolio loan / recall / bridge events post the same type twice (credit then debit) so the **physical cash pool is unchanged**. Delayed Proceeds from Stock Sale (SELL was booked with `applyCash=false`) post a **single positive** `recall` or `bridge` instead of `deposit`. Applying those proceeds to outstanding claims does **not** post a second special movement. Direct `CapitalLoan` inserts (test/accounting rows) still do not invent cash rows.

Does not create synthetic BUY/SELL, does not change holdings, does not add a cash mutation API, and did not implement SPEC-005 (SPEC-005 is a later slice).

### Files

- `app/Models/CashLedgerEntry.php`, `app/Services/CashManagementService.php`
- `app/Services/Lending/SpecialCashMovementService.php`
- Wired from `CapitalRequestApprovalService`, `CapitalLoanRepaymentService`, `RecallImmediateSettlementService`, `RecallBridgeLoanService`, `SaleProceedsAvailabilityService`, `ProceedsApplicationService`
- Cash UI labels + `GET /api/cash/ledger` `entry_type` / `movement_kind`

### Tests

`tests/Feature/Lending/V4Spec004CashLedgerSpecialMovementsTest.php` plus existing cash-ledger, capital/lending, financial-integrity, live-execution, and advanced-order regressions.

## V4-SPEC-005 — Explicit cross-owner SELL attribution (2026-08-28)

**Status:** **DECIDED** product rule, **implemented**. Not a new V4-FEAT row. SPEC-006 and related FEAT rows stay OPEN.

New SELL writes must be attributable to one owner. If exactly one open lot exists, or the request already identifies the owner (`recommendation_id`, `owner_key`, or `strategy_id`), that owner is used and persisted on `portfolio_transactions.owner_key`. If two or more owners hold the stock and the transaction does not identify the owner, the write returns 422 — no FIFO, proportional, largest, or oldest guess. Quantity is checked against that owner’s holding, not the portfolio sum. Unmanaged is a first-class owner (`unmanaged`). A SELL does not transfer leftover shares to another owner and does not create synthetic BUY/SELL or new cash types.

Broker/live fills and GTT protection fills pass the recommendation Strategy or the protection `owner_key`. Recall liquidation sells the ranked borrower lot with that lot’s `owner_key` (the previous post-sell ownership patch is gone). Recommendation `executed` still means an actual fill.

Historical ledger rows without `owner_key` keep the V3 blended recalc fallback. Tax lots are not introduced.

### Files

- `app/Services/Ownership/SellAttributionService.php`
- `app/Services/TransactionWriteService.php`, `HoldingsCalculationService.php`, `BulkTransactionImportService.php`
- `app/Engines/Execution/ExecutionEngine.php`, `app/Services/Protection/PositionProtectionService.php`, `app/Services/Lending/RecallLiquidationService.php`
- `app/Http/Controllers/Api/TransactionController.php`, `BulkTransactionImportController.php`
- Transactions / Holdings UI + `sellTransactionPrefill.js`

### Tests

`tests/Feature/V4Spec005SellAttributionTest.php` plus holdings, financial-integrity, live-execution, advanced-orders, lending/capital, and recommendation-execution regressions.

## V4-SPEC-003 — Split/bonus Strategy-position restatement (2026-08-28)

**Status:** **DECIDED** product rule, **implemented**. Not a new V4-FEAT row. SPEC-004 is implemented separately; SPEC-005 is a later slice; FEAT-002 is unchanged.

On top of OD-10 parent-owner quantity: `CorporateActionService::apply` restates the affected position so it stays economically equivalent.

- **Split:** existing F020 in-place `qty × (ratio_to/ratio_from)`, `price ÷ factor`. Invested cost unchanged; average cost falls with quantity. No new ledger row.
- **Bonus:** existing ₹0 `SOURCE_BONUS` buy (no cash). Invested cost unchanged; average cost falls with the extra quantity.
- **Trailing:** historical OHLCV before ex-date is divided by the existing price divisor (split: factor; bonus: `1 + factor`). First-buy date is not reset to the CA date.
- **Stop:** derived from OD-13 weighted-average fill cost after restatement (portfolio SL %). Not a broker GTT.
- **Target:** OD-12 `target_amount` is a **rupee** capital target and is preserved (implied remaining shares scale at the new price).
- Same stock + type + ex-date already applied is **idempotent** (no second mutation).

### Tests

`tests/Unit/CorporateActionSpec003RestatementTest.php` plus existing CorporateAction / OD-10 / financial-integrity / live-execution regressions.

## V4-SPEC-002 — Rights issues are not a corporate-action type (2026-08-28)

**Status:** **DECIDED** product rule, **implemented**. Not a new V4-FEAT row. SPEC-006 remains unimplemented.

Rights announcements do not change existing holdings and do not create entitlements or a rights CA type. `POST /api/corporate-actions/preview` and `POST /api/corporate-actions` reject `action_type` values that are rights (`rights`, `rights_issue`, `rights-issue`, case-insensitive) with 422. Holdings quantity/cost, cash, OHLCV, and `portfolio_corporate_actions` are unchanged. The Corporate Action UI stays split/bonus only and tells the user to record exercised shares as a normal buy.

If the user exercises and receives shares, record a **normal purchase** at the actual subscription price (`POST /api/transactions` BUY). Cash decreases, quantity increases, and average cost follows the existing WAVG ledger path — no special rights math. A `source=rights` buy is still a paid purchase (price must be > 0); with a `recommendation_id` it becomes `SOURCE_RECOMMENDATION` like any other attributed buy. GTT protections synchronize after a rights-tagged buy the same way they do after a material BUY (bonus/split still skip). Exchange-feed rights rows are not queued as data-quality CA issues.

Split/bonus (SPEC-003), cash LOAN/RECALL/BRIDGE (SPEC-004), SELL attribution (SPEC-005), live `executed` = fill (FEAT-001 / SPEC-007), and GTT lifecycle (FEAT-002) are unchanged. Mergers/demergers remain out of scope.

### Files

- `app/Services/CorporateActionService.php`, `app/Http/Controllers/Api/CorporateActionController.php`
- `app/Services/DataQualityCorporateActionSyncService.php`
- `app/Services/TransactionWriteService.php`, `app/Services/Protection/PositionProtectionService.php`
- `resources/js/src/pages/CorporateActionPage.jsx`, `resources/js/src/data/appDocumentation.js`

### Tests

`tests/Feature/V4Spec002RightsIssuesTest.php` plus DataQualityDetection (rights skipped), AdvancedOrders (rights buy syncs GTT), and existing CorporateAction / OD-10 / SPEC-003 / financial-integrity / live-execution regressions.

## V4-FEAT-001 — Broker / live execution automation (2026-08-27)

**Status:** **COMPLETE**. Product rules: **V4-SPEC-007**. Manual ledger execution is unchanged.

### Frozen product (shipped)

- Per-portfolio `execution_mode`: `manual` (default) / `semi_automatic` / `automatic`.
- Per-user admin `automated_execution_entitled_at` (off by default). Never inferred from mode.
- Authenticator **TOTP** (`pragmarx/google2fa`) required for Semi/Auto broker submit; not for Manual. Recovery codes hashed, single-use. Rate limit 5/min. Replay blocked via `verifyKeyNewer`. Secrets never logged.
- Semi-Automatic: `POST /api/v1/execution/submit-selected` with TOTP. Selection is not execution.
- Automatic: no per-order UI; pipeline + `tos:submit-automatic-orders`. Enabling Automatic requires TOTP + `confirm_automatic`. Unattended jobs check enrolled TOTP, not a fresh code.
- Domain: recommendation → execution decision → broker order → fill → ledger. `executed` still means ledger fill (`RecommendationEngine::markExecuted`).
- Zerodha/Kite only. **Per-user** Kite Connect (encrypted access token). Central shared account was not used because it would mix whose money is traded. The cross-site Kite callback is guest-accessible and bound to the initiating Lido user by a short-lived encrypted `state`; it does not rely on the SPA session cookie surviving the broker round trip.

### Engineering

- Idempotency: unique `submission_key`; unknown/in-flight → reconcile, never re-place. Ambiguous HTTP → `broker_status=unknown`.
- Reconcile every 5 minutes. Partial fill updates broker qty only until terminal full fill (then one ledger write). Reject/cancel with zero fill does not execute the recommendation.
- FakeBrokerGateway in `testing`. Tests never call Zerodha.
- Env: `KITE_API_KEY`, `KITE_API_SECRET`, `KITE_REDIRECT_URL`. Tokens expire ~06:00 IST.

### API (`/api/v1`)

TOTP begin/confirm/verify/recover/disable; execution mode GET/PUT; submit-selected; order reconcile; broker status/login/callback/session/disconnect; admin entitlement.

### UI

Settings Portfolio: execution mode. Settings Account: TOTP + Kite. Users admin: entitlement. Pending Execution: Accept / Execute Selected when Semi-Automatic.

### Tests

`tests/Feature/Totp/TotpFlowTest.php`, `tests/Feature/Execution/LiveExecutionFeatureTest.php`, logger redaction, OpenAPI, TOS Vitest (`tos-pending-execution.test.jsx`), TradingOsControllerSplitTest.

### Explicitly not in this feature

GTT/stop/target (FEAT-002), multi-broker marketplace, email OTP, password re-prompt as 2FA.

## V4-FEAT-002 — Advanced broker orders (2026-08-28)

**Status:** **COMPLETE**. Frozen product rules in `specs/LidoPortfolio-V4-Wishlist.md` V4-FEAT-002. Builds on FEAT-001 / FEAT-024 / SPEC-001 / SPEC-003. SPEC-004 and SPEC-005 are implemented separately. Does not implement any other V4 FEAT.

### Frozen product (shipped)

- One active broker protection per Strategy position: **GTT Target** or **GTT Stop-Loss**. Placing one replaces the other.
- Prices are Strategy-derived only: Target = `target_amount / quantity`; Stop = OD-13 weighted-average fill × (1 − portfolio SL %). No independent broker-order price. Missing/invalid price → do not place/sync, keep the position, `needs_attention`. Never use a stale broker price.
- **Manual:** no automatic GTT; explicit place is rejected (`EXECUTION_MODE_MANUAL`).
- **Semi-Automatic:** user-triggered `POST /api/v1/protections` with TOTP. A fill does not silently place protection.
- **Automatic:** Stop-Loss is placed after an automated BUY fill. No extra per-order approval. Same automated-execution entitlement as FEAT-001 (no second entitlement).
- Material BUY/SELL and SPEC-003 corporate-action restatement synchronize the existing GTT (modify if the broker supports it, else cancel + replace). Non-material changes (notes-only) do not.
- Sync failure retries on `tos:reconcile-broker-orders`; persistent failure → `needs_attention`. The underlying BUY/SELL/CA is not rolled back.
- Ambiguous broker responses persist `broker_status=unknown` and must not place a duplicate.
- Partial GTT SELL: existing ledger fill path first; remaining quantity is synchronized on the **next** reconcile cycle.
- Position qty → 0: fill first, then cancel remaining GTT and mark `reconciled`. No orphans. `executed` still means an actual ledger fill (FEAT-024).
- SPEC-001 merged destination lot is the only attach point; unmanaged lots are not protected.

### Engineering

- Table `portfolio_tos_position_protections` / model `PositionProtection`. States: pending, active, synchronizing, needs_attention, cancelled, reconciled.
- `BrokerGateway` GTT methods; `FakeBrokerGateway` in tests; Kite `POST/PUT/DELETE/GET /gtt/triggers`.
- `PositionProtectionService` + `ProtectionTriggerPriceResolver`. Hooks: `TransactionWriteService` (material buy/sell), `LiveBrokerExecutionService` (automatic Stop after BUY), `CorporateActionService` (after restatement), `HoldingAdoptionService` (destination lot only), `tos:reconcile-broker-orders`.
- API: `GET/POST /api/v1/protections`, `GET /protections/{id}`, `POST /protections/{id}/cancel`, `POST /protections/{id}/reconcile`.
- UI: Holdings Sell menu Place/Cancel in Semi-Automatic; status badge for Strategy lots. Automatic shows status only. Manual does not offer Place.

### Tests

`tests/Feature/Execution/AdvancedOrdersFeatureTest.php`; Vitest `tests/js/tos/position-protection.test.js`.

### Explicitly not in this feature

Tax lots, multi-broker marketplace, a second Advanced Orders entitlement.

## V4-FEAT-010 — Pipeline Operations / Unattended Production Execution (2026-08-28)

**Status:** **COMPLETE**. Frozen product rules in `specs/LidoPortfolio-V4-Wishlist.md` V4-FEAT-010. Makes FEAT-001 Automatic mode operational end-to-end in production. Does not change the decision algorithm, execution modes, TOTP, `executed` = fill, GTT, or reservation/capital rules.

### Frozen product (shipped)

1. **Unattended daily pipeline.** Production cron continues to invoke `php artisan schedule:run` every minute. That is the **sole** production scheduler. The existing `portfolio:decision-pipeline --trigger=scheduled` event is **on by default** (`TRADING_OS_PIPELINE_SCHEDULE`, default `true`). Set the env var to `false` to disable. No dedicated cPanel one-shot scripts.
2. **One effective run per portfolio calendar day.** Scheduler ticks may overlap or repeat; `DecisionPipelineScheduleService` once-per-day + per-profile success markers + cache lock `trading-os:decision-pipeline:automatic` (2700s) plus Laravel `withoutOverlapping(45)` prevent a second *effective* decision run. Partial failures retry remaining profiles; successful profiles are not re-run. Manual / `--force` still bypass the automatic skip.
3. **In-app + Telegram on operational failures.** Pipeline, `tos:reconcile-broker-orders`, and `tos:submit-automatic-orders` unexpected failures persist as operational alerts (`decision_pipeline_failed`, `broker_reconcile_failed`, `automatic_submit_failed`) and notify admins via existing `sendAdminOperationalAlert` (6-hour Telegram cooldown). No email. No V5 multi-channel work. Gate-blocked Automatic submit (entitlement / TOTP / Kite / Manual mode) is **not** treated as a failure.
4. **Automatic execution stays on the existing path.** After a successful pipeline, Automatic portfolios still submit via `DailyDecisionPipeline` → `LiveBrokerExecutionService::submitAutomaticForProfile` (FEAT-001 gates). The scheduler also runs `tos:submit-automatic-orders` and `tos:reconcile-broker-orders` every five minutes with `withoutOverlapping(5)`. Duplicate broker placement is still prevented by `submission_key` / in-flight checks.

### Engineering

- Defaults: `config/trading_os.php` `pipeline.schedule_enabled` and `run_after_daily_sync` default **true**; `TradingOsConfig` fallbacks match.
- Commands record/clear durable failures in `portfolio_settings.unattended_ops_failures` and call `AdminOperationalAlertService::syncAndNotify()` immediately (hourly `portfolio:check-operational-alerts` still re-evaluates).
- Messages are sanitized (no tokens, TOTP, request bodies, broker credentials).

### Tests

`tests/Feature/V4Feat010UnattendedOpsTest.php`, `ScheduleRegistrationTest`, `TradingOsConfigTest` default accessors, existing H1/H2 `DecisionPipelineHardeningTest` / `DecisionPipelineScheduleTest`.

### Explicitly not in this feature

Email operational alerts, V5 notification architecture, new scheduler/worker frameworks, new cPanel scripts, live-portfolio exclusivity (SPEC-006), execution-mode redesign.

## V4-FEAT-008 — Trading Artifact Framework remainder deferred to V5 (2026-08-28)

**Status:** `OPEN`, **V5-deferred / out of active V4**. Docs/register correction only — **no application-code change**. Do **not** invent a new V4 TAF slice.

**Already shipped (do not treat as unimplemented):** artifact envelope / validation; package import/export (`/api/v1/artifacts*`); Indicator, Screener, and Strategy registries; Create / Enable / Archive; AI authoring/runtime documentation; V3 multi-strategy surfaces. See **Trading Artifact Registry infrastructure** below.

**Deferred to V5 (remainder of SD-034):** immutable published versions vs Save-in-place as a first-class library/binding model; dual library vs binding UX beyond current registries; extra AI draft-from-schema catalogue UX beyond shipped docs/prompt builder; sharing / pack distribution, dependency dashboards, rollback, bundle UI, fork workflows.

Active V4 after this move: **21** (**15** COMPLETE, **6** OPEN). V5-deferred: **15**. Historical 22-item active V4 register (2026-08-26): FEAT-008 is the row that moved. **V4-FEAT-009** later completed the Review reports UI (2026-08-28); see that section.

## V4-FEAT-009 — Review reports list / detail (2026-08-28)

**Status:** **COMPLETE**. Frozen UX over existing ReviewEngine APIs. No ReviewEngine, migration, pipeline, or Review API contract changes.

### Routes and navigation

- Live observational dashboard remains `/review` (`ReviewDashboardPage`).
- List `/review/reports`; detail `/review/reports/:id`.
- Sidebar stays a **single** Review entry (`/review`). The live dashboard has a **Reports** control. Detail has **Back to reports**.

### List

`GET /api/v1/reviews` with existing `page` / `pageSize` (default 20). No filters or sorting. Empty when `total === 0` includes Generate. Columns: ID, Period, Generated at, Status, stored portfolio value, stored XIRR. Row click and Open go to detail. Values are not recomputed in React.

### Detail

`GET /api/v1/reviews/{id}`. Unknown id uses existing API 404 plus navigation back to the list. Header: report id, period start/end, generated at, status. Named metric cards from persisted keys (portfolio snapshot, win rate, average gain/loss, profit factor, expectancy, **Accepted (not executed)** = `recommendation_accepted`, Executed). Remaining persisted keys in a table with frozen human labels. `summary_json.methodology` shown as stored strings.

`recommendation_accepted` remains pending_execution + accepted; executed is `recommendation_executed`.

### Generate

List page only. `POST /api/v1/reviews/generate`. Optional From/To are `period_start` / `period_end` **query parameters**, not a JSON body. Both empty keeps the existing 90-day ReviewEngine default. Success navigates to the returned report id when present.

### Help

In-app topics `review` (live dashboard) and `review-reports` (list/detail). Static HTML via `npm run docs:static`.

### Tests

`app/tests/js/tos/tos-review-reports.test.jsx`; `app/tests/js/tos/review-reports.test.js`. Existing Review/pipeline/pagination/OpenAPI/financial-integrity PHP tests unchanged.

### Explicitly not in this feature

Drawdown, execution quality, slippage, tax reporting, tax lots, attribution, benchmarks, ranking, new ReviewEngine formulas, methodology rewrites, pipeline generation changes, Review API changes, DELETE, list filtering/sorting.

## V4-FEAT-014 — Backtest Duplicate (2026-08-28)

**Status:** **COMPLETE**. Frozen product rule: Duplicate on a Backtest History row starts a **new** simulation from that row’s stored **inputs** (period dates, initial capital, notes, tags) against the **current Strategy**. It does **not** copy completed trades, trade results, statistics, portfolio snapshots, or the original run’s stored Strategy snapshot/version. This is backtest duplication only — Strategy Duplicate / version-fork stays removed.

### Mechanism

No new endpoint. History Duplicate reuses `POST /api/v1/backtests` and `startBacktest()`. `duplicateBacktestPayload()` sends `from_date`, `to_date`, `range_key`, `initial_capital`, `notes`, `tags`, and `session_token`. `strategy_version_id` and `name` are omitted so `BacktestSimulationEngine::start()` resolves the current Strategy via `ensureActive()` and auto-names the new run. Explicit dates are required because sending only `range_key` would recompute “now minus range” and change the period.

If the original row lacks period dates or a valid initial capital (min 1000), Duplicate stops with an error toast and does not invent values.

New / Open / Continue / Delete / metadata edit are unchanged.

### Tests

`app/tests/Feature/Backtest/BacktestDuplicateTest.php`; `app/tests/js/tos/backtest-duplicate.test.jsx`; `app/tests/js/backtestDuplicate.test.mjs`. Existing Unit Backtest tests and TOS Vitest suite (including Review) unchanged in behavior.

### Explicitly not in this feature

New backtest API, Strategy Duplicate/version-fork, copying result/output state, Review / TAF / live execution / ranking / tax / accounting, history delete/filter/benchmark UX.

## V3 residual — UNFUNDED zero-own lending offer (2026-08-24)

**Status:** **DONE**. Residual WS4 gap only. Does **not** change WS2 accounting, WS3 ranking/allocator, partial-own lending math, recall, Recall Bridge Loan, capital-priority, lender ranking, loan FIFO, adoption, OD-10, OD-17, §34.3, §34.4, or Strategy-page configuration.

### Implemented

When an OPEN/INCREASE is `UNFUNDED` after capital resolution (own free capital = 0) and a positive this-cycle target exists:

- Gap = `this_cycle_target − own` (= this-cycle target when own is 0).
- Loan size = `ceil(gap / 5000) × 5000` (**DEP-PARTIAL-ATOMIC** via `UnfundedLendingAmountCalculator` / `CeilToRupee5000`). Not OD-06 1% reservation.
- Eligible lenders queried first (`LenderEligibilityService` through `CapitalRequestService::eligibleLendersFor`).
- Lenders exist → create existing `CapitalRequest` (`displayed`), status `awaiting_lender_selection`. Rec stays OPEN/INCREASE (not WATCH). `actual_execution_amount` / this-cycle target unchanged (still 0 / original target).
- No eligible lenders → remain `unfunded`; no capital request.
- Own capital > 0 keeps the existing partial-own path unchanged. Recall still runs in `applyCapitalResolutionBeforeTradeMode` before any lending offer.

### Tests

`tests/Unit/Lending/UnfundedLendingAmountCalculatorTest.php`; `tests/Feature/Lending/UnfundedZeroOwnLendingTest.php`; lifecycle UNFUNDED-with-lenders assertion updated (now awaits selection, still cannot Approve until committed).

## V3 §29 — Strategy-page `horizon_calendar_days` + `first_entry_pct` (2026-08-24)

**Status:** **DONE**. UI/configuration only. Frozen engines and completed workstreams were not changed (WS2–WS4, §34.3, §34.4 calculators, OD-10, OD-17, §10.4/§10.5).

### Implemented

- **Strategy page:** Exit Strategy tab — optional **Horizon (calendar days)** (`portfolio_rules.horizon_calendar_days`). Empty/unset = no horizon expiry (engine already never fires). Positive integer when set; no invented default.
- **Strategy page:** Portfolio Rules tab — **First entry %** (`portfolio_rules.first_entry_pct`). Override of factory/engine default **50%**. Empty on save stores null so the engine default applies. Range when set: 1–100. BUY cooldown remains frozen at 1 calendar day and is not exposed.
- **Persistence:** Existing `PUT /api/v1/strategy` `config.portfolio_rules` (no new API or allowlist). Client validation on Save.
- **Help:** `appDocumentation.js` Strategy controls/concepts only.

### Explicitly not in this workstream

- `ExitPrecedenceEvaluator`, `StaggeredEntryCalculator`, `BuyCooldownEvaluator`, target/filled, allocator
- Moving/duplicating portfolio SL / trailing / reserve
- Same-stock adopt merge, CA rights restatement, B4, broker, weakest-position window UI (see Final V3 Completion Audit)

## V3 §10.5 / §10.4 — Ownership backfill + unmanaged adoption (2026-08-24)

**Status:** **DONE** (P0 next workstream after Final V3 Completion Audit). Does **not** redesign WS2–WS4, §34.3, §34.4, OD-10, or OD-17.

### Phase 1 — §10.5 migration backfill

**Old rule:** `HoldingOwnershipBackfill` assigned every unmanaged holding to the profile’s sole strategy.

**New rule (conservative):** infer strategy S only when every FIFO-remaining contributing buy lot is recommendation-linked to S. No lot evidence → remain unmanaged. Never infer from “exactly one strategy”. Do not merge into an existing strategy-owned row for the same stock. Explicit strategy ownership is preserved by `infer*`. One-shot migration `2026_08_24_000004_realign_holding_ownership_backfill_od105` reverts unattested heuristic tags (no rec-linked buy for that strategy) then re-runs lot-level inference.

### Phase 2 — §10.4 Adopt

- Service: `HoldingAdoptionService` — explicit unmanaged → one strategy
- API: `POST /api/holdings/{id}/adopt` `{ strategy_id }`
- UI: Holdings Sell menu → **Adopt** (strategy picker modal)
- Target init (destination does **not** already own the stock): `target_amount = filled_amount = invested_amount` (remaining BUY = 0)
- OD-15: attribute unmanaged buys via executed `HOLD_POSITION` (not OPEN/INCREASE → no OD-11 cooldown)
- Same-stock destination already owned: **V4-SPEC-001 merge** (2026-08-28) — one Strategy position; combined qty/cost; final `avg_buy_price` 2 dp half-up; destination `target_amount` unchanged
- Audit: `portfolio_holding_adoptions`

### Files

- `app/Services/Strategy/HoldingOwnershipBackfill.php`
- `app/Services/Ownership/HoldingAdoptionService.php`, `Models/HoldingAdoption.php`
- Migrations `2026_08_24_000004_*`, `2026_08_24_000005_*`
- `HoldingController`, `routes/api.php`, `HoldingsPage.jsx`
- Tests: `HoldingOwnershipBackfillTest`, `HoldingAdoptionTest`; identity test updated

### Tests

Focused: HoldingOwnershipBackfill + V3DomainIdentity (17); HoldingAdoption (5). JS Adopt control assertion in `staggeredEntryUiPhase2.test.mjs`.

---

## V3 OD-10 / §34.1 — Corporate-action quantity follows parent owner (2026-08-24)

**Status:** **DONE** (WS1 residual OD-10). Frozen. Does **not** touch WS4 recall/lending, §34.3, §34.4, or §34.8.

Authoritative: V3 §10.7, §34.1; **OD-10 (frozen).** Parent-owner attachment — not pro-rata — governs CA quantity ownership.

### Root cause (pre-fix)

- **Bonus:** `CorporateActionService::previewBonus` used blended `quantityAsOfDate` (profile+stock replay) and `applyBonus` created a single `SOURCE_BONUS` buy **without** `recommendation_id`, so multi-strategy ledgers collapsed to one unmanaged lot on recalculate.
- **Split:** In-place transaction rescale already preserved per-row `recommendation_id`; owner isolation held when the ledger was attributable. No split logic change required beyond bonus attribution and shared owner-resolution helper.

### Implemented behaviour

| CA type | Owner resolution |
|---------|------------------|
| **Bonus** | `HoldingsCalculationService::quantityAsOfDateByOwner` → per-owner eligible qty → one `SOURCE_BONUS` buy per owner. Strategy-owned bonus reuses the parent lot's latest recommendation-linked buy (`recommendation_id`); rare fallback creates a `HOLD_POSITION` executed rec (does not start OD-11 BUY cooldown). Unmanaged parent → unmanaged bonus. |
| **Split** | Existing per-transaction rescale; ownership travels with each row's existing `recommendation_id`. |
| **Ambiguous ledger** | When `partitionTransactionsByOwner` is not attributable (e.g. manual sell with two open strategy owners), bonus stays a **single blended unmanaged** buy — no invented owners. |

`CorporateActionService::apply()` now recalculates via `recalculateOwnerLotsForProfileStock` and returns additive `holdings` (all owner lots) alongside legacy `holding` (first representative row).

`TransactionWriteService::normalizeCore` preserves `SOURCE_BONUS` when `recommendation_id` is present (OD-10 parent-owner attribution without flipping to `SOURCE_RECOMMENDATION`).

### Intentional fallback (unchanged)

- Ledgers that cannot be fully attributed still use one blended `portfolio_holdings` row (`owner_key = unmanaged`).
- **V4-SPEC-003 (2026-08-28):** split/bonus now restate cost/average (ledger path), trailing high (OHLCV ratio), derived stop (OD-13), and preserve OD-12 rupee `target_amount`. Rights remain SPEC-002 (no special CA; exercise = normal purchase, implemented 2026-08-28).

### Files

- `app/Services/HoldingsCalculationService.php` — `quantityAsOfDateByOwner`
- `app/Services/CorporateActionService.php` — per-owner bonus preview/apply; `holdings` in apply response
- `app/Services/TransactionWriteService.php` — bonus+recommendation source guard
- `tests/Unit/CorporateActionOd10OwnershipTest.php` — Cases 1–6

### Tests

`tests/Unit/CorporateActionOd10OwnershipTest.php` (6 tests, 41 assertions). Regressions green: CorporateAction suite (32), `HoldingsCalculationServiceTest` (1), `HoldingPresentationServiceTest` + Risk (17), Entry + WS3 generation (18), Lending/Recall (153).

### §34.1 / WS1 status

OD-10 corporate-action parent-owner attribution is **complete** for supported quantity-changing CAs (**bonus**, **split**). WS1 ownership substrate is no longer blocked on OD-10; remaining WS1 gaps (if any) are adoption, ambiguous cross-owner sell policy, and other non-CA items — not CA quantity blending.

---

## V3 §34.8 / OD-17 — History depth & holdings charts (2026-08-24)

**Status:** **DONE** (final audit 2026-08-24). Campaign completion is `all_available`; charts support 5Y / All / Since Buy; no V3 product 550-day ceiling.

- Removed V3 product coupling to the old numeric history-depth ceiling in the history-depth campaign service (`HistoryDepthBackfillService` now marks completion by `all_available` pass mode, not numeric day target).
- Stock price history API now supports `range=all|since_buy` for held stocks; `all` returns full cached instrument history, while `since_buy` remains a separate current-episode view.
- Holdings stock history page now exposes explicit **All** vs **Since Buy** toggle and labels their semantics.
- Force sync for held stocks now requests all-available provider/listing history through `StockPriceHistoryService::fetchAllAvailableHistory`.
- Config/CLI: unused numeric `HISTORY_DEPTH_TARGET_DAYS` campaign target removed from operational completion messaging; re-arm with `--reset`.



**Status:** Phase 1 domain + Phase 2 live pipeline / API / UI / help **DONE**.

Authoritative: V3 §11.2 (BUY cooldown), §12 (staggered entry / target), §34.4 item 4; OD-11, OD-12. Does **not** touch §34.3 risk/exit or WS4 lending/recall/capital-resolution ordering.

### Spec rules implemented

| Rule | Implementation |
|------|----------------|
| OD-11 BUY cooldown = **1 calendar day** | `BuyCooldownEvaluator` — Day 0 opportunity allowed; Day 1 suppressed; Day 2 elapsed. Key `(stock, strategy)`. |
| Cooldown clock | Starts on BUY **recommendation generation** (`generated_at`), including cancelled rows. Not fill/approval. |
| Stale-cancel | Skips OPEN/INCREASE pairs while cooldown active; does not clear cooldown; does not cancel `pending_execution`. |
| First entry | `first_entry_pct` (strategy `portfolio_rules`, default **50%**) × current **position target amount**. |
| Subsequent INCREASE | `remaining = max(0, target − filled)` — not a second 50% slice. |
| Persist target | `portfolio_holdings.target_amount` (strategy-owned / `owner_key`). |
| Filled amount | `portfolio_holdings.filled_amount` synced from actual `invested_amount` on recalculate; never from target or unfunded allocation. |
| Whole shares | `WholeShareQuantityCalculator` FLOOR(amount / raw close). |
| Min actionable | Platform default ₹5,000; portfolio override `minimum_actionable_buy_amount` (OD-12 §12.4). |
| Allocator | Existing `ReturnQualityCapitalAllocator` receives **this-cycle** amount; `capital_allocation.target_amount` remains this-cycle (lending BC); `position_target_amount` is OD-12 full target. |
| WS4 capital resolution | Unchanged and downstream of allocation; `actual_execution_amount` remains authoritative for executable cash. |

### Live path

`RecommendationGenerationPipeline`: decide action → `sizeOpenOrIncrease` (cooldown + stagger + floor + min actionable) → rank → allocate → apply outcomes → persist (upsert holding target). SELL/EXIT untouched by cooldown.

### API / UI / help (Phase 2)

- Holdings API: `target_amount`, `filled_amount`, `remaining_target_amount`
- Recommendations API: `position_target_amount`, `this_cycle_amount`, `position_filled_amount`, `remaining_target_amount`, `is_first_entry`
- Holdings UI: optional **Target** column (filled / remaining in Complex)
- Recommendations detail: Position target (OD-12) block
- `appDocumentation.js`, `API_DOCUMENTATION.md`

### Files

- Services: `app/Services/Entry/{BuyCooldownEvaluator,StaggeredEntryCalculator,WholeShareQuantityCalculator,MinimumActionableAmountResolver,StrategyPositionTargetService}.php`
- Migration: `2026_08_24_000003_add_target_and_filled_amount_to_portfolio_holdings.php`
- Pipeline / Holding / HoldingsCalculation / HoldingPresentation / Trading OS V1 HTTP (`App\Http\Controllers\Api\V1\TradingOs\*`)
- Factory default `portfolio_rules.first_entry_pct = 50`
- UI: `HoldingsPage.jsx`, `RecommendationsPage.jsx`

### Tests

`tests/Unit/Entry/StaggeredEntryCalculatorsTest.php`; `tests/Feature/Entry/StaggeredEntryPhase1FoundationTest.php`; JS `tests/js/staggeredEntryUiPhase2.test.mjs`.

### Explicitly not in §34.4

- Adoption, broker automation, horizon UI
- Redesigning WS4 capital resolution or §34.3 SL/trailing
- ~~Strategy editor UI for `first_entry_pct`~~ — **DONE** (2026-08-24 §29 Strategy-page controls; engine default still 50%)

---

## V3 Phase 3 — Exit / SL / Trailing API·UI·Help sync (2026-08-24)

**Status:** Phase 3 **DONE**. Do **not** begin §34.4 here.

Authoritative: V3 §13–§15, §13.2, §13.5, §34.3; OD-13 / OD-14 / OD-22. Presentation/configuration/docs only — Phase 1 calculators and Phase 2 precedence frozen.

### Implemented

- **Settings:** Existing Portfolio Stop-Loss % and Portfolio Trailing Stop % remain independent (seed trailing **15%**); labels/help clarify independence.
- **Holdings presentation:** Complex view shows portfolio trailing % from peak raw close; Avg Buy shows stop-loss price (and fill WAVG when distinct) from `stoploss_summary`. Added `weighted_average_fill_cost` to summary payload.
- **Recommendations API/UI:** Top-level `primary_exit_reason` + `exit_attribution` on serialize; detail modal shows primary exit reason with human labels (canonical values preserved).
- **Transactions:** History column `Exit reason` from persisted `exit_reason`; recommendation execution block shows `execution.exit_reason` when present.
- **Help/docs:** `appDocumentation.js`, `API_DOCUMENTATION.md`, this file — portfolio SL/trailing vs strategy-specific exits; §13.2 precedence; strategy JSON `trailing_stop` still documented as strategy-only V1 proxy (not claimed removed).

### Explicitly not in Phase 3

- §34.4 staggered entry / BUY cooldown / target amount / adoption
- Domain changes to Phase 1/2 or recall/lending
- ~~Horizon configuration UI~~ — **DONE** (2026-08-24 §29 Strategy-page control; evaluator unchanged)
- Removing V1 strategy JSON `trailing_stop` proxy

### Tests

`tests/Feature/Risk/PortfolioRiskPhase3PresentationTest.php`; JS `tests/js/exitAttributionLabels.test.mjs`, `tests/js/appDocumentationExitRiskHelp.test.mjs`, `tests/js/portfolioRiskUiPhase3.test.mjs`; plus Phase 1/2 + WS3 + WS4 regression.

---

## V3 Phase 2 — Exit precedence live wiring (2026-08-24)

**Status:** Phase 2 **DONE**. Phase 3 API/UI/help sync **DONE** (see above).

Authoritative: V3 §13–§16, §13.2, §13.5, §28.6, §34.3, §35.7; OD-13 / OD-14 / OD-22. Builds on Phase 1 risk calculators.

### Implemented

- **`ExitPrecedenceEvaluator`** — §13.2 order: `strategy_exit` > `stop_loss` > `trailing_stop` > `horizon_expiry`. Portfolio SL/trailing use Phase 1 calculators; strategy exit remains `ExitStrategyEvaluator` (strategy-specific only). Optional horizon via `horizon_calendar_days` / `portfolio_rules.horizon_calendar_days` / `exit_strategy.horizon_calendar_days` (no invented default).
- **Live path:** `RecommendationGenerationPipeline::buildDrafts` + `appendPortfolioRiskExitOnlyDrafts` (+ screener-exit path) evaluate precedence per strategy-owned holding; EXIT when any mechanism wins.
- **Attribution:** `evidence.exit_attribution` + `execution_plan.primary_exit_reason` / `exit_attribution`; `TradingRecommendation::primaryExitReason()`.
- **§13.5 sell copy:** `portfolio_transactions.exit_reason` (migration `2026_08_24_000002_*`); `TransactionWriteService` copies from recommendation on SELL fill.
- **Owner isolation:** evaluations use strategy-scoped holdings + Phase 1 ownership episodes.

### Explicitly not in Phase 2

- Phase 3 Settings/help redesign beyond existing Phase 1 fields
- Staggered entry / BUY cooldown / adoption / recall-lending changes
- Removing V1 strategy JSON `trailing_stop` proxy from `ExitStrategyEvaluator` (still strategy_exit only)

### Tests

`tests/Unit/Risk/ExitPrecedenceEvaluatorTest.php` (9); `tests/Feature/Risk/ExitPrecedencePipelineTest.php` (2); regression WS3 generation + WS4 Lending green.

---

## V3 Phase 1 — Portfolio SL / Trailing / Exit foundation (2026-08-24)

**Status:** Phase 1 domain foundation **DONE**. Phase 2 (§13.2 live EXIT precedence / attribution) **DONE** (see above).

Authoritative: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) §13–§15, §21, §34.3, §35.7; OD-13, OD-14, OD-22.

### Implemented

- **`portfolio_trailing_percent`** — portfolio/common setting via `ProfileSettingsService` (default **15%**, OD-22). Independent of `default_stoploss_percent`. Migration `2026_08_24_000001_seed_portfolio_trailing_percent_od22` seeds existing profiles idempotently. Settings API + Settings page expose both %.
- **Pure calculators:** `App\Services\Risk\PortfolioStopLossCalculator` (OD-13 weighted-average fill cost + stop price; OD-14 raw-close hit test); `PortfolioTrailingStopCalculator` (peak raw close × trailing % — not unrealized-%, not SL %, not strategy JSON).
- **`OwnershipEpisodeService`** — per-holding entry date, episode fills, peak/latest raw `close_price` scoped by `owner_key` / `strategy_id`.
- **Holdings attribution:** `HoldingsCalculationService` partitions ledger by recommendation owner when every row is determinable and sells do not oversell that owner; otherwise blended fallback (documented). Same-symbol strategy lots keep independent peaks when buys are recommendation-linked.
- **Presentation:** `HoldingPresentationService` uses portfolio trailing % and owner-scoped peak/entry; adds `stop_loss_price` / `portfolio_trailing_percent` / `weighted_average_fill_cost` to `stoploss_summary`.

### Explicitly not in Phase 1

- §13.2 exit precedence / live EXIT generation / primary attribution
- Strategy horizon wiring; adoption workflow; recall/lending changes
- V1 `ExitStrategyEvaluator` `trailing_stop` unrealized-% proxy left in place (labelled; Phase 2 coexistence)

### Limitation (not invented)

Manual BUY (unmanaged) + EXIT sell linked to a strategy recommendation that does not own the shares is **rejected** on new writes (V4-SPEC-005) rather than blending. Historical rows without `owner_key` may still blend on recalc.

### Tests

`tests/Unit/Risk/PortfolioRiskCalculatorsTest.php`; `tests/Feature/Risk/PortfolioRiskPhase1FoundationTest.php`; regression: HoldingsCalculation, HoldingPresentation, Lending/WS4, V3RecommendationGeneration, StoplossService.

---

## V3 WS4 gap-closure — live wiring (2026-08-21)

**Status:** Five acceptance-audit integration gaps closed. **§6.0 close-at-actual follow-up (2026-08-22):** residual partial-lend no longer blocks execution of already-funded capital.

### Gaps closed

1. **Live capital resolution** — `RecommendationLendingCoordinator::syncAfterGenerated` calls `applyCapitalResolutionBeforeTradeMode` before partial lending / trade-mode handling. Uses allocator-assigned own (`own_available_override`) then recall; updates `actual_execution_amount` / `suggested_investment_amount`. Manual/Semi/Auto unchanged (still `pending_review` after generation).
2. **Auto bridge lender** — `RecallBridgeLenderSelector` (lowest strategy id among capacity+cushion winners targeting max settle ≤ R). `RecallBridgeLoanService::create` enforces cushion + lender capacity (context overrides for tests).
3. **Immediate fulfilment** — `RecallImmediateSettlementService::apply` chains `RecallFulfilmentService::afterImmediateSettlement` after settle/pending. Idempotent skip when recall already past `requested`/`immediate_settlement`. Daily `portfolio:process-recall-settlements` retained.
4. **§6.15 normal-loan good-faith** — `GoodFaithCapitalLoanRepaymentService` via existing `CapitalLoanRepaymentService`; scheduled with bridge good-faith.
5. **POST `/api/v1/capital/recalls`** — `RecallService::requestAndProcess` runs create → immediate settlement (auto bridge) → fulfilment; response reflects post-workflow state.

### DEP-CAPITAL-PRIORITY §6.0 close-at-actual (2026-08-22)

After capital resolution with `close_at_actual` and `actual_execution_amount > 0`, `canEnterPendingExecution` allows the recommendation through Manual/Semi/Auto at that amount even if an optional residual capital request remains `awaiting_lender_selection`. Residual lending may still top up later; on commit, executable rises only to `min(requested, own+remainder)` (never own+full ₹5k loan). Example: ₹20k request + ₹15k own + ₹4k recall → **₹19k executable** without waiting for ₹1k.

### Tests

`tests/Feature/Lending/RecallGapClosureTest.php`, `tests/Feature/Lending/CapitalPriorityCloseAtActualTest.php` (+ RecommendationLending / Recall regression).

---

## V3 WS4 Phase 3B-2 — Recall notifications + help sync (2026-08-21)

**Status:** Telegram domain notifications + `appDocumentation.js` sync. No UI redesign.

### Notifications (reuse `NotificationEngine`)

| Event | Type | Idempotency key pattern |
|-------|------|-------------------------|
| Recall requested | `recall_requested` | `recall-{id}-requested-telegram` |
| Pending held | `recall_pending_held` | `recall-{id}-pending_held-telegram` |
| Settlement batch / completed | `recall_settlement` / `recall_completed` | `recall-{id}-settlement-{settledTotal}-telegram` |
| Bridge created | `recall_bridge_created` | `bridge-{id}-created-telegram` |
| Bridge partial / complete | `recall_bridge_partial_repay` / `recall_bridge_completed` | `bridge-{id}-repay-{repaidTotal}-telegram` |
| Sale initiated | `sale_proceeds_initiated` | `proceeds-{id}-sale-initiated-telegram` |
| Proceeds applied | `sale_proceeds_applied` | `proceeds-{id}-applied-telegram` |
| Partial capital resolution | `capital_resolution_partial` | `capital-partial-{rec\|adhoc}-{actual}-telegram` |
| Capital required (UNFUNDED / PARTIAL / AWAITING) | `capital_required` | `capital-required-{recId}-{status}-v{version}-telegram` |
| Lending commitment | `lending_commitment` | `lending-commitment-{loanId}-telegram` |
| Lending failure | `lending_failure` | `lending-failure-{requestId}-{reason}-telegram` |
| Capital committed / execution ready | `capital_committed` | `capital-committed-{recId}-loan-{loanId}-telegram` |

Channel: existing Telegram + `notifications_enabled`. Messages via `NotificationMessageComposer`. Emitters: `RecallService`, `RecallBridgeLoanService`, `ProceedsApplicationService`, `RecallLiquidationService`, `CapitalResolutionService`, `RecommendationLendingCoordinator`, `CapitalRequestApprovalService`.

See also **V3 §30 notification hooks + §19 SuccessCriteriaEvaluator (2026-08-26)** at top of this file.### Help

Updated topics: `cash`, `recommendations`, `notifications`, `settings` (+ cash enrichment). Terminology: **Recall Bridge Loan**, **Proceeds from Stock Sale**. Removed “recall not available/implemented”. Documented capital priority, 75% rule, good-faith automation; no auto-return toggle framing.

### Tests

`tests/Feature/Lending/RecallNotificationTest.php`, `tests/js/appDocumentationRecallHelp.test.mjs`.

## V3 WS4 Phase 3B-1 — Recall UI integration (2026-08-21)

**Status:** Minimal UI on existing screens. **Not** in this phase: notifications, help/`appDocumentation` sync, redesign.

### Reused screens

| Screen | Change |
|--------|--------|
| `RecommendationsPage` | Capital-resolution block in detail modal (`RecommendationCapitalResolution`) |
| `CashManagementPage` | Lent/borrowed columns; `CapitalRecallPanel` (recalls / bridge / proceeds); ledger `movement_label` |
| `SettingsPage` (Portfolio) | Recall period GET/PUT via `/v1/capital/recall-period` |

### New files

- `resources/js/src/utils/capitalRecallUi.js`
- `resources/js/src/components/RecommendationCapitalResolution.jsx`
- `resources/js/src/components/CapitalRecallPanel.jsx`
- `tests/js/capitalRecallUi.test.mjs`

### Auto-return toggle

**None found in the React UI.** No control removed. v0.28 framing is automated/rule-driven in the new Cash panel copy only.

### Terminology

Uses **Recall Bridge Loan** and **Proceeds from Stock Sale** only (no Soft Loan / Return on Stock Sale).

## V3 WS4 Phase 3A — Recall HTTP APIs (2026-08-21)

**Status:** Thin HTTP APIs over Phase 1–2 domain. **Not** in this phase: UI, notifications, help sync.

### Endpoints (`ApiEnvelope`, Sanctum + active portfolio)

| Method | Path | Notes |
|--------|------|-------|
| GET/PUT | `/api/v1/capital/recall-period` | Effective period; SoT ≠ `min_recall_at` |
| GET/POST | `/api/v1/capital/recalls` | List / request (domain validation) |
| GET | `/api/v1/capital/recalls/{id}` | Detail + bridge + proceeds |
| GET | `/api/v1/capital/bridge-loans` | Read-only Recall Bridge Loan |
| POST | `/api/v1/capital/bridge-loans` | **405** — workflow only |
| GET | `/api/v1/capital/pending-sale-proceeds` | Proceeds from Stock Sale |
| POST | `…/mark-available` | **405** — settlement job only |
| POST | `/api/v1/capital/resolve` | Run resolution; optional attach to rec |
| GET | `/api/v1/recommendations/{id}/capital-resolution` | UI contract (actual execution amount) |

### Controllers / presenters

- `CapitalRecallController`, `RecallPeriodController`, `RecallBridgeLoanController`, `PendingSaleProceedsController`, `CapitalResolutionController`
- `CapitalRecallPresenter`, `CapitalResolutionStatusService`
- Cash ledger `movement_kind` / labels for Proceeds from Stock Sale / bridge / recall (V4-SPEC-004 now also sets `entry_type` to `loan`/`recall`/`bridge`)
- Recommendation detail includes `capital_resolution` when detailed

### Docs

- [`specs/architecture/domains/REST-API-Specification.md`](specs/architecture/domains/REST-API-Specification.md)
- [`specs/architecture/audit/API_INVENTORY.md`](specs/architecture/audit/API_INVENTORY.md)

### Tests

`tests/Feature/Lending/RecallPhase3aApiTest.php` (+ Phase 1/2 + repayment regression).

## V3 WS4 Phase 2 — Recall fulfilment / liquidation / proceeds (2026-08-21)

**Status:** Domain liquidation + proceeds + jobs implemented. **Not** in this phase: HTTP APIs, UI, notifications, help sync.

Authoritative: V3 **v0.28**; [`specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md`](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md).

### Introduced

| Component | Why |
|-----------|-----|
| `SaleBufferCalculator` | Platform-wide **0.5%** buffer (`required` → `target = required + 0.5%`) |
| `WeakestPositionRanker` | OD-16 `window_return_pct`; default window **90 calendar days** (3 months); DEP-WEAKEST-HISTORY shorten |
| `RecallLiquidationService` | Weakest-first sells; `applyCash=false`; schedule PendingSaleProceeds |
| `ProceedsApplicationService` | Release cash + apply to recall or bridge; excess retained |
| `RecallFulfilmentService` | Continue pending_held / after-immediate → liquidation; `processSettlements` |
| `GoodFaithBridgeRepaymentService` | Voluntary bridge repay any amount ≤ outstanding when liquid |
| `portfolio:process-recall-settlements` | Daily 21:30 schedule; idempotent |

### Schema

Migration `2026_08_21_000002_v3_pending_sale_proceeds_phase2.php` adds on `portfolio_tos_pending_sale_proceeds`: `expected_amount`, `obligation_type` (`recall`\|`bridge`), `recall_bridge_loan_id`, `transaction_id`, `cash_released_at`, buffer breakdown columns. `amount` = **actual** proceeds.

### Behaviour notes

- Sale execution ≠ available capital; cash is released as **Proceeds from Stock Sale** only at `available_at` (~1 day). V4-SPEC-004 posts that inflow as signed `recall` or `bridge` (not `deposit`).
- Actual vs expected: only `amount` (actual) is released/applied.
- Liquidate only unresolved gaps; skip if pending proceeds already cover.
- Bridge repayment: no ₹5k blocks; no recall against bridge lender.
- Same `CapitalRecall` instance continues after partial immediate settlement.

### Tests

`tests/Feature/Lending/RecallPhase2FulfilmentTest.php` (+ Phase 1 + `CapitalLoanRepaymentServiceTest` regression).

## V3 WS4 Phase 1 — Recall / Capital Resolution foundation (2026-08-21)

**Status:** Backend/domain foundation implemented. Liquidation / proceeds / jobs = Phase 2 (see section above). UI / APIs / notifications = Phase 3.

Authoritative: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) **v0.28**; [`specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md`](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md).

### Reused

- `CapitalLoan`, `CapitalLoanReturn`, `CapitalRequest`
- `CapitalLoanRepaymentService` (normal repay any amount ≤ outstanding)
- `PortfolioCapitalAccountingService` (now also sums Recall Bridge Loan outstanding into lent/borrowed)
- Strategy available capital from accounting snapshot

### Introduced (why)

| Component | Why |
|-----------|-----|
| `CapitalRecall` + `portfolio_tos_capital_recalls` | Persist recall lifecycle/history |
| `RecallBridgeLoan` + `portfolio_tos_recall_bridge_loans` | Distinct from normal investment loans (no Soft Loan) |
| `PendingSaleProceeds` | Hook for ~1-day proceeds delay (DEP-SALE-PROCEEDS) |
| `RecallPeriodResolver` | Dynamic OD-07 period (not `min_recall_at` SoT) |
| `RecallEligibilityService` | Eligibility + one-active + follow-up cooldown |
| `RecallAmountCalculator` | FULL/PARTIAL sizing (DEP-RECALL-SIZE) |
| `RecallService` | Request + state transitions; cancel rejected |
| `RecallBridgeEligibilityCalculator` / `RecallBridgeLoanService` | Bridge shortfall + 10% cushion; create/repay |
| `RecallImmediateSettlementEvaluator` / `…Service` | 75%/100% immediate settle vs pending_held |
| `CapitalResolutionService` | Own → recall → borrow shortfall; close_at_actual |
| `SaleProceedsAvailabilityService` | Schedule/refresh proceeds availability |

### Behaviour notes

- Eligibility = `committed_at` + **current** portfolio recall period (default 14). Changing period does not restart the clock.
- Follow-up cooldown = `floor(period/2)` after completed recall.
- Immediate settle ≥75% of **R**: settle max available ≤100%; remainder of R → `pending_held` (liquidation Phase 2).
- Below 75%: no tiny partial / no tiny bridge → `pending_held`.
- Capital resolution sets `close_at_actual=true`, `hold_for_remainder=false`.

### Tests

`tests/Unit/Lending/RecallDomainCalculatorsTest.php`, `tests/Feature/Lending/RecallPhase1FoundationTest.php`, plus existing `CapitalLoanRepaymentServiceTest` regression.

## V3 WS4 — Recall / Capital Resolution / Recall Bridge Loan (spec v0.28, 2026-08-21)

**Status:** Specification complete; Phase 1 + Phase 2 domain code shipped. Phase 3 (APIs/UI/notifications/help) still open.

- Authoritative product SoT: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) **v0.28**.
- Implementation delta: [`specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md`](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md).
- New frozen DEPs: **DEP-CAPITAL-PRIORITY**, **DEP-RECALL-SIZE**, **DEP-RECALL-IMMEDIATE-75**, **DEP-RECALL-BRIDGE**, **DEP-RECALL-FOLLOWUP**, **DEP-SALE-PROCEEDS**.
- Terminology: **Recall Bridge Loan** (not Soft Loan); **Proceeds from stock sale** (not Return on stock sale).

## WS4 Step 7 — Capital Loan Repayment / Return (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) **v0.28** (was written against v0.27; repayment behaviour preserved — any amount ≤ outstanding, not ₹5,000 multiples).

### Implemented

`App\Services\Lending\CapitalLoanRepaymentService::repay($loan, $amount)` in a DB transaction with `lockForUpdate` on the loan row.

- Rejects `amount <= 0`, amount greater than current `outstanding`, and loans already fully returned (`status = returned` or `outstanding <= 0`).
- Creates one `CapitalLoanReturn` (`loan_id`, `capital_request_id`, `amount`, `returned_at`).
- Reduces `CapitalLoan.outstanding` by exactly the returned amount. **`principal` is never changed.**
- Status after a successful return: `partially_returned` if outstanding remains `> 0`; `returned` if outstanding is exactly `0`.
- No holding/ownership/stock transfer. No `allocation_pct` change. No strategy cash accounts.
- `lent_capital` / `borrowed_capital` still come only from `CapitalLoan.outstanding` (WS2). Return rows are not summed.

### Cash ledger

Physical cash is one portfolio pool. Loan commitment never moved net cash; repayment is the reverse accounting claim, not a deposit/withdrawal between strategy accounts.

**V4-SPEC-004 (2026-08-28):** dedicated `loan` / `recall` / `bridge` ledger types now exist. Commitment and voluntary repayment post signed `loan` pairs that **net to zero** on the physical pool so the Cash statement shows the trail. Physical cash balance is still unchanged by `repay()`.

### Not started

**Recall**, recall eligibility (OD-07), FIFO loan selection (OD-09), weakest-position sells (OD-16), Recall Bridge Loan, capital-resolution orchestrator, notifications, UI, HTTP repay endpoint, UNFUNDED loan sizing — see [`specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md`](specs/architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md).

Normal repayment is **not** forced to ₹5,000 multiples (aligned with v0.28 **DEP-RECALL-SIZE** / §6.1). Step 7 correctly accepts any positive amount ≤ outstanding.

### Tests / help

`tests/Feature/Lending/CapitalLoanRepaymentServiceTest.php`. Cash help: loan repayment concept; statement lists V4-SPEC-004 `loan` / `recall` / `bridge` in addition to deposit/withdrawal/adjustment/buy/sell.

## WS4 Step 6 — Lending-Aware Trade Execution (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) **v0.27**. Spec file was **not** edited.

### Execution flow

Existing path unchanged: trade Approve → `pending_execution` → `POST /api/transactions` (or order execute) → `TransactionWriteService` cash BUY on the **one** physical pool → holdings recalc → `ExecutionEngine::completeRecommendationFromTransaction` → rec `executed`.

`RecommendationLendingCoordinator::assertCanExecute()` is the execution-time gate (same rule as Step 5 pending-execution). `FUNDED` and `capital_committed` may execute. `UNFUNDED`, `partially_funded`, and `awaiting_lender_selection` cannot. Sells skip the buy gate.

`capital_committed` does **not** auto-execute. User still Approves, then records the fill.

### Funding amounts

`CommittedLendingExecutionAmounts`: `own_amount` = WS3 allocated own capital; `borrowed_amount` = committed loan principal; `intended_amount` = own + remainder = **original target** (not own + loan). ₹5,000 ceiling excess stays as outstanding borrowed capital and is **not** invested. OD-06 and `PartialLendingAmountCalculator` are unchanged.

Pending-execution cash reservation remains the **own-funded** suggested amount. The loan is not a second reservation and is not deposited as extra cash; V4-SPEC-004 `loan` rows for commitment net to zero on the pool.

### Ownership

New holdings created from recommendation-linked ledger rows take `strategy_id` of the **borrower** (recommendation owner). Lender receives no holding. `allocation_pct` is not modified.

### Idempotency / linkage

One request ↔ one loan ↔ one recommendation. Rec `executed_transaction_id` plus `capital_allocation.execution_transaction_id` / `capital_loan_id` / `capital_request_id`. A second fill for the same rec or a second use of the same committed loan is rejected. No extra tables.

### Failure

If the fill fails after the loan is committed, the loan stays **outstanding**. No `CapitalLoanReturn`, no fake deposit, no silent discard. Rec stays `pending_execution` (or never leaves `pending_review` if Approve was blocked).

### Not in this step

Repayment, recall, FIFO recall, weakest-position sells, UI, notifications, jobs, UNFUNDED loan sizing.

### Tests / help

`tests/Feature/Lending/RecommendationLendingExecutionTest.php`, `tests/Unit/Lending/CommittedLendingExecutionAmountsTest.php`. Pending-execution and unfunded help text updated.

## WS4 Step 5 — Recommendation Lifecycle + Lending Integration (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) **v0.27**. Spec file was **not** edited.

### Implemented

- After WS3 persist, `RecommendationLendingCoordinator::syncAfterGenerated()` runs. **PARTIALLY_FUNDED** OPEN/INCREASE stays that action; target and own allocation are kept. Remainder `target − own` is sized with `PartialLendingAmountCalculator` (ceil to ₹5,000, **not** OD-06 1%). One `CapitalRequest` is created (`displayed`, no lender). Status in `execution_plan.capital_allocation` becomes `awaiting_lender_selection`.
- **UNFUNDED** (own = 0 after capital resolution) stays OPEN/INCREASE. If eligible lenders exist, a same-cycle `CapitalRequest` is created with DEP-PARTIAL-ATOMIC loan size and status becomes `awaiting_lender_selection`. If no eligible lenders, it remains `unfunded` with no request. Cannot enter `pending_execution` until a loan is committed (actual execution amount stays 0). See **V3 residual — UNFUNDED zero-own lending offer**.
- Trade Approve (`RecommendationLifecycleService::recordReview` → `pending_execution`) is blocked until `funded`, `capital_committed`, or a committed loan exists. Legacy recs with no capital-allocation status still approve as before. Sells/exits are unchanged.
- `CapitalRequestApprovalService` success still creates one `CapitalLoan` (accounting outstanding only), then `markCapitalCommitted()` sets `capital_committed` and stores `capital_loan_id`. **Loan commitment does not execute the trade**, does not write cash ledger rows, does not create holdings, does not change `allocation_pct`, and does not auto-move to `pending_execution`. The user must still Approve the recommendation afterward.
- Duplicate requests: `CapitalRequestService::createRequest` and the coordinator reuse any active request (`displayed` / `awaiting_approval` / `revalidation_failed` / `committed`).

### Intentionally inactive

Broker execution, repayment, recall, FIFO recall, weakest-position sells, UI, notifications, jobs. WS2 accounting formulas unchanged.

### Unresolved

UNFUNDED full-gap loan size is **resolved** as DEP-PARTIAL-ATOMIC (`ceil(gap/5000)×5000`); OD-06 1% remains reservation-only. See residual UNFUNDED zero-own lending offer (2026-08-24).

### Tests / help

`tests/Feature/Lending/RecommendationLendingLifecycleTest.php`, `tests/Unit/Lending/UnfundedLendingAmountCalculatorTest.php`. In-app help: unfunded/partially funded + lifecycle Approve gating.

## WS4 Step 4 — Lender Discovery & Approval Substrate (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited.

### Implemented

- `LenderEligibilityService` — eligible lenders from WS2 `available_for_lending` (not physical cash, not OD-20 unallocated cash). Borrower excluded. Same profile only. Amount must be ≥ ₹5,000 and a ₹5,000 multiple (`CommittedLoanAmount`). Displayed/awaiting requests do not reduce AFL (Step 2: only outstanding loans).
- `LenderRankingService` — **OD-08:** available-for-lending **percentage** descending, then **amount** descending. Exact ties: `strategy_id` ascending (deterministic; spec allows any tied lender).
- `CapitalRequestService::createRequest` — explicit create; status `displayed`; no lender chosen. `eligibleLenders()` delegates to eligibility + ranking.
- `CapitalRequestApprovalService` — approve/reject in a DB transaction with `lockForUpdate`. Success: one `CapitalLoan` (principal = outstanding = request amount, `committed_at` now, status `outstanding`); request `committed` with `lender_strategy_id`, `approved_at`, `approved_by`. Availability revalidated at approval. Failure: no loan; status `revalidation_failed` when the selected lender is not currently eligible. Reject: `rejected`, no loan. Committed requests cannot be rejected or approved again.
- HTTP (active portfolio, Sanctum): `GET /api/v1/capital/requests/{id}/lenders`, `POST .../approve` (`lender_strategy_id`), `POST .../reject`.

Committed loans remain **accounting claims only**. No cash ledger, holdings, reservations, or recommendation status changes. Configurable lending-limit settings are not persisted (spec item 8 skipped — no invented limit). UNFUNDED sizing is still not chosen; createRequest requires an already ₹5,000-aligned amount.

### Intentionally inactive

Lifecycle gating, execution, repayment processing, recall, OD-09 FIFO, UI, jobs, notifications, auto-create from recommendations.

### Tests

`tests/Unit/Lending/LenderRankingServiceTest.php`; `tests/Feature/Lending/LenderEligibilityServiceTest.php`; `CapitalRequestServiceTest.php`; `CapitalRequestApprovalServiceTest.php` — 29 tests, 102 assertions.

## WS4 Step 3 — Partial Lending Sizing Substrate (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited.

### Implemented

Deterministic **PARTIALLY_FUNDED remainder** loan size only (DEP-PARTIAL-ATOMIC). No capital requests, loans, lifecycle, execution, or accounting changes. The live pipeline does not call this helper yet.

- Remainder = `max(0, target_amount − own_allocated_amount)` — **not** `atomic_allocation − own`.
- `loan_amount` = `ceil(remainder / 5000) × 5000` via `App\Support\CeilToRupee5000` (integer `intdiv` ceiling). Zero remainder → ₹0. Any positive remainder → at least ₹5,000.
- API: `App\Services\Lending\PartialLendingAmountCalculator::calculateForRemainder()` and `calculateForPartialRemainder($target, $own)`. No generic `calculateLoanAmount()`.
- **Not** OD-06: does not use `ReturnQualityCapitalAllocator::atomicAllocation()` (1% then ceil). Example: remainder ₹10,000 → loan ₹10,000; OD-06 would be ₹15,000.
- **UNFUNDED** full-gap sizing is **not** this helper; `UnfundedLendingAmountCalculator` now uses the same DEP-PARTIAL-ATOMIC ceil (residual 2026-08-24). This helper still does not size UNFUNDED loans.

### Tests

`tests/Unit/Lending/PartialLendingAmountCalculatorTest.php` — remainder/ceil cases, target−own vs atomic−own, OD-06 independence, no UNFUNDED API.

## WS4 Step 2 — Accounting Substrate (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited.

### Implemented

`PortfolioCapitalAccountingService::snapshot()` now reads outstanding `CapitalLoan` rows. Physical cash formula is unchanged. No cash ledger entries, no strategy bank accounts, no lending workflow.

**Formulas**

- `lent_capital` = `SUM(CapitalLoan.outstanding)` where `lender_strategy_id` = strategy (zero outstanding excluded).
- `borrowed_capital` = `SUM(CapitalLoan.outstanding)` where `borrower_strategy_id` = strategy. Informational only; not a second cash balance; not added to `strategy_available_capital`.
- Deployed (unchanged structure, lent no longer stubbed): `owned MV + pending_execution_reserved + lent_capital`.
- Unused: `max(0, strategy_capital_allocation − deployed)`.
- `strategy_available_capital` = `min(unused, fundablePhysical)` — **OD-24 is not applied** (own-capital BUY funding unchanged).
- `available_physical_cash` = `max(0, total_cash − pending_execution_reservations)` (WS2 / OD-19).
- `required_cash_reserve` still comes from OD-19 on investable capital; **not** subtracted again in available-for-lending.
- `available_for_lending` = `floor_to_₹5000` of capped lendable surplus:
  - `raw = max(0, unused − OD-24 retained − already_committed_to_lending)`
  - optional §5.7 portfolio caps (blank = skip): `raw = min(raw, unused × max_lending_pct_of_unused/100)` then `raw = min(raw, max_lending_absolute)`
  - then `FloorToRupee5000::floor(raw)`
- `floor_to_₹5000` is `App\Support\FloorToRupee5000` (`intdiv` whole rupees). **Not** OD-06 `ceil(requirement × 1.01 / 5000) × 5000`.
- OD-24 retained unset (`recommended_minimum_holdings` missing) → subtract **0** for lending surplus (same unresolved WS2 edge; no invented divisor).
- `unallocated_cash` (OD-20) remains presentation-only and is not lendable surplus.
- §5.7 settings: `max_lending_pct_of_unused`, `max_lending_absolute` on `ProfileSettingsService` (default empty → full surplus).

**Lent vs §8.2 `already_lent`:** unused already excludes lent because lent is inside deployed (§5.2). Subtracting lent a second time in available-for-lending would double-count. `already_lent` is applied via deployed/unused only.

**Already committed-to-lending:** Step 1 has request statuses (`displayed`, `awaiting_approval`, `committed`, …) and a loan row whose `outstanding` **is** lent capital. Spec §8.3 says committed-to-lending increases only on successful approval; display/pending UI must not. There is **no** distinct persisted amount for “approved but not yet outstanding principal” (no settlement-pending column). Treating `committed` requests without a loan as committed-to-lending would invent a settlement step. Therefore `already_committed_to_lending` is **always 0.0** this step. Displayed/awaiting requests do not change lent or borrowed.

### Intentionally not implemented

Lender selection / OD-08, approval APIs, auto loan creation, lifecycle gating, execution, cash ledger loan types, repayment processing that would update `outstanding`, recall / FIFO, UI, notifications, jobs. `allocation_pct` is not rewritten. Holdings ownership is not changed by loan rows.

### Tests

`tests/Unit/FloorToRupee5000Test.php` (1 test); `tests/Feature/V3CapitalLendingAccountingTest.php` (11 tests, 87 assertions combined with the unit file).

## WS4 Step 1 — Lending Data Foundation (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited.

### Implemented

Persistence only for V3 §28.5 capital requests, loans, and capital returns/repayments.

**Tables**

- `portfolio_tos_capital_requests` — `profile_id`, `borrower_strategy_id`, nullable `lender_strategy_id`, `recommendation_id`, `amount`, `status`, `approved_at`, `approved_by`, timestamps.
- `portfolio_tos_loans` — `profile_id`, unique `capital_request_id` (one loan / one lender per request), `borrower_strategy_id`, `lender_strategy_id`, `principal`, `outstanding`, `committed_at`, nullable `min_recall_at`, `status`, timestamps.
- `portfolio_tos_loan_returns` — `loan_id`, optional `capital_request_id`, `amount`, `returned_at`. Capital repayment only (not stock transfer).

**Models:** `CapitalRequest`, `CapitalLoan`, `CapitalLoanReturn`. Status constants match spec §23.5 (`displayed` → `cancelled` on requests; `outstanding` / `partially_returned` / `returned` on loans). Relationships on `TradingStrategy`, `TradingRecommendation`, `PortfolioProfile`.

**Constraints**

- Lender ≠ borrower: model `saving` guard (`InvalidArgumentException`). Not a DB CHECK (not used elsewhere; SQLite tests cannot portably `ALTER TABLE … ADD CHECK`).
- One lender per request: unique `capital_request_id` on `portfolio_tos_loans`. Request also has a single `lender_strategy_id` column.
- FKs to `portfolio_profiles`, `portfolio_tos_strategies`, `portfolio_tos_recommendations`, `portfolio_users`.
- Indexes: `(profile_id, status)` on requests and loans; borrower/lender; `recommendation_id`; `(lender_strategy_id, outstanding)`; `committed_at` (OD-09 FIFO key later).

**₹5,000 / sizing:** no DB or model helper. Spec §28.5 denomination and DEP-PARTIAL-ATOMIC / OD-06 / UNFUNDED full-gap sizing are **not** encoded here.

**`min_recall_at`:** nullable column only. No 14-day default, no portfolio override table, no eligibility math.

Physical cash remains one portfolio pool (`portfolio_cash_accounts` / `portfolio_cash_ledger_entries`). No strategy bank accounts. No cash ledger loan types.

### Intentionally not implemented

Lending/borrowing behaviour, `available_for_lending`, `lent_capital` / `borrowed_capital`, OD-08 ranking, OD-24 lendability, partial/UNFUNDED lending flows, loan sizing service, approval APIs, auto-commit, recommendation lifecycle gating, execution changes, cash accounting, repayment processing, recall / FIFO / weakest-position, UI, notifications, jobs. No rows are auto-created from recommendations.

### Tests

`tests/Feature/V3CapitalLendingFoundationTest.php` — 8 tests, 41 assertions (schema, FKs, lender ≠ borrower, one loan per request, principal/outstanding/`committed_at`, returns, cash pool unchanged).

## V3 Workstream 1 — Domain identity / multi-strategy foundation (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.26 (OD-01–OD-24 and DEP-* frozen). This workstream does **not** change that file.

### Implemented

- A portfolio may have **multiple enabled** strategies (`STATUS_ACTIVE`). Enable (`POST /api/v1/strategy-registry/{id}/activate`) no longer archives other enabled strategies. Factory seed (`ensureActive` / `seedFactoryStrategy`) also no longer archives siblings.
- Registry contract: `selection_rule` / `enablement_rule` = `multiple_enabled_per_portfolio` (replaces `exactly_one_active_per_portfolio`). `GET /strategy-registry/selection` returns `enabled[]` plus a convenience `selected`/`editor` (newest enabled). That convenience is **UI editor focus**, not an exclusive-active domain rule.
- Optional `strategy_id` on `GET`/`PUT /api/v1/strategy` (and related editor GETs) chooses which strategy the editor loads/saves. Omitted `strategy_id` still returns the first enabled strategy (or factory seed) — existing single-strategy portfolios behave as before.
- Recommendation identity: existing `strategy_version_id` plus `TradingRecommendation::owningStrategyId()` and API `strategy_id`.
- Transaction identity: no new ledger column. Logical owner is `recommendation_id` → `strategy_version_id` when present (`Transaction::owningStrategyId()`). Manual rows stay unmanaged.
- Holdings identity: `portfolio_holdings.strategy_id` (nullable), `owner_key` (`unmanaged` or `strategy:{id}`), unique `(profile_id, stock_id, owner_key)`. Migration infers owner when the profile has **exactly one** strategy; does not split lots or rewrite qty/cost/cash/recs/SL/trailing. Recalculate still blends ledger qty per `(profile, stock)` and **preserves** the existing owner row (does not yet split same-symbol lots by strategy). New calculated holdings start unmanaged (V3 manual path).
- Strategy `allocation_pct` column default 100 (storage only). No sum-to-100 validation, no capital formula, no mixer UI.
- Physical cash remains one portfolio-level pool (`portfolio_cash_accounts` / `portfolio_cash_ledger_entries` — no `strategy_id`).
- Help copy in `appDocumentation.js` and registry UI labels updated so Enable is not described as exclusive-active.

### Deliberately not implemented (later workstreams)

OD-23 ranking/fill order, conviction allocation, lending / partial lending / loan ownership / recall, weakest-position selection, trailing migration (OD-22), broker execution, per-strategy recommendation generate-all-enabled, holdings split on recalculate, target_amount / adoption workflow, borrowed capital. Cash reserve / Unallocated Cash / OD-21 / OD-24 / Dashboard reserve warning / strategy capital formulas are **Workstream 2**.

### Remaining V3 gap after this workstream

Recommendation generate still uses `ensureActive()` (one strategy). Holdings calculation does not yet attribute lots per owner. Unique `(profile, stock, owner)` is in schema; live same-symbol multi-owner lots are not produced by the ledger replay yet. Capital math for `allocation_pct` is Workstream 2.

### Tests

`tests/Feature/V3DomainIdentityTest.php`; `StrategyRegistryApiTest` activate now expects two enabled strategies.

## V3 Workstream 2 — Cash / strategy capital / OD-19 / OD-20 / OD-21 / OD-24 (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.26. Spec file was **not** edited. Workstream 1 identity/multi-enable is unchanged.

### Implemented

- Physical cash remains **one** portfolio pool (`portfolio_cash_accounts` / `portfolio_cash_ledger_entries`). No strategy bank accounts. No new cash ledger types or historical rewrite.
- **OD-19** `available_physical_cash = max(0, total_cash − pending_execution_reservations)` — same formula as V1 `available_investable_cash` (aliased). `required_cash_reserve = MAX(currently held invested amount, current holdings market value) × portfolio_cash_reserve_pct / 100`. Setting lives on the portfolio (`portfolio_settings.portfolio_cash_reserve_pct` via Settings / `PUT /api/v1/capital/reserve-pct`). **Unset = 0** (OD-19 did not freeze a numeric default; factory strategy `min_cash_reserve_pct` 20% is **not** copied as a V3 default this workstream).
- **Investable capital** = `(cash − required_cash_reserve − pending reserved) + strategy-owned holdings market value`. Unmanaged MV is excluded from the 100% split base.
- **Strategy capital** = `investable_capital × allocation_pct / 100` using `portfolio_tos_strategies.allocation_pct` only (not score-band `allocation_pct`, not rec `*_allocation_pct`, not holdings market %). `PUT /api/v1/capital/allocations` requires **every enabled strategy** and **sum = 100 ± 0.01**. No auto-normalize, no silent force to 100, no invented unallocated-strategy bucket. Enable of a second strategy still leaves stored values as-is (often 100+100); snapshot reports `allocation_pct_sum` / `allocation_pct_sum_is_100` and computes with stored percentages.
- **OD-20 Unallocated Cash** is presentation-only: `max(0, available_physical_cash − required_cash_reserve − sum(unused_allocation))`. Not a ledger bucket. Shown on Cash UI and `GET /api/cash` / `GET /api/v1/capital`.
- **OD-21** withdrawals still cap only at available physical cash (cannot spend pending reservations). They are **not** blocked because cash would fall below `required_cash_reserve`. Dashboard shows B3 warning when shortfall exists. No app-wide B4 banner. Recommendations are not expired/cancelled solely for the shortfall. Recommendation generate still uses V1 `% of cash` `min_cash_reserve_pct` (unchanged this workstream).
- **OD-24** `minimum_retained_capital = floor(strategy_capital_allocation / recommended_minimum_holdings + 0.5)` via `App\Support\NearestIntegerRupee` (not PHP `round()` / banker's). Strategy-level accounting flag `minimum_retained_capital_is_physical_cash = false`. `recommended_minimum_holdings` is read from strategy `config_json` (top-level or `portfolio_rules`); **0/unset → retained is null** (spec edge case left unresolved; no invented divisor). Editable on Strategy → Portfolio Rules. Recalculation cadence not invented (computed on snapshot).
- APIs: `GET /api/v1/capital`, `PUT /api/v1/capital/allocations`, `PUT /api/v1/capital/reserve-pct`. Cash summary and Dashboard attach capital snapshot fields. Cash UI: reserve, Unallocated Cash, allocation mixer. Settings: portfolio cash reserve %.
- Help: `appDocumentation.js` (Dashboard, Cash, Settings, Strategy portfolio rules).

### Database migrations

None. Reserve % uses existing `portfolio_settings`. No new cash tables.

### Deliberately not implemented (later workstreams)

Lending / available-for-lending / recall / weakest-position; OD-23 ranking/fill; recommendation generate for all enabled strategies; holdings lot split on recalculate; broker execution; factory `min_cash_reserve_pct` → portfolio reserve **migration seed**; persistent app-wide shortfall banner (B4); unique inter-strategy split of physical cash beyond the unused-vs-fundable-physical cap on `strategy_available_capital`.

### Tests

`tests/Unit/NearestIntegerRupeeTest.php`; `tests/Feature/V3CapitalAccountingTest.php` (OD-24 examples, .5 upward, multi-strategy ₹10,00,000 75/25, withdrawal A/B/C/D, sum-to-100 validation, unmanaged MV excluded).

## V3 Workstream 3 — Substrate enrichment: backtest entry score (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited this pass.

### Purpose

V3 return-quality ranking (DEP-FIT-BAND-10) requires grouping historical backtest trade outcomes by the strategy fit/score at BUY entry. The existing `portfolio_backtest_trades` table did not persist the entry-time score. This substrate pass adds it so future backtests populate the column.

### Implemented

- **Migration** `2026_08_19_000002_add_entry_score_to_backtest_trades`: adds `entry_score DECIMAL(8,4) NULL` after `exit_reason` on `portfolio_backtest_trades`. Nullable; existing rows remain NULL.
- **BacktestTrade model**: `entry_score` added to `$fillable` and `$casts` (float).
- **Data flow**: `SimulationDayProcessor::processDay()` passes `$draft['score']` (the `overall_score` computed at BUY time) to `PaperTradeExecutor::buy()` as new optional `?float $entryScore` parameter. `buy()` stores it on the open lot (`['entry_score' => $entryScore]`). `closeLots()` carries `$lot['entry_score']` onto the closed-trade array. Partial-lot remainders also preserve `entry_score`. `BacktestPersistenceService::persistOpenLotsAsTrades()` includes `entry_score` from the lot on open-at-end trades.
- No backtest engine logic changed (entry/exit decisions, position sizing, calendar, CAGR, return_pct, holding_days, prices, quantities, dates, strategy selection, strategy version linkage, open/closed trade semantics).
- No live recommendation ranking or capital allocation was modified.
- Entry scores for historical trades are **not** reconstructed. Only newly executed backtests populate the column.

### Tests

`tests/Unit/Backtest/EntryScorePersistenceTest.php` — 8 tests:
- BUY stores entry_score on open lot (A)
- BUY without score stores null
- Entry score survives close (B)
- Entry score independent of exit-time score (C)
- Existing trade fields unchanged (D)
- NULL entry_score valid for legacy lots (E)
- Partial lot close preserves entry_score on remainder
- FIFO close uses correct entry_score per lot

### Deliberately not implemented (later WS3 passes)

OD-23 fallback ordering integration, new capital allocator, multi-strategy recommendation generation, partial/unfunded V3 handling, lending, trailing migration, broker execution.

## V3 Workstream 3 — Return-quality ranking service (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited this pass.

### Purpose

Isolated V3 return-quality ranking engine implementing the full ranking pipeline from backtest corpus to trimmed-mean return quality per fit band. Does NOT modify live recommendation ranking, capital allocation, or any WS1/WS2 services.

### Implemented

**Service:** `app/app/Services/Ranking/ReturnQualityRankingService.php`

- **Corpus selection (OD-03):** Latest completed backtest run (`status = completed`, ordered by `completed_at` desc then `id` desc) for a `strategy_version_id`. Only closed trades (`is_open = false`) with non-null `entry_score` and `return_pct`. No cross-strategy/version leakage. Scoped by `backtest_run_id`.
- **Fit bands (DEP-FIT-BAND-10):** 10-point bands `[0,10)`, `[10,20)`, …, `[90,100]`. Entry score determines band. Adaptive sparsity: up to 2 neighbor-band merges, then min-n reduction 15→12→10. Neighbor selection: prefer adjacent band with more observations; tie → right neighbor (engineering choice, isolated in `selectMergeNeighbor()`).
- **Trimming (DEP-TRIM-K / OD-04):** `k = floor(0.07 × n + 0.5)`. Same k from both tails. Symmetric trimmed mean of sorted return values. No banker's rounding.
- **Return metric (OD-18):** Prefer CAGR when `holding_days ≥ 30` and CAGR is non-null; fall back to `return_pct` (simple return). CAGR never used for < 30-day trades. Engineering choice: spec §4.5 says "prefer XIRR/annualized" subject to OD-18; the spec does not prescribe a single metric for the aggregate — CAGR-when-eligible with return_pct fallback is the smallest deterministic policy consistent with the spec.
- **Confidence (§4.2.2):** 0.0–1.0 diagnostic that decreases with merges and threshold reductions. Purely user/audit-facing. Does NOT affect ranking, allocation, or eligibility.
- **Ranking-unavailable:** Explicit `computable: false` result when no fit band reaches minimum-n. Does NOT implement OD-23 fallback — that is the next WS3 step.
- **Determinism:** Explicit sort on return values before trimming. No dependency on DB row order.

### Tests

`tests/Unit/Ranking/ReturnQualityRankingServiceTest.php` — 30 tests:

**Corpus (4):** closed included, open excluded, wrong run excluded, null entry_score excluded.
**Fit bands (5):** exact boundaries, grouping, sparse merge, adaptive 15→12→10, unavailable below 10.
**Trimming (9):** k for n=15/20/25/30/35/50/100, symmetric tail removal, n=25 two-tail removal.
**OD-18 (4):** short trade uses return_pct not CAGR, long trade uses CAGR, null CAGR falls back to return_pct, exactly-30 days uses CAGR.
**Ranking (8):** higher return quality ahead, deterministic order, insufficient corpus, auditable diagnostics, confidence decreases with compromises, band key coverage, empty corpus, extract return values.

### Engineering choices where spec was genuinely silent

1. **Merge neighbor direction:** When a sparse band has equally-sized left and right neighbors, the service picks the right (higher-fit) neighbor. Isolated in `selectMergeNeighbor()`.
2. **Return metric for aggregate:** Spec §4.5 says "prefer XIRR/annualized" and OD-18 gates annualized to ≥30 days. The spec does not explicitly say whether the trimmed mean should aggregate CAGR values, return_pct values, or a per-trade choice. The service uses CAGR when OD-18 permits and CAGR is non-null, otherwise return_pct. This is the smallest deterministic policy consistent with the spec. Isolated in `rankingReturnForTrade()`.

### No live code changed

- `RecommendationGenerationPipeline` — untouched
- `ScorePriorityCapitalAllocator` — untouched
- All WS1/WS2 services — untouched
- Specification remains v0.27

## V3 Workstream 3 — OD-23 capital fill order service (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited this pass.

### Purpose

Isolated OD-23 capital fill order service. Used ONLY when V3 return-quality ranking is NOT computable. This is a **capital fill order**, NOT V3 ranking. Does not call `ReturnQualityRankingService` and vice versa — the future allocator will connect them.

### Implemented

**Service:** `app/app/Services/Ranking/CapitalFillOrderService.php`

- **OD-23 ordering (frozen):**
  1. `target_amount` DESC — conviction / target investment amount (maps to recommendation draft `position_size` / `suggested_investment_amount`: the ₹ amount from score-band allocation × portfolio value, capped by max position size)
  2. `fit_score` DESC — strategy fit score (maps to draft `score`)
  3. `symbol` ASC — stock listing symbol, case-insensitive alphabetical
- **Null handling (engineering choice):** `null` target_amount → 0.0 (lowest priority); `null` fit_score → -1.0 (lowest priority within same target). Isolated in `resolveTargetAmount()` / `resolveFitScore()`.
- **Conviction sub-score:** NOT implemented, NOT reintroduced. Removed from V3 spec per Decision 2.
- **Immutability:** Input array is not mutated; returns a new sorted array.
- **Extra data:** Additional keys on candidates are preserved through ordering.

### Tests

`tests/Unit/Ranking/CapitalFillOrderServiceTest.php` — 18 tests:

**Primary key (2):** target amount is primary, higher target before lower regardless of fit.
**Secondary key (1):** fit is secondary when targets equal.
**Tertiary key (1):** symbol is final deterministic tie-break.
**Multi-candidate (1):** 5-candidate exact ordering verification.
**Determinism (1):** input order does not affect output.
**No conviction sub-score (1):** extra conviction_sub_score field ignored.
**Immutability (1):** service does not mutate input.
**Edge cases (6):** empty list, single candidate, zero target, null target, null fit, extra data preserved.
**Relationship (1):** fill order service is distinct from ranking service, no ranking keys on output.

### No live code changed

- `RecommendationGenerationPipeline` — untouched
- `ScorePriorityCapitalAllocator` — untouched
- All WS1/WS2 services — untouched
- Specification remains v0.27

## V3 Workstream 3 — V3 capital allocator (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited this pass.

### Purpose

V3 sequential capital allocator that consumes pre-ordered candidates (return-quality ranking when computable, OD-23 fill order when unavailable) and produces allocation outcomes. Does NOT mutate recommendations, holdings, transactions, or cash. Does NOT integrate into the live pipeline yet.

### Implemented

**Allocator:** `app/app/Engines/Recommendation/Allocation/ReturnQualityCapitalAllocator.php`

- **Implements `CapitalAllocationStrategy`** interface for drop-in compatibility.
- **Sequential fill (not proportional):** Candidates are processed in supplied order. First candidate receives as much capital as permitted before any remainder flows to the next. No score-weighted distribution.
- **Capital pool:** `strategy_available_capital` from WS2 `PortfolioCapitalAccountingService::snapshot()` — `min(unused_allocation, fundablePhysical)`. Allocator does NOT recalculate cash, reserve, or pending reservations.
- **Target amount source:** `desired_amount` from draft (= `position_size` / `suggested_investment_amount` from `buildExecutionPlan()`, derived from `allocationPctForScore() × portfolioValue` capped by `max_position_size_pct`). The spec (§5.2) defines strategy capital allocation as `investable_capital × allocation_pct / 100` but the target sizing formula (`allocationPctForScore` from score bands) operates on portfolio value in V1. The allocator does NOT change target sizing — it receives the draft's target as input.
- **OD-05 partial funding:** If available capital is positive but insufficient for target, allocate the maximum executable whole-share amount. Target is preserved. Status = `PARTIALLY_FUNDED`. Not converted to WATCH.
- **Unfunded:** Zero executable quantity → status = `UNFUNDED`. Target preserved. Not converted to WATCH. No recommendation lifecycle mutation.
- **OD-06 atomic reservation:** `ceil(requirement × 1.01 / 5000) × 5000`. Computed and reported but does NOT cap own-capital partial funding (per spec: "Own-capital partial amounts are not required to be re-ceiled").
- **Whole-share quantity:** `floor(fundable / price)`. No fractional shares.
- **Max-position constraint:** `min(desired, max_position_amount)` when set.
- **OD-24:** Retained capital is a lending boundary, per spec §5.5: "It does not change BUY funding, OD-05 partial funding, or OD-06 pending-execution reservation." Allocator does NOT subtract OD-24 from available capital for own buys.
- **No V1 score weighting:** No proportional budget. No score-based share calculation. Candidates processed strictly in supplied order.

### Result contract

Each candidate produces: `target_amount`, `allocated_amount`, `quantity`, `unfunded_amount`, `atomic_reservation`, `funding_status` (FULLY_FUNDED / PARTIALLY_FUNDED / UNFUNDED).

### Tests

`tests/Unit/Recommendation/ReturnQualityCapitalAllocatorTest.php` — 24 tests:

**Sequential (3):** first filled before second, not proportional, remainder flows.
**Full funding (2):** target funded, limited by available.
**Partial (3):** partial allocation, target preserved, unfunded remainder preserved.
**Unfunded (4):** zero capital, target not erased, no WATCH conversion, sub-share unfunded.
**Ordering (3):** ranking order respected, OD-23 order respected, no V1 score weighting.
**Strategy capital (1):** uses supplied available capital.
**Constraints (2):** whole-share, max-position.
**OD-06 (2):** atomic allocation normative examples, atomic reservation in result.
**Edge cases (3):** empty drafts, zero price, zero desired.
**Interface (1):** implements CapitalAllocationStrategy.

### No live code changed

- `ScorePriorityCapitalAllocator` — untouched (V1 tests pass)
- `RecommendationGenerationPipeline` — untouched in this pass
- All WS1/WS2 services — untouched
- Specification remains v0.27

## V3 Workstream 3 — RecommendationGenerationPipeline integration (2026-08-19)

Authoritative spec: [`specs/LidoPortfolio-V3-Specification.md`](specs/LidoPortfolio-V3-Specification.md) v0.27. Spec file was **not** edited this pass.

### Purpose

Integrate completed WS3 ranking, OD-23 fill order, and sequential allocator into live generation — per enabled strategy, using one WS2 capital snapshot. Does not implement lending, recall, trailing, or broker execution.

### Implemented

**Pipeline:** `app/app/Engines/Recommendation/RecommendationGenerationPipeline.php`

- **Multi-strategy:** `run()` lists every `STATUS_ACTIVE` strategy with an active version (seeds factory only when none exist). Each strategy gets its own context, drafts, ranking, allocation, persist, and stale-cancel. Sibling strategies are not disabled.
- **WS2 snapshot:** `PortfolioCapitalAccountingService::snapshot()` once per generation cycle (and once per `decideForSecurity`). Funding pool is that strategy’s `strategy_available_capital`. V1 `CashManagementService` reserve/deploy haircuts are not re-applied (reserve is already inside WS2).
- **Holdings:** `held_qty` / `is_held` / current allocation use only that strategy’s owned lots. Unmanaged holdings remain unmanaged and do not count as held for a strategy.
- **Ranking:** OPEN/INCREASE ordered by `ReturnQualityRankingService` when the opportunity’s fit band is eligible; otherwise `CapitalFillOrderService` (OD-23). Score is not the primary V3 ranking key. Non-buy drafts follow after buy drafts.
- **Allocator:** `ReturnQualityCapitalAllocator` (default). Candidates are passed in already-chosen order. `desired_amount` remains the existing draft target (`allocationPctForScore` × portfolio value). Strategy available capital is the funding pool only.
- **Outcomes:** FULLY_FUNDED / PARTIALLY_FUNDED / UNFUNDED. OPEN/INCREASE is **not** converted to WATCH. Original target is stored as `plan.target_investment_amount` and `capital_allocation.target_amount`; this-cycle allocated amount is `suggested_investment_amount`.
- **Stale cancel:** `whereHas('strategyVersion', strategy_id = this strategy)` — not portfolio-wide.
- **Constant:** `TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED`.

### Tests

- `tests/Unit/Recommendation/V3PipelineCapitalOutcomesTest.php` — unfunded/partial/full outcomes; return-quality vs OD-23 order; allocator receives supplied order and `strategy_available_capital`.
- `tests/Feature/V3RecommendationGenerationTest.php` — two enabled strategies; stale cancel scoped; holdings scoped; one snapshot; single-strategy still works; ranking evidence recorded.
- `MarketGateRecommendationTest` — insufficient cash keeps OPEN + unfunded; strategy-owned holdings for INCREASE/EXIT gate tests.

### Not in this step

Lending / DEP-PARTIAL-LEND requests, recall, weakest-position, trailing, broker execution, redesign of `allocationPctForScore` / score-band targets.

### No unintended redesign

- `PortfolioCapitalAccountingService` — not modified
- `ScorePriorityCapitalAllocator` — not modified
- WS1 ownership / TradingStrategy — not modified
- Specification remains v0.27

**Architecture specs reorganization (2026-08-06):** Documentation-only moves under `specs/architecture/` (`platform/`, `ui/`, `indicators/`, `portfolio/`, `data/`, `domains/`, `live-trading/`, `integrations/`, `governance/`, `audit/`). Filenames unchanged. `engines/` renamed to `domains/`; `System-Domain-Model.md` lives under `platform/`. Summary: [`specs/architecture/MIGRATION-SUMMARY.md`](specs/architecture/MIGRATION-SUMMARY.md).

**Architecture Repository V1.0 (Frozen):** Structure, governance, naming, and authoring rules are stable — start at [`specs/architecture/platform/README.md`](specs/architecture/platform/README.md) (Platform Architecture) and [`specs/architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md`](specs/architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md). Extend the repo; do not reorganize it.

### Engine layer (`app/app/Engines/`)

| Engine | Class | Owns / wraps |
|--------|-------|----------------|
| Data | `Data\DataEngine` | `portfolio_stocks` / `portfolio_stock_prices` / daily sync. **V4-FEAT-032:** list/find/paginate + inspection counts via `Repositories\Tos\MarketDataRepository`. |
| Discovery | `Discovery\DiscoveryEngine` | `portfolio_tos_candidates` (orchestrates PatternScan + Screener). **V4-FEAT-032:** candidate list/filter via `DiscoveryCandidateRepository`; rank sort stays in the engine. |
| Evaluation | `Evaluation\EvaluationEngine` | Factor facts only (no Strategy weights). **V4-FEAT-029:** factor formulas in `Evaluation\Rules\*` via `EvaluationFactorRuleSet`. **V4-FEAT-005:** `market_regime` numeric fact from MarketAnalysisEngine categorical regime (Bullish=100 / Neutral=50 / Bearish=0) via `MarketRegimeScoreMapper` (once per run). Indicator periods / lookback / benchmark from `Evaluation\EvaluationParameterResolver`. **V4-FEAT-032:** result list + bar load queries via `EvaluationResultRepository` / `MarketDataRepository`; rules remain DB-unaware. |
| Strategy | `Services\StrategyConfigurationService` | Versioned strategy config (factors, thresholds, rules); consumed by Recommendation |
| Recommendation | `Recommendation\RecommendationEngine` | Thin façade only (TD-001) — generation delegated to `Recommendation\RecommendationGenerationPipeline` (TD-002); lifecycle (Approve/Reject/Defer; pending-execution / cancel-execution / expire / reopen; **V4-FEAT-024 `markExecuted`**; cash reservation; list/history queries) delegated to `Recommendation\RecommendationLifecycleService`. **V4-FEAT-032:** list/find/paginate via `RecommendationQueryRepository`; `expireStale` stays in the lifecycle service. |
| Market Analysis | `Market\MarketAnalysisEngine` | Benchmark OHLCV → market analytics / sentiment / phase (SD-032); façade `MarketAnalyticsService` |
| Notification | `Notification\NotificationEngine` | Telegram + `portfolio_tos_notifications`; message text delegated to `Services\Notification\NotificationMessageComposer` (TD-005). Recommendation Telegram notify skips informational HOLD / WATCH (`isActionable()` / `ACTIONABLE_ACTIONS` only). **V4-FEAT-032:** history/find via `NotificationQueryRepository`. |
| Execution | `Execution\ExecutionEngine` | Pending execution → ledger transaction (manual or future broker); does **not** write recommendation `executed` status (calls `RecommendationEngine::markExecuted`). **V4-FEAT-032:** order/transaction/position list queries via `ExecutionQueryRepository`. |
| Review | `Review\ReviewEngine` | Dashboard, outcomes, reports. **V4-FEAT-032:** report list/find via `ReviewReportRepository`; dashboard aggregations stay in the engine. |
| Backtest | `Services\Backtest\BacktestSimulationEngine` | Historical strategy simulation (paper portfolio; resumable ~20s slices) |
| Pipeline | `Pipeline\DailyDecisionPipeline` | End-to-end stages. **V4-FEAT-022:** `DatasetFreshnessGate` — last successful sync age ≤ 24h (72h on Monday, cron timezone) before Discovery. |

### Config / schema / CLI

- Config: `config/trading_os.php`.
- Migrations: `2026_07_25_000002_*` … `000013_*` (market analysis snapshots).
- Command: `php artisan portfolio:decision-pipeline`.
- **Cash / capital allocation (SD-026):** Spec: [`specs/architecture/portfolio/Cash-Management-Specification.md`](specs/architecture/portfolio/Cash-Management-Specification.md). `CashManagementService` (balance, reserved from pending_execution buys, available investable cash; `reservationDetails` for breakdown). `RecommendationEngine::generate()` delegates to `RecommendationGenerationPipeline::run()` (TD-002). **V3 WS3 Step 6:** generation runs per enabled strategy; funding pool is WS2 `strategy_available_capital`; default allocator is `ReturnQualityCapitalAllocator`; unfunded/partial OPEN/INCREASE stay OPEN/INCREASE (`ALLOCATION_UNFUNDED` / `ALLOCATION_PARTIALLY_FUNDED`) and are **not** demoted to WATCH. V1 `ScorePriorityCapitalAllocator` remains in tree unused by the live pipeline. Approve buy → `reserveForApproval` (fails if amount exceeds available); cancel/expire/reopen → `releaseReservation`; execute → `convertReservation`. APIs: `GET/POST /api/cash*` (deposit/withdraw/adjust + ledger + reservations).
- **TD-002 (2026-07-27, code audit remediation):** Extracted `RecommendationEngine::generate()` into `Recommendation\RecommendationGenerationPipeline` with staged orchestration (`prepareContext` → `cancelStaleRecommendations` → `buildDrafts` → `rankDrafts` → `allocateCapital` → `persistDrafts`). No behaviour change.
- **TD-003 (2026-07-27, code audit remediation):** Separated universe sync orchestration from the per-stock provider fetch loop. New `Services\UniversePrice\UniversePriceBatchExecutor::run()` (built with `PriceFetchService` + `SyncLogService`) owns the batch loop — moved verbatim: per-stock `syncStock`, `PriceSyncNotificationContext::withoutTelegram` wrapper, inter-stock delay, stats accumulation, per-stock `SyncLogService::log` messages. `UniversePriceSyncService` keeps orchestration (enable flag, in-progress lock, maintenance windows, cursor, status, sync-run begin/complete) and delegates the loop via `$this->executor->run(...)`; `looksLikeRateLimit()` stayed on the service (also used by `recentProviderIssues()`). New trailing `?UniversePriceBatchExecutor $executor = null` constructor param defaults to a fresh executor, so existing call sites needed no changes. Public API unchanged; verified via `php vendor/bin/phpunit --filter "UniversePriceSync|HistoryDepthBackfillServiceTest|ScheduleRegistrationTest"` (25/25 passing) plus full suite (400/405 — 5 pre-existing unrelated failures, none touching universe price sync).
- **TD-004 (2026-07-27, code audit remediation):** Split independent pattern detectors out of `PatternDetectionService` into `Services\PatternDetection\` (`PatternDetectorInterface`, `CandleMetrics`, candlestick single/two/three-bar, chart reversal/continuation). `PatternDetectionService` orchestrates `scanBars()` only; public API unchanged.
- **TD-005 (2026-07-27, code audit remediation):** Separated notification message composition from dispatch via `Services\Notification\NotificationMessageComposer`. `NotificationEngine` and `AlertNotificationService` keep queue/dispatch only. **(2026-07-28)** `notifyRecommendations` only queues Telegram for actionable types (`OPEN_POSITION` / `INCREASE_POSITION` / `REDUCE_POSITION` / `EXIT_POSITION`, plus legacy BUY/SELL via `isActionable()`); HOLD / WATCH insights are skipped.
- **TD-001 (2026-07-27, code audit remediation):** Split lifecycle out of `RecommendationEngine` into `Recommendation\RecommendationLifecycleService`. Engine is a thin façade over generation pipeline + lifecycle service; public APIs unchanged. **V4-FEAT-024 (2026-08-27):** `markExecuted` added here; ExecutionEngine no longer writes recommendation `executed` status.
- **TD-006 (2026-07-27, code audit remediation):** Extracted duplicated `TradingRecommendation` query patterns into Eloquent local scopes on the model (no Repositories layer — SD-013 deferred). Scopes: `forProfile`, `pendingExecution`, `openForReview`, `withCashReservation`, `actionableTypes`, `openList`, `staleOpen`; status constants `OPEN_LIST_STATUSES`, `STALE_OPEN_STATUSES`. Callers updated in `RecommendationLifecycleService`, `CashManagementService`, `RecommendationGenerationPipeline`, `RecommendationPreviewService`, `ReviewEngine`, `ExecutionEngine`, `TradingOsController`, `TransactionController`, `NotificationEngine`. Behaviour unchanged.
- **TD-007 (2026-07-27, code audit remediation):** Added `Support\TradingOsConfig` — typed accessors and `KEY_*` path constants for all `trading_os` config sections. Replaced scattered `config('trading_os....')` at call sites: `RunDecisionPipelineCommand`, `DailyDecisionPipeline`, `TradingOsController`, `routes/console.php`, and engine config reads (`DiscoveryEngine`, `EvaluationEngine`, `NotificationEngine`, `ReviewEngine`, `MarketAnalysisEngine` — one-line delegation only). No config file redesign.
- **TD-011 (2026-07-27, code audit remediation):** Centralised recommendation pipeline thresholds and domain strings. `TradingRecommendation` gains action (`ACTION_*`), risk (`RISK_*`), capital allocation (`ALLOCATION_*`), market opinion (`OPINION_*`, `STRENGTH_*`), and legacy `STATUS_ACTIVE_LEGACY` constants. `RecommendationGenerationPipeline::prepareContext()` reads strategy JSON via `TradingOsConfig::STRATEGY_*` / `THRESHOLD_*` keys and falls back to `trading_os.recommendation.*` getters (expanded in `config/trading_os.php`: `very_strong_high/low`, `max_concurrent_recommendations`, `max_new_positions_per_cycle`). Generation/lifecycle logic uses named constants instead of inline magic strings; behaviour unchanged.
- **TD-013 (deferred / not implemented):** Guide asks for DTOs/value objects for service contracts. Accepted governance **SD-013** defers repositories/DTOs. Introducing DTOs now would conflict with that decision and expand beyond incremental extraction. Revisit only if SD-013 is superseded.
- **TD-014 (2026-07-27, code audit remediation — frontend):** Centralised duplicated page-level API load/mutation patterns without a second HTTP client. Extended `api.js` with exported `getApiErrorMessage()` (TOS `error.message`, Laravel validation, existing interceptor logic). New hooks: `hooks/useApiGet.js` (loading + reload + toast on failure) and `hooks/useApiMutation.js` (`runApiMutation` + `useApiMutation` busy helper). Migrated proof pages: `StrategyPage`, `RecommendationsPage`, `CashManagementPage` — all use `skipErrorToast: true` on hook-driven calls to avoid double toasts from the axios interceptor. Routes and UI unchanged. **V4-FEAT-027 (2026-08-27):** Discovery, Pending Execution, Review dashboard, and Notification history adopted the same hooks plus `utils/tosEnvelope.js`.
- **Strategy + Screeners (SD-027 / SD-028 / SD-029 / SD-030):** Specs: [`Strategy-Configuration-Specification.md`](specs/architecture/domains/Strategy-Configuration-Specification.md), [`Screener-Specification.md`](specs/architecture/domains/Screener-Specification.md). **Screeners** are the sole eligibility engine; Strategies reference them (`eligibility_sources`, `portfolio_tos_strategy_screeners`). Default **Minervini Strategy** (Minervini Trend Template eligibility + momentum scoring). **V3 WS1 (2026-08-19):** SD-029 exclusive-active is no longer enforced — multiple `STATUS_ACTIVE` strategies may coexist; editor `strategy_id` is UI selection. Save still updates `config_json` in place (no Duplicate, no version fork, no factory protection). Scoring weights must sum to 100 after save — **auto-normalised** on Save / `normalizeConfig` (largest-remainder, 2 d.p.; relative proportions kept; UI **Normalise now** preview). Exit Strategy on holdings — including **Screener Exit** (`screener_exit` rule: when enabled + screener selected, any open holding present in that screener’s latest completed run within 72h becomes `EXIT_POSITION`; works for holdings in or outside the evaluation result set). Recommendation evidence: eligibility / scoring / exit (+ `strategy_name`). APIs: `/api/v1/strategy*` (`strategy_id` query optional), `PUT /strategy/screeners` (`POST /strategy/duplicate` removed). UI: General · Eligibility Sources · Scoring Model · Recommendation Thresholds · Exit Strategy · Market Gates · Cash — header card shows name, last modified, eligibility, weight total, exit/market flags (no version / factory badges).
- **Analytics Architecture (SD-031):** Spec: [`Analytics-Architecture-Specification.md`](specs/architecture/portfolio/Analytics-Architecture-Specification.md). Owners: `StockAnalyticsService`, Evaluation Engine (`EvaluationProfileService`), `PortfolioAnalyticsService`, `MarketAnalyticsService`. Pages: Dashboard (portfolio+market), Watchlist (research tabs), Portfolio/Holdings (positions), Discovery (candidates). APIs: `/api/v1/analytics/*`. Cache tables `000012`. Nav label Holdings → **Portfolio**.
- **Market Analysis Engine (SD-032):** Spec: [`Market-Analysis-Engine-Specification.md`](specs/architecture/domains/Market-Analysis-Engine-Specification.md). `MarketAnalysisEngine` analyses primary benchmark OHLCV (NIFTY50 via IndexCatalog) into trend/momentum/volatility/risk/drawdown/breadth + sentiment (0–100) + deterministic market phase. Persists `portfolio_tos_market_analytics` (`000013`). APIs: `/api/v1/market-analysis*`. Recommendation applies `allocation_multiplier` / `new_entry_allowed` + optional Strategy `market_gates`. Dashboard Market Analytics: gauge cards (Trend via `TrendGauge` from `trend.score`/`strength`, Momentum, Volatility, Risk, Sentiment, phase, breadth, regime) plus **Stocks Above** market-depth heatmap (`MarketDepthService` / `GET /api/dashboard` → `market_depth`); optional legacy `% above 50/200 DMA` text cards when engine fields are non-null. Top Gainer/Loser sit under Portfolio (after summary cards). Active strategy card removed from Dashboard (configure via `/strategy`). Portfolio Analytics attaches `market_context`. **Gauge colour consistency (2026-07-30):** Sentiment, Market phase, Volatility, and Risk use `HalfDonutShell` `invertScale` so rings read red→green left→right like Trend/Momentum/regime/breadth, while needle + zone labels stay on matching colours (high fear/risk/volatility remain on the red side).
- **F098 — Market gates in live recommendations (2026-08-08):** Extracted gate evaluation into `Recommendation\MarketGateEvaluator` (deterministic; consumed by `RecommendationGenerationPipeline::prepareContext()`). Combines Market Analysis `new_entry_allowed` / `allocation_multiplier` with optional Strategy `market_gates` (`enabled`, `min_sentiment`, `allowed_phases`, `max_risk_raw`). When blocked: **OPEN / INCREASE** demoted to WATCH (not held) or HOLD (held); **EXIT / REDUCE / HOLD** unchanged. `max_risk_raw` breach also caps multiplier at 0.5×. Evidence now records `market_gates` checks/block reasons, base vs effective entry/multiplier, and `market_gate_demoted`; reasoning includes demotion text. Cash unfunded demotion is **not** used in V3 (OPEN/INCREASE remain; capital status is a separate axis). Tests: `tests/Unit/MarketGateEvaluatorTest.php`, `tests/Feature/MarketGateRecommendationTest.php`. No DB/API/frontend changes.
- **F148 / F149 — Pipeline scheduling & post-sync hook (2026-08-08; unattended defaults V4-FEAT-010 2026-08-28):** Daily Decision Pipeline is registered on Laravel `schedule:run` (`TRADING_OS_PIPELINE_SCHEDULE` **defaults true**; set `false` to disable) at `TRADING_OS_PIPELINE_TIME` (default `19:00`), portfolio timezone, trading-session `when()` guard, `--trigger=scheduled`, `withoutOverlapping(45)`. Post-sync hook (`TRADING_OS_PIPELINE_AFTER_SYNC` **defaults true**) runs `portfolio:decision-pipeline --trigger=post-sync` from `DailyMarketDataJob` only after successful sync (`failed === 0`); sync failure/partial completion does not trigger. `DecisionPipelineScheduleService` enforces once-per-day guard for automatic triggers (scheduled + post-sync share guard; manual/`--force` bypass). **H1/H2 hardening (2026-08-08):** automatic partial-failure retry tracks per-profile success for the portfolio calendar day (`decision_pipeline_auto_success_date` + JSON profile-id list in `portfolio_settings`); automatic retries skip already-successful profiles and only rerun failures; profile markers written only after successful pipeline completion; manual runs never skip on profile markers and do not write automatic profile state. Shared automatic execution lock `trading-os:decision-pipeline:automatic` (2700s, matches scheduler overlap window) acquired at command start for scheduled/post-sync, released in `finally`; lock contention returns success with skip log (not pipeline failure). Global once-per-day guard marked when all profiles in the run have succeeded for the day. `RunDecisionPipelineCommand` accepts `--trigger=manual|scheduled|post-sync` and logs via `PortfolioLoggerService::scheduler()`. `DailyDecisionPipeline` stores trigger in `stages_json._meta`. Tests: `DecisionPipelineScheduleServiceTest`, `DecisionPipelineScheduleTest`, `DecisionPipelineHardeningTest`, `DecisionPipelineRetryVerificationTest`, `ScheduleRegistrationTest` (pipeline rows), `V4Feat010UnattendedOpsTest`, fixed `DailyMarketDataJobTest` (Laravel TestCase + AdminOperationalAlertService mock).
- **Transactions routes:** `/transactions` = Transaction History; `/transactions/pending` = Pending Execution. Toggle navigates between them. Page tabs toggle has no “View” label; uses larger height/font (`.lido-segment-toggle--page-tabs`).

### REST `/api/v1` (additive)

Sanctum auth. Machine-readable contract: [`app/openapi/v1.json`](app/openapi/v1.json) (OpenAPI 3.0.3, V4-FEAT-025; pagination meta V4-FEAT-028). **V4-FEAT-027:** TOS `/api/v1` HTTP is split by engine under `App\Http\Controllers\Api\V1\TradingOs\{Data,Discovery,Evaluation,Recommendation,Notification,Execution,Review,Pipeline}Controller`. Wire JSON mapping is `TradingOsPresenter`. **V4-FEAT-032:** engines load TOS aggregates through `App\Repositories\Tos\*`; HTTP contract unchanged. Routes/methods/auth unchanged: securities, imports, candidates, evaluations, recommendations (`/review` with `approved|accepted|rejected|deferred`, `/pending-execution`, `/cancel-execution`, `/expire`, `/reopen`), notifications, orders (BC), review dashboard/outcomes, pipeline. List pagination (`page` / `pageSize`, meta `{page,pageSize,total,lastPage}`) on securities, price-bars, recommendations, orders, transactions, notifications, reviews — see V4-FEAT-028. Ledger create: `POST /api/transactions` (+ optional `recommendation_id`).

### Frontend

`/candidates` (nav: **Discovery** — includes evaluation score/confidence/explanation; `/evaluations` redirects here), `/recommendations` (**Approve**/Reject/Defer only), `/strategy` (**Strategy**), `/transactions` (**Pending Execution** + history via page controls), `/cash` (**Cash**), `/review`, `/notification-history` (via **Settings → Portfolio → Notification history**, not primary nav). Manual execute from pending queue → Add Transaction form.

**Navigation architecture (2026-07-30):** Production sidebar refactored for maintainability — `navigation/` registry (`ROUTES`, icon registry, permissions context, `registerModule` for future plugins/marketplace/custom dashboards), reusable `NavMenuItem` / `NavGroup` / `NavBadge` / `NavTooltip`, config-driven catalogs. Spec: [`specs/architecture/ui/15-Sidebar-Navigation-Architecture.md`](specs/architecture/ui/15-Sidebar-Navigation-Architecture.md). Legacy AppTabs / AppBottomNav removed.

**Dashboard (2026-07-30):** Removed the “View Market Depth →” link above Market Analytics diagnostic gauges; Market breadth gauge title still links to `/market-depth`.

**Knowledge Board (2026-07-31):** Manage-mode note action icon buttons (pin/edit/duplicate/archive/delete + mobile menu) are 50% larger (hit target 1.95rem, icons 24px).

**UI (2026-07-30):** Metadata-driven **sidebar-only** primary navigation (top AppTabs removed). Favourites (pin up to 8 per user, reorder) and configurable Quick Actions above Navigation groups. Notification history under Portfolio settings.

**Navigation chrome (2026-07-30):** `PageChrome` breadcrumbs + page title (and `document.title`) from catalog via `buildBreadcrumbs` / `getPageTitle`. **Ctrl/Cmd+B** toggles sidebar (skipped in inputs). Active route’s group auto-expands; active highlight resolves via `findActiveSidebarPageId` (editors/registries keep their parent top-level item highlighted). Sidebar scroll position preserved in `sessionStorage` (`lido-sidebar-scroll`). Shell isolates scroll: sidebar and `.lido-main` scroll independently. Catalog supports `badge`, `tag` (`NEW`/`BETA`), `disabled`, `external`, and `permission` (future filter via `canAccessNavItem`). Editor/registry/admin tool routes stay internal (`showInSidebar: false`). Smooth width/group animations; reserved chrome height; reduced-motion respected.

**Sidebar IA (2026-07-30):** Portfolio (Dashboard, Holdings, Watchlist, Transactions, Cash, Corporate Actions) · Market (Discovery, Stock Explorer, Patterns, Indices, Calendar, Market Depth) · Trading (Recommendations, Review, Strategies, **Backtests**, Screeners) · Knowledge (Knowledge Board, Knowledge Tags) · Administration (Settings). URLs unchanged; browser Back uses normal React Router history (`NavLink` push).

**Strategy Backtesting CAGR overflow fix (2026-07-31):** Trade-level `cagr` for 1-day winners (e.g. PKTEA +3% overnight) annualized to millions of percent and overflowed MySQL `DECIMAL(12,6)`, failing the run during `GENERATING_STATISTICS` / trade persist. Fix: `BacktestMath::cagrPercent` returns **null** when holding days &lt; 30 (CAGR meaningless for day trades) and clamps any finite value into DECIMAL(12,6); `return_pct` / drawdown also clamped. No schema change required — re-run the backtest after deploy.

**Backtest detail UX (2026-07-31):** Trades and Transactions tables on `/backtests/:id` are collapsible and **collapsed by default** (header shows row count + chevron). Floating scroll-to-top / scroll-to-bottom buttons (fixed bottom-right) scroll `.lido-main`. New Backtest modal + in-progress banners show a duration notice (`BACKTEST_DURATION_NOTICE`): can take several minutes; keep the page open.

**Strategy Backtests UI (2026-07-31):** `/backtests` (sidebar **Backtests**, Trading group) lists strategy simulation runs; `/backtests/:id` shows detail. APIs: `GET/POST /api/v1/backtests`, `GET/PUT/DELETE /api/v1/backtests/{id}`, `POST /api/v1/backtests/{id}/continue`, `GET /api/v1/backtests/meta`. Frontend: `BacktestHistoryPage` (new run modal, chunked Continue polling up to ~2000 slices, session token `lido_strategy_backtest_session`), `BacktestDetailPage` (summary cards, Recharts portfolio line, trade timeline matrix, trades/transactions/snapshots tables, full statistics grid, metadata save). Shared helpers: `utils/backtestHelpers.js`; components `components/backtest/BacktestPortfolioChart.jsx`, `BacktestTradeTimeline.jsx`. Contextual help topic `backtests` in `appDocumentation.js` (Strategy related links updated). Distinct from Screener editor backtests (`POST /api/screeners/{id}/backtest`).

**Strategy Backtesting & Simulation engine (2026-07-31):** Core historical simulator for StoX — **not** live trading. Resumable state machine with cooperative **~20s** time budget per HTTP request (cPanel-safe). Stages: `PREPARING` (stock-major entry/exit screener hit precompute into transient `portfolio_backtest_run_hits`) → `SIMULATING_DAYS` (day-major path-dependent paper portfolio) → `GENERATING_STATISTICS` → `GENERATING_REPORT` → `COMPLETED` / `FAILED`.

| Concern | Implementation |
|---------|----------------|
| Engine | `App\Services\Backtest\BacktestSimulationEngine` |
| Day loop | `SimulationDayProcessor` — eligibility → `AsOfFactorScorer` → `StrategyConfigurationService::score` → `ExitStrategyEvaluator` → `ScorePriorityCapitalAllocator` → `PaperTradeExecutor` → daily snapshot |
| Paper state | `SimulationContext` + `PaperPortfolioManager` (never mutates live cash/holdings/recommendations) |
| Persistence | Immutable `portfolio_backtest_runs` + transactions / trades / snapshots; transient hits + `context_json` cleared on complete/fail/delete (`BacktestPersistenceService`) |
| Reporting | `StatisticsGenerator`, `TimelineBuilder` (timeline derived from trades, not stored) |
| Migration | `2026_07_31_000001_create_strategy_backtest_tables.php` |
| Tests | `tests/Unit/Backtest/PaperPortfolioSimulationTest.php` |

**Simulation day contract:** For each weekday as-of date: load paper portfolio → entry screener hits (union) → exit screener hits → score eligible + held stocks with OHLCV `price_date <= as_of` → decide OPEN/INCREASE/REDUCE/EXIT/HOLD/WATCH (same thresholds/rules as live Recommendation, market gates disabled pending historical Market Analysis series) → auto-execute funded actions at close → update cash/holdings/lots → persist snapshot. Recommendations are **not** cached across days or runs. Architecture intentionally reusable later for paper trading / walk-forward / portfolio replay (same engine + alternate clock/broker adapters).

**Deploy:** upload migration + new PHP under `app/Services/Backtest/*`, `Models/Backtest*`, `Http/Controllers/Api/V1/BacktestController.php`, routes; frontend `build/`; run `cpanel-migrate.php` after upload.

**Static documentation (2026-07-30):** In-app help is generated as plain HTML under `app/public/docs/` (`npm run docs:static` / part of `npm run build`). Public URLs: `/portfolio/docs/index.html`, `/portfolio/docs/{keyword}.html` (e.g. `strategy.html`). No login or JavaScript required for crawlers. Legacy `/documentation?q=` redirects to these files. Header (?) opens the matching static topic.

**Public documentation (2026-07-29):** `/documentation` is reachable **without login** (alongside invite/reset-password). Guests see docs in `lido-main` with a Sign in button; login screen links to documentation; header (?) is shown even when logged out. Product routes linked from topics still require authentication.

**Documentation presentation UX (2026-07-30):** In-app docs keep the same technical prose (`appDocumentation.js` + enrichments) but render with a Stripe/GitHub-style layout: Purpose callouts, “Where am I?” workflow (`DocWorkflow`), comparison tables, decision cards, concept boxes, Mermaid diagrams (`DocMermaid` + `mermaid` package), Bootstrap Icons, Common mistakes accordions, and Related topics. Presentation metadata lives in `components/docs/docPresentation.js` (one entry per keyword). Shared UI: `DocCallout`, `DocRichText` (fenced `mermaid` / ASCII tables → Bootstrap tables), `DocSection` / `DocItemCards`. Trading OS and Strategy ASCII flowcharts were converted to Mermaid without dropping steps. No product behaviour changes.

**Contextual documentation (2026-07-28):** Header (?) left of profile (`HeaderHelpButton.jsx`) captures the current route, maps it to a keyword (`utils/documentationLinks.js` + `data/appDocumentation.js`), and opens `/documentation?q=<keyword>` in a new tab. `DocumentationPage.jsx` resolves the keyword against the index (exact / alias / fuzzy) and shows the enriched article layout above; the left pane also supports free-text topic search with per-topic icons. **Related topics** at the bottom of each article are `<Link>` elements (not buttons) to `/documentation?q=<keyword>`. Documentation content is enriched by `DOC_ENRICHMENTS` + `DEFAULT_RICH_CONTENT` in `appDocumentation.js` (workflow guidance, validation behavior, portfolio/data-freshness caveats, and page-specific deep explanations). On `/documentation`, the sidebar and page chrome are hidden for a focused reading layout, and the docs container is widened (`.lido-docs-page`) for better readability on large screens.

**Strategy help rewrite (2026-07-29):** The Strategy Documentation topic (`keyword: strategy`) is the canonical walkthrough after the in-page Guide/Summary tabs were removed. It covers every Strategy tab field (what each value means and what it is compared against), all Exit Strategy rules with numeric worked examples (max loss / trailing V1 proxy / MA / ATR / screener exit), a four-stock scoring + ranking example against factory-like thresholds, a Mermaid filter-sequence flowchart (Screener → Score → Thresholds → Exit → Portfolio → Market gates → Allocation/Cash → Recommendations → Pending Execution → fill), and an explicit “where do finished ideas appear?” answer (Recommendations, not the Strategy page). Related links include `pending-execution`.

**Scoring weight auto-normalisation (2026-07-29):** Enabled indicator weights no longer block Save when the live total ≠ 100. `StrategyConfigurationService::normalizeConfig` calls `redistributeEnabledWeights` (proportional scale to 100, 2 d.p., largest-remainder). Strategy UI mirrors this on Save and offers **Normalise now**. SD-029 / Strategy spec / MVP scope updated accordingly. Save still requires at least one enabled positive weight.

**Trading OS pages & flow (2026-07-29):** Product-facing map of Screener / Strategy / Discovery / Recommendations / Pending Execution / Review (what each page shows + recommendation path). Spec: [`specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md`](specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md). In-app Documentation topic `trading-os-flow` (also linked from overview and related TOS pages). Indexed in `DOCS.md` §2.6a.

**Indicator architecture analysis (2026-07-30):** As-built report of dual catalogues (`ScreenerCatalog` + `SupportedIndicators`), `TechnicalIndicatorService`, Evaluation composites, consumers, storage, and extensibility — [`specs/architecture/indicators/08-Indicator-Architecture-Analysis.md`](specs/architecture/indicators/08-Indicator-Architecture-Analysis.md).

**Indicator Registry design (2026-07-30, SD-033):** Target evolution — unified metadata/discovery Registry; preserve TI + Evaluation + Strategy scoring; types Primary/Composite/Metric; Admin Indicator Registry UI; planned Liquidity/Tradability indicators (metadata only). Specs: [`specs/architecture/indicators/09-Indicator-Registry.md`](specs/architecture/indicators/09-Indicator-Registry.md), [`specs/architecture/domains/Indicator-Registry-Specification.md`](specs/architecture/domains/Indicator-Registry-Specification.md). Strategy-param→Evaluation wiring tracked separately (TD-19 / PB-054). Implementation plan: [`specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md`](specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md). Indexed in `DOCS.md` §2.6b–d / §2.14l.

**Trading Artifact Framework (SD-034):** Shared envelope for **Indicator**, **Screener**, and **Strategy** artifacts. **Shipped:** envelope/validation, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, AI authoring/runtime docs, V3 multi-strategy surfaces (see Registry infrastructure notes below). **Not shipped / V5 V4-FEAT-008 (2026-08-28):** immutable published versions vs Save-in-place as a first-class model, sharing/distribution, extra AI draft-from-schema catalogue UX, dependency dashboards, rollback, bundle UI, fork workflows. Do not treat shipped registries as unimplemented. Specs: [`specs/architecture/indicators/11-Trading-Artifact-Framework.md`](specs/architecture/indicators/11-Trading-Artifact-Framework.md), [`specs/architecture/domains/Trading-Artifact-Framework-Specification.md`](specs/architecture/domains/Trading-Artifact-Framework-Specification.md). Backlog remainder: PB-058+ / V4-FEAT-008. Indexed in `DOCS.md` §2.6e / §2.14n.

**Indicator Registry Epic 1 (2026-07-30):** Foundation landed — **metadata only, no behaviour change**. New package `App\Services\Indicators\*` (`IndicatorRegistry`, `IndicatorDefinition`, type/category/status/consumer/capability constants, `IndicatorRegistryFactory` seeds from catalogues + Stock Analytics metrics + planned Liquidity/Tradability entries). Bound as singleton in `AppServiceProvider`. Unit tests: `tests/Unit/Indicators/IndicatorRegistryTest.php`. Screener/Strategy/Evaluation/Recommendation/Dashboard **unchanged**.

**Indicator Registry Epic 2 (2026-07-30):** Migration — Registry is metadata SoT. Seed data in `ScreenerPrimarySeed` + `StrategyCompositeSeed`; `ScreenerCatalog` / `SupportedIndicators` are **façades** (`ScreenerCatalogueProjector`, `StrategyCatalogueProjector`, `ScreenerMinBars`). Duplicated definition bodies removed from catalogues. Calculations / formulas / recommendation logic **unchanged**. Validator: `IndicatorRegistryValidator`. Tests: `IndicatorRegistryFacadeParityTest`.

**Indicator Registry Epic 3 + Liquidity/Tradability V1 (2026-07-30):**
- **Admin UI:** `/settings/indicators` list (search, category, type, status) + detail (metadata, parameters, consumers, capabilities, dependency tree, formula explanation — docs only, no editor). Nav from Global Settings.
- **API (admin):** `GET /api/v1/indicators`, `/meta`, `/{id}` — see `specs/architecture/domains/Indicator-Registry-API.md`.
- **New Primaries (screenable):** average_volume, average_turnover, relative_turnover, gap_frequency, gap_fill_ratio, circuit_frequency, circuit_risk — calculators in `LiquidityTradabilityCalculator` + `TechnicalIndicatorService`.
- **New Composites (Registry active, NOT strategy_scorable):** liquidity_score, tradability_score (deps include circuit_risk). Available for future Discovery/Dashboard/Stock Details; **not** in Strategy catalogue; **not** wired into Recommendation/Evaluation ranking.
- **Docs:** `specs/architecture/indicators/13-Indicator-Lifecycle.md`, `14-Indicator-Registry-Diagrams.md`; contextual help topic `indicator-registry`.
- **Impact:** Existing screeners/strategies/recommendations/dashboards unchanged except Screener catalogue gains optional new primary ids (additive). Saved strategies unaffected.

### Impact analysis (Parts A–D, 2026-07-30)

| Surface | Result |
|---------|--------|
| Existing screeners | Continue; catalogue additive only (new optional primary ids). No change to existing conditions. |
| Recommendations | Unchanged — Liquidity/Tradability not in Recommendation Engine. |
| Dashboards | Unchanged — no auto-wiring of new scores. |
| APIs | Backward compatible; new admin `/api/v1/indicators*` only. Strategy catalogue endpoints unchanged in content. |
| Saved strategies | Unchanged — composites not `strategy_scorable`; factory keys unchanged. |
| Evaluation ranking | Unchanged — composites not emitted as Evaluation facts. |

**Recommended follow-ups:** Epic 5 consumer cutover (surface scores on Stock Details/Dashboard); optional Evaluation facts with default-off; TD-19 Strategy param→Evaluation wiring; universe/benchmark relative turnover; official circuit feed if available.

**Trading Artifact JSON (2026-07-30, format spec):** Declarative portable formats for Indicator / Screener / Strategy — `specs/architecture/domains/Trading-Artifact-JSON-Specification.md` plus worked examples under `specs/architecture/domains/artifacts/examples/`. Defines `schema_version`, `minimum_engine_version`, `artifact_version`, validation rules, and extension/BC policy. Runtime package I/O uses this envelope via `ArtifactRegistry` (shipped). Remaining TAF (immutable published versions, sharing, extra AI draft UX) is **V5 V4-FEAT-008**.

**Trading Artifact Registry infrastructure (2026-07-30):** SD-034 Phase 1–2 style landing — `App\Services\Artifacts\*` (envelope, validation, package I/O) with `IndicatorArtifactRegistry`, `ScreenerArtifactRegistry`, `StrategyArtifactRegistry`, umbrella `ArtifactRegistry`. HTTP: `/api/v1/artifacts*`. Indicator create/update → `portfolio_trading_artifact_drafts` only (SD-028; no TI mutation). Screener CRUD maps to existing `ScreenerService`. Strategy create = draft (not activated); active update uses existing `updateActiveConfig`. **Does not** change Screener run, Strategy score, or Recommendation behaviour. Migrate guide: `specs/architecture/domains/Trading-Artifact-Registry-Migration.md`.

**Screener Registry (2026-07-30):** First-class reusable Screener artifacts on top of existing `portfolio_screeners` / `definition_json` — **no Screener execution redesign**. Additive columns (`slug`, `artifact_version`, `definition_hash`, `intent`, `summary`, `tags_json`, `artifact_status`) + `portfolio_screener_versions`. Version snapshots on create and whenever `definition_json` changes (classic editor or registry). Dedicated API `/api/v1/screener-registry*` (list/meta/get/versions/create/update/validate/export/import + shared import). UI: `/screeners/registry` (+ detail) for all users; `/settings/screener-registry` admin. Shared (`is_shared`) screeners appear as read-only registry rows. Help docs synced in `appDocumentation.js`. Migration notes: `specs/architecture/domains/Screener-Registry-Migration.md`.

**Screener Registry import docs (2026-07-31):** In-app topic `screener-registry` (aliases `import-screener`, `screener-json`) now documents the minimum Trading Artifact envelope, mandatory vs optional fields, slug/name/`definition` meanings, condition-tree rules, copy-paste minimal JSON, and common Validate/Import errors. Static HTML regenerated under `/docs/screener-registry.html` (and alias files). **Import** button (renamed from Create from JSON) stays disabled until Validate returns `ok`; editing the textarea clears validation.

**Strategy Registry import docs (2026-07-31):** Same Import gating/rename on Strategy Registry. Topic `strategy-registry` expanded to match Screener depth: minimum envelope, multi-factor scoring example, mandatory vs optional table with **Unique?** column, dedicated **Uniqueness rules** table, nested metadata / eligibility / scoring field tables, optional editor sections (`thresholds`, `exit_strategy`, …), Select-after-import workflow, common errors. UI link “Import schema guide” → `/docs/strategy-registry.html`. `docPresentation.js` presentation entry added.

**Indicator Registry catalogue docs (2026-07-31):** Topic `indicator-registry` enriched like Screener/Strategy guides — consumer map (Screener vs Strategy), types/capabilities, full catalogues for screenable Primaries (with meanings + param defaults/min/max), RS primaries, strategy-scorable Composites (weights/mins/formulas/aliases), Liquidity/Tradability composites, Stock Analytics metrics, acronym glossary (EMA/SMA/RSI/…), artifact envelope notes. UI “Catalogue guide” → `/docs/indicator-registry.html`. Catalogue C/D wide tables split (shared Key/Id) and static/in-app docs CSS wrap code/tables to avoid horizontal scroll for printing. Added Strategy parameter row examples + explicit parameter naming convention (`period`/`fast`/`slow`/`mult` vs Composite `rsi_period`/`lookback_days`/…).

**Trading Artifact authoring docs (2026-07-31):** Screener Registry now documents the full operator enum (and explicitly lists unsupported ops), operand shapes (indicator/constant numeric only), left `entity` indices, and six complete screener examples (MA breakout, RSI pullback, BB squeeze, volume breakout, Minervini, Darvas proxy). Strategy Registry documents live optional sections (`thresholds`, `portfolio_rules`, `exit_strategy`, `market_gates`) with schemas plus Momentum/Growth/Value/Swing/Breakout Strategy envelopes. New topics: `authoring-trading-artifacts` (AI/human workflow) and `trading-cookbook` (recipes). Shared prose in `tradingArtifactGuides.js`.

**AI authoring Markdown pack (2026-07-31):** `npm run docs:static` writes consolidated `app/public/docs/stox-trading-artifacts-ai-guide.md` (deploy download) and mirrors `specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md`. Includes hard-rules table + full Indicator/Screener/Strategy/Authoring/Cookbook prose. Download links on Screener Registry and Strategy Registry import cards. Indexed in `DOCS.md` §2.14s.

**Trading Artifact runtime semantics (2026-07-31):** New topic `trading-artifact-runtime` (prose in `tradingArtifactRuntimeSemantics.js`) documents Recommendation/Screener behavioural contracts for AI authors: weighted scoring with soft min/max gates (zero contribution, weight still dilutes), eligibility **UNION**, threshold evaluation order, exit `any`/`all`, market gates (demote OPEN/INCREASE only), portfolio cash demotion to WATCH, Screener param omission vs catalogue defaults (TI service often `period ?? 20`), Validate reject (no clamp), missing data, `eq` epsilon, Import normalisation, `schema_version` vs unused `minimum_engine_version`, and pointers to the normative Contract / Complete Examples. Included in the AI guide **Appendix**. Short pointers prepended to Indicator/Screener/Strategy registry overviews.

**AI Authoring Contract (2026-07-31):** Normative RFC 2119 constitution (`tradingArtifactAuthoringContract.js`, topic `ai-authoring-contract`) — ~102 atomic MUST/SHOULD rules. AI guide structure is now: Introduction → Contract → Hard Rules → Authoring Workflow → Indicator/Screener/Strategy Registries → Cookbook → Complete Examples → Appendix (Runtime Semantics). Contract is the single normative source of truth; reference sections must not contradict it. Maintenance: update Contract first when behaviour changes.

**AI Strategy Designer (2026-07-31):** Collapsible panel on `/strategy` (`AIStrategyPromptBuilder`). Client-side only — builds an external-AI prompt from structured form fields (style, risk, holding period, market, universe, max positions, allocation, exit style, market prefs, optimization priorities, complexity, explainability, constraints). `generatePrompt(inputs, templateId)` + template registry (`strategyPrompt/templates/`, StoX Default implemented; ChatGPT/Gemini/Claude/Grok slots reserved). Auto-copies to clipboard with toast; Copy Again / Select All / Clear / Reset Defaults; form persisted in `localStorage` (`lido.strategy.aiPromptBuilder.v1`). No backend / no LLM API. Docs updated on Strategy topic; attach `/docs/stox-trading-artifacts-ai-guide.md` when pasting externally. Panel open state mounts body via conditional render (not Bootstrap `.collapse`) so content is not left blank by collapse CSS.

**Registry success UX (2026-07-31):** Screener Registry and Strategy Registry Import / Select success use toast notifications instead of top-of-page inline alerts. After Validate succeeds, a green check + “Validated successfully” (`ValidationSuccessBanner`) appears above Validate/Import while the JSON result panel remains. Detail-page Select/Export also toast.

**Strategy Registry (2026-07-30):** First-class reusable Strategy artifacts on `portfolio_tos_strategies` / versions — **no Recommendation algorithm change**. Additive `slug` / `definition_hash` / `intent` / `summary` / `tags_json`. Exactly one active Strategy per portfolio; `POST /api/v1/strategy-registry/{id}/activate` selects. Import creates drafts; export is portable (Screener slug/factory_key + Indicator registry ids — never portfolio `screener_id`, never embedded Screener trees). Minervini (`momentum_factory`) auto-migrates to slug `momentum_strategy` with Minervini Trend Template eligibility. UI: `/strategy/registry` (+ detail); admin `/settings/strategy-registry`. Active editor `GET/PUT /api/v1/strategy` unchanged (in-place Save). Notes: `specs/architecture/domains/Strategy-Registry-Migration.md` (migration + architecture + future enhancements).

**Discovery merges Evaluations UI (2026-07-30):** Removed the separate Evaluations nav page. Discovery (`/candidates`) shows evaluation rank/score/confidence/explanation (from latest `EvaluationResult` per candidate); **Run discovery** auto-runs evaluation afterward; **Run evaluation** re-scores the latest discovery run. `/evaluations` redirects to `/candidates`. Docs clarify long-focused evaluation scoring and Discovery↔Evaluation linkage (no Recommendations references in that Discovery topic). APIs `GET /v1/evaluations` and `POST /v1/evaluation/runs` remain.

**V5 FEAT-034 Evaluation history (2026-09-04):** Discovery now includes a read-only selector for the 20 newest portfolio-scoped Evaluation runs and loads each run's immutable ranked results through `GET /api/v1/evaluation/runs` plus the existing run-filtered `GET /api/v1/evaluations`. Run history is bounded to 50 server-side, reports status/result count/error metadata, and explicit result lookup now enforces active-portfolio ownership. The frozen merged-page rule remains: `/evaluations` still redirects to `/candidates`.

**Brand rename (2026-07-30):** Product display name is **StoX by Lido Alexion**. Header shows **StoX** in Nulshock at the former title size; **by Lido Alexion** is a much smaller cursive byline. Browser title (`app.blade.php`) updated accordingly.

**Discovery reason icons (2026-07-30):** Discovery reason column renders matched patterns as `PatternSketch` icons (same as Watchlist / Patterns guide) with name + category on hover; links to `/patterns#id`. Non-pattern signals (screener hit, holding/watchlist membership) stay text badges.

**Data Quality Center (2026-07-30):** Added a new admin-only subsystem for market-data anomaly governance, starting with corporate actions.
- **Schema (generic / extensible):** new tables `portfolio_data_quality_issues`, `portfolio_data_quality_issue_evidence`, `portfolio_data_quality_issue_resolutions`, and `portfolio_price_adjustment_factors` (`2026_07_30_000001_create_data_quality_tables.php`). Design keeps **immutable detection fields** (method/source/suggested ratio/confidence/raw payload/timestamps) separate from **mutable resolution history** (accept/reject/modified/auto/migrated, resolver, notes, superseded resolution chain).
- **Detection pipelines:** new commands/services:
  - `portfolio:sync-corporate-actions` (`DataQualityCorporateActionSyncService`) imports exchange-feed split/bonus/face-value events into pending issues (does not modify OHLCV directly).
  - `portfolio:detect-corporate-action-anomalies` (`DataQualityCorporateActionHeuristicService`) scans overnight discontinuities (prev close vs current open, ratio, volume delta, common split ratios) and queues review issues with confidence/evidence.
  - `portfolio:auto-resolve-data-quality-issues` auto-accepts **eligible exchange-feed** pending issues after configurable days (see F042 V2 hardening below; heuristic issues require manual review).
- **Resolution workflow + audit:** `DataQualityResolutionService` now records append-only resolution events and supports reversal-style re-resolution by superseding prior decisions; original detector suggestion is never overwritten. Accepted decisions generate adjustment factors in `portfolio_price_adjustment_factors`; rejected/reversed paths deactivate active factors.
- **Data safety gating:** unresolved (`pending_review`) stocks are excluded from key decision paths via `DataQualityGuardService` integrations in `DiscoveryEngine`, `EvaluationEngine`, `ScreenerRunService`, `PatternScanService`, `RelativeStrengthService`, and recommendation candidate loading (`RecommendationGenerationPipeline`). This prevents unresolved anomalies from polluting screeners/discovery/recommendation scoring.
- **Admin UI / APIs:** added `/settings/data-quality` (queue + review actions) and `/settings/data-quality/history` (resolved audit history). Backend admin API endpoints under `/api/data-quality/*` in `DataQualityController`.
- **Legacy migration path:** added `portfolio:migrate-legacy-corporate-actions` (`DataQualityLegacyCorporateActionMigrationService`) to map existing manual split/bonus records into Data Quality issues (`detection_method=legacy_manual`, resolution type `migrated`) without removing the legacy corporate-action UI.
- **Production no-SSH script:** added `deploy/cpanel-data-quality-center.php` to run sync/scan/auto/migrate tasks via browser (`?token=...&task=sync|scan|auto|migrate`, optional `&apply=1` for migration). `deploy/prepare-upload.ps1` now stages this script.
- **Contextual help sync:** updated `app/resources/js/src/data/appDocumentation.js` with new topics `data-quality-center` and `corporate-action-history`, route matching, controls/concepts, and Settings cross-links.

**F042 V2 hardening (2026-08-09):** Formal V2 implementation pass per `docs/v2/F042-DATA-QUALITY-SPEC.md` and product-owner policy decisions (`docs/v2/F042-POLICY-DECISIONS.md`). Changes:
- **Conditional auto-accept:** `portfolio:auto-resolve-data-quality-issues` auto-accepts **only** eligible `exchange_feed` issues with `exchange_match=true`, valid ratio, and confidence ≥ 1.0 after configurable threshold (`DATA_QUALITY_AUTO_ACCEPT_DAYS` / `config/services.php` → `data_quality.auto_accept_days`, default 15). Heuristic and low-confidence issues never auto-accept.
- **Repeated detection:** `DataQualityIssueService::createOrRefreshPendingIssueForStock` appends evidence on duplicate pending detections (immutable original detection fields preserved; may update `latest_suggested_ratio`).
- **Detection run ID:** each sync/heuristic invocation generates `{command}:{uuid}` stored in evidence payload (`detection_run_id`).
- **Concurrent resolution:** pending-queue accept/reject returns **409** (`DATA_QUALITY_STALE_RESOLUTION`) when issue is no longer `pending_review`; history re-resolution uses API flag `re_resolve=true` (Corporate Action History page) and **requires a non-empty note**.
- **F043 handoff:** accepted issues create adjustment-factor metadata with `metadata.ohlcv_repair_status = pending` (queryable via `PriceAdjustmentFactor::scopePendingOhlcvRepair`); F042 does **not** mutate OHLCV or invoke F043.
- **Tests:** PHPUnit coverage under `tests/Unit/DataQuality*`, `tests/Feature/DataQuality*`, including EvaluationEngine AC009 gating (`DataQualityEvaluationGatingTest`).
- **Compliance:** `docs/v2/F042-FINAL-COMPLIANCE-AUDIT.md` — remaining intentional non-blockers: no unique pending-dedupe index; optional 409 fresh-issue body.

**F043 specification / reconciliation (2026-08-09):** Planning docs under `docs/v2/` — `F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md`, `F043-IMPLEMENTATION-GAP-MATRIX.md`, `F043-F042-BOUNDARY.md`. Indexed in `DOCS.md` §3.C.

**F043 implementation (2026-08-09):** Formal V2 factor-driven OHLCV repair:
- **Factor path:** `CorporateActionPriceRepairService::scanPendingFactors` / `repairPendingFactors` consumes `PriceAdjustmentFactor::pendingOhlcvRepair()`, validates supported types (`split`, `bonus`, `face_value_split`), previews without mutation, applies with stored `price_divisor`/`volume_multiplier` via `CorporateActionPriceAdjustmentService::adjustHistoricalPricesByDivisors`, wraps each apply in `DB::transaction` + `lockForUpdate`, and sets `metadata.ohlcv_repair_status=completed` with `metadata.ohlcv_repair` audit only after successful row updates.
- **Ordering / safety:** ascending `effective_ex_date` then id; same stock+ex-date pending duplicates → `ambiguous` (no mutation); completed factors skipped (idempotent).
- **Ops:** `portfolio:repair-corporate-action-prices` and `deploy/cpanel-repair-corporate-action-prices.php` support `--factors-only` / `factors_only`, `--factor` / `factor`, and retain `--apply` dry-run default. Does **not** change F042 gating, issue status, transactions, or holdings.
- **Tests:** `tests/Unit/CorporateActionFactorPriceRepairTest.php` (+ existing adjustment/repair unit tests).

**F043 double-restatement hardening (2026-08-09):** Enforced single OHLCV writer per stock+ex-date+action-family event:
- `PriceAdjustmentFactor::activeOhlcvRepairForEvent` / `findActiveOhlcvRepairForEvent` matches active pending/completed factors (`split`↔`split|face_value_split`, `bonus`↔`bonus`).
- `CorporateActionService::apply` still mutates ledger; **skips** historical OHLCV when a matching factor exists and records `metadata.price_adjustment.deferred_to_factor`.
- F043 CA recovery scan uses the same match for `deferred_to_factor` (no CA-path mutation).
- Tests: `tests/Unit/CorporateActionOhlcvDelegationTest.php` (both execution orders, repeats, mismatch stock/date/type, failed F043 still blocks F020 OHLCV).
- **Verdict:** `F043_COMPLETE` (remaining non-blockers: admin API deferred; SQLite lock soft / no concurrent race test).

### V2 Market Data Quality — closed (2026-08-09)

| Feature | Status |
|---------|--------|
| **F042** | **COMPLETE** (`F042_COMPLETE_WITH_NON_BLOCKERS`) |
| **F043** | **COMPLETE** (`F043_COMPLETE`) — factor consumption, preview/apply, idempotency, multi-factor, F020/F043 single-writer invariant, double-restatement resolved; F020 ledger + F042 boundary preserved |

**Deferred / non-blocker (F043 — not incomplete):** admin API/UI; scheduled auto-repair; rollback snapshots; dividend/rights/merger; true multi-process concurrency suite.

**Regression snapshot:** F043+delegation+adjustment/repair+F042 **67/67**; recommendation/pipeline/market gates **23/23**; full suite @512M **612** tests (**603** passed, **5** failed, **4** errors — unrelated/pre-existing; suite not fully green). Tracking: `docs/v2/V2-ROADMAP.md`, `DOCS.md` §3.B–3.C.

**Shared API hooks (TD-014 / V4-FEAT-027):** `resources/js/src/hooks/useApiGet.js` and `useApiMutation.js` wrap the existing axios client (`api.js`); export `getApiErrorMessage()` from `api.js` for TOS/Laravel error text. Adopted on Strategy, Recommendations, Cash, Discovery (`CandidatesPage`), Pending Execution, Review dashboard, and Notification history. Envelope unwrap helper: `utils/tosEnvelope.js`. Not TanStack Query (V5-FEAT-035).

**V5 FEAT-035 frontend migration foundation (2026-09-04):** Added strict TypeScript/no-emit checking (`tsconfig.json`, `npm run typecheck`) to CI while retaining JavaScript interoperability. The application root now owns a shared TanStack Query client. Evaluation history is the first portfolio-scoped query slice, and its ranked results use a typed AG Grid component loaded through `React.lazy` to keep the grid runtime out of the initial application chunk. The broader migration remains incremental and is tracked as IN PROGRESS.

**V5 FEAT-037 Dashboard Kite readiness (2026-09-04):** Automatic portfolios check broker status independently of cached Dashboard analytics and show a prominent reconnect warning only when Kite is unusable. Dashboard-initiated login stores an allowlisted return target inside the existing encrypted short-lived state, so successful authentication returns to Dashboard while Account settings retains its current return. Portfolio settings expose `kite_readiness_reminder_time` (default 08:30 in the application timezone; blank disables). The every-minute `portfolio:send-kite-readiness-reminders` schedule sends at most once per portfolio/date, only for Automatic + unusable, through existing Telegram credentials; successful delivery alone records the date.

**Cash UI (2026-07-25):** Full cash management lives on `/cash`: cash balance, **Reserved cash** (with expandable reservation details), and Available cash (balance − reserved), plus deposit / withdraw / adjust via shared `NumberInput` (₹1 steps), optional remarks, transaction date (`TransactionDateInput`, default today), and cash account statement. Dashboard no longer shows Available Cash / Cash reserved cards. Withdrawals cannot exceed available investable cash (UI + API). Ledger stores `entry_date` (migration `000008`).

**NumberInput (Jul 2026):** Shared `components/NumberInput.jsx` (+/− stepper). Prop `buttonVariant` (`primary` default = blue step buttons; `secondary` = grey). Screener create/edit form passes `secondary` for all spinners; Cash, Strategy, Transactions, Settings, etc. keep primary.

**Dashboard cash deposit fix (2026-07-25):** SD-026 deposit UI briefly declared `submitCashDeposit` before `fetchDashboard` (TDZ → production “Cannot access … before initialization”) and dropped `cachedAt` / `handleTopMoverPeriodChange`. Deposit callback now sits after `fetchDashboard`; cache timestamp and top-mover period handler restored. Deposit UI later moved off Dashboard to `/cash`.

**Recommendations review dialog:** Bootstrap modals were staying light in dark mode because only `data-theme` was set (not `data-bs-theme`). `applyResolvedTheme` now sets both. Dialog also uses `.lido-tos-review-modal` Lido tokens. Qty → Quantity. Defer/Reject close the dialog; Approve on BUY/SELL moves to pending execution (trade later); Approve on WATCH/HOLD N/A (insights view-only).

**Recommendations clarity cleanup (2026-09-02):** Recommendation rows now identify their strategy; `published` insights are labelled informational; WATCH/HOLD allocation text describes intent instead of showing a zero-to-zero allocation; current scope is explicitly status-based with result count and visible generated range; closed history is independently selectable. HOLD insights remain persisted for audit but are hidden by default behind **Show HOLD insights**. Migration `2026_09_02_190000_retire_legacy_hold_pending_execution.php` expires invalid legacy HOLD records left in executable lifecycle states.

**Shared ledger write (Option A):** `TransactionWriteService::create` is the single create path for `POST /api/transactions` and TOS completions (`ExecutionEngine` after shared insert). Holdings, realizations, buy OHLCV backfill, and snapshot rebuild stay aligned.

**TD-009 (2026-07-27, code audit remediation):** `CorporateActionService::applyBonus` was calling raw `Transaction::query()->create([...])` with price 0, bypassing `TransactionWriteService` (the SD-021 single write path). Fixed: `TransactionWriteService::normalizeInput`/`insert` now accept an optional `corporate_action_id` (persisted on create) and force price to `0` when `source` is `Transaction::SOURCE_BONUS` (all other sources still require price > 0). `CorporateActionService` now has `TransactionWriteService` injected; `applyBonus($profile, $stock, $action, $preview)` calls `$this->writes->insert(...)` (not `create()`/`applyAfterCreate`) since `apply()` already recalculates holdings/realizations/metrics/snapshots after the DB transaction — using `create()` would double-apply. `applySplit`'s `Transaction::update()` (quantity/price rescale + `corporate_action_id` backfill) is unchanged — this TD is scoped to **creates** only. Verified via `php vendor/bin/phpunit --filter "CorporateAction|Transaction"`: all Corporate Action / Transaction Write / Execution Engine tests pass (2 pre-existing failures in `TransactionStockResolverTest` are unrelated — missing cash-balance seeding for a cash-management feature added in a later, unrelated commit).

**TD-010 (2026-07-27, code audit remediation):** Adopted a minimal exception contract for business-rule failures without a broad rewrite. New `App\Exceptions\DomainException` (message + `errorCode` + `httpStatus`, default 422) for domain preconditions; keep `ValidationException` for input/field validation (e.g. `RecommendationLifecycleService` review/approve — unchanged). Global API render in `bootstrap/app.php` maps uncaught `DomainException` on `api/*` to `ApiEnvelope::error(code, message, status)`. Converted one `RuntimeException` in `RecommendationGenerationPipeline::prepareContext()` (“No completed evaluation run…”) → `DomainException` with code `RECOMMENDATION_PRECONDITION` so `POST /api/v1/recommendations/generate` returns 422 instead of 500. **Not converted (intentional):** `EvaluationEngine` still throws `RuntimeException`; `TradingOs\EvaluationController::evaluationRunsStore()` catches it locally as `EVALUATION_PRECONDITION` (V4-FEAT-027 moved the catch with the controller split; mapping unchanged). Approved engine behaviour unchanged.

**Undo review / fill:** `POST /api/v1/recommendations/{id}/reopen` returns Approve/Reject/Defer to `pending_review`. Deleting a Transactions row linked by `recommendation_id` returns the recommendation to `pending_execution`.

### Tests

`tests/Feature/TradingOsPipelineTest.php` (pipeline, review/approve, pending-execution, manual execute, cancel-execution, outcomes).

### Production schema note (Jul 2026)

Recommendations / Review / Notifications return “schema out of date” when `portfolio_tos_*` tables are missing (migrate ran without those migration files, or migrate failed).

**MySQL index name limit:** auto-generated compound index names on `portfolio_tos_*` exceeded 64 characters and aborted mid-migration (tables partially created). Migrations now use short explicit index names (`tos_rec_profile_type_idx`, etc.) and are idempotent.

Repair: upload updated `2026_07_25_000002_*` + `000003_*`, then:

`/portfolio/cpanel-repair-tos-schema.php?token=YOUR_TOKEN&apply=1`

Delete repair script after success. Updated `cpanel-migrate.php` also verifies TOS tables.

**Evaluation JSON crash:** Indicator `INF`/`NAN` values can break Eloquent JSON casts on shared hosting. EvaluationEngine now sanitizes floats, caps OHLCV history, and isolates per-candidate failures.

### Spec deviations (confirmed)

Sanctum not JWT; reuse `portfolio_*`; Screener/PatternScan remain Services; Telegram only; no Strategy entity; approval separated from execution (SD-025).

## Local development runbook (agent / future sessions)

Use this section to bring the project up again on a Windows dev machine. Human-oriented summary also lives in root `README.md`.

### What must run separately

| Service              | Required?                         | Typical setup on Windows                                  | Notes                                                                                                                                                                 |
| -------------------- | --------------------------------- | --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **MySQL**            | **Yes**                           | EasyPHP / XAMPP / WAMP MySQL, standalone MySQL, or Docker | App uses `portfolio_*` tables; can share an existing DB (e.g. `lido_db`).                                                                                             |
| **PHP web**          | **Yes** (one of)                  | `php artisan serve` **or** Apache/Nginx                   | Default dev URL: `http://127.0.0.1:8001`. Document root for Apache: `app/public`.                                                                                     |
| **Node.js**          | Dev UI hot-reload                 | Installed globally                                        | Only for `npm run dev` / `npm run build`.                                                                                                                             |
| **Apache (EasyPHP)** | **No** (if using `artisan serve`) | EasyPHP control panel                                     | Optional. Use when you prefer vhost over built-in PHP server.                                                                                                         |
| **Queue worker**     | Optional locally                  | `php artisan queue:listen`                                | `QUEUE_CONNECTION=database`; needed for async jobs if not using `sync`.                                                                                               |
| **Scheduler**        | Optional locally                  | `php artisan schedule:work` or OS cron                    | `portfolio:daily-sync` (holdings prices); `portfolio:sync-universe-prices` (NSE universe OHLCV batches); `portfolio:send-notifications` per `notification_schedules`. |
| **Redis**            | No                                | —                                                         | Not used by default (`CACHE_STORE=database`).                                                                                                                         |
| **Vite dev server**  | Optional                          | `npm run dev`                                             | Hot reload for React; omit if you ran `npm run build` and only use `artisan serve`.                                                                                   |

**Minimum to use the app:** MySQL running + Laravel reachable (usually `php artisan serve`) + frontend assets (`npm run dev` **or** `npm run build`).

### Prerequisites

- **PHP 8.3+** with extensions: `mbstring`, `pdo_mysql`, `openssl`, `curl`, `json`, `tokenizer`, `xml`, `ctype` (recommended: `fileinfo`).
- **Composer** (project uses `app/composer.json`; `composer.phar` may exist at repo root).
- **Node.js 18+** and npm (in `app/`).
- **MySQL 5.7+ / 8.x** listening on `127.0.0.1:3306` (or your host/port).
- Verify PHP: `cd app && php -v && php -m`

Confirm the **same** `php.exe` is used for CLI and (if applicable) Apache/EasyPHP, or extension/session issues will confuse debugging.

### Repository layout

**Folder rename (Jun 2026):** The application root was renamed from `backend/` to **`app/`** — it holds the full Laravel + React stack (not “API only”). Setting keys like `backend_log_level` are unchanged (they mean server-side logging, not the old folder name).

**Human-readable structure guide:** [README.md → Project structure](README.md#project-structure) (collapsible section with folder tables and data-flow notes).

```
LidoPortfolio/
  README.md                 ← short quick start
  implementation.md         ← this file (read first for agents)
  app/                      ← application root (Laravel + React; artisan, .env, public/)
    app/                    ← Laravel PHP code (controllers, services, models)
    resources/js/src/       ← React SPA source
    .env.mysql.template     ← copy to .env
    config/DBConfig.php     ← optional MySQL constants (keep out of git if secrets)
    public/                 ← web root if using Apache
  deploy/                   ← cPanel deploy scripts & guides (see deploy/DEPLOY.md)
  .gitignore                ← root ignore (secrets, vendor, node_modules, build)
```

**Git (Jun 2026):** Monorepo at project root (`LidoPortfolio/`). Remote: `https://github.com/lido-alexion/LidoPortfolio` (private). Branch `master` tracks `origin/master`. Secrets excluded: `app/.env`, `app/config/DBConfig.php`, env backups.

### First-time setup (clean machine)

From PowerShell:

```powershell
cd D:\Projects\LidoPortfolio\app

# 1) Environment
copy .env.mysql.template .env
# Edit .env: DB_*, APP_KEY (or run key:generate), APP_URL, SANCTUM_STATEFUL_DOMAINS, SESSION_SECURE_COOKIE

php artisan key:generate

# 2) Optional legacy DB constants (only if your workflow uses them)
# copy config\DBConfig.php.template config\DBConfig.php  # then fill DB_HOST, DB_NAME, etc.

# 3) Dependencies
composer install
npm install

# 4) Database (MySQL must already be running)
php artisan migrate --force
php artisan db:seed

# 5) Frontend assets (required if not using `npm run dev` in a second terminal)
npm run build
```

After any React/CSS UI change, run `npm run build` again (or keep `npm run dev` running). The Lido shell styles live in `resources/js/src/styles/lido-app.css`.

**Local `.env` values that commonly bite:**

```env
APP_URL=http://127.0.0.1:8001
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8001,127.0.0.1,127.0.0.1:8001
SESSION_SECURE_COOKIE=false
```

(`SESSION_SECURE_COOKIE=true` in `.env.mysql.template` breaks cookie login over plain `http://`.)

**Default seeded login** (`DatabaseSeeder`):

- Email: `admin@lidoportfolio.local`
- Password: `password123`

### Every time you return to the project

1. Start **MySQL** (EasyPHP / XAMPP / Windows service).
2. In `app/`:

```powershell
cd D:\Projects\LidoPortfolio\app
```

3. Choose **one** dev mode:

**Option A — all-in-one (recommended):**

```powershell
composer run dev
```

Starts concurrently: `php artisan serve`, `queue:listen`, log tail (`pail`), `npm run dev`. Default serve URL is `http://127.0.0.1:8000` unless you customize; this repo’s smoke scripts use **port 8001**, so prefer Option B or run:

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

in a separate terminal alongside `composer run dev` (only one process can bind a port).

**Option B — manual terminals (matches port 8001):**

| Terminal     | Command                                          |
| ------------ | ------------------------------------------------ |
| 1            | `php artisan serve --host=127.0.0.1 --port=8001` |
| 2            | `npm run dev`                                    |
| 3 (optional) | `php artisan queue:listen`                       |
| 4 (optional) | `php artisan schedule:work`                      |

4. Open **`http://127.0.0.1:8001`** in the browser (not Vite’s port; Laravel serves the SPA and proxies Vite assets in dev).

**React + `npm run dev`:** `resources/views/app.blade.php` must include `@viteReactRefresh` **before** `@vite` (Laravel 13 + `@vitejs/plugin-react`). Without it, the console shows `can't detect preamble` and the SPA fails to load. On Windows, if `npm run dev` exits immediately, use `npx vite` from `app/` instead.

### Using EasyPHP / Apache instead of `artisan serve`

1. Start **MySQL** from EasyPHP.
2. Point the site **document root** to `D:\Projects\LidoPortfolio\app\public`.
3. Set `APP_URL` in `.env` to that vhost URL (e.g. `http://localhost/lidoportfolio/public` or a virtual host).
4. Add that host (and port if any) to `SANCTUM_STATEFUL_DOMAINS`.
5. Run `npm run build` (no Vite dev server required).
6. `php artisan migrate --force` still runs from CLI in `app/`.

You do **not** need a separate Node server in production-style Apache mode after `npm run build`.

### Outbound HTTPS (NSE / Yahoo / Telegram)

If price sync fails with `cURL error 60`, set in `.env`:

```env
CURL_CAFILE=C:\path\to\cacert.pem
```

See `DEPLOYMENT_VALIDATION_PLAN.md` § SSL.

**NSE session cookies (Jul 2026):** `NseHttpClient` uses `CookieJar` + warms `www.nseindia.com` and `marketStatus`. **Equity historical:** primary `charting.nseindia.com` daily OHLCV (`RELIANCE-EQ` token lookup), fallback `NextApi` `getHistoricalTradeData` (66-day chunks, shared session). Charting rows outside the requested range are discarded before deciding success (so a single stray bar no longer blocks NextApi/Yahoo). **Indexes:** NSE charting index API for configured `nse_charting_name` values (not only NIFTY50); BSE indexes (Sensex, BSE 100/200/500) skip NSE and use Yahoo → Alpha Vantage. **BSE-only equities:** `providerChainForStock()` is `bse_bhavcopy → yahoo → alpha_vantage` (NSE never called). **BSE bhavcopy (Jul 2026):** `BseBhavcopyService` downloads BSE UDiFF cash-market bhavcopy (`BhavCopy_BSE_CM_0_0_0_{YYYYMMDD}_F_0000.CSV`), matches `FinInstrmId` / `TckrSymb` to `portfolio_stocks.bse_scrip_code` or symbol, and upserts OHLCV for **BSE-only** universe stocks. Bulk backfill: `portfolio:backfill-bse-bhavcopy` or browser `cpanel-bse-bhavcopy-backfill.php` (dry run default; `&apply=1` writes; optional `&sync_scrip=1`, `&from=`, `&to=`, `&days=` default **5** on cPanel). CSV is **stream-parsed** line-by-line. **Gap fill:** per-stock fill **skips** `bse_bhavcopy` by default (`bse_bhavcopy_gap_fill_enabled=false`) and when range exceeds `bse_bhavcopy_max_gap_calendar_days` (45) — use bulk bhavcopy for BSE history. **Scheduler fix (Jul 2026):** heartbeat/explain probes use `--write-heartbeat` / `--explain` flags (not `=1`) so cPanel `schedule:run` stops erroring every minute. **Gap fill provider chain (Jul 2026):** per missing range, tries exchange-appropriate providers until the range closes; provider rows are clipped to the requested dates; partial fills and provider errors surface in the failure report. **Yahoo:** tries `query1` then `query2`; for Indian equities tries `.NS` then `.BO` when the primary ticker has no data (fixes thin listings like `3BBLACKBIO` where Yahoo only has `.BO`); missing-symbol 400s are treated as empty, not hard failures. **Production probe:** `cpanel-probe-price-providers.php?token=...` loads DB exchange + Yahoo candidates — delete after use.

### Useful commands

```powershell
cd D:\Projects\LidoPortfolio\app

php artisan migrate --force          # after pulling new migrations
php artisan db:seed                  # re-seed admin + default settings
php artisan portfolio:daily-sync     # manual daily prices + portfolio snapshots (holdings)
php artisan stocks:sync              # NSE equity master CSV import
php artisan portfolio:sync-universe-prices --mode=backfill --all   # one-time ~1y OHLCV for full NSE universe
php artisan portfolio:sync-universe-prices --mode=daily            # one batch of universe incremental sync
php artisan test                     # PHPUnit (uses sqlite in-memory)
npm run test:js                      # node:test + Vitest TOS smoke
npm run test:js:tos                  # Vitest TOS UI smoke only
npm run test:e2e:tos                 # one Playwright TOS path (npx playwright install chromium once)
npm run build                        # production JS/CSS bundle

# API smoke (server must be on :8001)
PowerShell -ExecutionPolicy Bypass -File tests\Feature\api_smoke.ps1
```

### Tests vs local MySQL

`php artisan test` uses **SQLite in-memory** (`phpunit.xml`); it does not require MySQL. Local browsing and manual testing use MySQL from `.env`.

### Alert policies (Jul 2026)

**V2 F127 (2026-08-09):** Formal pack + hardening — [`docs/v2/F127-PORTFOLIO-ALERTS-SPEC.md`](docs/v2/F127-PORTFOLIO-ALERTS-SPEC.md), [`F127-BOUNDARY.md`](docs/v2/F127-BOUNDARY.md), [`F127-POLICY-DECISIONS.md`](docs/v2/F127-POLICY-DECISIONS.md), [`F127-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F127-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.E. Verdict: **`F127_COMPLETE_WITH_NON_BLOCKERS`**. Final compliance audit: compliant with non-blockers. Tracking cleanup: `V2-ROADMAP.md` and PD-F127-07 CURRENT wording aligned to expire→evaluate (no behaviour change).

**Hardening delivered:** `DailyMarketDataJob` runs trading-day `expireBeforeTradingDay` **before** `evaluateAllProfiles` when sync fully succeeds and the max portfolio price date advances (PD-F127-07 — daily lifecycle expire → evaluate → create/reuse). `expireForProfileStockIfUnheld` expires alerts when the ledger hits zero without consulting the stale Holding row (so `holding_closed` works for already-open positions). Repeated Telegram digests, empty-schedule = in-app only, weekend/holiday skip, holdings-only level conditions, implicit re-arm, and profile scoping preserved. Manual Run now remains evaluate-only (no trading-day expiry). `is_sent` left as legacy/dead. Tests: `AlertLifecycleOrderingTest` + digest regressions. Help: alert-policies topic updated.

**V2 F019 (2026-08-09):** Bulk CSV Import hardening complete. Pack: [`docs/v2/F019-BULK-CSV-IMPORT-SPEC.md`](docs/v2/F019-BULK-CSV-IMPORT-SPEC.md), [`F019-BOUNDARY.md`](docs/v2/F019-BOUNDARY.md), [`F019-POLICY-DECISIONS.md`](docs/v2/F019-POLICY-DECISIONS.md), [`F019-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F019-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.F. Verdict: **`F019_COMPLETE_WITH_NON_BLOCKERS`**.

**Delivered:** `TransactionWriteService` financial unit = ledger insert + holdings/realizations + cash in one DB transaction (PD-14); OHLCV/snapshots post-commit best-effort (skipped in PHPUnit). `POST /api/transactions/bulk` all-or-nothing commit with client `batch_id` + per-row `row_id` (`portfolio_transaction_import_batches` / `_items`); already-committed batches return idempotent `already_committed`. Frontend `BulkTransactionImport.jsx` uses bulk API (no sequential POSTs); preserves review order and per-row dates. Tests: `BulkTransactionImportTest`. Audit: `F019_COMPLIANT_WITH_NON_BLOCKERS`.

**F014 (delivered 2026-08-09):** `GET /api/portfolio/historical-holdings?as_of=YYYY-MM-DD` — on-demand ledger reconstruction (inclusive ≤ D; order date,id; fee-exclusive cost), `warnings[]` for historical oversells, valuation via latest `adjusted_close ?? close` on/before D, null/incomplete when prices missing, unrealized P/L + % (same as live Holdings). UI: `/portfolio/historical-holdings` (sidebar Historical Holdings). Does not use `portfolio_holdings` or F015 snapshots as SoT. Tests: `HistoricalHoldingsTest`, extended `PortfolioHistoricalHoldingsServiceTest`. Verdict: `F014_COMPLETE_WITH_NON_BLOCKERS`. Cash/realized/export/compare deferred/OOS.

Per-portfolio rules in `portfolio_alert_policies`; evaluated after daily price sync and via **Run policies now** (`POST /api/alert-policies/evaluate`). Optional `alert_definition` text (human-readable summary shown in policy list). Universe: **Holdings** only (extensible). Conditions use enriched holding fields vs column / derived formula (`{{column}}` tags + `+ - * / ( )`) / constant. Generated alerts: `alert_type=policy`, `instance_key` = `{user_id}-{profile_id}-{stock_id}-{policy_id}`, `condition_display`, `action_suggested`, `context_json` (`{ text }` rendered from optional `context_template` with same `[[ ]]`, `<< >>`, `{{column}}` syntax as message; legacy `context_columns` array still supported). Dedup on active `instance_key`. UI: Settings → Portfolio → **Manage alert policies** (`/settings/alert-policies`). **Built-in stoploss alert generation removed (Jul 2026):** `StoplossService` updates trailing-stop metrics only; alerts come from policies (or manual fixtures in tests). `GET /api/dashboard` returns active alerts under `alerts` (was `stoploss_alerts`). `AlertService::getActiveForProfile()` backs dashboard + `GET /api/alerts`. **API errors:** production JSON 500/503 responses include actionable `message` + `request_id` (not generic "Server Error"); evaluate checks schema before running.

### Stock Screener (Jul 2026)

Portfolio-scoped OHLCV technical screens (cached `portfolio_stock_prices` only — no live provider fetch during a run).

**V2 F060 (2026-08-09) — COMPLETE:** Shared Screener Import hardened — [`docs/v2/F060-SHARED-SCREENER-IMPORT-SPEC.md`](docs/v2/F060-SHARED-SCREENER-IMPORT-SPEC.md), [`F060-BOUNDARY.md`](docs/v2/F060-BOUNDARY.md), [`F060-POLICY-DECISIONS.md`](docs/v2/F060-POLICY-DECISIONS.md), [`F060-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F060-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.H. Verdict: **`F060_COMPLETE_WITH_NON_BLOCKERS`**. **DECIDED + shipped:** same-user multi-profile only via `Screener::sharedVisibleTo` / `ownedOrSameUserShared` (profile→user); classic + registry shared list/GET/import aligned; shared payload `{id,name,definition_json}` (+ minimal registry flags); eligibility/Discovery/backtest pin same-user; import fork; name collisions `Name (1)`, `Name (2)`, …; no admin bypass. Tests: `F060SharedScreenerAuthzTest`. Residual: PD-10 remap, PD-22 404 semantics.

**V2 F137 (2026-08-10) — COMPLETE:** Recommendation Preview API — [`docs/v2/F137-RECOMMENDATION-PREVIEW-SPEC.md`](docs/v2/F137-RECOMMENDATION-PREVIEW-SPEC.md), [`F137-BOUNDARY.md`](docs/v2/F137-BOUNDARY.md), [`F137-POLICY-DECISIONS.md`](docs/v2/F137-POLICY-DECISIONS.md), [`F137-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F137-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.I. Verdict: **`F137_COMPLETE_WITH_NON_BLOCKERS`**. **Shipped:** `RecommendationGenerationPipeline::decideForSecurity` + `applyCapitalOutcomes` (shared with generate; preview never persist/cancel/`ensureActive`); `GET /api/v1/analytics/stocks/{stock}/recommendation-preview?strategy_id=`; cycle-fresh persisted via `evaluation_result_id`→run; canonical `BUY`/`SELL`/`HOLD_POSITION`/`WATCH`; `execution` + `research` sections; `available:false` + `unavailable_reasons[]`; Watchlist `WatchlistResearchPanel` uses dedicated route + `/v1/strategy`; help synced. Tests: `F137RecommendationPreviewTest` (11). Residual non-blockers: no FE component test harness; flat field aliases; unrelated full-suite failures (CA UNIQUE / Explorer / RS). Do not pull F143/F144 ahead.

**V2 F143 (2026-08-10) — COMPLETE (formalization only):** In-app Contextual Help — [`docs/v2/F143-CONTEXTUAL-HELP-SPEC.md`](docs/v2/F143-CONTEXTUAL-HELP-SPEC.md), [`F143-BOUNDARY.md`](docs/v2/F143-BOUNDARY.md), [`F143-POLICY-DECISIONS.md`](docs/v2/F143-POLICY-DECISIONS.md), [`F143-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F143-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.J. Verdict: **`F143_COMPLETE_WITH_NON_BLOCKERS`**. Runtime already shipped: `appDocumentation.js` (~44 topics), `HeaderHelpButton` → static `/docs/{keyword}.html`, `generate-static-docs.mjs` on `npm run build`, cursor help-sync rule. **No F143 implementation phase** in this pass (docs-only). Non-blockers: no automated help tests; unused SPA `components/docs/*`; older `implementation.md` SPA prose drift; roadmap “43 topics” / Phase-4 wording. Do not absorb F144; do not reopen closed initiatives.

**V2 F144 (2026-08-10) — COMPLETE (formalization only):** Knowledge Board — [`docs/v2/F144-KNOWLEDGE-BOARD-SPEC.md`](docs/v2/F144-KNOWLEDGE-BOARD-SPEC.md), [`F144-BOUNDARY.md`](docs/v2/F144-BOUNDARY.md), [`F144-POLICY-DECISIONS.md`](docs/v2/F144-POLICY-DECISIONS.md), [`F144-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F144-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.K. Verdict: **`F144_COMPLETE_WITH_NON_BLOCKERS`**. Runtime already shipped: `/knowledge-board` + tags, `/api/knowledge-board/*`, profile-scoped notes/tags/images, editors/export, `KnowledgeBoardTest` + `KnowledgeBoardImageTest`. **No F144 implementation phase** in this pass (docs-only). Non-blockers: note/tag cross-profile AuthZ tests SHOULD; image orphan GC; `API_DOCUMENTATION.md` drift; favorite/unarchive UX. Standalone vs F014/F019/F060/F137/F143. Do not reopen closed initiatives.

**V2 program (2026-08-10) — SD-035 V2 = CLOSED:** All eleven deferred initiatives reconciled and closed — [`docs/v2/V2-FINAL-RECONCILIATION.md`](docs/v2/V2-FINAL-RECONCILIATION.md), indexed in `DOCS.md` §3.L. Verdict: **Option A — V2 CLOSED**. No unfinished SD-035 capability; no remaining Phase 1–4 SD-035 implementation work. Remaining items are maintenance/backlog/non-blocking polish (tests, docs drift, deferred product enhancements such as F014 cash-as-of/export, F005 admin force-logout, F127 extra channels, F144 sharing) — **not** open SD-035 initiatives. Tracking docs updated 2026-08-10 (`V2-ROADMAP`, `V2-DEPENDENCIES`, `V2-PRIORITIZATION`, `DOCS.md`) so historical phase/score tables are labelled planning context. Do not invent new SD-035 IDs; do not start a new V2 initiative from residual polish.

**V2.1 Product Hardening (2026-08-10) — audit started (docs only):** Stabilization phase before V3 covering **entire shipped product** (V1 + closed V2), not only V2 residuals. Authoritative read-only audit: [`docs/v2.1/V2.1-PRODUCT-HARDENING-AUDIT.md`](docs/v2.1/V2.1-PRODUCT-HARDENING-AUDIT.md) (`DOCS.md` §3.M). Full PHPUnit baseline (512M): **679** tests, **676** passed, **2** failed, **1** error, **7** risky — failures concentrated in Explorer/RS/growth analytics. No application/test/schema/frontend changes in the audit pass. Do not invent F145+; defer expansions to V3 / PRODUCT_BACKLOG.

**V2.1 WS-A — Test Baseline Cleanup (2026-08-10) — COMPLETE:** [`docs/v2.1/WS-A-TEST-BASELINE-CLEANUP.md`](docs/v2.1/WS-A-TEST-BASELINE-CLEANUP.md). Fixed 3 failing/error tests (all **test drift**: RS ctor mock, session-normalized OHLCV fixtures for growth/Explorer). Added assertions to 7 risky mock-only tests. **Final baseline:** `php -d memory_limit=512M vendor/bin/phpunit` → **679/679 passed**, 0 failed, 0 errors, 0 risky. No application code changes — tests only.

**V2.1 WS-B — Financial Integrity & AuthZ Audit (2026-08-10) — AUDIT COMPLETE (docs only):** [`docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md`](docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md). Read-only. Create/bulk financial units sound; soft reservations exist. **Defects for review before fix:** transaction **update** ignores cash (WSB-D1); transaction **delete** non-atomic (WSB-D2); soft reserved cash spendable by buys (WSB-D4 policy); executed overwrite edge (WSB-D5). Large cash test gaps. AuthZ via `activePortfolio()` OK. No app/test changes; no V3; no new IDs.

**V2.1 WS-B — Financial Hardening (2026-08-10) — COMPLETE:** See §0 of [`docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md`](docs/v2.1/WS-B-FINANCIAL-AUTHZ-AUDIT.md). **WSB-D1:** `TransactionWriteService::update` reverse+reapply cash with holdings in one DB txn. **WSB-D2:** atomic `delete` (cash reverse + TOS revert + ledger delete + holdings). **WSB-D5:** reject overwrite of executed recommendation by different transaction id (same id idempotent). **WSB-D4 PO:** soft reservation retained — manual/F019 buys use balance; reserved remains approve/withdraw workflow state; regression tests lock soft semantics. **WSB-D3:** manual fill create+complete unified under one DB txn when `recommendation_id` present. Tests: `FinancialIntegrityHardeningTest` + updated `TransactionUpdateTest`. Full suite: **692/692** passed (`512M`). No schema change; F019 create/bulk unchanged; no V3 / no new IDs.

**V2.1 WS-C — Shadow Feature Inventory (2026-08-10) — DISCOVERY COMPLETE (docs only):** [`docs/v2.1/WS-C-SHADOW-FEATURE-INVENTORY.md`](docs/v2.1/WS-C-SHADOW-FEATURE-INVENTORY.md). Read-only classification of shipped surfaces vs V2 packs. ~20 shadow capabilities (Cash, F015 Snapshots, Dashboard, Calendar, Strategy/Notifications/Explorer, etc.). Recommended pack order: Cash → Snapshots → Dashboard → Calendar. No packs authored yet; no code/tests/schema changes; no new F-IDs; no V2 reopen; no V3.

**V2.1 Cash Management retrospective pack (2026-08-10) — DOCS ONLY:** First WS-C formalization. Pack: [`CASH-MANAGEMENT-SPEC.md`](docs/v2.1/CASH-MANAGEMENT-SPEC.md), [`CASH-MANAGEMENT-BOUNDARY.md`](docs/v2.1/CASH-MANAGEMENT-BOUNDARY.md), [`CASH-MANAGEMENT-POLICY-DECISIONS.md`](docs/v2.1/CASH-MANAGEMENT-POLICY-DECISIONS.md), [`CASH-MANAGEMENT-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2.1/CASH-MANAGEMENT-IMPLEMENTATION-GAP-MATRIX.md) (`DOCS.md` §3.M 3.56–3.59). Documents CURRENT post–WS-B cash lifecycle, soft reservation (CM-11), financial-unit invariants, boundaries with TransactionWriteService/F019/F014/reservations. No F-number. No app/test/schema/frontend changes; no V3; no V2 reopen. Open: CM-06 adjust-reason required?; dedicated CashManagementTest (TEST GAP); help soft-buy wording (DOCUMENTATION GAP).

**V2.1 WS-C2 — Portfolio Snapshots (F015) retrospective pack (2026-08-10) — DOCS ONLY:** [`PORTFOLIO-SNAPSHOTS-SPEC.md`](docs/v2.1/PORTFOLIO-SNAPSHOTS-SPEC.md), [`PORTFOLIO-SNAPSHOTS-BOUNDARY.md`](docs/v2.1/PORTFOLIO-SNAPSHOTS-BOUNDARY.md), [`PORTFOLIO-SNAPSHOTS-POLICY-DECISIONS.md`](docs/v2.1/PORTFOLIO-SNAPSHOTS-POLICY-DECISIONS.md), [`PORTFOLIO-SNAPSHOTS-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2.1/PORTFOLIO-SNAPSHOTS-IMPLEMENTATION-GAP-MATRIX.md) (`DOCS.md` §3.M 3.60–3.63). Documents CURRENT rebuildable equity-curve cache (`portfolio_portfolio_snapshots`), post-commit/cron/lazy rebuild, F014 boundary (aggregates vs as-of cross-section), Dashboard consumes growth only (live metrics ≠ snapshot SoT), cash excluded from valuation. No app/test/schema/frontend changes; F014/F015 not reopened; no V3. Open/TD: retention DEFERRED; PHPUnit create/update skip vs delete rebuild; F019 multi-rebuild perf; soft-fail TEST GAP.

**V2.1 WS-C3 — Dashboard retrospective pack (2026-08-10) — DOCS ONLY:** [`DASHBOARD-SPEC.md`](docs/v2.1/DASHBOARD-SPEC.md), [`DASHBOARD-BOUNDARY.md`](docs/v2.1/DASHBOARD-BOUNDARY.md), [`DASHBOARD-POLICY-DECISIONS.md`](docs/v2.1/DASHBOARD-POLICY-DECISIONS.md), [`DASHBOARD-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2.1/DASHBOARD-IMPLEMENTATION-GAP-MATRIX.md) (`DOCS.md` §3.M 3.64–3.67). Documents CURRENT home `/` + `GET /api/dashboard` as presentation/aggregation: live headlines via `PortfolioCalculationService`; growth via F015; cash available via Cash Management; market gauges via Market Analysis Engine; alerts/patterns/calendar as companion APIs. Explicit non-ownership of F014/F137/Explorer formulas. Finding: help/historical notes may still mention on-Dashboard Stocks Above / `market_depth` on dashboard API — **CURRENT** UI/API are link-only to `/market-depth` (no inline heatmap; controller does not attach `market_depth`). No app/test/schema/frontend changes; no V3; F014/F015/F137 not reopened.

**V2.1 WS-C4 — User Calendar retrospective pack (2026-08-10) — DOCS ONLY:** [`CALENDAR-SPEC.md`](docs/v2.1/CALENDAR-SPEC.md), [`CALENDAR-BOUNDARY.md`](docs/v2.1/CALENDAR-BOUNDARY.md), [`CALENDAR-POLICY-DECISIONS.md`](docs/v2.1/CALENDAR-POLICY-DECISIONS.md), [`CALENDAR-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2.1/CALENDAR-IMPLEMENTATION-GAP-MATRIX.md) (`DOCS.md` §3.M 3.68–3.71). Documents CURRENT `/calendar` as persisted portfolio events + recurrence expansion + optional Telegram reminders; admin global `trade_holiday` rows feed `TradingCalendar` (session/quiet days). Explicitly **not** auto-derived from transactions/CA/alerts/recommendations/watchlist. F&O/Options UI presets are helpers, not exchange sync. No app/test/schema/frontend changes; no V3; no V2 reopen. Open/TD: reminder TEST GAP; retention DEFERRED; exchange holiday import OOS.

**V2.1 WS-C5 — Explorer / Analytics retrospective pack (2026-08-10) — DOCS ONLY:** [`EXPLORER-ANALYTICS-SPEC.md`](docs/v2.1/EXPLORER-ANALYTICS-SPEC.md), [`EXPLORER-ANALYTICS-BOUNDARY.md`](docs/v2.1/EXPLORER-ANALYTICS-BOUNDARY.md), [`EXPLORER-ANALYTICS-POLICY-DECISIONS.md`](docs/v2.1/EXPLORER-ANALYTICS-POLICY-DECISIONS.md), [`EXPLORER-ANALYTICS-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2.1/EXPLORER-ANALYTICS-IMPLEMENTATION-GAP-MATRIX.md) (`DOCS.md` §3.M 3.72–3.75). Documents CURRENT Stock Explorer (cache-only `POST /api/analytics/explore`, multi-index benchmark, growth/RS 1/3/6/12) and SD-031 analytics owners (Stock/Portfolio/Market/Evaluation) plus shared RS math. Analytics is not financial SoT; F137 preview remains F137-owned. Finding: help may imply period toggles; CURRENT always runs four periods. No app/test/schema/frontend changes; no V3; F042/F043/F137 not reopened. Open/TD: classic vs v1 API dual path; Indicator Registry / TD-19 deferred.

**V2.1 WS-D — Final Reconciliation (2026-08-10) — V2.1 CLOSED (docs only):** [`V2.1-FINAL-RECONCILIATION.md`](docs/v2.1/V2.1-FINAL-RECONCILIATION.md) (`DOCS.md` §3.M 3.76). Consolidates WS-A/B/C1–C5: suite trust + financial integrity hardening + five priority shadow packs. Records fixed defects (WSB-D1/D2/D3/D5; soft D4 PO), remaining TD/TEST GAP/doc polish, unpaked shadows, and unprioritized V3 candidates. No app/test/schema/frontend changes in WS-D; no V3 started; no V2 reopen.

**UI:** Sidebar **Screeners** with tabs **My screens** | **Shared screens** | **Guide** (`/screeners`, `/screeners?tab=shared`, `/screeners?tab=guide`; `/screeners/new` create, `/screeners/:id` edit). My screens: TanStack DataTable (sort, columns, fit-to-window). Nested AND/OR condition builder; scope `holdings` | `watchlist` | `all_equities` | **`index`** (NSE broad/sector constituents via `IndexConstituentService`; pick `index_symbol` e.g. `NIFTY50`); optional schedule + **Notify me results (only for cron runs)** when schedule is enabled. Create/edit toggle **Share with your other portfolios** (`is_shared`, default off). Shared tab: screeners shared from **your other portfolios** (same account) + **Import** (private copy, schedule off; watchlist scope → holdings; **index scope keeps `index_symbol`**). Guide tab: scopes, operators, lookback, sharing, indicator catalog. Editor has live inline validation while typing (name required + max 120 + allowed chars, description max 500 + allowed chars, watchlist/time checks) and Save/Run stay disabled while invalid. **Number fields** in the condition builder (indicator params e.g. SMA days, constant operands, RHS `weight_factor`) all use shared `NumberInput` with `buttonVariant="secondary"` (grey +/−); other pages leave the default primary (blue) buttons. Run results metrics show operator symbols (`>` / `≥` / …), not ids (`gt`). Missing/deleted watchlist scope shows red field error on edit; list **Last run** column shows counts plus ⚠ warning text from run stats (e.g. deleted watchlist empty set). Editor footer uses **Close**; save toasts include screener name. Run **result symbols** link to `/watchlist/{SYMBOL}`; each hit has **Explorer** → `/explorer?symbol={SYMBOL}&benchmark=NIFTY50` plus configurable **external research links** (new tab) from global setting `external_stock_links` (Chartink, TradingView, Yahoo, Zerodha, Screener.in, StockScans by default; placeholders `{SYMBOL}`, `{EXCHANGE}`, `{YAHOO_SUFFIX}`). No Holdings **Prices** link (hits may be outside holdings). Selected run-history row uses theme elevated highlight (not Bootstrap `bg-light`).

**Tables:** `portfolio_screeners` (`index_symbol` nullable — migration `2026_07_21_000001`), `portfolio_screener_runs`, `portfolio_screener_run_hits` (migrations `2026_07_20_000001`, `2026_07_20_000002` adds `is_shared`). Scoped by `profile_id` (same as watchlists/alert policies — portfolios belong to users; classic API route binding uses `activePortfolio()` so **CRUD** does not resolve foreign profile ids). **F060:** shared list/import/registry/eligibility/Discovery/backtest-pin restrict `is_shared` rows to **same owning user** via profile→user (`sharedVisibleTo` / `ownedOrSameUserShared`). Hard-delete screener cascades runs/hits.

**Engine:** `App\Services\Screener\TechnicalIndicatorService` + `ScreenerEvaluationService` + `ScreenerRunService`. Indicators: close/OHLCV, change_pct, high_n/low_n, **high_52w/low_52w** (rolling high/low over up to 252 sessions; shorter history uses all available bars — min 1), range_pct, sma/ema (+ vs price / spreads), rsi, roc, stoch_k/d, macd/signal/hist, atr, bollinger set, volume_sma/ratio. Period-style params allow **minimum 1** (e.g. SMA/EMA period 1 = latest close). **Condition `weight_factor`** (default `1`, optional float): compares `left` vs `weight_factor * right` (UI: number next to operator + `×` before RHS). **Min sessions** per stock = max lookback derived from your condition params (true math floors only — e.g. SMA = period, RSI/ROC/change_pct = period+1, ATR = period after Wilder seed including bar 0; no fixed floor of 20 or 252 for 52w). Guide tab explains this and labels the catalog column **Min sessions (defaults)**. Insufficient bars → stock skipped (`skipped_insufficient_data`), run still completes. Universe runs chunked (~150 stocks) with `POST /api/screener-runs/{id}/continue`.

**LHS entity selector (Jul 2026):** Each condition's **left operand** can carry `entity` (dropdown in editor above the Indicator/Number toggle; `meta.left_entities`): `stock` (default, omitted from JSON) or an index — `NIFTY50`, `SENSEX`, `NIFTY100`, `NIFTY200`, `NIFTY500`, `NIFTYMIDCAP150`, `NIFTYSMLCAP250` (`ScreenerCatalog::LEFT_ENTITIES`). When set, the LHS indicator is computed on that index's OHLCV (benchmark `Stock` row `is_benchmark=true` + `portfolio_stock_prices`), while the **RHS always evaluates on the scanned stock** and the result set is always stocks (e.g. find stocks whose `range_pct` > Nifty 50's `range_pct`). Validator rejects unknown left entities and any `entity` on the right operand. `ScreenerEvaluationService::evaluateStock(definition, bars, entityBars)` takes a symbol→bars map; skip check uses `stockLookback()` (entity-pinned lefts excluded so a short-history stock isn't skipped for the index's lookback); `entityLookbacks()` reports symbol→min bars. `ScreenerRunService::loadEntityBars()` loads index bars once per chunk (missing/short index history → run warning; those conditions evaluate false, stock not skipped); the stock-major backtest loads index bars once per continue request (full range + lookback) and aligns each as-of date to the last index bar on or before it. Hit metrics include `left_entity`, and `left` label is prefixed (e.g. `Nifty 50 range_pct`). Volume-history skip check ignores entity-pinned lefts (indexes have no volume).

**Guide tab indicator definitions (Jul 2026):** `ScreenerGuideTab.jsx` indicator tables have a **Definition** column — plain-language definitions (frontend map `INDICATOR_DEFINITIONS`, ≤600 chars, with formulas/examples). Shows first 50 chars + `...` with a **More** link that expands to the full text and swaps to **Less** (per-row `IndicatorDefinition` component). Common indicators (SMA/EMA/RSI/ROC/stochastic/MACD/ATR/Bollinger set/close/open/volume/52w) also get an ⓘ Investopedia link (new tab, `INVESTOPEDIA_LINKS` map) that appears only on row hover/focus (`.lido-guide-indicator-table .lido-indicator-info-link` CSS in `lido-app.css`).

**API:** `GET/POST /api/screeners`, `GET /api/screeners/shared`, `POST /api/screeners/shared/{id}/import`, `GET/PUT/DELETE /api/screeners/{id}`, `GET /api/screeners/meta`, `POST /api/screeners/{id}/run`, `GET /api/screeners/{id}/runs`, `GET /api/screeners/{id}/runs/compare` (stock×run presence matrix for stacked UI), `DELETE /api/screeners/{id}/runs` (clear all run history + hits), `POST /api/screeners/{id}/backtest` (body `range` + `session_token`; all scopes), `GET /api/screeners/{id}/backtest/matrix` (persisted per-date matrix, all cached dates), `POST /api/screener-backtests/{id}/continue`, `GET /api/screener-backtests/{id}/matrix`, `DELETE /api/screener-backtests/session/{token}` (job rows only; results persist), `GET /api/screener-runs/{run}`, `POST /api/screener-runs/{run}/continue`.

**Run storage:** Each run is a row in `portfolio_screener_runs` (stats in `stats_json`); matched stocks in `portfolio_screener_run_hits`. All runs are kept in DB until **Clear history** on the editor (`DELETE /api/screeners/{id}/runs`, cascades hits). Editor lists the latest **30** runs (`RUN_HISTORY_UI_LIMIT`); older runs remain stored but hidden from the list. Run ID `#N` in UI is the database primary key, not “Nth run”.

**Stacked run results (Jul 2026):** Editor section under Run history — **not loaded by default**. **Show stacked results** fetches `GET /api/screeners/{id}/runs/compare` on demand (`ScreenerRunService::compareMatrix`: columns = completed runs in the UI window oldest→newest L→R; rows = unique hit symbols sorted by hit-count desc). Cells: green = present in that run, grey = miss; badge = green count; green cells show consecutive streak counters (reset after a miss). Sticky first column + horizontal scroll (`ScreenerRunsCompareTable`). Refresh button reloads after new runs; cleared when history is cleared or page reloads.

**Backtest (Jul 2026, persisted per date, stock-major engine):** Editor footer **Backtest** split button (`ComboButton`) — primary runs the selected window; dropdown picks `1y` / `6m` / `3m` / `1m` / `15d` (**all scopes**: holdings, watchlist, all_equities, index — `ScreenerCatalog::BACKTEST_SCOPES = SCOPES`). Walks weekdays from window start → today (Sat/Sun skipped); each day evaluates with OHLCV `price_date <= as_of`. **Stock-major evaluation (Jul 2026 redesign):** instead of re-fetching and re-computing per day (the old day-major loop caused ~N_stocks×N_days queries and cPanel timeouts on large universes), each `continue` request processes a slice of **stocks** (`BACKTEST_STOCK_CHUNK = 150`): per stock, one raw `DB::select` loads all bars up to the last missing date (`ScreenerBacktestService::loadBarsWithDates`, no Eloquent hydration), `TechnicalIndicatorService::evaluateSeries()` computes each indicator's **full causal series once** (value at index i uses only bars 0..i — includes O(n) monotonic-deque rolling max/min for high_n/52w **and stochastic %K**, running-sum rolling std for Bollinger, null-aware volume SMA; SMA uses a rolling add-new/subtract-oldest sum and EMA/RSI/ATR/MACD update recursively from the previous value. A per-stock **sub-series memo** (`memoSeries`: EMA/SMA/RSI per period, %K, MACD line, BB bands, rolling std/extremes, volume SMA) ensures shared building blocks are computed once per stock no matter how many conditions reference them (e.g. `macd_hist` no longer recomputes the MACD line, `bb_upper`/`bb_lower` share one SMA+std) — ~2× faster on stoch/MACD/Bollinger-heavy screens; the single-value run path shares the same memo), and `ScreenerEvaluationService::evaluateAcrossDates()` answers every as-of date via a forward two-pointer over bar dates (weekends/holidays align to the last bar ≤ date; entity/index bars get their own pointer; per-day skip = valid bars ≤ date < `stockLookback`, volume streak check precomputed). Per-date counters accumulate in `stats_json.day_agg`; hits bulk-insert per stock; **day rows are written only at finalization** (so a crashed job never leaves a date looking complete — stray hits without day rows are deleted on the next job's first chunk). Note: EMA-seeded indicators (ema/rsi/atr/macd) now see the full loaded history rather than the old `lookback+5` clamp, so values are slightly more accurate/stable than the day-major engine's. **Results are persistent and keyed by date only** (time irrelevant — one result per screener per as-of date): `portfolio_screener_backtest_days` (unique `screener_id`+`as_of_date`, counters) + `portfolio_screener_backtest_hits` (re-keyed to `screener_id`+`as_of_date`; migration `2026_07_21_000003` drops/recreates hits — old rows were session-scoped throwaways). At job start, dates already in the day cache are pinned as reused (`stats.days_reused`) and **never recomputed**; only missing dates are evaluated. `portfolio_screener_backtests` remains only as a transient job/progress row (`session_token`); session discard deletes job rows only — results survive. Progress: `stats.stock_cursor`/`stock_total`; `days_done` estimated from the stock cursor for the UI bar. Editor loads persisted matrix on open via `GET /api/screeners/{id}/backtest/matrix` (`matrixForScreener`, all cached dates) and shows it in the Backtest results section; post-backtest fetch uses the same endpoint. **Run write-through:** every **completed run** (cron-scheduled or manual, any scope) also upserts its date into the day cache (`ScreenerRunService::writeBacktestDayCache` — deletes that date's backtest hits, copies run hits, `updateOrCreate` day row with run counters; last completed run of the date wins; failures only log, never fail the run). So a nightly cron screener passively builds its backtest matrix, and a later backtest over that window reuses those dates. Caveat: a run's result equals "as-of that date" only if the day's prices were synced before the run fired. **Invalidation:** `ScreenerService::update` clears saved days+hits when `definition_json`/`scope`/`watchlist_id`/`index_symbol` change (rename/share/schedule edits keep them); **Clear history** (`DELETE /api/screeners/{id}/runs`) also clears them (`backtest_days_cleared` in response); screener delete cascades. `dateKey()` normalizes DB date values (SQLite datetime strings vs MySQL DATE) for matrix keying. Deploy: run `cpanel-migrate.php` for `2026_07_21_000003`.

**Schedule:** `portfolio:run-due-screeners` every minute (`console.php`) vs `schedule_time` / `schedule_days` in `cron_timezone`; Telegram via `TelegramNotificationService` only when `telegram_enabled` **and** run `triggered_by=schedule` (not manual runs).

**Tests:** `tests/Unit/Screener/TechnicalIndicatorServiceTest.php`, `tests/Unit/Screener/IndicatorSeriesParityTest.php` (series↔single-value parity for every catalog indicator, prefix causality for seeded indicators, `evaluateAcrossDates` vs per-day evaluation, entity date alignment), `tests/Unit/Screener/ScreenerBacktestCalendarTest.php`, `tests/Feature/ScreenerTest.php` (includes an all-equities backtest end-to-end).

**Deploy:** run migration via `cpanel-migrate.php` after upload (includes `2026_07_21_000001` `index_symbol` on `portfolio_screeners` and `2026_07_21_000002` screener backtest tables).

**Footer nav (Jul 2026):** fixed overlay — collapsed to 12px reveal strip at bottom; expands on CSS `:hover`; click strip to **pin** (adds static main padding via `html.lido-footer-visible`). No JS mousemove tracking; `html { overflow-y: scroll; scrollbar-gutter: stable }`. Controlled by `FOOTER_NAV_ENABLED` in `App.jsx` (currently **enabled**). Screener **My screens** scrollbar flicker at viewport-edge heights was fixed separately (table overflow + `.screeners-page` min-height) — not caused by the footer.

**Evaluation logging & report (Jul 2026):** `AlertPolicyEvaluationService` logs to app channel category `AlertPolicy` via `PortfolioLoggerService::alertPolicy()` — profile start/finish (info), each holding (debug) with outcome. `POST /api/alert-policies/evaluate` returns `data.details[]` (up to 100 rows): `policy_name`, `stock_symbol`, `outcome` (`generated`, `condition_not_met`, `missing_left`, `missing_right`, `duplicate_active`, `formula_error`, `error`), `left`/`right` numeric operands, `summary` text. Alert policies page shows **Last evaluation** table after **Run policies now**. **Bug fix:** `FormulaEvaluator` used `/` as `preg_match` delimiter while `/` also appeared in the allowed-character class, causing `preg_match(): Unknown modifier '('` on every derived-formula evaluation — fixed with `#` delimiters. **Alert policy form UX:** `ColumnTagEditor` removes one tag occurrence at a time (not all duplicates); **Add column…** picker uses highlighted `.column-tag-picker` style; constant compare uses 2-decimal `NumberInput`; message template always shows column picker. **Alert message formatting:** `AlertMessageRenderer` resolves innermost `[[...]]` / `<<...>>` blocks first (no infinite loop on failure). `[[expr]]` supports math expressions (2-decimal thousands format). `<<expr>>` evaluates math (compact number; commas stripped if nested after `[[ ]]`). Plain `{{column}}` display tags last. Tips under message field in policy form. **Save validation:** `AlertPolicyTemplateValidator` checks delimiter balance, known columns, then dry-runs message (and derived formula when applicable) against the first open holding; API returns `message_template` / `compare_formula` field errors; form highlights invalid fields. **Context details:** optional multiline `context_template` (labeled column picker adds `Label: {{column}}` per line); rendered to `context_json.text` on alerts; dashboard Context column uses `white-space: pre-line`.

### Pending deploy (2026-06-21 — Knowledge Board + Explorer + profile fixes)

**Includes:** **Knowledge Board** — notes/tags CRUD, Tiptap editor, search/filter/sort, manual drag order (`localStorage` per portfolio); sort mode (`portfolio_knowledge_board_sort`), bulk select, clipboard export, tag management page; main nav tab **Knowledge**. Full-width cards with hover overlay toolbar; streamlined filter toolbar. **Explorer** — universe-cache-only analytics (no on-demand provider fetch); analyzes **1M / 3M / 6M / 1Y**; historical price cards, four RS cards, bar chart, and 1-year normalized % gain line chart. **Profile menu light theme:** account name/email visible on hover; profile photos use app-relative `/api/profile/photo` URL. **Hotfix:** `GET /api/knowledge-board/notes?archived=false` no longer 422 (`$request->boolean('archived')`).

**Migration required (first Knowledge Board deploy only):** `2026_07_05_000001` (`portfolio_knowledge_notes`, `portfolio_knowledge_tags`, `portfolio_knowledge_note_tag`). Skip migrate if tables already exist. **Explorer changes need no migration.**

**Build:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-DNP7RVSO.js`**, CSS **`lido-app-BcoBxG1q.css`**).

**Upload (cPanel File Manager or FTP):**

| Local | Server |
|-------|--------|
| `deploy/staging/lidoportfolio/*` | `public_html/lidoportfolio/` (merge — app, routes, migrations, views, bootstrap, composer.json, `public/build/`) |
| `deploy/staging/portfolio/build/` | `public_html/portfolio/build/` (replace entire folder) |
| `deploy/staging/lidoportfolio/public/build/` | `public_html/lidoportfolio/public/build/` (replace entire folder) |
| `deploy/staging/portfolio/cpanel-migrate.php` | `public_html/portfolio/cpanel-migrate.php` |

**Already uploaded Knowledge Board but notes list 422?** Upload only `deploy/staging/lidoportfolio/app/Http/Controllers/Api/KnowledgeBoardNoteController.php` → `public_html/lidoportfolio/app/Http/Controllers/Api/` — no build or migrate.

**Migrate (if not done):** `https://www.lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — confirm `2026_07_05_000001` ran; **delete** `cpanel-migrate.php` after success.

**Smoke:** Hard refresh (Ctrl+Shift+R) → **Explorer** → run analysis → see 3 RS cards (green/red) + 6-bar chart → **Knowledge** tab loads notes (no 422) → Edit/save note with tags → **Profile** menu readable in light theme.

### Pending deploy (2026-06-21 — Knowledge Board v1.0 + UI polish) — superseded by bundle above

### Pending deploy (2026-06-21 — pattern guide + OHLCV scanners + deep links) — superseded by 2026-07-01 bundle above

**Includes:** Patterns nav tab + educational guide with SVG sketches; pattern detection on cached OHLCV (JS + PHP); `GET /api/patterns/scan`; dashboard **Pattern signals (holdings)** table with pattern name → `/patterns#id`; watchlist **Scan my watchlist**; OHLCV chart **Possible patterns on this window**; new candlesticks (harami, piercing line, dark cloud cover); **deep links** `/patterns#hammer` auto-switch chart/candle section, expand card, scroll into view.

**Upload:** `deploy/prepare-upload.ps1` → staging built (JS **`app-BXGjdmr7.js`**, CSS **`lido-app-C3UwQt67.css`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders. **No migration.**

**Smoke:** Hard refresh → **Patterns** tab shows sketches; open `/patterns#hammer` (candle section) and `/patterns#double_top` (chart section); **Dashboard** → click pattern name in signals table; **Watchlist** → Scan my watchlist; holding OHLCV → patterns under chart.

### Pending deploy (2026-06-21 — toast crash fix on universe price sync)

**Fix:** `UniversePriceSyncPage` `showToast(message, variant)` — was passing object and crashing React on batch complete.

**Upload:** replace **both** `build/` folders from `deploy/staging/` (JS **`app-BEYJcvch.js`**). No migration.

**Smoke:** Settings → Universe price sync → Run backfill batch → green toast, no React error overlay.

### Pending deploy (2026-06-21 — UI: footer, bulk CSV, transactions layout)

**Includes:** Stabilized bottom footer (single fixed nav collapsed to 12px reveal strip; pure CSS `:hover` expand — no JS mouse tracking; `overflow-y: scroll` + `scrollbar-gutter: stable`; pin adds static main padding only); transactions form stacked above table; **Bulk (CSV)** import on Transactions page.

**Upload:** `deploy/prepare-upload.ps1` → replace **both** `build/` folders only (JS **`app-BS26khuH.js`**; no migration).

**Smoke:** Transactions → **Bulk (CSV)** → paste sample → Save all; footer appears at page bottom / mouse to lower edge.

### Pending deploy (2026-06-21 — universe sync, CSRF, password reset, htaccess)

**Includes:** NSE universe OHLCV sync (CLI + admin API + `/settings/universe-price-sync`); mobile CSRF fix (`csrf-token` + 419 retry); apex→www `.htaccess`; admin password-reset links + guest `/reset-password/:token`; user-mgmt UX fixes; boot panel / viewport CSS mitigations.

**Migration required:** `2026_07_03_000001` (`portfolio_password_reset_links`).

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-B3HlGFOg.js`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders; upload `portfolio/.htaccess` and root portfolio snippet if not already applied.

**Migrate:** `https://www.lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Login on mobile (use `www` URL); Settings → **Universe price sync** → Sync stock master → Run backfill batch; Settings → **Manage users** → password reset link; guest reset page.

### Pending deploy (2026-07-02 — mobile CSRF login fix) — superseded by 2026-06-21 bundle above

**Fix:** After `/sanctum/csrf-cookie`, always load token from `GET /api/auth/csrf-token` and send `X-CSRF-TOKEN` (stops stale `XSRF-TOKEN` at path `/` on mobile). API auto-retries once on `419` after forced CSRF refresh.

**Upload:** `deploy/staging/` (JS **`app-avmKe_to.js`**). Replace **both** `build/` folders only (no migration).

**On affected device:** Clear site data for `lidoalexion.com` once, then hard refresh and login.

### Pending deploy (2026-07-02 — alert policies polish + remove built-in stoploss alerts)

**Includes:** `context_template` + `alert_definition` on policies; dashboard `alerts` key (was `stoploss_alerts`); built-in `stoploss_triggered` auto-generation removed (`StoplossService` metrics only); `AlertService` for active alert listing.

**Migrations (if not already applied):** `2026_07_01_000001`, `2026_07_01_000002`, `2026_07_02_000001`, `2026_07_02_000002`.

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-C95fw9u-.js`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate:** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Hard refresh → Dashboard alerts load; Settings → **Manage alert policies** → create/edit policy with context template → **Run policies now**; confirm no new `stoploss_triggered` rows from daily sync (policy alerts only).

### Pending deploy (2026-07-01 — alert policies)

**Migration required:** `2026_07_01_000001` + repair `2026_07_01_000002` (adds missing `portfolio_alerts` policy columns if first migration partially applied).

**Upload:** `deploy/staging/` (JS `app-Czrb7gYm.js`). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate:** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Settings → Portfolio → **Manage alert policies** → create policy → **Run policies now** → dashboard shows new alerts with Condition/Action columns.

### Pending deploy (2026-06-30 — portfolio delete + stale-tab recovery) — applied on production

**Migration (if not already applied):** `2026_06_30_000001_add_deleted_at_to_portfolio_profiles`.

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (built — JS `app-BhkLY2PL.js`). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate (first time only):** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** `/portfolios` — no Delete on default or active-in-tab; delete works after switching tab; second tab recovers after delete in another tab (navigation or focus).

### Pending deploy (2026-06-29 — multi-portfolio) — applied on production

**One migration required:** `2026_06_29_000001_create_portfolio_profiles_and_migrate_data` — creates `portfolio_profiles`, migrates `portfolio_user_settings` → `portfolio_profile_settings`, backfills `profile_id` on transactions/holdings/snapshots/alerts, drops `user_id` from those tables. Uses **short index names** (`ppt_prof_stock_date_idx`, etc.) for MySQL 64-char identifier limit; migration is **idempotent** so a failed partial run can be retried safely.

**Also upload:** `bootstrap/app.php` (middleware), `composer.json` (helpers autoload; `bootstrap/app.php` also requires helpers directly).

**Deploy:** `deploy/prepare-upload.ps1` → `deploy/staging/`; run `cpanel-migrate.php?token=Lido`; delete script after success. Hard-refresh browser after upload (portfolio switcher in header).

**Smoke:** login → header shows portfolio dropdown → `/portfolios` create/rename → switch portfolio in two tabs shows different data.

### Pending deploy (2026-06-21 batch) — superseded if 2026-06-29 applied

**One migration required:** `2026_06_21_000001_add_total_fees_to_portfolio_holdings` — adds `portfolio_holdings.total_fees` and recalculates all holdings (fee-exclusive avg buy/invested).

**Deploy checklist:** `deploy/RELEASE-2026-06-21.md` (build, upload paths, `cpanel-migrate.php`, smoke tests).

**No** new env vars, routes files, or `composer.json` changes in the 2026-06-21 batch alone.

### Related docs

| Doc                             | Purpose                                                                 |
| ------------------------------- | ----------------------------------------------------------------------- |
| `README.md`                     | Short quick start                                                       |
| `deploy/DEPLOY.md`              | Production deploy (GoDaddy `/portfolio`)                                |
| `deploy/RELEASE-2026-06-21.md`  | Pending release: migration `total_fees`, holdings/transactions UI batch |
| `DEPLOYMENT_CPANEL.md`          | Generic cPanel pointer → `deploy/DEPLOY.md`                             |
| `DEPLOYMENT_VALIDATION_PLAN.md` | Pre/post deploy checks                                                  |
| `app/API_DOCUMENTATION.md`      | REST API                                                                |

## Technical Decisions

- Local environment templates aligned to MySQL-based setup.
- Added `.env` template specifically for MySQL usage in `app/.env.mysql.template`.
- **DB credentials:** `config/load_db_config.php` finds `config/DBConfig.php` by walking up directories (outermost first so `/home/USER/config/DBConfig.php` wins over `lidoportfolio/config/DBConfig.php` if a dev template was uploaded). Supports **class `DBConfig`** or **define()** constants. Optional `DB_CONFIG_PATH` in `.env`. When `DBConfig.php` is loaded, its values **take precedence over** `.env` `DB_*`. Delete `bootstrap/cache/config.php` if DB still shows `root` after fixes. `deploy/cpanel-diagnose.php` flags app-local `DBConfig.php` and cached config.
- **GoDaddy migrate 1142:** shared MySQL user may lack `INDEX` on existing tables. `2026_05_29_000001_extend_portfolio_stocks_master` adds columns always; composite unique on `(symbol, exchange)` is skipped when error 1142 (keeps `symbol` unique). See `deploy/FIX-MYSQL-INDEX-PRIVILEGES.md`.
- To share a single MySQL database with an existing project, this app uses isolated table names:
  - `portfolio_users`, `portfolio_stocks`, `portfolio_transactions`, `portfolio_holdings`
  - `portfolio_stock_prices`, `portfolio_stock_metrics`, `portfolio_portfolio_snapshots`
  - `portfolio_alerts`, `portfolio_settings`, `portfolio_profile_settings`, `portfolio_profiles`, `portfolio_system_logs`
  - queue tables: `portfolio_jobs`, `portfolio_job_batches`, `portfolio_failed_jobs`
- Added Sanctum migration manually from vendor due `finfo` extension issue with `vendor:publish`.
- Added `Schema::defaultStringLength(191)` for MySQL index compatibility in current environment.
- Added frontend toast event bus (`portfolio-toast`) and confirmation dialog for transaction delete UX polish.
- Added lightweight bar visualization for portfolio growth in dashboard without introducing heavy chart dependencies.
- Added deeper automated tests around provider fallback and scheduler failure path.
- **Logging architecture (May 2026):** file-based Monolog daily channels, `PortfolioLoggerService`, `X-Request-ID` correlation, frontend `logger.js` + Error Boundary, `POST /api/logs/frontend`. See **Logging & Debugging Architecture** below.
- **Stock validation & master (May 2026):** local-first validation, `portfolio_stocks` extended fields, `stocks:sync` weekly job, `StockValidationService` / `StockMasterSyncService` / `ProviderResolverService`, autocomplete UI. See **Stock Validation Architecture** below.
- **Session authentication (May 2026):** Sanctum SPA cookies (no JWT/localStorage tokens), Remember Me, multi-device sessions UI, `AuthProvider`. See **Authentication Architecture** below.
- Added Recharts-based interactive line chart for portfolio growth trends on dashboard.
- Enabled `sqlite3` + `pdo_sqlite` extensions in active PHP runtime and removed stoploss test skip guard; DB-bound stoploss persistence test now executes.
- Scheduler now resolves `cron_time` and `cron_timezone` from `portfolio_settings` with safe env fallback.
- **Telegram notifications** are separate from **data syncing time** (`cron_time`). Per-profile `notification_schedules` (JSON in `portfolio_profile_settings`) drive `portfolio:send-notifications` — Laravel scheduler registers the **union** of all profiles' times; each run sends only to profiles whose schedule includes that slot. `AlertNotificationService` sends **that profile's** active alerts to **that profile's** Telegram credentials; when global **ping when clear** is enabled, empty portfolios still get a “no active alerts” confirmation at schedule time (testing). **Quiet days (Aug 2026):** scheduled trade-alert digests (and clear pings) are **skipped on weekends and admin-defined trade holidays** via `TradingCalendar::isEquitySessionDate` in cron timezone — markets closed. Settings **Test telegram** is **not** gated so integration can still be verified. Stoploss triggers only **persist** alerts (no immediate Telegram). Settings UI: add/remove notification times; **Test telegram integration** sends **only the active profile's** alerts.
- **Per-profile settings (Jun 2026):** `portfolio_profile_settings` (`profile_id` + `setting_key`) stores per portfolio: `telegram_bot_token`, `telegram_chat_id`, `notifications_enabled`, `default_stoploss_percent`, `notification_schedules`, **`indiavix_alert_enabled`** (default `true`), **`indiavix_alert_threshold`** (default `20`). Replaces `portfolio_user_settings`. `GET/PUT /api/settings` merges global app settings (cron, fees, API keys, log level) with the **active profile's** personal settings. **Trailing stop %** in holdings/alerts uses the active profile's `default_stoploss_percent`.
- **Multi-portfolio architecture (Jun 2026):** `portfolio_profiles` (`user_id`, `name`, `is_default`). Portfolio data tables use `profile_id`: `portfolio_transactions`, `portfolio_holdings`, `portfolio_portfolio_snapshots`, `portfolio_alerts`. Migration `2026_06_29_000001` creates profiles (one default per user), migrates settings and backfills `profile_id`, drops `user_id` from portfolio tables. **`ResolveActivePortfolio` middleware** resolves active profile from **`X-Profile-Id`** header (alias `X-Portfolio-Id`) or `portfolio_id` query param, else user's default; **`activePortfolio()`** helper (`app/Support/helpers.php`). **`PortfolioProfileService`:** `createDefaultForUser`, `setDefault`, `listForUser`, `defaultForUser`. **`GET/POST/PUT/DELETE /api/portfolios`**, `POST /api/portfolios/{id}/set-default`. `GET /api/auth/me` includes `default_portfolio_id`. **Frontend:** `PortfolioProvider` + `sessionStorage` key `portfolio_active_id`; `api.js` sends `X-Profile-Id`; **`/portfolios`** management page; data pages listen for `portfolio-changed`.
- **Multi-portfolio UI (Jun/Jul 2026):** Header switcher shown only when user has 2+ portfolios (10px left margin); profile menu links to `/portfolios`. Settings removed from top tabs (footer nav only). Settings page scope tabs are route-backed: **Global** (`/settings/global`, admin), **Portfolio** (`/settings/portfolio`), **Account** (`/settings/account`); `/settings` redirects to `/settings/portfolio`. Buttons/links now return to the matching scope route (e.g. sync/admin pages back to `/settings/global`, alert policies back to `/settings/portfolio`, users back to `/settings/account`). Admin-only settings text uses a distinct accent color for quick visual separation. Portfolio names: letters, numbers, spaces, hyphen, underscore only (client + API validation). **Manage Portfolios** (`/portfolios`) uses theme-aware `.contentPane .card` / `.list-group-item` (no hardcoded `bg-dark`); **Set default** uses `btn-outline-primary` so label is visible in light and dark themes. **Delete portfolio** (`DELETE /api/portfolios/{id}`): confirm dialog in UI; portfolio row soft-deleted (`deleted_at` migration `2026_06_30_000001`); related profile data (transactions, holdings, snapshots, alerts, profile settings) **hard-deleted** in the same transaction — **no restore** in app (soft delete is audit-only on `portfolio_profiles`). Reusing a deleted portfolio **name** creates a new profile id with empty data. **Default** and **active-in-tab** portfolios cannot be deleted (UI + API when `X-Profile-Id` matches). **Stale tab recovery:** `GET/POST /portfolios` omit `X-Profile-Id`; on `404 Portfolio not found` for data APIs, `portfolioRecovery.js` re-bootstraps active portfolio and retries once; `BroadcastChannel` + `visibilitychange` refresh portfolio list when another tab deletes a portfolio.
- **Admin roles (Jun 2026):** `portfolio_users.is_admin` (boolean, default `false`). Migration `2026_06_27_000001` sets `is_admin = true` for all accounts that already exist at migrate time. `GET /api/auth/me` includes `is_admin`. Admins only: user management, invites, global settings write, stock master `POST/PUT`, daily sync, backfill, sync logs. Settings → **Manage users** (`/settings/users`).
- **Invite-only registration (Jun 2026):** Open `POST /api/auth/register` removed. Admins create invites (`portfolio_user_invites`, 72h expiry) via `POST /api/invites`; UI provides **Copy link** and **Copy message** (full email text). Guest routes: `GET /api/invites/{token}`, `POST /api/invites/accept` → `/invite/:token` SPA page sets password and signs in. Expired tokens deleted on access with contact-admin message. Login with pending invite returns `invite_setup_required` + `invite_token` → redirect to invite page. Admin can regenerate (new token/expiry) or revoke (delete).
- **Admin password reset links (Jul 2026):** For forgotten passwords on existing accounts. `portfolio_password_reset_links` (`user_id`, 72h token, `used_at`). Admin: Settings → **Manage users** → **Password reset links** card (user picker + table) or **Reset password** per user row; copy link/message, regenerate, revoke (same UX as invites). Guest: `GET /api/reset-password/{token}`, `POST /api/reset-password/accept` → `/reset-password/:token` SPA (new password only, no current password); signs in on success. One pending link per user.
- **Admin-only application settings:** Global keys in `portfolio_settings` (cron, fees, NSE retry, Alpha Vantage key, backend log level, sync log retention) — read/write via `GET/PUT /api/settings` for admins only. **Settings → Global → Application settings:** **Alpha Vantage API key** (password field; stored as `alpha_vantage_api_key`; optional third OHLCV fallback after NSE/Yahoo). Non-admins get per-user settings + read-only `cron_timezone`. **Settings → Global (Jul 2026):** admins can edit **`cron_timezone`** (scheduler timezone; default `Asia/Kolkata`) alongside **Data syncing time** — controls daily sync, notification schedule slots, and universe maintenance window (19:00–23:45). `daily_market_sync` omitted from dashboard API for non-admins. `Alert` route binding scoped to owner. Stock catalog `POST/PUT /api/stocks` admin-only (`POST /stocks/validate` persist still allowed for transaction flow).
- Improved provider resilience with per-provider retries, backoff, and structured attempt-level failure logging.
- Dashboard UI expanded with top gainer/loser, **Alerts** card (full width; when empty, card body shows “No active alerts” only — no table headers), **Relative Strength** and **Allocation** tables side by side (`col-lg-6` each) on wide viewports and stacked full width on narrow, relative-strength trend widgets (vs **NIFTY50** benchmark: stock period return % − index return %; cached in `portfolio_stock_metrics`). Relative Strength table: **Avg. strength** = mean of available 1M/3M/6M values (whole %); default sort descending on that column. Dashboard **Alerts** table: **Context** column shows `context_json` label/value pairs from policy alerts; **Acknowledge** is a separate last column (always visible, not hideable); message column is text only.
- **Alert expiration** (`portfolio_alerts.profile_id`, `expired_at`, `expiration_reason`): alerts are **per profile** (not global per stock). Policy alerts dedupe on `instance_key`; legacy `stoploss_triggered` rows may still exist in DB but are no longer auto-created. `portfolio_stock_metrics` / holdings `stoploss_summary` still track since-buy peak and trailing stop for display and policy conditions (`latest_close` vs `trailing_stop_price` via `HoldingPresentationService`). `firstBuyDateForCurrentPosition` resets after a full exit. Dashboard alerts table shows **Date** (`created_at`). `GET /api/alerts` / dashboard `alerts` filter by active `profile_id`. Expiration: manual clear all + acknowledge (own alerts only); hourly 100h max age; new trading day after daily sync; **full sell** expires only that profile's alerts (`expireForProfileStockIfUnheld`). Active = `expired_at` IS NULL.
- Dashboard summary cards (Portfolio Value, Invested, Gain/Loss, XIRR, combined **Top gainer/loser**, **Cash available**): responsive grid — **3** per row (`col-lg-4`) on wide viewports, **2** (`col-md-6`) on medium, **1** (`col-12`) on narrow. **Top gainer/loser** is one card: gainer symbol+% left-aligned, loser symbol+% right-aligned on the same row; icon-only period toggle (All time / Latest day) in `localStorage` `portfolio_dashboard_top_mover_period`. **All time** ranks holdings by unrealized gain % since purchase; **Latest day** ranks by OHLCV day-over-day %. API: `GET /api/dashboard` → `top_movers.all_time|latest_day.gainer|loser`. **Cash available** shows `available_investable_cash` via `formatInrWhole` plus `( cash% )` where % = `cash_available / (cash_available + portfolio_value) × 100` (1 dp); value is `text-success` when % is **7.1–15** inclusive, else default theme text. Allocation **Market Value**, growth-chart axis/tooltips use `formatInrWhole` / `formatInrCompactWhole` (no paise; `₹ ` + amount, lakh grouping). **Portfolio Growth** line chart (portfolio vs invested value); **Unrealized P/L** line chart directly below (`portfolio_value − invested_value` per snapshot, zero baseline). Holdings/Explorer use `formatInr` (2 dp) via `formatTableMoney2`.
- Dashboard **Sync prices for today** → `POST /api/sync/daily` (`force: true` from UI when re-syncing same day). Skips without `force` if already synced today (cron-safe). Button stays enabled as **Sync again today** after first success; shows muted “Synced for …” hint.
- **Dashboard client cache (Jul 2026):** `GET /dashboard` + `GET /patterns/scan` responses cached in `localStorage` per user + active portfolio (`utils/dashboardCache.js`, key `portfolio_dashboard_cache_v1_{userId}_{profileId}`). **24h TTL**; unused `nifty_comparison.prices` stripped before store. On revisit within TTL, dashboard renders instantly with no API calls. **Refresh dashboard** button (left toolbar) clears cache and refetches; **Last refreshed {time}** label sits to its right when serving from cache. Cache invalidated on logout (`clearAllDashboardCaches`), transaction mutations (`notifyPortfolioDashboardRefresh`), and portfolio switch (`notifyPortfolioChanged`). Dashboard mutations (acknowledge/clear alerts, sync, rebuild) clear cache and refetch.
- **Production deploy:** Canonical steps in **`deploy/DEPLOY.md`** (first deploy + code updates), including **§2.1 Build folders explained** (one PC build, two server copies, what the browser loads). **Agent workflow (Jul 2026):** `.cursor/skills/deploy-cpanel/SKILL.md` — run `prepare-deploy.ps1` from repo root; it builds via `prepare-upload.ps1`, maps `git diff` to cPanel paths, and writes `deploy/deploy-table.md` + `deploy/deploy-manifest.json` (gitignored). Present the generated table to the user; do not rebuild paths manually. Use `-SinceCommit <ref>` or `-SkipBuild` when needed; `-Debug` writes `deploy/deploy-trace.log`. **Script pitfall:** PowerShell parse breaks on Unicode em dash inside double-quoted strings — use ASCII `-` only in `.ps1` files.
- **Production subdirectory** (`https://lidoalexion.com/portfolio`): `APP_URL` includes path; build with `VITE_APP_BASE=/portfolio/build/`; upload `public/build/` to **`lidoportfolio/public/build/`** and **`portfolio/build/`**. Vite tags use **root-relative** paths (`AppServiceProvider::createAssetPathsUsing`) so `www` and apex both work. Delete `public/hot` on server. Troubleshooting: `deploy/DEPLOY.md` §7, `implementation.md` → Production learnings.
- Dashboard cards: **Portfolio Value** / **Total Gain/Loss** green when portfolio &gt; invested, red when less, default text when equal; **XIRR** green/red by sign (`text-success` / `text-danger` → theme tokens `--lido-text-success` / `--lido-text-danger`). Allocation table: **Market %** (holding market value ÷ portfolio value) and **Invested %** (holding invested ÷ total invested); whole numbers; &gt;15% orange (`text-allocation-elevated`), &gt;20% red. **Allocation** card: **Table** / **Visual** toggle (preferences `portfolio_dashboard_allocation_view`, `portfolio_dashboard_allocation_mobile_metric` in `localStorage` via `dashboardPrefs.js`; restored on load); visual mode shows donut charts for market value and invested (side by side on `lg+`, single chart on narrow with invested/market switcher); table mode unchanged with column menu.
- **Dashboard Portfolio section (2026-07-27):** Summary cards (Portfolio Value, Invested, Total Gain/Loss, XIRR) live under a single **Portfolio** heading (former “Portfolio analytics” title + Watchlist subheader removed). All Portfolio metric cards use the same grid as summary cards (**3** per row on `lg+`: `col-lg-4`). **Total Gain/Loss** shows amount plus return % in braces when invested &gt; 0, e.g. `₹ 3,32,200 ( +19.3% )` where % = `total_gain_loss / invested_value × 100` (1 decimal, signed). Available Cash / Cash reserved / Cash utilisation cards are not on Dashboard — cash balance, **Reserved cash** (with reservation details), and Available cash live on the **Cash** tab (`/cash`). **Positions** count is green when 6–20 inclusive, else red; if any holding’s market allocation &gt;15%, shows orange underline-free `(Oversized: N)` link that smooth-scrolls to `#dashboard-allocation` (hidden when N=0). **Largest position %** card removed. **Diversification** uses reusable `PercentGradientBar` (`components/PercentGradientBar.jsx`): 10px red→green bar (theme `--lido-text-danger` → `--lido-text-success`), no numeric label on the card — score appears on hover over the triangle pointer; equilateral downward triangle at **1.5× bar height**, tip 3px inside bar top; marker fill is solid white (dark) / black (light) with **2px** opposite stroke (`--lido-percent-marker-fill` / `--lido-percent-marker-stroke`). Score = `max(0, 100 − HHI×100)` where HHI = Σ(allocation_pct/100)². **Avg Relative Strength (3-month Nifty50)** = mean of holdings’ 3M RS (`stock_return% − NIFTY50_return%`; percentage points; can be negative). **Market analytics** is now executive-summary first: always-visible **Market Health** card (reuses `PercentGradientBar`; score from existing `sentiment.score` / optional dedicated health keys; decision zone / exposure from `new_entry_allowed` + `allocation_multiplier` when present) + compact status/decision-zone/exposure rows + contributor chips, followed by a separator with circular chevron toggle that shows/hides diagnostics via React conditional render (session-persisted; default collapsed; no “Analytics gauges” heading). Existing gauges move into the diagnostics panel and keep all previous data/hover behavior. **Sentiment** / **Volatility** / **Risk** / **Market phase** are mirrored with `HalfDonutShell` `invertScale` so all gauges read red→green left→right; needle/labels stay aligned with the underlying zone semantics. **Trend** / **Momentum** / **Market regime** / **Market breadth** continue red→green via `BULLISH_GRADIENT_STOPS`. **Market breadth** title remains linked to `/market-depth`, and diagnostics also includes a visible “View Market Depth →” link so navigation is preserved. **Market depth / Stocks Above (2026-07-28):** full-width heatmap under the gauges (`MarketDepthTable.jsx`). Rows = configured indexes (default Nifty 50 / 500 / Bank / Financial Services / Midcap 50); columns = **RS 55 &gt; 0**, **SMA 20/50/100/200**. Cell = % of constituents with enough history that meet the criterion; background orange→yellow→green by %. Backend: `MarketDepthService` (`config/portfolio.php` → `market_depth`); RS 55 = 55-session return vs primary benchmark &gt; 0; SMA = last close &gt; SMA(n). Cached in `portfolio_settings` (`market_depth_json` / `market_depth_as_of`) + Laravel cache; refreshed after successful daily sync; stale when benchmark has a newer price date. Exposed on `GET /api/dashboard` as `market_depth`. **Dashboard is cache-only** (`forDashboard()`): never runs the Nifty-500 scan on the request path (inline compute caused OOM/timeout 500s). Warm via daily sync or **`deploy/cpanel-refresh-market-depth. Dedicated page **`/market-depth`** (nav **Market Depth**): bare **Market Breadth** table (not card-wrapped); columns Rising, RS 55 > 0, Above SMA 20/50/100/200; toggles for **% / Count**, **NSE / BSE**, and **as-of date** (dropdown of available snapshot dates). NSE scope = NSE + NSE+ constituents; BSE scope = BSE-only + dual-listed NSE+. All constituent-capable NSE indexes (`market_depth.indexes` null/`*`). Rising = last close > prior close. History: `portfolio_market_depth_snapshots` (max 7 as-of dates × nse/bse); Nifty 50 7-day line chart above the table. **UI (2026-07-28):** reachable only via Dashboard **Market breadth** gauge title link (not a main-nav tab); no as-of label on the page; NSE/BSE toggle filters **index rows** by catalog exchange; `MarketDepthBackfillService` / `cpanel-backfill-market-depth.php` backfills up to 7 trading-day snapshots. API: `GET /api/market-depth?date=&exchange=nse|bse`. Dashboard card title **Market depth** links to the page. Dark-mode heatmap uses darker HSL cells. Refresh: daily sync or `cpanel-refresh-market-depth.php`.php`** (`?token=…`; delete after use).
- Transactions UI now supports edit/update flow in addition to create/delete. **Fix (Jun 2026):** update/delete auth compared `user_id` with strict `!==`, so string vs int IDs from MySQL caused false 403; `Transaction` route binding now scopes to `activePortfolio()->transactions()` (SQL ownership check). FE `api.js` maps generic auth errors to a full sentence. **Delete buy guard:** before deleting a buy, `HoldingsCalculationService::assertReplayValidAfterDeleting()` dry-runs the ledger replay; if orphan sells would break recalc, API returns 422 with guidance to delete sell transaction(s) first. **Squared-off sells (Jul 2026):** `portfolio_transactions.realized_pl` and `squared_off_fees` store FIFO realized P/L (price spread only, no fees in P/L) and proportional squared-off fees (sell fees + matched buy fees by quantity) on **sell** rows; recomputed on create/update/delete via `TransactionRealizationService`. Backfill: `php artisan portfolio:backfill-sell-realizations` (`--profile=` optional) or **`deploy/cpanel-backfill-sell-realizations.php`** (no SSH; delete after use). Squared-off table (`/transactions/closed`) shows **Realized P/L** and **Fees** columns for sells.
- Holdings UI shows highest close since buy, trailing stop, unrealized P/L, and links to OHLCV price history screen. `GET /holdings` enriches each row with `unrealized_profit`, `unrealized_gain_percent`, and `stoploss_summary.daily_change_percent` (latest vs preceding OHLCV close).
- `GET /stocks/{stock}/prices` and force sync via `POST /sync/backfill/{stock}`; buy transactions trigger synchronous backfill.
- Price providers use `App\Support\ExternalHttp` with `CURL_CAFILE` / `config/portfolio.php` CA bundle for SSL.
- Sync failures return 422 with provider error details; UI shows informative messages (not silent "0 rows").
- Transactions can create stocks inline: POST `/api/transactions` accepts `symbol` (+ optional `name`, `exchange`) instead of requiring a prior Stocks screen entry (`StockResolverService`).
- Transactions UI uses symbol autocomplete; new symbols auto-create master row on first buy.
- Transactions table: **Qty** via `formatTableInteger`; **Price** via `formatTableMoney2` (`₹ ` + `en-IN` grouping, 2 dp).
- Stocks SPA tab removed; `GET /api/stocks` still used by Transactions autocomplete. Stock CRUD APIs retained for future UI.
- Holdings table shows **Latest Close** (from most recent OHLCV row since buy date, with metrics fallback).
- Holdings **Avg Buy** and **Invested** exclude transaction fees (price × qty only). **`total_fees`** on `portfolio_holdings` sums buy + sell fees for the current open position; **Fees** column shows absolute amount and `% of invested` (1 dp). Recalc via `HoldingsCalculationService` on each `GET /holdings`.
- Holdings **XIRR** from backend (`XirrService` via `GET /holdings`); per-holding terminal value uses `qty × latest_close` where **latest_close** is the same figure shown in the holdings row (since-buy OHLCV, else metrics fallback — not a separate global history lookup). Dashboard portfolio XIRR uses the same terminal as displayed **Portfolio Value**. Portfolio XIRR includes all historical buy/sell transactions (closed positions too), so it only equals a single holding’s XIRR when that stock is the only one ever traded.
- Tabular UIs: **TanStack Table** via `DataTableCard` / `DataTable.jsx` (columns icon in card header; show/hide + reorder panel; **drag column edges to resize** — widths persisted in `localStorage` with order/visibility via `tableColumnPrefs.js`, reset via **Reset columns**). **Fit columns** toolbar button (expand icon beside columns menu) redistributes visible column widths proportionally to fill the table container (`distributeColumnWidths` in `tableColumnPrefs.js`, target = container width − 2px to avoid border overflow scroll) — removes empty right-edge gap or horizontal scrollbar until the user resizes again. On load, if saved widths still overflow the container, columns auto-fit once. Rendered table width is capped to container − 2px to prevent horizontal scroll toggling that can oscillate page height at the bottom. Pass `loading` while fetching — shows a spinner row instead of the empty-state message until data arrives (`TableLoadingRow`). Used on holdings, transactions, closed transactions, dashboard tables (alerts, relative strength, allocation, pattern signals), watchlist scan results, stock prices OHLCV, and corporate-action preview. Plain HTML tables on Settings and sync logs are not resizable.
- **Stocks calendar (Jul 2026):** per **active portfolio** market-event calendar for F&amp;O expiry, options expiry, and custom recurring dates. Main nav **Calendar** → `/calendar` (`CalendarPage.jsx`). **Recurrence types:** one-time, daily, weekly (day of week), monthly (day of month), monthly (nth weekday e.g. last Thursday), yearly (fixed date), yearly (nth weekday of month). Each event has configurable **color** (used on calendar day markers). **Calendar UI:** all months of the current year in a responsive grid; if current month is Oct–Dec, also shows Jan–Mar of next year. Days with events show a circular marker — single color fill or **conic-gradient pie** when multiple distinct event colors fall on the same date. Hover shows event titles; click opens a dialog listing that day's events (with edit). **Dashboard:** **Upcoming calendar events** card lists next 31 days with date + “Today” / “Tomorrow” / “N days ahead”. **Telegram reminders:** optional per event (`reminder_enabled` + `reminder_days_before` array — `0` = on the day, `N` = N days prior); uses existing per-portfolio Telegram creds (`notifications_enabled`, `telegram_bot_token`, `telegram_chat_id`). Scheduler: `portfolio:send-calendar-reminders` daily at 07:00 (`Asia/Kolkata` / cron timezone). Dedup via `portfolio_calendar_reminder_sends`. API: `GET/POST /api/calendar/events`, `PUT/DELETE /api/calendar/events/{id}`, `GET /api/calendar/occurrences?from=&to=`, `GET /api/calendar/upcoming`. Migration: `2026_07_11_000001_create_portfolio_calendar_events_table.php`. Backend: `CalendarRecurrenceService`, `CalendarEventService`, `CalendarReminderService`, `CalendarEventController`.
- **Trade holidays (Aug 2026):** builtin global category `trade_holiday` on `portfolio_calendar_events` (`profile_id` nullable, migration `2026_08_04_000001`). **Admins** create/edit/delete via the calendar form checkbox “Trade holiday (global…)”; fixed amber color `#b45309`; shown on **every** portfolio’s list/occurrences/upcoming. Non-admins see holidays (View) but cannot mutate. `TradingCalendar::isTradeHoliday` / `isEquitySessionDate` treat these dates as non-session days (along with weekends). Scheduled **trade-alert Telegram** digests skip those days. Ops/VIX/screener/calendar-reminder Telegram paths are unchanged.
- Transaction form: labeled fields; **NSE+ × BSE** toggle used **only for fee calculation** (stock autocomplete searches NSE+BSE and is not filtered by this toggle; changing the toggle does not clear a validated stock); integer qty (empty by default, like price); price step **0.05** (2 dp); **Fees** auto-calculated (read-only) from Settings fee components — hover **ⓘ** for line-item breakdown; symbol validate → local `/stocks/search` across both exchanges, then `POST /api/stocks/validate` with `check_only: true` trying NSE/BSE as needed (local + in-memory cache); Save disabled until valid + symbol validated; while save API runs, submit shows **Adding…** / **Updating…** and stays disabled. After **add**, form retains stock/symbol/qty/type/date/notes; only **price** clears (blocks accidental duplicate submit). After **update**, form resets. **Bulk import (CSV):** toggle **Single** / **Bulk (CSV)** on Transactions page — paste `Stock,Quantity,Average Price,Transaction Type` rows, review editable table (exchange NSE/BSE, Buy/Sell, per-row date defaulting to today, auto fees), **Save all** commits via `POST /api/transactions/bulk` (**all-or-nothing**; batch UUID + row UUIDs; failed batch leaves zero rows and is retryable; completed batch is not re-submittable). Parser: `utils/bulkTransactionCsv.js`; UI: `BulkTransactionImport.jsx`. Shared create path (`TransactionWriteService`) applies ledger + holdings/realizations + cash atomically (PD-F019-14). **Transaction date** (`TransactionDateInput.jsx`): text `dd-mmm-yyyy` + picker button with calendar icon (outline + 3×3 date dots). DB/API field: `fees` (renamed from `brokerage`, Jun 2026). FE is canonical calculator (`resources/js/src/utils/feeCalculator.js`); API trusts client `fees` on save. One-time migration recalculated historical rows using PHP mirror (`FeeCalculatorService`).
- **Transaction history** table (**Active transactions**): `GET /transactions?scope=open` — only transactions for stocks with holding `quantity > 0` (recalculates holdings first). Client search in header. Link **Squared-off** → `/transactions/closed`. Columns include **Notes** (truncated with full text on hover; `—` when empty). **Squared-off** page: `scope=closed`, server search + pagination (25/page), edit navigates to main form, delete in place. **Layout:** add/edit form stacked above the active transactions table at all breakpoints.
- **Corporate actions (Jul 2026):** guided stock **split** and **bonus issue** workflow for the active portfolio. Routes: `/corporate-action` (`CorporateActionPage.jsx`); entry from Holdings row **Split/Bonus** and Transactions **Corporate action** link (prefills stock when selected). API: `POST /api/corporate-actions/preview`, `POST /api/corporate-actions`, `GET /api/corporate-actions?stock_id=`. Migration: `2026_07_10_000001_create_portfolio_corporate_actions_table.php` (`portfolio_corporate_actions`, `portfolio_transactions.corporate_action_id`). **Split:** proportional restatement — every transaction in scope gets `qty × (ratio_to/ratio_from)`, `price ÷ factor` (default scope: all ledger rows; optional `split_scope=before_ex_date`). **Bonus (Indian tax style):** existing buys/sells unchanged; one new **buy at ₹0** on ex-date for `eligible_qty × (ratio_to/ratio_from)` where `eligible_qty` = shares held on record date (inclusive replay). Preview shows per-transaction adjustments (split) or proposed bonus buy + warnings. Apply triggers holdings recalc, FIFO `TransactionRealizationService` (loads transactions after clearing stale sell columns), portfolio snapshot rebuild, **local OHLCV restatement** for rows before ex-date (`CorporateActionPriceAdjustmentService` — split divides price by factor and multiplies volume; bonus divides price by `1 + factor` and multiplies volume), and `MetricsUpdateService` refresh (highest close, trailing stop, relative strength). Holdings **highest close since buy** / **trailing stop** recompute from restated `portfolio_stock_prices` on next `GET /holdings`. **Production repair:** `CorporateActionPriceRepairService`; local dev Artisan `portfolio:repair-corporate-action-prices`. **Production (no SSH):** `deploy/cpanel-repair-corporate-action-prices.php` — scan `?token=TOKEN`, apply `&apply=1`; optional `&profile=`, `&stock=`, `&action=`, `&force=1`; delete after success (see `.cursor/rules/No-SSH-use-cpanel-web-scripts.mdc`). Bonus buys allow `price = 0` when `corporate_action_id` is set.
- **Transaction fee settings (Jun 2026):** Settings → **Transaction fees** card (collapsible, **collapsed by default**) — configurable lines: label, **Type** (% / fixed ₹), rate, **Buy/Sell** tap toggles, **NSE/BSE/Both** exchange filter, per-line **GST %** (single row per component; theme-aware `lido-fee-component-row`; compact `NumberInput` height). Defaults match Zerodha equity delivery (brokerage 0%, STT 0.1%, NSE/BSE txn charges, SEBI 0.0001% [= ₹10/crore], stamp 0.015% buy-only). Stored as JSON in `portfolio_settings.fee_components`.
- **External stock links (Jul 2026):** Settings → **Global** → **External stock links** (admin, collapsible) — label + URL template + enabled flag. Defaults: Chartink, TradingView, Yahoo Finance, Zerodha, Screener.in, StockScans. Placeholders `{SYMBOL}`, `{EXCHANGE}`, `{YAHOO_SUFFIX}`. Stored as JSON in `portfolio_settings.external_stock_links`. Exposed to Screener via `GET /api/screeners/meta` → `external_stock_links` (enabled templates only).
- Shell UI: full-bleed black header (`AppHeader.jsx`, brand **StoX** …). **Sidebar-only nav (2026-07-30):** top AppTabs removed; catalog `config/navigation.js`; **Favourites** + **Quick Actions**; collapsible groups (Portfolio / Market / Trading / Knowledge / Administration). Layout shell 260/64/overlay (`lido-app-frame` scroll isolation). **Ctrl/Cmd+B** toggle; breadcrumbs/title in `PageChrome.jsx`. Editor pages (screener editor, registries, settings tools) remain routable but off the sidebar.

- **Watchlist** (`/watchlist`, `/watchlist/:symbol`, `WatchlistPage.jsx`): per **active portfolio**, **multiple named watchlists** (`portfolio_watchlists`: `profile_id`, `name`, `sort_order`; max 20 per portfolio). Items in `portfolio_watchlist_items` (`watchlist_id`, `profile_id`, `stock_id`, optional `note` ≤500 chars; unique per watchlist+stock; max 100 items per watchlist). Default list **My Watchlist** auto-created per portfolio (migration `2026_07_17_000001`; existing items migrated). **API:** `GET/POST/PUT/DELETE /api/watchlists`; `GET/POST /api/watchlists/{id}/items` (`search`, `sort` query params); `GET /api/watchlist/membership?stock_id=`; `PUT/DELETE /api/watchlist-items/{id}`. Watchlist item payloads include `holding` for open positions in the active portfolio (`quantity`, `avg_buy_price`, `invested_amount`, `unrealized_profit`; `null` otherwise). **UI:** page-level stock search (placeholder explains search or pick-from-list); selecting a stock (search or list) navigates to **`/watchlist/{SYMBOL}`** and loads price history; opening that URL restores the stock. Active-list card title bar has the **watchlist dropdown** plus **Manage** / **Scan watchlist**; item filter + sort + **Search & add** sit in a toolbar under that header; a **briefcase icon** beside NSE/BSE when the stock is held, with a hover detail panel for units, average buy, invested amount, and unrealized P/L (same pointer cursor as the row — no `cursor: help`); per-row **red hover delete** (forced red for normal, active, hover, and Bootstrap button states); **day change** under price (bright if `latest_price_date` is today, faded if stale); **persisted pattern icons** after Scan. Active watchlist persisted in `localStorage` (`portfolio_active_watchlist_v1_{profileId}`). `StockAutocomplete` + `PriceVolumeChart` via `GET /api/stocks/{id}/market-prices`. Selected-stock **Add to …** combo (`AddToWatchlistComboButton`) primary label is **Add to {active watchlist name}** (menu items match). **Compare strength** combo (`CompareStrengthComboButton`) sits beside Remove/Add — primary **Compare strength against Nifty 50** (or other primary index); menu lists every Explorer benchmark and navigates to `/explorer?symbol=…&benchmark=…`. **Manage modal backdrop fix (Jul 2026):** uses Knowledge Board portal pattern (`lido-knowledge-modal-root` + custom backdrop behind dialog) so Bootstrap `.modal-backdrop` no longer covers the dialog. **Dark mode theme (Jul 2026):** Manage modal styles labels, inputs, and list rows with Lido CSS variables (portal sits outside `.contentPane`). Migration: `2026_07_04_000001_create_portfolio_watchlist_items.php`, `2026_07_17_000001_create_portfolio_watchlists_table.php`.
- **Pattern guide** (`/patterns`, `PatternGuidePage.jsx`): educational reference + pattern scanner docs. Main nav tab **Patterns** with **Chart patterns** / **Candlesticks** sections (`SegmentToggle`; preference `localStorage` key `portfolio_pattern_guide_section`). Static datasets: [`data/chartPatterns.js`](app/resources/js/src/data/chartPatterns.js) (cup & handle, H&S, triangles, flags, wedges, etc.) and [`data/candlestickPatterns.js`](app/resources/js/src/data/candlestickPatterns.js) (doji, hammer, shooting star, engulfing, harami, piercing line, dark cloud cover, stars, etc.). Each entry: **SVG sketch** (`PatternSketch.jsx` — close above open = green, close below open = red; SVG Y is inverted so color uses `c <= o`), characteristics, meaning, OHLCV **math rules**; glossary in [`data/ohlcvCandleTerms.js`](app/resources/js/src/data/ohlcvCandleTerms.js). UI: expandable `PatternGuideCard` + search filter. **Deep links:** each pattern card has DOM `id` = pattern id; URL fragment `/patterns#hammer` switches section, expands card, and scrolls into view (`patternGuideLinks.js`). Dashboard pattern signals link pattern name → definition.
- **Pattern detection (Jun 2026):** OHLCV scanners run on cached `portfolio_stock_prices` (daily bars). JS engine: [`utils/patternDetection/`](app/resources/js/src/utils/patternDetection/) (`candleMath`, `detectCandlesticks`, `detectChartPatterns`, `scanOhlcv`) — patterns complete on the **latest bar** of the window. PHP mirror: `PatternDetectionService` + `PatternScanService`. API: **`GET /api/patterns/scan?scope=holdings|watchlist&watchlist_id=&actionable_only=`** (default `true`; `watchlist_id` scopes watchlist scan to one list and **persists** results) — returns `{ results, persisted }`. **Watchlist persistence (Jul 2026):** `portfolio_watchlist_pattern_scans` stores per watchlist+stock `matches` JSON, `price_as_of`, `expires_at` (end of calendar day 2 days after `price_as_of`), `scanned_at`. Results stay valid until `expires_at` **or** newer OHLCV arrives (`latest price_date > price_as_of`). `GET /api/watchlists/{id}/items` includes `pattern_matches` for valid scans. **UI:** Scan watchlist button refreshes row icons (no results table); each match is a small `PatternSketch` link to `/patterns#{id}` with hover tooltip (name · signal · as-of). Switching active lists reuses stored matches. **Actionable** = category ≠ `neutral` (doji/spinning top excluded from dashboard). **Dashboard:** table **Pattern signals (holdings)** — actionable matches only. **OHLCV chart:** footer **Possible patterns on this window** (client-side `scanOhlcv`). **Single-stock scan (Jul 2026):** `GET /api/stocks/{stock}/pattern-scan` (`actionable_only` default `false`) → `{ stock_id, symbol, matches, price_as_of, source: fresh|watchlist_cache, persisted }`. `PatternScanService::scanStock` first looks for a **valid persisted watchlist scan** for the stock across any of the profile's watchlists (same validity rules: not expired, no newer OHLCV) and serves it without recomputing; on a cache hit it also copies the row to member watchlists that lack a valid scan. Otherwise it computes fresh from cached bars and **writes back** to every member watchlist row (`persisted=true` only when the stock is on ≥1 watchlist). **Watchlist tab UI:** loading a stock in the detail panel auto-fetches this endpoint and renders **Matched patterns** badges (sketch + name + signal) linking to `/patterns#{id}`; a fresh persisted result also updates the row icons in the list below, and adding the stock to a watchlist re-runs the fetch so the new list's row gets the scan.
- **Knowledge Board (Jun 2026):** per **active portfolio** notes for stock-market learnings, research, and ideas — **not** a general notes app. Routes: `/knowledge-board`, `/knowledge-board/tags`. Sidebar **Knowledge** group lists **Knowledge Board** and **Knowledge Tags**. **UI (Jul 2026 cleanup):** no title field (title auto-derived from first content line); compact collapsible toolbar (collapsed by default — **+ New** on the left, then a 6px gap, then a full-width expand strip with centered chevron; expanded action buttons use 6px gaps; toolbar has 6px top margin; expanded shows full **Knowledge Board** header + **New Note** and filters) — action buttons row includes **Manage tags**; filter row without field labels (search placeholder **Search text, tags**; sort options e.g. **Sort manually**, **Sort by date created** (persisted in `portfolio_knowledge_board_sort`)); tag-match dropdown on tag filter row (**Match any tags**, etc.); filter tag chips dark gray; full-width single-column cards; manual sort drag grip in overlay before checkbox (compact); note body text (`--lido-knowledge-note-text`, normal weight); card header toolbar uses stroke SVG icons (`KnowledgeCardIcons.jsx`, muted gray, no underline) — order clock → pin → edit → duplicate → archive → delete; card header toolbar overlays note text on hover (transparent, compact row; text starts at top); ⋮ menu on mobile; filter tags use tag colors + distinct active ring; cards show full note body (no clip); edit via toolbar only; editor **Simple / Formatted / Markdown** toggle in footer (Markdown mode: **Edit / Preview** bar above textarea; `marked` for preview + save); formatted editor ProseMirror styles restore list markers, blockquote border, and task-list flex layout (Tailwind preflight reset); inline tags row in editor footer (between mode toggle and action buttons, with separators); save status uses theme colors (visible **Saved** in dark mode); manual save button **Save (Ctrl/Cmd + S)**; footer **Delete** icon button (outline danger, left of Close) when editing a saved note (including after autosave creates one). **Export dialog:** `SegmentToggle` for Plain Text / Markdown / AI Friendly (`portfolio_knowledge_board_export_format` in `localStorage`); exports note body only (no title/tags/dates); notes always separated by dividers; footer gap between Close and Copy; dialog max-height capped to viewport (`100dvh` minus root padding) with scrollable preview area. API still stores `title` for search/sort. `is_favorite` retained in DB but hidden from UI. **Read / Manage toggle (Jul 2026):** `SegmentToggle` (**Read** | **Manage**, persisted `portfolio_knowledge_board_manage_mode`, default Manage). **Manage** shows per-note checkbox + hover action toolbar (and bulk Select/Archive/Delete/Export). **Read** hides those controls for a clean reading view (tags stay visible above the body); selection is cleared when switching to Read. **Images (Jul 2026):** Formatted + Markdown editors support insert (toolbar / paste / drop). Client resizes large images to max **1200px** edge for embed and keeps a full-size copy (max **4000px**). `POST /api/knowledge-board/images` stores both under `storage/app/knowledge-images/{profile_id}/`; `GET /api/knowledge-board/images/{uuid}` serves the display version and `…/full` the original. TipTap `data-full-src` + markdown `![alt](display "full")`; click opens a lightbox at full resolution. Cards render HTML (including images). Migration `2026_07_24_000001` → `portfolio_knowledge_images`. **Color palettes (Jul 2026):** fixed contrasting bg+text pairs (`KnowledgeNotePaletteCatalog` / `knowledgeNotePalettes.js`: Theme default, Slate, Paper, Ocean, Forest, Ink, Sky, Moss, Navy, Mint, Ember, Charcoal). Column `portfolio_knowledge_notes.color_palette` (migration `2026_07_25_000001`, default `default`). `GET /api/knowledge-board/palettes`; create/update accept `color_palette`; duplicate copies it. UI: swatch picker on create/edit modal (live preview) and compact swatches on note cards in **Manage** mode only (hidden in **Read**); one-click change patches palette.
- Holdings bottom-nav item stays active on OHLCV sub-routes (`/holdings/:id/prices`).
- Holdings OHLCV screen (`StockPricesPage`): reusable **`PriceVolumeChart`** ([`components/charts/PriceVolumeChart.jsx`](app/resources/js/src/components/charts/PriceVolumeChart.jsx)) above the table — Recharts `ComposedChart` with close line + volume bars (green/red by bucket vs prior close). Data from same `GET /stocks/{id}/prices` rows as the table; transforms in [`utils/ohlcvChartData.js`](app/resources/js/src/utils/ohlcvChartData.js). **Range** (footer): All (default), 1M, 3M, 6M, 1Y — calendar cutoff from latest date; clamps to available history with muted hint when shorter than selected window. **Sampling**: 1 day (default), 5 days, 10 days, 1 month (30 records) — consecutive record buckets; **close** = last row in bucket, **volume** = sum. `DataTableCard` below with `formatTransactionDateDisplay`, `formatTableMoney2` (Open/High/Low/Close), `formatTableInteger` (Volume).
- Holdings table **Stock** symbol is a direct link to OHLCV (`/holdings/:id/prices`). **Sell** primary action navigates to `/transactions` with form prefilled: symbol/name/exchange, type `sell`, quantity = holding qty, price = latest close, symbol marked validated (`sellTransactionPrefill.js` + router `location.state`). Row actions use reusable **`ComboButton`** (`components/ComboButton.jsx`, Carbon ComboButton pattern): primary **Sell** + menu chevron; menu items portaled to `document.body` and positioned with **Popper** (`bottom-end`) so dropdown does not overlap the button inside table cells; opening one combo menu broadcasts `lido-combo-button-open` so any other open combo menu closes. **Invested** column: list icon link (hidden until row/cell hover; tooltip **View transactions**) → `/transactions` with `transactionSearch` set to the stock symbol.
- Holdings **Latest Close**: `₹` whole amount; **complex** view adds day-over-day `(±N.NN%)` from the latest two OHLCV closes (`stoploss_summary.daily_change_percent`); omitted when only one price row exists. Price still red (no bold) when below trailing stop. When LTP &lt; trailing stop: **Stock** symbol uses `text-danger`; **Sell** uses solid `btn-danger` (not outline).
- Holdings **Unrealized P/L** column (simple + complex): signed `±₹` amount (2 dp, green/red). **Complex** adds secondary line `(±N.NN%)` vs invested (`unrealized_gain_percent` from API). Shows `—` when latest close unavailable.
- Holdings **Highest Close** 2nd line: `LTP: N%` = `((LTP − highest since buy) / highest) × 100`; green if ≥ 0, orange if below 0 but above −`stoploss_percent`, red if ≤ −`stoploss_percent` (from settings / `stoploss_summary.stoploss_percent`). **Complex** column sort uses that LTP % (not rupee high); **Latest Close** sort uses day-over-day %; **Unrealized P/L** sort uses `unrealized_gain_percent` (simple view sorts by rupee amounts).
- Holdings table card header shows open position count next to title, e.g. **Holdings** `(3)` — count in muted smaller type; hidden while loading.
- Holdings table default column order: Stock → **Name** (company name from `stock.name`) → Latest Close → **Unrealized P/L** → Invested → Fees → XIRR → Highest Close → **Qty** → **Avg Buy** → Trailing Stop → Realized P/L → Sell. Hovering the **Stock** symbol shows the company name in a native tooltip (`title`). A puzzle-piece **Analyse** icon beside the symbol copies an AI analysis prompt (with 7-day OHLCV) to the clipboard — also on Watchlist rows, Explorer results, and Dashboard alerts/pattern tables (`AnalyseStockButton.jsx`, `utils/stockAnalysisPrompt.js`). New columns added to a table after a user saved column prefs are inserted at their **default-order position** (next to default neighbours), not appended at the end (`mergeMissingColumnIds` in `tableColumnPrefs.js`). **Name**, **Fees**, and **Realized P/L** hidden by default (`defaultColumnVisibility`); DataTable merges those defaults under saved visibility so newly default-hidden columns apply even when older `localStorage` prefs omit the key. Column prefs stored per view in `localStorage` keys `portfolio_datatable_holdings` (complex) and `portfolio_datatable_holdings-simple` (simple). **Simple / Complex** toggle in card header (before columns menu): complex = current multi-line cells; simple = primary value only (no since-buy date, price date, daily % in Latest Close, unrealized % subline, fee %, LTP drawdown, stop %, etc.). Selected view persisted in `portfolio_holdings_view`. Two table instances share the same dataset; only the active view is shown; column menu applies to the visible view only.
- SPA routing: `routes/web.php` catch-all serves `app` view for all non-API paths so browser refresh on `/holdings`, `/transactions`, etc. works (React `BrowserRouter`).
- Primary sidebar active state uses `useLocation().pathname` with per-item `match` helpers in `config/mainNav.js` (NavLink `className` callback does not receive `location` in React Router v6).

## Change Requests

- Track user requests in chat; this section is not a full history log.

## Deviations From Spec

- Table names are prefixed with `portfolio_` to avoid clashes with existing tables in the same DB.
- `throttleApi` middleware removed because the `api` limiter was not defined and caused runtime 500s.

## Bugs Fixed

- Table prefix migration fallout (validators, `HoldingController`, XIRR/Carbon 3, dashboard eager-load, PHPUnit/mbstring, health route).
- **Production mobile blank page (Jun 2026):** Vite emitted absolute script URLs on `lidoalexion.com` while users opened `www.lidoalexion.com` — ES modules failed cross-origin. Fix: `Vite::createAssetPathsUsing()` → root-relative `/portfolio/build/...` in `AppServiceProvider`.
- **Boot probe noise (Jun 2026):** diagnostic “Module script” lines were saved to `sessionStorage` and shown in `BootErrorBanner` after successful load; now only real failures persist and success clears storage.
- **`GET /api/auth/me` (Jun 2026):** moved outside `auth:sanctum` so guests get `{ user: null }` (200), not 401/500 during SPA boot.
- **Dark mode disabled inputs (Jun 2026):** Bootstrap’s disabled `form-control` used a light background; profile username (read-only) was unreadable. `lido-app.css` now themes `:disabled` / `[readonly]` inputs with `--lido-input-bg` and muted text.
- **Knowledge Board list 422 (Jul 2026):** `GET /api/knowledge-board/notes?archived=false` failed Laravel `boolean` validation (query string `"false"` is not accepted by the rule). `index` now uses `$request->boolean('archived')` without strict validation — same pattern as `PatternScanController` `actionable_only`.
- **Knowledge Board editor hang + colors (Jul 2026):** Tiptap `setContent` loop when parent passed live `contentJson` as `initialJson`; autosave updated `editingNote` and re-ran modal init effect. Fixed: editor mounts once per `sessionKey`, no content re-sync effect; modal init only on `sessionKey`; skip autosave without title; don’t refresh `editingNote` on autosave for existing notes. Modal/export CSS uses Lido theme variables. **Modal click-through (Jul 2026):** Bootstrap `modal-backdrop` was rendered after `modal-dialog`, covering the dialog (dimmed UI, no focus/clicks). Fixed: portal to `document.body`, custom backdrop behind dialog. Modal `border` + `box-shadow` on `.modal-content` for visible edge in dark theme. Explicit modal header/body/footer padding. **Tag input white flash (Jul 2026):** autosave set `saving` → `disabled` on tag field; Bootstrap default disabled bg (white) outside `.contentPane`. Fixed: no `disabled` during autosave; manual save only toggles `saving`; themed `:disabled` on modal inputs.
- **Profile menu light theme (Jun 2026):** account name hover used white text on white menu; email line used `text-white-50`. Fixed theme-aware `.lido-profile-account-link` / `.lido-profile-account-email`. Profile photos use app-relative `/api/profile/photo` URL (`profilePhotoUrl.js` + `User` accessor) so avatars load under `/portfolio` subdirectory.

## Known Limitations

- `vendor:publish` for Sanctum migrations fails when `finfo` extension is unavailable.

## Completed platform improvement

- Full PHP 8.4 backend tests, Node 22 frontend tests, and production-build CI are enforced by `.github/workflows/ci.yml` (V4-FEAT-030, completed 2026-09-04).

## Wishlist (deferred — no implementation yet)

- **Single-folder deploy (`portfolio/` only):** Collapse `lidoportfolio/` into `public_html/portfolio/laravel/` so one `build/` upload suffices. Design in `deploy/DEPLOY.md` §2.2. **Deferred** — consolidating Laravel under the web-visible `portfolio/` tree increases `.env` exposure risk unless `.htaccess` and secrets handling are bulletproof; current two-folder layout (`lidoportfolio/` with Deny all + `portfolio/` web entry) is kept intentionally.
- **Production secrets handling:** Before or as part of single-folder deploy, replace or supplement on-server `.env` (e.g. env vars outside web root, cPanel secrets, or shared `config/` only — DB already uses `/home/USER/config/DBConfig.php`). Goal: no sensitive values in a path that could become web-reachable after a layout change.

## Open Items

| Item                        | Status   | Notes                                                                                              |
| --------------------------- | -------- | -------------------------------------------------------------------------------------------------- |
| BSE master sync             | Optional | Enable `BSE_STOCK_MASTER_ENABLED=true` and `BSE_EQUITY_CSV_URL` when BSE CSV source is configured. |
| Single-folder deploy        | Wishlist | See § Wishlist; depends on secrets handling; keep `lidoportfolio/` + `portfolio/` for now.         |
| Production secrets / `.env` | Wishlist | Harden before nested Laravel under `portfolio/`; see `deploy/DEPLOY.md` §2.2.                      |

## Deployment Validation

- **Canonical deploy:** `deploy/DEPLOY.md` · checklists: `DEPLOYMENT_VALIDATION_PLAN.md`
- **Stage uploads:** `deploy/prepare-upload.ps1` → `deploy/staging/` (gitignored). Staging includes `lidoportfolio/config/*.php` (all app config except dev `DBConfig.php`); run `cpanel-config-cache.php` after upload when config changes.

### Production learnings (Jun 2026 — `/portfolio` on GoDaddy)

| Issue                                                          | Cause                                                                                                                                 | Fix                                                                                                                                                                                                        |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mobile blank page                                              | `www` vs apex in Vite `<script type="module">` src                                                                                    | Root-relative asset URLs in `AppServiceProvider`; `config:cache` after deploy                                                                                                                              |
| Login CSRF on some devices                                     | Stale `XSRF-TOKEN` at path `/` read from `document.cookie` after `/sanctum/csrf-cookie`                                               | Deploy latest build (`csrf.js` always uses `/api/auth/csrf-token` + `X-CSRF-TOKEN`); clear site cookies once; `config:cache` with `SESSION_PATH=/portfolio`, `SESSION_DOMAIN=.lidoalexion.com`             |
| **CSRF 419 even after clear / incognito**                      | **Wrong host** (`lidoalexion.com` apex vs `www.lidoalexion.com`) — apex may have expired/missing SSL or different cookie/TLS behavior | **Always use `https://www.lidoalexion.com/portfolio/`**; deploy `.htaccess` apex→www redirect (`deploy/public_html-portfolio-.htaccess` + root snippet); `APP_URL=https://www.lidoalexion.com/portfolio`   |
| “App did not start” on `mobile-debug.html`                     | Static file missing → Laravel SPA                                                                                                     | Upload as `portfolio/mobile-debug.html`; fix `portfolio/.htaccess` + root snippet                                                                                                                          |
| 404 on whole `/portfolio/`                                     | `.htaccess` missing `index.php` rewrite                                                                                               | Use `deploy/public_html-portfolio-.htaccess`                                                                                                                                                               |
| Red “App load problem” on login                                | Stale `sessionStorage.lido_boot_error`                                                                                                | Tap Dismiss; deploy latest `BootErrorBanner` + `app.blade.php`                                                                                                                                             |
| Intermittent blank page typing in forms (e.g. user mgmt email) | Mobile keyboard / `100vw` header overflow / `backdrop-filter` repaint bug — devtools resize “fixes” it                                | Deploy Jun 2026 fix: drop `100vw` header breakout, `overflow-x: hidden`, `100dvh`, `interactive-widget=resizes-content`, solid footer nav, `scroll-margin` on inputs, `autoComplete="off"` on invite email |

**Server cleanup after troubleshooting:** see **`debugging.md` → [Cleanup TODO (long-term)](#cleanup-todo-long-term)** — delete `cpanel-*.php` scripts, set `LIDO_AGENT_DEBUG_ENABLED=false`, run `cpanel-config-cache.php`. Also remove `mobile-debug.html`, `portfolio-OK.txt`, `test-ok.php` from `public_html/portfolio/`. Keep `index.php`, `.htaccess`, `build/`.

**Optional deploy diagnostics (repo only, upload temporarily):** `cpanel-ping.php`, `cpanel-mobile-debug.php`, `cpanel-api-probe.php`, `cpanel-schedule-diagnostic.php`, **`cpanel-db-query.php`**, **`cpanel-read-logs.php`**, **`cpanel-api-call.php`**, `portfolio-mobile-debug.html` (upload renamed → `mobile-debug.html`). Full agent runbook: **`debugging.md`**. See `deploy/README.md`.

**Scheduler diagnostic (Jul 2026):** `deploy/cpanel-schedule-diagnostic.php` — read-only browser script for production cron troubleshooting. Upload to `public_html/portfolio/`, visit `https://www.lidoalexion.com/portfolio/cpanel-schedule-diagnostic.php?token=Lido` (set `SETUP_TOKEN` before upload). Reports: `cron_time` / `cron_timezone`, universe enablement, **`isMaintenanceWindowDue()`**, **schedule heartbeat** (`schedule_run_heartbeat_at` — proves `schedule:run` every minute), last probe JSON, **withoutOverlapping mutex**, in-progress flag, `schedule:list`, events due now, recent sync runs, tonight's window check. Optional query flags: `&explain=1`, `&clear_mutex=1`, `&clear_in_progress=1`. **Force one batch:** `deploy/cpanel-run-universe-maintenance.php?token=...&apply=1` (optional `&clear_guards=1`, `&skip_gap_fill=1`). **Delete after use.**

**Schedule heartbeat / probe (Jul 2026):** Every minute `portfolio:universe-maintenance-probe --write-heartbeat` updates `portfolio_settings.schedule_run_heartbeat_at` and (when `backend_log_level=debug`) writes a **debug** line to `storage/logs/scheduler-YYYY-MM-DD.log` with window/interval/mutex/in-progress/`would_skip_reason`/batch_size. On each maintenance due tick, `--explain` also writes `universe_maintenance_probe_json` + an **info** probe line. Maintenance command logs **info** start/finish and **debug** daily/gap results. Sync skips (disabled / in-progress) and last_run heal events are logged. If heartbeat debug lines are missing while evening sync is idle, cPanel cron is not invoking `schedule:run` every minute. **Retention:** scheduler channel uses `LOG_DAILY_DAYS` (default 2) — set `backend_log_level` back to `info` after debugging so minute heartbeats don’t inflate logs.

**Vite build:** `npm run build` defaults to `VITE_APP_BASE=/portfolio/build/` in production (`vite.config.js`); CSS `url()` assets (Nulshock font) use relative paths. Copy full `public/build/` (including `assets/nulshock-*.ttf`) to both `lidoportfolio/public/build/` and `portfolio/build/`.

## Logging & Debugging Architecture (May 2026)

Mandatory lightweight logging for same-day / 1–2 day debugging. **File-based only** — no log rows written to `portfolio_system_logs` (table retained for legacy; new path uses Monolog daily files).

### Goals

- Quick debugging, recent log inspection, error tracing, frontend↔backend correlation via `X-Request-ID`.
- No long-term retention; `LOG_DAILY_DAYS=2` rotates and deletes older files.

### Backend channels (`config/logging.php`)

| Channel           | File pattern                            | Purpose                                              |
| ----------------- | --------------------------------------- | ---------------------------------------------------- |
| `daily` (default) | `storage/logs/laravel-YYYY-MM-DD.log`   | Application / API / validation / security / telegram |
| `frontend`        | `storage/logs/frontend-YYYY-MM-DD.log`  | Logs from SPA via API                                |
| `provider`        | `storage/logs/provider-YYYY-MM-DD.log`  | NSE / Yahoo / Alpha Vantage failures & fallbacks     |
| `scheduler`       | `storage/logs/scheduler-YYYY-MM-DD.log` | Cron / `DailyMarketDataJob`                          |

Env: `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=2`, `LOG_LEVEL=debug` (Monolog floor; app-level filter is separate).

### Dynamic backend log level

- Setting key: `backend_log_level` in `portfolio_settings` (`debug` | `info` | `warning` | `error`).
- Default: `info` (`SettingsService`).
- Editable in Settings UI and `PUT /api/settings`.
- `PortfolioLoggerService::shouldLog()` filters before writing; Monolog always receives normalized level when allowed.

### Backend services

- `App\Services\PortfolioLoggerService` — categories: API, Scheduler, Provider, Telegram, Validation, Security; methods `api()`, `scheduler()`, `provider()`, `frontend()`, `telegram()`, `validation()`, `security()`, `logFrontendPayload()`.
- `App\Services\SystemLogService` — backward-compatible facade; maps legacy categories to channels; **no DB writes**.
- `App\Support\RequestContext` — holds current request ID.
- `App\Http\Middleware\AssignRequestId` — reads or generates `X-Request-ID`, shares `request_id` in `Log::shareContext()`, echoes header on response. Registered on `api` + `web` in `bootstrap/app.php`.
- Uncaught exceptions: `bootstrap/app.php` `report()` callback logs via `PortfolioLoggerService::api()`.
- **API exception render fix (Jul 2026):** the custom JSON `render()` in `bootstrap/app.php` treated `AuthenticationException`/`AuthorizationException` as generic 500s (they don't implement `HttpExceptionInterface`); they now fall through to the framework so unauthenticated/forbidden API calls correctly return **401/403**.
- **TD-010 domain exceptions (Jul 2026):** uncaught `App\Exceptions\DomainException` on `api/*` renders `ApiEnvelope::error` (422/400). Use for business preconditions; keep `ValidationException` for form validation.

### Request correlation flow

1. Frontend `api.js` interceptor: `createRequestId()` → header `X-Request-ID` on every Axios call; stored on `config.metadata.requestId`.
2. Middleware preserves client ID or assigns UUID.
3. All `PortfolioLoggerService` entries include `request_id` in context.
4. Frontend errors shipped to backend include `requestId` (and use same header on log POST).

### Frontend logger (`resources/js/src/services/logger.js`)

- Methods: `logger.debug|info|warn|error`, `setLevel` / `getLevel` via `localStorage.logLevel`.
- Local console output respects level; **only `warn` and `error`** are queued to `POST /api/logs/frontend` (async, batched, non-blocking).
- Redacts password/token/secret patterns before ship.
- Do **not** use `console.log` in app code — use `logger`.

### Frontend error boundary

- `resources/js/src/components/ErrorBoundary.jsx` wraps authenticated app in `App.jsx`.
- Catches render errors, user-friendly fallback + reload; logs via `logger.error` → backend.

### Frontend logging API

- `POST /api/logs/frontend` (Sanctum auth required).
- Controller: `FrontendLogController` — max body 8KB, field validation, extra JSON cap 4KB, sanitization via `PortfolioLoggerService`.
- Payload: `level`, `message`, `url`, `userAgent`, `timestamp`, `requestId`, `extra` (e.g. `category`: API | UI | Validation | Navigation).

### Provider & scheduler logging

- `PriceFetchService`: logs failures, zero-row responses, fallback activation to `provider` channel with symbol, provider name, attempt, request time, failure reason.
- `DailyMarketDataJob`: start/end, processed/failed/skipped counts, per-stock failures; portfolio snapshot count (aggregate, not per-user rows).
- **In-app sync logs (Jun/Jul 2026):** `portfolio_sync_runs` + `portfolio_sync_logs` tables; `SyncLogService` writes DB rows when `sync_log_retention_days` &gt; 0 (default **7**, max 90; **0** disables DB writes and prunes existing rows) **and both tables exist**. File logs via `PortfolioLoggerService::scheduler()` unchanged. Jobs: `daily-market-data` (`DailyMarketDataJob` / `POST /api/sync/daily`), `stock-master` (`stocks:sync`), and `universe-price-sync` (`portfolio:sync-universe-prices`). Prune on each run start + hourly `sync-log-prune` schedule. Settings: retention field + latest run summaries on `GET /api/settings`. UI: **Settings → View sync logs** → `/settings/sync-logs` shows **Recent runs** (`GET /api/sync-logs/runs`) plus paginated log lines, filters, CSV export. **Timestamps (Jul 2026):** sync log UI formats `started_at` / `finished_at` / `logged_at` in **`cron_timezone`** from settings (API `meta.cron_timezone`) as `DD Mon, YYYY, h:mm AM/PM ZZZ`, e.g. `08 Jul, 2026, 4:30 PM IST` (not browser local time). If runs cluster around 05:00 IST while timezone is `Asia/Kolkata`, they are outside the 19:00–23:45 maintenance window and point to a scheduler/code mismatch, not a display bug. **Sync runs pagination (Jul 2026):** `GET /api/sync-logs/runs` supports `page`, `per_page` (default 20, max 100), `job_name`, and `date_from`/`date_to` (cron timezone); UI **Sync runs** table has Previous/Next pagination like log entries. If runs appear but log lines are empty, apply migration `2026_06_21_000002` and re-run a sync. **cPanel:** `deploy/cpanel-migrate.php` runs `migrate --force`, repairs orphaned state (`portfolio_sync_runs` without `portfolio_sync_logs`), verifies required tables/columns, and reports `SyncLogService` readiness. Migration: `2026_06_21_000002_create_portfolio_sync_logs_tables.php`.

### Error handling policy

- Never silent failures on API (Axios interceptor + toast + `logger.error`).
- Important failures always logged server- or client-side.
- Retries remain in price providers / HTTP layer where already implemented.

### Security

- Strip tags / newlines from messages; redact secrets in context JSON.
- No passwords, tokens, cookies, or API keys in logs.

### Tests

- `tests/Unit/PortfolioLoggerServiceTest.php` — level filter, request_id, sanitization.
- `tests/Feature/RequestCorrelationTest.php` — `X-Request-ID` header.
- `tests/Feature/FrontendLogControllerTest.php` — validation, auth, accept path.
- `tests/Unit/PriceFetchServiceTest.php` — provider logging mock.
- `tests/Feature/DailyMarketDataJobTest.php` — scheduler logging mock.
- `tests/Feature/SyncLogTest.php` — retention, disabled writes, API filters, CSV export, settings summaries.

### Debugging (local)

```bash
cd app
php artisan test
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
tail -f storage/logs/provider-$(date +%Y-%m-%d).log
```

Set frontend verbosity in browser: `localStorage.setItem('logLevel','debug')`.

### Debugging (cPanel)

- File Manager or SSH → `app/storage/logs/`.
- Open today’s `laravel-*.log`, `frontend-*.log`, `provider-*.log`, `scheduler-*.log`.
- Search by `request_id` from browser Network tab (`X-Request-ID`) across files.
- Ensure `storage/logs` is writable; cron output is not duplicated to DB.

### Future logging changes

Document any new channel, endpoint, or retention change in this section.

### Related docs

- API: `app/API_DOCUMENTATION.md` → Frontend logs section

## Stock Validation Architecture (May 2026)

### Design principle

All validation is **local-first**. Live providers (NSE → Yahoo → Alpha Vantage) run only when the symbol is missing from `portfolio_stocks`. This minimizes API usage and improves UX.

### Schema (`portfolio_stocks`)

Migration `2026_05_29_000001_extend_portfolio_stocks_master.php` adds provider symbol fields and replaces unique(`symbol`) with unique(`symbol`, `exchange`).

| Column                                 | Purpose                                              |
| -------------------------------------- | ---------------------------------------------------- |
| `symbol`                               | Normalized ticker only (e.g. `INFY`) — not `INFY.NS` |
| `exchange`                             | `NSE` or `BSE`                                       |
| `name`, `isin`, `sector`               | Display / metadata                                   |
| `bse_scrip_code`                       | BSE numeric scrip code (`FinInstrmId` in UDiFF bhavcopy) |
| `yahoo_symbol`, `alpha_vantage_symbol` | Provider-specific symbols                            |
| `is_active`, `admin_deactivated`, `is_benchmark`, `is_dual_listed` | `is_active` = system/feed listing state; `admin_deactivated` = explicit admin override (`effective_active = is_active AND NOT admin_deactivated`); benchmark rows ignore admin override |
| `series` | NSE listing series (`EQ`, `BE`, `BZ`); used to build NSE provider trade symbol |
| `last_verified_at`                     | Last provider or sync verification                   |

**Unique:** `(symbol, exchange)` — same ticker may exist on NSE and BSE separately.

### Symbol normalization

| Input     | Stored                                  |
| --------- | --------------------------------------- |
| `INFY`    | symbol=`INFY`, exchange=`NSE` (default) |
| `INFY.NS` | symbol=`INFY`, exchange=`NSE`           |
| `INFY.BO` | symbol=`INFY`, exchange=`BSE`           |

Provider suffixes are resolved by `ProviderResolverService`, not stored in `symbol`.

### Services

| Service                   | Responsibility                                                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------------- |
| `ProviderResolverService` | `normalizeSymbol()`, `yahooSymbol()`, `alphaVantageSymbol()`, `isMalformed()`, `applyProviderSymbols()` |
| `StockValidationService`  | Stage 1 local lookup; stage 2 provider chain; `validateAndPersist()` upserts + backfill                 |
| `StockMasterSyncService`  | NSE + BSE master import via `stocks:sync`; ISIN dedup (NSE preferred); `is_dual_listed` flag |
| `BseEquityMasterService`  | BSE equity list via API (`BSE_EQUITY_LIST_API_URL`) or optional `BSE_EQUITY_CSV_URL`; persists `bse_scrip_code` when available |
| `BseBhavcopyService`      | Download + parse BSE UDiFF bhavcopy per session day |
| `BseBhavcopyBackfillService` | Bulk OHLCV backfill for BSE-only universe stocks from bhavcopy |
| `EquityUniverseService`   | Universe/search queries, ISIN dedup, `exchange_label` (`NSE+`), canonical stock resolution |
| `StockResolverService`    | Used by transactions — delegates to validation (no blind `Stock::create`)                               |

### Provider fallback flow

```
User input → normalize → local DB hit? → return valid
                      → miss → NSE (retry nse_retry_count)
                      → fail → Yahoo (meta quote)
                      → fail → Alpha Vantage GLOBAL_QUOTE
                      → fail → 422 / validation error
```

### Provider-specific symbol mapping

- NSE API uses normalized `symbol`
- Yahoo uses `yahoo_symbol` on stock row or `{SYMBOL}.NS` / `.BO`
- Alpha Vantage uses `alpha_vantage_symbol` or Yahoo-style symbol
- `PriceFetchService` passes per-provider symbols from `ProviderResolverService::providerSymbolsForStock()`

### Stock master sync

- Command: `php artisan stocks:sync` (`SyncStockMasterCommand`) — after a successful import, also refreshes NSE index constituent caches (broad + sector)
- Schedule: weekly Sunday 02:00 (timezone from settings / env)
- **NSE:** `config('portfolio.stock_master.nse_equity_csv_url')` default NSE `EQUITY_L.csv`; imports **EQ, BE, and BZ** series (one primary row per ISIN: EQ → BE → BZ). Stores base `symbol` + `series` column (Jul 2026); NSE trade id for providers is derived as `SYMBOL` or `SYMBOL-{series}` when not EQ.
- **BSE (Jul 2026):** enabled by default (`BSE_STOCK_MASTER_ENABLED=true`). **`BseEquityMasterService`** fetches BSE equity list from `BSE_EQUITY_LIST_API_URL` (BSE `ListofScripData` API) or optional `BSE_EQUITY_CSV_URL` override. **`bse_scrip_code`** (migration `2026_07_13_000001`) is set from `scrip_cd` / `SC_CODE` when the master row has a text trading symbol. **Deploy:** upload `config/portfolio.php` and run `config:cache` — stale cache without `bse_list_api_url` caused null URL errors; service now falls back to built-in default API URL if config is missing.
- **ISIN dedup:** when the same ISIN exists on NSE and BSE, only the **NSE** row is kept; BSE duplicate rows are skipped/deactivated. NSE rows get `is_dual_listed=true` and display as **`NSE+`** (`exchange_label` in API). BSE-only listings remain `exchange=BSE`
- Duplicates within same exchange logged and skipped; removed symbols set `is_active=false` (IDs preserved)
- **Immediate new-symbol price fill:** when stock master adds new NSE or BSE-only symbols, CLI `stocks:sync` may backfill those ids (`STOCK_MASTER_BACKFILL_ON_SYNC`, capped by `STOCK_MASTER_MAX_BACKFILL_PER_SYNC`). **UI** `POST /universe-price-sync/stock-master` imports master only (no price backfill) to avoid HTTP timeouts on shared hosting.

#### NSE `series` column + dual-listed BSE cleanup (Jul 2026)

**Problem fixed:** NSE `EQUITY_L.csv` lists ~310 BE-only and ~27 BZ-only ISINs (e.g. `TOKYOPLAST` series `BE`). Previously only EQ rows were imported, so those names never got an NSE row; only a BSE row existed and universe pricing used BSE providers while chart sites showed NSE data.

**Implementation:**

- Migration `2026_07_14_000001`: nullable `portfolio_stocks.series` (`EQ`, `BE`, `BZ`).
- UI keeps base `symbol` (e.g. `TOKYOPLAST`); `ProviderResolverService::nseTradeSymbol()` builds NSE trade id (`TOKYOPLAST-BE`) for `NsePriceProvider`.
- Stock master sync imports non-EQ NSE lines (primary per ISIN: EQ → BE → BZ), persists `series`, sets `is_dual_listed`, deactivates BSE duplicate.
- **`DualListedNseRepairService`:** for each BSE row whose ISIN has an active NSE row — repoint holdings/transactions/alerts/watchlist/corporate actions to NSE id, delete BSE `portfolio_stock_prices` + metrics, deactivate BSE row, optionally refill NSE history (`fetchMissingHistory`).
- Runs automatically at end of stock master sync when `STOCK_MASTER_DUAL_LISTED_REPAIR_BACKFILL=true` (default; capped by `STOCK_MASTER_DUAL_LISTED_REPAIR_MAX_BACKFILL`, default 50). UI stock-master sync skips NSE backfill when `backfillNewSymbols=false` but still purges BSE duplicates.
- CLI: `php artisan portfolio:repair-dual-listed-nse` (`--dry-run`, `--no-backfill`, `--max-backfill=`).
- cPanel: `cpanel-repair-dual-listed-nse.php` — probe `?token=Lido&probe=1`; apply `&apply=1&max_backfill=25`; **NSE-only backfill** `&backfill_nse_only=1&max_backfill=25` (re-run until `nse_backfill_remaining=0`; use `&reset_cursor=1` to restart). Delete after success.
- cPanel: `cpanel-stock-master-sync.php` — `?token=Lido` runs full stock master (NSE BE/BZ import + BSE dedup); optional `&backfill=1` for new-symbol price backfill. Run **before** repair if `pairs_found=0`.
- Repair pairs match by **ISIN** or, when legacy BSE rows lack ISIN, by **symbol** with active NSE row. `is_dual_listed` is set from any matching BSE symbol (not only ISIN skip list). BSE skip during master sync backfills ISIN and deactivates the BSE duplicate immediately.

**Post-deploy:** migrate → config cache → stock master sync (or repair script with `apply=1`) → clear gap scan → scan/fill gaps.

### Universe price sync (Jun 2026)

Bulk OHLCV for the **equity universe** (NSE + BSE-only; ISIN deduped). Reuses `portfolio_stock_prices`, `StockPriceHistoryService` gap-fill, and `PriceFetchService` provider chain (NSE → Yahoo → Alpha Vantage). No screener metrics or buy alerts in this phase.

**NIFTY50 benchmark (Explorer / RS):** Universe sync excludes `is_benchmark` rows. **`BenchmarkPriceSyncService`** keeps the primary index (default **NIFTY50**) current via **`IndexPriceSyncService::syncOneSymbol`**: full ~12-month backfill when cache is insufficient for 6M analytics, otherwise incremental. Runs automatically via (1) **`portfolio:sync-benchmark-prices`** scheduled daily with daily market sync, (2) **`portfolio:daily-sync`** (force each run), (3) first universe batch each calendar day (`syncIfNeeded` skips if already synced today). Manual: `php artisan portfolio:sync-benchmark-prices`. Holdings/dashboard relative strength (`RelativeStrengthService`, `StockMetric`, `nifty_comparison`) still use the **primary** index only (`INDEX_PRIMARY_SYMBOL`, default NIFTY50).

**Explorer multi-index (Jul 2026):** The Explorer tab lets the user pick **any enabled index** (NSE + BSE) as the benchmark, not just NIFTY50. Deep links: **`/explorer?symbol=RELIANCE&benchmark=NIFTY50`** — selecting a stock or changing the benchmark updates the query string; landing with `symbol` (+ optional `benchmark`) auto-runs analysis. Stock search has **no exchange toggle** — autocomplete queries both NSE and BSE (`exchange` omitted). `GET /api/indexes` (auth, non-admin) returns `{ primary_symbol, indexes: [{ symbol, name, exchange, is_primary }] }` from `IndexCatalogService::enabledDefinitions()`; `StockExplorerPage.jsx` populates the **Benchmark index** `<select>` (grouped by exchange, primary flagged) from it and falls back to a NIFTY50-only option if the call fails. `ExploratoryAnalyticsService::analyze` now resolves the benchmark via `IndexCatalogService::definitionForSymbol` + `ensureIndexStock` (handles BSE indexes like SENSEX correctly), falling back to `primaryBenchmarkStock()` for unknown/empty symbols. All Explorer copy/labels/charts use the selected benchmark symbol dynamically. Requires the chosen index's OHLCV to be present in the index cache (run Market indexes sync in Settings otherwise).

**Indices page (Jul 2026):** Main nav **Indices** → `/indices`. Charts include **broad + sector** indexes; **India VIX** is enabled as a detail section (`tier: volatility`) but **excluded** from comparison line/period charts and shared legend. Expandable sections: NSE/BSE → Broad market / Sector / Volatility. Each expanded section leads with a one/two-line **description** from `portfolio.indexes.definitions`. Shared legend (Select all / Clear all, portfolio localStorage). Stacked period growth charts (6M / 3M / 1M / 15D). Constituents for **NSE broad + sector** indexes via archives CSV (e.g. `ind_niftybanklist.csv`) + API fallback; constituent **symbol links open `/watchlist/{SYMBOL}`**. **Weekly refresh:** after `stocks:sync` (Sun 02:00 `cron_timezone`) and a backup `portfolio:refresh-index-constituents` at Sun 02:30; one-off via `cpanel-refresh-index-constituents.php`.

**Documentation sync (2026-07-29):** Indices help topic now covers India VIX alert controls and the normal ~10–30 quote scale (vs ×100 provider glitches).

**India VIX alert (Jul 2026):** On the India VIX expandable section: toggle + threshold (defaults **enabled**, threshold **20**). Per-portfolio settings `indiavix_alert_enabled` / `indiavix_alert_threshold` via `PUT /api/settings`. **`IndiaVixAlertService`** runs after index price sync (`syncBatch`, `fillGapsBatch`, and successful `INDIAVIX` `syncOneSymbol`): if latest close is **above** the threshold and the alert is armed, notifies via `sendMessageForProfile` (respects `notifications_enabled` + credentials), then disarms; re-arms when VIX falls back to or below the threshold. Changing enable/threshold re-arms. Internal key `indiavix_alert_armed` (not exposed in settings UI). UI copy stays channel-agnostic (“notify”).

**India VIX ×100 scale fix (2026-07-29):** NSE charting sometimes returns India VIX OHLC scaled by 100 (e.g. close `1264.5` instead of `12.645`). That made alerts fire every day: scaled close ≫ threshold → notify/disarm, next sync corrected prior day below threshold → re-arm, repeat. Fix: **`IndiaVixScale`** divides OHLC by 100 when close is in `[100, 10000)`; applied in `NsePriceProvider` (INDIA VIX charting), `PriceFetchService::storeHistoricalRows`, and `IndiaVixAlertService::latestVixClose` (repairs DB on read). Provider chain for INDIAVIX is now **yahoo → nse → alpha_vantage**. One-off repair: `cpanel-repair-indiavix-scale.php?token=…` (dry-run) / `&apply=1` (writes + re-arms alerts).

**Multi-index OHLCV (Jul 2026):** Config `portfolio.indexes` (Nifty broad/sector, Sensex, BSE 100/200/500; **India VIX disabled**) — ~26 enabled `is_benchmark` rows ensured by **`IndexCatalogService`**. **`IndexPriceSyncService`** cursor-batches daily/backfill (`INDEX_PRICE_SYNC_BATCH_SIZE` default 3). Providers: NSE charting for indexes with `nse_charting_name`; BSE indexes use Yahoo → Alpha Vantage (no bhavcopy). **Volume:** benchmark/index OHLCV stores `volume` as **null** (NSE index charting reports aggregate constituent quantity that can exceed legacy `INT UNSIGNED` and is not used for RS). Migration `2026_07_16_000001` widens `portfolio_stock_prices.volume` to `BIGINT UNSIGNED` for large equity volumes. Schedule: **`portfolio:sync-index-prices --mode=daily`** daily at cron time; each **`portfolio:run-universe-maintenance`** tick also runs one index daily batch. Gaps: separate from equity inventory — UI **Market indexes** card + `POST /api/universe-price-sync/indexes/fill-gaps`. Initial 1y: `portfolio:sync-index-prices --mode=backfill --all` or browser `cpanel-backfill-index-prices.php` (`?token=&apply=1&mode=backfill`, optional `&all=1`, `&symbol=`, `&fill_gaps=1`). Env: `INDEX_PRICE_SYNC_ENABLED`, `INDEX_PRIMARY_SYMBOL`, `INDEX_PRICE_SYNC_BATCH_SIZE`, `INDEX_PRICE_SYNC_HISTORY_DAYS`, `INDEX_PRICE_SYNC_DELAY_MS`. API: `GET/POST /api/universe-price-sync/indexes/status|run|fill-gaps|reset-cursor`. **Stale index RCA (2026-07-27):** production NIFTY50 `price_to` stuck at 2026-07-09 while index daily batches reported `success`/`stored_rows=0`/`fetched_rows=0` — caused by suffix gaps being ignored in `getMissingHistoryRanges`; fixed so trailing ranges are fetched.
**OHLCV gap checker / repair (Jul 2026):** **`PriceHistoryGapService`** scans `portfolio_stock_prices` for missing edge ranges and internal gaps (`max_internal_gap_days`, default 7) across the universe history window (max of `history_days` and 6M analytics buffer). **Ignored gaps (Jul 2026):** table `portfolio_ignored_price_gaps` (`stock_id`, `gap_from`, `gap_to`); excluded from scan inventory, gap fill, and `getMissingHistoryRanges`. UI: tabular scan report (one row per gap) with **Ignore**; manage at `/settings/universe-price-sync/ignored-gaps`. API: `GET/POST /api/universe-price-sync/gaps/ignored`, `POST /api/universe-price-sync/gaps/ignore`, `DELETE /api/universe-price-sync/gaps/ignored/{id}`. **Fill failures page:** `/settings/universe-price-sync/gap-failures` (`GET /api/universe-price-sync/gaps/failures`) — moved off main sync page. **Fill report numbers:** `last_scan.with_gaps` = inventory size at scan time; after fill, `last_fill.still_with_gaps` = live re-check; `last_fill_failure_report.resolved` / `unresolved` = `inventory − still_with_gaps` (not the same as chunk `filled`/`failed`). Failure report rows include `exchange`. **BSE-only illiquid cleanup:** `cpanel-gap-analysis.php?deactivate_candidates=1`; `cpanel-deactivate-bse-unpriceable.php` dry-run/`&apply=1`. **Required-through session (Jul 2026):** gap scans use `TradingCalendar::lastRequiredPriceSession()` as the window end — on a weekday that is **prior completed session**, not today — so symbols are not flagged with a false `today → today` gap before nightly EOD sync. Status API exposes `required_through_session`. **Minimum gap span:** internal holes and **prefix** edge gaps require **>7 calendar days** (`max_internal_gap_days`) before they appear in scan results. **Suffix trailing gaps (Jul 2026 fix):** missing days after the last stored OHLCV through `requiredTo` / `required_through_session` are **always** reported by `getMissingHistoryRanges` so daily/index sync asks providers (previously ignored → false cache hits and stale NIFTY50); prefix min-span filter does **not** apply to suffix. **Pre-listing prefix gaps (Jul 2026):** when the first stored OHLCV session is after the universe window start, the prefix ending the day before that session is treated as pre-listing (IPO after window start) and is **not** inventoried — unless the user has transactions in that span or the stock master row is >180 days older than first OHLCV (incomplete backfill on an established listing). Example: `ADVANCE` gap `2025-07-09 → 2025-10-07` with first trade `2025-10-08` is skipped; providers correctly return 0 rows for pre-listing dates. **`portfolio:fill-price-history-gaps`** (`--scan-only`, `--all` for full-universe scan/fill, `--chain` for legacy cursor batches) processes cursor-based batches for nightly maintenance; fills via `fetchMissingHistory` (providers). Also repairs **NIFTY50** at the start of each fill batch. Admin UI: Settings → Universe price sync → **Price history gap checker** (`GET/POST /api/universe-price-sync/gaps/*`) — **Scan all gaps** / **Fill all gaps** / **Clear scan & reports** (`POST /gaps/clear` clears `price_history_gap_last_scan_json`, gap inventory, fill failure report, and last fill summary). **Scan all gaps** stores compact symbol rows with **`ranges`** (`from`/`to` dates per gap) in `price_history_gap_last_scan_json`. **Fill all gaps** persists `price_history_gap_fill_failure_report_json` after a full run (unresolved symbols, providers tried, errors; `last_fill_failure_report` on `GET /gaps/status`). **`fetchMissingHistory` success (Jul 2026 fix):** a symbol counts as resolved only when `getMissingHistoryRanges` is empty after the provider fetch — previously `success` was true when providers returned zero rows, so fill could report completion while ~1k+ symbols still gapped. Full fill-all run stores `still_with_gaps` after inventory verification. `fill_progress` persists in settings between chunks (not cleared when `in_progress` lock releases per chunk); frontend banner stays visible for the whole browser fill chain via `gapPending` + polled `fill_progress`. **Why fill can “timeout” on cPanel:** the web server/proxy kills HTTP requests after ~60–120s regardless of `set_time_limit(0)`; a single fill request that rescans the full universe (~6971 stocks) plus fetches many provider histories exceeds that limit — the sync run stays `running` and the UI shows a timeout toast; click **Fill all gaps** again to resume from saved cursor. **Stale lock auto-heal:** if fill lock remains `in_progress` while latest `price-history-gap-fill` run is still `running` for >8 minutes (or local lock older than ~3 minutes without a running run), status clears the lock automatically so the button is usable again. **Storage:** gap scan results persist compact symbol rows (no per-range JSON) in `portfolio_settings`; migration `2026_07_12_000001` widens `setting_value` to `LONGTEXT` so large universes do not hit MySQL `TEXT` limits after a full scan. Nightly maintenance still uses one `fillBatch()` per tick (cursor chains). Sync log job: `price-history-gap-fill`.

**Automated normal-day maintenance (Jul 2026):** Scheduler runs **`portfolio:run-universe-maintenance`** every **5** minutes during **19:00–23:45** (`cron_timezone`) when universe sync is enabled. Each run: (1) one universe **daily** price batch, (2) one **index** daily batch (`IndexPriceSyncService`, when enabled), (3) one **gap scan/fill** batch (`PriceHistoryGapService::fillBatch`) — cursor resets at **19:00** window start, then chains across the evening (~58 batches × 125 stocks ≈ full universe/night). Disable gap pass: `UNIVERSE_MAINTENANCE_GAP_FILL_ENABLED=false`. Extra gap batches still run when daily sync has failures (`UNIVERSE_MAINTENANCE_GAP_FILL_RETRIES`, default 2). CLI chain: `portfolio:fill-price-history-gaps --chain` (optional `--max-batches`, `--max-seconds`).

**Weekend skip + Friday-failure retry (Aug 2026, tightened):** Markets are closed Sat/Sun. **Daily market sync**, **benchmark sync**, and **index daily sync** skip weekends and admin trade holidays via `TradingCalendar::isScheduledMarketDataDay()` on the scheduler `when()` gate (manual UI/CLI unchanged). Scheduled universe maintenance also **skips weekends by default** (`UNIVERSE_MAINTENANCE_SKIP_WEEKENDS=true`). If the **last finished** `universe-price-sync` batch in the prior equity session’s maintenance window (typically Friday 19:00–23:45 IST) ended **failed/partial**, weekend slots **re-open** to heal (`UNIVERSE_MAINTENANCE_WEEKEND_RETRY_ON_FAILURES=true`) — earlier partials that later healed no longer trigger Saturday/Sunday runs. Manual UI/CLI runs are unchanged. Ops “maintenance overdue” (45‑min threshold) only applies when the calendar day is actually allowed to run. **Ops overdue Telegram (Aug 2026):** `universe_sync_overdue` and `daily_sync_overdue` are **not** raised on weekends/trade holidays when the prior session succeeded (universe: last Friday maintenance batch success → `allowsMaintenanceOnCalendarDay` is false; daily: Friday `daily-market-data` success). They still fire when that Friday run failed/partial (weekend retry expected). Failed/rate-limit/stock-master/scheduler alerts are unchanged.

**V5 FEAT-038 official exchange holidays (2026-09-04):** The existing global amber `trade_holiday` Calendar category and `TradingCalendar` are now fed by `NseHolidaySyncService` from NSE's official holiday-master `CM` segment. Weekly `portfolio:sync-nse-holidays` upserts future dates with source/external-key provenance, preserves already-begun dates in IST, and deactivates removed future rows only after a valid response. Editing or deleting an NSE row creates a durable admin override; manual Trade Holiday entry remains the fallback. Feed failures leave the canonical calendar unchanged.

**Rate-limit alert accuracy (Aug 2026):** `isLikelyRateLimited` no longer treats a high batch failure rate alone as throttling. Provider rate-limit alerts require `rate_limit_hits > 0` or ≥3 recent issues matching throttle patterns (403/429/etc.). Empty-provider storms (`returned 0 rows`) raise **Universe sync batch failed**, not rate-limit warnings.

**Hung batch recovery (Aug 2026):** When the in-progress lock is older than `UNIVERSE_STALE_LOCK_MINUTES` (default 30), `isSyncInProgress()` clears the lock **and** marks orphan `portfolio_sync_runs` still `running` as **failed** (`Abandoned: sync process did not finish…`). Exceptions mid-batch also `completeRun(..., failed)`. Diagnostic `cpanel-schedule-diagnostic.php?clear_in_progress=1` abandons running runs too. Ops overdue/failure checks use **finished** sync runs only (orphan `running` rows no longer look like healthy activity).

**Test-suite stabilization (Jul 2026, with history depth work):** fixed time-of-day flakes that failed between midnight and 05:30 IST — `BenchmarkPriceSyncServiceTest` and `SyncUniversePricesCommandTest` seeded `KEY_LAST_SYNC_DATE` with the UTC date while the service compares in `cron_timezone` (IST); `ExplorerAnalyticsTest` seeded anchor OHLCV rows on raw calendar dates that could land on weekends (now normalized via `TradingCalendar::normalizeToSessionDate`). Also: `TransactionUpdateTest` now fakes `BackfillHistoricalDataJob` (update dispatched a **live provider fetch**), and `RelativeStrengthServiceTest` was updated for the two-arg constructor. **V4-BUG-004 (2026-08-26):** `DailyMarketDataJobTest` no longer uses `RefreshDatabase` (pure Mockery) — removed the full-suite `db.schema` pollution flake.

**Archive OHLCV import (Aug 2026, completed):** One-off merge of external NSE archives into production `portfolio_stock_prices` as `data_source=archive_combined_nse` — **5,327,000** rows inserted (`insertOrIgnore`; existing live bars unchanged). Post-import: ~**67%** of active NSE equities have **5+ years** calendar span; NIFTY50 gaps limited to recent listings (**JIOFIN**, **TMPV**). BSE-only depth unchanged (NSE-only import). Local source folder, normalize/import tooling, and `cpanel-import-historical-ohlcv.php` removed after success.

**History depth backfill (Jul 2026):** **`HistoryDepthBackfillService`** + **`portfolio:backfill-history-depth`** deepen stored OHLCV beyond the rolling 365-day universe window to `HISTORY_DEPTH_TARGET_DAYS` (default **550** ≈ 18 months) so long-lookback indicators (SMA200, 52-week high, …) have bars at the **start** of a 1-year backtest instead of stocks being skipped for insufficient history. **Why a separate job:** the normal gap path suppresses prefix gaps as “pre-listing” (`isPreListingPrefixGap`); this service passes a new `includePreListingPrefix: true` flag through `fetchMissingHistory`/`getMissingHistoryRanges` so the older prefix is fetched — providers simply return nothing before the real listing date and the campaign moves on (counted `already_deep`, not failed). **Campaign design:** one re-armable pass — indices (all active `is_benchmark` rows) first in the opening batch, then the equity universe via the standard priority cursor (`history_depth_cursor_stock_id`/`_priority` in `portfolio_settings`); per stock only missing ranges are fetched (idempotent). Batch **25** stocks (`HISTORY_DEPTH_BATCH_SIZE`), 400ms delay (`HISTORY_DEPTH_DELAY_MS`). **Schedule: every 5 minutes ALL DAY** (not the maintenance window) gated by `isDue()` — enabled, not completed, no run in progress, and universe daily sync not mid-batch. ~288 runs × 25 ≈ 7,200 stocks/day → full universe in ~1 day. On cycle completion it writes `history_depth_completed_at` + `history_depth_completed_target_days` and goes idle; **raising `HISTORY_DEPTH_TARGET_DAYS` re-arms it automatically**, or `--reset` / cPanel `&reset=1` restarts. State/progress: `history_depth_progress_json` (campaign totals), `history_depth_last_run_json`, `history_depth_indexes_done_at`. Sync log job: **`history-depth-backfill`**. CLI: `portfolio:backfill-history-depth` (`--batch=`, `--reset`, `--status`). cPanel (no SSH): **`cpanel-history-depth.php`** — status `?token=Lido`, manual batch `&run=1[&batch=5]`, restart `&reset=1`; safe to leave on server during the campaign, delete after completion. BSE-only stocks deepen via the Yahoo fallback (bhavcopy is skipped for ranges >45 days). Fix along the way: `EquityUniverseService::applySyncPriorityOrder` errored on SQLite with no holdings/watchlists (`ORDER BY 0` = positional column) — now orders by `id` alone in that case, and `syncPriorityForStockId` returns the matching constant priority so cursor math stays aligned.

**Last batch card vs Sync Logs (Jul 2026):** Universe status “Last batch” used only `portfolio_settings.universe_price_sync_last_run_json`. If that settings write lagged, the UI could show an old `completed_at` while Sync Logs (`portfolio_sync_runs`) showed newer successful batches. `UniversePriceSyncService::lastRunStats()` now prefers the fresher of settings JSON vs latest finished `universe-price-sync` sync run (summary + completion log context for processed/ok/fail/stored/cache_hits/rate_limit_hits/cursor/cycle_completed) and heals the settings row when the sync log wins. `status()` also heals **cursor** (`universe_price_sync_cursor_stock_id` → Status card cursor + batch progress %), **rate_limits.last_run_hits** from healed `last_run`, and **latest_sync_run** from the same finished run so the “Sync log” timestamp matches Last batch.

**Last cycle stale timestamp fix (Jul 2026):** Universe status “Last cycle” originally read only `portfolio_settings.universe_price_sync_last_cycle_completed_at`, so it could stay stale even when a newer cycle completion signal existed in run payload/log context. `UniversePriceSyncService::status()` now resolves and heals `last_cycle_completed_at` from the freshest source among: setting key, `last_run` when `cycle_completed=true`, and latest `portfolio_sync_logs` row with `context.cycle_completed=true`. UI label clarified to **Last full cycle**.

**Daily lookback strategy (Jul 2026):** Universe daily sync uses a fixed lookback window (`UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS`, default 10). Missing ranges are still detected first; provider fetch runs only for detected gaps.

**Prerequisite:** stock master populated — `stocks:sync` CLI or **Settings → Universe price sync → Sync stock master** (or `POST /api/universe-price-sync/stock-master`).

| Command / API                                          | Purpose                                                                |
| ------------------------------------------------------ | ---------------------------------------------------------------------- |
| `portfolio:sync-universe-prices --mode=backfill --all` | Initial ~1 year history for entire scope (long run; rate-limited)      |
| `portfolio:sync-universe-prices --mode=backfill`       | Same window, one batch (repeat until cycle completes)                  |
| `portfolio:sync-universe-prices --mode=daily`          | Incremental sync (default lookback 10 days) for one batch              |
| `portfolio:run-universe-maintenance`                   | Daily batch + one gap-fill batch per tick (cursor chains nightly); extra retries on daily failures |
| `portfolio:sync-benchmark-prices`                      | Sync primary index (NIFTY50) OHLCV via IndexPriceSyncService           |
| `portfolio:sync-index-prices`                          | Sync all configured indexes (daily/backfill batches, `--fill-gaps`)    |
| `portfolio:refresh-index-constituents`                 | Refresh NSE broad/sector constituent caches (weekly + after `stocks:sync`) |
| `portfolio:fill-price-history-gaps`                    | Scan/fill OHLCV gaps in local history (`--scan-only`, cursor batches)  |
| `portfolio:backfill-history-depth`                     | Deepen OHLCV history to ~18 months for backtest lookback (`--batch=`, `--reset`, `--status`); auto-scheduled all day until complete |
| `portfolio:backfill-bse-bhavcopy`                        | Backfill BSE-only OHLCV from BSE bhavcopy (`--sync-scrip-codes`, `--dry-run`, `--days=`) |
| `portfolio:repair-dual-listed-nse`                       | Purge BSE duplicate OHLCV for dual-listed ISINs; refill NSE history (`--dry-run`, `--no-backfill`) |
| `portfolio:check-operational-alerts`                   | Evaluate sync health; Telegram admins; update operational alert flags  |
| `POST /api/universe-price-sync/run`                    | Same as CLI batch (cPanel-friendly; one HTTP request per batch)        |
| `GET /api/universe-price-sync/status`                  | Progress, coverage, cursor, rate-limit signals, recent provider issues |
| `POST /api/universe-price-sync/stock-master`           | NSE + BSE equity master import                                         |
| `GET /api/universe-price-sync/indexes/status`          | Index catalog progress, per-index gap/price bounds                     |
| `POST /api/universe-price-sync/indexes/run`            | Index daily/backfill batch (or single `symbol`)                        |
| `POST /api/universe-price-sync/indexes/fill-gaps`      | Fill gaps for indexes with missing ranges                              |
| `POST /api/universe-price-sync/indexes/reset-cursor`   | Reset index sync cursor                                                |
| `GET /api/indexes`                                    | List enabled benchmark indexes (Explorer selector; auth, non-admin)   |
| `GET /api/indexes/page`                               | Broad indexes with price metadata for Indices page                    |
| `GET /api/indexes/comparison`                         | Normalized multi-index % gain series (default 12 months)              |
| `GET /api/indexes/{symbol}/constituents`              | NSE index constituents (symbols + stock master names)               |

**Admin UI:** Settings → **Universe price sync** (`/settings/universe-price-sync`) — scope **All equities (NSE + BSE-only)** (`all_equities`). Status area uses three cards: **Universe cycle** (cursor pass: cycle progress %, processed/remaining counts, **Last batch completed** vs **Last full cycle completed** with ⓘ tooltips), **Last batch details** (per-batch metrics + **Finished full cycle** flag), **Configuration**. Plus **Market indexes (OHLCV)** card (table + daily/backfill/gap/reset controls). API `GET /universe-price-sync/status` includes `processed_through` and `remaining_in_cycle`. Other behavior unchanged (backfill chain, gap checker, stock master sync, toasts).

**Scope** (`UNIVERSE_PRICE_SYNC_SCOPE`, default `all_equities`):

- `all_equities` — active NSE rows + active BSE-only rows (BSE rows whose ISIN already exists on NSE are excluded)
- `all_nse` — **deprecated** alias for `all_equities` (accepted in API/CLI for backward compatibility)
- `nifty500` — intersection with NIFTY 500 NSE constituents (cached in `portfolio_settings` for 7 days)

**Rate limiting:** configurable delay between symbols (`UNIVERSE_PRICE_SYNC_DELAY_MS`, default 400ms); batches default 75 stocks/run. API throttled 12/min per admin. `rate_limit_hits` and `likely_rate_limited` on status when errors match 403/429/throttle patterns (**not** when failures are only empty provider rows). Telegram suppressed during batch (`PriceSyncNotificationContext::withoutTelegram`).

**Sync order (Jul 2026):** Universe OHLCV batches (and gap scan/fill cursor batches) process stocks in priority order across **all portfolios**: (1) open holdings (`quantity > 0`), (2) watchlist symbols not already in holdings, (3) remaining universe equities — then by `id` within each tier. Cursor advance / cycle completion / progress `%` use that same order (not raw `id` alone). Cursor also stores `universe_price_sync_cursor_priority` / `price_history_gap_cursor_priority` so mid-cycle holding/watchlist changes do not scramble the remaining queue. Daily holdings sync (`portfolio:daily-sync`) remains holdings-only and is unchanged.

**Schedule:** `portfolio:run-universe-maintenance` checks **`isMaintenanceWindowDue()`** every minute (explicit PHP timezone from `cron_timezone`, not host cron TZ) during **19:00–23:45** by default, running every **5** minutes (`UNIVERSE_MAINTENANCE_INTERVAL_MINUTES`, default 5). **Weekends skipped** unless prior session had failures (see weekend policy above). Default batch **125** stocks (`UNIVERSE_PRICE_SYNC_BATCH_SIZE`) ≈ **58 runs/night** ≈ **7,250 stocks/night** (enough for ~7k universe in one evening). Overlap guard: `withoutOverlapping(25)` + in-progress flag on `sync()`. Env: `UNIVERSE_MAINTENANCE_START_HOUR`, `UNIVERSE_MAINTENANCE_END_HOUR`, `UNIVERSE_MAINTENANCE_END_MINUTE`, `UNIVERSE_MAINTENANCE_SKIP_WEEKENDS`, `UNIVERSE_MAINTENANCE_WEEKEND_RETRY_ON_FAILURES`, `UNIVERSE_STALE_LOCK_MINUTES`. Cursor resumes across nights until cycle completes.

**Admin operational alerts (Jul 2026):** **`AdminOperationalAlertService`** monitors sync health and persists active issues in `portfolio_operational_alerts`. Detects: provider rate limits, universe sync overdue/failed, daily market sync overdue/failed, stock master weekly sync overdue/failed, and scheduler inactivity (no sync runs). Telegram notifications go to **all admin users** with Telegram configured on any portfolio (deduped by bot token + chat id); re-notify at most every **6 hours** per alert while still active (`ADMIN_OPS_ALERT_TELEGRAM_COOLDOWN_HOURS`). Command: `portfolio:check-operational-alerts` (hourly schedule). Also runs after daily sync, universe maintenance, stock master sync (**CLI and UI** `POST /universe-price-sync/stock-master`). **Weekend overdue silence (Aug 2026):** hourly checks still run Sat/Sun, but `universe_sync_overdue` / `daily_sync_overdue` are suppressed on closed sessions when Friday’s last relevant run succeeded — matching the job skip. Help: admin-alerts + universe-price-sync topics. **Ping when clear (Jul 2026):** global setting `admin_ops_telegram_ping_when_clear` (`portfolio_settings`, default `false`) — admin toggle on Settings → **Global** (“Ping Telegram when there are no alerts”). When enabled: (1) **`portfolio:send-notifications`** at each profile’s scheduled notification time sends a Telegram “no active alerts” confirmation if that portfolio has none (proves notification cron ran); (2) **`POST /api/operational-alerts/run-check`** sends admin sync-health confirmation when zero operational alerts. Hourly `portfolio:check-operational-alerts` and post-sync ops checks do **not** auto-send clear pings. Disable after testing. Settings → **Admin alerts** (`/settings/admin-alerts`) — **Dismiss** acknowledges (hides from “Needs attention” while issue persists); **Clear off** manually resolves with `manually_cleared_at` suppression so the alert stays hidden until the underlying issue is fixed and then recurs. API: `POST /operational-alerts/clear`, `POST /operational-alerts/clear-dismissed`. **Warning toast:** when an admin loads the dashboard from the **server** (cache miss, **Refresh dashboard**, or post-mutation refetch — not when serving `localStorage` cache), `showAdminOperationalAlertsToastIfAny()` calls `GET /api/operational-alerts` and shows a warning toast if `unacknowledged_count > 0`. **Universe price sync** page still shows a compact operational alerts card with link to admin alerts. API: `GET /api/operational-alerts`, `POST /api/operational-alerts/acknowledge`, `POST /api/operational-alerts/acknowledge-all`, `POST /api/operational-alerts/run-check`; universe status still includes `operational_alerts`. Migration: `2026_07_06_000001_create_portfolio_operational_alerts_table.php`. Env thresholds: `ADMIN_OPS_DAILY_SYNC_STALE_HOURS` (36), `ADMIN_OPS_UNIVERSE_SYNC_STALE_HOURS` (26), `ADMIN_OPS_UNIVERSE_SYNC_STALE_MINUTES` (45 during 19:00–23:45), `ADMIN_OPS_STOCK_MASTER_STALE_DAYS` (8), `ADMIN_OPS_SCHEDULER_DEAD_HOURS` (48). **Universe overdue during maintenance window (Jul 2026 fix):** staleness is measured from the **current day's 19:00 window start** (or last run within that window), not from the previous morning/overnight run — avoids a false “overdue” alert at 19:00 when the last batch was hours earlier but before tonight's window. Dismiss sets `acknowledged_at` until the condition clears or re-triggers.

**Services:** `EquityUniverseService`, `UniverseStockResolverService` (wrapper), `Nifty500ConstituentService`, `UniversePriceSyncService`, `BseEquityMasterService`. Sync log job name: `universe-price-sync`.

**Env (optional):** `UNIVERSE_PRICE_SYNC_ENABLED`, `UNIVERSE_PRICE_SYNC_SCOPE`, `UNIVERSE_PRICE_SYNC_HISTORY_DAYS` (default 365), `UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS` (default 10), `UNIVERSE_PRICE_SYNC_DELAY_MS`, `UNIVERSE_PRICE_SYNC_BATCH_SIZE`, `UNIVERSE_MAINTENANCE_SKIP_WEEKENDS`, `UNIVERSE_MAINTENANCE_WEEKEND_RETRY_ON_FAILURES`, `UNIVERSE_STALE_LOCK_MINUTES`.

### API endpoints (auth required)

| Method       | Path                                     | Purpose                                 |
| ------------ | ---------------------------------------- | --------------------------------------- |
| GET          | `/api/stocks/search?q=&exchange=&limit=` | Local master autocomplete (min 2 chars; effectively active only) |
| POST         | `/api/stocks/validate`                   | Explicit validation + persist           |
| GET/POST/PUT | `/api/stocks`                            | List / create (validated) / update (metadata only on PUT) |
| GET          | `/api/admin/stocks` (admin)              | Paginated catalogue with status filter (`all`, `active`, `inactive`, `admin_deactivated`) |
| POST         | `/api/stocks/{id}/activate` (admin)      | Clears `admin_deactivated`; does not change `is_active` |
| POST         | `/api/stocks/{id}/deactivate` (admin)    | Sets `admin_deactivated`; does not change `is_active` |

**V4-FEAT-011 Stocks admin SPA (VERIFIED COMPLETE; PRODUCTION VERIFIED 2026-09-02):** Admin page `/settings/stocks` — searchable, paginated catalogue of non-benchmark `portfolio_stocks` with system vs admin vs effective status columns. Activate/Deactivate use dedicated admin endpoints only. Public `/api/stocks` and `/api/stocks/search` continue to return effectively active equities only. Transaction `resolveCanonicalStock()` / `resolveByStockId()` keep raw `is_active` semantics. Stock-master sync and provider validation may still update `is_active` but never write `admin_deactivated`. Manual GitHub Actions run [`33544994802`](https://github.com/lido-alexion/LidoPortfolio/actions/runs/33544994802) passed `StockAdminTest`, `StockSearchTest`, `EquityUniverseServiceTest`, and `stocksAdmin.test.jsx` on PHP 8.4 / Node 22 with isolated in-memory SQLite. Production deploy verification passed on PHP 8.4.23: `migrate --force` applied `2026_09_01_000001_add_admin_deactivated_to_portfolio_stocks`, all cPanel checks passed, the browser loaded `assets/app-DKEolVAa.js`, and the Product Owner confirmed the deployed pages were working.

## V4-FEAT-030 — full CI gate (2026-09-04)

**Status:** **COMPLETE.** `.github/workflows/ci.yml` is the required repository verification gate for pushes to `master`, pull requests, and manual runs. The backend job installs locked Composer dependencies on PHP 8.4, creates an isolated SQLite test environment, and runs the complete Laravel test suite. The frontend job uses Node 22 with `npm ci`, runs both JavaScript test layers, and creates the production Vite build. Composer and npm caches are keyed by their lock files.

The first broad run correctly exposed two previously hidden test-contract defects: market-gate fixtures produced a ₹3,000 first tranche below OD-12's ₹5,000 minimum actionable amount, and the OpenAPI test incorrectly required a Sanctum cookie on Kite's external redirect callback. Production rules were preserved: the fixture now creates an actionable tranche, while the callback is explicitly documented as the sole public `/api/v1` operation authenticated through short-lived encrypted state. Local verification passed **1,322 tests / 8,048 assertions**; GitHub Actions run [`33865499081`](https://github.com/lido-alexion/LidoPortfolio/actions/runs/33865499081) passed PHP 8.4 and Node 22 on commit `e09a9ff`.

## V4-FEAT-031 — external secrets and single-folder deploy (2026-09-04)

**Status:** **IMPLEMENTED; PRODUCTION CUTOVER PENDING.** Frozen behavior and acceptance criteria: [`specs/V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md`](specs/V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md). Operator runbook: [`deploy/SINGLE-FOLDER-DEPLOY.md`](deploy/SINGLE-FOLDER-DEPLOY.md).

`App\Support\ProductionEnvironment` resolves `LIDO_ENV_PATH` or the outermost readable ancestor `config/LidoPortfolio.env`; `bootstrap/app.php` directs Laravel to that file while retaining normal `.env` fallback. The FEAT-031 front controller boots `portfolio/laravel` and binds `public_path()` to the containing `portfolio/`, eliminating the second Vite build. Parent rewrite rules deny `laravel/` and dot paths before real-directory pass-through, while the nested deny-all file remains defense in depth. `prepare-single-folder-upload.ps1` builds and assembles an upload while refusing secret files, the Vite hot marker, a local DB template, or a duplicate nested build. All cPanel helpers resolve the new root first and retain the legacy root fallback.

## V4-FEAT-033 — Discovery inline default screener (2026-09-04)

**Status:** **COMPLETE.** Frozen behavior: [`specs/V5-FEAT-033-Discovery-Inline-Default-Screener.md`](specs/V5-FEAT-033-Discovery-Inline-Default-Screener.md). `CandidatesPage` loads the existing portfolio Screener list, selects the stable Minervini factory key, and presents enabled state, scope, description, latest status, and matched/scanned totals above the Discovery metrics. The existing chunk-aware Screener run API can be invoked inline; completing it refreshes the summary and explicitly leaves candidate regeneration to **Run discovery**. Missing, disabled, or misconfigured factory screeners have non-destructive recovery/status states. No backend, schema, engine, scheduling, or Strategy behavior changed.

### Autocomplete UX

- `StockAutocomplete.jsx` — debounced search; omit `exchange` param to search NSE+BSE; shows `exchange_label` (`NSE+` for dual-listed); optional `clearOnBlur` clears typed text when the field loses focus without a selection (used by Watchlist quick-add)
- `TransactionsPage.jsx` — requires selection or validated symbol; no datalist free-text; **NSE+/BSE toggle** is fee-only (autocomplete not filtered); validation success shows `exchange_label`
- `StockExplorerPage.jsx` — no exchange toggle; stock search across NSE+BSE; selected stock label shows `NSE+` when dual-listed
- `WatchlistPage.jsx` — shows `exchange_label` instead of raw `exchange`
- `BulkTransactionImport.jsx` — per-row NSE/BSE toggle on review step
- Unknown symbol on save triggers backend provider validation

### Rate limits & security

- `stock-search`: 60/min per user
- `stock-validate`: 15/min per user
- No provider calls on page load — only search (local DB), validate, add, and scheduled sync
- Input sanitization and malformed-symbol rejection via `ProviderResolverService::isMalformed()`

### Validation retry logic

- NSE: `nse_retry_count` from settings (default 3) with incremental backoff (`usleep`)
- Yahoo/Alpha: single attempt each after NSE exhaustion
- All failures logged to `provider` channel via `PortfolioLoggerService`

### Revalidation

- `last_verified_at` set on provider upsert and master sync
- `STOCK_REVALIDATION_DAYS` (default 7) in `config/portfolio.php` for future explicit revalidation job
- Standard `validate()` does **not** re-call providers when local row exists (performance)

### Known provider limitations

- NSE endpoints may block datacenter IPs; Yahoo/Alpha used as fallback
- Alpha Vantage rate limits (`Note` / `Information` responses treated as failure)
- BSE master uses BSE API by default; optional `BSE_EQUITY_CSV_URL`. Rows without a text trading symbol (scrip-code only) are skipped

### Tests

- `tests/Unit/ProviderResolverServiceTest.php`
- `tests/Unit/StockValidationServiceTest.php` (Http::fake)
- `tests/Unit/EquityUniverseServiceTest.php`, `tests/Unit/BseEquityMasterServiceTest.php`, `tests/Unit/StockMasterBseDedupTest.php`
- `tests/Feature/StockSearchTest.php`
- `tests/js/debounce.test.mjs` (`npm run test:js`)

### Future stock validation changes

Document provider, schema, or UX changes in this section.

## Historical Data & Exploratory Analytics (May 2026)

### Hybrid history architecture

| Type                | Detection (`StockTrackingService`)                                        | Fetch behavior                                                                        |
| ------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Portfolio / tracked | Holdings qty &gt; 0, alerts, `tracking_active` metrics, past transactions | `ensurePortfolioHistory(buyDate)` → **buy − 3 months** → today; incremental gaps only |
| Exploratory         | Not tracked                                                               | `ensureAnalyticsHistory(months)` → ~60d (1M) / ~150d (3M) buffer; cached permanently  |

**Never delete** OHLCV when buy date changes or stock is sold. Wider local history is acceptable; gaps are not.

### Local cache strategy

- DB (`portfolio_stock_prices`) is the **primary** analytics source.
- Providers are **gap-fillers** via `PriceFetchService::fetchHistoricalWithFallback()` called only from `StockPriceHistoryService::fetchMissingHistory()`.
- Cache hit: missing ranges empty → log + skip HTTP (`cache_hit: true`).
- Cache miss: fetch only missing `{from,to}` segments; merge adjacent ranges; optional internal gap detection (`max_internal_gap_days`).

### Incremental fetch flow

1. `getAvailableHistoryRange(stock)` → min/max `price_date` or null.
2. `getMissingHistoryRanges(stock, requiredFrom, requiredTo)` → **prefix** edge gaps (still subject to pre-listing skip + min-span filter) and **suffix** trailing gaps after last stored bar through `requiredTo` (always reported so sync asks providers — Jul 2026 fix for stale NIFTY50 / false cache hits), plus internal gaps if &gt;7 calendar days between rows.
3. `fetchMissingHistory()` → provider fetch per range → `updateOrCreate` rows (no mass delete).

`PriceFetchService::syncStock()` delegates to `fetchMissingHistory()` (daily cron uses same path).

### Buy backfill

`BackfillHistoricalDataJob` → `ensurePortfolioHistory($stock, $buyDate)` (not raw buy date only).

### Nearest trading day

```sql
WHERE stock_id = ? AND price_date <= ? ORDER BY price_date DESC LIMIT 1
```

Implemented as `getCloseOnOrBeforeDate()`; uses `adjusted_close_price` fallback to `close_price`.

### RS & growth

- Growth %: `(close_end - close_start) / close_start * 100` using on-or-before dates.
- RS: `stock_growth% - benchmark_growth%` (simple difference per product spec).
- `RelativeStrengthService` delegates to `StockPriceHistoryService`.

### Exploratory analytics flow

1. User opens `/explorer` (**Stock Analytics Explorer**) → autocomplete → **Run Analysis** (no period toggle).
2. `POST /api/analytics/explore` → `ExploratoryAnalyticsService::analyze()` — **1, 3, 6, and 12 months**; benchmark NIFTY50.
3. Symbol resolved from **local stock master only** (`validate(..., allowProvider: false)`); no on-demand provider fetch or backfill trigger.
4. Price history read from **`portfolio_stock_prices`** populated by **universe price sync** (`getCachedAnalyticsHistoryStatus` — no provider fetch). Warns in UI when cache incomplete.
5. Response includes `latest_close`, `period_closes.{1m,3m,6m,12m}`, `growth_percent`, `benchmark_growth_percent`, `relative_strength`, Recharts bar chart (4 periods: 1M/3M/6M/1Y), and **`normalized_gain_chart`** — daily % gain from the 12-month start close for stock and benchmark (line chart).
6. UI shows latest close for stock and benchmark, **historical start-close tables** (stock + index; period label + price only, no start date column) for 1M/3M/6M/1Y, **four RS cards** (1M / 3M / 6M / 1Y) color-coded green/red, bar chart, and 1-year normalized % gain line chart.
7. If any RS-required input is missing for any period, Explorer shows **Manual Relative Strength Input** (6-month period closes); available values prefilled from cache.
8. If analyze API returns validation failure (422, symbol not in local master), API returns user-facing `message` via `StockValidationUserMessage`. UI prefers `message` over raw `errors[0]`.
9. Manual form submission computes RS in-memory for 6M only (`stock_growth - benchmark_growth`); not stored in DB.
10. NIFTY50 Yahoo symbol is `^NSEI` (`ProviderResolverService` always corrects benchmark row; not `NIFTY50.NS`).
11. Explorer Run Analysis button disabled until a symbol is entered/selected.
12. When analysis fails and user uses manual RS inputs, Explorer renders summary + 6-bar chart (6M manual values where applicable).

Rate limit: `analytics-explore` 20/min.

### Schema migration

`2026_05_30_000001_extend_stock_prices_history.php` — `adjusted_close_price`, `provider_source` (copied from `data_source`).

### Config (`config/portfolio.php`)

```php
'history' => [
    'portfolio_lookback_months' => 3,
    'analytics_buffer_days' => ['1m' => 60, '3m' => 150, '6m' => 210],
    'max_internal_gap_days' => 7,
],
```

### Tests

- `tests/Unit/StockPriceHistoryServiceTest.php`
- `tests/Unit/StockTrackingServiceTest.php`
- `tests/Feature/ExplorerAnalyticsTest.php`

### Future changes

Document cache, retention, or analytics behavior changes in this section.

## Historical snapshot rebuild architecture (May 2026)

Report: `portfolio-history-rebuild-report.md`.

### Philosophy

`portfolio_portfolio_snapshots` rows are **materialized, rebuildable cache** — not append-only cron logs. The dashboard growth chart answers: _“What was my portfolio worth on date D given all transactions known today?”_

### Services

| Service                              | Role                                                                                                             |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `PortfolioHistoricalHoldingsService` | Replay transactions with `transaction_date <= D` → open qty + cost basis per stock                               |
| `PortfolioSnapshotRebuildService`    | `calculatePortfolioStateForDate()`, `rebuildDateRange()`, `rebuildFromDate()`, `rebuildAfterTransactionChange()` |
| `StockPriceHistoryService`           | Gap-fill OHLCV before rebuild (`fetchMissingHistory`)                                                            |
| `StockQuoteService`                  | `latestClose(stock, asOf)` — close on or before D                                                                |

### Formulas (any historical date D)

1. **Holdings(D)** — all transactions ≤ D (buys add price×qty cost basis + qty; sells reduce qty; avg-cost invested amount; fees excluded from cost basis).
2. **portfolio_value(D)** — `SUM(quantity(D) × latest_close_on_or_before(D))` per open holding.
3. **invested_value(D)** — `SUM(remaining_cost_basis(D))` for open holdings.
4. **unrealized_pnl(D)** — `portfolio_value(D) − invested_value(D)`.

Nearest trading day: `WHERE price_date <= end_of_day(D) ORDER BY price_date DESC LIMIT 1` (weekends/holidays use prior session close). Weekend `price_date` rows from providers are ignored on ingest and when resolving closes (`TradingCalendar`). Upper-bound `price_date` filters use `endOfDay()` so same-day rows stored with a time component are not excluded (fixes flat/wrong “today” closes in snapshots).

**Portfolio growth chart dips (Jun 2026):** Yahoo sometimes stores Saturday/Sunday `price_date` rows; rebuild used those as trading days → bogus weekend snapshots with stale/wrong closes (both notional and invested could dip). Fix: skip weekends in `resolveTradingDates` / `closeFromIndex`, purge weekend snapshots on rebuild, skip weekend rows on price ingest, and use inclusive end-of-day date bounds in price queries.

### Rebuild triggers (mandatory)

After **any** transaction **create / update / delete**, `TransactionController` calls `rebuildAfterTransactionChange()` with:

`affected_start = MIN(old_transaction_date, new_transaction_date)` → rebuild **affected_start → today**.

Daily cron (`portfolio:daily-sync`) still refreshes **today** via `storeSnapshot()` → `rebuildDateRange(today, today)` but is **not** the sole source of history.

### Rebuild algorithm

1. Load ordered user transactions.
2. For each symbol, `fetchMissingHistory` from `min(first_tx, range_start)` → today (no silent skip).
3. Build trading-day list = distinct weekday `price_date` in range for held symbols + today (weekends excluded).
4. For each trading day: compute state → `updateOrCreate` snapshot; purge legacy weekend snapshots in range.
5. Log start/end, counts, missing closes, duration (`SnapshotRebuild` category).

### API

| Method | Path                             | Purpose                                                       |
| ------ | -------------------------------- | ------------------------------------------------------------- |
| GET    | `/api/portfolio/snapshots`       | List materialized snapshots (`from_date`, `to_date`, `limit`; profile-scoped) |
| POST   | `/api/portfolio/rebuild-history` | Manual full/partial rebuild (`from_date`, optional `to_date`) |

### Frontend

After transaction save/delete, `notifyPortfolioDashboardRefresh()` → Dashboard reloads `portfolio_growth` (latest 365 days, ascending). If snapshots are empty but transactions exist, `GET /dashboard` triggers a one-time lazy rebuild. **Portfolio Growth** card header shows **View snapshots** (→ `/portfolio/snapshots`) and **Rebuild history** (browser `confirm` before `POST /portfolio/rebuild-history`).

**F015 — Portfolio Snapshots UI (2026-08-09):** Dedicated page at `/portfolio/snapshots` (sidebar: Portfolio → Portfolio Snapshots). `GET /api/portfolio/snapshots` with range presets (90/180/365 days, All up to 2000 rows). Displays backend `portfolio_value` / `invested_value` only; unrealized P/L and day change are display derivatives. Components: `PortfolioSnapshotsPage`, `PortfolioSnapshotGrowthChart`. Tests: `tests/Feature/PortfolioSnapshotApiTest.php`. No schema changes.

### Tests

- `tests/Unit/PortfolioHistoricalHoldingsServiceTest.php`
- `tests/Feature/PortfolioSnapshotRebuildTest.php`
- `tests/Feature/PortfolioSnapshotApiTest.php`
- `tests/Feature/DashboardGrowthTest.php`

### Future snapshot / history changes

Document in this section and `portfolio-history-rebuild-report.md`.

## Authentication Architecture (May 2026)

**V2 Account & Access (2026-08-09):** Spec pack for **F003** (User Invite) and **F005** (Session Management) — [`docs/v2/F003-F005-BOUNDARY.md`](docs/v2/F003-F005-BOUNDARY.md), [`F003-USER-INVITE-SPEC.md`](docs/v2/F003-USER-INVITE-SPEC.md), [`F005-SESSION-MANAGEMENT-SPEC.md`](docs/v2/F005-SESSION-MANAGEMENT-SPEC.md), [`F003-F005-POLICY-DECISIONS.md`](docs/v2/F003-F005-POLICY-DECISIONS.md), [`F003-F005-IMPLEMENTATION-GAP-MATRIX.md`](docs/v2/F003-F005-IMPLEMENTATION-GAP-MATRIX.md). Indexed in `DOCS.md` §3.D.

**Current tracking:** **F003 = COMPLETE** (`F003_COMPLIANT_WITH_NON_BLOCKERS`). **F005 = COMPLETE** (`F005_COMPLETE_WITH_NON_BLOCKERS`). F003 does **not** own password-change/reset session revocation (that is F005 / PD-006).

### F003 — User Invitation Security Hardening (2026-08-09) — COMPLETE

Implemented PD-004 / PD-005 only (not F005 / PD-006).

**Compliance:** Final compliance audit verdict **`F003_COMPLIANT_WITH_NON_BLOCKERS`** — no MUST failures; F003 formally closed.

**Capabilities**
- Invitation bearer tokens: CSPRNG raw token; store **SHA-256 hash** only in `portfolio_user_invites.token` (varchar 64, no column rename).
- Raw URL returned only on create and explicit regenerate; list payloads never reconstruct a URL.
- Regenerate: same row, replace hash, **preserve original `expires_at`**, old URL invalid; admin confirm UX.
- Login with pending invite: **no** auth/session/`invite_token`; returns `invite_setup_required` + administrator-link message. SPA shows message only (no `/invite/{token}` navigation).
- Accept creates user + first session; no F005-style session revocation.

**Migration / deploy (pending invites):** `2026_08_09_120001_harden_portfolio_user_invite_token_hashes`

- **Intentional / destructive for pending rows:** deletes all invitations with `accepted_at` null (pending and expired-unaccepted).
- Existing pending invitation **URLs no longer work** after this migration runs.
- Administrators **must re-issue** any still-needed invitations after deploy + migrate.
- Accepted invitation rows are retained; stored token values are scrambled to random hashes (irreversible; not usable as URLs).
- `down()` does **not** restore deleted pending invitations. Not zero-downtime data-preserving migration.

**Auth fix (shared login path):** `AuthController` uses `Auth::guard('web')->attempt` and `Auth::forgetGuards()` on logout so Sanctum’s default-driver cache does not break re-login or leave stale identity (tests/Octane). Not F005 revoke-others.

**Tests:** `UserInviteTest` covers hash storage, validate/accept, rotation/expiry, authz, PD-005 login. Related: `AuthSessionTest`, `PasswordResetLinkTest`, `AuthCsrfLoginTest`. Residual AC/test/UX non-blockers are listed in the gap matrix (not MUST failures).

**UI:** `UserManagementPage` — Copy Invitation URL banner vs Regenerate Invitation URL + confirm; `LoginPage` pending-invite message; help in `appDocumentation.js` Users topic.

**Out of F003 (delivered under F005):** password-change/reset session revocation (PD-006), Active sessions help (F005-G014). **Still deferred:** admin force-logout (PD-007).

### F005 — Session Management Hardening (2026-08-09) — COMPLETE

Verdict: **`F005_COMPLETE_WITH_NON_BLOCKERS`**. Preserves shipped list/revoke/logout-others; implements **PD-006**.

**PD-006 password change** (`PUT /api/profile/password`): after successful update — keep current session; `SessionManagementService::revokeOtherSessionsForCredentialChange` deletes other DB session rows and rotates `users.remember_token`; response message notes other devices signed out; returns `sessions_removed`. Failed validation does not revoke.

**PD-006 password reset** (`PasswordResetAcceptController::accept`): after F004 accept — rotate `remember_token`, then `login` + `session()->regenerate()`, then `destroyOtherSessions` for all ids except the new session id (token already rotated — do not double-call full revoke helper). Message: other devices signed out. Invalid accept does not revoke.

**Remember-me mechanism (auditable):** Laravel stores one `remember_token` per user. Rotating it invalidates every outstanding remember-me cookie for that account. Surviving session continues via the session cookie. Deleting session rows alone is not sufficient for remember-me.

**Invitation accept:** unchanged — first session only; **no** PD-006 revoke-others (`InviteAcceptController` does not call session revocation).

**Manual F005 (unchanged ownership):** GET `/api/auth/sessions`, DELETE `/api/auth/sessions/{id}`, POST `/api/auth/sessions/logout-others`, current-session logout via AuthController.

**Tests:** `AuthSessionTest`, `ProfileTest` (PD-006 change), `PasswordResetLinkTest` (PD-006 reset), `UserInviteTest` (no `sessions_removed` on accept).

**UI / help:** `ProfilePage` success toast; Settings Active sessions UI unchanged; `appDocumentation.js` Profile + Settings Active sessions concepts.

**Non-blockers:** no frontend automated UI tests (build + source inspection); PHPUnit DELETE-current full cookie logout path is flaky under Sanctum test client — covered by service refuse-current + AuthController logout branch; single `remember_token` means current device’s remember cookie is also invalidated (session cookie remains).

**Not in scope:** PD-007 admin force-logout, PAT/JWT, RBAC, SMTP.

### Stack (mandatory)

- **Laravel Sanctum** SPA mode (`bootstrap/app.php` → `statefulApi()`)
- **Session guard** (`web`) — not Bearer tokens in JS
- **HTTP-only cookies** + `axios` `withCredentials: true`
- **CSRF** — `GET /sanctum/csrf-cookie` then `GET /api/auth/csrf-token`; mutations send `X-CSRF-TOKEN` (plain session token). Cookie `X-XSRF-TOKEN` is still set but not trusted client-side on subdirectory deploy (stale path `/` cookies on some mobile browsers).
- **Remember Me** — `Auth::attempt($credentials, $remember)`

### What we removed

- `localStorage.portfolio_token` and `Authorization: Bearer` headers
- API token returned from login/register responses

### Session configuration

| Setting                    | Default                          | Purpose                                                                                                     |
| -------------------------- | -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `SESSION_DRIVER`           | `database`                       | Multi-server friendly on cPanel                                                                             |
| `SESSION_LIFETIME`         | `43200`                          | ~30 days sliding idle timeout                                                                               |
| `SESSION_SECURE_COOKIE`    | `true` in production             | HTTPS only                                                                                                  |
| `SESSION_SAME_SITE`        | `lax`                            | CSRF mitigation                                                                                             |
| `SESSION_PATH`             | derived from `APP_URL` path      | Subdirectory deploy (`/portfolio`) scopes cookies so they do not collide with other apps on the same domain |
| `SESSION_DOMAIN`           | `.your-domain.com` in production | Same login cookies on `www` and apex host                                                                   |
| `SANCTUM_STATEFUL_DOMAINS` | localhost + app host             | Sanctum treats requests as SPA                                                                              |

### Frontend flow

1. `AuthProvider` mounts → `ensureCsrfCookie()` then `GET /api/auth/me` restores user or shows login.
2. Login page → `ensureCsrfCookie({ force: true })` clears stale `XSRF-TOKEN` at `/` and `/portfolio`, hits `/sanctum/csrf-cookie`, then **always** loads the session token from `GET /api/auth/csrf-token` and sends `X-CSRF-TOKEN` (avoids reading a wrong cookie from `document.cookie` on mobile).
3. On `401` while logged in → `portfolio-unauthorized` → inline “session expired” on login (no toast); save path in `sessionStorage`. Initial `/auth/me` 401 (first visit / not logged in) is silent. `419` on API mutations retries once after forced CSRF refresh; login/invite accept skip the warning toast (AuthContext also retries login once).
4. **Subdirectory URLs:** JS resolves API paths from `<meta name="app-base">`, `window.__LIDO_APP_BASE__`, or `/portfolio` in the URL. `api.js` sets `baseURL` on every request (not only at import time).
5. After login → redirect to saved path (`auth/redirect.js`).

### API (session guard)

- `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`
- `GET /api/auth/me` — **guest-safe** (returns `{ user: null }` when logged out; not behind `auth:sanctum`)
- `GET /api/auth/csrf-token` — **guest-safe**; returns `{ token }` (session CSRF token) when cookie is not readable client-side
- `GET /api/auth/sessions`, `POST /api/auth/sessions/logout-others`, `DELETE /api/auth/sessions/{id}` — auth required

### Active sessions

- `SessionManagementService` reads `sessions` table (device label from user-agent).
- Settings UI: list sessions, revoke one, log out all other devices.
- `DELETE /api/auth/sessions/{id}` — cannot revoke another user's session.
- Multi-device simultaneous sessions allowed; `personal_access_tokens` table retained for legacy but login does not issue API tokens.

### Security

- Login/register: `throttle:login` (10/min/IP).
- `AuthAuditService` logs success/failure with masked email (no passwords/cookies).
- Compatible with future PIN/biometric/2FA (not implemented).

### Tests

- `tests/Feature/AuthSessionTest.php`
- `tests/js/auth-redirect.test.mjs`
- All feature tests use `$this->actingAs($user)` instead of `Sanctum::actingAs`.

### HTTPS / production cookies

1. Force HTTPS on the domain (cPanel AutoSSL + redirect).
2. `.env`: `APP_URL=https://your-domain/portfolio` (include subdirectory path), `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.your-domain.com`, `SANCTUM_STATEFUL_DOMAINS` = hostnames without scheme.
3. `SESSION_PATH` auto-derives from `APP_URL` (`/portfolio`); run `php artisan config:cache` after `.env` changes.
4. Run `php artisan migrate` so `sessions` table exists.
5. Serve SPA and API from the same origin (`app/public` document root or `/portfolio` entry).
6. **419 / CSRF mismatch on some devices:** often stale or colliding `XSRF-TOKEN` at cookie path `/`, or mixing `www` vs apex. Deploy latest `csrf.js` + `/api/auth/csrf-token` fallback; run `config:cache`; user clears site cookies once; always open the same hostname (`https://www.lidoalexion.com/portfolio/`). SSL warning in the address bar blocks `Secure` cookies — fix AutoSSL/redirect first. Upload table: `deploy/DEPLOY.md` §7 “Login loops / 419”.

See also `DEPLOYMENT_CPANEL.md` § HTTPS.

### Future authentication changes

Document in this section. Authenticator TOTP for broker execution shipped as V4-FEAT-001; login itself is still password + Sanctum session (no TOTP at login).

### Tests (multi-portfolio, Jun 2026)

- PHPUnit suite uses `CreatesPortfolioProfiles` (defaultPortfolioFor, createPortfolioProfile, withProfileHeader). Portfolio rows in tests use `profile_id`; settings tests use `ProfileSettingsService` (`ProfileSettingsTest` replaces `UserSettingsTest`).
- `PortfolioMiddlewareTest`: default portfolio when `X-Profile-Id` omitted, header scoping, foreign profile 404, parallel profiles return different transaction counts.
- `bootstrap/app.php` requires `app/Support/helpers.php` (until `composer dump-autoload` picks up the file autoload entry) and sets middleware **priority** so `ResolveActivePortfolio` runs before `SubstituteBindings` (route model binding for transactions/alerts needs `activePortfolio()`).
- API controllers call `\activePortfolio()` (global helper) in namespaced classes.

- **Market Breadth update (2026-07-28):** Removed NSE/BSE toggle and BSE index rows. Calculations now run on NSE/NSE+ constituents only (BSE-only excluded). Date dropdown reloads only table data, chart remains fixed to Nifty 50 history.

- **Universe Price Sync fix (2026-07-28):** Restored missing ormatGapRangeList import in UniversePriceSyncPage.jsx to prevent post-load React crash on /settings/universe-price-sync (error: ormatGapRangeList is not defined).
