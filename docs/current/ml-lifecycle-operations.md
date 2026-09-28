# ML lifecycle operations (FEAT-056)

This runbook describes the StoX V8 Admin workflow for scheduled training,
candidate review, promotion, rollback and recovery. Training and evaluation
are automated; production activation remains an explicit Admin decision.

## Preconditions

1. Apply migrations with the normal release procedure.
2. Confirm `STOXLA_ML_LIFECYCLE_ENABLED=true` only after the queue worker and
   Laravel scheduler are running.
3. Confirm the matching ML Python runtime is installed and reachable through
   `STOXLA_ML_TEST_PYTHON`/the production adapter configuration.
4. Verify queue, scheduler, notification and artifact-storage health before
   enabling a horizon schedule.

The scheduler runs `portfolio:ml-lifecycle-tick` every minute. It evaluates
the persisted per-horizon schedule and uses the same durable queue path as a
manual or drift-triggered run. The Admin schedule controls accept only the
bounded cadence options returned by `GET /api/v1/admin/ml`; do not edit raw
cron expressions to change model cadence.

## Normal Admin workflow

Open the Admin ML Scoring page and select a horizon.

- Enable/disable its schedule and select a supported cadence.
- Use **Retrain (queued)** for an explicit manual run.
- Follow the durable run state and SSE progress stream; reconnecting the page
  does not cancel the queue job.
- Inspect **Evidence** after `completed_eligible` or `completed_rejected`.
- Review candidate metrics, calibration, active-model comparison and the
  deterministic StoX baseline before promotion.
- Use **Promote latest** only after the explicit review decision.

There can be only one queued/running/cancelling run per horizon. Different
horizons may run concurrently. A quality rejection is recorded as
`completed_rejected` and is not retried as an operational failure.

## Failure and cancellation recovery

For a queued or running run, use **Cancel**. Queued work is cancelled
immediately; running work stops at a durable checkpoint. A transient worker
or provider failure is retried only within the configured bounded retry
budget. After exhaustion the run becomes `failed`, retains the active model,
and requires operator investigation.

The lifecycle table exposes the next scheduled run, latest terminal result,
active progress, retry attempt, cancellation request and actionable failure.
Use the run detail endpoint after an SSE disconnect to recover authoritative
state.

Do not delete failed evidence or manually change lifecycle rows to force a
retry. Requeue through the Admin/API path after resolving the underlying
failure.

## Promotion and rollback safety

Promotion is explicit and atomic: the prior active version becomes retained
and the selected eligible candidate becomes the sole active version for its
horizon. Training never promotes automatically.

Rollback is also explicit. Select an artifact-valid retained version from the
horizon card and confirm **Rollback**. The service verifies artifact integrity
and keeps exactly one active model. Drift alerts may recommend retraining or
rollback but never execute either action automatically.

Retention pruning must never remove the active artifact. Retained versions
that fail integrity checks are not offered as rollback targets.

## Deployed-runtime checklist

On the target host, verify without changing production state first:

```text
php artisan migrate:status
php artisan schedule:list
php artisan portfolio:ml-lifecycle-tick
systemctl status stoxla-queue.service
journalctl -u stoxla-queue.service --since "15 minutes ago"
```

Then perform a controlled Admin queue/cancel test for one non-production
horizon and verify the durable run, SSE stream, worker restart recovery,
notification and artifact paths. Record live-provider credentials, host
paths, timestamps and candidate IDs only in the deployment evidence system;
never commit them to this repository.

## Safety boundary

Never auto-promote, auto-rollback, disable the active model because a training
run failed, or treat an external notification as authoritative lifecycle
state. If the worker is unavailable, preserve the current active model and
repair the queue/runtime before retrying.
