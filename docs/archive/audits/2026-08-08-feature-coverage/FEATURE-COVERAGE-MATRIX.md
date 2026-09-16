# V1 Feature Coverage Matrix

**Date:** 2026-08-08  
**Baseline:** TOS-V1.0-2026-07-25 (`MVP_SCOPE.md`, `SPECIFICATION_DECISIONS.md`)  
**Method:** Spec review + repository inspection (no code changes)

**Primary status (mutually exclusive):** IMPLEMENTED | PARTIALLY_IMPLEMENTED | SPECIFIED_NOT_IMPLEMENTED | OUT_OF_SCOPE | AMBIGUOUS

**V1 scope (mutually exclusive):** V1_REQUIRED | V1_OUT_OF_SCOPE | V1_SCOPE_AMBIGUOUS

**Secondary attributes (non-exclusive):** NOT_SPECIFIED | DEVIATION:* — do not add to primary totals

**Layer legend:** YES | PARTIAL | NO | N/A

---

## Summary counts (reconciled 2026-08-08)

### Primary status (159 rows — mutually exclusive)

| Primary Status | Count |
|----------------|------:|
| IMPLEMENTED | 124 |
| PARTIALLY_IMPLEMENTED | 13 |
| SPECIFIED_NOT_IMPLEMENTED | 1 |
| OUT_OF_SCOPE | 21 |
| AMBIGUOUS | 0 |
| **TOTAL** | **159** |

### V1 scope (159 rows — mutually exclusive)

| V1 Scope | Count |
|----------|------:|
| V1_REQUIRED | 115 |
| V1_OUT_OF_SCOPE | 29 |
| V1_SCOPE_AMBIGUOUS | 15 |
| **TOTAL** | **159** |

### V1-required implementation status (strict coverage denominator)

| V1 Required — Primary Status | Count |
|------------------------------|------:|
| IMPLEMENTED | 110 |
| PARTIALLY_IMPLEMENTED | 5 |
| SPECIFIED_NOT_IMPLEMENTED | 0 |
| **TOTAL V1 REQUIRED** | **115** |

**Strict V1 implementation coverage:** 110 ÷ 115 = **95.7%**

**Formula:** `IMPLEMENTED_V1 / (IMPLEMENTED_V1 + PARTIALLY_IMPLEMENTED_V1 + SPECIFIED_NOT_IMPLEMENTED_V1)`

V1_SCOPE_AMBIGUOUS rows (15) and V1_OUT_OF_SCOPE rows (29) are excluded from this denominator.

**Weighted effective coverage (optional):** (110 + 0.5×5) ÷ 115 = **97.8%** — partial rows counted at 50%; use only as supplementary metric.

### Secondary attributes (not added to row totals)

| Secondary Attribute | Count |
|---------------------|------:|
| NOT_SPECIFIED (documentation gap) | 15 |
| DEVIATION (spec ↔ implementation) | 6 |

---

## 1. Authentication & Authorization

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F001 | Auth | Sanctum session login/logout | V1_REQUIRED | IMPLEMENTED | DEVIATION:SD-001 | YES | YES | YES | YES | N/A | YES | P2 | SPEC/IMP DEVIATION | App-Architecture-Spec: JWT; SD-001 | `AuthController.php`, `POST /api/auth/login` | Sanctum not JWT — accepted SD-001 |
| F002 | Auth | CSRF token for SPA | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | YES | P3 | — | App-Architecture | `GET /api/auth/csrf-token`, `AuthCsrfLoginTest.php` | — |
| F003 | Auth | User invite flow | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | YES | P3 | SPEC GAP | Not in MVP_SCOPE | `UserInviteService.php`, `/invite/:token` | Useful admin feature |
| F004 | Auth | Password reset | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | YES | P3 | SPEC GAP | Not in MVP_SCOPE | `PasswordResetLinkService.php` | — |
| F005 | Auth | Session management (list/revoke) | V1_SCOPE_AMBIGUOUS | PARTIALLY_IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | PARTIAL | N/A | YES | P3 | SPEC GAP | Not in MVP_SCOPE | `SessionManagementService.php` | Settings UI partial |
| F006 | Auth | Admin role flag | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | TEST GAP | App-Architecture admin | `middleware('admin')`, `User.is_admin` | — |
| F007 | Auth | Multi-portfolio profile scope | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `PortfolioProfile`, `active.portfolio` middleware | — |
| F008 | Auth | JWT Bearer tokens | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded; SD-001 | — | Explicitly out of V1 |
| F009 | Auth | Fine-grained RBAC matrix | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | Future |

---

## 2. Portfolio & Holdings

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F010 | Portfolio | Multi-portfolio profiles CRUD | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `PortfolioController`, `portfolio_profiles` | — |
| F011 | Portfolio | Default portfolio selection | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `POST /portfolios/{id}/set-default` | — |
| F012 | Holdings | Transaction-derived holdings | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `HoldingsCalculationService`, `GET /holdings` | — |
| F013 | Holdings | Holdings enrichment (prices, P&L) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `HoldingPresentationService` | — |
| F014 | Holdings | Historical holdings reconstruction | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | PARTIAL | N/A | YES | P2 | SPEC GAP | Not in MVP_SCOPE | `PortfolioHistoricalHoldingsService`, rebuild-history | No dedicated UI |
| F015 | Holdings | Portfolio snapshots | V1_REQUIRED | PARTIALLY_IMPLEMENTED | — | YES | YES | PARTIAL | PARTIAL | N/A | YES | P2 | UI GAP | Portfolio-Analytics-Spec | `portfolio_portfolio_snapshots`, Dashboard | Snapshot UI limited |
| F016 | Transactions | Buy/sell CRUD | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Portfolio-Spec | `TransactionController`, `TransactionWriteService` | — |
| F017 | Transactions | Fee calculation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Portfolio-Spec | `FeeCalculatorService` | — |
| F018 | Transactions | Sell realization / cost basis | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Portfolio-Spec | `TransactionRealizationService` | — |
| F019 | Transactions | Bulk CSV import | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | PARTIAL | P3 | SPEC GAP | Not in MVP_SCOPE | `BulkTransactionImport.jsx` | — |
| F020 | Transactions | Corporate actions | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | YES | YES | P2 | SPEC GAP | Not in MVP_SCOPE | `CorporateActionPage.jsx`, sync commands | Full subsystem |

---

## 3. Cash Management

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F021 | Cash | Cash account per profile | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec §Account | `CashAccount`, `portfolio_cash_accounts` | — |
| F022 | Cash | Deposit | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `POST /api/cash/deposit` | — |
| F023 | Cash | Withdraw | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `POST /api/cash/withdraw` | — |
| F024 | Cash | Adjustment (reason required) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `POST /api/cash/adjust` | — |
| F025 | Cash | Cash ledger / history | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `GET /api/cash/ledger`, `CashLedgerEntry` | — |
| F026 | Cash | Available cash calculation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `CashManagementService::availableCash()` | — |
| F027 | Cash | Reserved cash (pending buys) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec SD-026 | Derived from pending-execution reservations | — |
| F028 | Cash | Reservation on recommendation approval | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `RecommendationLifecycleService` reserve on approve | Covered in pipeline test |
| F029 | Cash | Reservation release on cancel/expire/reopen | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | `RecommendationLifecycleService` | — |
| F030 | Cash | Reservation conversion on execution | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Cash-Management-Spec | Execute posts ledger via `TransactionWriteService` | — |
| F031 | Cash | Cash-aware capital allocation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Cash-Management-Spec SD-026 | `ScorePriorityCapitalAllocator` | — |
| F032 | Cash | Insufficient cash → WATCH demotion | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Recommendation-Engine-Spec | `RecommendationGenerationPipeline` | — |
| F033 | Cash | Alternate allocators beyond ScorePriority | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | Interface exists; only ScorePriority impl | Future |

---

## 4. Market Data & Data Quality

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F034 | Market Data | Security master (stocks) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Data-Engine-Spec | `portfolio_stocks`, `StockController` | — |
| F035 | Market Data | OHLCV price bars | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Data-Engine-Spec | `portfolio_stock_prices`, sync providers | — |
| F036 | Market Data | NSE → Yahoo → Alpha Vantage fallback | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | PARTIAL | YES | YES | P1 | — | implementation.md | `PriceFetchService`, providers | — |
| F037 | Market Data | Daily holdings price sync | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Data-Engine-Spec | `DailyMarketSyncService`, `portfolio:daily-sync` | — |
| F038 | Market Data | Universe OHLCV sync | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | PARTIAL | YES | YES | P1 | — | Data-Engine-Spec | `UniversePriceSyncService` | — |
| F039 | Market Data | Dataset status / soft version | V1_REQUIRED | IMPLEMENTED | DEVIATION:SD-004 | YES | YES | YES | PARTIAL | N/A | PARTIAL | P1 | SPEC/IMP DEVIATION | Data-Engine: hard publish; SD-004 | `DataEngine`, `GET /v1/dataset/status` | Soft version only |
| F040 | Market Data | Hard publish/validation gates | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P1 | CODE GAP | Data-Engine-Spec; PB-001 backlog | — | Deferred Critical |
| F041 | Market Data | Trading calendar product | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | PARTIAL | NO | NO | N/A | PARTIAL | P2 | — | SD-003 deferred | `TradingCalendar.php` unit test only | — |
| F042 | Data Quality | Data quality detection/resolution | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | YES | PARTIAL | P2 | SPEC GAP | Not in MVP_SCOPE | `DataQualityGuardService`, admin UI | Full subsystem |
| F043 | Data Quality | Corporate action price repair | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | PARTIAL | YES | YES | P2 | SPEC GAP | Not in MVP_SCOPE | Repair services, deploy scripts | — |
| F044 | Market Data | Index/benchmark prices | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P2 | — | Market-Analysis-Spec | `IndexPriceSyncService`, Indices page | — |

---

## 5. Indicators

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F045 | Indicators | Fixed supported-indicator catalogue | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config SD-028 | `SupportedIndicators.php` | — |
| F046 | Indicators | Technical indicators (SMA, RSI, ATR, etc.) | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | YES | P1 | — | Screener-Spec | `TechnicalIndicatorService` | — |
| F047 | Indicators | Relative strength vs NIFTY | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Evaluation-Engine-Spec | `RelativeStrengthService` | — |
| F048 | Indicators | Unified Indicator Registry (full) | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | PARTIAL | PARTIAL | PARTIAL | PARTIAL | N/A | YES | P2 | CODE GAP | SD-033; MVP excluded | `IndicatorRegistry`, Admin API | Epics 1–2 only |
| F049 | Indicators | Liquidity/Tradability calculators | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | N/A | PARTIAL | NO | NO | N/A | YES | P3 | — | SD-033; PB-057 | `LiquidityTradabilityCalculator` unit test | Not wired to evaluation |
| F050 | Indicators | Strategy-param → Evaluation wiring | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P2 | — | PB-054 backlog | — | Future |

---

## 6. Screeners

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F051 | Screeners | Screener entity CRUD | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Screener-Spec | `ScreenerController`, `portfolio_screeners` | — |
| F052 | Screeners | Expression tree evaluation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Screener-Spec | `ScreenerEvaluationService` | — |
| F053 | Screeners | Screener versioning | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Screener-Spec | `ScreenerVersioningService` | — |
| F054 | Screeners | Screener run (live) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Screener-Spec | `ScreenerRunService`, `POST /screeners/{id}/run` | — |
| F055 | Screeners | Resumable screener runs | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Screener-Spec | Chunked continue API | — |
| F056 | Screeners | Scheduled screener runs | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | PARTIAL | P2 | — | Screener-Spec | `portfolio:run-due-screeners` every minute | — |
| F057 | Screeners | Screener run history | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Screener-Spec | `ScreenerRun`, compare table UI | — |
| F058 | Screeners | Screener backtesting (hit matrix) | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | YES | P2 | SPEC GAP | Not in MVP_SCOPE | `ScreenerBacktestService`, editor UI | See BACKTEST-COVERAGE.md |
| F059 | Screeners | Screener registry (import/export) | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | SD-034 partial | `ScreenerRegistryController` | Design landed |
| F060 | Screeners | Shared screener import | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | PARTIAL | P3 | SPEC GAP | Not in MVP_SCOPE | `ScreenerSharedTab.jsx` | — |

---

## 7. Discovery, Candidates, Patterns, Signals

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F061 | Discovery | Discovery runs persisted | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Discovery-Spec | `DiscoveryRun`, `POST /v1/discovery/runs` | — |
| F062 | Discovery | Pattern scan candidates | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Discovery-Spec | `PatternScanService`, `PatternScanController` | — |
| F063 | Discovery | Screener hit candidates | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Discovery-Spec SD-030 | `DiscoveryEngine` screener hits | — |
| F064 | Discovery | Holdings/watchlist fallback | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Discovery-Spec | `DiscoveryEngine` membership | — |
| F065 | Candidates | Candidate entity + evidence JSON | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Discovery-Spec | `Candidate`, `GET /v1/candidates` | — |
| F066 | Candidates | Candidates UI with filters | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Discovery-Spec | `CandidatesPage.jsx` `/candidates` | — |
| F067 | Patterns | Candlestick/chart pattern detection | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Discovery-Spec | Pattern detectors, `/patterns` | — |
| F068 | Signals | Standalone signal entity/API | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | NO | NO | NO | NO | N/A | N/A | P3 | — | Domain model intent | Signals embedded in candidate evidence only | By design for V1 |
| F069 | Discovery | Full-universe mandatory scan | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | Optional universe sync exists | — |
| F070 | Discovery | Dedicated Discovery Engine spec doc | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | N/A | N/A | N/A | N/A | N/A | P3 | DOCUMENTATION GAP | MVP_SCOPE excluded | Page spec only | Accepted |

---

## 8. Evaluation, Scoring, Ranking, Risk

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F071 | Evaluation | Evaluation runs persisted | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Evaluation-Engine-Spec | `EvaluationRun`, `POST /v1/evaluation/runs` | — |
| F072 | Evaluation | Factor facts (RS, momentum, trend, volume, risk) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Evaluation-Engine-Spec | `EvaluationEngine` | — |
| F073 | Evaluation | Weighted scoring | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Evaluation-Engine-Spec | `StrategyConfigurationService::score()` | — |
| F074 | Evaluation | Deterministic ranking | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Evaluation-Engine-Spec | `EvaluationEngine`, pipeline test | — |
| F075 | Evaluation | Passed/failed rules + evidence | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Evaluation-Engine-Spec | `EvaluationResult` evidence JSON | — |
| F076 | Evaluation | Evaluations UI | V1_REQUIRED | IMPLEMENTED | DEVIATION:DEV-001 | N/A | YES | YES | YES | N/A | PARTIAL | P2 | UI GAP | Evaluations page spec | `/evaluations` redirects to `/candidates` | Combined discovery view |
| F077 | Scoring | Market regime factor in evaluation | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | N/A | PARTIAL | N/A | N/A | N/A | N/A | P3 | — | MVP excluded; SD-028 stub | `EvaluationEngine` line 319: stub 50.0 | Stub only |
| F078 | Scoring | Sector strength factor | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | N/A | PARTIAL | N/A | N/A | N/A | N/A | P3 | — | MVP excluded | `EvaluationEngine` line 320: stub 50.0 | Stub only |
| F079 | Risk | ATR-based risk score | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Evaluation-Engine-Spec | `EvaluationEngine` risk_score | — |
| F080 | Evaluation | ML scoring / pluggable rules engine | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | Future |

---

## 9. Strategy Configuration

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F081 | Strategy | Strategy entity (single active) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config SD-027 | `TradingStrategy`, `portfolio_tos_strategies` | — |
| F082 | Strategy | Versioned strategy config JSON | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config-Spec | `TradingStrategyVersion` | — |
| F083 | Strategy | Scoring factors + weights | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config-Spec | `GET/PUT /v1/strategy/scoring` | — |
| F084 | Strategy | Weight validation (sum = 100) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config SD-028 | Auto-normalize on save | — |
| F085 | Strategy | Thresholds (buy/sell/hold/watch) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config-Spec | `GET/PUT /v1/strategy/thresholds` | — |
| F086 | Strategy | Portfolio rules (max positions, etc.) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Strategy-Config-Spec | Strategy config JSON | — |
| F087 | Strategy | Exit strategy rules | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Strategy-Config-Spec | `ExitStrategyEvaluator` | — |
| F088 | Strategy | Eligibility via screeners only | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-030 | `StrategyEligibilityService` | — |
| F089 | Strategy | Factory Minervini default seed | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | N/A | N/A | YES | P1 | — | MVP_SCOPE | `FactoryMomentumStrategy`, seeder | — |
| F090 | Strategy | Strategy UI (/strategy) | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Strategy-Config-Spec | `StrategyPage.jsx` | — |
| F091 | Strategy | Strategy registry (portable JSON) | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | SD-034 partial | `StrategyRegistryController` | — |
| F092 | Strategy | Multi-strategy isolation / A-B | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | Single active strategy only | — |
| F093 | Strategy | Strategy backtesting | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | PARTIAL | P2 | SPEC/TEST GAP | Not in MVP_SCOPE | `BacktestSimulationEngine`, `/backtests` | See BACKTEST-COVERAGE.md |

---

## 10. Market Analysis & Gates

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F094 | Market Analysis | Market phase detection | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Market-Analysis SD-032 | `MarketAnalysisEngine` | — |
| F095 | Market Analysis | Market sentiment score | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Market-Analysis-Spec | `GET /v1/market-analysis/sentiment` | — |
| F096 | Market Analysis | Market breadth metrics | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Market-Analysis-Spec | Dashboard gauges | — |
| F097 | Market Analysis | Allocation multiplier | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | PARTIAL | N/A | PARTIAL | P2 | UI GAP | Strategy-Spec market gates | `MarketAnalysisEngine` allocation_multiplier | UI exposure limited |
| F098 | Market Analysis | Market gates in live recommendations | V1_REQUIRED | PARTIALLY_IMPLEMENTED | — | YES | PARTIAL | YES | PARTIAL | N/A | PARTIAL | P2 | CODE GAP | Strategy-Spec | `MarketAnalyticsService` | Integration depth varies |
| F099 | Market Analysis | Market gates in strategy backtest | V1_OUT_OF_SCOPE | SPECIFIED_NOT_IMPLEMENTED | — | N/A | NO | N/A | N/A | N/A | N/A | P3 | CODE GAP | Not required V1 | Disabled in `SimulationDayProcessor` | No historical series |

---

## 11. Recommendations & Lifecycle

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F100 | Recommendation | Five-stage generation pipeline | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Recommendation SD-023/026 | `RecommendationGenerationPipeline` | — |
| F101 | Recommendation | OPEN/INCREASE/REDUCE/EXIT actions | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Recommendation-Spec | `TradingRecommendation` action enums | — |
| F102 | Recommendation | HOLD_POSITION / WATCH informational | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-022 | Auto-published status | — |
| F103 | Recommendation | Market Opinion + Execution Plan fields | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-023 | Migration 2026_07_25_000005 | — |
| F104 | Recommendation | Approve / Reject / Defer | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Recommendation-Spec | `RecommendationLifecycleService` | — |
| F105 | Recommendation | Approve → pending_execution (not execute) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-025 | Separate approval from execution | — |
| F106 | Recommendation | Recommendation expiry | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | TEST GAP | Recommendation-Spec | `expire` endpoint | — |
| F107 | Recommendation | Recommendations UI (actionable vs insights) | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | UI spec | `RecommendationsPage.jsx` | — |
| F108 | Recommendation | Evidence / explainability on recs | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Recommendation-Spec | Evidence JSON on recommendations | — |
| F109 | Recommendation | Capital allocation on generation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-026 | `ScorePriorityCapitalAllocator` | — |
| F110 | Recommendation | Position-aware sizing | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Recommendation-Spec | Target/max position % rules | — |

---

## 12. Execution, Orders, Pending Execution

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F111 | Execution | Pending execution queue | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Execution-Spec SD-025 | `GET /v1/recommendations/pending-execution` | — |
| F112 | Execution | Manual execute via Transactions | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Execution-Spec SD-021 | `TransactionWriteService` + `recommendation_id` | — |
| F113 | Execution | Cancel pending execution | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Execution-Spec | `cancel-execution` endpoint | — |
| F114 | Execution | Recommendation ↔ transaction traceability | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | SD-021 | `recommendation_id` on transactions | — |
| F115 | Execution | Orders API (legacy v1) | V1_REQUIRED | IMPLEMENTED | DEVIATION:DEV-004 | YES | YES | YES | PARTIAL | N/A | YES | P2 | UI GAP | Execution-Spec | `/v1/orders*`; Review page uses | No dedicated Orders page — accepted |
| F116 | Execution | execute_now defaults false | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | N/A | N/A | YES | P1 | — | SD-025 | `ExecutionEngine` | — |
| F117 | Execution | Broker automation (Zerodha) | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | Future live-trading |
| F118 | Execution | GTT / stop-target orders | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | `StoplossService` exists for alerts only | — |
| F119 | Execution | Partial fills | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | — |
| F120 | Execution | Pending Execution UI panel | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | UI spec | `PendingExecutionPanel.jsx` `/transactions/pending` | — |

---

## 13. Notifications

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F121 | Notifications | Telegram delivery (actionable only) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Notification-Spec SD-009 | `NotificationEngine`, `TelegramNotificationService` | — |
| F122 | Notifications | Skip when Telegram unconfigured | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | MVP_SCOPE | Pipeline completes without error | — |
| F123 | Notifications | Notification history + retry | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | TEST GAP | Notification-Spec | `TosNotification`, `/notification-history` | — |
| F124 | Notifications | HOLD/WATCH not notified | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | N/A | N/A | YES | P1 | — | SD-022 | `NotificationEngine` filter | — |
| F125 | Notifications | Email channel | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | — |
| F126 | Notifications | Webhook channel | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | — |
| F127 | Notifications | Portfolio alerts (non-TOS) | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | YES | YES | P2 | SPEC GAP | Not in MVP_SCOPE | `AlertService`, `AlertPolicyService` | Parallel alert system |

---

## 14. Review & Analytics

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F128 | Review | Review report generation | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | PARTIAL | P1 | TEST GAP | Review-Engine-Spec | `ReviewEngine`, `POST /v1/reviews/generate` | — |
| F129 | Review | Review dashboard | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | Review-Engine-Spec | `ReviewDashboardPage.jsx` | — |
| F130 | Review | Actionable vs informational metrics | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | SD-022 | Separate outcome tables | — |
| F131 | Review | Recommendation effectiveness / outcomes | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | TEST GAP | Review-Engine-Spec | `GET /v1/review/outcomes` | — |
| F132 | Review | Full attribution / tax reporting | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | — |
| F133 | Analytics | Portfolio analytics (TOS) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | PARTIAL | N/A | PARTIAL | P2 | UI GAP | Analytics-Architecture SD-031 | `PortfolioAnalyticsService`, v1 endpoints | No dedicated analytics page |
| F134 | Analytics | Stock analytics / research | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Analytics-Architecture | `StockAnalyticsService`, Explorer, Watchlist | — |
| F135 | Analytics | Market analytics bundle | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | PARTIAL | N/A | PARTIAL | P2 | UI GAP | Analytics-Architecture | `MarketAnalyticsService` | Dashboard partial |
| F136 | Analytics | Legacy portfolio analytics | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Portfolio-Analytics-Spec | `GET /analytics/portfolio` | — |
| F137 | Analytics | Recommendation preview | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | N/A | YES | YES | PARTIAL | N/A | NO | P3 | TEST GAP | Not in MVP_SCOPE | `RecommendationPreviewService` | — |

---

## 15. Dashboard, Watchlist, UI Platform

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F138 | Dashboard | Portfolio summary dashboard | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Dashboard-Spec | `DashboardPage.jsx`, `GET /dashboard` | — |
| F139 | Dashboard | TOS analytics dashboard card | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Dashboard-Spec | v1 analytics dashboard endpoint | — |
| F140 | Watchlist | Multi-watchlist CRUD | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P1 | — | Watchlist-Spec | `WatchlistService`, limits 20×100 | — |
| F141 | Watchlist | Pattern scan integration | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | YES | P2 | — | Watchlist-Spec | `WatchlistResearchPanel` | — |
| F142 | Frontend | TOS nav pages (Discovery, Recs, Strategy, Cash, Review) | V1_REQUIRED | IMPLEMENTED | DEVIATION:DEV-001 | N/A | YES | YES | YES | N/A | PARTIAL | P1 | TEST GAP | UI 07-Trading-OS-Pages | `navigation.js`, routed pages | Evaluations merged into Candidates |
| F143 | Frontend | In-app contextual help docs | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | N/A | YES | YES | YES | N/A | NO | P3 | SPEC GAP | Not in MVP_SCOPE | `appDocumentation.js`, `/documentation` | — |
| F144 | Frontend | Knowledge Board | V1_SCOPE_AMBIGUOUS | IMPLEMENTED | NOT_SPECIFIED | YES | YES | YES | YES | N/A | YES | P3 | SPEC GAP | Not in MVP_SCOPE | `KnowledgeBoardPage.jsx` | — |
| F145 | Frontend | Mandatory TypeScript / TanStack Query | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | N/A | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | JavaScript + axios | Accepted |

---

## 16. Pipeline, Scheduling, Infrastructure

| ID | Feature Area | Feature / Capability | V1 Scope | Primary Status | Secondary Attributes | DB | Backend | API | Frontend | Jobs | Tests | Priority | Gap Type | Spec Evidence | Implementation Evidence | Difference / Notes |
|----|--------------|---------------------|:------------:|-------------|----------------------|:--:|:-------:|:---:|:--------:|:----:|:-----:|:--------:|----------|---------------|------------------------|-------------------|
| F146 | Pipeline | Daily decision pipeline | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | YES | YES | P1 | — | Daily-Decision-Pipeline | `DailyDecisionPipeline`, `portfolio:decision-pipeline` | — |
| F147 | Pipeline | Pipeline run persistence | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | PARTIAL | YES | YES | P1 | — | Daily-Decision-Pipeline | `PipelineRun` | — |
| F148 | Pipeline | Optional scheduled pipeline | V1_REQUIRED | PARTIALLY_IMPLEMENTED | — | N/A | YES | N/A | N/A | YES | PARTIAL | P1 | CODE GAP | MVP_SCOPE | `TRADING_OS_PIPELINE_SCHEDULE=true` | Off by default; ops wiring |
| F149 | Pipeline | Run after daily sync hook | V1_REQUIRED | PARTIALLY_IMPLEMENTED | — | N/A | PARTIAL | N/A | N/A | PARTIAL | NO | P2 | CODE GAP | Implementation-Roadmap | Config exists; not fully wired | — |
| F150 | API | REST /api/v1 TOS endpoints | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | YES | N/A | YES | P1 | — | REST-API-Spec | `TradingOsController`, audit API_INVENTORY | — |
| F151 | API | OpenAPI generation | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | — | — |
| F152 | Architecture | Engine layer App\Engines\* | V1_REQUIRED | IMPLEMENTED | DEVIATION:DEV-003 | N/A | YES | YES | N/A | YES | YES | P2 | ARCHITECTURE GAP | App-Architecture interfaces | Concrete classes wrap Services | Accepted SD pattern |
| F153 | Architecture | Repository/DTO refactor | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | NO | NO | NO | N/A | N/A | P3 | — | MVP_SCOPE excluded | Service layer direct | — |
| F154 | Tests | TradingOsPipelineTest (E2E) | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | N/A | N/A | YES | P1 | — | MVP_SCOPE success criteria | `tests/Feature/TradingOsPipelineTest.php` | — |
| F155 | Tests | Broad feature test coverage | V1_REQUIRED | PARTIALLY_IMPLEMENTED | — | N/A | PARTIAL | PARTIAL | NO | N/A | PARTIAL | P2 | TEST GAP | MVP_VERDICT | 117 PHP tests; no React tests; backtest E2E missing | — |
| F156 | Artifacts | Trading Artifact Framework (full) | V1_OUT_OF_SCOPE | PARTIALLY_IMPLEMENTED | — | PARTIAL | PARTIAL | PARTIAL | PARTIAL | N/A | YES | P2 | CODE GAP | SD-034 design only | Registry infrastructure landed | PB-058+ |
| F157 | Paper Trading | Live paper trading mode | V1_OUT_OF_SCOPE | OUT_OF_SCOPE | — | N/A | PARTIAL | NO | NO | N/A | PARTIAL | P3 | — | live-trading specs | Paper classes backtest-only | See BACKTEST-COVERAGE.md |
| F158 | Audit | Logging / error handling | V1_REQUIRED | IMPLEMENTED | — | N/A | YES | YES | PARTIAL | N/A | PARTIAL | P2 | — | MVP_SCOPE | `PortfolioLoggerService` | — |
| F159 | Audit | Versioning (strategy/screener) | V1_REQUIRED | IMPLEMENTED | — | YES | YES | YES | YES | N/A | PARTIAL | P2 | — | Strategy-Config | Version tables + services | — |

---

## Traceability Index

### Spec → Implementation (V1 required gaps)

| Spec requirement | Gap ID(s) |
|------------------|-----------|
| Hard data publish gates | F040 (V1_OUT_OF_SCOPE / PB-001 deferred) |
| Evaluations dedicated page | F076 (IMPLEMENTED + DEVIATION:DEV-001) |
| Pipeline scheduling ops | F148, F149 |
| Market gates depth | F098 |
| Cash dedicated tests | F021–F030 TEST GAP |
| Strategy backtest E2E tests | F155 (F093 is V1_SCOPE_AMBIGUOUS, implemented) |

### Implementation → Spec (unspecified features)

See `IMPLEMENTED-BUT-UNSPECIFIED.md` for full list (15 NOT_SPECIFIED secondary attributes). Key: F058, F093, F003–F005, F014, F019, F020, F042, F043, F060, F127, F137, F143, F144.

---

*Generated 2026-08-08. Supersedes informal gap notes; complements `specs/architecture/audit/SPECIFICATION_TRACEABILITY.md`.*
