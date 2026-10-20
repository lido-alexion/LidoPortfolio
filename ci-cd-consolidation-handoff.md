# CI/CD consolidation handoff

**Owner:** CI/CD consolidation implementation only
**Branch:** `chore/ci-cd-consolidation-20261020`
**Baseline:** `origin/master` at `cdfb7d3601251ca927cd5a0caba2fcdb7ce34a94`, fetched before work
**Worktree:** `CONTROL/ci-cd-consolidation`
**Scope:** `.github/workflows/ci.yml`, conversion of `.github/workflows/deploy-stoxla-production.yml` to a deployment-only reusable workflow, offline CI orchestration contract/helper tests, and this decision/handoff documentation.

## Implementation summary

The `CI` workflow now owns the orchestration DAG. Its canonical backend/frontend/VPS health jobs and always-applicable AI runtime job are the only source of verification results; package directly depends on those jobs and validates event, master ref, candidate/checkout SHA equality, scope, and each applicable result. Package and the locally called deployment component use the exact event SHA. The artifact is bound to its run and SHA. Pull request jobs remain verification-only. The deployment workflow has no independent master trigger and performs no duplicate verification.

Production Vite settings and the existing deploy/environment/secrets/health/smoke/evidence/worker/rollback path are preserved. Job display names for canonical checks are unchanged. Scope classification preserves docs-only no-op behavior and avoids treating user journey prose as deployable. Dispatch forces all canonical test scopes and can package only from master.

## Review and verification

- `python3 -m unittest discover -s scripts -p 'test_ci*.py' -v`: 18 tests.
- `node --test app/tests/js/deployment-runtime-contract.test.mjs`: 8 existing checks passed unchanged.
- Python bytecode compilation for the helper/tests.
- Workflow parsed with PyYAML `BaseLoader`; dependency references checked by the offline graph tests.
- `git diff --check` required before publication.
- No canonical backend/frontend verifier, full browser suite, database, migration, production command, or credential/secret action was run. Existing runtime contract tests and new offline orchestration tests were run without changing the repository's existing test inventory or assertions.

## Publication boundary and owner actions

Only a **draft PR** may be published for review. PR checks must be inspected as hosted evidence; no production deployment is expected from the PR event. After merge by the root owner, the first master run is the bootstrap activation path: it runs applicable canonical checks, then packages and deploys the same exact master SHA. Do not merge, alter required contexts/settings/secrets/environments, dispatch an older workflow, or deploy from this implementation handoff. Root owner independently reviews readiness, merge, activation, releases, and post-deploy results.

The `CI` workflow name and canonical job display names remain stable; no new required PR status is added. The `Deploy to VPS` job name remains. If branch protection contains a specifically workflow-qualified standalone deployment context, the repository owner must inspect it before merge; no settings are edited here.

## Savings estimate

Workflow-definition count: one backend verifier and one frontend verifier per applicable deployable master run, reduced from two of each across the old CI and deploy workflows. This is measured from source definitions. Runner-minute or billing savings are assumed to equal one backend/MySQL suite and one frontend test/typecheck suite per applicable commit; actual minutes/cost were not measured. The separate production build/package and release smoke remain intentionally.

Hosted run IDs, PR number, final diff inventory, and source-review findings are intentionally left for completion after the draft is published; none are inferred in advance.
