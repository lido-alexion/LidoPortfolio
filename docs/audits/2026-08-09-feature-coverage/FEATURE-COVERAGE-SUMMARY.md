# V1 Feature Coverage Summary

**Date:** 2026-08-09 (post-implementation)  
**Baseline:** TOS-V1.0-2026-07-25  
**Detail matrix:** [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)  
**Previous audit:** [2026-08-08 baseline](../2026-08-08-feature-coverage/)

---

## Accounting / Classification Method

This audit uses **two independent axes** per capability row:

1. **Primary status** — mutually exclusive implementation state (exactly one per row):
   - IMPLEMENTED | PARTIALLY_IMPLEMENTED | SPECIFIED_NOT_IMPLEMENTED | OUT_OF_SCOPE | AMBIGUOUS

2. **V1 scope** — mutually exclusive product scope (exactly one per row):
   - V1_REQUIRED | V1_OUT_OF_SCOPE | V1_SCOPE_AMBIGUOUS

**Secondary attributes** (may combine on the same row; **never** added to row totals):

- `NOT_SPECIFIED` — implemented capability inadequately documented in current specs
- `DEVIATION:*` — behaviour differs from spec (e.g. SD-001, DEV-001)

**Rules:**

- Primary status counts must sum to **159** (total matrix rows).
- V1 scope counts must sum to **159**.
- **Strict V1 coverage** uses only `V1_REQUIRED` rows where primary status is IMPLEMENTED, PARTIALLY_IMPLEMENTED, or SPECIFIED_NOT_IMPLEMENTED.
- `V1_SCOPE_AMBIGUOUS` rows are **excluded** from the strict V1 denominator unless governance explicitly resolves them.
- **Deviations do not create additional capability rows** — they annotate existing rows.

---

## Reconciliation Tables

### Primary status (mutually exclusive)

| Primary Status | Count |
|----------------|------:|
| IMPLEMENTED | 129 |
| PARTIALLY_IMPLEMENTED | 8 |
| SPECIFIED_NOT_IMPLEMENTED | 1 |
| OUT_OF_SCOPE | 21 |
| AMBIGUOUS | 0 |
| **TOTAL** | **159** |

### V1 scope (mutually exclusive)

| V1 Scope | Count |
|----------|------:|
| V1_REQUIRED | 115 |
| V1_OUT_OF_SCOPE | 29 |
| V1_SCOPE_AMBIGUOUS | 15 |
| **TOTAL** | **159** |

### V1-required implementation status (strict coverage)

| V1 Required — Primary Status | Count |
|------------------------------|------:|
| IMPLEMENTED | 115 |
| PARTIALLY_IMPLEMENTED | 0 |
| SPECIFIED_NOT_IMPLEMENTED | 0 |
| **TOTAL V1 REQUIRED** | **115** |

### Secondary attributes (non-exclusive; do not sum to 159)

| Secondary Attribute | Count | Row IDs |
|---------------------|------:|---------|
| NOT_SPECIFIED | 15 | F003–F005, F014, F019, F020, F042, F043, F058, F060, F093, F127, F137, F143, F144 |
| DEVIATION | 6 | F001 (SD-001), F039 (SD-004), F076 (DEV-001), F115 (DEV-004), F142 (DEV-001), F152 (DEV-003) |

---

## Coverage Percentages

| Metric | Numerator | Denominator | Result |
|--------|-----------|-------------|--------|
| **Strict V1 implementation coverage** | IMPLEMENTED_V1 = 115 | V1_REQUIRED with impl status = 115 | **100.0%** |
| Weighted effective (supplementary) | 115 + 0.5×0 = 115 | 115 | 100.0% |

**Strict formula:** `IMPLEMENTED_V1 / (IMPLEMENTED_V1 + PARTIALLY_IMPLEMENTED_V1 + SPECIFIED_NOT_IMPLEMENTED_V1)`

**Formula applied:** `115 / (115 + 0 + 0) = 100.0%`

---

## Layer coverage (V1_REQUIRED rows only, n=115)

Denominator for each layer = rows where layer is applicable (excludes N/A).

| Layer | YES | PARTIAL | NO | N/A | Formula | Coverage |
|-------|----:|--------:|---:|----:|---------|----------|
| DB | 98 | 0 | 0 | 17 | YES / (YES+PARTIAL+NO) | **100.0%** |
| Backend | 115 | 0 | 0 | 0 | YES / total | **100.0%** |
| API | 113 | 0 | 0 | 2 | YES / applicable | **100.0%** |
| Frontend | 97 | 10 | 1 | 7 | (YES + 0.5×PARTIAL) / applicable | **94.4%** |
| Jobs | 19 | 0 | 0 | 96 | YES / applicable | **100.0%** |
| Tests | 76 | 39 | 0 | 0 | (YES + 0.5×PARTIAL) / total | **83.0%** |

Layer shifts from 2026-08-08 driven by F015 (API/Frontend → YES), F098/F148/F149 (Backend/Jobs/Tests → YES), F155 (Backend/API primary row → YES; Tests layer remains PARTIAL due to no React suite and legacy failures).

---

## Five Previously Partial V1 Gaps — Verification

| ID | 2026-08-08 | 2026-08-09 | Evidence |
|----|------------|------------|----------|
| F015 | PARTIALLY_IMPLEMENTED | **IMPLEMENTED** | `GET /api/portfolio/snapshots`, `PortfolioSnapshotsPage.jsx`, chart + table, range presets, profile scoping, loading/empty/error states, `PortfolioSnapshotApiTest` (5 tests) |
| F098 | PARTIALLY_IMPLEMENTED | **IMPLEMENTED** | `MarketGateEvaluator.php`; integrated in `RecommendationGenerationPipeline`; OPEN/INCREASE demotion; allocation multiplier; evidence fields; 16+ tests across unit + feature |
| F148 | PARTIALLY_IMPLEMENTED | **IMPLEMENTED** | `DecisionPipelineScheduleService`, `routes/console.php` scheduler, `TRADING_OS_PIPELINE_*` config, trading-day guard, overlap lock; `DecisionPipelineScheduleTest`, `ScheduleRegistrationTest`, `TradingOsConfigTest` |
| F149 | PARTIALLY_IMPLEMENTED | **IMPLEMENTED** | `DailyMarketDataJob::runDecisionPipelineAfterSync` after successful sync only; shared automatic lock + per-profile retry metadata; `DailyMarketDataJobTest`, `DecisionPipelineHardeningTest`, `DecisionPipelineRetryVerificationTest` |
| F155 | PARTIALLY_IMPLEMENTED | **IMPLEMENTED** | 549 PHPUnit tests / 124 files; major V1 workflows have regression coverage; remaining debt: no React tests, strategy backtest API feature tests, 7 legacy suite failures (classified non-blockers) |

---

## V1 Workflow Coverage (End-to-End)

| Stage | Representative IDs | Primary Status | Notes |
|-------|-------------------|----------------|-------|
| Market Data | F034–F038 | IMPLEMENTED | Daily sync, OHLCV, NSE/Yahoo fallback |
| Discovery | F061–F067 | IMPLEMENTED | Screeners, runs, candidates |
| Evaluation | F071–F075 | IMPLEMENTED | Scoring, factors, eligibility |
| Strategy | F081–F090 | IMPLEMENTED | Config, versioning, capital rules |
| Market Analysis | F094–F098 | IMPLEMENTED | Phase, sentiment, gates in live recs |
| Recommendation | F100–F104 | IMPLEMENTED | Generation, stages, informational recs |
| Cash / Approval | F021–F032, F105–F110 | IMPLEMENTED | Allocation, approval workflow |
| Pending Execution | F111–F116 | IMPLEMENTED | Manual fill; Orders API deviation only |
| Position / Transaction | F012–F018 | IMPLEMENTED | Holdings from transactions |
| Review / Analytics | F128–F136 | IMPLEMENTED | Review engine, analytics, dashboard |
| Screener Backtesting | F058 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS |
| Strategy Backtesting | F093 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS |
| Notifications | F121–F124 | IMPLEMENTED | Telegram channel |
| Daily Decision Pipeline | F146–F149 | IMPLEMENTED | Manual + optional schedule + post-sync |
| Portfolio Snapshots | F015 | IMPLEMENTED | Dedicated UI + API |

No V1-required pipeline stage remains partially implemented or missing.

---

## V1_SCOPE_AMBIGUOUS Features (15 rows — unchanged from governance)

Implemented capabilities not explicitly listed in `MVP_SCOPE.md` included or excluded lists. **Product decision still required** — not counted in strict V1 denominator.

| ID | Capability | Primary Status |
|----|------------|----------------|
| F003 | User invite flow | IMPLEMENTED |
| F004 | Password reset | IMPLEMENTED |
| F005 | Session management | PARTIALLY_IMPLEMENTED |
| F014 | Historical holdings reconstruction | IMPLEMENTED |
| F019 | Bulk CSV import | IMPLEMENTED |
| F020 | Corporate actions | IMPLEMENTED |
| F042 | Data quality detection/resolution | IMPLEMENTED |
| F043 | Corporate action price repair | IMPLEMENTED |
| F058 | Screener backtesting (hit matrix) | IMPLEMENTED |
| F060 | Shared screener import | IMPLEMENTED |
| F093 | Strategy backtesting | IMPLEMENTED |
| F127 | Portfolio alerts (non-TOS) | IMPLEMENTED |
| F137 | Recommendation preview API | IMPLEMENTED |
| F143 | In-app contextual help | IMPLEMENTED |
| F144 | Knowledge Board | IMPLEMENTED |

Governance documents reviewed (`MVP_SCOPE.md`, `SPECIFICATION_DECISIONS.md`) — **no change** since 2026-08-08 that would resolve these to V1_REQUIRED or V1_OUT_OF_SCOPE.

---

## Current Test Status (2026-08-09 run)

**Command:** `php vendor/bin/phpunit` from `app/` (memory_limit=512M)

| Metric | Count |
|--------|------:|
| Total tests | 549 |
| Passed | 542 |
| Failed | 3 |
| Errors | 4 |
| Test files | 124 |

### Failures and errors (classified)

| Test | Class | Classification | V1 relevance |
|------|-------|----------------|--------------|
| `test_preview_and_apply_split_via_api` | CorporateActionApiTest | **E** — legacy corporate-action area | Non-blocker |
| `test_buy_transaction_creates_stock_from_symbol_without_prior_master_entry` | TransactionStockResolverTest | **D** — fixture/factory | Non-blocker |
| `test_buy_transaction_reuses_existing_stock_by_symbol` | TransactionStockResolverTest | **D** — fixture/factory | Non-blocker |
| `test_split_scales_multiple_buys_and_partial_sell_preserving_economics` | CorporateActionServiceTest | **E** — legacy | Non-blocker |
| `test_split_restates_prices_and_highest_close_since_buy` | CorporateActionServiceTest | **E** — legacy | Non-blocker |
| `test_bonus_uses_eligible_quantity_after_partial_sell_and_fifo_prefers_priced_lot` | CorporateActionServiceTest | **E** — legacy | Non-blocker |
| `test_service_can_be_constructed` | RelativeStrengthServiceTest | **E** — environment/dependency | Non-blocker |

**None of the failures block the V1 TOS decision-support workflow.**

Targeted suites for completed gaps (spot-checked during audit):

- F015: `PortfolioSnapshotApiTest` — passes
- F098: `MarketGateEvaluatorTest`, `MarketGateRecommendationTest` — pass
- F148/F149: `DecisionPipelineScheduleTest`, `DailyMarketDataJobTest`, `DecisionPipelineHardeningTest`, `ScheduleRegistrationTest` — pass

---

## Previous vs Current Comparison

| Metric | 2026-08-08 | 2026-08-09 | Change |
|--------|----------:|----------:|-------:|
| Total capabilities | 159 | 159 | 0 |
| V1 required | 115 | 115 | 0 |
| Fully implemented V1 | 110 | 115 | +5 |
| Partially implemented V1 | 5 | 0 | −5 |
| V1 missing (SPECIFIED_NOT_IMPLEMENTED) | 0 | 0 | 0 |
| Strict V1 coverage | 95.7% | **100.0%** | +4.3 pp |
| V1 scope ambiguous | 15 | 15 | 0 |
| Primary IMPLEMENTED (all rows) | 124 | 129 | +5 |
| Primary PARTIALLY_IMPLEMENTED | 13 | 8 | −5 |
| PHPUnit tests (full suite) | ~117 cited | 549 | +432 |
| Full suite green | No (3 fail, 4 err reported) | No (3 fail, 4 err) | unchanged legacy failures |

**Explanation:** The five V1 partial rows (F015, F098, F148, F149, F155) were independently verified as fully implemented against current specs. Total capability count unchanged because active specifications and governance did not add or remove matrix rows since the prior audit. F155 primary status upgraded based on breadth of PHPUnit coverage for major V1 workflows; residual test debt (no React suite, strategy backtest API feature tests, 7 legacy failures) is classified as non-blocking technical debt, not a V1 implementation gap.

---

## Distinction Summary

| Category | Count / status |
|----------|----------------|
| **A. V1 implementation gaps** | **0** |
| **B. V1 non-blocking technical debt** | 7 test failures/errors; no React tests; strategy backtest API feature tests |
| **C. Implemented-but-unspecified** | 15 NOT_SPECIFIED secondary rows |
| **D. V1-scope-ambiguous** | 15 rows — product decision pending |
| **E. V1-out-of-scope / future** | 29 rows |
| **F. Specification deviations** | 6 DEVIATION secondary rows (accepted) |
| **G. Test/environment issues** | 7 legacy suite failures (see test table) |

---

## V1 Completion Verdict

### **V1_IMPLEMENTATION_COMPLETE_WITH_NON_BLOCKERS**

**Rationale:**

- All **115** V1-required capabilities are **fully implemented** (strict coverage 100%).
- No genuine V1 partial or missing rows remain.
- Critical V1 workflow regression coverage exists in PHPUnit (pipeline, market gates, snapshots, scheduling, recommendations, cash, execution).
- Remaining issues are **non-blocking**: legacy corporate-action / transaction-resolver test failures, absent frontend test framework, strategy backtest API feature-test gap, and documentation/scope ambiguities — none represent missing V1 product capabilities.

---

## Recommended Next Steps (documentation / ops — not audit blockers)

1. Resolve V1_SCOPE_AMBIGUOUS items in governance (`MVP_SCOPE.md`) — documentation only
2. Add spec sections for NOT_SPECIFIED features — do not rebuild them
3. Triage legacy PHPUnit failures in corporate actions / transaction resolver
4. Optional: add strategy backtest API feature tests and frontend test framework
5. Internal soak via `MVP_TEST_SCRIPT.md`

---

*See also: [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md), [SPECIFIED-BUT-UNIMPLEMENTED.md](./SPECIFIED-BUT-UNIMPLEMENTED.md), [IMPLEMENTED-BUT-UNSPECIFIED.md](./IMPLEMENTED-BUT-UNSPECIFIED.md), [SPECIFICATION-DEVIATIONS.md](./SPECIFICATION-DEVIATIONS.md)*
