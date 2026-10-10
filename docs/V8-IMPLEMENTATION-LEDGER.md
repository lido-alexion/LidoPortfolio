**Retirement update (2026-10-06): FEAT-063, FEAT-065 and V9-DATA-002 are retired from active scope. Historical entries later in this ledger retain dated implementation evidence only.**

# StoX V8 implementation ledger

Authoritative specs: `docs/archive/specs/LidoPortfolio-V8-Wishlist.md` and linked `V8-*-Specification.md` files.

## Epic status

```text
FEAT-052  [IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN] OpenTelemetry / LidoTelemetry (producer core, shared middleware/route wiring, fail-open OTLP traces/events/metrics, browser and PHP SDK paths, queue/scheduler propagation, production Collector integration and privacy mitigations verified; broader privacy, outage/recovery and sustained-delivery scenarios remain in the functional plan)
FEAT-054  [IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN] Historical fundamental bootstrap (PO-approved relaxed coverage criteria pass: 4,696/5,148 overall on the 2026-10-05 persisted-fact snapshot; 497/501 cached Nifty symbols with current facts on the 2026-10-07 read-only check; all 25 non-exempt fields pass and 15 approved exceptions remain excluded. The PO accepted the cached constituent view without a separate official-list refresh; provider/deployed runtime acceptance remains open)
FEAT-055  [IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN] Account access request / Admin approval (deployed implementation, security and lifecycle suites; remaining browser, race, outage and accessibility scenarios tracked in the functional plan)
FEAT-056  [IMPLEMENTED / DEPLOYED FUNCTIONAL ACCEPTANCE DEFERRED UNTIL FEAT-057 QUALIFICATION] ML lifecycle automation (queued runs, persistent per-horizon queue locking, SSE, drift, cancel, notifications, transient retries, retention, stale-run recovery and mixed scoring integration committed; deployed lifecycle acceptance remains open)
FEAT-057  [IMPLEMENTED] ML feature engineering / training (versioned horizon-resolved registry, PIT context refusal of current-universe fallback, authoritative dated provider adapter with resumable backfill, horizon-derived purge/embargo, training-only missing-value/outlier preprocessing, paired active/baseline evidence, pinned explainability, durable archive integrity, per-partition feature coverage and bounded same-architecture 1m/3m/6m training evidence verified locally; production apply/preflight, 1m/3m/6m evidence, deployed active-model comparison where applicable and investor-facing acceptance remain open)
FEAT-061  [IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN] Guided tour / onboarding (formal audit complete; missing-target regression, keyed i18n, viewport-safe placement, desktop/mobile/tablet welcome-to-tour journeys, persisted-step resume, in-progress browser-refresh recovery, scrim interception, full configured-route traversal, labelled modal descriptions, welcome/resume/step focus containment, Escape handling and manual-launch focus return covered; production session interruption and screen-reader/device acceptance remain open)
FEAT-062  [IMPLEMENTED / REAL-PROVIDER AND ASSISTIVE-TECH ACCEPTANCE OPEN] Fundamental signals & AI insights (complete deterministic catalogue matrix with PIT comparison evidence, provider-neutral orchestration, bounded investor-safe validation, follow-up guidance, local desktop/mobile degradation coverage and full axe accessibility coverage; screen-reader/device and real-provider acceptance remain)
FEAT-064  [IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN] Screener / Strategy UX (semantic versions, exact pinned runtime execution, immutable activation/dependency semantics, immutable save, readiness, no obsolete last-active restriction, provenance audit, WP-09 return flow and legacy fixture reconciliation; broader two-account, membership-drift, recommendation-resume and deployed assistive-technology acceptance remains)
FEAT-065  [RETIRED 2026-10-06] Intraday ML historical data platform (historical implementation evidence only; exclusive implementation and acceptance paths removed)
```

## Dependency order (implementation)

2. FEAT-055, FEAT-061, FEAT-052, FEAT-064 (largely independent)
3. FEAT-054 → FEAT-062, FEAT-057 (fundamentals)
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

## Frontend validation baseline (2026-09-29)

- Node `20.19.1` / npm `10.8.2` via the existing user NVM installation.
- Node JS suite: **193 passed, 0 failed**; Vitest: **101 passed, 0 failed**.
- Vite production build: passed.
- Typecheck: passed.
- Static documentation check: passed.
- No `lint` script is declared in `app/package.json`; an attempted lint command reports the missing script rather than an application lint failure.
- FEAT-052 browser telemetry now has focused Vitest coverage for uncaught exception/unhandled rejection hook registration, raw-reason exclusion, and fail-open setup (`otelBrowser.test.jsx`).
- The five inherited JS failures were resolved as three superseded V8 expectations (C), one stale documentation/help expectation (D), and one genuine request-account documentation gap fixed in the request-account catalog (D/B).

## Takeover reconciliation

See `docs/audit/V8-CODEX-TAKEOVER-WORKSPACE-RECONCILIATION.md`, `docs/audit/V8-FEAT-055-ACCEPTANCE-AUDIT.md`, and `docs/audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md` for evidence and remaining external validation. The separate FEAT-063 audit is retained as a historical record. This ledger's dated entries are not claims that any epic is production complete.

## Verified continuation checkpoint (2026-09-29)

- The current checkout is the authoritative source for the exact HEAD; the earlier handoff SHA `3265357` is not the current baseline.
- The latest full Feature suite under local PHP CLI `memory_limit=512M` is **1,346 passed / 1,347 total / 1 skipped / 0 failures** with **11,014 assertions** when the documented x86_64 ML runtime is configured. The remaining skip is the normal-CLI OpenTelemetry extension-only assertion. The previous FEAT-064 fixture cascade was repaired by giving activation-oriented legacy fixtures the executable factory configuration; the production Setup Required gate was not weakened.
- The latest configured Playwright run is **31/31 executed passed** with 21 intentional viewport/configuration skips across 52 configured journeys; it includes full WCAG 2A/2AA axe accessibility scans for guided tour, fundamentals, investor insights, Screener editor and Request an account plus FEAT-054 fundamentals desktop/mobile and two-user preference isolation journeys, FEAT-062, FEAT-064 and responsive-shell coverage.
- FEAT-064 browser smoke now passes **2/2**: screener create/save/runtime detail and incomplete Strategy Setup Required with Enable disabled; the browser test runs against deterministic API mocks and does not claim live membership-drift validation. FEAT-061 desktop/narrow-mobile welcome-to-tour, persisted-step resume and full configured-route traversal smoke now pass **4/4** in the focused guided-tour suite.
- FEAT-062 now covers the frozen deterministic catalogue with PIT-safe operating-profit/bottom-line, ROA/ROCE, working-capital/liquidity, capex, ownership/financial, persistent cash-quality and historical valuation-context signals; absent source data remains unavailable rather than inferred. Local browser/mobile and full axe coverage are verified; it is REVIEW pending screen-reader/device and real-provider acceptance.
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
- FEAT-056 queue admission now locks a seeded per-horizon database row before checking/inserting active runs, and trigger/context validation rejects malformed lifecycle requests (`3408846`, `ad3b68e`, `6c89592`); focused lifecycle coverage is **14/14 passed**.
- FEAT-065 now supports a separate operator-supplied broad/sector index manifest, preserves `NSE_INDEX` checkpoint/Parquet identity, and keeps index partitions distinct from equities (`148b423`); the isolated intraday suite is **22/22 passed**.
- FEAT-061 step changes now have an explicit polite live-region announcement for assistive technology (`66b8601`); the focused guided-tour Chromium suite is **5/5 passed**.
- The post-hardening full Feature suite is **1,341 passed / 1 skipped / 0 failures / 1,342 total** with 10,982 assertions under the matching x86_64 ML runtime.
- The same bounded campaign also reloads each persisted logistic artifact through the PHP adapter and verifies non-empty native contribution output; investor-facing browser acceptance remains external.
- FEAT-057 is now REVIEW: implementation and bounded same-architecture 1m/3m/6m evidence are complete locally; production dated-provider population, deployed active-model pairing where applicable and investor-facing browser acceptance remain external.
- Shared V8 API routes, middleware aliases, console commands, scheduler entries and environment documentation are wired in `c22c0bf`; unrelated inherited feature files remain intentionally preserved in the working tree.

## Post-hardening continuation (2026-09-28)

- FEAT-056 Admin lifecycle controls are now persisted and bounded: schedule enablement/cadence is stored per horizon, exposed through an Admin-only update route, surfaced in the dashboard, and consumed by the scheduler. Retained artifact-valid versions are visible with explicit rollback controls, and completed run evidence is available for both `completed_eligible` and `completed_rejected` terminal states. Focused lifecycle/API coverage: **19/19 passed** including Investor denial; frontend Admin static test and typecheck passed. Commits: `7493eeb`, `17e37c3`.
- FEAT-056 Admin status now also exposes the computed next scheduled run and durable active-run retry, cancellation, progress and failure state; fixed-date lifecycle coverage is **13/13 passed** and the Admin static test passes.
- FEAT-056 lifecycle status now includes a normalized latest-run outcome per horizon (trigger, terminal state, completion and failure context), avoiding reliance on raw JSON for operational review; focused lifecycle coverage remains **13/13 passed** with 40 assertions. Commit: `8617e34`.
- On 2026-09-29, the full Feature suite completed **1,345 passed / 1,347 total / 2 skipped / 0 failures** with 9,565 assertions under the matching x86_64 ML runtime after the training-only outlier-policy slice; the bounded FEAT-057 campaign itself remains separately verified at 1,449 assertions.
- FEAT-056 Definition-of-Done runbook added at `docs/current/ml-lifecycle-operations.md`, covering bounded schedules, durable run/retry/cancel recovery, candidate review, explicit promotion/rollback and deployed verification commands. Live deployment remains external.
- FEAT-061 focused Chromium acceptance now passes **11/11** across fundamental guided-tour journeys, narrow mobile, tablet, persisted resume, in-progress browser-refresh recovery, scrim interception, full route traversal, focus return and the legacy investor welcome smoke; the remaining status is still REVIEW for production session interruption, screen-reader and broader device acceptance.
- After OpenAPI regeneration for the FEAT-056 schedule route and the FEAT-061 browser assertion repair, the full Feature suite is **1,343 passed / 1,344 total / 1 skipped / 0 failures** with 10,998 assertions under the matching x86_64 ML runtime. The skip remains the normal-CLI OpenTelemetry extension assertion.
- After the explicit FEAT-056 Investor-denial regression, the full Feature suite is **1,344 passed / 1,345 total / 1 skipped / 0 failures** with 10,999 assertions under the matching x86_64 ML runtime.

## Next task

- **Current thread: v8 implementation disposition review, skipping FEAT-054 and FEAT-057 per PO direction.** The audits support IMPLEMENTED status for FEAT-052, FEAT-056, FEAT-061 and FEAT-062; their remaining product checks are tracked separately as functional acceptance.
- **FEAT-052:** production producer/Collector integration, browser-to-HTTP parentage, CLI-to-queue propagation, natural cron spans, metrics receipt, privacy mitigations and bounded fail-open probes support IMPLEMENTED. The broader privacy/outage matrix and historical 78,274 failed-span cause remain functional follow-up, not implementation blockers.
- **FEAT-056:** local lifecycle implementation and backend CI are verified. Keep schedules and lifecycle triggers disabled; deployed queue/SSE/cancellation/recovery acceptance waits for FEAT-057 qualification and a dedicated safe worker.
- **FEAT-061:** implementation and local API/frontend/Chromium/axe coverage are complete. Full deployed first-run/reset journeys, production interruption and native screen-reader/device checks remain functional follow-up. Remove the temporary Developer options before public hardening.
- **FEAT-062:** deterministic catalogue, provider adapters/failover, safety validation, limits/audit and local desktop/mobile/axe checks are complete. Real-provider and assistive-technology/device checks remain functional follow-up.
- **FEAT-064:** IMPLEMENTED / FUNCTIONAL ACCEPTANCE OPEN. PRs #72, #122 and #123 are merged; production build 493 is live. Missing Screener 4/5 snapshots were reconstructed from proven same-lineage immutable evidence, and active bindings 42, 43, 44 and 47 now have exact pins through revisions 57–60. Readiness and deployed runtime checks passed; broader two-account, membership-drift, recommendation-resume and deployed assistive-technology acceptance remains in the functional test plan and acceptance audit.
- **FEAT-054 and FEAT-057 are assigned to another thread (PO direction, 2026-10-07).** Skip both here. FEAT-057 production qualification remains a prerequisite for FEAT-056 operational acceptance; do not duplicate its campaign or alter its runtime.
- Handoff: before that direction arrived, this thread created FEAT-057 preflight campaign `abab7807-4745-473d-af36-7605ffccbb9e` on build `38107807be3293139732b71f397d106ee6e1ef1d`. Last read-only check (2026-10-07 00:59 IST) found it still in `preflight`, with no horizon results and one reserved `ml-acceptance` queue job. No training, promotion, rollback, schedule enablement, or model mutation was started. The other thread should check this campaign's live state before creating another.

## Failing tests

Historical context: earlier reruns recorded **1,329 tests: 1,328 passed, 1 skipped, 0 failures/errors** under local PHP CLI `memory_limit=512M`; the current authoritative suite result is recorded above in the verified continuation checkpoint.

The broad direct PHPUnit Feature run with `php -d memory_limit=512M vendor/bin/phpunit tests/Feature` currently completes **1,346 passed / 1,347 total / 1 skipped / 0 failures** with the matching x86_64 ML runtime and 11,014 assertions. The remaining skip is the normal-CLI OpenTelemetry extension check. OpenAPI generated-spec drift and ML constructor failures are resolved; focused ML/provider, FEAT-062, telemetry and intraday suites pass.

## MlScoringService ownership reconciliation

The inherited `MlScoringService` lifecycle work was inspected and separated from FEAT-057 ownership. Queueing, cancellation, retries, lifecycle notifications, challenger registration, promotion review, drift dashboard, and investor insight orchestration are FEAT-056-owned and are now represented by focused committed slices (`2900e50`, `73307cb`, `bd4148b`); no separate uncommitted `MlScoringService` diff remains in the current checkout. FEAT-057 profile pinning used by training/artifacts is independently committed and tested.


### FEAT-054 PO coverage acceptance — 2026-10-07

Read-only production reconciliation against the PO decision in `docs/decisions/2026-10-05-fundamental-field-availability-exceptions.md`:

- All-stocks coverage: **4,696 / 5,148 (91.2%)**, above the 50% target.
- Nifty coverage: the production cache held **501 symbols** (cache time `2026-10-03T21:00:09Z`, within the service's seven-day freshness window); **497 mapped active stocks had current persisted facts**. This exceeds the 490-stock minimum for 98% of 500. Four cached symbols were unmapped; no exchange refresh or fetch was performed.
- Field coverage: **all 25 non-exempt canonical facts** had at least one value and at least 50% coverage among covered stocks. The 15 PO-exempt facts remain excluded from those field thresholds.

The stock/field counts are from the persisted-fact coverage report dated 2026-10-05; the Nifty cache/fact check was repeated read-only on 2026-10-07. This records the PO coverage criteria as met against the production cache. Broader deployed provider/runtime acceptance remains open and is not represented as complete here.