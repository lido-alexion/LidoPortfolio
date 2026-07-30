# Technical Debt Register

**Audit date:** 2026-07-25

| ID | Debt item | Reason | Impact | Recommended future work | Priority |
|----|-----------|--------|--------|-------------------------|----------|
| TD-01 | No repository layer | Engines query Eloquent directly for speed of MVP | Harder to swap storage / unit-test isolation | Introduce repositories per engine aggregate | Medium |
| TD-02 | ExecutionEngine mutates Recommendation status | Lifecycle convenience | Ownership leak; future bugs on state machine | `RecommendationEngine::markExecuted()` called by Execution | Medium |
| TD-03 | Monolithic `TradingOsController` | Single V1 entry point | Merge conflicts; harder reviews | Split controllers by engine module | Low |
| TD-04 | Formal Data publish/validation deferred | A13 / reuse existing sync | Bad bars can skew scores | Validation job + published dataset flag before discovery | High |
| TD-05 | Position Review shallow | Time-boxed MVP | Weak SELL/HOLD quality | Stop-loss / ATR exits / allocation checks stage | High |
| TD-06 | `max_position_pct` unused | Incomplete wiring | Oversized suggestions possible | Enforce cap in size calculation | Medium |
| TD-07 | `pipeline.run_after_daily_sync` unused | Config added early | Ops confusion; missed automation | Hook after DailyMarketSyncService completion | Medium |
| TD-08 | Notification channel hardcoded | Telegram-only MVP | Painful to add email/webhook later | Channel interface + config iteration | Medium |
| TD-09 | Evaluation rules embedded in engine | A8 weighted scoring | Hard to A/B policies | Extract rule/score strategy classes | Medium |
| TD-10 | Dual API surfaces undocumented for external clients | Additive `/api/v1` | Client confusion | OpenAPI + migration guide legacy→v1 | Medium |
| TD-11 | TOS pages lack shared hooks/components | Fast page delivery | Duplicated fetch/error patterns | Extract `useTosQuery` / shared tables | Low |
| TD-12 | No Vitest/E2E for TOS UI | Backend feature tests prioritized | UI regressions undetected | Smoke Vitest + one Playwright path | Medium |
| TD-13 | Soft dataset versioning | Date-string version | Non-reproducible historical recomputes | Immutable dataset_version table | High |
| TD-14 | JWT in specs vs Sanctum in code | Existing SPA auth | Spec drift | Update Application/REST specs formally | Low |
| TD-15 | Discovery depends on stale screener hits | Avoid long inline screener | Missed opportunities if screeners idle | Optionally run default screener inside Discovery | Low |
| TD-16 | Review reports without UI | Dashboard prioritized | Reports API underused | Simple reports list page | Low |
| TD-17 | Logging not fully structured per App Arch §7 | Existing logger | Harder cross-engine tracing | Standardize engine/request_id fields | Low |
| TD-18 | Pagination inconsistent on v1 lists | Arrays returned for speed | Large portfolios may overfetch | Add page/pageSize everywhere | Low |
| TD-19 | Strategy indicator parameters ignored by EvaluationEngine | Strategy UI persists `parameters` (rsi_period, lookbacks, SMA periods, …) but Evaluation reads `trading_os.evaluation` defaults | Operators believe Strategy params change scores; they do not | Wire Evaluation to active Strategy parameters (fallback to trading_os). **Separate from Indicator Registry (SD-033)** — see PB-054 | High |
