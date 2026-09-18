# AUD-009 - Deployment / Secrets / Scheduler / Production Runtime Verification

## 1. Finding Recap

AUD-009 verifies the actual StoX production environment rather than inferring production behavior from repository scripts. The current target is the Hostinger VPS serving `https://stoxla.in/`, not the archived GoDaddy/cPanel `/portfolio` deployment.

**Disposition: `RUNTIME_VERIFICATION_REQUIRED` (Medium severity, High confidence).** The VPS layout, secret isolation, database, HTTPS, cron scheduler, release activation, and queue processing are now operationally verified. The two prior High defects are closed. Remaining runtime work is bounded to release freshness/pending approved deployment and backup/restore assurance.

No application release was changed, no migration was run, and no secret value was read during the DEP-001/DEP-002 activation.

## 2. Current Deployment Architecture

The accepted deployment architecture is the Hostinger VPS `stoxla-prod` at `82.112.230.20`, running Ubuntu, Nginx, PHP-FPM, MariaDB, a database queue, and a cron-invoked Laravel scheduler. The current public domain is `https://stoxla.in/`.

The release architecture is a VPS-compatible replacement for the legacy single-folder cPanel model:

```text
/var/www/stoxla/
  current -> releases/<release-id>
  releases/
  shared/.env
  shared/storage/
  public -> current/public
  artisan -> current/artisan
```

This is `IMPLEMENTED_DIFFERENTLY` from the old cPanel topology and is an acceptable current deployment model. Secrets remain outside the packaged release and outside the public root.

| Concern | Documented target | Actual deployed state | Result |
| --- | --- | --- | --- |
| Production target | Hostinger VPS / `stoxla.in` | `stoxla-prod`, `82.112.230.20`, HTTPS responding | Match |
| Application root | `/var/www/stoxla` | Present, release/shared layout active | Match |
| Public root | `/var/www/stoxla/public` | Nginx root is this symlink to `current/public` | Match |
| Secrets | shared non-public `.env` | `/var/www/stoxla/shared/.env`, mode `0600`, owner `nitty:www-data` | Match |
| Queue | systemd worker | Enabled and active, but wrong queue routing | Defect |
| Scheduler | minute cron `schedule:run` | Installed and recent heartbeat exists | Match |

## 3. Deployed Release / Commit

The active filesystem release is:

```text
releases/20260918084844-af426a7ead54
```

Its on-disk `bootstrap/build-info.json` identifies commit `af426a7ead544a6603ee0bd9e8e267e75f946211`, CI run 65, built at `2026-09-18T08:48:24Z`. The local intended `master` at audit time is `ce2810a1fc46a94c9c6aa2f770f6fdf7c0e34029`; it has not been packaged into production.

Before the DEP-001 activation, public `/api/build-info` incorrectly reported older commit `b1327c22b376ccc73662332be3929baa4afbb903`, CI run 61. A localhost HTTPS probe returned the same older value while direct file inspection returned `af426a7`; this established a runtime stale-code condition rather than a CDN cache discrepancy. The subsequent graceful FPM reload corrected the public response to `af426a7ead544a6603ee0bd9e8e267e75f946211`.

## 4. Filesystem / Public Root

Nginx serves only `/var/www/stoxla/public` and routes PHP through `/run/php/php8.4-fpm.sock`. The active release, public root, frontend build directory, shared storage, and bootstrap cache exist with expected ownership. Effective target directory modes are `2755` for public/build assets and `0775` for writable shared storage/cache paths. Symlink mode display (`0777`) is normal and does not make the target public directory world-writable.

`public/storage` is not linked. Current source does not use the public disk directly; storage is local/private, so this is not a defect for the observed deployment.

## 5. Secrets / Environment

The real environment file was not printed. Runtime evidence confirms:

- `APP_ENV`, `APP_DEBUG`, `APP_KEY`, `APP_URL`, database, queue, cache, session, Sanctum, Kite, mail, filesystem, and logging variables are present.
- `APP_ENV=production` and `APP_DEBUG=false` are active at runtime.
- The shared `.env` is mode `0600`; the top-level `.env` is a symlink to it.
- No `.env`, `.git`, or secret-bearing directory exists under the public root.

The production environment uses `APP_URL=https://stoxla.in`, a database queue/cache/session driver, secure sessions, SameSite `lax`, and the local filesystem disk.

## 6. Laravel Runtime Configuration

`php artisan about` reports Laravel 13.12.0, PHP 8.4.25, production mode, debug off, and config/event/route/view caches enabled. The Laravel application timezone is UTC; the scheduler's configured business timezone is `Asia/Kolkata`, and the server clock is also `Asia/Kolkata`. MariaDB session/global timezone is `SYSTEM`.

The intended India-market scheduler behavior is therefore driven explicitly by the scheduler timezone rather than by the Laravel default timezone. This is consistent with the current scheduling model; AUD-010 remains responsible for market-calendar functional verification.

## 7. Database / Migration State

MariaDB 10.11.14 is reachable. `php artisan migrate:status` reports all migrations bundled in the active `af426a7` release as run, through `2026_09_12_100001_v7_stox_fundamentals_and_ml`.

The active production release predates the local `2026_09_18_000001_historical_replay_lifecycle_evidence` migration. Accordingly, the deployed database does not yet contain:

- `portfolio_recommendation_reservation_events`
- `portfolio_tos_recall_bridge_loan_returns`

This is expected for the older active release, but the next deployment containing AUD-008 Batch 1B must apply that migration before serving code that relies on those tables. No schema drift was found relative to the deployed release itself.

## 8. Storage / Permissions

Shared Laravel storage directories (`framework/cache`, `framework/sessions`, `framework/views`, `logs`, `app/private`, and `app/public`) are present and writable by `nitty:www-data` with mode `0775`. Bootstrap cache is writable. There was no permission-denied signal in current-day application logs.

## 9. Queue / Worker Runtime

`stoxla-queue.service` is enabled and active. It runs as `nitty:www-data`, has `Restart=always`, and was started at `2026-09-18 14:19:10 IST` after the active release switch.

However, its command is:

```text
php artisan queue:work --sleep=3 --tries=3 --timeout=120
```

Before the DEP-002 activation, the database connection's configured default queue was `default`, with 12 unreserved jobs on `notifications` and the oldest created/available timestamp `2026-09-13 01:30:38 IST`; the failed-job table was empty. The root-managed worker then changed to the intentional `notifications,default` command and drained the backlog to zero as 11 delivered and 1 suppressed, with no failed jobs. A running worker is accepted as runtime evidence only when its effective command covers all required named queues.

## 10. Scheduler Runtime

The `nitty` crontab contains exactly the intended minute cadence:

```text
* * * * * cd /var/www/stoxla && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The application heartbeat was `2026-09-18T12:11:02Z`, within minutes of the audit, proving scheduler invocation. No second `schedule:work` process was found, so duplicate scheduler execution was not observed.

## 11. Scheduled Job Inventory

`php artisan schedule:list` exposes the current application schedule. The following runtime-relevant jobs are registered:

| Command / job | Cadence | Runtime evidence |
| --- | --- | --- |
| Daily market, benchmark, and index sync | Daily at configured market time | Latest DatasetVersion `ds-20260918160515-20260918`; latest price date 2026-09-18 |
| Notification delivery and 48-hour reminders | Configured daily sends; reminders hourly | Registered; 12 `notifications` queue jobs are blocked by worker routing |
| Exchange calendar sync | Weekly | Registered; functional provider verification remains AUD-010 |
| Broker order and portfolio reconciliation | Every five minutes | Registered; no broker mismatch drill performed |
| Backtest, Replay, Paper processors | Every five minutes | Registered; durable runtime execution remains AUD-008 verification |
| Artifact binding usability | Hourly | Registered |
| Decision pipeline and operational alerts | Daily/hourly | Registered |
| Scheduler heartbeat / universe maintenance | Every minute | Recent persisted heartbeat |

## 12. Frontend Build / Asset Provenance

The active release contains a Vite manifest with 20 entries, and the browser module `/build/assets/app-CIyiGfHq.js` returns `200` with `application/javascript`.

The active release's on-disk build metadata and asset manifest belong to `af426a7`. Before DEP-001 activation, the PHP runtime's stale `b1327c2` build-info response made the release switch ineffective for dynamic PHP requests. The verified FPM reload now makes the public PHP build-info response match the active release, closing that backend/frontend provenance defect.

## 13. Web Server / HTTPS / Proxy

Nginx 1.24.0 is active. Its virtual host:

- uses `/var/www/stoxla/public` as document root;
- redirects HTTP and `www.stoxla.in` to canonical `https://stoxla.in/`;
- uses the Laravel front controller;
- denies hidden files other than `/.well-known`.

HTTPS is valid. The Let's Encrypt certificate covers `stoxla.in`, was issued 2026-09-12, and expires 2026-12-11. `certbot.timer` is active.

## 14. Auth / Cookie / Sanctum Runtime

The unauthenticated Sanctum CSRF endpoint responds normally and sets secure, HttpOnly session cookies with SameSite `lax`; the XSRF cookie is secure and scoped to `/`. The application exposes a JSON 404 for an unknown API route without a stack trace.

`SANCTUM_STATEFUL_DOMAINS` is present. A full authenticated Investor shell smoke was not performed because this audit intentionally did not use a production account.

Cross-origin preflight responses use `Access-Control-Allow-Origin: *` but do not allow credentials. This does not make cookie-authenticated requests cross-origin, but the broad public CORS policy should be reviewed under AUD-012/API security policy rather than treated as proof of an authenticated data exposure here.

## 15. Cache / Sessions / Redis

Cache and sessions use the database driver. Redis is inactive and is not the configured runtime dependency. The database queue uses `portfolio_jobs`; `portfolio_failed_jobs` exists and has zero entries.

## 16. Provider Configuration

Kite configuration variables are present but broker connectivity/tokens were not exercised. The current external notification configuration has one enabled, healthy Telegram channel. No real Telegram message was sent. Mail is configured with the `log` driver, so real email delivery is not currently evidenced. No active email or webhook channel configuration was observed.

Market-data runtime evidence is current: the latest DatasetVersion was created on 2026-09-18 and records 14,958,632 price bars. Provider quality and calendar correctness remain separate AUD-010/AUD-011 runtime checks.

## 17. Logs / Disk / Process Health

Nginx, PHP-FPM, MariaDB, and the queue service are active. Disk usage is 13% of 96 GB; approximately 6.5 GiB of memory is available and swap is unused. `logrotate.timer` is active.

Current-day logs contain 14 frontend error entries, 2 Laravel error/exception entries, and 1 scheduler error entry, with no current-day critical entries and no permission-denied, migration, queue-failed, Kite, Telegram, or mail failure pattern found. Raw log payloads were deliberately not copied because they may contain user/request data. These errors require normal operational triage but do not by themselves establish a deployment outage.

## 18. Backup / Rollback

The release rollback helper exists at `/home/nitty/.stoxla-deploy/stoxla-rollback-release.sh`, mode `0700`. Seven retained releases are available, and rollback atomically repoints `current`, rebuilds Laravel caches, signals `queue:restart`, resets opcache through the established localhost-only endpoint, and checks the production URL. It intentionally does not perform database down migrations.

The newest visible database backup is a 316 MB gzip SQL backup dated 2026-09-15. No application-user backup schedule or retention evidence was available; Hostinger-managed backup status was not accessible. Backup freshness and restore readiness therefore remain runtime verification, not proven by the single visible backup.

## 19. Security Exposure Probes

External non-destructive probes produced the following results:

| Probe | Result |
| --- | --- |
| `/.env`, `/.env.example`, `/.git/HEAD` | `403` |
| `composer.json`, `artisan`, logs, database path, deploy script | `200` SPA fallback with the exact root-login HTML hash, not source/file content |
| Unknown API route | JSON `404`, no debug trace |
| HTTP and `www` host | Canonical HTTPS redirect |

No public secret or source-file exposure was found in these probes.

## 20. Cross-Audit Runtime Evidence

- AUD-006 / NTF-005: the root-managed worker now consumes `notifications,default`; normal recovery processed 12 aged jobs as 11 delivered and 1 suppressed, with 11 successful delivery attempts and no failed jobs. This proves live queue consumption, provider delivery, and stale/resolved-condition suppression. Email remains the `log` driver; webhook, elapsed-time reminders, and provider-failure behavior remain runtime boundaries.
- AUD-007: shared local storage and release activation/rollback infrastructure are present; operator rollout still needs a deployed representative drill.
- AUD-008: Backtest/Replay/Paper processor commands are scheduled every five minutes; no controlled live workload was started.
- AUD-010: India-market scheduler timezone and calendar command registration are present; provider/timezone functional behavior remains open.
- AUD-011: reconciliation commands are scheduled every five minutes; no broker mismatch/recovery drill was performed.

## 21. Gap Register

| ID | Check | Repository expectation | Runtime evidence | Verdict | Severity |
| --- | --- | --- | --- | --- | --- |
| DEP-001 | Release activation reaches PHP runtime | New release executes after atomic symlink switch | Graceful FPM reload refreshed the symlink runtime: active filesystem and public PHP build-info both resolve to `af426a7ead544a6603ee0bd9e8e267e75f946211` | `IMPLEMENTED` | High |
| DEP-002 | Named notification queue consumption | External delivery work is consumed | Root-owned `stoxla-queue` unit is active with `notifications,default`; 12 aged jobs drained normally as 11 delivered and 1 suppressed, with 0 failed jobs | `IMPLEMENTED` | High |
| DEP-003 | Current source release freshness | Intended release/commit is identifiable and deployed deliberately | Runtime filesystem is `af426a7`; local master is `ce2810a1`; newest migration is not deployed | `RUNTIME_VERIFICATION_REQUIRED` | Medium |
| DEP-004 | Backup freshness / restore path | Backup schedule, retention, and recoverability are known | One 2026-09-15 local SQL backup visible; host-managed backup evidence unavailable | `RUNTIME_VERIFICATION_REQUIRED` | Medium |
| DEP-005 | Secrets and public-root isolation | Secrets remain private and public root is limited | Shared `.env` mode 0600; probes block hidden files; public-root target correct | `IMPLEMENTED` | High |
| DEP-006 | Scheduler runtime | Scheduler runs once at expected cadence | Minute cron and recent heartbeat; no duplicate worker found | `IMPLEMENTED` | High |
| DEP-007 | Core process/resource health | Web, PHP, DB, storage, TLS, and disk remain viable | Core processes active, certificate valid, storage writable, disk/memory healthy | `IMPLEMENTED` | Medium |

## 22. Remediation Groups

### Batch 1A - DEP-001 / DEP-002 secure privilege correction

The initial Batch 1 proposal incorrectly allowed the deployment identity to
install a deployment-user-writable systemd source into `/etc/systemd/system`.
Combined with `daemon-reload` and a service restart, that would have allowed a
materially broader root-code-execution boundary. It was rejected before any
sudoers rule or production unit change was installed.

The corrected model keeps `stoxla-queue.service` root-owned and administrator
installed. Routine deployment may only inspect the two named services, reload
`php8.4-fpm`, restart `stoxla-queue`, and verify the live command covers
`notifications,default`. It cannot install units, invoke `daemon-reload`, or
write root-controlled service definitions.

#### DEP-001 root cause and durable correction

The active filesystem symlink and active release build-info file resolve to
`af426a7`, while both external and localhost HTTPS PHP requests returned
`b1327c2`. PHP-FPM's master and workers predate the release switch. Even with
timestamp validation enabled, a long-running FPM process did not reliably
discard the previous release's resolved application path after `current` was
repointed. The existing one-request localhost `opcache_reset()` endpoint was
therefore insufficient for a symlink release deployment.

The committed deployment and rollback scripts now:

1. validate that the staged release build metadata matches the expected CI
   commit before activation;
2. repoint the release symlink and rebuild the existing Laravel caches;
3. gracefully reload `php8.4-fpm`;
4. signal `queue:restart` and restart the managed worker;
5. run `stoxla-runtime-health-check.sh`, which fails if public
   `/api/build-info` differs from the active release metadata, the browser
   module is unavailable, the queue service does not cover required queues, or
   the scheduler heartbeat is stale.

Rollback now follows the same FPM refresh and public identity gate. The GitHub
Actions workflow uploads the health helper and executes it again as a
post-deployment check.

#### DEP-002 worker topology and durable correction

The code inventory found one named queue: `notifications`, produced by
`NotificationDeliveryPlanner`; all other current dispatches use `default`.
The committed systemd template defines one intentional priority worker. An
administrator must review and install it as `root:root`, mode `0644`; the
deployment identity only checks its effective `ExecStart`:

```text
queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120
```

This is sufficient for the current scale and avoids adding a second worker,
Redis, or Horizon. The runtime health helper asserts that the managed worker
command includes the required queue list, so future release activation fails
instead of silently leaving a named queue unconsumed.

#### Production activation outcome

An administrator reviewed and installed the root-managed queue unit at
`/etc/systemd/system/stoxla-queue.service` as `root:root`, mode `0644`. The
unit is not writable by `nitty` and now starts:

```text
/usr/bin/php /var/www/stoxla/artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120
```

The constrained sudo boundary was installed and validated for only these
commands:

- inspect/reload `php8.4-fpm`;
- inspect/restart `stoxla-queue`.

No broad `systemctl`, `daemon-reload`, `install`, `cp`, wildcard, or writable
wrapper privilege was granted. The verified systemctl binary is
`/usr/bin/systemctl`.

The administrator then reloaded `php8.4-fpm`. Public `/api/build-info` moved
from `b1327c2` to the active filesystem release
`af426a7ead544a6603ee0bd9e8e267e75f946211`; both services are active.

After restarting the root-managed worker, the 12 aged `notifications` jobs
passed through the normal processor without manual queue or delivery changes:

```text
notifications backlog: 12 -> 0
deliveries: 11 delivered, 1 suppressed
delivery attempts: 11 succeeded
failed jobs: 0
```

The suppression is correct evidence that a stale/resolved condition was not
sent. At least one normal provider delivery succeeded; this does not claim
email runtime verification because production mail remains configured with the
`log` driver.

### Remaining operational groups

#### A - Release activation correctness (resolved)

The durable FPM reload plus public build-info gate is now proven against the
active release. Future deployments must retain that gate.

#### B - Queue routing and notification recovery (resolved)

The root-managed worker now intentionally consumes `notifications,default` and
the aged backlog completed through normal delivery/suppression handling.

#### C - Release and migration freshness

Use CI deployment once the intended release is approved. It must apply the historical replay lifecycle-evidence migration together with the corresponding code, then verify filesystem and public runtime commit identity match.

#### D - Backup assurance

Obtain the Hostinger backup schedule, retention, latest successful backup timestamp, and a documented non-production restore drill. Do not infer backup safety from the local SQL artifact.

#### E - Operational observability

Triage current non-critical log errors, retain a sanitized release/worker health check, and decide whether API CORS should remain public for unauthenticated endpoints under AUD-012 policy.

## 23. Runtime Commands / Evidence

Sanitized read-only commands used included:

```text
ssh nitty@82.112.230.20 hostname
php artisan about
php artisan migrate:status
php artisan schedule:list
php artisan queue:failed
systemctl is-active/is-enabled/show stoxla-queue
crontab -l
curl HTTPS root, build-info, API 404, CSRF headers, and exposure probes
df -h
free -h
```

Additional read-only Laravel queries checked configuration presence, scheduler heartbeat, DatasetVersion recency, queue routing/depth, migration-table state, and notification-channel aggregate status. No command printed an environment value, provider credential, cookie value, queue payload, or application log body.

## 24. Final AUD-009 Assessment

**Disposition: `RUNTIME_VERIFICATION_REQUIRED` (Medium severity, High confidence).**

The production architecture uses the accepted VPS release/shared model,
protects secrets and the public root, serves valid HTTPS, runs its scheduler,
and now proves both release-runtime activation and named queue consumption. The
FPM reload made public PHP match the active release, while the root-managed
queue worker drained the aged notification backlog through normal lifecycle
processing with no failed jobs.

The remaining bounded work is DEP-003 release/current-source freshness and an
approved newer deployment, plus DEP-004 backup schedule, retention, and restore
assurance. These are Medium runtime-verification boundaries, not open High
runtime defects.

## 25. Open Questions

- What Hostinger-managed backup cadence, retention, and restore evidence are accepted for StoX production?
- Is broad no-credentials CORS intentional for public API endpoints under the current authorization policy?
