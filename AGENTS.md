# StoX agent rules

These rules apply equally to Codex, ChatGPT Work, ChatGPT Chat, human contributors, and automated tools. They are outcome rules: GitHub verification and protected-branch policy are authoritative.

## Before changing code

1. Start from the latest `origin/master`; fetch and reconcile before work and again before a push.
2. Preserve unrelated working-tree changes and never force-push or rewrite shared history.
3. Read the relevant frozen specification and protect V8 contracts that remain in REVIEW.
4. Do not weaken tests, bypass safety checks, or silently change a production/deployment contract to obtain green CI.

## CI-parity verification

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
2. Before each push: fetch `origin`, inspect `HEAD...origin/master`, reconcile normally, run the relevant verifier mode, and run `git diff --check`.
3. Never push a change that skips required CI unless the Product Owner explicitly authorizes the exception and the commit records why.
4. Do not deploy manually to bypass GitHub Actions. Production releases must remain tied to the exact verified commit SHA and pass the post-deploy SHA health check.
5. `master` should be protected in GitHub: required PRs, required current CI checks, and required review for workflow, deployment, migration, and dependency-lockfile changes.
