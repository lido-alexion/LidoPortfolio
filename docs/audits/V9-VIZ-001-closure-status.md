# V9-VIZ-001 Closure — Live Status

**Updated:** 2026-10-15 10:21 UTC
**Workspace:** `/home/nitty/stox-v9-viz001`
**Branch:** `fix/viz001-progressive-history-readiness`, created from fetched `origin/master` `c4eb345550b32c110789146fabc940dac81479ee`.
**Current phase:** Fixing repeated production acceptance failure. PR #177 merged as `c4eb3455`; build544 / deployment run `38717452088` activated that exact SHA and exact-SHA health passed, but authenticated read-only smoke failed twice at `combo_chart_visible` (job `116364601091`). Source trace found `StockDetailsComboChart` withheld `ComboChart` until all six fundamentals histories settled, while price/volume observations were already supplied by Stock Details. This is a concrete frontend dependency defect; production request latency was not measured and is not claimed as proven. Local fix now renders data-backed Price + Volume after preference resolves, independently settles metrics with explicit loading/unavailable states, and resolves an account default against its own history while retaining deliberate in-session user choice. Focused component tests pass 8/8 and browser suite 5/5, including deferred P/E, rendered price/volume SVG, and eventual default selection. Canonical frontend verifier passed 232 Node and 286 Vitest tests, typecheck and production build; it stopped only at prescribed Playwright OS dependency installation, denied because sandbox policy forbids sudo. No PR published yet.
**V9 register:** `IMPLEMENTATION IN PROGRESS`; production acceptance remains failed.
**Session/process/log:** Active session; focused test processes exited successfully. Canonical verifier has not started; no detached verifier process or durable log.

## Production evidence and diagnosis

- Authenticated read-only production smoke on build544 (run `38717452088`) failed twice at fixed stage `combo_chart_visible`, job `116364601091`; the prior preference-response and account-scope checks passed. The stage assertion and timeout are unchanged.
- Root-provided aggregate evidence: NSE TCS listing id 1886 has 5,458 price rows, all containing volume, dates 2004-08-25 through 2026-10-15; BSE id 4573 has no price rows. This aggregate does not identify which private listing the smoke account selected.
- PR #177's listing resolver and explicit-selection protection remain source-supported and regression-tested, but build544 proves they did not by themselves fix live visibility. AC8's all-unavailable catalogue correction remains intact.
- `StockDetailsComboChart.jsx` previously gated the entire `ComboChart` on `loading` set true until a `Promise.allSettled` across PE/PB/PS/EPS/revenue/net-profit finished. Price + Volume derives entirely from the already-loaded `prices` prop. `FundamentalInvestorSnapshotService::valuationMetricHistory` can traverse every daily price row and calculate PIT valuation metrics; this is a plausible expensive endpoint path, but live duration/request timing has not been measured. The repair removes the unnecessary frontend all-six-settled gate without changing service work, endpoints, data, or smoke assertions.

## Current local fix

- Each metric request now settles independently; outstanding metric presets stay present and disabled with a loading reason, settled failures retain their explicit unavailable reason, and successful histories become eligible by canonical thresholds.
- Actual Price + Volume chart renders as soon as price observations and account preference response permit. A saved metric default stays marked/loading and cannot be mistakenly saved over; when its own history becomes available, it wins unless the user intentionally chose another chart while waiting. If history proves unavailable, the specification fallback resolver applies.
- Regressions cover six outstanding histories while real price and volume are rendered, delayed saved P/E default then precedence, user selection during the delay, partial request failure, retry/recovery, and no preference writes. Browser regression uses a deferred P/E response, verifies rendered line/bar SVG from real fixture observations while pending, then verifies the saved default takes effect.
- No production writes, credentials, raw account rows, network payloads, manual deploy, or latency benchmark were used.

## Verification so far on this branch

- `npm run test:js:tos -- tests/js/tos/dashboard-combo-recovery.test.jsx`: 8/8 passed.
- `npm run test:e2e:tos -- tests/e2e/v9-viz001-combo-charts.spec.js`: 5/5 Chromium tests passed after final code, including desktop, BSE-first route selection, deliberate BSE preservation, deferred-history render/default integration, and mobile touch.
- The initial attempt used nonexistent npm script `test:unit` and did not execute tests; rerun used the repository script above. An initial test assertion expected a Recharts SVG in jsdom (not laid out there); corrected it to assert semantic data/availability, while the browser regression verifies actual SVG render.
- The first canonical frontend run found one selector regression: a newly changed saved-default prop must still supersede a prior local selection. The implementation now distinguishes that explicit prop change from a late availability update; focused preset coverage passed and the complete canonical rerun passed all 286 Vitest tests.
- `git diff --check` passed before the last test label correction; rerun before commit.
- Canonical `./scripts/verify-ci.sh --frontend`: 232 Node and 286 Vitest passed; TypeScript and production build passed. The prescribed Playwright OS dependency installer then exited because `sudo` is blocked by `no new privileges`. Focused exact Chromium browser coverage passed 5/5; hosted exact-head CI remains required.
- `npm run docs:static:check` passed (53 topics) after journey documentation update; `git diff --check` passed. Branch self-review, fetch/reconcile before push, and exact-head hosted CI remain outstanding.

## Next steps

1. Run the canonical `./scripts/verify-ci.sh --frontend`; resolve in-scope failures without weakening gates. Inspect generated noise afterward.
2. Review the final complete diff against frozen AC1–AC16 and existing feedback; update journey/audit evidence accurately.
3. Fetch latest `origin/master`, reconcile normally, rerun required checks and `git diff --check`; commit/push a scoped branch and publish a reviewable PR. Root will handle connector mutation if necessary.
4. Poll exact-head required CI; resolve any in-scope failures using new reviewed commits. Merge normally only after green checks/root review.
5. Inspect serialized deployment state and leave other releases untouched. Verify the exact deployed SHA health and unchanged authenticated read-only production smoke. If visibility still fails, use fixed stage diagnostics and source investigation; do not extend timeout or mask the assertion.
6. Only after authenticated acceptance passes: update V9 register, journey/audit documentation, and write the final closure report. Do not claim epic closure before evidence.

## Scope boundaries

No other worktree/process, ML implementation/configuration/private evidence, production data/service, broker order, unrelated epic, or deployment policy was changed. No manual deployment, forced history, gate bypass, or secret access occurred.
