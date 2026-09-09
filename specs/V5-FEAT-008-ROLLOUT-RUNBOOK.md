# V5 FEAT-008 — Legacy Artifact Backfill Rollout Runbook

**Status:** Required production/representative-data procedure

**Updated:** 2026-09-09
**Authority:** [`V5-FEAT-008-Trading-Artifact-Framework.md`](V5-FEAT-008-Trading-Artifact-Framework.md)

## Purpose

Map existing Portfolio Screener and Strategy runtime rows to immutable V5 artifacts and exact Portfolio bindings. The backfill is deterministic and idempotent, processes Screeners before dependent Strategies, and leaves an invalid legacy row unmapped without retaining a partial artifact or binding.

The command changes data unless `--dry-run` is supplied. Always preview first against the same database and application build that will perform the rollout.

## Preconditions

1. Deploy the application build containing the V5 artifact migrations and `portfolio:backfill-reusable-artifacts` command.
2. Put normal application writes and scheduled Trading jobs into the deployment maintenance window.
3. Take and verify a restorable database backup. Record its identifier and timestamp in the deployment evidence.
4. Run pending migrations and confirm they succeed before the artifact backfill.
5. Confirm the application process is using PHP 8.4 or later and the intended database connection.

## Inventory and validation (no committed writes)

All Portfolios:

```text
php artisan portfolio:backfill-reusable-artifacts --dry-run
```

One Portfolio while investigating or remediating:

```text
php artisan portfolio:backfill-reusable-artifacts --profile=PROFILE_ID --dry-run
```

The dry run executes the exact publication, dependency-resolution, projection and binding path inside an outer transaction, then rolls back every write. Record the summary and every reported `screener #ID` or `strategy #ID` failure.

Do not run the committing command while any failure remains unexplained. Typical remediation is forward-only:

- repair an invalid Screener condition tree or unsupported Indicator reference;
- restore the missing Screener referenced by a Strategy before retrying;
- resolve a duplicate or stale legacy identity deliberately rather than deleting history;
- archive/disable an internally contradictory legacy row only after the Product Owner accepts that data choice.

Repeat the dry run until it reports zero failures. A non-zero failure count returns a failing command exit code.

## Commit the backfill

After a clean preview and verified backup:

```text
php artisan portfolio:backfill-reusable-artifacts
```

Immediately repeat the command. The second run must report zero created, all applicable rows skipped, and zero failures. This proves idempotence against the rollout database.

## Post-rollout verification

1. Every intended legacy Screener and Strategy has a non-null `reusable_artifact_id`.
2. Every mapped row has one matching Portfolio binding whose active version is published.
3. Enabled legacy rows have enabled bindings; disabled/draft rows do not become enabled implicitly.
4. Strategy dependencies resolve to exact published Screener versions.
5. Open representative mapped rows in the compatibility Registry and confirm they are read-only and link to the Artifact Library.
6. Run one mapped Screener and one Recommendation generation path; verify artifact-version and binding-revision evidence is persisted.
7. Start and continue representative Screener and Strategy backtests; verify their immutable definition/config snapshots and pinned evidence remain unchanged.
8. Resume schedulers only after these checks pass.

## Failure and rollback

Before the committing command, rollback is simply to stop: `--dry-run` retains no changes.

If the committing command reports a failure, successful rows from other per-row transactions may already be mapped. Do not manually delete published artifact history or clear foreign keys. Fix the reported legacy rows and rerun; the command skips successful mappings and continues idempotently.

Use full database restore only when the rollout must be reversed as a whole (for example, an unexpected systemic projection defect). Keep the application in maintenance mode, restore the verified pre-rollout backup, deploy the prior compatible build, run its documented migration checks, then verify row counts and core Strategy/Screener workflows before reopening traffic. This is a destructive operational decision and requires explicit authorization.

## Evidence to retain

- deployed commit and migration output;
- database backup identifier and restore verification;
- complete dry-run and committing-command output;
- second-run idempotence output;
- invalid-row inventory and each disposition, if any;
- representative runtime/backtest evidence checks;
- operator, environment and timestamps.
