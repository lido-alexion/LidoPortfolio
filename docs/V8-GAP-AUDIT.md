# StoX V8 gap audit (requirement-level)

**Purpose:** Honest end-state check against frozen V8 specs. Complements [V8-ACCEPTANCE-AUDIT.md](V8-ACCEPTANCE-AUDIT.md) (test gates + checklists).

**Last verified:** 2026-09-29 — `STOXLA_ML_TEST_PYTHON=/tmp/stox-v8-ml-x86-venv.anZaFw/bin/python arch -x86_64 php -d memory_limit=512M vendor/bin/phpunit tests/Feature` (**1,345 passed / 1,347 total, 2 skips, 0 failures, 9,565 assertions**); Node 20.19.1 JS/Vitest/build/typecheck/docs checks green; the full configured Playwright run passed **28/28 executed tests** with 21 intentional viewport/configuration skips, including axe accessibility scans, FEAT-054 fundamentals, guided-tour 11/11, FEAT-062 insights, FEAT-064 Screener, and responsive-shell journeys; Python microstructure/intraday focused suites pass in the project-compatible environment.

| Epic | Verdict | Remaining work (authoritative gaps) |
|------|---------|-------------------------------------|
| FEAT-052 | **REVIEW** | Local producer, official browser/PHP SDK paths and fail-open tests are verified; Collector receipt, PHP extension deployment, queue/scheduler propagation and production probe remain external. |
| FEAT-054 | **REVIEW** | Local bootstrap, source precedence, derived metrics, history UI and Screener boundary are verified; focused Chromium now covers fundamentals summary/provenance/history and Advanced preference reload on desktop and 390px touch; live provider and deployed bootstrap evidence remain external. |
| FEAT-055 | **REVIEW** | Domain, security, concurrency and notification-isolation behavior are locally verified; real Turnstile, mail delivery and deployed multi-worker contention remain external. |
| FEAT-056 | **REVIEW** | Durable lifecycle, retries, cancellation, recovery, retention, explicit promotion/rollback boundaries and notifications are locally verified; deployed worker/scheduler/SSE and production notification/archive evidence remain external. |
| FEAT-057 | **REVIEW** | Versioned/PIT-safe registry, training-only missing-value/outlier preprocessing, same-architecture bounded 1m/3m/6m training, archive integrity, paired baseline evidence, partition coverage and artifact explainability are verified; production provider population, deployed active-model pairing and investor browser acceptance remain external. |
| FEAT-061 | **REVIEW** | Keyed i18n, missing-target behavior, persisted resume, in-progress refresh recovery, full configured-route traversal, desktop/mobile/tablet journeys, focus return and Tab containment are verified; production session interruption, screen-reader review and broader-device acceptance remain. |
| FEAT-062 | **REVIEW** | Deterministic catalogue, PIT comparisons, provider-neutral bounded AI validation and degradation behavior are locally verified; browser/mobile and real-provider acceptance remain external. |
| FEAT-063 | **REVIEW** | Collector lifecycle, quality states, recovery, finalization, backup gating, alerts and resilience tests are locally verified; VPS installation, live Kite full-mode collection and deployed backup remain external. |
| FEAT-064 | **REVIEW** | Runtime create/import/shared copy, immutable provenance/readiness semantics, legacy fixture reconciliation and Playwright journeys are verified; live membership-drift/runtime acceptance remains external. |
| FEAT-065 | **REVIEW** | Schema-versioned Parquet, resumable/idempotent backfill, retries, checkpoints, coverage, DuckDB/Polars and manual-backup architecture are locally verified; live Kite POC/full corpus and handoff evidence remain external. |

## Evidence anchors (implemented slices)

- **Tests:** full `app/tests/Feature` (1,347 total, 1,345 passed, 2 skips); full configured Playwright run (49 configured, 28 executed passed, 21 intentional skips); `shared/intraday/tests` (22/22) and `shared/microstructure/tests/` (28/28) in the isolated project-compatible environment.
- **Ledger:** [docs/V8-IMPLEMENTATION-LEDGER.md](V8-IMPLEMENTATION-LEDGER.md).
- **Behavior:** [implementation.md](../implementation.md) § V8 FEAT-* sections.

## Production deploy gate (not satisfied until gaps closed)

1. `php artisan test tests/Feature/V8/` green.
2. `npm run build` (Node 20.19.1 project-compatible runtime) green.
3. Per-epic rows above reviewed; no epic marked **COMPLETE** without spec § completion criteria met. All epics currently remain REVIEW or partial pending external evidence.
4. Migrations applied via `deploy/cpanel-migrate.php` when schema changed.

**Conclusion:** V8 is **not** production-complete per frozen wishlist; continue implementation on partial epics before claiming release closure.
