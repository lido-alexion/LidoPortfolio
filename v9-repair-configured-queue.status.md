# Configured ML acceptance queue repair status

- Branch: `fix/ml-repair-configured-queue`
- Starting HEAD: `295f4e41e26fe6728835c06429851f705cc3c4de` (matches the requested starting commit)
- Change: both repair safety checks now resolve and query the configured `ml-acceptance` database queue connection, table and queue name. Unsupported drivers and invalid configuration fail closed. Source/campaign checks and repair scope are preserved.
- Regression: isolated PHP behavioral test covers a nondefault database connection and `portfolio_jobs`, confirms a nonempty queue blocks both checks before the mutation continuation, and checks invalid configurations.
- Verification passed: PHP lint for the repair script and regression; `php app/scripts/tests/test_repair_ml_acceptance_configured_queue.php`; `git diff --check`.
- Verification incomplete: the broader `node --test app/tests/js/deployment-runtime-contract.test.mjs` did not complete in the local run; a name-filtered attempt exited unsuccessfully without diagnostics. Hosted canonical CI is still required.
- GitHub sync/push blocker: `git fetch origin refs/heads/master:refs/remotes/origin/master` failed because sandbox DNS could not resolve `github.com`. Do not push until the ordinary fetch/reconcile step succeeds. Current HEAD still starts from the requested commit; no production or service actions were run.
- Production boundary: no production databases/writes, imports, campaigns, sudo, services, permissions, locks, training, model/lifecycle/schedule actions, or manual deployment were performed. Worker state and existing uploads were left untouched.
