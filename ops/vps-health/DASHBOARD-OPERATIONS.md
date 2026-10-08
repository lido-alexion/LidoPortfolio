# StoX VPS health dashboard and operations

This guide describes the VPS health dashboard, its sample and alert flows, initial server setup, and the commands used to inspect or restart the monitor. The dashboard and monitor are read-only: they collect and report evidence; they do not restart or reconfigure Nginx, PHP-FPM, MariaDB, or other services.

For monitor implementation details, environment variable reference, privacy limits, and diagnostic snapshot behavior, see [the VPS health monitor README](README.md).

## What runs

- The application release contains `/var/www/stoxla/current/scripts/vps-health/monitor.py`.
- A systemd **timer** starts the one-shot `vps-health.service` once per minute.
- The monitor collects aggregate host, PHP-FPM, and Nginx metrics, runs the StoX Artisan commands to record a sample and send notifications, then exits.
- Aggregate samples are stored in the StoX database table `portfolio_vps_health_samples`. The admin dashboard API reads these records; it does not run checks itself.
- Detailed diagnostic JSON snapshots and monitor state stay on the VPS under `/var/lib/vps-health`; they are not returned by the dashboard API.

Application code under `app/` is deployed by the normal GitHub production workflow after merge. The root-owned systemd units, EnvironmentFile, PHP-FPM pool settings, and systemd drop-in are **not** part of the application release and must be managed on the VPS.

## Dashboard access and behavior

- **Page:** `/settings/vps-health` in StoX.
- **Access:** sign in as a StoX admin. The route and API require admin access; investors do not see or access this page.
- **API:** `GET /api/v1/admin/vps-health`. The `hours` query parameter accepts `1`, `6`, `24`, or `72`; the default is `24`. For example: `GET /api/v1/admin/vps-health?hours=72`.
- **Ranges:** 1 hour, 6 hours, 24 hours, and 3 days.
- **Refresh:** the page refreshes every minute. A sample appears after the first successful service run and database write.
- **Display:** timestamps use `DD-Mmm-YYYY hh:mm:ss AM/PM`; sample age is shown in rounded words, such as “24 seconds ago.”
- **Database retention:** dashboard samples older than 96 hours (4 days) are deleted during the hourly pruning pass. If the timer or database write is failing, pruning will not run.
- **Diagnostic retention:** local detailed snapshots use a separate 14-day retention in `/var/lib/vps-health`. The two retention periods serve different purposes.

The overall **Healthy/Critical** badge comes from the monitor’s alert result. Metric card colors are quick guidance for the latest sample and can differ from that badge, especially when a monitor alert requires repeated checks.

## Metric colors shown in the dashboard

| Metric | Green: normal | Amber: attention | Red: action needed |
| --- | --- | --- | --- |
| Load per core | Below 1.0 | 1.0 to below 2.0 | 2.0 or higher |
| Available RAM | 20% or more | 10% to below 20% | Below 10% |
| Root disk used | Below 80% | 80% to below 90% | 90% or more |
| Swap used | Below 50% | 50% through 80% | Above 80% |
| FPM workers | No queue and below 90% of configured capacity | No queue and at least 90% of capacity | Any queued request |
| Nginx 5xx errors | 0 in the last 5 minutes | 1–4 | 5 or more |

Nginx 5xx counts combine 502, 503, and 504 responses. Nginx 499 client closes are shown separately. These UI thresholds do not change alert delivery. The monitor’s server-side critical defaults are documented in [README.md](README.md); for example, a high load or FPM saturation alert requires two consecutive checks.

## One-time VPS setup

The commands below match the StoX production paths currently in use. Confirm the installed PHP-FPM version, pool file, application release path, storage path, and Nginx log path before applying them to another host.

### 1. Configure the private PHP-FPM status listener

This is optional, but without it the dashboard has no FPM worker or listen-queue metrics. The listener must stay on loopback and must not be exposed through public Nginx.

First make a backup, then edit the pool file. Do not add duplicate directives if they already exist:

```sh
sudo cp -a /etc/php/8.4/fpm/pool.d/www.conf \
  "/etc/php/8.4/fpm/pool.d/www.conf.backup.$(date -u +%Y%m%dT%H%M%SZ)"
sudoedit /etc/php/8.4/fpm/pool.d/www.conf
```

Add or verify these directives in the pool:

```ini
pm.status_path = /fpm-status
pm.status_listen = 127.0.0.1:9001
```

Validate before reloading:

```sh
sudo /usr/sbin/php-fpm8.4 -t
sudo systemctl reload php8.4-fpm
```

If the FPM test fails, do not reload. Correct the pool configuration or restore the backup, test again, and then reload.

### 2. Configure monitor-specific settings

The monitor reads its settings from `/etc/vps-health/monitor.env` through systemd. Use the actual Nginx access log path configured on this host; the current StoX path is `/var/log/nginx/stoxla.access.log`.

```sh
sudo install -d -o root -g root -m 0750 /etc/vps-health
sudo tee /etc/vps-health/monitor.env >/dev/null <<'EOF'
VPS_HEALTH_ACCESS_LOG=/var/log/nginx/stoxla.access.log
VPS_HEALTH_FPM_STATUS_ENABLED=true
VPS_HEALTH_FPM_STATUS_HOST=127.0.0.1
VPS_HEALTH_FPM_STATUS_PORT=9001
VPS_HEALTH_FPM_MAX_CHILDREN=5
EOF
sudo chown root:root /etc/vps-health/monitor.env
sudo chmod 0640 /etc/vps-health/monitor.env
```

Set `VPS_HEALTH_FPM_MAX_CHILDREN` to the pool’s real `pm.max_children` value. Add other non-secret `VPS_HEALTH_*` overrides only as needed; the defaults and supported keys are in [README.md](README.md).

Do not put mail or Telegram credentials or admin recipients in this file. StoX reads admin email recipients and enabled Telegram recipients from its database. Mail transport and sender settings come from the existing Laravel `.env`/cached configuration. The monitor stores a short-lived encrypted recipient cache under `/var/lib/vps-health` for database outages; it uses StoX’s `APP_KEY` to encrypt that cache.

### 3. Install the systemd units and storage permission

Use the **approved application release commit SHA** below; do not use a floating `master` URL. These templates live at the repository root under `ops/`, outside the application release archive.

```sh
release_sha=REPLACE_WITH_APPROVED_COMMIT_SHA
curl -fsSL "https://raw.githubusercontent.com/lido-alexion/LidoPortfolio/${release_sha}/ops/vps-health/systemd/vps-health.service" -o /tmp/vps-health.service
curl -fsSL "https://raw.githubusercontent.com/lido-alexion/LidoPortfolio/${release_sha}/ops/vps-health/systemd/vps-health.timer" -o /tmp/vps-health.timer

sudo install -o root -g root -m 0644 /tmp/vps-health.service /etc/systemd/system/vps-health.service
sudo install -o root -g root -m 0644 /tmp/vps-health.timer /etc/systemd/system/vps-health.timer
```

On the current StoX VPS, `ProtectSystem=strict` also requires an explicit writable path for Laravel’s shared storage. This is needed for the Artisan sample/notification commands; without it, checks can report success while `sample_recorded` is false.

```sh
sudo install -d -o root -g root -m 0755 /etc/systemd/system/vps-health.service.d
sudo tee /etc/systemd/system/vps-health.service.d/storage.conf >/dev/null <<'EOF'
[Service]
ReadWritePaths=/var/www/stoxla/shared/storage
EOF
sudo systemd-analyze verify /etc/systemd/system/vps-health.service /etc/systemd/system/vps-health.timer
sudo systemctl daemon-reload
```

The service runs as `nitty:adm`, has a systemd-managed `/var/lib/vps-health` state directory, and has restricted filesystem access. Verify that this account can read the configured Nginx logs. Do not broaden permissions on unrelated files.

### 4. Enable and run the monitor

```sh
sudo systemctl enable --now vps-health.timer
sudo systemctl start vps-health.service
sudo systemctl is-active vps-health.timer
sudo systemctl status vps-health.service --no-pager --full
sudo journalctl -u vps-health.service -n 20 --no-pager -o cat
```

A successful run prints JSON with `"sample_recorded": true`. The timer should be active and the service should exit successfully after each check. Refresh the dashboard after a successful sample.

## Routine operations

The monitor service is a **oneshot**. The timer is the recurring scheduler; there is no long-running health-monitor process to keep alive.

| Task | Command |
| --- | --- |
| Check timer state | `sudo systemctl status vps-health.timer --no-pager --full` |
| See next/previous run | `sudo systemctl list-timers vps-health.timer --all` |
| Trigger one check now | `sudo systemctl start vps-health.service` |
| Read recent check output | `sudo journalctl -u vps-health.service -n 50 --no-pager -o cat` |
| Follow service logs | `sudo journalctl -u vps-health.service -f` |
| Restart the recurring schedule | `sudo systemctl restart vps-health.timer` |
| Stop and disable recurring checks | `sudo systemctl disable --now vps-health.timer` |
| Re-enable recurring checks | `sudo systemctl enable --now vps-health.timer` |

After editing `/etc/vps-health/monitor.env`, the next service start reads the new values; no daemon reload is needed. Trigger a check to verify the change. After editing a unit or drop-in, validate and reload systemd:

```sh
sudo systemd-analyze verify /etc/systemd/system/vps-health.service /etc/systemd/system/vps-health.timer
sudo systemctl daemon-reload
sudo systemctl restart vps-health.timer
sudo systemctl start vps-health.service
sudo journalctl -u vps-health.service -n 20 --no-pager -o cat
```

Only reload PHP-FPM after changing its pool configuration and passing the FPM config test. The monitor itself never restarts services. A code change to `app/` should go through the normal PR and production deployment workflow; do not copy a new monitor script onto the VPS manually.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| No new dashboard samples | Confirm the timer is active; inspect the service journal; check for `sample_recorded: false`; verify the systemd storage drop-in and that `/var/www/stoxla/shared/storage` resolves to the deployed Laravel storage directory. |
| FPM cards show no data | Check `cgi-fcgi` is installed, the pool has the loopback status directives, port 9001 is listening locally, and the EnvironmentFile enables the FPM status check. |
| Nginx chart is empty | Check `VPS_HEALTH_ACCESS_LOG` points to the active access log, uses standard combined timestamps, and is readable by `nitty:adm`. |
| Timer is inactive | Run `sudo systemctl enable --now vps-health.timer`, then inspect `systemctl status` and the journal. |
| Alerts fail | Check the journal for the delivery failure message, StoX database access, admin email addresses and Telegram settings, Laravel `MAIL_*` settings, and the presence of a valid encrypted cache. The monitor does not print recipient addresses or credentials. |
| FPM reload fails | Do not repeatedly reload. Run `sudo /usr/sbin/php-fpm8.4 -t`, inspect the PHP-FPM journal, and restore the timestamped pool backup if needed. |

A direct call to `monitor.py` does not automatically load `/etc/vps-health/monitor.env`; it uses defaults plus variables exported in that shell. For a production-configured check, prefer `sudo systemctl start vps-health.service` and inspect the journal. Detailed manual diagnostics and their privacy limits are documented in [README.md](README.md).

## Removal

To stop the scheduled monitor, run `sudo systemctl disable --now vps-health.timer`. Remove the installed service and timer files and the service drop-in only when you intend to retire the feature, then run `sudo systemctl daemon-reload`. Preserve or retire `/etc/vps-health` configuration and `/var/lib/vps-health` state deliberately. Removing the monitor does not change Nginx, PHP-FPM, MariaDB, StoX deployment configuration, or credentials stored elsewhere.
