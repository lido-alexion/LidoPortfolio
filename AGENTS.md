# StoX agent rules

These rules apply equally to Codex, ChatGPT Work, ChatGPT Chat, human contributors, and automated tools. They are outcome rules: GitHub verification and protected-branch policy are authoritative. For production VPS checkout, verification, and push lessons, read [docs/current/CODEX-VPS-REPO-WORKFLOW-LEARNINGS.md](docs/current/CODEX-VPS-REPO-WORKFLOW-LEARNINGS.md).

## Before changing code

1. Start from the latest `origin/master`; fetch and reconcile before work and again before a push.
2. Preserve unrelated working-tree changes and never force-push or rewrite shared history.
3. Read the relevant frozen specification and protect V8 contracts that remain in REVIEW.
4. Do not weaken tests, bypass safety checks, or silently change a production/deployment contract to obtain green CI.

## CI-parity verification

For routine local changes, start with the focused contract or unit tests that
cover the edited scope. Run the complete local CI-parity verifier when the
change affects a high-risk contract (including CI orchestration, release gates,
deployment, migrations, security, or financial integrity) and the required
services/toolchains are available and safe in this environment. If they are not
available or appropriate, run the comprehensive relevant offline contracts
and report the limitation. This local focus does not replace canonical hosted
checks, protected-branch requirements, or production release gates.

Use the shared verifier from the repository root:

```bash
./scripts/verify-ci.sh --backend
./scripts/verify-ci.sh --frontend
./scripts/verify-ci.sh --all
```

The backend mode intentionally requires MySQL and the PHP extensions declared by CI. The frontend and nightly journey modes install and verify the exact Playwright Chromium executable before browser tests. Do not substitute SQLite-only or browser-less runs for these gates when a change affects their scope.

## Migrations and generated contracts

1. Run `php scripts/verify-migration-portability.php` for every migration change.
2. Use explicit index/foreign-key names when Laravel's generated name could exceed MySQL's 64-character limit.
3. Do not use defaults on JSON/TEXT/BLOB-style columns unless the deployed MySQL version and the full migration gate prove support.
4. Regenerate and check OpenAPI/static documentation when routes or documented contracts change.

## Commit and push discipline

1. Keep commits narrow, scoped, and independently verifiable.
2. Before each push: fetch `origin`, inspect `HEAD...origin/master`, reconcile normally, run the focused relevant checks or full local CI-parity verification as required above, and run `git diff --check`. Record tests and any local environment limitations; required hosted CI remains mandatory.
3. Never push a change that skips required CI unless the Product Owner explicitly authorizes the exception and the commit records why.
4. Do not deploy manually to bypass GitHub Actions. Production releases must remain tied to the exact verified commit SHA and pass the post-deploy SHA health check.

## Standing StoX owner authorization: autonomous release lifecycle (2026-11-15)

The StoX product owner grants standing authorization for any code fix to commit scoped local changes, push a feature branch, raise a GitHub pull request, independently self-review (no external reviewer is available), address failed CI checks, merge after all required checks pass, and track the existing GitHub Actions CI/CD deployment through exact-SHA production verification. Do these proactively as the default; do not stop at a local fix or request redundant push/PR/merge/deploy approval. An explicit owner HOLD overrides this authorization. Respect branch protections, required checks, CI integrity, existing deployment procedures, and production rollback/health safeguards.

This is NOT blanket authorization for discretionary production database/config/cache/scheduler/worker edits, incident replays, provider backfills, financial/trading operations, or real-account notification experiments. Obtain specific approval for these. Exception granted November 15, 2026: ONE controlled historical P4 API incident replay canary, only after verifying safeguards, running a dry-run, and establishing release readiness; never treat that as approval for bulk replay or additional canaries.

Incident/supervisor reports MUST contain a highly visible **ACTION REQUIRED FROM YOU** section listing exact owner actions/approvals and why they block closure; state **None** if no owner action is needed. Proactively resolve everything within standing authorization without waiting for the next user message, and continue tracking CI/CD and live acceptance until actual closure.
