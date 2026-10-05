# FEAT-056 ML Lifecycle Functional Acceptance Plan

**Status:** OPEN — local lifecycle implementation is complete; CI-parity and deployed runtime evidence remain.

## Ownership and boundaries

- **FEAT-056:** lifecycle scheduling and queueing, run progress/recovery/retry/cancellation, candidate retention, explicit promotion/rollback, production monitoring, notifications, and operations.
- **FEAT-057:** training/validation evidence, feature selection, candidate eligibility, and drift baselines consumed by FEAT-056.
- Production model activation and rollback remain explicit Admin actions. This plan does not authorize training, promotion, rollback, or production configuration changes.

## Current evidence and blockers

- The acceptance audit records local implementation and focused tests as passing.
- The prior FEAT-056 attempt stopped at platform preflight because `pdo_sqlite` was unavailable in that shell. This is not an unresolved repository/platform defect: FEAT-061 later ran the shared backend verifier successfully using the existing PHP extensions through `PHP_INI_SCAN_DIR`. Re-run the backend verifier for the current FEAT-056 code using that same working PHP setup; record the result against the tested commit.
- Production lifecycle acceptance is gated on FEAT-057 qualification with the deployed build, registry/configuration, and complete 1m/3m/6m real-adapter evidence.
- Production must use `STOXLA_ML_MODEL_DIRECTORY=/var/www/stoxla/shared/ml/models`. Follow `docs/current/ml-lifecycle-operations.md`; first inspect with the documented artifact-repair dry run. Do not run repair or change production configuration as part of this plan.
- The lifecycle flag was recorded false in the 2026-10-01 production audit. Recheck current state read-only before any later controlled exercise.

## Acceptance sequence

### 1. Re-run the backend CI-parity gate

- Reuse the PHP extension setup proven by FEAT-061 (`PHP_INI_SCAN_DIR`) and the isolated MySQL verification setup.
- Run `./scripts/verify-ci.sh --backend` against the current FEAT-056 code.
- Record the exact commit, command, environment, test counts, and result; resolve any failures before production acceptance.

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

- Required backend CI-parity verification passes.
- FEAT-057 qualification is recorded for the deployed build/configuration before live lifecycle exercise.
- Queue/scheduler, concurrency, SSE reconnect, retry, cancellation, restart recovery, and notifications have controlled runtime evidence.
- Retention and explicit promotion/rollback checks preserve active-model and immutable-artifact invariants.
- No automatic promotion or rollback occurs.
- Evidence, operator, timestamps, build/configuration, and any deviations are appended to `docs/audit/V8-FEAT-056-ACCEPTANCE-AUDIT.md`.

FEAT-056 remains **REVIEW** until its acceptance audit records these gates as passed. Do not mark it COMPLETE based on local tests or this plan alone.
