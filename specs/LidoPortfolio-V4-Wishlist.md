# LidoPortfolio V4 Wishlist

| Field | Value |
|-------|-------|
| **V3 Status** | **V3 STRICTLY COMPLETE** (strict register-to-implementation pass 2026-08-26) |
| **Document type** | Forward-looking V4 register + V5 deferred features (same genuine-new-work pool) |
| **Created** | 2026-08-25 |
| **Last reconciled** | 2026-09-02 (V4 closed and FEAT-011 production-verified) |
| **Canonical path** | [`specs/LidoPortfolio-V4-Wishlist.md`](LidoPortfolio-V4-Wishlist.md) |
| **Related** | [`LidoPortfolio-V3-Specification.md`](LidoPortfolio-V3-Specification.md) · [`../implementation.md`](../implementation.md) |

## Purpose

This register holds **only**:

1. **Genuine new post-V3 functionality** that was not part of V3 normative scope (active V4 FEAT IDs and V5-deferred FEAT IDs), **or**
2. **Genuine V4 product/spec decisions** for rules V3 never froze. **V4-SPEC-001 through V4-SPEC-007 are now FROZEN Product Owner decisions**. Frozen means the rule is specified. Implementation is separate: **SPEC-007** is implemented by FEAT-001; **SPEC-001** same-stock adoption merge, **SPEC-003** split/bonus restatement, **SPEC-004** cash-ledger LOAN/RECALL/BRIDGE, **SPEC-005** explicit cross-owner SELL attribution, and **SPEC-002** rights-are-not-a-CA (exercise = normal purchase) are implemented 2026-08-28; **SPEC-006** remains unimplemented.

It is **not** a deferral bin for V3 bugs, V3 technical debt, V3 UX polish, or historical notes.

**V4 vs V5** in this file is a **roadmap / prioritization** split of that same pool. Moving an item to V5 does **not** mean the capability is implemented, closed, or no longer needed. Feature IDs are unchanged (`V4-FEAT-*`).

**Do not reopen frozen V3 workstreams** (WS2–WS4, §34.3–§34.4, OD-10, OD-17, §10.4–§10.5, §29 product surfaces, zero-own UNFUNDED lending, B3 Dashboard reserve warning, OD-16 engine + Strategy window control) unless a *new* regression is proven.

### Status values

`OPEN` · `BLOCKED` (waiting on a SPEC decision) · `DECIDED` (PO rule frozen; implementation is recorded on the SPEC row — SPEC-001, SPEC-002, SPEC-003, SPEC-004, and SPEC-005 are implemented, SPEC-006 is not) · `COMPLETE` (implemented in production)

A SPEC may be `DECIDED` while related FEAT rows stay `OPEN`. Do **not** mark a FEAT `COMPLETE` because its prerequisite SPEC is frozen.

---

## 1. What V3 closed (do not reopen)

Normative V3 is implemented, tested, and documented, including (non-exhaustive): multi-strategy / OD-01, WS2–WS4, §34.3–§34.4, OD-10/17, §10.4–§10.5, §29 surfaces, OD-12 settings, max_holdings, archive, capital badges, §5.7 lending limits, §19 success flags, §30 capital/lending notifies, **OD-16 Strategy weakest-window control**, deterministic `schedulerTimestamp`, and `DailyMarketDataJobTest` harness fix.

Living detail: [`implementation.md`](../implementation.md).

---

## 2. Genuine V4 features (active V4 scope)

Active V4 feature count: **18** (**0** `OPEN`, **18** `COMPLETE`). **V4 is closed.** The former 22-item active V4 register moved **V4-FEAT-008** to V5 on 2026-08-28, followed by **V4-FEAT-012, V4-FEAT-013, and V4-FEAT-015** on 2026-09-02 (IDs unchanged; deferred items remain `OPEN`).

| ID | Item | Why genuinely V4 | Priority | Status |
|----|------|------------------|----------|--------|
| V4-FEAT-001 | Broker / live execution automation | V3 §3 / §32 Decision 11 + SD-010: V3 does **not** require broker automation; manual/semi-auto fill is V3. **Product rule frozen as V4-SPEC-007**. Implemented 2026-08-27: per-portfolio modes, per-user entitlement, authenticator TOTP, Zerodha/Kite adapter, broker order lifecycle, fake-broker tests. | P2 | COMPLETE |
| V4-FEAT-002 | Advanced orders (GTT / stop / target / partial fills) | Broker-era order types; depends on FEAT-001. **Implemented 2026-08-28:** one GTT Target **or** Stop-Loss per Strategy position; Strategy-derived prices; Manual never auto-places; Semi-Automatic explicit place; Automatic Stop-Loss after automated BUY fill; material BUY/SELL/CA sync; modify or cancel+replace; `needs_attention` + retry; partial GTT fill via existing ledger then later sync; zero position clears orphans. Same automated-execution entitlement and TOTP as FEAT-001. | P3 | COMPLETE |
| V4-FEAT-005 | Market regime assessment (non-stub) | Not a V3 normative engine; Evaluation stub residual. **PO decision (2026-08-27):** Evaluation consumes MarketAnalysisEngine categorical `market_regime` (Bullish/Neutral/Bearish via existing `regimeFromPhase()`). Numeric Evaluation factor is Bullish→100, Neutral→50, Bearish→0. No new phase/regime calculation; sentiment is not the score. Implemented via `MarketRegimeScoreMapper` in EvaluationEngine (2026-08-27). | P2 | COMPLETE |
| V4-FEAT-006 | Liquidity & Tradability indicator calculators | Indicator Registry expansion; not V3 SoT. **PO decision (2026-08-27):** Keep the existing composite formulas and complete their runtime wiring. Do not redesign formulas, retune thresholds, change weights, or invent new metrics. Implemented via `TechnicalIndicatorService` dispatch to `LiquidityTradabilityCalculator` (2026-08-27). | P2 | COMPLETE |
| V4-FEAT-009 | Review reports list UI + deeper metrics | New Review UX beyond V3 Dashboard/API. **Implemented 2026-08-28:** live dashboard stays `/review`; list `/review/reports` and detail `/review/reports/:id`; single sidebar Review entry plus a Reports control on the live dashboard. List uses `GET /api/v1/reviews` (`page` / `pageSize`, default 20), stored portfolio value and XIRR, row click and Open. Detail uses `GET /api/v1/reviews/{id}` persisted metrics (no frontend formulas); `recommendation_accepted` is labelled **Accepted (not executed)**. Generate on the list only via `POST /api/v1/reviews/generate` query params (`period_start` / `period_end`; both empty keeps the existing 90-day default). No DELETE, filters, new endpoints, ReviewEngine, or pipeline changes. | P3 | COMPLETE |
| V4-FEAT-010 | Pipeline Operations / Unattended Production Execution | **PO frozen 2026-08-28:** (1) Daily Decision Pipeline runs fully unattended in production via Laravel `schedule:run` (no human trigger). (2) One effective pipeline run per portfolio calendar day (scheduler may fire repeatedly; existing F148/F149 lock + once-per-day guard). (3) Pipeline / broker-reconcile / automatic-submit failures stay visible in-app and send Telegram via existing ops alerts (6-hour cooldown; no email; no V5 multi-channel). (4) Laravel scheduler is the sole production scheduling mechanism — no dedicated cPanel one-shot scripts. Implemented 2026-08-28: pipeline schedule + post-sync hook default **on**; `tos:reconcile-broker-orders` and `tos:submit-automatic-orders` remain every five minutes with `withoutOverlapping`. | P2 | COMPLETE |
| V4-FEAT-011 | Stocks admin SPA surface | Admin product expansion; not V3. **Verified complete 2026-09-02:** `/settings/stocks` searchable/paginated admin catalogue; separate `admin_deactivated` override; dedicated Activate/Deactivate actions; raw feed state preserved; no manual add/delete. Focused PHP and Vitest suites passed in GitHub Actions run `33544994802`. | P3 | COMPLETE |
| V4-FEAT-014 | Backtest history “Duplicate” action | New UX convenience; not V3. **Implemented 2026-08-28:** Duplicate on a history row starts a **new** simulation via existing `POST /api/v1/backtests` using that row’s stored `from_date` / `to_date`, initial capital, notes, and tags, against the **current Strategy** (`strategy_version_id` omitted → `ensureActive`). Does not copy trades, statistics, snapshots, or the original run’s stored Strategy version. Does not revive Strategy Duplicate/version-fork. Missing period dates or valid capital stops instead of inventing values. | P3 | COMPLETE |
| V4-FEAT-021 | Strategy indicator params → EvaluationEngine wiring | EvaluationEngine is a separate TOS path from V3 Strategy fit/scoring; wiring is a V4 Evaluation design choice (was TD-19 / V4-BUG-002 / V4-TD-001). **PO decision (2026-08-26):** Strategy catalogue parameters are authoritative — a valid Strategy value overrides global Evaluation config; otherwise the existing global/default is used. Keys: `rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`. Implemented via `EvaluationParameterResolver` (2026-08-26). | P1 | COMPLETE |
| V4-FEAT-022 | Hard dataset publish / validation gate | Pre-discovery data-platform hardening (was V4-TD-002). **PO clarification (2026-08-27) — correction of the same feature, not a new ID:** Discovery is allowed when the required market dataset was successfully synced within the previous 24 hours of the pipeline run. On Monday, the allowed freshness window is 72 hours. The comparison is based strictly on timestamps, not calendar dates. Holiday-aware freshness / trading-calendar handling is out of scope (deferred to V5). Supersedes the earlier `published === true` / “synced today” gate. Implemented via `DatasetFreshnessGate` in `DailyDecisionPipeline` (2026-08-27). | P1 | COMPLETE |
| V4-FEAT-023 | Immutable dataset versioning | Data-platform hardening (was V4-TD-003). **Implemented (2026-08-27):** a successful daily market sync appends an insert-only `portfolio_tos_dataset_versions` row with a unique `version_key`; DiscoveryRun records that key; later syncs create a new row and do not mutate earlier identity. Failed/incomplete syncs create no version. FEAT-022 freshness remains last-successful-sync timestamp + 24h/72h Monday. | P2 | COMPLETE |
| V4-FEAT-024 | Recommendation `markExecuted` ownership refactor | Architecture cleanup (was V4-TD-004). **Implemented (2026-08-27):** `RecommendationLifecycleService::markExecuted()` (façade `RecommendationEngine::markExecuted`) writes executed status + converts reservation. `ExecutionEngine` orchestrates fill and calls that method inside the existing DB transaction. API/status/idempotency unchanged. | P3 | COMPLETE |
| V4-FEAT-025 | OpenAPI for `/api/v1` | Machine-readable contract (was V4-TD-006). **Implemented (2026-08-27):** OpenAPI 3.0.3 at `app/openapi/v1.json` covers all live `/api/v1` routes (122 operations) as they behave today. No API redesign, no Swagger UI. | P3 | COMPLETE |
| V4-FEAT-026 | Vitest / E2E smoke for TOS UI | New test harness (was V4-TD-007). **Implemented (2026-08-27):** Vitest + jsdom smoke for Recommendations/Discovery chrome, loading/empty/error, Review→Approve, pipeline freshness error; one Playwright Chromium path with intercepted `/api/v1`. | P2 | COMPLETE |
| V4-FEAT-027 | Split TradingOsController / shared React hooks | Maintainability (was V4-TD-008/009). **Implemented (2026-08-27):** TOS `/api/v1` HTTP split by engine under `App\Http\Controllers\Api\V1\TradingOs\*`; wire JSON in `TradingOsPresenter`. Frontend adopted existing `useApiGet` / `runApiMutation` on Discovery, Pending Execution, Review, Notifications (not TanStack Query). Routes/envelopes unchanged. | P3 | COMPLETE |
| V4-FEAT-028 | Structured logging / pagination consistency | Platform hardening (was V4-TD-010/011). **Implemented (2026-08-27):** `PortfolioLoggerService::event()` with stable event names + structured identifiers on TOS pipeline/discovery/evaluation/recommendation/execution/notification/data/review logs; key redaction for secrets. `TradingOsPagination` (`page`/`pageSize`, meta `{page,pageSize,total,lastPage}`, max 200 / price-bars 500) on securities, price-bars, recommendations, orders, transactions, notifications, reviews. Candidates/evaluations/positions/pending-execution stay bounded and unpaginated. OpenAPI updated. | P3 | COMPLETE |
| V4-FEAT-029 | Pluggable Evaluation rules modules | Evaluation architecture (was V4-TD-012). **Implemented (2026-08-27):** `EvaluationFactorRule` modules registered via `EvaluationServiceProvider`; `EvaluationEngine` orchestrates context + equal-weight aggregation. Formulas/weights/FEAT-005/021/API unchanged. `AsOfFactorScorer` stays historical-safe (no live Market Analysis). | P3 | COMPLETE |
| V4-FEAT-032 | Repository layer for TOS aggregates | Architecture (was V4-TD-015). **Implemented (2026-08-27):** focused `App\Repositories\Tos\*` classes own TOS aggregate list/find/paginate queries. Engines keep orchestration, lifecycle, scoring, and writes. No generic repository framework. API/OpenAPI unchanged. | P3 | COMPLETE |

---

### V4-FEAT-011 — Stocks admin SPA (VERIFIED COMPLETE 2026-09-02)

- Admin route `/settings/stocks` provides a searchable, server-paginated catalogue of non-benchmark stocks, including system, admin-override, and effective availability states.
- Dedicated admin Activate/Deactivate actions write only `admin_deactivated`; stock-master sync and provider validation retain ownership of raw `is_active`.
- Public stock index/search return effectively active rows only. Transaction resolution and BSE/NSE ISIN deduplication retain the frozen raw-state behavior.
- No manual stock-add or delete control was introduced.
- Verification: manual GitHub Actions run [`33544994802`](https://github.com/lido-alexion/LidoPortfolio/actions/runs/33544994802) passed `StockAdminTest`, `StockSearchTest`, `EquityUniverseServiceTest`, and `stocksAdmin.test.jsx` on PHP 8.4 / Node 22 with an isolated in-memory SQLite database.
- Production verification: deployed 2026-09-02; PHP 8.4.23 `migrate --force` applied `2026_09_01_000001_add_admin_deactivated_to_portfolio_stocks` successfully, all cPanel migration checks passed, the updated `assets/app-DKEolVAa.js` bundle loaded, and the Product Owner confirmed the deployed pages were working.
- The focused FEAT-011 workflow did **not** complete V5-deferred `V4-FEAT-030`; the broader PHPUnit + frontend-build CI scope was subsequently completed in V5 on 2026-09-04.

---

### V4-FEAT-010 — frozen production operations (2026-08-28)

These four Product Owner decisions are **frozen** and **implemented**:

1. **Unattended daily pipeline.** Production must not require a human to trigger the Daily Decision Pipeline. Automatic execution mode is operational end-to-end.
2. **One effective pipeline run per portfolio calendar day.** `schedule:run` may fire repeatedly; locking/idempotency prevent a second effective decision run for the same portfolio/day. Multiple decision runs per day are not introduced.
3. **Operational failures: in-app + Telegram.** Pipeline, broker reconciliation, and automatic-order submission failures remain visible in-app and notify Telegram via existing infrastructure. **No email.** **No V5 multi-channel work.**
4. **Laravel `schedule:run` only.** Production cron continues to invoke `php artisan schedule:run` every minute. That is the sole production scheduler. **No dedicated cPanel one-shot scripts. No second scheduler/worker framework.**

Disable the unattended pipeline only with `TRADING_OS_PIPELINE_SCHEDULE=false`.

---

### V4-FEAT-009 — Review reports list / detail (COMPLETE 2026-08-28)

Frozen UX, implemented as specified:

- Routes: live `/review`; list `/review/reports`; detail `/review/reports/:id`. One sidebar Review item. Reports control on the live dashboard. Detail has Back to reports.
- List: existing `GET /api/v1/reviews` pagination (`page` / `pageSize`, default 20). No filters or sorting. Empty when `total === 0` includes Generate.
- Detail: existing `GET /api/v1/reviews/{id}`; 404 uses the existing API and returns to the list. Persisted metric cards and remaining-key table; `recommendation_accepted` = **Accepted (not executed)**. Methodology from `summary_json.methodology` as stored.
- Generate: list only; `POST /api/v1/reviews/generate` with optional `period_start` / `period_end` query parameters (not a JSON body). Both empty preserves the existing 90-day default.
- Out of scope (unchanged): drawdown, execution quality, slippage, tax reporting, tax lots, attribution, benchmarks, ranking, ReviewEngine formulas/methodology, pipeline, Review API, DELETE, list filtering/sorting.

---

## 3. V5 deferred features (moved from V4)

Product Owner decisions: **2026-08-26** (original 14 IDs), **2026-08-28** (**V4-FEAT-008**), and **2026-09-02** (**V4-FEAT-012, V4-FEAT-013, V4-FEAT-015, V4-FEAT-037, V4-FEAT-038, V4-FEAT-039, V4-FEAT-040, V4-FEAT-041, V4-FEAT-042**). These **24** items are **V5 scope**, not active V4. They remain genuine post-V3 work and stay `OPEN` until implemented.

This is a **roadmap / prioritization** decision, **not** a claim that the underlying capability is implemented. Status remains `OPEN`. **Do not mark these COMPLETE** because they moved. **Do not treat shipped TAF registries/import-export as unimplemented** because FEAT-008 moved — only the *remainder* is V5.

| ID | Item | Why genuinely V4 (original rationale) | Priority | Status |
|----|------|---------------------------------------|----------|--------|
| V4-FEAT-003 | B4 persistent app-wide critical banner | V3 §29: **B4 is explicit wishlist**; B3 Dashboard reserve warning is current V3 | P2 | OPEN |
| V4-FEAT-004 | Notification channel abstraction + email/webhook | V3 §30 requires Telegram/in-app capability (shipped); multi-channel is new | P2 | OPEN |
| V4-FEAT-007 | Indicator Registry deeper versioning / remaining cutover | SD-033 residual beyond V3 registries already shipped | P2 | OPEN |
| V4-FEAT-008 | Trading Artifact Framework remaining phases | SD-034 residual **beyond shipped** envelope, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, AI authoring/runtime docs, and V3 multi-strategy surfaces. **PO 2026-08-28:** remainder is V5, not active V4. Do **not** invent a new V4 TAF slice. Shipped infrastructure stays shipped. | P2 | OPEN |
| V4-FEAT-012 | Admin force-logout of other users (PD-007) | Auth product expansion; not V3. **PO 2026-09-02:** deferred to V5; no V4 implementation required. | P3 | OPEN |
| V4-FEAT-013 | Cash-as-of / export / compare polish | F014 residual polish; not V3. **PO 2026-09-02:** deferred to V5; no V4 implementation required. | P3 | OPEN |
| V4-FEAT-015 | Tax reporting / attribution / benchmarks | New product surface. **PO 2026-09-02:** deferred to V5; no V4 implementation required. | P3 | OPEN |
| V4-FEAT-016 | Mobile application | New client | TBD | OPEN |
| V4-FEAT-017 | AI assistant (non-decision) | New assistive surface | TBD | OPEN |
| V4-FEAT-018 | ML scoring models | Optional non-deterministic path; V3 is deterministic | TBD | OPEN |
| V4-FEAT-019 | Options / crypto / ETF products | Markets expansion | TBD | OPEN |
| V4-FEAT-020 | Live paper / portfolio replay modes | New simulation modes | TBD | OPEN |
| V4-FEAT-030 | CI workflow for PHPUnit + frontend build | V5-deferred item, subsequently **completed 2026-09-04**; see the canonical V5 register. | P2 | COMPLETE |
| V4-FEAT-031 | Production secrets / single-folder deploy hardening | Ops (was V4-TD-014) | P3 | OPEN |
| V4-FEAT-033 | Discovery inline default screener | V5-deferred item, subsequently **completed 2026-09-04**; see the canonical V5 register. | P3 | COMPLETE |
| V4-FEAT-034 | Richer Evaluation history UX | V5-deferred item, subsequently **completed 2026-09-04**; see the canonical V5 register and `V5-FEAT-034-Richer-Evaluation-History-UX.md`. | P3 | COMPLETE |
| V4-FEAT-035 | TypeScript / TanStack Query / AG Grid migration | V5-deferred item; incremental migration started 2026-09-04. See the canonical V5 register and `V5-FEAT-035-Frontend-Stack-Migration.md`. | TBD | IN PROGRESS |
| V4-FEAT-036 | Optional JWT/token API for non-SPA clients | Auth expansion; Sanctum SPA is V3 (was V4-UX-007 / TD-016 drift → docs reconciled) | TBD | OPEN |
| V4-FEAT-037 | Dashboard-first daily Kite readiness and reconnect | V5-deferred item, subsequently **completed 2026-09-04**; see canonical V5 register. | P2 | COMPLETE |
| V4-FEAT-038 | Exchange holidays in Calendar with automatic holiday-list sync | Introduce an exchange-holiday event type in Calendar, visibly distinguish market holidays from personal events, and allow admin correction/override. Automatically fetch and refresh the official NSE holiday list when a reliable source is available, while retaining manual entry as the fallback. This feature establishes the canonical trading-calendar data consumed by later scheduling features. | P2 | OPEN |
| V4-FEAT-039 | Holiday-aware scheduled order execution | After **V4-FEAT-038** introduces the canonical exchange calendar, make the platform-level scheduled execution window skip NSE holidays as well as weekends. Pending Semi-Automatic and Automatic orders roll forward to the next eligible trading day and remain subject to the same execution gates. Until then, weekday-only scheduling deliberately ignores holidays. | P2 | OPEN |
| V4-FEAT-040 | Kite portfolio reconciliation (holdings and funds) | V5 broker-account synchronization for **Semi-Automatic and Automatic modes only**; Manual mode must not expose or run it. Fetch Kite holdings/positions and funds/margins through read-only broker APIs, manually and on a configurable schedule, including when markets are closed while the Kite session is usable. Show differences against StoX holdings and cash before applying anything. Kite data is reconciliation evidence; it must not silently overwrite the StoX transaction ledger, Strategy ownership, cost/fees, or accounting history. Applying a difference requires an explicit, auditable reconciliation action. Persist run status, discrepancies, decisions, and failures. | P2 | OPEN |
| V4-FEAT-041 | Linked Markdown wiki rooted in Knowledge Board | Add wiki-style Knowledge pages whose canonical source is `.md` Markdown but which are rendered as HTML inside the app. **Knowledge Board is the root** of the page directory/navigation hierarchy; pages may be nested beneath it. Every rendered page shows a breadcrumb navigation tree at the top derived from that hierarchy, beginning at Knowledge Board. Authors can easily link one page to another using stable internal page links without manually constructing deployment URLs; links continue to work when the app is hosted under its configured base path. Provide safe Markdown rendering, clear handling of missing/broken links, and navigation back to the Knowledge Board tree. | P2 | OPEN |
| V4-FEAT-042 | Separate role-based Admin Portal and investor application | Retain one login endpoint, then route the authenticated account into a role-specific application shell: administrators see only administrative data and controls, while normal users see the portfolio and investment application. Enforce this separation in server-side authorization as well as navigation and UI: users cannot access administrative controls or endpoints, including by direct URL/API calls; administrators cannot own portfolios, holdings/stocks, strategies, recommendations, broker connections, or trading activity. Admin and user product capabilities are mutually exclusive. Define migration and validation for any existing administrator-owned investment data before enforcing the invariant. | P1 | OPEN |

Notification channel interface (old V4-TD-005) is covered by **V4-FEAT-004** (now V5-classified; still OPEN).

### V4-FEAT-008 — TAF remainder deferred to V5 (2026-08-28)

**Status:** `OPEN`, **V5-deferred**. **Not** `COMPLETE`. ID unchanged. This is roadmap/prioritization, not an implementation claim for the remainder, and **not** a claim that shipped TAF infrastructure is unimplemented.

**Already shipped (keep recorded as implemented):**

- Artifact envelope / validation (`App\Services\Artifacts\*`)
- Package import/export (`/api/v1/artifacts*`)
- Indicator, Screener, and Strategy registries
- Create / Enable / Archive and existing V3 multi-strategy surfaces
- AI authoring / runtime documentation (in-app topics + `stox-trading-artifacts-ai-guide.md`)

**Deferred to V5 (remainder of SD-034 — do not implement as V4):**

- Immutable published versions vs Save-in-place as a first-class library/binding model
- Dual library vs portfolio-binding UX beyond current registries
- Extra AI draft-from-schema catalogue UX beyond shipped docs / prompt builder
- Sharing / pack distribution, dependency dashboards, rollback, bundle UI, fork workflows (architecture phases 5–6 leftovers)

Do **not** invent a new V4 TAF slice.

### V4-FEAT-021 — PO parameter-authority rule (COMPLETE)

**PO decision (2026-08-26):** Strategy indicator parameters are authoritative for the indicator parameters defined by the Strategy catalogue. For each supported parameter, a valid Strategy value overrides the global Evaluation configuration; otherwise the existing global/default value is used.

Supported override keys: `rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`.

**Implementation (2026-08-26):** `EvaluationParameterResolver` resolves those keys at the Evaluation boundary. Missing/invalid values fall back to existing globals/defaults. Weights / `trading_os.evaluation.weights` remain out of scope and unchanged.

### V4-FEAT-022 — PO dataset freshness gate (COMPLETE)

**PO decision (2026-08-27, original):** The daily decision pipeline must stop before Discovery unless the required market dataset is acceptable; it must not generate evaluation results or recommendations from an unpublished/stale dataset.

**PO clarification (2026-08-27) — same feature, not a new V4 ID:** The earlier implementation rule (`DataEngine::datasetStatus()['published'] === true`, where `published` meant “successfully synced today”) is **superseded**. Frozen rule:

> Discovery is allowed when the required market dataset was successfully synced within the previous 24 hours of the pipeline run. On Monday, the allowed freshness window is 72 hours. The comparison is based strictly on timestamps, not calendar dates. Holiday-aware freshness/trading-calendar handling is explicitly out of scope and is deferred to V5.

Implementation principles:

- Compare last successful dataset-sync timestamp to the **actual pipeline execution timestamp**.
- Normal days: maximum dataset age = **24 hours**.
- Monday (cron/sync timezone, not UTC): maximum dataset age = **72 hours**.
- Inclusive: age `<=` allowed window is fresh enough.
- Missing or unparseable last-success timestamp → blocked.
- Do **not** fall back to “OHLCV exists” or any older dataset beyond the window.
- Do **not** use calendar dates (“synced today”) as the gate.
- Do **not** introduce exchange-calendar or holiday logic.
- Do **not** introduce completeness scores or extra freshness rules.
- `DataEngine::datasetStatus()['published']` may still mean synced-today for inspection/UI; the pipeline gate **must not** use that boolean.

**Implementation (2026-08-27, corrected):** `DatasetFreshnessGate` evaluates age from `DailyMarketSyncService::lastSuccessfulSyncAt()` against `PipelineRun.started_at`. If not allowed, the run is saved as `failed` with reason `dataset_not_fresh` and a `DomainException` (`DATASET_NOT_FRESH`) is thrown before Discovery. Incomplete daily sync no longer overwrites the last successful `last_daily_market_sync_at` timestamp.

### V4-FEAT-005 — Evaluation market regime (COMPLETE)

**PO decision (2026-08-27):** MarketAnalysisEngine is the authoritative source. Categorical `market_regime` remains Bullish / Neutral / Bearish from existing `regimeFromPhase()`. Evaluation keeps its 0–100 factor model. Frozen numeric mapping: Bullish→100, Neutral→50, Bearish→0. Do not use sentiment. Do not create phase-specific scores (Strong Bull and Recovery are both Bullish → 100).

**Implementation (2026-08-27):** `EvaluationEngine` reads `MarketAnalysisEngine::latest()` once per run and maps the categorical value through `MarketRegimeScoreMapper`. Factor key `market_regime` stores the numeric score; evidence also stores categorical `market_regime` and `market_regime_score`. Unavailable Market Analysis still returns Neutral → 50. Backtest `AsOfFactorScorer` remains a 50 stub (historical leakage). `regimeFromPhase()` is unchanged.

### V4-FEAT-006 — Liquidity & Tradability calculators (COMPLETE)

**PO decision (2026-08-27):** Keep the existing composite formulas and complete their runtime wiring. Do **not** redesign the formulas, retune thresholds, change component weights, or invent new liquidity/tradability metrics. Registry definitions and `LiquidityTradabilityCalculator` remain the source of truth.

**Implementation (2026-08-27):** `TechnicalIndicatorService` `evaluate` / `evaluateSeries` dispatch `liquidity_score` and `tradability_score` to `LiquidityTradabilityCalculator::liquidityScore` / `tradabilityScore`, using the already-wired primary series at each bar. Missing/insufficient inputs still yield `null` (mean of available mapped components; no 0/50/100 fallback). Range 0–100 and existing caps are unchanged. Composites stay `screenable: false` (not in the Screener picker) and are **not** Evaluation/Strategy scoring inputs.

### V4-FEAT-023 — Immutable dataset versioning (COMPLETE)

**Requirement (from V4-TD-003 / PB-002 / TD-13):** Replace the soft date-string `dataset_version` (`ohlcv-{latest_price_date}`) with a reproducible published snapshot identity so historical decision runs remain attributable to the dataset they consumed.

**Implementation (2026-08-27):** Successful `DailyMarketSyncService::markSuccessful()` / `recordSuccessfulSyncAt()` appends an insert-only row in `portfolio_tos_dataset_versions` via `DatasetVersionLedger`. Identity is `version_key` = `ds-{YmdHis}-{YYYYMMDD|none}` in `cron_timezone` (suffix `-2`, `-3`, … on same-instant collision). Descriptive stats (`latest_price_date`, `price_bars`, `securities_active`) are frozen at insert time. Rows cannot be updated or deleted.

`DataEngine::currentDatasetVersion()` returns the current successful `version_key` (or `none`). `DiscoveryEngine` already stamps `DiscoveryRun.dataset_version` from that value; Evaluation/Recommendation stay linked through the discovery run. Later successful syncs create a distinct version and retarget only the current pointer (`last_successful_dataset_version_key`). Incomplete/failed syncs do not create a version and do not change the current pointer or last-success timestamp.

FEAT-022 is unchanged: the pipeline freshness gate still uses `lastSuccessfulSyncAt()` and the 24h / Monday-72h timestamp rule. A version row does not bypass a stale timestamp.

This is identity/attribution, not an OHLCV snapshot copy or a generic versioning framework.

### V4-FEAT-024 — Recommendation `markExecuted` ownership (COMPLETE)

**Requirement (from V4-TD-004 / TD-02 / PB-014 / SD-018):** ExecutionEngine must not write recommendation `executed` status; route that transition through `RecommendationEngine::markExecuted()`.

**Implementation (2026-08-27):** `RecommendationLifecycleService::markExecuted()` converts the cash reservation and writes `status=executed`, `executed_at`, and `executed_transaction_id`. `RecommendationEngine` forwards. `ExecutionEngine::completeRecommendationFromTransaction` and `executeOrder` call it inside the existing fill transaction. Fill preconditions, order status, lending `recordExecution`, and HTTP APIs are unchanged.

### V4-FEAT-025 — OpenAPI for `/api/v1` (COMPLETE)

**Requirement (from V4-TD-006):** Provide a machine-readable OpenAPI contract for the existing `/api/v1` API surface.

**Implementation (2026-08-27):** Canonical OpenAPI **3.0.3** document at `app/openapi/v1.json`, generated from live Laravel routes (`php artisan openapi:v1`). All 122 `/api/v1` operations are present; non-v1 routes are excluded. Auth is documented as Sanctum SPA cookies (`laravel_session`) plus optional `X-Profile-Id`. Overlay metadata records known request/response shapes from source; undocumented POST/PUT bodies stay generic (`additionalProperties: true`). Validation: JSON parse + `V1DocumentBuilder::assertValidDocument` + `openapi:v1 --check` + `OpenApiV1ContractTest`. Static-docs copy: `app/public/docs/openapi-v1.json`. No Swagger UI, no dedicated serve endpoint, no API behaviour change.

### V4-FEAT-026 — Vitest / E2E smoke for TOS UI (COMPLETE)

**Requirement (from V4-TD-007 / TD-12 / PB-032):** Smoke Vitest plus one Playwright path for TOS pages so UI regressions are not invisible behind backend-only tests.

**Implementation (2026-08-27):** Vitest (`npm run test:js:tos`) renders Recommendations and Discovery with mocked `/api/v1` envelopes. Playwright (`npm run test:e2e:tos`) loads the real App in Chromium via a Vite harness and intercepts APIs (no live backend). Existing `node --test` files remain; `npm run test:js` runs both.

---

## 4. Genuine V4 specification decisions (frozen PO rules — implementation on each SPEC row)

These **7** IDs are the V4 specification register (separate from the **18** active V4 features and from V5). SPEC-001–006 were resolved **2026-08-26**. **V4-SPEC-007** (broker execution modes) was recovered and frozen **2026-08-27**.

**Status:** `DECIDED` — the rule is frozen. **Not** `COMPLETE`. Implementation is recorded on the SPEC row: **SPEC-001, SPEC-002, SPEC-003, SPEC-004, and SPEC-005 are implemented**; SPEC-006 is not. **SPEC-007** is implemented by FEAT-001. Do not treat V3’s current safe behaviour (below) as the V4 target.

Canonical home for these rules is **this file** (`V4-SPEC-*`). Do **not** duplicate them as new V1 `SD-xxx` rows or as V3 `OD-*` / `DEP-*` (V3 remains frozen). V1 governance pointer: [`architecture/governance/SPECIFICATION_DECISIONS.md`](architecture/governance/SPECIFICATION_DECISIONS.md) (post-V3 note).

### Product philosophy (binds SPEC-001–006 accounting rules; SPEC-007 is live-execution)

The application is primarily for **personal use** and a small number of trusted friends. Public/commercial use is a distant possibility, not the current design target.

- Prefer **simplicity** over penny-accurate accounting.
- Prefer **broader useful functionality** over exhaustive accounting precision.
- Edge cases may be ignored when the simplest reasonable solution is sufficient.
- A **clear money trail** still matters (where money came from and where it went).
- Do **not** introduce enterprise accounting, tax-lot, or generalized corporate-action engines the PO did not request.

| ID | Frozen rule (one line) | Status |
|----|------------------------|--------|
| V4-SPEC-001 | Same-stock adoption merge uses simple weighted-average cost; one Strategy position; no lot accounting; final avg rounded to 2 dp half-up | DECIDED |
| V4-SPEC-002 | Do not model rights issues as a special CA; exercised shares are a normal purchase | DECIDED (implemented 2026-08-28) |
| V4-SPEC-003 | For splits and bonuses only, apply the ratio to qty, cost/avg, trailing high, stop, and target | DECIDED |
| V4-SPEC-004 | Cash-ledger special movements are exactly LOAN, RECALL, BRIDGE; signed amount sets cash direction | DECIDED (implemented 2026-08-28) |
| V4-SPEC-005 | Ambiguous cross-owner sells require explicit Strategy/owner attribution; never guess | DECIDED (implemented 2026-08-28) |
| V4-SPEC-006 | One live broker account may hold many Lido Strategies; broker = aggregate, Lido = logical ownership | DECIDED |
| V4-SPEC-007 | Per-portfolio execution mode (manual / semi_automatic / automatic); per-user admin entitlement; authenticator TOTP required for automated broker submission; Zerodha/Kite first broker; fill ≠ executed | DECIDED |

---

### V4-SPEC-001 — Same-stock unmanaged adoption cost-basis / multi-lot merge

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). **Implemented 2026-08-28** (same-stock adopt merge; the previous 422 “merge unspecified” path is gone). SPEC remains the product rule. |
| **Why V3 left it** | §10.4 requires merge when the destination Strategy already owns the symbol; cost math was never frozen (OD-15 = entry-date continuity only). This is **not** DEP-ADOPT-MERGE (lending-funded ownership). |
| **Current V3 behaviour** | Historically **422** until this implementation. Production now merges per the frozen rule below. |
| **PO decision** | Use a **simple weighted-average merge**. Portfolio-level accounting, not tax-lot accounting. |
| **Frozen rule** | When an unmanaged holding is adopted into a Strategy that already owns the same stock: combine quantities; combine total cost; compute weighted-average cost; treat the result as **one** Strategy position. **Do not** introduce FIFO, LIFO, tax lots, or separate lot accounting. |
| **Example** | Existing Strategy 50 @ ₹1,000 = ₹50,000. Unmanaged 100 @ ₹1,200 = ₹120,000. Result: **150 shares**, total cost **₹170,000**, unrounded average ₹1,133.333…, stored/resulting average cost **₹1,133.33**. |
| **Implementation clarification (rounding)** | Calculate the weighted-average cost from the available source values. **Do not** round the individual source values before the calculation. Round **only** the final resulting average cost. Final average cost is rounded to **exactly 2 decimal places** using **normal half-up** rounding. Frozen wording: final weighted-average cost rounded to 2 decimal places using half-up rounding; source values are not rounded before calculation. **Do not** introduce additional rounding policy. |
| **Implementation implications** | **Implemented 2026-08-28:** same-stock adopt no longer 422s. `HoldingAdoptionService` attributes unmanaged buys (HOLD_POSITION) and recalculates owner lots so quantities and total cost combine; final `avg_buy_price` is 2 dp half-up; destination `target_amount` is preserved (OD-12); destination ownership episode/entry date is preserved (OD-15). Still **DECIDED**, not a new FEAT row. |

---

### V4-SPEC-002 — Rights issues

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). **Implemented 2026-08-28** (explicit reject of rights as a CA; exercise path is the existing normal purchase). SPEC remains the product rule. |
| **Why V3 left it** | OD-10 freezes parent-owner **quantity** only; rights formulas were not decided. |
| **Current V3 behaviour** | Apply path: **split/bonus only**. |
| **PO decision** | **Do not** model rights issues as a special corporate-action operation. |
| **Frozen rule** | Existing holdings are **not** automatically changed because of a rights issue. Do **not** create or manage rights entitlements. If the user actually exercises the rights and receives additional shares, record those shares as a **normal purchase** at the actual subscription price. Do **not** introduce special rights-issue cost-basis or valuation mathematics. |
| **Implementation implications** | **Implemented 2026-08-28:** preview/apply of `action_type` rights (including `rights_issue`) returns 422 and mutates nothing. Exchange-feed rights rows are not queued as CA anomalies. Exercised shares use the existing BUY write path (cash, quantity, WAVG). A `source=rights` buy is a paid purchase, not zero-cost CA, and synchronizes GTT like any other buy. Split/bonus apply path unchanged. Mergers/demergers remain out of scope. Still **DECIDED**, not a new FEAT row. |

---

### V4-SPEC-003 — Corporate-action restatement (splits and bonuses)

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). **Implemented 2026-08-28** (split/bonus restatement of qty, cost/average, trailing, stop; OD-12 rupee target preserved). SPEC remains the product rule. |
| **Why V3 left it** | Explicit OD-10 leftover: cost / trailing-high / stop / target restatement after CA. |
| **Current V3 behaviour** | Historically ownership attachment (quantity) plus F020 ledger/OHLCV paths without an explicit Strategy-position restatement contract. Production now also restates per the frozen rule below. |
| **PO decision** | For corporate actions **already supported** (splits and bonuses), apply the appropriate ratio **consistently** to quantity, cost / average price, trailing high, stop-loss, and target. |
| **Frozen rule** | Example **1:2 split:** 100 shares @ ₹100 → 200 shares @ ₹50. A trailing high of ₹150 becomes ₹75; stop and target scale the same way. **Do not** expand this into a generalized corporate-action accounting engine. Rights, mergers, demergers, etc. are **outside** this rule (rights: V4-SPEC-002). |
| **Implementation implications** | **Implemented 2026-08-28:** `CorporateActionService` keeps the existing split/bonus ledger semantics; after apply, cost/average follow the restated ledger, trailing follows restated OHLCV, stop follows OD-13 fill cost, and OD-12 `target_amount` (rupees) is preserved so implied remaining shares scale at the new price. Duplicate same stock+type+ex-date apply is idempotent. Still **DECIDED**, not a new FEAT row. |

---

### V4-SPEC-004 — Cash-ledger entry types for loan / recall / bridge

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). **Implemented 2026-08-28** (cash-ledger `loan` / `recall` / `bridge` with signed amounts on the existing physical pool). SPEC remains the product rule. |
| **Why V3 left it** | §22 / DEP-ADOPT-MERGE: capital tables were sufficient; dedicated ledger kinds were never required. |
| **Current V3 behaviour** | Historically no dedicated loan/recall/bridge cash-ledger kinds. Production now posts them per the frozen rule below. |
| **PO decision** | Use **dedicated cash-ledger entry types** for relevant loan, recall, bridge, and corresponding money movements so the money trail is understandable. |
| **Frozen rule** | Use **exactly three** semantic special-movement transaction types: **LOAN**, **RECALL**, **BRIDGE**. The amount is **signed** and determines cash direction: **positive** = money enters trading cash; **negative** = money leaves trading cash. **Do not** create separate directional types (`LOAN_IN`, `LOAN_OUT`, `RECALL_IN`, `RECALL_OUT`, `BRIDGE_IN`, `BRIDGE_OUT`). An optional note/reference may provide human context; it is **not** part of the accounting logic. Frozen wording: LOAN, RECALL, BRIDGE are the three semantic special-movement types; signed amount determines cash direction. Prefer simplicity over exhaustive accounting semantics. **Do not** turn this into a full accounting or tax ledger. |
| **Implementation implications** | **Implemented 2026-08-28:** `CashManagementService` posts `loan` / `recall` / `bridge` through the existing ledger. Intra-portfolio events post both signs so physical cash is unchanged. Delayed Proceeds from Stock Sale post a single positive `recall` or `bridge`. No directional types, no new cash endpoints, no chart of accounts. V4-UX-003 remains a historical note (no separate UX row). Related FEAT rows stay `OPEN`. Still **DECIDED**, not a new FEAT row. |

---

### V4-SPEC-005 — Cross-owner sell attribution

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). **Implemented 2026-08-28** (write-path explicit SELL attribution; historical ambiguous rows still blend on recalc). SPEC remains the product rule. |
| **Why V3 left it** | Spec left some cross-owner sell edges conservative. |
| **Current V3 behaviour** | Conservative unmanaged / attributable paths; no invented proportional split. |
| **PO decision** | Require **explicit attribution** whenever a sell cannot be unambiguously attributed. Goal: trustworthy Strategy-level performance, not maximum automation. |
| **Frozen rule** | If a sell is unambiguously attributable to an owner/Strategy, use that attribution. If multiple owners/Strategies could legitimately be affected and the transaction does **not** identify the owner, **do not guess** — require the user to specify the Strategy/owner. **Do not** use proportional allocation, largest-position, oldest-position, FIFO, or any other automatic attribution rule. |
| **Implementation implications** | **Implemented 2026-08-28:** new SELL writes persist `owner_key` on `portfolio_transactions`. One open owner, `recommendation_id`, or explicit `owner_key`/`strategy_id` is enough. Multiple open owners without an identifier return 422. Quantity is gated on that owner’s holding. Broker/GTT/recall fills pass the identified owner. Historical rows without `owner_key` still use the V3 blended recalc fallback. Related FEAT rows stay `OPEN`. Still **DECIDED**, not a new FEAT row. |

---

### V4-SPEC-006 — Live-era portfolio exclusivity / broker-account binding

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-26). Not implemented (live broker attribution). |
| **Why V3 left it** | V3 §32 Decision 11 deferred exclusivity/binding to the broker era. |
| **Current V3 behaviour** | Multi-portfolio paper/manual continues. No live broker automation (SD-010). |
| **PO decision** | A **single live broker account may contain multiple Lido Strategies**. Do **not** impose one-broker-account / one-Strategy. |
| **Frozen rule** | Example: Broker Account → Momentum owns Reliance 100; Value owns Reliance 50. **Broker** = actual aggregate holdings and executions. **Lido** = logical Strategy ownership and attribution. The broker does not need to understand Lido Strategy ownership. Live orders/executions therefore need sufficient **Lido-side** Strategy attribution. |
| **Implementation implications** | Unblocks V4-FEAT-001 / FEAT-002 design. Do not require broker-side strategy tags. Live-mode semantics are **V4-SPEC-007**. FEAT-001 and FEAT-002 are implemented. |

---

### V4-SPEC-007 — Broker execution modes, entitlement, and domain separation

| Field | Content |
|-------|---------|
| **Status** | **DECIDED** (2026-08-27). Recovered from prior Product Owner discussion. TOTP mechanism frozen the same day. **Implemented by V4-FEAT-001** (2026-08-27). SPEC remains the product rule; FEAT-001 is the shipped behaviour. |
| **Why V3 left it** | SD-010 / §32 Decision 11: V3 is manual/semi-auto *ledger fill*; broker automation was future. V3 §6.16 named Manual / Semi-automatic / Automatic modes but did not freeze ownership, entitlement, 2FA, or fill-vs-executed semantics. |
| **Current V3 behaviour (unchanged for Manual)** | Approve → `pending_execution` → user records broker fill → ledger transaction → `RecommendationEngine::markExecuted()`. |
| **Initial broker** | **Zerodha / Kite** (first concrete adapter only; not a multi-broker marketplace). |

#### Frozen product decisions (authoritative)

**Execution modes (exactly one per portfolio):** `manual` (default for new portfolios), `semi_automatic`, `automatic`. An entitled user may use different modes on different portfolios.

- **Manual.** Lido does **not** submit orders to the broker. The user trades externally and may record/reconcile the fill with the existing manual execution path. Manual must remain fully usable without Zerodha configuration. **TOTP is not required.**
- **Semi-automatic.** The user must **explicitly confirm** before broker submission. Viewing, selecting, or reviewing is **not** confirmation. The confirming action is **Accept / Execute Selected**. Lido then submits the corresponding order(s) to Zerodha. Broker state is tracked; fills are reconciled into the local ledger.
- **Automatic.** Eligible recommendations are submitted **without per-order user acceptance**. Per-recommendation approval is not required once Automatic is legitimately enabled. Eligibility, authorization, TOTP enrollment, mode, capital/reservation, and other existing execution checks still apply. The user may later review/revoke/cancel where broker state permits. A **filled** broker order cannot be undone merely by changing application mode.

**Entitlement (separate from mode):** Automated-execution entitlement is **per user/account**, **disabled by default**, **admin-controlled**. It never grants another user permission. Semi-Automatic and Automatic broker submission are blocked unless the current user has entitlement. Mode is not itself authorization.

**2FA / TOTP:** Automated broker submission **must** be blocked server-side unless authenticator **TOTP** is enrolled and active. Compatible apps such as Google Authenticator are acceptable. **Password re-prompt is not a substitute. Email OTP is not used.** Enrollment, possession proof, recovery codes, disable/revoke, and rate-limited verification are required. Secrets and recovery codes must never be logged. Hiding UI is insufficient.

- Semi-Automatic submit requires a valid TOTP (or unused recovery code) on the submit request.
- Automatic unattended submit requires enrolled+active TOTP (no per-order prompt). Enabling Automatic requires a valid TOTP on that mode-change request.

**Mode transitions:** Manual → Semi-Automatic / Automatic must respect entitlement and security prerequisites. Manual → Automatic requires **explicit confirmation**. Automatic → Manual is allowed. Downgrade is **non-destructive**: it prevents future automatic submissions and does **not** implicitly cancel broker orders already submitted.

**Domain separation (do not collapse):**

Recommendation → execution decision → broker order → broker execution/fill → transaction / portfolio reconciliation.

- recommendation ≠ broker order
- broker order ≠ fill
- fill ≠ transaction
- `TradingRecommendation.status = executed` continues to mean an **actual ledger fill** (existing V3/V4-FEAT-024 semantics). Broker **acceptance** alone must **not** mark a recommendation executed. Partial fill must **not** become a full execution.

Broker state is authoritative for the broker-order lifecycle. Existing capital/reservation/lending/authorization must not be bypassed.

#### Engineering decisions (not product forks)

These may change without a Product Owner round-trip unless they alter capability, financial behaviour, security posture, or eligibility:

- Schema (`execution_mode`, TOTP columns, `portfolio_broker_connections`, broker fields on `portfolio_tos_orders`, `portfolio_tos_execution_decisions`)
- TOTP library: `pragmarx/google2fa` + `bacon/bacon-qr-code`; encrypted secret; hashed recovery codes; `verifyKeyNewer` replay window; 5 attempts/minute
- Per-user Kite Connect (app `KITE_API_KEY`/`KITE_API_SECRET`; encrypted per-user access token). A shared central Zerodha account would mix whose money is at risk and would conflict with frozen cross-user isolation
- Kite access tokens expire ~06:00 IST; Automatic only submits while a usable session exists
- Idempotency: local unique `submission_key`; if status is unknown/in-flight, poll and **do not** place again. Kite has no first-class idempotency key; `tag` is informational
- Reconciliation: poll every 5 minutes (`tos:reconcile-broker-orders`); Automatic sweep `tos:submit-automatic-orders`; pipeline hook after recommendation generation
- Order default: Kite regular MARKET CNC (delivery)
- Exact `/api/v1` paths, OpenAPI overlays, and Settings/Pending Execution layout

#### Remaining unspecified (do not invent as product)

- GTT / stop / target / advanced order types (**V4-FEAT-002**, implemented 2026-08-28)
- Multi-broker marketplace
- Exact stale-recommendation auto-cancel policy beyond existing expire/cancel APIs

#### Implementation implications

V4-FEAT-001 implements the frozen SPEC-007 rules above. GTT/stop/target is **V4-FEAT-002** (COMPLETE 2026-08-28). Do not change frozen V3 accounting. SPEC-007 `DECIDED` is the product rule; FEAT-001 `COMPLETE` is the shipped live-execution behaviour.

---

## 5. Closed in V3 strict pass (removed from active backlog)

| Former ID | Resolution |
|-----------|------------|
| V4-BUG-001 | **FIXED** — deterministic `schedulerTimestamp` restored |
| V4-BUG-002 | **Reclassified** → V4-FEAT-021 (not a V3 bug; Evaluation≠Strategy-fit) |
| V4-BUG-003 | **FIXED** in V3 closure — max position enforced |
| V4-BUG-004 | **FIXED** — `DailyMarketDataJobTest` no longer uses RefreshDatabase |
| V4-UX-001 | **IMPLEMENTED IN V3** — Strategy Portfolio Rules OD-16 window control |
| Discovery sidebar / strategy create-archive UI | **IMPLEMENTED IN V3** (2026-08-26 product-surface closure) — not V4 |
| V4-UX-002 | Duplicate of FEAT-003 (B4) — kept only as FEAT-003 |
| V4-UX-003 | Blocked presentation of SPEC-004 — no separate UX row |
| V4-TD-001–016 | Reclassified to FEAT-021+ or FEAT-004 / docs; none remain as open V3 debt |
| V4-HIST-* | **Archived** — see §6; not active backlog |

**Open V3 bugs / V3 TD / V3 UX:** **none**.

---

## 6. Historical archive (closed — not active V4 work)

These are **not** open tasks. Kept only as pointers.

| Former ID | Note |
|-----------|------|
| HIST-001 | OD-17 numeric 550 ceiling **resolved**; do not reintroduce |
| HIST-002 | WS1–WS4 / §34 / §10.4–§10.5 / §29 / zero-own UNFUNDED **COMPLETE** |
| HIST-003 | SD-035 V2 eleven deferred — **V2 CLOSED** |
| HIST-004 | PB-044 multi-strategy isolation **superseded by V3 OD-01** |
| HIST-005 | PB-003 deep review SL **superseded by §34.3**; residual deep-review UX would be a *new* FEAT if desired |
| HIST-006 | Pre-V3 PRODUCT_BACKLOG / TECHNICAL_DEBT / KNOWN_LIMITATIONS are **indexes only** |
| HIST-007 | WS4 delta build plan **largely implemented**; keep as historical plan |

---

## 7. Acceptance rules

A SPEC is **DECIDED** when the Product Owner has frozen the rule in this register. That is **not** `COMPLETE`.

An item may be marked **COMPLETE** only when: frozen decision (if SPEC), **production behaviour**, focused tests, V3 regressions green, and `implementation.md` updated — without inventing unspecified math.

Moving a FEAT from V4 to V5 does **not** satisfy acceptance. Freezing V4-SPEC-001–007 does **not** mark any FEAT `COMPLETE`. V5 rows stay `OPEN` until the capability is actually implemented.

---

## 8. Change log

| Date | Change |
|------|--------|
| 2026-08-25 | Initial register from Final V3 Completion Audit + backlog packs |
| 2026-08-26 | V4-BUG-001 fixed; V4-BUG-003 marked complete |
| 2026-08-26 | **Strict closure rewrite:** removed open V3 bugs/TD/UX/HIST from active backlog; OD-16 UI + DailyMarketDataJobTest fixed in V3; former TD/UX rows folded into genuine V4 FEAT or SPEC only |
| 2026-08-26 | V3 product-surface closure: Discovery removed from Market sidebar; strategy Create-from-factory + Archive UI completed in V3 (not added to this register) |
| 2026-08-26 | **Product Owner V4/V5 split:** former 36-item V4 feature register split into **22 active V4 features** and **14 V5-deferred features**. Moved to V5 (IDs unchanged, still OPEN, not COMPLETE): V4-FEAT-003, 004, 007, 016, 017, 018, 019, 020, 030, 031, 033, 034, 035, 036. Active V4 remains: 001, 002, 005, 006, 008–015, 021–029, 032. V4-SPEC-001–006 remain the V4 specification register. This is roadmap/prioritization, not an implementation claim. |
| 2026-08-26 | **Product Owner resolved all six V4 specification decisions (V4-SPEC-001 through V4-SPEC-006)**, establishing deliberately simple portfolio/corporate-action/accounting rules ahead of V4 implementation. These are **frozen product/specification decisions, not implemented functionality**. No FEAT status changed. |
| 2026-08-26 | **PO implementation clarifications:** V4-SPEC-001 weighted-average rounding (final average to 2 decimal places, half-up; do not round source values first); V4-SPEC-004 special cash-ledger types are exactly **LOAN**, **RECALL**, **BRIDGE** with signed amount for cash direction. Both remain **DECIDED**, not implemented. |
| 2026-08-26 | **V4-FEAT-021 PO decision:** Strategy catalogue indicator parameters (`rsi_period`, `lookback_days`, `sma_fast`, `sma_slow`, `atr_period`, `volume_sma_period`, `benchmark`) override global Evaluation config when valid; otherwise existing globals/defaults apply. Feature remains **OPEN** until implementation is verified. |
| 2026-08-26 | **V4-FEAT-021 COMPLETE:** `EvaluationParameterResolver` wires the seven catalogue parameters into EvaluationEngine (override-or-fallback). Scoring weights unchanged. Tests: `EvaluationParameterResolverTest`, `EvaluationParameterOverrideTest`. |
| 2026-08-27 | **V4-FEAT-022 PO decision:** Only a `published` dataset may enter the daily decision pipeline; otherwise stop before Discovery with no evaluation/recommendations and no stale-data fallback. Feature remains **OPEN** until implementation is verified. |
| 2026-08-27 | **V4-FEAT-022 COMPLETE (superseded):** `DailyDecisionPipeline` hard-gated on `DataEngine::datasetStatus()['published']` before Discovery. Tests: `DatasetPublishGateTest`. |
| 2026-08-27 | **V4-FEAT-022 PO clarification (not a new feature):** Timestamp freshness supersedes “synced today” / `published === true`. Normal days 24 hours; Monday 72 hours; inclusive; no holiday/exchange calendar (V5). Feature set **OPEN** while the implementation is corrected. |
| 2026-08-27 | **V4-FEAT-022 COMPLETE (correction):** `DatasetFreshnessGate` compares last successful sync timestamp to pipeline `started_at`. Tests: `DatasetFreshnessGateTest`, `DatasetPublishGateTest`. |
| 2026-08-27 | **V4-FEAT-005 PO decision:** Evaluation consumes MarketAnalysisEngine categorical `market_regime` (Bullish/Neutral/Bearish); numeric factor is 100/50/0. No new regime calculation; sentiment unused. Feature remains **OPEN** until implementation is verified. |
| 2026-08-27 | **V4-FEAT-005 COMPLETE:** `EvaluationEngine` maps `MarketAnalysisEngine::latest()['market_regime']` via `MarketRegimeScoreMapper`. Tests: `MarketRegimeScoreMapperTest`, `EvaluationMarketRegimeTest`. |
| 2026-08-27 | **V4-FEAT-023 COMPLETE:** Successful market sync records an insert-only `portfolio_tos_dataset_versions` row; DiscoveryRun stores that `version_key`; failed/incomplete syncs create none. FEAT-022 freshness unchanged. Tests: `DatasetVersioningTest`. |
| 2026-08-27 | **V4-FEAT-024 COMPLETE:** `RecommendationEngine::markExecuted()` / `RecommendationLifecycleService` own the executed-status write; ExecutionEngine orchestrates fill only. Tests: `RecommendationMarkExecutedTest`. |
| 2026-08-27 | **V4-FEAT-025 COMPLETE:** OpenAPI 3.0.3 contract for all live `/api/v1` routes at `app/openapi/v1.json` (122 operations). Tests: `OpenApiV1ContractTest`. |
| 2026-08-27 | **V4-FEAT-026 COMPLETE:** Vitest TOS UI smoke + one Playwright Chromium path. Commands: `npm run test:js:tos`, `npm run test:e2e:tos`. |
| 2026-08-27 | **V4-FEAT-027 COMPLETE:** Split `TradingOsController` by engine; shared `TradingOsPresenter` + `useApiGet`/`runApiMutation` on remaining TOS pages. API wire contract unchanged. Tests: `TradingOsControllerSplitTest`, OpenAPI, Vitest/Playwright TOS smoke. |
| 2026-08-28 | **V4-SPEC-001 implemented:** Same-stock unmanaged → Strategy adoption merges into one position (combined qty/cost; final average 2 dp half-up). Destination `target_amount` unchanged. Previous 422 “merge unspecified” path removed. SPEC remains **DECIDED**. |
| 2026-08-28 | **V4-SPEC-003 implemented:** Split/bonus restates Strategy quantity, cost/average, trailing high, and stop. OD-12 rupee `target_amount` preserved. Duplicate same-day apply is idempotent. SPEC remains **DECIDED**. FEAT-002 / SPEC-004 / SPEC-005 not in this slice. |
| 2026-08-28 | **V4-FEAT-002 COMPLETE:** Broker GTT Target / Stop-Loss on Strategy positions. Frozen rules: one active protection; Strategy-derived prices; Manual/Semi/Automatic as specified; material BUY/SELL/CA sync; `needs_attention` without rolling back the position; partial GTT fill via existing ledger then later sync. Tests: `AdvancedOrdersFeatureTest`. |
| 2026-08-28 | **V4-SPEC-004 implemented:** Cash-ledger special movements are exactly `loan` / `recall` / `bridge` with signed amounts. Intra-portfolio events net to zero on the physical pool. Delayed Proceeds from Stock Sale post as recall/bridge, not deposit. SPEC remains **DECIDED**. SPEC-005 not in this slice. |
| 2026-08-28 | **V4-SPEC-005 implemented:** Ambiguous cross-owner SELLs require `owner_key` / `strategy_id` / recommendation; never FIFO, proportional, or largest-lot. Single-owner and identified fills stamp `owner_key`. Historical ambiguous rows still blend on recalc. SPEC remains **DECIDED**. |
| 2026-08-28 | **V4-SPEC-002 implemented:** Rights issues are not a corporate-action type. Preview/apply of `rights` is 422 with no holdings/ledger/OHLCV mutation. Exercised shares are a normal purchase at the subscription price. SPEC remains **DECIDED**. SPEC-006 not in this slice. |
| 2026-08-28 | **V4-FEAT-010 COMPLETE:** Unattended production Daily Decision Pipeline + Automatic execution via Laravel `schedule:run` only. Frozen: one effective run per portfolio calendar day; in-app + Telegram ops failures (no email); no new cPanel scripts. Tests: `V4Feat010UnattendedOpsTest`, `ScheduleRegistrationTest`. |
| 2026-08-28 | **Product Owner V4-FEAT-008 → V5:** Trading Artifact Framework *remainder* is out of active V4. Shipped envelope, package I/O, Indicator/Screener/Strategy registries, Create/Enable/Archive, AI authoring/runtime docs, and V3 multi-strategy surfaces stay shipped. Do not invent a new V4 TAF slice. ID unchanged; status stays `OPEN` (not COMPLETE). Active V4 is **21** (**15** COMPLETE, **6** OPEN). V5-deferred is **15**. |
| 2026-08-28 | **V4-FEAT-009 COMPLETE:** Review reports list `/review/reports` and detail `/review/reports/:id`. Live `/review` kept. Single sidebar Review item. Stored ReviewEngine metrics only; Generate on the list with query-param dates. Tests: `tests/js/tos/tos-review-reports.test.jsx`, `tests/js/tos/review-reports.test.js`. Active V4 is **21** (**16** COMPLETE, **5** OPEN: 011–015). |
| 2026-08-28 | **V4-FEAT-014 COMPLETE:** Backtest history Duplicate starts a new simulation from stored period/capital/notes/tags against the current Strategy via existing `POST /api/v1/backtests`. No result-state copy; no new endpoint; no Strategy Duplicate. Tests: `tests/Feature/Backtest/BacktestDuplicateTest.php`, `tests/js/tos/backtest-duplicate.test.jsx`, `tests/js/backtestDuplicate.test.mjs`. Active V4 is **21** (**17** COMPLETE, **4** OPEN: 011–013, 015). |
| 2026-09-02 | **V4-FEAT-011 VERIFIED COMPLETE:** Stocks admin SPA frozen behavior passed `StockAdminTest`, `StockSearchTest`, `EquityUniverseServiceTest`, and `stocksAdmin.test.jsx` in manual GitHub Actions run `33544994802` (PHP 8.4, Node 22, isolated in-memory SQLite). Active V4 is **21** (**18** COMPLETE, **3** OPEN: 012, 013, 015). Focused verification workflow does not close V5-deferred FEAT-030. |
| 2026-09-02 | **Product Owner deferred V4-FEAT-012, V4-FEAT-013, and V4-FEAT-015 to V5:** IDs and `OPEN` status are unchanged; this is roadmap reprioritization, not implementation. Active V4 is now **18** (**18 COMPLETE, 0 OPEN**) and is formally closed. V5-deferred is **18**. |
| 2026-09-02 | **V4-FEAT-037 added directly to V5:** Dashboard-first daily Kite readiness/reconnect with minimum interaction and a configurable once-daily Telegram reminder while Automatic execution is enabled but the Kite session is unusable. Interactive Zerodha authentication remains mandatory; no silent renewal. V5-deferred is **19**. |
| 2026-09-02 | **V4-FEAT-038 and V4-FEAT-039 added directly to V5:** first introduce exchange holidays in Calendar with automatic NSE holiday-list retrieval where reliable and manual admin fallback; then make scheduled Semi-Automatic and Automatic execution skip those holidays and roll pending orders to the next trading day. V5-deferred is **21**. |
| 2026-09-02 | **V4-FEAT-040 added directly to V5:** Kite holdings/positions and funds/margins reconciliation, available only for Semi-Automatic and Automatic portfolios. Supports manual and scheduled read-only fetches while the session is usable, including outside market hours. Differences require preview and explicit audited application; broker data never silently replaces the StoX ledger or Strategy ownership. V5-deferred is **22**. |
| 2026-09-02 | **V4-FEAT-041 added directly to V5:** linked wiki-style Knowledge pages authored and stored as Markdown and rendered as HTML in-app. Knowledge Board remains the hierarchy root; every page has hierarchy-derived breadcrumbs at the top, and stable base-path-safe internal links make cross-page linking easy. V5-deferred is **23**. |
| 2026-09-02 | **V4-FEAT-042 added directly to V5:** separate role-based Admin Portal and investor application behind the same login endpoint. The authenticated role selects the application shell and permitted APIs. Users have no administrative access; administrators cannot own or operate portfolios, holdings, broker connections, or other investment activity. V5-deferred is **24**. |
| 2026-09-02 | **V4-FEAT-011 PRODUCTION VERIFIED:** cPanel migration checks passed on PHP 8.4.23; the `admin_deactivated` migration completed; the updated `assets/app-DKEolVAa.js` bundle loaded; Product Owner confirmed the deployed pages were working. V4 remains closed. |

## Appendix — Former ID map

| Former | Now |
|--------|-----|
| V4-BUG-002 / V4-TD-001 | V4-FEAT-021 |
| V4-TD-002 | V4-FEAT-022 |
| V4-TD-003 | V4-FEAT-023 |
| V4-TD-004 | V4-FEAT-024 |
| V4-TD-005 | V4-FEAT-004 (V5-classified 2026-08-26) |
| V4-TD-006 | V4-FEAT-025 |
| V4-TD-007 | V4-FEAT-026 |
| V4-TD-008/009 | V4-FEAT-027 |
| V4-TD-010/011 | V4-FEAT-028 |
| V4-TD-012 | V4-FEAT-029 |
| V4-TD-013 | V4-FEAT-030 (V5-classified 2026-08-26) |
| V4-TD-014 | V4-FEAT-031 (V5-classified 2026-08-26) |
| V4-TD-015 | V4-FEAT-032 |
| V4-TD-016 | Docs reconciled (Sanctum is auth SoT); optional token API → V4-FEAT-036 (V5-classified 2026-08-26) |
| V4-UX-001 | **V3 implemented** |
| V4-UX-004 | V4-FEAT-033 (V5-classified 2026-08-26) |
| V4-UX-005 | V4-FEAT-034 (V5-classified 2026-08-26) |
| V4-UX-006 | V4-FEAT-035 (V5-classified 2026-08-26) |
| V4-UX-007 | V4-FEAT-036 (V5-classified 2026-08-26) |
