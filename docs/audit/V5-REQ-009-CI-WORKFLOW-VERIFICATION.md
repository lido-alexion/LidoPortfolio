# V5-REQ-009 - CI Workflow Verification

## 1. Requirement

The accepted V5 requirement is the complete CI workflow for backend PHPUnit,
MySQL migration/seed validation, frontend JavaScript tests, TypeScript
checking, and a production frontend build. The archived V5 register records
the requirement as applying to pushes to `master`, pull requests, and manual
dispatch.

## 2. Current Workflow

The repository uses two coordinated workflows:

- `.github/workflows/ci.yml` provides pull-request, `master` push, and manual
  verification.
- `.github/workflows/deploy-stoxla-production.yml` repeats the required gates
  for a production package and, after success, deploys that immutable artifact
  to the VPS.

Both workflows use changed-area detection. A backend/frontend change enables
the corresponding gate; manual dispatch enables all gates. The production
workflow's package and deploy jobs require the applicable verification jobs to
finish successfully.

## 3. Backend Gate

The backend gate runs on PHP 8.4 with Composer dependencies from the lockfile,
starts disposable MySQL 8.4, runs `php artisan migrate:fresh --seed --force`,
executes the backend PHPUnit suite, and verifies the OpenAPI contract in the
deployment workflow. The general CI workflow runs the same MySQL migration and
seed gate and `php artisan test`.

The migration gate has real failure evidence: the historical Replay migration
was rejected by MySQL because Laravel generated a foreign-key identifier longer
than MySQL's 64-character limit. Packaging/activation was blocked until the
constraint names were explicitly shortened. A later production deployment also
blocked on shared-storage group permissions until corrected. These failures
demonstrate that the workflow is an enforcing gate, not a reporting-only job.

## 4. Frontend Gate

The frontend gate performs clean `npm ci` on Node 22, runs `npm run test:js`,
runs `npm run typecheck`, and builds production Vite assets. The deployment
workflow uses `VITE_APP_BASE=/build/` for the VPS root-domain artifact. A
frontend/build failure prevents packaging and therefore deployment.

## 5. Release Artifact and Activation

The package job installs production Composer dependencies with
`--no-dev --optimize-autoloader`, installs npm dependencies, writes
`bootstrap/build-info.json` with the exact GitHub SHA and build ID, builds the
frontend, and creates `stoxla-release.tgz`. `.env`, `node_modules`, storage,
public storage, and Vite hot-reload state are excluded.

The deploy job uploads the artifact rather than performing an uncontrolled
server checkout. It passes the GitHub SHA as `STOXLA_EXPECTED_COMMIT`; the
remote deployment rejects build metadata mismatches, runs migrations before
activation, atomically switches `current`, refreshes PHP-FPM and the queue
worker, and runs the hard public build-identity/runtime health gate. Rollback
uses the retained release directory and the same runtime identity gate.

## 6. Real Execution Evidence

Successful production deployments include application commits:

- `6838f8277a79a3cd36996f6f50b33dd559915caa`
- `0872014f23f0e592b0584ed036cb80a950064d02`

The latter production release reports the same SHA from both filesystem
metadata and public `/api/build-info`; its migration ledger is current through
the deployed namespace remediation. The associated runtime health check passed
for the public PHP identity, frontend module, queue coverage, scheduler
heartbeat, and writable paths.

## 7. Failure Behavior

Observed migration and permission failures stopped release activation until the
underlying defects were fixed. The workflow's job dependencies and remote
script's `set -euo pipefail`/explicit health failures prevent a failed gate
from being reported as a successful deployment.

## 8. Local Contract Checks

`app/tests/js/deployment-runtime-contract.test.mjs` verifies the release and
rollback FPM/queue refreshes, public identity gate, scheduler gate, DebugAgent
fail-closed state, writable-path checks, root-managed queue boundary, and
required queue coverage. The test passes 3 tests. Workflow YAML parses
successfully and all three deployment shell scripts pass `bash -n`.

## 9. Final Assessment

**V5-REQ-009: `IMPLEMENTED`**

The workflow materially satisfies the accepted CI requirement and has been
proven by successful production executions and enforced failure cases. Routine
third-party provider behavior and backup/restore assurance remain separate
operational concerns under AUD-009 and do not weaken this CI classification.
