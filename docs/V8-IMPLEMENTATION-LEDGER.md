# StoX V8 implementation ledger

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` and linked `V8-*-Specification.md` files.

## Epic status

```text
FEAT-052  [REVIEW] OpenTelemetry / LidoTelemetry (config, OTLP HTTP exporter, API middleware, browser traceparent + route hooks; queue/scheduler/browser spans and full acceptance still open)
FEAT-054  [IN PROGRESS] Historical fundamental bootstrap (queue/run tables + bootstrap service + CLI; official adapters, UI completeness, and runtime acceptance still open)
FEAT-055  [REVIEW] Account access request / Admin approval (inherited workflow and V8 tests pass; full acceptance/security audit and production verification still open)
FEAT-056  [IN PROGRESS] ML lifecycle automation (queued runs, SSE, drift, cancel, notifications, transient retries; retention/recovery/acceptance still open)
FEAT-057  [IN PROGRESS] ML feature engineering / training (feature registry + dataset plan/admin APIs; full PIT-safe feature/training/validation corpus still open)
FEAT-061  [REVIEW] Guided tour / onboarding (backend/frontend foundation and tests present; full acceptance/accessibility/i18n/runtime audit still open)
FEAT-062  [IN PROGRESS] Fundamental signals & AI insights (deterministic + Gemini/Codex orchestrator; admin diagnostics and full acceptance still open)
FEAT-063  [IN PROGRESS] Live microstructure collection (control plane + Python worker; OI/microprice + bounded raw-tick spool; full schema, lifecycle, quality, backup, alerting, and VPS hardening gaps)
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
| Minute aggregation + Parquet (dry-run) | partial | `shared/microstructure/tests/test_minute_aggregator.py` |
| Live KiteTicker WebSocket | wired (`kite_ticker_bridge.py`) | manual VPS + kiteconnect |
| Finalization + partition backup | partial (`retry_backup`, post-finalize copy) | — |
| Operational alerts | partial (stale heartbeat / error / disk via `MicrostructureCollectorHealthService`) | `MicrostructureCollectorHealthAlertTest.php` |
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
| Watchlist fundamentals tab (summary, chart, Basic/Advanced tables, cadence toggle) | partial | manual |
| Screener `fund_*` operands (catalog + evaluateStock) | done | `FundamentalScreenerOperandTest.php` |
| Metric history API | done | `FundamentalInvestorSnapshotTest.php` |
| Watchlist revenue TTM mini-chart + deterministic insights | partial | manual |
| Advanced/collapsed statement tables | partial | `fundamentals_ui.php` + `FundamentalStatementTables.jsx` |
| Backtest PIT fundamental operands | done | `FundamentalScreenerOperandTest.php` |
| Screener editor fundamental indicator grouping | done | manual |

## Takeover reconciliation

See `docs/audit/V8-CODEX-TAKEOVER-WORKSPACE-RECONCILIATION.md` for the exact inherited working-tree inventory, baseline results, undocumented work, and risk classification. This ledger is verified against the current workspace as of 2026-09-28; it is not a claim that any epic is production complete.

## Next task

1. Repair and regression-test version-aware Screener backtest compatibility.
2. FEAT-063: complete minute schema, lifecycle/quality/finalization/backup semantics, universe recovery, and VPS runtime readiness.
3. FEAT-055 + FEAT-061 formal acceptance/security audits.
4. FEAT-052: queue/scheduler instrumentation, full OTEL SDK alignment, browser view-duration spans.
5. FEAT-054: official history, inline YoY cells, and per-user advanced preference API.

## Failing tests

(none recorded yet this session)
