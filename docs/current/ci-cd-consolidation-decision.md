# CI and production deployment consolidation

Date: 2026-10-20
Baseline: `cdfb7d3601251ca927cd5a0caba2fcdb7ce34a94` (`origin/master` fetched before work)

## Decision

Keep pull request and master verification in the existing `CI` workflow. The package job depends on that run's canonical backend, frontend, VPS health, and AI runtime jobs. After package succeeds, `CI` calls the existing deployment workflow as a local `workflow_call` component in the same run. A small gate checks trusted event/ref, full candidate SHA, checked-out SHA, scope applicability, and exact success/skip outcomes before the production build. The release archive is uploaded and downloaded within the same workflow run under `stoxla-release-${{ github.sha }}`.

The deployment component now has no independent push or dispatch trigger and performs no backend/frontend verification. This removes the second backend and frontend test execution while preserving the existing deployment runtime contract file and tests. It does not assume a PR result qualifies a later merge commit: canonical checks run on the master push commit, and that same run gates packaging/deployment. There is no cross-run artifact lookup or prior-success reuse.

A local reusable workflow keeps the existing deployment runtime contract in one place while the caller DAG enforces the dependency. This avoids a `workflow_run` handoff that would need to correlate an independent workflow's conclusion, SHA, provenance, and artifact.

The production package still installs production Composer/npm dependencies and runs the production Vite build with `VITE_APP_BASE=/build/` and production telemetry settings. This is retained because production runtime settings differ from the CI frontend build. Existing release archive checks, production environment approval/secrets, serialized deployment concurrency, worker interlock, rollback scripts, runtime commit-SHA health check, public asset checks, authenticated read-only smoke, and evidence upload remain in place.

While the `stoxla-production-deploy` concurrency lock is held, the deploy step makes an authenticated read-only GitHub API request for the current `master` branch SHA immediately before invoking the remote activation script. It fails closed on API/response errors or any exact-SHA mismatch with `github.sha`; queued or rerun historical candidates therefore cannot activate. The existing same-run artifact and post-deploy exact-SHA health/smoke checks remain required. The frontend canonical job retains the current-master 45-minute outer timeout without changing its verifier or test policy.

## Trigger and scope behavior

- Pull requests retain the existing canonical CI jobs and stable job display names. They never run package or deploy.
- Master pushes classify the pushed commit range (`event.before` to `HEAD`), which includes the net file changes of a merge commit. Each applicable canonical job runs on the exact `github.sha` checkout.
- Package runs only for deployable changes after all applicable canonical jobs succeed. A skipped applicable job, failure, cancellation, missing result, untrusted event, non-master ref, or checkout SHA mismatch closes the gate.
- Documentation-only changes retain the prior no-package/no-deploy behavior. User-journey documentation still triggers frontend CI but does not create a release.
- Manual dispatch remains available on the consolidated workflow. It forces backend, frontend, VPS health, and AI runtime verification; packaging can proceed only for a dispatch on `refs/heads/master`.
- A workflow definition change itself remains deployable, so activation of this graph follows normal master CI and its own same-run verification.

## Required checks and bootstrap

The workflow name remains `CI`, and canonical job IDs/display names remain `PHPUnit (PHP 8.4)`, `Frontend tests and production build (Node 22)`, `VPS health monitor tests (Python 3.12)`, and `Python AI and FastMCP contracts`. No branch-protection settings or secrets are changed. The called deployment workflow retains its `Deploy StoXla Production` name and `Deploy to VPS` job name. Its former push/dispatch triggers are replaced by a gated local call from `CI`; PR creation runs only verification. On the first merge of this change, the resulting master push starts the new DAG and deployment follows only after that run's applicable checks succeed. If the new workflow fails to start or a previously configured required context does not appear, stop and have the repository owner inspect the workflow/check configuration; do not manually deploy, dispatch an older workflow, or bypass checks.

The new graph introduces no new mandatory PR check name, so existing canonical required contexts can remain as configured. A repository owner should confirm any required context tied specifically to the old workflow display name before merge; this implementation does not change repository settings.

## Cost estimate

- **Measured from the workflow definitions:** each deployable master run now invokes `verify-ci.sh --backend` once and `verify-ci.sh --frontend` once, instead of once in `CI` and again in the deployment workflow. The focused VPS health check remains scoped and is included in the gate when applicable.
- **Assumed savings:** one complete duplicate PHPUnit/MySQL validation and one duplicate frontend test/typecheck validation per deployable master commit. Actual runner minutes and billing savings are not measured here.
- **Retained work:** production dependency installation/build, archive verification, deployment, post-deploy health/public/authenticated smoke, and evidence upload. These are packaging/release gates, not duplicate canonical test suites.

## Verification and limitations

`scripts/test_ci_release_contract.py` covers documentation, backend, frontend, journey-doc, and VPS scope plus successful, failed, cancelled, missing, skipped-applicable, wrong-SHA, PR, non-master, and docs-only gate outcomes. `scripts/test_ci_workflow_graph.py` parses the workflow and checks dependency wiring, stable canonical job names, the 45-minute frontend timeout, same-run artifact naming, current-master equality-check placement and failure behavior, exact-SHA post-deploy checks, and absence of duplicate verification calls. The existing `app/tests/js/deployment-runtime-contract.test.mjs` remains unchanged. These checks are offline contract evidence; hosted GitHub Actions remains authoritative for workflow execution and the first post-merge activation.

No application/test inventory, assertions, skip policy, CI platform, secrets, environments, workflow settings, production host, or deployment scripts are changed. No hosted run is claimed until a draft PR is published and GitHub reports its results.
