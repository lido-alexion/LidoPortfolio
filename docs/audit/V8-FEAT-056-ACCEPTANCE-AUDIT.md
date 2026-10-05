# FEAT-056 ML Lifecycle Automation / Deployment / Operations — acceptance audit

Status: **REVIEW — local lifecycle implementation and tests complete; deployed worker/runtime evidence pending**

Evidence is mapped to `docs/archive/specs/V8-ML-Lifecycle-Automation-Deployment-Operations-Specification.md`.

| Requirement | Status | Evidence / remaining work |
|---|---|---|
| Per-horizon bounded schedules | PASS locally | `MlLifecycleAutomationService`, persisted `stox_ml_lifecycle_schedules`, bounded Admin schedule API/UI, `config/ml_lifecycle.php`, scheduled Artisan command and schedule tests |
| Manual and drift-triggered canonical queue path | PASS locally | `MlScoringService` integration, `MlRetrainJob`, queue and drift tests are committed; manual, scheduled and drift triggers share the canonical durable run path, and only drift runs may carry a drift-check reference |
| Same-horizon concurrency | PASS locally | A seeded `stox_ml_training_horizon_locks` row is locked transactionally before the active-run check and insert, so manual, scheduled and drift requests serialize across workers; queue tests cover duplicate rejection and trigger validation |
| Durable run/progress state and SSE | PASS locally | `MlTrainingRunAdminService`, progress persistence, SSE controller and lifecycle tests; Admin status now exposes next scheduled run plus durable retry/cancellation/failure state; deployed worker/SSE runtime remains pending |
| Restart recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running/cancelling runs with durable recovery evidence and test coverage |
| Bounded transient retry | PASS locally | `MlTrainingRunRetryService` persists bounded attempt/backoff and dispatches delayed retry |
| Terminal run-state vocabulary | PASS locally | Eligible runs persist `completed_eligible`, threshold failures persist `completed_rejected`, cancellation persists `cancelled`, and operational exceptions remain `failed`; lifecycle tests cover the eligible/rejected distinction |
| Cooperative cancellation | PASS locally | queued/running cancellation service and checkpoint assertions are covered; live worker cancellation remains pending |
| Explicit promotion / atomic rollback | PASS locally | existing promotion/rollback services and tests; Admin dashboard now lists retained, artifact-valid versions with explicit rollback controls; full route/UI/runtime acceptance remains |
| No automatic promotion/rollback | PASS by tests | lifecycle automation only queues training and evaluates drift; promotion remains explicit Admin action |
| Retention and stale/superseded candidates | PASS locally | retention service and promotion review tests; production archive/runtime proof remains |
| Notifications and actionable failures | PASS locally | lifecycle notification tests and existing StoX notification boundary; deployed channel validation remains |
| Admin authorization/auditability | PASS locally | Admin route group and focused authorization/lifecycle tests, including Investor denial for persisted schedule mutation; the lifecycle table exposes normalized latest outcome and active operational state per horizon |
| Durable stale-run recovery | PASS locally | `MlTrainingRunRecoveryService` requeues stale running runs after worker restart, finalizes stale cancellation requests without requeueing, and is invoked at lifecycle ticks; `MlLifecycleAutomationTest` covers both paths |
| Production queue/scheduler deployment | EXTERNAL VALIDATION PENDING | Operator runbook is now documented in `docs/current/ml-lifecycle-operations.md`; VPS worker, scheduler, queue restart and notification-provider runtime have not been claimed |

The epic is **REVIEW**. Local lifecycle implementation, recovery, retention, promotion/rollback boundaries, authorization, notifications, operator runbook and the broad Feature suite are green. Remaining evidence is limited to deployed worker/scheduler/queue restart, live cancellation/progress/SSE, notification-channel and production archive/runtime acceptance. No deployed worker/runtime success is claimed.


## Closure continuation — 2026-10-01 (production build `ef66133c`)

Operator: Codex via connected `stoxla-prod`; UTC times below. **This entry does not mark the epic COMPLETE.** Prior local checks remain separate from production acceptance.

| Acceptance check | State | Evidence / next exact check |
|---|---|---|
| Runtime gate | BLOCKED (qualification) | Build `ef66133c`, VPS, 2026-10-01 18:25 UTC: lifecycle flag false; campaign `996fa344-2533-4bf0-a555-9b052de2c8cb` currently blocked; dedicated acceptance service inactive although a one-shot worker handles NSE preview. Qualification requires current build/registry/config and complete 1m/3m/6m real-adapter evidence. |
| Scheduler/queue tick, locks, SSE reconnect, cancellation/restart/retry and notifications | NOT YET RUN | Controlled deployed lifecycle exercise only after FEAT-057 qualification; do not interrupt NSE worker. |
| Retention/archive and explicit promotion/rollback single-active invariant | NOT YET RUN | Use safe candidate/control path; separate Admin decisions remain required. |

### GitHub #19 — persistent artifact paths

Artifact writes and lifecycle readers now share canonical persistent path resolution.
Production must set `STOXLA_ML_MODEL_DIRECTORY=/var/www/stoxla/shared/ml/models`.
`php artisan portfolio:ml-artifacts-repair --dry-run` inventories and verifies existing
rows; omit `--dry-run` only after reviewing the report. Repair preserves model state,
checks persisted SHA-256 before path mutation, preserves source files and never
promotes models. See [the operations procedure](../current/ml-lifecycle-operations.md#canonical-model-storage-and-legacy-path-repair-github-19).
Release-pruning, repair conflict/no-mutation, idempotence, active scoring, retained
rollback and immutable archive reference regressions are covered locally. No
production repair or deployment was performed; FEAT-056 remains REVIEW.

Local verification for this fix: 18 focused lifecycle/path tests passed (91 assertions),
then the final expanded artifact-path suite passed 11 tests (39 assertions) against
an isolated MariaDB instance. Changed PHP syntax checks, Pint on new PHP files and
`git diff --check` passed. The shared `./scripts/verify-ci.sh --backend` gate was
attempted but stopped at its PHP platform preflight: `pdo_sqlite` is unavailable.
These focused results do not replace that CI-parity gate. No migrations changed.

## Closure continuation — 2026-10-05 (CI prerequisite reconciliation)

The 2026-10-01 note above accurately records that the FEAT-056 verifier attempt in that shell stopped because `pdo_sqlite` was unavailable. This was an environment-specific limitation, not an unresolved repository dependency: FEAT-061 later ran the shared backend verifier successfully using existing PHP modules loaded through `PHP_INI_SCAN_DIR` (see `docs/audit/V8-FEAT-061-ACCEPTANCE-AUDIT.md`). FEAT-056 still needs its own full backend verifier run against the current code; the FEAT-061 result is not substituted for that feature-specific run. Current execution scratch has no PHP runtime, so that run has not been claimed here.

### 2026-10-05 CI parity correction

The historical local preflight failure above is superseded for the current repository code. GitHub Actions passed `./scripts/verify-ci.sh --backend` on commit `d1279fb74b56a2cf488ba82f7c305cb58b424f24` using PHP 8.4, MySQL 8.4 and CI-provisioned `pdo_sqlite`: **2,242 PHPUnit tests, 14,962 assertions, 2 skipped**; migration portability passed for 172 migrations; Python checks ran 22 tests (8 skipped); OpenAPI remained current at 219 operations. [Backend verification job](https://github.com/lido-alexion/LidoPortfolio/actions/runs/37261402892/job/111609241889). This satisfies the CI-parity gate for the code at that commit; repeat only if FEAT-056 backend code changes.

## Closure continuation — 2026-10-05 production preflight (read-only)

Read-only checks on `stoxla-prod` found:

- Deployed release: `/var/www/stoxla/releases/20261005054340-1782da64f629`.
- `php artisan schedule:list` includes `portfolio:ml-lifecycle-tick`; this confirms registration only.
- `php artisan config:show ml_lifecycle`: lifecycle `false`; 1m/3m/6m schedule flags `false`; retention `false`; drift trigger `false`; notifications `true`.
- `stoxla-queue.service` is active, but runs the normal `notifications,default` queues. A dedicated bounded acceptance worker was not evidenced.
- Production CLI PHP 8.4.26 has `pdo_mysql` but not `pdo_sqlite`; no tests were run on production.

No lifecycle tick, queue action, configuration change, model operation or data mutation was performed. The FEAT-057 qualification gate is still unmet; do not enable schedules or exercise the live lifecycle until FEAT-057 qualifies and a dedicated acceptance setup is available.

## Dependency correction — FEAT-057 mapping passed; governed apply remains open

This addendum supersedes the earlier description of FEAT-057 as blocked on the 90% mapping floor. The current FEAT-057 audit records an offline 360-date replay and production run-4 preview at a **93.4096% minimum across all 360 dates**. Mapping is above the frozen 90% floor.

FEAT-057 remains unqualified for FEAT-056 because run 4 apply materialized only **1/360** dates before reporting completed. The apply lifecycle correction has isolated regression and backend-CI evidence, but a verified release and a fresh governed campaign/preview/apply are still required, followed by post-apply preflight and complete 1m/3m/6m training evidence. Preserve run 4 and its first boundary; do not force its state or reuse its preview/digest. Source: [FEAT-057 acceptance audit](V8-FEAT-057-ACCEPTANCE-AUDIT.md), “Offline checkpoints and verification” and “Governed multi-date apply lifecycle correction.”

FEAT-056 remains **REVIEW**. Its backend CI gate passes; deployed lifecycle acceptance remains deferred until FEAT-057 supplies qualified data/model evidence and a dedicated controlled acceptance worker is available. Production lifecycle settings remain disabled and were not changed.


## Closure continuation — 2026-10-05 production recovery and queued readiness

The deployed production release is build 436, commit `15037d9360189694f98cf5147a6656262f89961f` (PR #73). Read-only config confirms global lifecycle, the 1m/3m/6m schedules, drift triggers and retention are disabled; notifications are enabled. The active queue worker consumes only `notifications,default`. The dedicated `ml-acceptance` unit is installed and enabled, but inactive.

Production run 5 (`admin_sealed_nse`) completed its governed apply: **360 requested / 360 processed**, cursor 360, no failed dates, 360 persisted preview results, parser `nse-pit-universe-parser-5`, and **93.4096% minimum mapping**. Its 2022-11-04 result marked the existing boundary as present; its snapshot and membership digests match run 4's valid first boundary. Run 4 remains unchanged and partial at cursor 1 / 360.

A fresh campaign `ee93e3a6-5740-4dea-bfef-c109abc90a69`, cutoff **2026-10-01**, was created against production build 436. Its status is `preflight`; exactly one `ml-acceptance` queue job is pending. No stale queue job was present before it was queued. The installed dedicated worker is currently inactive, so the preflight has not executed. An attempt to start the systemd unit through the connected remote command tool was rejected by that tool's command policy; no alternate service-control path was used. Start the unit through the approved VPS operator path, then continue the governed preflight.

An earlier build-393 preflight reported `canonical_dataset_or_coverage_unavailable` for 1m and unavailable core fundamentals (`roe`, `debt_equity`, `operating_margin`, `net_margin`) for 3m/6m. Treat those as leads only; the new build-436 preflight must establish current blockers. Do not send NSE/BSE exchange requests unless the PO confirms receipt of the exchange approval letter recorded in [the exchange access decision](../decisions/2026-10-05-nifty500-exchange-fallback-and-manual-fetch.md). No training, promotion, rollback, lifecycle tick, schedule, drift or retention operation was performed. FEAT-056 remains **REVIEW** pending FEAT-057 qualification and the controlled FEAT-056 runtime acceptance below.