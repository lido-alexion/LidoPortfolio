# Known Limitations

**Audit date:** 2026-07-25  
**Scope:** Limitations present in the current implementation that affect MVP behavior.  
Excluded: pure future roadmap (broker automation, Strategy product, AI) unless they create an MVP gap.

---

## Data

1. **No hard publish/validation gate** — Downstream engines consume latest OHLCV even if sync is incomplete; `published` approximates “synced today”.  
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

8. **No market regime assessment** — Spec responsibility unimplemented.  
9. **Rules are hard-coded weighted components** — Not a pluggable rules engine.  
10. **Thin history UX** — Runs stored, but UI focuses on latest results.

---

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

23. **Pipeline schedule off by default** — `TRADING_OS_PIPELINE_SCHEDULE` false.  
24. **`run_after_daily_sync` not wired** — Config dead.  
25. **No OpenAPI for `/api/v1`**.  
26. **No Vitest / E2E UI tests** for TOS pages.  
27. **Sanctum not JWT** — Clients expecting Bearer tokens need SPA cookie flow.  
28. **Single large V1 controller** — Maintainability constraint.
