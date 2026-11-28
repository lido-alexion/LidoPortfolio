# Authentication upgrade Phase 1 release and rollback runbook

This runbook is an operator procedure for a future approved release. No production
cutover or rollback rehearsal was performed while preparing it. Coordinate release
execution and evidence collection through the StoX supervisor; do not use this
document to make direct production changes outside the existing GitHub Actions
release workflow.

## Compatibility across mixed versions

- The investor session cookie name, legacy Laravel `web` session key, investor
  remember-me cookie name, session payload, and Sanctum CSRF cookie contract are
  preserved. Existing investor sessions are expected to remain readable by the
  upgraded release and by the prior release during rollback.
- Administrator sessions use a separate `-admin` session cookie and contextual
  guard key. A prior release does not recognize that administrator session; an
  administrator may need to sign in again after rollback. This does not invalidate
  the investor cookie.
- The session database schema is unchanged by this authentication upgrade. Do not
  roll back or truncate session rows as part of application rollback.
- New Kite login states require the one-time shared-cache replay key. A callback URL
  started before upgrade that lacks this key fails closed; restart Kite login after
  upgrade. Callback ownership remains the initiating StoX user. A successful
  reconnect for that same StoX user replaces the saved broker account ID and access
  token with the account returned by Kite. There is no separate broker-account
  switching approval flow in this scope.
- Deploy only when the production cache supports atomic locks and shared values
  across application workers. The hosted tests do not prove that production
  configuration.

## Release preflight and safe-account smoke

The supervisor should retain the exact release SHA and successful hosted
`./scripts/verify-ci.sh --backend` and `./scripts/verify-ci.sh --frontend` results,
including browser evidence, before scheduling the existing deployment workflow.
Confirm the target is the exact verified `master` SHA and that the previous release
directory remains available. No database migration is expected for this change.

After the workflow activates the release and its exact-SHA health check passes, use
only designated safe test accounts with no funded/live broker connection or
submittable orders:

1. Open one browser profile and sign in as the safe investor. Open the administrator
   entry in another tab and sign in with the safe administrator account.
2. Confirm both sessions remain active at once, with the investor session using
   existing portfolio access and the administrator session limited to Admin routes.
   Verify an investor write from the administrator tab is denied without changing
   data.
3. In two investor tabs, select different safe portfolios. Refresh each tab and
   verify its selection remains tab-local; test logout from one context and confirm
   the other context remains signed in.
4. Exercise only non-destructive reads and a reversible safe-fixture write approved
   for the smoke. Do not submit, cancel, or reconcile a real broker order, and do
   not connect a real broker account. Do not use actual customer accounts.
5. Record the exact SHA, UTC time, account roles (no names, IDs, cookies, or tokens),
   route/result summary, and public runtime health result in the supervisor record.
   Stop and invoke the rollback decision path if identity, authorization, or health
   checks fail.

Interactive acceptance with safe accounts is still required; automated synthetic
fixtures are not a substitute for this check.

## Rollback decision and procedure

If the deployed release fails its exact-SHA runtime health check, safe-account
authorization smoke, or session isolation check, the supervisor should halt further
acceptance and use the established rollback helper with the retained previous
release ID. The helper validates its production app root, cached production config,
worker privileges, acceptance-worker state, target build metadata, and then switches
the `current` symlink, rebuilds Laravel caches, restarts queue/PHP-FPM services, and
runs the hard health check against the target commit SHA.

Use the existing workflow-managed helper from the deployment account, with its
release ID obtained from deployment evidence:

```bash
~/.stoxla-deploy/stoxla-rollback-release.sh <previous-release-id>
```

Do not hand-edit the symlink, run migrations down, clear session rows, or restore
database backups for this application-only rollback. If the helper refuses because
of service privileges, active acceptance work, missing target metadata, unsafe
cached configuration, or failed health, preserve evidence and escalate through the
supervisor rather than bypassing the guard. After rollback, confirm the health
response reports the previous exact SHA and repeat only the non-destructive safe
account login/read checks. Existing investor sessions should remain compatible;
administrator sessions may need to be re-established on the previous version.

No rollback execution has been claimed by this runbook. Capture a future rehearsal
or actual rollback result separately with the release SHA, target release SHA,
health result, and supervisor record reference.
