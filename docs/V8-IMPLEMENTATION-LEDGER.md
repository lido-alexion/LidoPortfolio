# StoX V8 implementation ledger

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` and linked `V8-*-Specification.md` files.

## Epic status

```text
FEAT-052  [REVIEW] OpenTelemetry / LidoTelemetry (config, OTLP HTTP exporter, API middleware, browser traceparent + route hooks; queue/scheduler/browser spans and full acceptance still open)
FEAT-054  [REVIEW] Historical fundamental bootstrap (summary/derived metrics, provenance, user-scoped Advanced preference, history UI, Screener boundary, and bootstrap evidence complete locally; browser/provider/deployed runtime acceptance remains)
FEAT-055  [REVIEW] Account access request / Admin approval (formal audit complete; production Turnstile/mail and deployed multi-worker validation remain external)
FEAT-056  [IN PROGRESS] ML lifecycle automation (queued runs, SSE, drift, cancel, notifications, transient retries; retention/recovery/acceptance still open)
FEAT-057  [IN PROGRESS] ML feature engineering / training (feature registry + dataset plan/admin APIs; full PIT-safe feature/training/validation corpus still open)
FEAT-061  [REVIEW] Guided tour / onboarding (formal audit complete; missing-target regression covered; browser accessibility/mobile journey and localization mechanism remain open)
FEAT-062  [IN PROGRESS] Fundamental signals & AI insights (deterministic + Gemini/Codex orchestrator, bounded investor-safe response validation, and follow-up evidence guidance; catalogue/UI/acceptance still open)
FEAT-063  [REVIEW] Live microstructure collection (local implementation and deterministic resilience verification complete; VPS installation, live Kite path, and deployed backup destination remain external)
FEAT-064  [REVIEW] Screener / Strategy UX (semantic versions, run/backtest pins, immutable save, readiness, provenance audit, and WP-09 return flow present; regression cleanup and full gate remain)
FEAT-065  [IN PROGRESS] Intraday ML historical data platform (checkpoints + admin status + Mac backfill worker; current-universe orchestration, coverage, and handoff acceptance remain)
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

## Next task

1. FEAT-063 external validation: install/validate the collector on the StoX VPS, then exercise the live Kite path and deployed backup destination when credentials/market conditions permit.
2. FEAT-054 external acceptance: browser/mobile fundamentals journey and representative provider/deployed bootstrap runtime.
3. FEAT-062: continue the deterministic signal catalogue and investor-facing acceptance audit; FEAT-054 is now the lower-rework dependency gate.

## Failing tests

(none recorded yet this session)
