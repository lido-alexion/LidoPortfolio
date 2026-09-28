# StoX V8 gap audit (requirement-level)

**Purpose:** Honest end-state check against frozen V8 specs. Complements [V8-ACCEPTANCE-AUDIT.md](V8-ACCEPTANCE-AUDIT.md) (test gates + checklists).

**Last verified:** 2026-09-29 — `STOXLA_ML_TEST_PYTHON=/tmp/stox-v8-ml-x86-venv.anZaFw/bin/python arch -x86_64 php -d memory_limit=512M vendor/bin/phpunit tests/Feature` (**1,346 passed / 1,347 total, 1 extension-only skip, 0 failures, 11,008 assertions**); Node 20.19.1 JS/Vitest/build/typecheck/docs checks green; focused Chromium acceptance passed 9/9; Python ML/microstructure/intraday focused suites green.

| Epic | Verdict | Remaining work (authoritative gaps) |
|------|---------|-------------------------------------|
| FEAT-052 | **REVIEW** | Local producer, official browser/PHP SDK paths and fail-open tests are verified; Collector receipt, PHP extension deployment, queue/scheduler propagation and production probe remain external. |
| FEAT-054 | **REVIEW** | Local bootstrap, source precedence, derived metrics, history UI and Screener boundary are verified; browser/mobile, live provider and deployed bootstrap evidence remain external. |
| FEAT-055 | **REVIEW** | Domain, security, concurrency and notification-isolation behavior are locally verified; real Turnstile, mail delivery and deployed multi-worker contention remain external. |
| FEAT-056 | **REVIEW** | Durable lifecycle, retries, cancellation, recovery, retention, explicit promotion/rollback boundaries and notifications are locally verified; deployed worker/scheduler/SSE and production notification/archive evidence remain external. |
| FEAT-057 | **REVIEW** | Versioned/PIT-safe registry, same-architecture bounded 1m/3m/6m training, archive integrity, paired baseline evidence, partition coverage and artifact explainability are verified; production provider population, deployed active-model pairing and investor browser acceptance remain external. |
| FEAT-061 | **REVIEW** | Keyed i18n, missing-target behavior, persisted resume, full configured-route traversal, desktop/mobile/tablet journeys, focus return and Tab containment are verified; screen-reader review and broader-device acceptance remain. |
| FEAT-062 | **REVIEW** | Deterministic catalogue, PIT comparisons, provider-neutral bounded AI validation and degradation behavior are locally verified; browser/mobile and real-provider acceptance remain external. |
| FEAT-063 | **REVIEW** | Collector lifecycle, quality states, recovery, finalization, backup gating, alerts and resilience tests are locally verified; VPS installation, live Kite full-mode collection and deployed backup remain external. |
| FEAT-064 | **REVIEW** | Runtime create/import/shared copy, immutable provenance/readiness semantics, legacy fixture reconciliation and Playwright journeys are verified; live membership-drift/runtime acceptance remains external. |
| FEAT-065 | **REVIEW** | Schema-versioned Parquet, resumable/idempotent backfill, retries, checkpoints, coverage, DuckDB/Polars and manual-backup architecture are locally verified; live Kite POC/full corpus and handoff evidence remain external. |

## Evidence anchors (implemented slices)

- **Tests:** `app/tests/Feature/V8/` (191 total, 189 passed and 2 environment skips with configured ML runtime); `app/tests/e2e/fundamental-insights-browser.spec.js`, `screener-investor-workflow.spec.js` and `guided-tour-browser.spec.js` (focused Chromium acceptance 9/9); `shared/intraday/tests` and `shared/microstructure/tests/` (isolated project-compatible suites).
- **Ledger:** [docs/V8-IMPLEMENTATION-LEDGER.md](V8-IMPLEMENTATION-LEDGER.md).
- **Behavior:** [implementation.md](../implementation.md) § V8 FEAT-* sections.

## Production deploy gate (not satisfied until gaps closed)

1. `php artisan test tests/Feature/V8/` green.
2. `npm run build` (Node 22) green.
3. Per-epic rows above reviewed; no epic marked **COMPLETE** without spec § completion criteria met. All epics currently remain REVIEW or partial pending external evidence.
4. Migrations applied via `deploy/cpanel-migrate.php` when schema changed.

**Conclusion:** V8 is **not** production-complete per frozen wishlist; continue implementation on partial epics before claiming release closure.
