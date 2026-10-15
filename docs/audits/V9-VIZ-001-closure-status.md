# V9-VIZ-001 Closure — Live Status

**Updated:** 2026-10-15 08:46 UTC (runner clock)
**Owner:** Codex VIZ-001 closure session
**Workspace:** `/home/nitty/stox-v9-viz001`
**Branch / local base:** `fix/viz001-smoke-stage-diagnostics`, currently based at `47cd05132352d6fb46d483d38e1841f1a3ef56dc` (merged PR #172)
**Latest upstream master observed through GitHub read-only connector:** `aeb4c3d0a200815e0537c2a1cb53ac95c137d8dc` (merged PR #174 documentation, after PR #170; not yet integrated locally)
**Local diagnostic commit:** `015ae043b90e3c7800139f344ce1eee5dd064d3f` (not published; parent is `47cd051`)
**Local branch tip:** `7c7de630` (three unpushed local commits: diagnostic code/test and two evidence-only status updates)
**Session / process / log:** active conversation; no persistent shell PID, detached process, or durable log file. Prior frontend verifier exec session `11134` ended after the sandbox-denied child-process test.

## Goal and plan

Close frozen `docs/archive/specs/V9-Combo-Chart-Support-Specification.md` AC1–AC16 with truthful implementation audit and V9 register evidence. Preserve merged VIZ implementation and other worktrees. Diagnose authenticated production combo smoke failure without exposing credentials/provider responses; retain every assertion and request-safety gate. Deliver diagnostic/runtime fixes through normal reviewed PR and exact-head CI; merge green code normally; allow serialized GitHub deployment; confirm deployed exact SHA, health and authenticated read-only VIZ acceptance before register closure.

## Current phase

**Post-merge production acceptance remediation.** PR #172 merged normally as `47cd05132352d6fb46d483d38e1841f1a3ef56dc`. Master CI run `38698956743` passed. Production deployment build 540 / run `38698956653` activated that exact SHA and its post-deploy exact-SHA health check passed; its final non-destructive browser smoke failed at the coarse diagnostic stage `combo_chart`. The feature has therefore not passed live acceptance and the V9 register remains `IMPLEMENTATION IN PROGRESS`.

This session created a clean local branch from then-recorded `origin/master` SHA `47cd051`. New upstream commits `51fec37` (PR #170) and `aeb4c3d` (PR #174 documentation) are visible via GitHub's read-only commit search. SSH and HTTPS fetch attempts at 08:41 and 08:46 UTC failed because this runner cannot resolve `github.com`. The UX-003 worktree was not touched. Normal integration with current master `aeb4c3d` is required before publishing; do not push the stale-base branch. GitHub connector evidence confirms PR #172's merge and successful CI/health stages. The smoke artifact was not downloaded because it can contain browser evidence and is unnecessary for the first diagnostic step.

## Acceptance audit (frozen AC1–AC16)

The implementation audit records the source and regression evidence for each criterion. Current evidence: AC1–4 and 7–16 are implemented and have local data/component/journey/browser evidence as cited in `V9-VIZ-001-implementation-audit.md`; AC5–6 have defaults and account-scoped authenticated API coverage, with hosted backend verification passed on the merged head. The original feature regression suite also passed the merge CI. Production-specific authenticated combo acceptance is **not passed**: it failed after login/review at `combo_chart`. AC1–16 are not considered end-to-end closed until authenticated deployed combo acceptance succeeds.

| Criterion group | Evidence / state |
| --- | --- |
| AC1–4 preset surface, single selection, grouped selector, cycle | Implemented; audit cites component and VIZ-01 tests. Deployed authenticated acceptance pending. |
| AC5–8 system/account default, opening precedence, disabled reasons | Implemented; API/component/data tests; merged hosted CI passed. Live smoke default assertion pending diagnosis. |
| AC9–12 range, sampling, renderer/axes, aligned tooltips, legend | Implemented; focused data/component and desktop/mobile VIZ-01 evidence cited by audit. |
| AC13–14 chart-pattern reuse and individual charts | Source-reviewed/preserved; chart coexistence evidence cited by audit. |
| AC15–16 touch/keyboard and regression matrix | Local Chromium/hosted merged CI evidence cited by audit; current production smoke still failing. |

## Smoke diagnostic remediation

On local branch, only authenticated smoke diagnostics are being refined. The coarse `combo_chart` region is split into fixed labels for navigation start, preference response, preference account scope, chart visibility, opening default, enabled options/default resolution, keyboard navigation, legend toggling, selection restoration, then the existing request-safety stage. A fixed allowlist converts any unexpected stage string to `unknown`; no Playwright error, selector value, account data, response content, credential, provider output or screenshot is logged. Existing checks and read-only request interception remain unchanged.

Added regression test `app/tests/js/productionSmokeDiagnostics.test.mjs` verifies all stage labels are accepted and secret/provider-shaped values are collapsed to `unknown`; it passed. Direct smoke-script runs for missing and non-HTTPS URL both produced only `AUTHENTICATED_SMOKE_FAILED:configuration` and exit 1. Syntax checks and `git diff --check` passed. Canonical `./scripts/verify-ci.sh --frontend` reached the Node suite; its existing `authenticatedProductionSmoke.test.mjs` child-process invocation is denied here (`spawnSync /usr/bin/node EPERM`), so the local verifier did not complete and no local full-suite pass is claimed. The attempted verifier shell session was stopped after the child-process denial; there is no active command. Hosted exact-head CI remains required.

## PR, checks, deployment evidence

- PR #172: merged; SHA `47cd05132352d6fb46d483d38e1841f1a3ef56dc`.
- Independent submitted review: none. Root diff review and explicit user merge authorization were the basis for merge; do not describe an independent review as pending or completed.
- Master CI run `38698956743`: successful (frontend/build, backend PHPUnit/MySQL, Python/FastMCP, and required jobs).
- Production workflow run `38698956653`, build 540: package, upload/activation, and `stoxla-runtime-health-check` exact SHA all succeeded; browser smoke step failed.
- The separate UX-003 merge advanced master to `51fec37be88399275948a74491dfef7350a61a30`; its CI run `38703908998` passed. Deployment #541 / run `38703909086` is completed (not active/queued): activation and exact-SHA health passed, but the same authenticated production smoke step failed. Safe log retrieval returned no step text/diagnostic markers, so no finer failure location is established. This confirms the smoke issue persisted across that deployment; it does not identify a cause.
- PR #174 (`aeb4c3d`) records that production remains at verified SHA `51fec37` (its docs-only deployment run skipped all deploy jobs) and the `combo_chart` failure also occurred before UX-003 on `47cd051`, after the VIZ production-smoke assertion was introduced by `f273c80c`. This supports that the failure is in the VIZ production-smoke contract, but it does not identify which assertion fails. Latest master CI and docs-only workflow succeeded; no master deployment is active or queued.
- Failed assertion unknown at this stage. The safe prior logger reported only `AUTHENTICATED_SMOKE_FAILED:combo_chart`; no exact assertion or underlying error is available from that log. Local refinement is intended to identify it on the next normal release smoke without relaxing checks.
- PR #172 stale review-blocker wording could not be edited through GitHub connector because its mutation request required approval and the configured approval policy is never. Do not retry or bypass; correct repository-owned audit/report wording in the next reviewed change and record that the PR-body edit was blocked if still relevant.

## Blockers and next actions

1. Local diagnostic commit is self-reviewed; `git diff --check`, focused stage-allowlist test, script syntax, and direct missing/non-HTTPS fail-closed checks passed. Hosted frontend CI must pass because this runner cannot spawn the pre-existing subprocess test.
2. Integrate current upstream master `aeb4c3d` into this branch after normal `git fetch` works. Current SSH and HTTPS fetch both fail DNS; do not publish/push until normally reconciled. Latest deployment history has no active run, so no other deployment needs cancellation or coordination now. Never rewrite shared history or force-push.
3. Publish diagnostic changes as a reviewable PR through standard GitHub flow after reconciling `aeb4c3d`; git pushes currently cannot resolve GitHub DNS, while prior GitHub connector mutation was approval-blocked under approval policy `never`. Local branch/commit/diff are ready. Required root-side action if this persists: restore normal git remote connectivity and fetch/merge `origin/master`, then push branch `fix/viz001-smoke-stage-diagnostics` and open a PR against `master` from commit `015ae043` plus its normal reconciliation merge.
4. Poll exact-head required CI and review feedback. Fix failures in scope without weakening gates; merge only reviewed green code.
5. Inspect release queue/active deployments before allowing another workflow. Never cancel another thread's release. Let master deployment serialize normally; do not manually deploy.
6. Use fixed sub-stage output to fix the actual combo-chart cause, then repeat green PR/CI/merge/deploy as needed.
7. Only after exact live SHA health and authenticated, non-destructive desktop/mobile-relevant combo smoke succeeds, update the detailed audit, journey evidence, and V9 register from `IMPLEMENTATION IN PROGRESS` to an evidence-supported verified status; produce final report in `docs/audits`.

## Boundaries

No ML implementation, production data/service, broker order, other worktree/process, or unrelated epic implementation has been changed. Read-only review of PR #174's merged audit note was limited to shared VIZ smoke failure/deployment evidence. No secret values or production smoke artifacts have been retrieved. No manual deploy, gate bypass, force-push, or production write has occurred.
