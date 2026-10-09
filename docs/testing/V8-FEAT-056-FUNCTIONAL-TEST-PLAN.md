# FEAT-056 ML Lifecycle Functional Acceptance Plan

**Implementation state:** IMPLEMENTED (local lifecycle tests and backend CI passed).
**Functional acceptance state:** OPEN — deployed runtime checks remain deferred until FEAT-057 qualification.

## Ownership and boundaries

- **FEAT-056:** lifecycle scheduling and queueing, run progress/recovery/retry/cancellation, candidate retention, explicit promotion/rollback, production monitoring, notifications, and operations.
- **FEAT-057:** training/validation evidence, feature selection, candidate eligibility, and drift baselines consumed by FEAT-056.
- Production model activation and rollback remain explicit Admin actions. This plan does not authorize training, promotion, rollback, or production configuration changes.

## Current evidence and blockers

- The acceptance audit records local implementation and focused tests as passing.
- **PASS — current code verified.** GitHub Actions ran `./scripts/verify-ci.sh --backend` successfully on 2026-10-05 at commit `d1279fb74b56a2cf488ba82f7c305cb58b424f24` (PHP 8.4/MySQL 8.4; the CI workflow installs `pdo_sqlite`). Results: 2,242 PHPUnit tests, 14,962 assertions, 2 skipped; migration portability passed for 172 migrations; Python checks 22 passed with 8 skipped; OpenAPI check passed at 219 operations. See [backend verification run](https://github.com/lido-alexion/LidoPortfolio/actions/runs/37261402892/job/111609241889). The earlier `pdo_sqlite` failure was environment-specific and is superseded by this CI run.
- FEAT-057 mapping is now above the frozen gate: its offline replay and production run-4 preview passed all 360 dates, with a 93.4096% minimum. The current blocker is apply lifecycle completion: run 4 materialized only 1/360 dates before reporting completed. FEAT-057's latest correction has regression and backend-CI evidence, but requires a verified release and a fresh governed campaign/preview/apply, followed by post-apply preflight and 1m/3m/6m training evidence. Run 4 must remain immutable; do not reuse its preview or force its state.
- Production must use `STOXLA_ML_MODEL_DIRECTORY=/var/www/stoxla/shared/ml/models`. Follow `docs/current/ml-lifecycle-operations.md`; first inspect with the documented artifact-repair dry run. Do not run repair or change production configuration as part of this plan.
- **Latest read-only production preflight (2026-10-05):** deployed release `20261005054340-1782da64f629`; `ml_lifecycle.enabled=false`, all 1m/3m/6m schedules disabled, retention disabled, drift trigger disabled. Laravel scheduler is registered and the normal `stoxla-queue.service` is active on `notifications,default`; no dedicated acceptance worker was evidenced. The VPS CLI lacks `pdo_sqlite`, so it was not used for tests. No production setting, queue, or model state was changed.

## Acceptance sequence

### 1. Backend CI-parity gate — PASS

- Re-run this gate only if FEAT-056 backend code changes after commit `d1279fb74b56a2cf488ba82f7c305cb58b424f24`.

### 2. Confirm production prerequisites (read-only)

After FEAT-057 qualification:

- Confirm the deployed build, model registry, lifecycle configuration, shared artifact directory, queue connection, scheduler, and dedicated acceptance worker.
- Confirm an approved safe candidate/control path and a non-production or explicitly bounded acceptance horizon.
- Verify no active NSE preview or other unrelated worker would be interrupted.
- Confirm notification destination and test recipient/channel.
- Stop if any prerequisite is missing; do not enable lifecycle or mutate model state during this read-only check.

### 3. Exercise lifecycle runtime in a controlled window

With an approved bounded acceptance setup and operator present:

- Verify a lifecycle tick queues one eligible horizon run and persists schedule/run identity.
- Verify same-horizon duplicate requests are serialized across independent workers; verify distinct horizons do not block one another.
- Observe durable run progress and SSE updates, including client reconnect and final state.
- Exercise bounded retry on a controlled transient failure and verify attempt/backoff limits.
- Exercise cancellation while queued and during a safe training checkpoint; verify no promotion occurs.
- Restart the dedicated acceptance worker during a controlled run; verify stale-run recovery, durable evidence, and correct terminal state.
- Verify actionable success/failure notifications arrive once through the configured channel.
- Confirm status and audit history show schedule, trigger, attempt, progress, failure/recovery, and final outcome.

### 4. Verify candidate lifecycle and storage

- In a safe candidate/control path, verify retention pruning preserves the active model and configured retained versions.
- Verify stale/superseded candidates cannot be promoted.
- Verify artifact-path repair dry-run inventory, conflict reporting, hash checks, and idempotence using the operations runbook; do not mutate production artifacts during acceptance.
- Verify explicit promotion and rollback separately with an approved test candidate, checking the single-active-model invariant and immutable archive references.
- Confirm automation itself never promotes or rolls back.

## Exit criteria

- Backend CI-parity verification passes for the current code (2026-10-05 run recorded above).
- FEAT-057 has passed a fresh governed apply and the post-apply data/model preflight, with qualified 1m/3m/6m evidence for the deployed build/configuration before live lifecycle exercise.
- Queue/scheduler, concurrency, SSE reconnect, retry, cancellation, restart recovery, and notifications have controlled runtime evidence.
- Retention and explicit promotion/rollback checks preserve active-model and immutable-artifact invariants.
- No automatic promotion or rollback occurs.
- Evidence, operator, timestamps, build/configuration, and any deviations are appended to `docs/audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md`.

FEAT-056 is **IMPLEMENTED** for code and test completion. The production functional acceptance gates above remain open; do not enable lifecycle settings or claim V8 operational completion until the safe runtime sequence passes after FEAT-057 qualification.
