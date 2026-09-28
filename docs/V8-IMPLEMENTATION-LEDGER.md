# StoX V8 implementation ledger

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` and linked `V8-*-Specification.md` files.

## Epic status

```text
FEAT-052  [REVIEW] OpenTelemetry / LidoTelemetry (producer core, shared middleware/route wiring and documented OTLP configuration; queue/scheduler/browser spans and full acceptance still open)
FEAT-054  [REVIEW] Historical fundamental bootstrap (summary/derived metrics, provenance, user-scoped Advanced preference, history UI, Screener boundary, and bootstrap evidence complete locally; browser/provider/deployed runtime acceptance remains)
FEAT-055  [REVIEW] Account access request / Admin approval (formal audit complete; production Turnstile/mail and deployed multi-worker validation remain external)
FEAT-056  [IN PROGRESS] ML lifecycle automation (queued runs, SSE, drift, cancel, notifications, transient retries, retention and stale-run recovery committed; mixed scoring integration, quality-rejection mapping and deployed lifecycle acceptance remain open)
FEAT-057  [IN PROGRESS] ML feature engineering / training (versioned horizon-resolved registry, PIT context refusal of current-universe fallback, authoritative dated provider adapter with resumable backfill, horizon-derived purge/embargo, training-only preprocessing, paired active/baseline evidence, pinned explainability and bounded same-architecture 1m/3m/6m training evidence verified locally; durable archive/runtime coverage and investor-facing acceptance remain open)
FEAT-061  [REVIEW] Guided tour / onboarding (formal audit complete; missing-target regression covered; browser accessibility/mobile journey and localization mechanism remain open)
FEAT-062  [REVIEW] Fundamental signals & AI insights (complete deterministic catalogue matrix with PIT comparison evidence, provider-neutral orchestration, bounded investor-safe validation, and follow-up guidance; browser/mobile and real-provider acceptance remain)
FEAT-063  [REVIEW] Live microstructure collection (local implementation and deterministic resilience verification complete; VPS installation, live Kite path, and deployed backup destination remain external)
FEAT-064  [REVIEW] Screener / Strategy UX (semantic versions, run/backtest pins, immutable save, readiness, provenance audit, WP-09 return flow and legacy fixture reconciliation present; browser acceptance remains)
FEAT-065  [IN PROGRESS] Intraday ML historical data platform (schema/checkpoints/admin status, Mac backfill worker, explicit current-universe orchestration, idempotent Parquet corpus, bounded retry/failed-window reporting, DuckDB/Polars access and coverage reporting; full corpus coverage and handoff acceptance remain)
```

## Dependency order (implementation)

1. FEAT-063 (prospective data; parallel with 055/061/052)
2. FEAT-055, FEAT-061, FEAT-052, FEAT-064 (largely independent)
3. FEAT-054 → FEAT-062, FEAT-057 (fundamentals)
4. FEAT-065 → FEAT-057 (intraday corpus)
5. FEAT-057 → FEAT-056 (training evidence → lifecycle)

## FEAT-055 matrix (partial — updated as work lands)

| Requirement | Status | Tests |
|-------------|--------|-------|
| Public request + CAPTCHA | mostly done | `tests/Feature/V8/AccessRequestWorkflowTest.php` (8 tests) |
| Email verification before pending | done | same |
| Admin Create / Ignore / Reject | done | same |
| Request bans + clear | done | same |
| Ignore cooldown | done | `test_ignore_establishes_cooldown` |
| Prior history + audit | done | `AccessRequestWorkflowTest::test_verified_request_flow_and_admin_create` |

## FEAT-063 matrix (partial)

| Area | Status | Tests |
|------|--------|-------|
| Admin status API | done | `MicrostructureCollectorAdminTest.php` |
| Internal bootstrap API | done | `MicrostructureCollectorInternalApiTest.php` |
| Manual hold + commands | done | same |
| Kite login auto-start signal | done | same |
| Minute aggregation + Parquet (real pyarrow validation) | done locally | `shared/microstructure/tests/test_minute_aggregator.py`, `test_parquet_store.py`, `test_collector_finalization.py` |
| Live KiteTicker WebSocket | wired (`kite_ticker_bridge.py`) | manual VPS + kiteconnect |
| Finalization + partition backup | done locally (durable bounded retry, active-minute drain, corruption validation, atomic manifest/staged backup, spool pruning) | `test_finalization_state.py`, `test_collector_finalization.py`, `MicrostructureCollectorHealthAlertTest.php` |
| Reconnect + full-mode resubscription | done locally (bridge recovery and reconnect quality) | `test_kite_ticker_bridge.py`, `test_minute_aggregator.py` |
| No-trade/outage/reconnect quality rows | done | `test_minute_aggregator.py` |
| Operational alerts | done locally (stale heartbeat / error / disk / finalization / backup / low coverage; provider failures surface as actionable errors) | `MicrostructureCollectorHealthAlertTest.php` |
| Universe refresh audit | done locally (bounded additions/removals/mapping/conflict history and rejected partial refresh preservation) | `universe_audit.py`, `test_universe_audit.py` |
| VPS venv/systemd/runtime gate | external pending (provisioning + import checks + persistent paths are documented; service not installed on inspected VPS) | `deploy/systemd/stoxla-microstructure-collector.service`, shell syntax/runtime-contract checks |
| Telegram auth reminders | done | `MicrostructureCollectorKiteAuthReminderTest.php` |

## FEAT-061 matrix (partial)

| Requirement | Status | Tests |
|-------------|--------|-------|
| Investor-only API + persistence | done | `GuidedTourTest.php` |
| Welcome prompt / skip / dismiss | done | same |
| Manual relaunch (Profile) | done | — |
| Multi-route tour overlay | done | — |
| Full acceptance audit | pending | — |

## FEAT-054 matrix (partial)

| Area | Status | Tests |
|------|--------|-------|
| Bootstrap runs/jobs persistence | done | `FundamentalBootstrapWorkflowTest.php` |
| Manual CLI + dry-run | done | same |
| Yahoo historical ingest per stock | done (provider reuse) | same |
| TTM / YoY growth / FCF / ratio guards | done | `FundamentalMetricCorrectnessTest.php` |
| Admin bootstrap status API | done | — |
| NSE official JSON feed adapter | done | `NseOfficialFundamentalHistoricalTest.php` |
| BSE official JSON feed adapter | done | `BseOfficialFundamentalHistoricalTest.php` |
| Investor snapshot + history APIs | done | `FundamentalInvestorSnapshotTest.php` |
| Watchlist fundamentals tab (summary, chart, Basic/Advanced tables, cadence toggle) | done locally | `FundamentalInvestorSnapshotTest.php`, JS fundamentals tests; browser pending |
| Screener `fund_*` operands (catalog + evaluateStock) | done | `FundamentalScreenerOperandTest.php` |
| Metric history API | done | `FundamentalInvestorSnapshotTest.php` |
| Watchlist revenue TTM mini-chart + deterministic insights | done locally | `FundamentalInvestorSnapshotTest.php`, `FundamentalSignalsTest.php`; browser pending |
| Advanced/collapsed statement tables + user-scoped preference | done locally | `FundamentalStatementTables.jsx`, `fundamentalPreference.js`, JS fundamentals tests |
| Metric definitions + provenance | done locally | `fundamentalDefinitions.js`, `FundamentalInvestorSnapshotService.php`, `FundamentalInvestorSnapshotTest.php` |
| Backtest PIT fundamental operands | done | `FundamentalScreenerOperandTest.php` |
| Screener editor fundamental indicator grouping | done | manual |

## Frontend validation baseline (2026-09-28)

- Node `20.19.1` / npm `10.8.2` via the existing user NVM installation.
- Node JS suite: **188 passed, 0 failed**; Vitest: **99 passed, 0 failed**.
- Vite production build: passed.
- Typecheck: passed.
- Static documentation check: passed.
- No `lint` script is declared in `app/package.json`; an attempted lint command reports the missing script rather than an application lint failure.
- The five inherited JS failures were resolved as three superseded V8 expectations (C), one stale documentation/help expectation (D), and one genuine request-account documentation gap fixed in the request-account catalog (D/B).

## Takeover reconciliation

See `docs/audit/V8-CODEX-TAKEOVER-WORKSPACE-RECONCILIATION.md`, `docs/audit/V8-FEAT-063-ACCEPTANCE-AUDIT.md`, `docs/audit/V8-FEAT-055-ACCEPTANCE-AUDIT.md`, and `docs/audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md` for evidence and remaining external validation. This ledger is verified against the current workspace as of 2026-09-28; it is not a claim that any epic is production complete.

## Verified continuation checkpoint (2026-09-28)

- Actual checkout is `c22c0bf`; the earlier handoff SHA `3265357` is not the current HEAD.
- The full Feature suite under local PHP CLI `memory_limit=512M` now passes **1,331/1,332**, with one intentional bounded-training skip when `STOXLA_ML_TEST_PYTHON` is absent. The previous FEAT-064 fixture cascade was repaired by giving activation-oriented legacy fixtures the executable factory configuration; the production Setup Required gate was not weakened.
- FEAT-062 now covers the frozen deterministic catalogue with PIT-safe operating-profit/bottom-line, ROA/ROCE, working-capital/liquidity, capex, ownership/financial, persistent cash-quality and historical valuation-context signals; absent source data remains unavailable rather than inferred. It is REVIEW pending browser/mobile and real-provider acceptance.
- FEAT-056 lifecycle focus passes locally, including stale running-run recovery and stale cancellation finalization; mixed `MlScoringService` integration remains deliberately separated from FEAT-057.
- FEAT-052 telemetry focus passes **14/14**; FEAT-065 internal/admin focus passes **9/9**. Live/deployed evidence remains external where documented.
- FEAT-056 lifecycle support is now committed as `18dc08b`; the mixed `MlScoringService` integration remains intentionally uncommitted pending ownership separation.
- FEAT-065 current-universe backfill orchestration is now committed as `c039937`; it consumes an explicit operator-supplied symbol/token manifest and does not add automated backup.
- FEAT-052 telemetry producer core is now committed as `fc8e422`; shared bootstrap/route wiring and collector/deployment acceptance remain separate follow-up work.
- FEAT-062 deterministic catalogue completion is committed across `c1caacf`, `d90a429`, `f2744df`, `e307229`, `b67a777`; the epic is REVIEW pending browser/mobile and real-provider validation.
- FEAT-065 corpus storage, Kite historical client, coverage reporting and DuckDB/Polars handoff helpers are committed as `1fe3d6d`; bounded retry/backoff and failed-window checkpoint semantics are committed as `9e4985e`/`8b5cc0c`; the 20-test isolated corpus suite is green with DuckDB installed.
- Shared V8 API routes, middleware aliases, console commands, scheduler entries and environment documentation are wired in `c22c0bf`; dependent feature files remain intentionally preserved in the inherited workspace.

## Next task

1. FEAT-056: reconcile the preserved lifecycle WIP into tested commits, including route/controller wiring, durable run recovery and explicit promotion/rollback evidence.
2. FEAT-057: run bounded real training against populated dated snapshots and complete artifact reload, paired comparison, runtime explainability and coverage evidence.
3. FEAT-062: implement the remaining frozen partial signal rows, then browser/provider acceptance.
4. FEAT-065 / FEAT-052: close current-universe orchestration and telemetry/collector deployment evidence without conflating FEAT-065 with FEAT-063 backup.
5. FEAT-063 / FEAT-054 / FEAT-055 / FEAT-061: perform only the remaining external browser/provider/VPS validations.

## Failing tests

Latest rerun: **1,329 tests: 1,328 passed, 1 skipped, 0 failures/errors** under local PHP CLI `memory_limit=512M`; the existing paragraph below is historical checkpoint context.

The broad direct PHPUnit Feature run with `php -d memory_limit=512M vendor/bin/phpunit tests/Feature` now completes **1,321 tests: 1,320 passed, 1 skipped, 0 failures/errors**. The skip is the environment-gated bounded FEAT-057 campaign when its matching Python runtime is not configured. OpenAPI generated-spec drift and ML constructor failures are resolved; focused ML/provider, FEAT-062, telemetry and intraday suites pass.

## MlScoringService ownership reconciliation

The uncommitted `MlScoringService` diff was inspected and intentionally not committed as part of FEAT-057. Queueing, cancellation, retries, lifecycle notifications, challenger registration, promotion review, drift dashboard, and investor insight orchestration are FEAT-056-owned work and remain preserved as inherited WIP. The FEAT-057 profile pinning used by training/artifacts is already committed and tested; no additional scoring slice was found that could be safely committed without pulling the FEAT-056 dependency set into this commit.
