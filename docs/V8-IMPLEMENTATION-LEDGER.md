# StoX V8 implementation ledger

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` and linked `V8-*-Specification.md` files.

## Epic status

```text
FEAT-052  [REVIEW] OpenTelemetry / LidoTelemetry (producer core, shared middleware/route wiring, fail-open OTLP traces/events/metrics, browser fetch instrumentation, queue/scheduler context propagation and documented configuration; PHP SDK/extension, Collector and deployed acceptance remain open)
FEAT-054  [REVIEW] Historical fundamental bootstrap (summary/derived metrics, provenance, user-scoped Advanced preference, history UI, Screener boundary, and bootstrap evidence complete locally; browser/provider/deployed runtime acceptance remains)
FEAT-055  [REVIEW] Account access request / Admin approval (formal audit complete; production Turnstile/mail and deployed multi-worker validation remain external)
FEAT-056  [REVIEW] ML lifecycle automation (queued runs, SSE, drift, cancel, notifications, transient retries, retention, stale-run recovery and mixed scoring integration committed; deployed lifecycle acceptance remains open)
FEAT-057  [REVIEW] ML feature engineering / training (versioned horizon-resolved registry, PIT context refusal of current-universe fallback, authoritative dated provider adapter with resumable backfill, horizon-derived purge/embargo, training-only preprocessing, paired active/baseline evidence, pinned explainability, durable archive integrity, per-partition feature coverage and bounded same-architecture 1m/3m/6m training evidence verified locally; production provider population, deployed active-model pairing where applicable and investor-facing acceptance remain open)
FEAT-061  [REVIEW] Guided tour / onboarding (formal audit complete; missing-target regression, keyed i18n, desktop/mobile welcome-to-tour journeys, persisted-step resume, full configured-route traversal, manual-launch focus return, and Tab containment covered; screen-reader/live refresh acceptance remains open)
FEAT-062  [REVIEW] Fundamental signals & AI insights (complete deterministic catalogue matrix with PIT comparison evidence, provider-neutral orchestration, bounded investor-safe validation, follow-up guidance, and local desktop/mobile browser degradation coverage; accessibility and real-provider acceptance remain)
FEAT-063  [REVIEW] Live microstructure collection (local implementation, deterministic resilience verification, and systemd/provisioning contract validation complete; VPS installation, live Kite path, and deployed backup destination remain external)
FEAT-064  [REVIEW] Screener / Strategy UX (semantic versions, run/backtest pins, immutable save, readiness, provenance audit, WP-09 return flow and legacy fixture reconciliation present; Playwright now covers screener CRUD and incomplete-Strategy Setup Required behavior; live membership-drift/runtime acceptance remains)
FEAT-065  [REVIEW] Intraday ML historical data platform (schema/checkpoints/admin status, Mac backfill worker, explicit current-universe orchestration, idempotent Parquet corpus, bounded retry/failed-window reporting, DuckDB/Polars access and coverage reporting; live Kite POC/full corpus and handoff acceptance remain external)
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
- Node JS suite: **189 passed, 0 failed**; Vitest: **99 passed, 0 failed**.
- Vite production build: passed.
- Typecheck: passed.
- Static documentation check: passed.
- No `lint` script is declared in `app/package.json`; an attempted lint command reports the missing script rather than an application lint failure.
- The five inherited JS failures were resolved as three superseded V8 expectations (C), one stale documentation/help expectation (D), and one genuine request-account documentation gap fixed in the request-account catalog (D/B).

## Takeover reconciliation

See `docs/audit/V8-CODEX-TAKEOVER-WORKSPACE-RECONCILIATION.md`, `docs/audit/V8-FEAT-063-ACCEPTANCE-AUDIT.md`, `docs/audit/V8-FEAT-055-ACCEPTANCE-AUDIT.md`, and `docs/audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md` for evidence and remaining external validation. This ledger is verified against the current workspace as of 2026-09-28; it is not a claim that any epic is production complete.

## Verified continuation checkpoint (2026-09-28)

- The current checkout is the authoritative source for the exact HEAD; the earlier handoff SHA `3265357` is not the current baseline.
- The full Feature suite under local PHP CLI `memory_limit=512M` is **1,336 passed / 1,337 total / 1 skipped** when the documented x86_64 ML runtime is configured. The single skip is the extension-only OpenTelemetry assertion under the normal CLI; the extension-enabled direct PHPUnit run passes it. The previous FEAT-064 fixture cascade was repaired by giving activation-oriented legacy fixtures the executable factory configuration; the production Setup Required gate was not weakened.
- FEAT-064 browser smoke now passes **2/2**: screener create/save/runtime detail and incomplete Strategy Setup Required with Enable disabled; the browser test runs against deterministic API mocks and does not claim live membership-drift validation. FEAT-061 desktop/narrow-mobile welcome-to-tour, persisted-step resume and full configured-route traversal smoke now pass **4/4** in the focused guided-tour suite.
- FEAT-062 now covers the frozen deterministic catalogue with PIT-safe operating-profit/bottom-line, ROA/ROCE, working-capital/liquidity, capex, ownership/financial, persistent cash-quality and historical valuation-context signals; absent source data remains unavailable rather than inferred. It is REVIEW pending browser/mobile and real-provider acceptance.
- FEAT-056 lifecycle focus passes locally, including stale running-run recovery and stale cancellation finalization; mixed `MlScoringService` integration remains deliberately separated from FEAT-057.
- FEAT-056 scoring reconciliation is now committed as `2900e50`, with explicit `completed_rejected` quality outcomes and regression coverage in `73307cb`; FEAT-056 remains REVIEW for remaining deployment/acceptance evidence.
- FEAT-056 eligible runs now use the frozen `completed_eligible` terminal state, with `completed_rejected` and `failed` kept distinct (`bd4148b`).
- FEAT-052 telemetry focus passes **14/14**; FEAT-065 internal/admin focus passes **9/9**. The browser path now has an optional official OpenTelemetry SDK/fetch instrumentation module, disabled unless an OTLP endpoint is explicitly configured; live/deployed evidence remains external where documented.
- FEAT-056 lifecycle support and scoring reconciliation are committed in focused slices; the current checkout contains no separate uncommitted `MlScoringService` lifecycle diff pending ownership separation.
- FEAT-065 current-universe backfill orchestration is now committed as `c039937`; it consumes an explicit operator-supplied symbol/token manifest and does not add automated backup.
- FEAT-052 telemetry producer core is now committed as `fc8e422`; shared bootstrap/route wiring and collector/deployment acceptance remain separate follow-up work.
- FEAT-062 deterministic catalogue completion is committed across `c1caacf`, `d90a429`, `f2744df`, `e307229`, `b67a777`; focused Chromium acceptance now passes **3/3** for deterministic rendering, provider-failure degradation, and a 390px mobile viewport. The epic remains REVIEW pending screen-reader/accessibility and real-provider validation.
- FEAT-065 corpus storage, Kite historical client, coverage reporting and DuckDB/Polars handoff helpers are committed as `1fe3d6d`; bounded retry/backoff and failed-window checkpoint semantics are committed as `9e4985e`/`8b5cc0c`; the 20-test isolated corpus suite is green with DuckDB installed. The epic is REVIEW pending live Kite POC/full-corpus evidence.
- FEAT-057 candidate evidence is now durably archived by model version with an evidence checksum and idempotent re-persistence (`99ef02e`, `5802707`); the same-architecture bounded campaign passes **1/1, 1,425 assertions**, including persisted per-feature partition coverage.
- The post-archive-integrity full Feature suite is green at **1,336 passed / 1 skipped / 0 failures** with `STOXLA_ML_TEST_PYTHON` configured to the matching x86_64 runtime; the skip is limited to the normal-CLI OpenTelemetry extension check, while the bounded campaign itself runs with the matching runtime.
- The matching x86_64 bounded ML campaign now persists per-feature train/validation/test coverage for all three horizons when `STOXLA_ML_TEST_PYTHON` points to the isolated runtime.
- The same bounded campaign also reloads each persisted logistic artifact through the PHP adapter and verifies non-empty native contribution output; investor-facing browser acceptance remains external.
- FEAT-057 is now REVIEW: implementation and bounded same-architecture 1m/3m/6m evidence are complete locally; production dated-provider population, deployed active-model pairing where applicable and investor-facing browser acceptance remain external.
- Shared V8 API routes, middleware aliases, console commands, scheduler entries and environment documentation are wired in `c22c0bf`; unrelated inherited feature files remain intentionally preserved in the working tree.

## Next task

1. FEAT-056: perform deployed worker/runtime validation for the committed lifecycle implementation; no separate lifecycle WIP is currently pending ownership reconciliation.
2. FEAT-057: complete authoritative-provider/runtime coverage evidence, active-model paired runtime evidence where an active model exists, artifact prediction reload and investor-facing explainability acceptance.
3. FEAT-062: perform browser/mobile and real-provider acceptance; deterministic catalogue/PIT implementation is complete locally.
4. FEAT-065 / FEAT-052: close current-universe orchestration and telemetry/collector deployment evidence without conflating FEAT-065 with FEAT-063 backup.
5. FEAT-063 / FEAT-054 / FEAT-055 / FEAT-061: perform only the remaining external browser/provider/VPS validations.

## Failing tests

Historical context: earlier reruns recorded **1,329 tests: 1,328 passed, 1 skipped, 0 failures/errors** under local PHP CLI `memory_limit=512M`; the current authoritative suite result is recorded above in the verified continuation checkpoint.

The broad direct PHPUnit Feature run with `php -d memory_limit=512M vendor/bin/phpunit tests/Feature` currently completes **1,336 passed / 1,337 total / 1 skipped / 0 failures** with the matching x86_64 ML runtime. The skip is the normal-CLI OpenTelemetry extension check; the extension-enabled direct test passes. OpenAPI generated-spec drift and ML constructor failures are resolved; focused ML/provider, FEAT-062, telemetry and intraday suites pass.

## MlScoringService ownership reconciliation

The inherited `MlScoringService` lifecycle work was inspected and separated from FEAT-057 ownership. Queueing, cancellation, retries, lifecycle notifications, challenger registration, promotion review, drift dashboard, and investor insight orchestration are FEAT-056-owned and are now represented by focused committed slices (`2900e50`, `73307cb`, `bd4148b`); no separate uncommitted `MlScoringService` diff remains in the current checkout. FEAT-057 profile pinning used by training/artifacts is independently committed and tested.
