# Deployment Validation Plan

Use this checklist **before** and **after** deploying Lido Portfolio to production (cPanel / shared hosting). Each item includes exact commands, expected results, and pass/fail criteria.

Related docs:

- **Deploy steps (lidoalexion.com/portfolio):** `deploy/DEPLOY.md`
- Generic cPanel: `DEPLOYMENT_CPANEL.md`
- API reference: `app/API_DOCUMENTATION.md`
- Implementation notes: `implementation.md`

---

## 1. Pre-deployment (local / staging)

Run these on your machine (or a staging server that mirrors production PHP/MySQL).

### 1.1 PHP runtime requirements

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
php -v
php -m
php --ini
```

**Verify modules are loaded**

```powershell
php -m | Select-String -Pattern "mbstring|pdo_mysql|openssl|curl|json|tokenizer|xml|ctype|fileinfo"
```

| Module | Required | Pass criteria |
|--------|----------|---------------|
| `mbstring` | Yes | Listed in `php -m` |
| `pdo_mysql` | Yes | Listed in `php -m` |
| `openssl`, `curl`, `json` | Yes | Listed in `php -m` |
| `fileinfo` | Recommended | Listed (needed for `vendor:publish`; Sanctum migration already in repo) |
| `pdo_sqlite` | Dev/tests only | Not required on production |

**SSL for outbound HTTP (Telegram / providers)**

```powershell
php -r "echo file_get_contents('https://api.telegram.org');"
```

| Pass | Fail |
|------|------|
| No SSL/certificate error | `cURL error 60` or certificate verify failure → fix `curl.cainfo` / `openssl.cafile` in active `php.ini` |

---

### 1.2 Environment and secrets (pre-fill, do not commit)

**Steps**

1. Copy `app/.env.mysql.template` → `app/.env` (if not already).
2. Set production-oriented values locally first to validate boot:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

3. Confirm `app/config/DBConfig.php` matches DB host/name/user/pass (if you use that path).
4. **Rotate** Telegram bot token before production; set only in Settings UI or DB, not in git.
5. Production session auth (required for SPA login):

```env
APP_URL=https://your-domain.example
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=your-domain.example,www.your-domain.example
```

6. Logging (optional defaults):

```env
LOG_CHANNEL=daily
LOG_DAILY_DAYS=2
```

**Pass criteria**

```powershell
cd D:\Projects\LidoPortfolio\app
php artisan config:show app.env
php artisan config:show app.debug
```

| Setting | Expected |
|---------|----------|
| `app.env` | `production` (on prod host) |
| `app.debug` | `false` (on prod host) |

---

### 1.3 Database schema

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
php artisan migrate:status
php artisan migrate --force
```

**Pass criteria**

- All migrations show **Ran**.
- Tables exist: `portfolio_users`, `portfolio_stocks`, `portfolio_transactions`, `portfolio_holdings`, `portfolio_stock_prices`, `portfolio_stock_metrics`, `portfolio_portfolio_snapshots`, `portfolio_alerts`, `portfolio_settings`, `portfolio_jobs`, `portfolio_failed_jobs`, `sessions`, `personal_access_tokens` (legacy; login does not issue API tokens).

**Optional DB check (MySQL client)**

```sql
SHOW TABLES LIKE 'portfolio_%';
```

---

### 1.4 Automated test gate

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
php artisan test
```

**Pass criteria**

- Output: **0 failures**, **0 errors** (suite is 45+ tests as of May 2026).
- Optional: `npm run test:js` for frontend unit tests.

---

### 1.5 Frontend production build

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
npm install
npx vite build
```

**Pass criteria**

- Exit code `0`.
- Files exist: `public/build/manifest.json`, `public/build/assets/*.js`, `public/build/assets/*.css`.

---

### 1.6 API smoke (local server)

**Steps**

1. Start server:

```powershell
cd D:\Projects\LidoPortfolio\app
php artisan serve --host=127.0.0.1 --port=8001
```

2. In another terminal:

```powershell
cd D:\Projects\LidoPortfolio\app
PowerShell -ExecutionPolicy Bypass -File tests\Feature\api_smoke.ps1
```

**Pass criteria**

- Line: `API smoke PASS for user: smoke...@example.com`
- No thrown errors for register, login, stock, transaction, holdings, dashboard, analytics, alerts.

---

### 1.7 Scheduler / settings integration (local)

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
PowerShell -ExecutionPolicy Bypass -File tests\Feature\scheduler_live_verify.ps1
```

**Pass criteria**

- Line: `SCHEDULER LIVE VERIFY PASS`
- `Before schedule` ≠ `After schedule` after API cron change.
- `Restored schedule` matches `Before schedule`.

**Manual spot-check**

```powershell
php artisan schedule:list
```

- Rows contain `portfolio:daily-sync` and `stocks:sync` (weekly master import).
- `portfolio:daily-sync` cron expression changes after updating Settings (`cron_time`, `cron_timezone`) and re-running `schedule:list`.

---

### 1.8 Provider fallback (unit level)

**Steps**

```powershell
cd D:\Projects\LidoPortfolio\app
php artisan test --filter=PriceFetchServiceTest
```

**Pass criteria**

- `test_fallback_uses_secondary_provider_when_primary_fails` passes.

---

### 1.9 Manual daily sync (local API)

**Steps**

Use the UI (**sync** from authenticated session) or SSH:

```bash
cd app
php artisan portfolio:daily-sync
```

**Pass criteria**

- Command completes without exception.
- JSON message / logs indicate sync finished.
- Check `storage/logs/scheduler-YYYY-MM-DD.log` (not DB `portfolio_system_logs` — logging is file-based).

---

### 1.10 Telegram (local, optional but recommended)

**Steps**

1. Settings UI or `PUT /api/settings` with `telegram_bot_token`, `telegram_chat_id`, `notifications_enabled=true`.
2. Trigger a controlled failure or use a test command if you add one; minimum check:

```powershell
php artisan tinker --execute="app(\App\Services\TelegramNotificationService::class)->sendMessage('Lido Portfolio deploy test');"
```

**Pass criteria**

- Message appears in Telegram chat.
- No SSL error in logs.

---

### 1.11 Pre-deploy packaging checklist

| Item | Action | Pass |
|------|--------|------|
| `.env` | Production values on server only, not in git | Not in repo / `.gitignore` |
| `vendor/` | `composer install --no-dev --optimize-autoloader` on server OR upload with deploy | Autoload works |
| `public/build/` | Built via Vite | manifest present |
| `storage/`, `bootstrap/cache/` | Writable on server | 775 or host default |
| Document root | Points to `app/public` | `/` loads SPA |

---

## 2. Deployment execution (on server)

Follow `DEPLOYMENT_CPANEL.md`, then complete this section on the **production host**.

### 2.1 Upload and install

```bash
cd /path/to/project/app
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Pass criteria**

- `migrate --force` succeeds.
- `php artisan about` runs without DB connection errors.

---

### 2.2 cPanel cron (required for scheduled sync)

**Add in cPanel → Cron Jobs (every minute):**

```cron
* * * * * /usr/local/bin/php /home/USER/path/to/app/artisan schedule:run >> /dev/null 2>&1
```

Use the exact PHP binary path from cPanel (“Select PHP version” / `which php` in SSH).

**Pass criteria**

- Cron entry saved.
- After 2–3 minutes, `storage/logs/laravel.log` shows scheduler activity OR daily job effects (prices/snapshots updated) when due.

---

## 3. Post-deployment validation (production)

Replace `https://your-domain.example` with your live URL. API base: `https://your-domain.example/api`.

### 3.1 Health and SPA load

| Step | Command / action | Pass |
|------|------------------|------|
| Health | `curl -s -o /dev/null -w "%{http_code}" https://your-domain.example/up` | `200` |
| SPA | Open `https://your-domain.example/` in browser | Login page loads, no blank screen, no 404 on JS/CSS |
| Assets | DevTools → Network: `build/assets/*.js` returns 200 | All green |

---

### 3.2 Production API smoke

**Option A — adapt script**

Edit first line of `app/tests/Feature/api_smoke.ps1`:

```powershell
$base = "https://your-domain.example/api"
```

Then run:

```powershell
PowerShell -ExecutionPolicy Bypass -File app\tests\Feature\api_smoke.ps1
```

**Option B — manual browser**

1. `GET /sanctum/csrf-cookie` (sets CSRF cookie)  
2. `POST /api/auth/register` or login → JSON includes `user` (no Bearer token)  
3. With session cookie + `X-XSRF-TOKEN`: create stock, transaction, open holdings/dashboard  

**Pass criteria:** All steps HTTP 2xx, no 500/401/419 on authenticated routes. Login requires **HTTPS** on production (`SESSION_SECURE_COOKIE=true`).

---

### 3.3 Auth and security checks

| Check | How | Pass |
|-------|-----|------|
| Debug off | `php artisan config:show app.debug` on server | `false` |
| Error pages | Trigger invalid route | No stack trace / env dump |
| HTTPS | Site loads only via HTTPS | Valid cert, no mixed-content warnings |
| Unauthenticated API | `GET /api/dashboard` without session cookie | `401` |
| Session cookies | DevTools → Application → Cookies after login | `laravel_session` present on HTTPS |
| Secrets | View page source / public repo | No bot token or DB password exposed |

**Note:** API rate limiting (`throttleApi`) is **not** enabled yet. Treat as post-deploy hardening (Section 5).

---

### 3.4 Settings UI + cron on production

**Steps**

1. Log in → **Settings**.
2. Set `cron_time` (e.g. `18:30`), `cron_timezone` (`Asia/Kolkata`) → Save.
3. SSH to server:

```bash
cd /path/to/app
php artisan schedule:list
```

**Pass criteria**

- `portfolio:daily-sync` row present.
- Changing settings in UI and re-running `schedule:list` updates the cron expression (same behavior as `scheduler_live_verify.ps1` locally).

---

### 3.5 Scheduled job end-to-end (production)

**Steps**

1. Temporarily set cron time to **2–3 minutes from now** (Settings UI).
2. Wait for that time + 1 minute (scheduler runs every minute).
3. Check:

```sql
SELECT COUNT(*) FROM portfolio_stock_prices WHERE price_date >= CURDATE();
SELECT * FROM portfolio_portfolio_snapshots ORDER BY snapshot_date DESC LIMIT 5;
```

**Pass criteria**

- New price rows and/or new rows in `portfolio_portfolio_snapshots` for active stocks.
- Check `storage/logs/scheduler-*.log` and `provider-*.log` for failures (file-based logging).

**Restore** cron time to your real daily window after test.

---

### 3.6 Manual sync on production

**SSH (preferred on cPanel):**

```bash
cd /path/to/app
php artisan portfolio:daily-sync
```

Or use the authenticated UI if a manual sync action is exposed.

**Pass criteria**

- Command or API completes successfully.
- Holdings/dashboard reflect updated prices after refresh.

---

### 3.7 Historical backfill (spot check)

**Steps**

1. Create or pick one stock with symbol known to work (e.g. `INFY`).
2. `POST /api/sync/backfill/{stock_id}` with auth.

**Pass criteria**

- 200 response.
- `portfolio_stock_prices` has multiple rows for that `stock_id`.
- `GET /api/analytics/stocks/{id}` returns metrics without 500.

---

### 3.8 Business rules (manual scenarios)

| Scenario | Steps | Pass |
|----------|-------|------|
| Buy increases holding | Buy 10 @ 100, check holdings | `quantity` = 10, `avg_buy_price` ≈ 100 |
| Sell reduces holding | Sell 3, check holdings | `quantity` = 7 |
| Sell all | Sell remaining qty | Holding hidden or `quantity` = 0 |
| Edit transaction | UI: Edit → change qty/price → save | Holdings recalc correctly |
| Duplicate symbol | Add same symbol twice | Validation error, no duplicate row |

---

### 3.9 Analytics sanity

**Steps**

- Open **Dashboard**: portfolio value, XIRR, allocation table, growth chart, RS trends, alerts panel.
- Open **Holdings**: list matches transactions.

**Pass criteria**

- Numbers are consistent (invested ≤ portfolio value when market is up; no NaN).
- Benchmark (NIFTY) section loads if benchmark stock exists.

---

### 3.10 Stoploss + alerts (production)

**Steps**

1. Ensure holding exists with `tracking_active` and prices in DB (after sync).
2. Set aggressive `default_stoploss_percent` in Settings temporarily if needed.
3. Run `POST /api/sync/daily` or wait for cron.
4. `GET /api/alerts`

**Pass criteria**

- Alerts list returns JSON `data` array (may be empty if no breach).
- If breach simulated, alert row in `portfolio_alerts` and optional Telegram message.

---

### 3.11 Telegram on production

**Steps**

1. Settings: valid `telegram_bot_token`, `telegram_chat_id`, `notifications_enabled=true`.
2. Run daily sync or trigger failure path you can safely test.
3. Confirm message in Telegram.

**Pass criteria**

- Message received within 1 minute.
- `storage/logs/laravel-*.log` / `provider-*.log` have no persistent SSL/cURL errors for Telegram.

---

### 3.12 Provider resilience (production observation)

**Steps**

1. After sync, inspect logs:

```sql
# Inspect provider failures in log files on server:
# storage/logs/provider-YYYY-MM-DD.log
```

2. Confirm prices still landed for most symbols.

**Pass criteria**

- Fallback works: at least one provider succeeds per symbol over time.
- Failures are logged with provider name and attempt count, not silent.

---

### 3.13 Logs and permissions

| Check | Path | Pass |
|-------|------|------|
| Laravel log | `storage/logs/laravel-YYYY-MM-DD.log` | No recurring 500 stack traces |
| Scheduler log | `storage/logs/scheduler-YYYY-MM-DD.log` | Entries after cron runs |
| Provider log | `storage/logs/provider-YYYY-MM-DD.log` | Provider failures visible when sync fails |
| Writable storage | `storage/logs` new entries after requests | Yes |
| Queue | `portfolio:daily-sync` uses `dispatchSync` | No worker required; cron + `schedule:run` sufficient |

---

## 4. Validation scripts summary

| Script | When | Command |
|--------|------|---------|
| PHPUnit | Pre-deploy | `php artisan test` |
| API smoke | Pre + post (change `$base`) | `PowerShell -File tests\Feature\api_smoke.ps1` |
| Scheduler verify | Pre + post (SSH `schedule:list`) | `PowerShell -File tests\Feature\scheduler_live_verify.ps1` |

---

## 5. Post-deploy hardening (recommended, not blocking)

Complete after core validation passes.

| Item | Steps | Done when |
|------|-------|-----------|
| API rate limiting | Define `RateLimiter::for('api', ...)` in `AppServiceProvider`, re-enable `throttleApi` in `bootstrap/app.php` | Abuse returns `429`, normal use unaffected |
| CI pipeline | GitHub Action: `composer install`, `php artisan test`, `npm ci`, `vite build` | Green on every push |
| `ext-fileinfo` on server | Enable in cPanel PHP extensions | `php -m` shows `fileinfo` |
| Backup | cPanel backup or mysqldump cron for `portfolio_*` tables | Restore tested once |
| Monitor cron | Email on cron failure or log watcher | Alert if no sync 24h |

---

## 6. Sign-off checklist

Use this for a final “ready for production” sign-off.

| # | Area | Pre-deploy | Post-deploy |
|---|------|:----------:|:-----------:|
| 1 | PHP extensions (mbstring, pdo_mysql, curl/ssl) | ☐ | ☐ |
| 2 | Migrations / `portfolio_*` tables | ☐ | ☐ |
| 3 | `php artisan test` | ☐ | N/A |
| 4 | `vite build` + assets on server | ☐ | ☐ |
| 5 | API smoke | ☐ | ☐ |
| 6 | Scheduler + Settings cron | ☐ | ☐ |
| 7 | cPanel `schedule:run` every minute | N/A | ☐ |
| 8 | Manual `/api/sync/daily` | ☐ | ☐ |
| 9 | UI flows (login, stocks, tx, holdings, dashboard) | ☐ | ☐ |
| 10 | Telegram delivery | ☐ | ☐ |
| 11 | `APP_DEBUG=false`, HTTPS, secrets rotated | ☐ | ☐ |
| 12 | Logs clean (no recurring 500s) | ☐ | ☐ |

**All post-deploy items (6–12) must be checked for production sign-off.**

---

## 7. Rollback plan (if post-deploy fails)

1. Restore previous code + `public/build` backup.
2. `php artisan config:clear && php artisan cache:clear`
3. If migration broke DB: restore MySQL dump from pre-deploy backup.
4. Disable cPanel cron until fixed.
5. Re-run Section 3 smoke on restored version.
