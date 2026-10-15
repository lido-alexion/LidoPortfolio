# V9-VIZ-001 Closure — Live Status

**Updated:** 2026-10-15 09:38 UTC
**Workspace:** `/home/nitty/stox-v9-viz001`
**Branch:** `fix/viz001-combo-route-availability`, based on current `origin/master` `b2096e2fd0b55463c403ad3e1cc3a53569da3789`.
**Current phase:** Runtime fixes and regressions implemented; all four focused VIZ desktop/mobile browser cases pass, as do frontend tests/typecheck/build. Canonical verifier stops only at its required Playwright OS dependency installation because the environment forbids sudo. Final diff review complete; preparing scoped commits and hosted PR CI.
**V9 register:** `IMPLEMENTATION IN PROGRESS`; production acceptance remains failed pending a passing authenticated combo smoke.
**Session/process/log:** Active Codex session; no detached process or durable verifier log. Frontend verifier has exited after the sandbox-denied OS installer; no active local test PID.

## Production evidence

- PR #172 implementation merge: `47cd05132352d6fb46d483d38e1841f1a3ef56dc`.
- PR #175 stage-diagnostics merge: `b2096e2fd0b55463c403ad3e1cc3a53569da3789`. No independent review submission or inline thread was present; root reviewed its diff.
- Production build 543 / workflow run `38711506766` activated exact SHA `b2096e2fd0b55463c403ad3e1cc3a53569da3789`; exact-SHA health passed. Authenticated read-only smoke failed twice at fixed stage `combo_chart_visible` after preference response and account-scope checks passed. No credentials, private account rows, provider payloads, or raw browser errors were inspected here.
- Root supplied safe aggregate listing evidence: NSE TCS id 1886 has 5,458 price rows (all with volume) across 2004-08-25–2026-10-15; BSE TCS id 4573 has no price rows. Source review found symbol-only Watchlist deep links could pick the first same-symbol watchlist row (BSE) before search's NSE preference. This is a reproducible source-supported cause, not direct observation of the private production selection.
- Root review found a second selection-precedence issue: the NSE fallback ran before the same-symbol selectedStock guard. A late watchlist-items update could override an explicit BSE choice. This is now fixed and has a real browser regression that deliberately selects BSE and releases both listing rows afterward; the final selected price-history request remains BSE id 4573.
- Confirmed AC8 gap: when every preset is unavailable, the previous early return omitted the grouped catalogue and disabled reasons. The current UI exposes all seven disabled entries and the no-history explanation without fabricating chart values.

## Current change and verification

- Symbol-only deep links choose an exact NSE listing from watchlist rows when available; otherwise they search exact symbol results preferring NSE, with an existing-listing fallback on search error/no match.
- A same-symbol selected stock now takes precedence over all route fallback resolution, preserving deliberate exchange/listing selection when watchlist rows arrive later.
- AC8 all-unavailable UI retains the named combo-chart region, all seven grouped options with canonical disabled reasons, disabled navigation, and a no-history status.
- All four focused VIZ browser cases passed 4/4: desktop keyboard/control flow; canonical symbol-only deep link chooses data-bearing NSE id 1886 over a BSE-first item; deliberate BSE autocomplete selection survives late both-exchange watchlist rows and continues requesting BSE id 4573; and mobile touch/viewport behavior. Listing-selection cases assert no writes or blocked requests.
- Focused Node listing tests passed 3/3. Combo chart component/preset Vitest passed 9/9.
- `./scripts/verify-ci.sh --frontend`: Node 232/232; Vitest 282/282; TypeScript check and production build passed. It then stopped at the prescribed Playwright OS dependency installer: sudo is disabled by `no new privileges`. The full required browser gate remains assigned to hosted exact-head CI; no browser gate was skipped or weakened.
- `git diff --check` passed. Build-generated timestamp-only AI-guide/static-doc changes were inspected and restored.
- `git fetch origin` completed. `origin/master` remains `b2096e2`; no branch reconciliation needed before publication.

## Next actions

1. Self-review complete diff, including frozen AC1–AC16 coverage and root's BSE-precedence feedback; update the detailed audit truthfully.
2. Commit scoped implementation, tests and audit notes; push normally and publish a reviewable PR.
3. Poll exact-head hosted CI and review; repair any in-scope failures through normal commits, never bypass checks.
4. Merge only green reviewed code. Inspect the deployment queue and allow the normal serialized GitHub release to run; do not trigger a manual deployment or interfere with other releases.
5. Verify exact live SHA health, then the authenticated read-only combo smoke. If it fails, use only fixed stage diagnostics and continue the same reviewed green PR/release cycle.
6. Complete evidence review of AC1–AC16, update V9 register and journey/audit documentation only when supported by production acceptance, and write `docs/audits/V9-VIZ-001-closure-report.md` only on verified closure.

## Boundaries

No other worktree/process, ML implementation/configuration/private evidence, production data/services, broker order, unrelated epic, or deployment gate has been changed. No production write, manual deploy, force-push, secret, or raw production-smoke artifact was used.
