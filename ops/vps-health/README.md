# VPS health monitor

Python 3 standard-library monitor for low-cost, read-only checks. The timer records a sample every minute; configured email receives a rolling 30-minute digest, and Telegram/email receive critical and recovery notices. It does not restart or reload services. Alerts describe evidence of a possible bottleneck, not proof of its cause.

## Paths and configuration

The runtime program is `app/scripts/vps-health/monitor.py` in this repository and is packaged as `/var/www/stoxla/current/scripts/vps-health/monitor.py`. Operator docs and systemd templates are under the repository root `ops/vps-health`; that root directory is not in the application release archive. A merge to `master` that changes the packaged `app/` content invokes the normal production deploy workflow. It does not install or change the root-owned timer, EnvironmentFile, or PHP-FPM pool configuration. Those one-time setup tasks remain administrator actions. State and timestamped diagnostic snapshots default to systemd-managed `/var/lib/vps-health` (`0750`, files `0640`; snapshots older than 14 days are removed). Override the state path with `VPS_HEALTH_STATE_DIR` for manual runs. Other configurable paths default to `/var/log/nginx/access.log`, `/var/log/nginx/error.log`, `/var/log/php8.4-fpm-slow.log`; access/error/slow byte caps default to 1 MiB/256 KiB/16 KiB. Reads and subprocesses are bounded. Paths omit URL query strings.

EnvironmentFile variables use the `VPS_HEALTH_` prefix. Notification recipients come from StoX: every admin (`portfolio_users.is_admin`) with a valid account email receives email; Telegram alerts go to each admin with an enabled, verified Telegram channel in StoX notification settings. The existing unambiguous legacy Telegram settings are migrated through StoX's migration service. Mail transport and sender settings come from the application's existing Laravel `.env`/cached config (`MAIL_*`); no duplicate SMTP settings are needed in `monitor.env`. Telegram receives urgent alerts and recovery notices; email receives those plus the 30-minute digest. A successful database lookup refreshes an encrypted Laravel cache at `/var/lib/vps-health/notification-targets.enc` (mode `0600`); if the database is unavailable, the monitor uses a cache no older than seven days. The cache is stored alongside monitor state, encrypted with StoX's `APP_KEY`, and contains admin addresses plus Telegram destinations. If neither the database nor a valid cache is available, delivery fails and the service reports that no admin channels were reached. The Python monitor passes alert text to Artisan over stdin and never reads or receives mail/Telegram credentials.

Put only monitor-specific values in the external file and do not include it in backups or logs. Example settings:

```ini
VPS_HEALTH_ACCESS_LOG=/var/log/nginx/access.log
VPS_HEALTH_ERROR_LOG=/var/log/nginx/error.log
VPS_HEALTH_SLOW_LOG=/var/log/php8.4-fpm-slow.log
VPS_HEALTH_FPM_STATUS_ENABLED=true
VPS_HEALTH_FPM_STATUS_HOST=127.0.0.1
VPS_HEALTH_FPM_STATUS_PORT=9001
VPS_HEALTH_FPM_MAX_CHILDREN=5
VPS_HEALTH_NGINX_499_CRITICAL=5
VPS_HEALTH_DISK_CRITICAL_PERCENT=90
VPS_HEALTH_RAM_CRITICAL_PERCENT=10
VPS_HEALTH_SWAP_CRITICAL_PERCENT=80
VPS_HEALTH_LOAD_PER_CORE_CRITICAL=2
VPS_HEALTH_ALERT_COOLDOWN_MINUTES=60
VPS_HEALTH_HEARTBEAT_URL=
```

The monitor reports whether database-backed email and Telegram recipients were reached, plus whether the encrypted cache supplied the target list. Credentials and recipient addresses are never included in its status output. Configure the external heartbeat URL with a third-party uptime service's check-in URL. The URL is called only after a check completes; choose a service that alerts when expected check-ins are missed. A heartbeat URL is itself a secret and belongs only in the external file.

Threshold defaults: FPM listen queue >0 immediately; FPM active workers equal configured `FPM_MAX_CHILDREN` for two checks; at least 3 Nginx 502/504 responses or the configured `VPS_HEALTH_NGINX_499_CRITICAL` count (default 5) of Nginx 499 client-closed responses in the bounded rolling five-minute access-log sample (499 is reported and alerted separately); root disk >=90%; available RAM <10%; swap >80%; normalized one-minute load >=2 for two checks. The documented FPM default is 5 for the incident pool; verify it matches the actual current pool config and set `VPS_HEALTH_FPM_MAX_CHILDREN` accordingly. If no max is configured, saturation is not inferred from historical max-active metrics. Nginx 499/502/503/504 counts are reported. Recent standard combined access-log timestamps are used for the five-minute window; the 30-minute digest sums per-minute counts.

## PHP-FPM status endpoint

This is optional and requires the already-installed `cgi-fcgi`. An administrator must configure `pm.status_path` and `pm.status_listen = 127.0.0.1:9001` in the relevant PHP-FPM pool, ensure the status endpoint has no public Nginx exposure, validate configuration with the installed FPM binary's test option, and then perform a controlled FPM reload. This repository tool does not make that configuration change, validate production config, or reload anything. Without it, FPM metrics are reported empty and FPM thresholds cannot fire.

## Install / one-time sudo actions

Review and merge the change to `master`; the normal GitHub production workflow packages the contents of `app/` and deploys the release. There is no manual deployment step. Root-owned timer/config setup and PHP-FPM status configuration remain administrator actions. After the normal release makes the reviewed program available at `/var/www/stoxla/current/scripts/vps-health/monitor.py`, the administrator performs these one-time actions:

1. Create `/etc/vps-health` and `/var/lib/vps-health` as needed, and create `/etc/vps-health/monitor.env` with only intended configuration. The script remains in the application release at `/var/www/stoxla/current/scripts/vps-health/monitor.py`; do not copy it under root `ops/`. Set the config file owner to `root:root`, mode `0640`, and config directory mode `0750`; do not put credentials in git or shell command history.
2. Install the two supplied unit templates as `/etc/systemd/system/vps-health.service` and `/etc/systemd/system/vps-health.timer`; inspect their contents and paths.
3. Run `systemctl daemon-reload`, then `systemctl enable --now vps-health.timer`. For initial readiness, run `systemctl start vps-health.service` and inspect `systemctl status vps-health.timer` / `journalctl -u vps-health.service`. No sudo rule is needed; do not grant broad sudo.

The unit runs as `nitty:adm` so it can read Nginx logs. It has a systemd-owned `StateDirectory`, restrictive filesystem/kernel/process hardening, read-only log access, and no capabilities. Confirm that local policy grants `nitty` the intended read-only access to the configured logs. Do not widen permissions on unrelated files.

The configured PHP slow log is currently `root:root 0600`, so this service cannot read it by default. It will report an empty excerpt. If administrators want slow-log evidence, they must deliberately grant read-only `adm` access in a way that persists through log rotation (for example, an appropriate logrotate create/group policy and verified ACL); do not change the current file permissions as part of this change.

## Diagnosis and operations

Manual commands (same script, run as `nitty` where access allows):

```sh
python3 /var/www/stoxla/current/scripts/vps-health/monitor.py check
python3 /var/www/stoxla/current/scripts/vps-health/monitor.py diagnose
python3 /var/www/stoxla/current/scripts/vps-health/monitor.py summary
```

`diagnose` saves and prints a bounded JSON snapshot with resource values, FPM status, top five sanitized Nginx paths/error summaries, readable slow-log excerpt, a short process list, and optional MariaDB aggregate status counters. MariaDB collection invokes only the read-only `mariadb-admin status`/`mysqladmin status` command, may use the service user’s existing private client option file, never passes credentials in arguments or notification environment variables, and retains only an allowlist of numeric counters. It does not collect process lists, SQL text, or raw client output; if authentication is unavailable the MariaDB section is empty. IP addresses are masked in error summaries; query strings are not retained. Treat snapshots as operational data. First inspect the relevant metric and timestamp, FPM queue/worker capacity, Nginx/PHP errors, disk/RAM pressure, and recent changes; diagnosis alone does not establish causality. When present, `vmstat`, `iostat`, and `pidstat` run only in the diagnostic snapshot, with short timeouts and bounded output; the recurring health check does not invoke them. They are optional and are never installed by this change.

No package installation is required. Threshold changes belong in the external EnvironmentFile. Before relying on alerts, confirm Laravel's existing `MAIL_*` settings, that StoX admin accounts have the correct email addresses and Telegram channels, and that the service can read the application's runtime config and write `/var/lib/vps-health`.

## Readiness and removal

Before relying on the monitor, confirm config ownership/mode, expected log readability as `nitty:adm`, local JSON output has no secrets, Telegram and email delivery (if configured), heartbeat missed-check alert (if configured), FPM status availability (if enabled), timer cadence, state directory permissions, and snapshot pruning. Review a manual diagnostic and a 30-minute summary. A digest is emitted every 30 minutes after a successful scheduled check.

Safe removal: as administrator run `systemctl disable --now vps-health.timer`, then remove the two installed unit files and run `systemctl daemon-reload`. Remove `/etc/vps-health` only after preserving/retiring needed config, then optionally remove `/var/lib/vps-health` snapshots/state after retention review. This does not remove or alter Nginx, PHP-FPM, databases, services, credentials elsewhere, or application deployment configuration.
