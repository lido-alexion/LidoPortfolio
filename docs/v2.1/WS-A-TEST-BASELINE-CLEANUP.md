# V2.1 Workstream A — Test Baseline Cleanup

**Date:** 2026-08-10  
**Status:** **COMPLETE**  
**Scope:** Stabilize automated PHPUnit baseline only — no product feature changes  
**Predecessor audit:** [`V2.1-PRODUCT-HARDENING-AUDIT.md`](./V2.1-PRODUCT-HARDENING-AUDIT.md)

---

## Summary

| Metric | Before WS-A | After WS-A |
|--------|------------|------------|
| Tests | 679 | 679 |
| Passed | 676 | **679** |
| Failures | 2 | **0** |
| Errors | 1 | **0** |
| Risky | 7 | **0** |
| Assertions | 3442 | 3453 |
| Command | `php -d memory_limit=512M vendor/bin/phpunit` | same |
| Artifact | `docs/v2.1/_phpunit-baseline-raw.txt` | updated |

---

## 1. Original failures / errors

### E1 — `RelativeStrengthServiceTest::test_service_can_be_constructed`

| Field | Detail |
|-------|--------|
| Symptom | `ArgumentCountError`: ctor expects 3 deps; test passed 2 |
| Classification | **Test drift** (not production defect) |
| Root cause | `RelativeStrengthService` gained `DataQualityGuardService` (F042 DQ guard path); unit test not updated |
| Fix | Added `DataQualityGuardService` mock to test constructor |
| Layer | Test only |

### F1 — `StockPriceHistoryServiceTest::test_growth_and_relative_strength_calculation`

| Field | Detail |
|-------|--------|
| Symptom | `getGrowthPercentage` returned `null`; expected `10.0` |
| Classification | **Test drift** (not production defect) |
| Root cause | Fixture seeded OHLCV on calendar dates that can fall on weekends/holidays. `getCloseOnOrBeforeDate()` skips non-equity-session `price_date` rows, so anchor/latest closes were invisible to growth math |
| Fix | Normalize fixture dates via `TradingCalendar::normalizeToSessionDate()` (same pattern as passing `ExplorerAnalyticsTest::test_explore_endpoint_returns_growth_and_rs_from_cache`) |
| Layer | Test only — production behaviour (session-aware close lookup) preserved |

### F2 — `ExplorerAnalyticsTest::test_explore_uses_selected_index_benchmark`

| Field | Detail |
|-------|--------|
| Symptom | `benchmark.latest_close` **100** vs expected **105** |
| Classification | **Test drift** (not production defect) |
| Root cause | Same weekend/holiday fixture issue — latest NIFTYBANK row at `now()->subDay()` skipped; service fell back to older anchor close (100) |
| Fix | Normalize anchor and latest session dates with `TradingCalendar::normalizeToSessionDate()` |
| Layer | Test only |

**Triage note:** All three issues were **test/fixture drift** against intentional session-aware price lookup. No analytics redesign or production code changes required.

---

## 2. Risky tests (zero PHPUnit assertions)

| Test | Classification | Fix |
|------|----------------|-----|
| `PortfolioLoggerServiceTest::test_provider_logs_include_request_id_context` | Mock-only; legitimate improvement needed | Capture `$logged` flag in `withArgs`; `assertTrue($logged)` |
| `PortfolioLoggerServiceTest::test_log_frontend_payload_sanitizes_secrets` | Mock-only; legitimate improvement needed | Capture `$sanitized` flag; `assertTrue($sanitized)` |
| `UniversePriceSyncServiceTest::test_daily_sync_uses_fixed_daily_lookback_config` | Mock-only; legitimate improvement needed | Assert `$result['processed']` and `$result['succeeded']` |
| `AlertLifecycleOrderingTest::test_daily_market_job_expires_before_evaluating_on_new_trading_day` | Product behaviour requiring assertions | Track `$lifecycleSequence`; `assertSame(['expire', 'evaluate'], ...)` |
| `DecisionPipelineScheduleTest::test_successful_daily_sync_triggers_pipeline_when_hook_enabled` | Product behaviour requiring assertions | `$pipelineTriggered` flag via Artisan mock |
| `DecisionPipelineScheduleTest::test_successful_daily_sync_does_not_trigger_pipeline_when_hook_disabled` | Product behaviour requiring assertions | `$syncCompleted` callback after successful job |
| `DecisionPipelineScheduleTest::test_partial_daily_sync_does_not_trigger_pipeline` | Product behaviour requiring assertions | Capture `completeRun` status `'partial'` |

No risky tests were reclassified as “acceptable framework limitation” — all seven benefited from explicit assertions.

---

## 3. Files changed

| File | Change |
|------|--------|
| `app/tests/Unit/RelativeStrengthServiceTest.php` | Add `DataQualityGuardService` mock |
| `app/tests/Unit/StockPriceHistoryServiceTest.php` | Session-normalized fixture dates |
| `app/tests/Feature/ExplorerAnalyticsTest.php` | Session-normalized fixture dates (benchmark test) |
| `app/tests/Unit/PortfolioLoggerServiceTest.php` | Explicit log/sanitize assertions |
| `app/tests/Unit/UniversePriceSyncServiceTest.php` | Assert daily sync result counts |
| `app/tests/Feature/AlertLifecycleOrderingTest.php` | Assert expire→evaluate ordering |
| `app/tests/Feature/DecisionPipelineScheduleTest.php` | Assert pipeline hook + partial status |
| `docs/v2.1/_phpunit-baseline-raw.txt` | Updated full-suite output |
| `docs/v2.1/WS-A-TEST-BASELINE-CLEANUP.md` | This document |
| `DOCS.md` | Index WS-A |
| `implementation.md` | WS-A completion note |

**No application code, schema, migrations, or frontend changes.**

---

## 4. Remaining justified risks / watch items

| Item | Status | Notes |
|------|--------|-------|
| PHPUnit memory | **Documented** | Full suite requires `-d memory_limit=512M` |
| Historical CA UNIQUE flake | **Not reproduced** | Watch in WS-B / maintenance if it returns |
| Historical cash-seeding 422 | **Not reproduced** | Watch |
| No Vitest/E2E | **Unchanged** | Out of WS-A scope → V3 / PB-032 |
| Growth/RS on weekend calendar dates in other tests | **Partially addressed** | WS-A fixed known failures; grep for raw `now()->subDay()` in price fixtures if new flakes appear |

---

## 5. Findings for downstream workstreams

### WS-B (Money & AuthZ) — no blockers from WS-A

WS-A did not uncover production defects in cash or AuthZ paths. Continue WS-B as planned.

### V3 / backlog — not triggered by WS-A

- Strategy→Evaluation parameter wiring (TD-19) — behaviour unchanged; still V3  
- Dataset publish gate — unchanged  
- Explorer/RS **production** logic unchanged; failures were fixture drift only  

### Optional follow-up (P2, not WS-A)

- Audit other price-history tests for non-session fixture dates (prevent future flakes)  
- Document 512M in CI/dev README or composer test script  

---

## 6. Validation

```powershell
cd app
php -d memory_limit=512M vendor/bin/phpunit
```

**Result (2026-08-10):** **679 / 679 passed**, 0 failed, 0 errors, 0 risky.

---

## 7. Confirmation

- No product features implemented  
- No V2 initiatives reopened  
- No new feature IDs  
- No V3 work started  
- WS-A scope complete  
