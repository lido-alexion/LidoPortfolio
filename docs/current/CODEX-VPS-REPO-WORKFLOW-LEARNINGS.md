# VPS repository workflow learnings

These notes capture practical lessons from the FEAT-063 / FEAT-065 / V9-DATA-002 retirement work. Follow the root `AGENTS.md` CI and branch rules; this guide adds production-VPS-specific cautions.

## Start from the right checkout

- Identify the intended repository and its active checkout before editing. Read root `AGENTS.md` and the relevant current specs first.
- On the VPS, confirm the branch and worktree state. Prefer a fresh feature branch from fetched `origin/master`; do not assume an old deployment checkout is current.
- Preserve existing local commits and unrelated work. Do not reset or clean a shared checkout to make it match the remote.
- Keep adjacent epics and shared flows in scope: trace route, service, UI, config, scheduled-task, deployment, and test references before removing feature code.

## Treat data and production hosts carefully

- Retiring a feature does not mean deleting its migration history or stored data. Preserve historical migrations and use a forward migration for schema retirement; verify migration portability.
- Read CI scripts before running them. In this repository, backend verification runs `migrate:fresh` against its configured MySQL database. Never point this at production; use isolated CI/test databases.
- Do not run heavyweight install/build/browser suites on a production VPS unless specifically required. The frontend verifier can install npm packages and Playwright OS dependencies and use substantial CPU/time. Prefer hosted PR CI.
- Build commands may touch generated timestamps or outputs. Review `git status` afterward and restore only known, irrelevant generated noise; never discard unexplained changes.
- Keep unrelated repositories and deployment services outside the task scope untouched.

## Review, commit, and push

- Before staging, inspect `git status`, `git diff --stat`, the full diff, and `git diff --check`. Explicitly confirm intended untracked files are included.
- If the VPS lacks Git author identity, inspect recent commits and set repository-local `user.name` / `user.email` to the established identity. Do not invent an identity or change global config.
- If HTTPS push hangs, check whether Git is waiting for interactive credentials. Inspect the configured SSH host alias and remote URL; verify access with `git ls-remote`, then use the established SSH alias. Never expose or read private key contents.
- Fetch before pushing, inspect divergence from `origin/master`, reconcile without rewriting shared history, and push only the feature branch.
- Keep the PR draft while verification is incomplete. Check the actual required GitHub jobs and merge only after they pass. A push to `master` triggers production deployment; do not bypass that workflow with a direct push or manual deployment.

## Verification notes from this change

- Hosted PR CI passed the required Python/FastMCP, frontend, and PHPUnit jobs.
- The local VPS backend verifier could not authenticate to its configured MySQL account before migration execution; no database changes occurred. Hosted CI supplied the merge gate.
- One full Vitest run had an unrelated Discovery history failure; the isolated test passed on rerun. Record both outcomes rather than claiming the full run passed.
- Playwright journeys were not run on the production VPS.
