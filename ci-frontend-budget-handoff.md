# Canonical frontend CI outer timeout adjustment

## Change

On 2026-10-21, with explicit PO approval for the additional runner minutes and root's sole publication lease, the canonical frontend job's outer GitHub Actions timeout changes from 30 to 45 minutes in `.github/workflows/ci.yml`. This is a conservative ceiling, not a runtime promise. It leaves the verifier, tests, assertions, test-level timeouts, retries, skips, runner platform, check names, scope classifier, production/deployment workflows, and runtime contracts unchanged.

## Basis and limits

The owner incident response records two cancelled frontend attempts in PR run `39456731386`: job `118759880914` cancelled at 17:20:37 with 421 total emitted browser passes and 2 governed skips, and job `118776290407` cancelled at 17:53:52 with 440 total emitted browser passes and 2 governed skips. In each cancelled attempt, the two PR238-owned E2E-19 cases passed on first execution. Both attempts had no failures, retry/flaky/final result. The second also reported Node 247, Vitest 464/83, typecheck and build passing. Neither cancellation is a successful check; proximity to the prior 30-minute outer limit supports timeout as an inference only and does not establish cancellation cause.

## Review and release boundary

Base: freshly fetched `origin/master` at `a11d86f57bdab8e89d8ee3cefa2c30abbf43b510`. This is a separate checkout; the unpublished consolidation commit `2a66ebd7` remains untouched. Static YAML/source and diff checks only; no local frontend verifier, install, browser, database, merge, deployment, manual dispatch, or prior PR rerun is part of this change. Before this handoff-only correction, draft PR 239 was open at head `a7a64feca08ea96bad2e9e23479f66f075ab19d5`. Canonical CI run `39498469133` for that exact commit completed successfully: changed-area detection, Python AI/FastMCP contracts, frontend verification, and PHPUnit succeeded; the VPS health monitor job was skipped. The earlier PR238 frontend attempts remain cancelled, not green. Keep PR 239 draft until root's independent review/readiness decision; root owns merge and release. No root acceptance, merge, or deployment is claimed. After successful review/merge, reconcile PR 238 normally against the new master and run its exact-head CI; do not count old cancellations as passes.

Publication uses the repository's documented configured SSH alias `github-lidoportfolio` only if the normal HTTPS push cannot obtain a username. No credentials or private keys are read, no alternate account or permission is used, and no history is rewritten.
