# Known Limitations

**Audit date:** 2026-07-25  
**Scope:** Limitations present in the current implementation that affect MVP behavior.  
Excluded: pure future roadmap (broker automation, Strategy product, AI) unless they create an MVP gap.

**Post-V3:** Deferred / wishlist work is tracked in [`../../LidoPortfolio-V4-Wishlist.md`](../../LidoPortfolio-V4-Wishlist.md). Some KL rows may be partially superseded by V3 (see V4-HIST-*); verify before treating as open bugs.

---

## Data

1. **Hard publish gate is pipeline-only (V4-FEAT-022)** — The daily decision pipeline blocks Discovery unless last successful market sync is within 24 hours of the run (72 hours on Monday, timestamp comparison). Standalone Discovery/Evaluation/Recommendation APIs still consume latest OHLCV. `datasetStatus()['published']` still means “synced today” and is inspection-only. Holiday / exchange-calendar freshness is not implemented.  
2. **No trading calendar entity** — Missing sessions are not productized as Data Engine outputs.  
3. **Dataset version is a date string** — Not an immutable published snapshot identifier.  
4. **Pipeline does not sync market data** — It reads status; sync must be run separately (UI/scheduler).

---

## Discovery

5. **Screener contribution depends on recent prior screener runs** — Discovery does not always execute screeners inline.  
6. **Pattern scopes default to holdings/watchlist** — Not full exchange universe scan in MVP config.  
7. **No Discovery Engine specification document** — Behavior documented only via code + progress notes.

---

## Evaluation

8. **Market regime in Evaluation is live (V4-FEAT-005); sector strength is still a stub** — Evaluation `market_regime` is MarketAnalysisEngine Bullish=100 / Neutral=50 / Bearish=0. Strategy composite `sector_strength` remains constant 50. Backtest `AsOfFactorScorer` still stubs market regime at 50 (no historical Market Analysis).  
9. **Rules are hard-coded weighted components** — Not a pluggable rules engine.  
10. **Thin history UX** — Runs stored, but UI focuses on latest results.  
10a. **Strategy indicator parameters ignored by Evaluation** (2026-07-30) — Strategy UI persists periods/lookbacks/benchmark but EvaluationEngine reads `trading_os.evaluation`. Tracked TD-19 / PB-054; **not** solved by Indicator Registry Phases 1–3 (SD-033).

---

## Indicators / Registry

10b. **Dual catalogues (as-built)** — `ScreenerCatalog` + `SupportedIndicators` façades now project Indicator Registry (Epics 1–2); Admin Registry UI and full consumer cutover still pending (SD-033 / PB-055).  
10c. **Liquidity / Tradability composites are calculated (V4-FEAT-006)** — `TechnicalIndicatorService` dispatches `liquidity_score` / `tradability_score` to `LiquidityTradabilityCalculator` using the existing primary series. Formulas/caps/weights unchanged. Remaining by design: not Strategy-scorable, not Evaluation facts, not in the Screener picker (`screenable: false`). Primaries remain screenable.  
10d. **Trading Artifact Framework remainder is V5 (V4-FEAT-008)** (updated 2026-08-28) — Envelope/validation, package import/export, Indicator/Screener/Strategy registries, Create/Enable/Archive, and AI authoring/runtime docs are **shipped**. Remaining SD-034 work (immutable published versions vs Save-in-place, sharing/distribution, extra AI draft-from-schema UX, dependency dashboards, rollback, bundle UI, fork workflows) is **not** an active V4 target. Do not treat shipped registries as unimplemented. Tracked as V5 **V4-FEAT-008** / PB-058+ remainder.

## Recommendation / Position review

11. **Position Review is shallow** — Held names become SELL/HOLD from score thresholds only; no stop-loss, target, or allocation-impact analysis.  
12. **`max_position_pct` unused** — Suggested size uses `default_position_pct` only.  
13. **WATCH catch-all** — Non-held below buy threshold becomes WATCH (including weak scores).

---

## Notification

14. **Telegram only** — Email/webhook not implemented (by design for MVP).  
15. **Channel hardcoded** — Config `channels` list not dynamically selected.  
16. **Delivery requires portfolio Telegram settings** — Otherwise notifications skip/fail; pipeline still succeeds.

---

## Execution

17. **No broker integration** — User executes externally; system records fills.  
18. **Order `rejected` state unused** — Cancel covers user abort.  
19. **Legacy transactions may exist without TOS orders** — Hybrid ledger.

---

## Review

20. **No dedicated Reports UI** — Reports API exists; dashboard is primary UX.  
21. **Limited metric depth** — Drawdown / execution-quality analytics incomplete vs full Review spec.  
22. **No strategy comparison** — Single portfolio scope.

---

## Platform / ops

23. **~~Pipeline schedule off by default~~** — Closed by **V4-FEAT-010** (2026-08-28): `TRADING_OS_PIPELINE_SCHEDULE` defaults **true**; disable with `false`.  
24. **~~`run_after_daily_sync` not wired~~** — Obsolete; F149 wired the hook; **V4-FEAT-010** defaults it **on**.  
25. **No OpenAPI for `/api/v1`**.  
26. **No Vitest / E2E UI tests** for TOS pages.  
27. **Sanctum not JWT** — Clients expecting Bearer tokens need SPA cookie flow.  
28. **Single large V1 controller** — Maintainability constraint.
