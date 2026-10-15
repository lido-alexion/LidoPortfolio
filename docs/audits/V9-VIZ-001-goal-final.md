# V9-VIZ-001 Closure Handoff — Not Closed

**Updated:** 2026-10-15 11:23 UTC
**State:** Epic remains open; production acceptance is not verified.
**Latest merged code:** `f43201beb43ea5145ed3fb8ea742bd5167dc8432` (PR #178).
**Follow-up source:** `c485eaf1` (`fix/viz001-default-availability-sync`), pushed and based on latest fetched master.
**Deployment evidence:** Build545/run `38722690372` activated `f43201be` and exact-SHA health passed. Authenticated smoke still failed; no live VIZ acceptance.

## Work completed

- Audited production failure context without claiming the private listing selection or production latency was observed. Prior safe aggregate evidence showed NSE TCS id 1886 has 5,458 volume-bearing price rows while BSE id 4573 has none; the smoke's selected private listing was not logged.
- Implemented user-overridable monthly sampling for dense all/5Y histories in combo and Stock Details Price + Volume charts, retaining actual valuation observation dates and summed volume without fabricated or forward-filled fundamentals.
- Resolved saved-default availability in the same render while preserving explicit user selection. Existing BSE-first deep-link and deliberate BSE-selection regressions remain.
- Preserved production smoke's shared 20-second budget, fixed/sanitized diagnostics, default precedence checks, and actual nonempty Recharts data geometry requirement.
- Updated journey and VIZ audit/status documents. The V9 register remains `IMPLEMENTATION IN PROGRESS`.

## Verification evidence

- Configured VIZ-01 Chromium suite: 6/6 passed, including mobile/touch, keyboard and legend use, canonical symbol deep-link, deliberate BSE selection, delayed saved-default resolution, and synthetic 5,458-row rendering while all six metric requests are pending. The dense fixture verifies real SVG line/bar geometry and monthly sampling on both charts.
- Canonical `./scripts/verify-ci.sh --frontend`: 235 Node tests, 287 Vitest tests, typecheck, and production build passed. The exact verifier then stopped at the prescribed Playwright OS dependency installer because sandbox sudo is blocked by `no new privileges`. No gate bypass was used. Hosted exact-head CI is still required.
- Focused data/sampling/privacy Node tests: 11/11 passed. Journey metadata check passed (129 topics); static docs check passed (53 topics); production smoke syntax and `git diff --check` passed.
- Master CI `38722690366` passed for `f43201be`. Build545 authenticated production smoke failed; there is no successful VIZ production run.

## Connector blocker and exact next action

The source commit is pushed and ready for review. GitHub connector `github_create_pull_request` was called for repository `lido-alexion/LidoPortfolio`, head `fix/viz001-default-availability-sync`, base `master`, and returned: **“MCP tool call requires approval, but approval policy is never.”** Read-only lookup of commit `c485eaf1` found no PR workflow runs because publication did not occur. An authorized root/operator must create the PR through the normal GitHub connector; no local workaround or alternate mutation path was used.

Use this PR title:

> V9-VIZ-001: render dense combo histories within the existing smoke budget

Use this PR body:

> ## Scope
>
> - Select deterministic monthly sampling for dense all/5Y histories above 1,200 rows in the combo chart and the existing Stock Details Price + Volume chart. Keep ordinary defaults daily and preserve user cadence overrides.
> - Preserve actual P/E, P/B and P/S observations on their source dates while sampling prices; do not carry them forward or double-count volume.
> - Resolve late saved-default availability in the same render while retaining explicit user choices.
> - Keep production smoke assertions intact and the shared 20-second chart-readiness budget unchanged. Distinguish fixed stages and require nonempty, preset-appropriate chart geometry.
> - Add synthetic 5,458-row, delayed-history, saved-default, listing-choice, alignment, and no-write regressions; update the VIZ journey and in-repo audit. Production listing selection and history timings remain unobserved.
>
> ## Verification
>
> - VIZ-01 configured Chromium suite: 6/6 passed, including desktop/mobile, canonical symbol deep-link, deliberate BSE selection, delayed saved-default precedence, and dense 5,458-row rendering while six metric requests remain pending. The dense test checks real line/bar SVG geometry and monthly states on both charts.
> - Canonical `./scripts/verify-ci.sh --frontend`: 235 Node tests, 287 Vitest tests, typecheck and production build passed. The verifier then stopped at its prescribed Playwright OS dependency install because this sandbox blocks sudo (`no new privileges`); no bypass was used. Hosted exact-head CI must complete the canonical browser gate.
> - Focused data/sampling/privacy Node tests: 11/11 passed. Journey metadata (129 topics), static docs (53 topics), smoke syntax, and `git diff --check` passed.
>
> ## Production context
>
> Build545 on `f43201be` passed activation and exact-SHA health but the authenticated read-only smoke failed. This PR does not claim measured production latency or claim that synthetic timing proves the cause. It does not extend the smoke timeout, accept an empty shell, fabricate data, or change account-default precedence. Epic and V9 register remain in progress until green reviewed CI, normal serialized deployment, exact-SHA health, and authenticated VIZ smoke all pass.

After publication, require green exact-head CI and normal review/merge, inspect serialization before release, and do not interfere with another deployment. Verify deployed SHA health and authenticated read-only combo acceptance. If that smoke fails, continue fixed-stage diagnosis and another normal reviewed release. Only then update the V9 register and issue a closure report.
