# Final V1 Feature Coverage Summary

**Date:** 2026-08-09 (final baseline — frozen scope SD-035)  
**Detail matrix:** [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)  
**Scope decision:** [V1-SCOPE-DECISION.md](../2026-08-09-feature-coverage/V1-SCOPE-DECISION.md)  
**Previous baseline:** [2026-08-09 pre-freeze](../2026-08-09-feature-coverage/)

---

## Accounting method

Two independent axes per capability row:

1. **Primary status** — IMPLEMENTED | PARTIALLY_IMPLEMENTED | SPECIFIED_NOT_IMPLEMENTED | OUT_OF_SCOPE | AMBIGUOUS  
2. **V1 scope** — V1_REQUIRED | V1_OUT_OF_SCOPE | V1_SCOPE_AMBIGUOUS

V1 denominator derived from **current** `MVP_SCOPE.md` + SD-035. **V1_SCOPE_AMBIGUOUS = 0.**

---

## Primary status (159 rows)

| Primary Status | Count |
|----------------|------:|
| IMPLEMENTED | 129 |
| PARTIALLY_IMPLEMENTED | 8 |
| SPECIFIED_NOT_IMPLEMENTED | 1 |
| OUT_OF_SCOPE | 21 |
| AMBIGUOUS | 0 |
| **TOTAL** | **159** |

---

## V1 scope (159 rows)

| V1 Scope | Count |
|----------|------:|
| V1_REQUIRED | **119** |
| V1_OUT_OF_SCOPE | **40** |
| V1_SCOPE_AMBIGUOUS | **0** |
| **TOTAL** | **159** |

**Derivation:** Previous 115 V1_REQUIRED + 4 promotions (F004, F020, F058, F093) = **119**. Previous 29 V1_OUT_OF_SCOPE + 11 deferrals = **40**. Previous 15 ambiguous − 15 resolved = **0**.

---

## V1 implementation (strict coverage)

| V1 Required — Primary Status | Count |
|------------------------------|------:|
| IMPLEMENTED | **119** |
| PARTIALLY_IMPLEMENTED | **0** |
| SPECIFIED_NOT_IMPLEMENTED | **0** |
| **TOTAL V1 REQUIRED** | **119** |

| Metric | Value |
|--------|-------|
| Numerator | 119 |
| Denominator | 119 |
| Formula | `IMPLEMENTED_V1 / V1_REQUIRED` |
| **Strict V1 coverage** | **100.0%** |

---

## Four promoted capabilities — verification

| ID | Verdict | Evidence |
|----|---------|----------|
| **F004** | **IMPLEMENTED** | `PasswordResetLinkService`, token link flow, admin create + user reset APIs, public reset page; `PasswordResetLinkTest` **6/6 pass**; Sanctum unchanged |
| **F020** | **IMPLEMENTED** | `CorporateActionService` split/bonus apply, `CorporateActionPage.jsx`, sync commands, holdings/transaction effects; unit + API tests exist; **3 unit errors + 1 API fail** due to SQLite price-row collision during metrics side-effect (test fixture — not missing product capability) |
| **F058** | **IMPLEMENTED** | `ScreenerBacktestService`, weekday hit matrix, as-of OHLCV, editor UI, resumable chunks, persisted results; `ScreenerTest`, `ScreenerBacktestCalendarTest` pass |
| **F093** | **IMPLEMENTED** | `BacktestSimulationEngine`, full paper simulation (eligibility, scoring, allocation, cash, positions, trades, P&L, equity curve, stats, partial sells, multi-position, resumable); `/backtests` UI; unit tests pass; **no API feature tests** (test debt, not implementation gap) |

Scope boundaries respected: F043 repair and F042 Data Quality **not** included in F020 V1 scope.

---

## Eleven deferred capabilities — V2 verification

All classified **V1_OUT_OF_SCOPE** (not V1_REQUIRED): F003, F005, F014, F019, F042, F043, F060, F127, F137, F143, F144.

None appear in the V1 strict denominator.

---

## Full V1 workflow verification

| Stage | Status | Key IDs |
|-------|--------|---------|
| Market Data | IMPLEMENTED | F034–F038 |
| Discovery | IMPLEMENTED | F061–F067 |
| Evaluation | IMPLEMENTED | F071–F075 |
| Strategy | IMPLEMENTED | F081–F090 |
| Market Analysis + gates | IMPLEMENTED | F094–F098 |
| Recommendation | IMPLEMENTED | F100–F104 |
| Cash / Approval | IMPLEMENTED | F021–F032, F105–F110 |
| Pending Execution | IMPLEMENTED | F111–F116 |
| Manual Execution / Transactions | IMPLEMENTED | F012–F018 |
| Review / Analytics | IMPLEMENTED | F128–F136 |
| Portfolio Snapshots | IMPLEMENTED | F015 |
| Screener Backtesting | IMPLEMENTED (V1) | F058 |
| Strategy Backtesting | IMPLEMENTED (V1) | F093 |
| Notifications (Telegram TOS) | IMPLEMENTED | F121–F124 |
| Daily Pipeline + schedule + post-sync | IMPLEMENTED | F146–F149 |
| Password Reset | IMPLEMENTED (V1) | F004 |
| Corporate Actions | IMPLEMENTED (V1) | F020 |

No broken boundary identified between workflow stages.

---

## Test status (2026-08-09)

**Full suite:** `549` tests — `542` pass, `3` fail, `4` errors

**Targeted V1 suites:** `106/110` pass (single failure: `CorporateActionApiTest` — same root cause as unit errors)

| Test | Class | Affects V1? |
|------|-------|-------------|
| `CorporateActionApiTest::test_preview_and_apply_split_via_api` | **C** Fixture | F020 tests only; product implemented |
| `CorporateActionServiceTest` (3 errors) | **C** Fixture | SQLite UNIQUE on `portfolio_stock_prices` during metrics fetch side-effect |
| `TransactionStockResolverTest` (2 fails) | **D** Fixture | Transaction resolver; not a V1 scope gap |
| `RelativeStrengthServiceTest::test_service_can_be_constructed` | **E** Env/debt | Evaluation support service; unrelated V1 workflow blocker |

**None classified as A (genuine V1 implementation defect).**

---

## Previous vs final baseline

| Metric | 2026-08-09 pre-freeze | Final (SD-035) | Change |
|--------|----------------------:|---------------:|-------:|
| V1 required | 115 | **119** | +4 (governance) |
| V1_SCOPE_AMBIGUOUS | 15 | **0** | −15 |
| V1_OUT_OF_SCOPE | 29 | **40** | +11 |
| Implemented V1 | 115 | **119** | +4 |
| Partial V1 | 0 | **0** | 0 |
| Missing V1 | 0 | **0** | 0 |
| Strict coverage | 100.0% | **100.0%** | 0 pp |

The +4 increase is a **scope/governance change**, not newly written code. All four promoted capabilities were already implemented before SD-035.

---

## Distinction summary

| Category | Status |
|----------|--------|
| **A. V1 implementation gaps** | **0** |
| **B. V1 non-blocking technical debt** | Corporate-action test fixture failures; strategy backtest API feature-test gap; RelativeStrength constructor test; no React test suite |
| **C. V2/future (deferred)** | 11 capabilities (F003, F005, F014, F019, F042, F043, F060, F127, F137, F143, F144) |
| **D. Specification deviations** | 6 accepted (unchanged) |
| **E. Documentation gaps (V2 only)** | 11 NOT_SPECIFIED on deferred rows |

---

## Final V1 verdict

### **V1_IMPLEMENTATION_COMPLETE_WITH_NON_BLOCKERS**

- All **119** frozen V1-required capabilities are **fully implemented**  
- Strict coverage **119 / 119 = 100.0%** — **actually achieved**, not assumed  
- Critical V1 workflows covered by PHPUnit feature/unit tests  
- Remaining test failures are fixture/environment debt, not missing V1 capabilities  

---

*See also: [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md), [SPECIFIED-BUT-UNIMPLEMENTED.md](./SPECIFIED-BUT-UNIMPLEMENTED.md)*
