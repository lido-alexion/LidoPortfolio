# Lido Portfolio — production debugging guide

Living reference for **how this app runs in production** and **how to debug issues** without SSH or a local replica of live data/rate limits.

**Security:** These hooks are **intentionally insecure** for pre-launch development. **Remove or disable all of them before the app is public.** See [Pre-launch cleanup](#pre-launch-cleanup).

Also update **`implementation.md`** when behavior changes; this file is the agent/human runbook for investigation.

---

## Cleanup TODO (long-term)

**When you are done debugging** (or before any public launch), complete this checklist:

- [ ] **Delete** temporary `cpanel-*.php` scripts from `public_html/portfolio/` — at minimum `cpanel-db-query.php`, `cpanel-read-logs.php`, `cpanel-api-call.php` (delete other one-off `cpanel-*.php` scripts too unless still needed)
- [ ] Set **`LIDO_AGENT_DEBUG_ENABLED=false`** in `lidoportfolio/.env`
- [ ] Run **`cpanel-config-cache.php?token=...`** so Laravel picks up the env change

Optional hardening after the above: remove `DebugAgentToken` middleware from `bootstrap/app.php`, set Backend log level back to `info` in Settings → Global. Full checklist: [Pre-launch cleanup](#pre-launch-cleanup).

---

## How production works (our setup)

| Aspect | Reality |
|--------|---------|
| **Hosting** | GoDaddy shared cPanel — **no SSH**, no `php artisan` from terminal |
| **Deploy** | SCP upload from PC (`deploy/prepare-upload.ps1` → `deploy/staging/`) |
| **App URL** | `https://www.lidoalexion.com/portfolio/` (SPA) |
| **Laravel root** | `/home/USER/public_html/lidoportfolio/` (not web-accessible) |
| **Web entry** | `/home/USER/public_html/portfolio/index.php` → Laravel |
| **API base** | `https://www.lidoalexion.com/portfolio/api/...` |
| **Audience** | Private — only you; we **build and test on production** on purpose |
| **Why** | Live stock APIs, provider rate limits, cPanel constraints differ from local |

**Cron:** cPanel must run `php artisan schedule:run` **every minute** against `lidoportfolio/`. One-off maintenance uses token-guarded `deploy/cpanel-*.php` in `public_html/portfolio/`.

---

## Three layers to debug

| Layer | Symptoms | Tools |
|-------|----------|--------|
| **Database** | Wrong counts, stale settings JSON, cursor not advancing | `cpanel-db-query.php`, Sync Logs UI |
| **PHP / Laravel** | Scheduler not firing, API 500, wrong config cache | `cpanel-read-logs.php`, `cpanel-api-call.php`, `cpanel-schedule-diagnostic.php` |
| **React SPA** | UI stale vs API truth | Browser devtools, `cpanel-api-call.php` to compare API JSON |

**Rule:** When UI and Sync Logs disagree, **trust `portfolio_sync_runs` and API JSON** first; settings keys like `universe_price_sync_last_run_json` can lag (healed automatically when newer code is deployed).

---

## Shared debug token

All temporary hooks use the same pattern as existing cPanel scripts:

- **Token in file:** `const SETUP_TOKEN = 'Lido';` (change before upload if desired)
- **Laravel API bypass:** header `X-Lido-Debug-Token: Lido` or query `?debug_token=Lido`
- **Env (Laravel):** `LIDO_AGENT_DEBUG_ENABLED=true`, `LIDO_AGENT_DEBUG_TOKEN=Lido` in `lidoportfolio/.env` — run `cpanel-config-cache.php` after changing

---

## 1. Read-only database queries

**File:** `deploy/cpanel-db-query.php` → upload to `public_html/portfolio/cpanel-db-query.php` (self-contained SQL allowlist — no extra Laravel class upload required)

**POST** JSON:

```json
{
  "token": "Lido",
  "query": "SELECT setting_key, setting_value, updated_at FROM portfolio_settings WHERE setting_key LIKE 'universe%'",
  "limit": 100
}
```

**Allowed:** single statement `SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN` only (enforced by `App\Support\ReadOnlySqlGuard`).

**Useful queries:**

```sql
-- Universe sync cursor + last run JSON
SELECT setting_key, setting_value, updated_at
FROM portfolio_settings
WHERE setting_key IN (
  'universe_price_sync_cursor_stock_id',
  'universe_price_sync_last_run_json',
  'universe_price_sync_in_progress',
  'schedule_run_heartbeat_at',
  'universe_maintenance_probe_json'
);

-- Recent universe sync runs
SELECT id, job_name, status, started_at, finished_at, stocks_processed, failures, summary
FROM portfolio_sync_runs
WHERE job_name = 'universe-price-sync'
ORDER BY started_at DESC
LIMIT 20;

-- Scheduler heartbeat age
SELECT setting_value AS heartbeat_at
FROM portfolio_settings
WHERE setting_key = 'schedule_run_heartbeat_at';
```

**Response:** JSON with `rows`, `row_count`, `duration_ms`.

---

## 2. REST API without browser login

### Option A — Direct API (middleware)

Any `/api/*` request with debug token logs in as **first admin user**:

```http
GET /portfolio/api/universe-price-sync/status?debug_token=Lido
X-Lido-Debug-Token: Lido
Accept: application/json
```

Works from curl, Postman, or agent HTTP tools. Optional portfolio scope:

```http
X-Profile-Id: 1
```

Controlled by `App\Http\Middleware\DebugAgentToken` + `config/portfolio.php` → `debug_agent`.

### Option B — cPanel API proxy (no cookies)

**File:** `deploy/cpanel-api-call.php` → `public_html/portfolio/cpanel-api-call.php`

Examples:

```
GET  .../cpanel-api-call.php?token=Lido&path=api/universe-price-sync/status
GET  .../cpanel-api-call.php?token=Lido&path=api/sync-logs/runs&method=GET
POST .../cpanel-api-call.php?token=Lido&path=api/universe-price-sync/run&method=POST&body={"mode":"daily","scope":"all_equities"}
```

Returns JSON: `status`, `body` (parsed), `request_id`.

---

## 3. Read PHP log files

**File:** `deploy/cpanel-read-logs.php` → `public_html/portfolio/cpanel-read-logs.php`

| Query | Purpose |
|-------|---------|
| `?token=Lido` | List log files under `storage/logs/` |
| `?token=Lido&file=scheduler&tail=300` | Last 300 lines of latest `scheduler-*.log` |
| `?token=Lido&file=laravel&tail=200` | Latest `laravel-*.log` |
| `?token=Lido&file=scheduler&grep=heartbeat&tail=500` | Filter lines containing `heartbeat` |

**Log channels** (see `config/logging.php`):

| File pattern | Contents |
|--------------|----------|
| `scheduler-YYYY-MM-DD.log` | Cron, universe maintenance, gap fill, heartbeats |
| `laravel-YYYY-MM-DD.log` | General app / API errors |
| `provider-YYYY-MM-DD.log` | NSE/Yahoo/Alpha Vantage fetches |
| `frontend-YYYY-MM-DD.log` | Browser-reported errors |

**Settings → Global → Backend log level:** `debug` enables verbose scheduler lines (minute heartbeats). Set back to `info` after investigating.

**Retention:** `LOG_DAILY_DAYS` (default 2) — older files are deleted.

---

## Existing diagnostics (keep using)

| Script | Use |
|--------|-----|
| `cpanel-schedule-diagnostic.php` | Timezone, due events, mutex, sync run buckets |
| `cpanel-run-universe-maintenance.php` | Force one maintenance batch (`&apply=1`) |
| `cpanel-config-cache.php` | After `.env` / config upload |
| `cpanel-migrate.php` | Migrations |
| `cpanel-sync-logs-probe.php` | API routing / route cache for sync logs |

Full list: `deploy/README.md`.

---

## Example: universe sync “not running”

Use this checklist (Jul 2026 incident pattern):

1. **Sync Logs UI** — Are `universe-price-sync` rows appearing tonight 19:00–23:45 IST?
2. **If yes but UI card stale** — `cpanel-db-query.php` compare `portfolio_sync_runs` vs `universe_price_sync_last_run_json`.
3. **If no runs tonight** — `cpanel-read-logs.php?file=scheduler&grep=heartbeat` — heartbeats every minute?
   - **No heartbeats** → cPanel cron not running `schedule:run` every minute.
   - **Heartbeats, `would_skip_reason=not_due_window_or_interval` / `outside_maintenance_hours` / `not_due_interval`** → outside window or wrong interval (check deployed `routes/console.php` + config cache).
   - **`would_skip_reason=weekend_skip`** → Sat/Sun and the prior session’s **last** maintenance batch succeeded (expected; set `UNIVERSE_MAINTENANCE_SKIP_WEEKENDS=false` only if you want every weekend).
   - **Daily/benchmark/index sync on Sat/Sun** → scheduler skips non-session days; manual `portfolio:daily-sync` still works.
   - **`mutex_held` / `sync_in_progress`** → `cpanel-schedule-diagnostic.php?clear_mutex=1` or `clear_in_progress=1` (also abandons orphan `running` sync runs).
4. **API truth** — `cpanel-api-call.php?path=api/universe-price-sync/status` — `last_run`, `maintenance`, `config.batch_size`.
5. **Processed=75 every 15 min** → old config still on server; upload `config/portfolio.php` + `routes/console.php`, run `cpanel-config-cache.php`.

**Scheduler debug events** (when `backend_log_level=debug`):

| `event` in log | Meaning |
|----------------|---------|
| `schedule_heartbeat` | `schedule:run` invoked; includes `would_skip_reason` |
| `maintenance_probe` | Maintenance slot evaluation |
| `universe_maintenance_start` / `finish` | Maintenance command ran |
| `universe_sync_start` | Batch started |
| `universe_last_run_healed` | Settings JSON updated from sync log |

---

## Agent workflow (recommended order)

1. Reproduce / read user report (UI + Sync Logs screenshot).
2. **`cpanel-api-call.php`** — GET the relevant status API.
3. **`cpanel-db-query.php`** — SELECT settings + sync_runs for that feature.
4. **`cpanel-read-logs.php`** — scheduler tail + grep feature keywords.
5. **`cpanel-schedule-diagnostic.php`** — if scheduler-related.
6. Fix code locally → `prepare-upload.ps1` → give user upload table → verify with same hooks.

Do **not** guess from code alone when production state is available.

---

## Pre-launch cleanup

Same as [Cleanup TODO (long-term)](#cleanup-todo-long-term), plus before any public users:

1. Delete from `public_html/portfolio/`:
   - `cpanel-db-query.php`
   - `cpanel-read-logs.php`
   - `cpanel-api-call.php`
   - All other `cpanel-*.php` except any you still need
2. Set in `lidoportfolio/.env`:
   - `LIDO_AGENT_DEBUG_ENABLED=false`
3. Run `cpanel-config-cache.php`
4. Set **Backend log level** to `info` in Settings → Global
5. Remove `DebugAgentToken` middleware registration from `bootstrap/app.php` (optional hardening)

---

## File map (repo)

| Path | Role |
|------|------|
| `debugging.md` | This guide |
| `implementation.md` | Architecture + feature behavior |
| `deploy/cpanel-db-query.php` | Read-only SQL |
| `deploy/cpanel-read-logs.php` | Log tail/grep |
| `deploy/cpanel-api-call.php` | API proxy with debug token |
| `app/app/Http/Middleware/DebugAgentToken.php` | API auth bypass |
| `app/app/Support/ReadOnlySqlGuard.php` | SQL allowlist |
