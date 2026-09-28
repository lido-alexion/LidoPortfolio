# StoX V8 gap audit (requirement-level)

**Purpose:** Honest end-state check against frozen V8 specs. Complements [V8-ACCEPTANCE-AUDIT.md](V8-ACCEPTANCE-AUDIT.md) (test gates + checklists).

**Last verified:** 2026-09-28 — `cd app && php -d memory_limit=512M vendor/bin/phpunit tests/Feature` (**1,336** tests: 1,335 passed, 1 skipped); Node 20.19.1 JS/Vitest/build/typecheck/docs checks green; Python ML/microstructure/intraday focused suites green.

| Epic | Verdict | Remaining work (authoritative gaps) |
|------|---------|-------------------------------------|
| FEAT-052 | **partial** | Official OpenTelemetry PHP/JS SDKs; run `cpanel-lido-telemetry-probe.php?send=1` against production Collector (auth login + fundamentals/ML/screener/order events wired) |
| FEAT-054 | **partial** | Bank/NBFC + metric catalog; valuation history API + Watchlist P/E/P/B frequency toggle; gaps: live NSE/BSE exchange APIs at scale |
| FEAT-055 | **done** | — |
| FEAT-056 | **partial** | Retention opt-in + admin UI; lifecycle schedule/drift gates (`STOXLA_ML_LIFECYCLE_*`, `STOXLA_ML_DRIFT_TRIGGER_ENABLED`); no auto-promote/rollback |
| FEAT-057 | **partial** | 50/50 registered features; calibration; chrono grid v2 + benchmark-vol regime slices; durable candidate archive; same-architecture bounded 1m/3m/6m campaign with partition coverage and artifact reload/contribution evidence; production provider population and browser/deployed acceptance remain external |
| FEAT-061 | **done** | — |
| FEAT-062 | **partial** | Insights page, orchestrator, admin provider prefs, invocation log + daily limits + token/cost rollups, provider test API/UI; gaps: provider-native billing hooks |
| FEAT-063 | **partial** | VPS hardening; live Parquet production validation (`shared/microstructure/tests/test_parquet_store.py` + minute aggregator tests for schema_v1 fields) |
| FEAT-064 | **partial** | Runtime create/import/shared copy; audit PHPUnit; provenance incl. `definition_json`; Playwright screener and incomplete-Strategy `Setup Required` journey; remaining live membership drift/runtime acceptance |
| FEAT-065 | **partial** | Full NIFTY 500 + index backfill at scale (`instrument_resolver.py` + sample token map; Kite client + DuckDB/Polars builders shipped) |

## Evidence anchors (implemented slices)

- **Tests:** `app/tests/Feature/V8/` (190 cases with configured ML runtime); `app/tests/e2e/screener-investor-workflow.spec.js` (2 browser cases); `shared/intraday/tests` and `shared/microstructure/tests/` (isolated project-compatible suites).
- **Ledger:** [docs/V8-IMPLEMENTATION-LEDGER.md](V8-IMPLEMENTATION-LEDGER.md).
- **Behavior:** [implementation.md](../implementation.md) § V8 FEAT-* sections.

## Production deploy gate (not satisfied until gaps closed)

1. `php artisan test tests/Feature/V8/` green.
2. `npm run build` (Node 22) green.
3. Per-epic rows above reviewed; no epic marked **done** without spec § completion criteria met.
4. Migrations applied via `deploy/cpanel-migrate.php` when schema changed.

**Conclusion:** V8 is **not** production-complete per frozen wishlist; continue implementation on partial epics before claiming release closure.
