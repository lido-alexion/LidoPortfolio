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

## Canonical model storage and legacy path repair (GitHub #19)

Set the persistent production environment to:

```dotenv
STOXLA_ML_MODEL_DIRECTORY=/var/www/stoxla/shared/ml/models
```

The application resolves the directory before writing artifacts. Without an override,
release layouts use the deployment root's `shared/ml/models`; local/test installs
use `storage/app/ml-models`. Release directories are rejected as storage destinations.
Promotion review, inference, retained rollback, retention and archive integrity share
the canonical resolver. Historical release-relative archive references resolve to
this directory without rewriting immutable evidence or its digest. FEAT-056 remains
REVIEW; this fix does not supply deployed acceptance evidence.

Production procedure (operator action after the fix passes CI and is released through
the normal verified-SHA GitHub Actions process):

1. Back up `stox_ml_model_versions` and artifact storage. Pause training admission,
   training workers, retention and manual promotion/rollback for the repair window.
   Preserve old release/shared directories until repair verification is complete.
2. Set the environment variable above in the persistent production environment.
   Ensure the application account can read/write that directory and sufficient disk
   space exists for copies. Run as the application account from the directory
   containing the deployed `artisan` file:

   ```bash
   php artisan config:cache
   php artisan portfolio:ml-artifacts-repair --dry-run
   ```

3. Review every row's report. A missing artifact, missing digest or SHA-256 mismatch
   exits nonzero and leaves that row unchanged. Restore missing artifacts from a
   trusted backup matching the persisted digest; investigate mismatches rather than
   replacing stored hashes. Dry-run makes no filesystem or database writes.
4. Once the dry-run succeeds, run:

   ```bash
   php artisan portfolio:ml-artifacts-repair
   php artisan portfolio:ml-artifacts-repair --dry-run
   ```

   The command checks every model row, including active and retained models. It
   verifies all available original, lexically normalized legacy and destination
   files against the persisted SHA-256. If needed it copies through a verified
   temporary file and publishes atomically without overwriting an existing file.
   It locks each row and updates only `artifact_path` after destination verification.
   Source files are preserved; rerunning is safe. Failures are isolated per row,
   reported with nonzero exit status; earlier successful rows remain repaired.
   Null paths (including pruned records) are skipped. No model is promoted.
5. Check Admin candidate review, active scoring, retained rollback readiness and
   archive integrity. Restart long-lived workers through normal operations so they
   load the refreshed config, then resume paused lifecycle work. Do not perform a
   real rollback merely to validate path readiness. Retain backups for recovery.

No migration is required. The repair never changes status, evaluation evidence,
artifact digests or promotion metadata. A digest mismatch cannot be repaired by
path canonicalization alone.
