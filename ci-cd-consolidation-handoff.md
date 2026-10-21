# CI/CD consolidation handoff

**Owner:** CI/CD consolidation implementation only
**Branch:** `chore/ci-cd-consolidation-20261020`
**Original baseline:** `cdfb7d3601251ca927cd5a0caba2fcdb7ce34a94`
**Latest reconciled master:** `c213ac559ef3e6f3b0c01a60ca951c954e13c2de`
**Worktree:** `CONTROL/ci-cd-consolidation`
**Scope:** `.github/workflows/ci.yml`, conversion of `.github/workflows/deploy-stoxla-production.yml` to a deployment-only reusable workflow, offline CI orchestration contract/helper tests, and this decision/handoff documentation.

## Implementation summary

The `CI` workflow owns the orchestration DAG. Its canonical backend/frontend/VPS health jobs and always-applicable AI runtime job are the only source of verification results; package directly depends on those jobs and validates event, master ref, candidate/checkout SHA equality, scope, and each applicable result. Package and the locally called deployment component use the exact event SHA. The artifact is bound to its run and SHA. Pull request jobs remain verification-only. The deployment workflow has no independent master trigger and performs no duplicate verification.

Production Vite settings and the existing deploy/environment/secrets/health/smoke/evidence/worker/rollback path are preserved. Job display names for canonical checks are unchanged. Scope classification preserves docs-only no-op behavior and avoids treating user journey prose as deployable. Dispatch forces all canonical test scopes and can package only from master. Reconciliation preserved the canonical frontend outer timeout of 45 minutes.

While the existing `stoxla-production-deploy` concurrency lock is held, the deployment job now reads the authoritative current `master` SHA through the authenticated read-only GitHub API. API/response failure or any mismatch with `github.sha` fails closed immediately before the remote activation script. The same-run artifact identity and post-deploy exact-SHA health/smoke checks remain required.

## Review and verification

- `python3 scripts/test_ci_workflow_graph.py`: 7 tests passed.
- `python3 scripts/test_ci_release_contract.py`: 13 tests passed.
- `node --test app/tests/js/deployment-runtime-contract.test.mjs`: 8 existing checks passed unchanged.
- Workflow parsed with PyYAML `BaseLoader`; dependency references, stable check names, timeout45, freshness-check placement/failure semantics, same-run artifact identity and post-deploy exact-SHA checks are covered by offline tests.
- `git diff --check` and merge-index diff checks passed before publication.
- No canonical backend/frontend verifier, full browser suite, database, migration, production command, manual dispatch, or credential/secret action was run. Hosted GitHub Actions remains authoritative.

## Publication boundary and owner actions

Publish only a **draft PR** first. PR checks must be inspected as hosted evidence; no production deployment is expected from the PR event. After protected root review and merge, the first master run is the bootstrap activation path: it runs applicable canonical checks, then packages and deploys the same exact current master SHA. Do not alter required contexts/settings/secrets/environments, dispatch an older workflow, or deploy manually.

The `CI` workflow name and canonical job display names remain stable; no new required PR status is added. The `Deploy to VPS` job name remains. If branch protection contains a specifically workflow-qualified standalone deployment context, the repository owner must inspect it before merge; no settings are edited here.

## Savings estimate

Workflow-definition count: one backend verifier and one frontend verifier per applicable deployable master run, reduced from two of each across the old CI and deploy workflows. This is measured from source definitions. Runner-minute or billing savings are not yet measured. The separate production build/package and release smoke remain intentionally.

Hosted run IDs, PR number, final hosted results, root merge decision, activation receipt and post-deploy evidence remain pending; none are inferred in advance.
