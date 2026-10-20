# Canonical frontend CI outer timeout adjustment

## Change

On 2026-10-21, with explicit PO approval for the additional runner minutes and root's sole publication lease, the canonical frontend job's outer GitHub Actions timeout changes from 30 to 45 minutes in `.github/workflows/ci.yml`. This is a conservative ceiling, not a runtime promise. It leaves the verifier, tests, assertions, test-level timeouts, retries, skips, runner platform, check names, scope classifier, production/deployment workflows, and runtime contracts unchanged.

## Basis and limits

The owner incident response records two cancelled frontend attempts in PR run `39456731386`: job `118759880914` cancelled at 17:20:37 with 421 passed and 2 skipped, and job `118776290407` cancelled at 17:53:52 with 440 passed and 2 skipped. Both had no failures, retry/flaky/final result, and 19 first-attempt E2E passes. The second also reported Node 247, Vitest 464/83, typecheck and build passing. Neither cancellation is a successful check; proximity to the prior 30-minute outer limit supports timeout as an inference only and does not establish cancellation cause.

## Review and release boundary

Base: freshly fetched `origin/master` at `a11d86f57bdab8e89d8ee3cefa2c30abbf43b510`. This is a separate checkout; the unpublished consolidation commit `2a66ebd7` remains untouched. Static YAML/source and diff checks only; no local frontend verifier, install, browser, database, merge, deployment, manual dispatch, or prior PR rerun is part of this change. Hosted PR CI is the verification producer. Keep this PR draft until root's independent review/readiness decision. Root owns merge and release. After successful review/merge, reconcile PR 238 normally against the new master and run its exact-head CI; do not count old cancellations as passes.

Publication uses the repository's documented configured SSH alias `github-lidoportfolio` only if the normal HTTPS push cannot obtain a username. No credentials or private keys are read, no alternate account or permission is used, and no history is rewritten.
