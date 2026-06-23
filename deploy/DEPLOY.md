# Lido Portfolio — production deploy (GoDaddy / cPanel)

**Live URL:** `https://lidoalexion.com/portfolio`  
**Account example:** `/home/p7xatiz6j0mk/` (replace with your cPanel username)

This is the **canonical** deploy guide (verified May 2026). Use it for first deploy and for code updates.

| Also see | Purpose |
|----------|---------|
| [DEPLOYMENT_VALIDATION_PLAN.md](../DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post checklists, test commands |
| [implementation.md](../implementation.md) | Architecture, auth, logging |
| [app/.env.production.example](../app/.env.production.example) | Production `.env` template |

---

## 1. What you are deploying

| Layer | Detail |
|-------|--------|
| Backend | Laravel 13 — contents of repo `app/` |
| Frontend | React + Vite — built assets under `/portfolio/build/` |
| Database | Shared MySQL `lido_db` via `/home/USER/config/DBConfig.php` |
| Tables | `portfolio_*` prefix (same DB as other Lido apps) |
| Auth | Sanctum session cookies (HTTPS required) |

**Scheduler:** cron runs `artisan schedule:run` every minute. Daily sync uses `dispatchSync()` — no queue worker required on cPanel.

---

## 2. Server layout (required on GoDaddy)

GoDaddy **open_basedir** only allows PHP under `public_html/`, `config/`, etc. Laravel **must** live inside `public_html`, not at `/home/USER/lidoportfolio/` outside the web tree.

```
/home/USER/
├── config/
│   └── DBConfig.php                 ← shared MySQL (class DBConfig or define() constants)
└── public_html/
    ├── .htaccess                    ← add portfolio snippet (see §7)
    ├── lidoportfolio/               ← entire repo app/ folder (not web-accessible)
    │   ├── .htaccess                ← Deny from all (deploy/public_html-lidoportfolio-.htaccess)
    │   ├── app, bootstrap, config, database, public, resources, routes, storage, vendor
    │   ├── .env                     ← production only; no DB_* credentials
    │   └── public/
    │       ├── build/               ← manifest.json (Laravel reads this)
    │       └── hot                  ← must NOT exist in production
    └── portfolio/                   ← URL path /portfolio/
        ├── index.php                ← deploy/public_html-portfolio-index.php
        ├── .htaccess                ← deploy/public_html-portfolio-.htaccess
        ├── build/                   ← copy of public/build (browser loads these)
        └── cpanel-*.php             ← temporary; delete after setup
```

### Obsolete approaches (do not use)

| Do not | Why |
|--------|-----|
| Document root = `app/public` on a subdomain | Not used; app is at `/portfolio` under main domain |
| Laravel at `/home/USER/lidoportfolio/` outside `public_html` | **open_basedir** blocks it |
| `DB_HOST` / `DB_USER` in production `.env` | Use shared `config/DBConfig.php` only |
| `npm run dev` on server | Dev server is `127.0.0.1:5173`; causes blank page |
| Upload `public/hot` | Forces dev Vite URLs |
| `php artisan route:cache` on `/portfolio` subdirectory | Can break API routing; use `config:cache` only |
| Leave `lidoportfolio/config/DBConfig.php` (dev template) | Picks `root` with no password before shared config |

---

## 3. First-time deploy checklist

### A. On your PC (build machine)

From repo root, in PowerShell:

```powershell
cd D:\Projects\LidoPortfolio\app

composer install --no-dev --optimize-autoloader
php artisan test

$env:VITE_APP_BASE='/portfolio/build/'
npm ci
npm run build
```

**Verify locally:**

- `public/build/manifest.json` exists
- `public/hot` does **not** exist (delete it if `npm run dev` created it)

**Do not upload:** `.env`, `node_modules/`, `public/hot`, `app/config/DBConfig.php` (dev template).

### B. Upload to server (File Manager or FTP)

| Local | Server |
|-------|--------|
| `app/*` (full tree) | `public_html/lidoportfolio/` |
| `deploy/public_html-lidoportfolio-.htaccess` | `public_html/lidoportfolio/.htaccess` |
| `deploy/public_html-portfolio-index.php` | `public_html/portfolio/index.php` |
| `deploy/public_html-portfolio-.htaccess` | `public_html/portfolio/.htaccess` |
| `app/public/build/` (entire folder) | `public_html/lidoportfolio/public/build/` |
| same `public/build/` | `public_html/portfolio/build/` |

Create production `.env` on server from [app/.env.production.example](../app/.env.production.example). Set `APP_URL=https://lidoalexion.com/portfolio` and `DB_CONFIG_PATH=/home/USER/config/DBConfig.php`.

### C. PHP (cPanel)

1. **Select PHP Version** → **8.3+** (8.4 verified) for the domain / `public_html`.
2. Enable extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `tokenizer`, `xml`, `ctype`, `json`.
3. If you previously had bad **open_basedir** paths in `.user.ini`, remove Windows/Plesk paths — see [FIX-OPEN-BASEDIR.md](FIX-OPEN-BASEDIR.md).

### D. Root `.htaccess` snippet

In `public_html/.htaccess`, after `RewriteEngine On`, paste [public_html-root-portfolio-snippet.htaccess](public_html-root-portfolio-snippet.htaccess) so `/portfolio/build/*` and `cpanel-*.php` are served as real files (not swallowed by other app rules).

### E. Diagnose (browser)

1. Upload [cpanel-diagnose.php](cpanel-diagnose.php) → `public_html/portfolio/cpanel-diagnose.php`
2. Open `https://lidoalexion.com/portfolio/cpanel-diagnose.php`
3. Confirm: extensions OK, `public/hot: no`, both manifests `yes`, **DB connection: OK**, resolved user = `niti_nits` (not `root`)

### F. One-time setup (browser)

1. Edit [cpanel-once-setup.php](cpanel-once-setup.php) — set `SETUP_TOKEN`, upload to `public_html/portfolio/`
2. Visit `https://lidoalexion.com/portfolio/cpanel-once-setup.php?token=YOUR_TOKEN`
3. Expect: `key:generate` OK, `migrate` OK, `config:cache` OK
4. **Delete** `cpanel-once-setup.php` and `cpanel-diagnose.php`

### G. Cron

cPanel → **Cron Jobs** → every minute:

```cron
* * * * * /usr/local/bin/php /home/USER/public_html/lidoportfolio/artisan schedule:run >> /dev/null 2>&1
```

Use the PHP 8.4 binary path shown in cPanel if different.

### H. Smoke test

1. Open `https://lidoalexion.com/portfolio/` — login page loads (not blank).
2. DevTools → Network: scripts from `/portfolio/build/assets/...` (200), not `127.0.0.1:5173`.
3. Log in (register link is hidden in CSS; enable temporarily via DevTools or create user locally first).
4. **Settings** → Telegram, sync time; optional `CURL_CAFILE` in `.env` if price APIs fail SSL.

---

## 4. Production `.env` (summary)

Copy [app/.env.production.example](../app/.env.production.example). Key points:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lidoalexion.com/portfolio
DB_CONFIG_PATH=/home/USER/config/DBConfig.php

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=.lidoalexion.com
SANCTUM_STATEFUL_DOMAINS=lidoalexion.com,www.lidoalexion.com
```

**No** `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, or `DB_PASSWORD` lines.

`SESSION_PATH` defaults from `APP_URL` path (`/portfolio`) at `config:cache` — do not set to `/` on production unless you intend domain-wide cookies.

`APP_KEY` is set by `cpanel-once-setup.php` (`key:generate`).

---

## 5. Updating code (redeploy)

**Release-specific steps:** see [RELEASE-2026-06-21.md](RELEASE-2026-06-21.md) for the current uncommitted batch (holdings fees column, transaction scopes, one migration).

Repeat whenever you change backend or frontend.

### On PC

```powershell
cd D:\Projects\LidoPortfolio\app

composer install --no-dev --optimize-autoloader
php artisan test

$env:VITE_APP_BASE='/portfolio/build/'
npm run build
```

### Upload changed files

Paths below use `/home/USER/` — replace `USER` with your cPanel username (e.g. `p7xatiz6j0mk`).

| Upload from (PC — repo) | Upload to (server) | When |
|-------------------------|---------------------|------|
| `app/public/build/` **(entire folder)** | `/home/USER/public_html/lidoportfolio/public/build/` | After any frontend / React change |
| `app/public/build/` **(same folder — second copy)** | `/home/USER/public_html/portfolio/build/` | After any frontend / React change |
| `app/app/` | `/home/USER/public_html/lidoportfolio/app/` | PHP business logic changed |
| `app/routes/` | `/home/USER/public_html/lidoportfolio/routes/` | Routes changed |
| `app/config/` | `/home/USER/public_html/lidoportfolio/config/` | Config changed (e.g. `session.php`) |
| `app/database/migrations/` | `/home/USER/public_html/lidoportfolio/database/migrations/` | New migrations |
| `app/vendor/` | `/home/USER/public_html/lidoportfolio/vendor/` | `composer.json` / `composer.lock` changed |

**Do not upload:** `app/.env`, `app/node_modules/`, `app/public/hot`, `app/config/DBConfig.php` (dev template).

### Run migrations

**With SSH:**

```bash
cd /home/USER/public_html/lidoportfolio
php artisan migrate --force
php artisan config:cache
```

**Without SSH:** temporarily re-upload `cpanel-once-setup.php` (token protected), add only a migrate step, or use phpMyAdmin only for emergency — prefer artisan migrate.

After `.env` changes: delete `bootstrap/cache/config.php` or run `config:clear` then `config:cache`.

### Clear stale caches

| File | When to delete |
|------|----------------|
| `bootstrap/cache/config.php` | Wrong DB user after `.env` / DBConfig fix |
| `public/hot` | Blank page / 127.0.0.1:5173 in console |
| Browser cache | Old JS/CSS after `npm run build` |

---

## 6. Deploy helper files (`deploy/`)

| File | Use |
|------|-----|
| `deploy/README.md` | Deploy helper index |
| `deploy/RELEASE-2026-06-21.md` | Current batch release notes |
| `cpanel-diagnose.php` | Pre-flight: PHP, DB, Vite manifests, `hot` file |
| `cpanel-once-setup.php` | One-time: `key:generate`, `migrate`, `config:cache` |
| `cpanel-config-cache.php` | Re-run `config:cache` after `.env` changes (no SSH) |
| `cpanel-migrate.php` | Run `migrate --force`, repair orphaned `portfolio_sync_logs` table, verify DB tables/columns + sync-log readiness, then `config:cache` (no SSH) |
| `portfolio-mobile-debug.html` | Upload as `public_html/portfolio/mobile-debug.html` — blank-page diagnostics on phone/tablet (delete after use) |
| `public_html-portfolio-index.php` | Front controller for `/portfolio` |
| `public_html-portfolio-.htaccess` | Rewrites under `/portfolio/` |
| `public_html-lidoportfolio-.htaccess` | Deny web access to Laravel tree |
| `public_html-root-portfolio-snippet.htaccess` | Paste into root `public_html/.htaccess` |

---

## 7. Troubleshooting

### Blank page; console shows `127.0.0.1:5173`

- Delete `public_html/lidoportfolio/public/hot`
- Upload production `public/build/` to **both** build locations (§3B)
- Rebuild with `$env:VITE_APP_BASE='/portfolio/build/'` before upload  
→ [FIX-BLANK-VITE-PAGE.md](FIX-BLANK-VITE-PAGE.md)

### DB connection `root@localhost` (password: NO)

- Remove `DB_*` from `.env`
- Delete `lidoportfolio/config/DBConfig.php` if present (dev template)
- Delete `bootstrap/cache/config.php`
- Confirm shared `/home/USER/config/DBConfig.php` exists

### Migrate: `INDEX command denied` (1142)

- Ensure MySQL user has **ALL PRIVILEGES** on `lido_db`
- Current migration skips composite index when denied; app works with symbol-only unique  
→ [FIX-MYSQL-INDEX-PRIVILEGES.md](FIX-MYSQL-INDEX-PRIVILEGES.md)

### 403 Forbidden on `/portfolio/`

- Remove accidental “Deny all” in `portfolio/.htaccess`; use `deploy/public_html-portfolio-.htaccess`  
→ [FIX-403-FORBIDDEN.md](FIX-403-FORBIDDEN.md)

### open_basedir errors

- Laravel must be under `public_html/lidoportfolio/`; fix `.user.ini`  
→ [FIX-OPEN-BASEDIR.md](FIX-OPEN-BASEDIR.md)

### Login loops / 419 (CSRF token mismatch)

Common on subdirectory deploy when session cookies used path `/` and collided with another app on the same domain (wrong `XSRF-TOKEN` read by the browser).

**Step 1 — server `.env`** (`/home/USER/public_html/lidoportfolio/.env`):

```env
APP_URL=https://lidoalexion.com/portfolio
SESSION_DOMAIN=.lidoalexion.com
SANCTUM_STATEFUL_DOMAINS=lidoalexion.com,www.lidoalexion.com
```

**Step 2 — build on PC, then upload**

On your PC (PowerShell):

```powershell
cd D:\Projects\LidoPortfolio\app
$env:VITE_APP_BASE='/portfolio/build/'
npm run build
```

Upload these paths (replace `USER` with your cPanel username, e.g. `p7xatiz6j0mk`):

| Upload from (PC — repo) | Upload to (server) |
|-------------------------|---------------------|
| `app/config/session.php` | `/home/USER/public_html/lidoportfolio/config/session.php` |
| `app/app/Providers/AppServiceProvider.php` | `/home/USER/public_html/lidoportfolio/app/Providers/AppServiceProvider.php` |
| `app/resources/views/app.blade.php` | `/home/USER/public_html/lidoportfolio/resources/views/app.blade.php` |
| `app/public/build/` **(entire folder — all files inside)** | `/home/USER/public_html/lidoportfolio/public/build/` |
| `app/public/build/` **(same folder again — second copy)** | `/home/USER/public_html/portfolio/build/` |
| `deploy/cpanel-diagnose.php` *(optional — verify, then delete)* | `/home/USER/public_html/portfolio/cpanel-diagnose.php` |

**What changed in the frontend build** (you do not upload these `.jsx`/`.js` files separately — they are compiled into `public/build/assets/*.js`):

| Source file (for reference only) | Purpose |
|----------------------------------|---------|
| `app/resources/js/src/appBase.js` | Resolve `/portfolio` API URLs (fallback if meta missing) |
| `app/resources/js/src/auth/csrf.js` | Refresh CSRF cookie before login |
| `app/resources/js/src/context/AuthContext.jsx` | Retry login once on 419 |
| `app/resources/js/src/api.js` | Per-request base URL + CSRF header |
| `app/resources/js/src/pages/LoginPage.jsx` | Show client-side auth errors clearly |

**Do not upload**

| File / folder | Why |
|---------------|-----|
| `app/.env` | Edit production `.env` in place on the server (Step 1) |
| `app/public/hot` | Dev-only; causes blank page if present |
| `app/node_modules/` | Not used on server |
| `app/config/DBConfig.php` | Dev template; use shared `/home/USER/config/DBConfig.php` |

**Step 3 — refresh config cache**

Pick **one** method below (replace `USER` with your cPanel username, e.g. `p7xatiz6j0mk`).

#### Option A — Browser (no SSH) — recommended on GoDaddy

1. On your PC, open `deploy/cpanel-config-cache.php` in a text editor.
2. Change `SETUP_TOKEN` to a long random secret (e.g. `mySecret2026Xyz`).
3. Upload to `/home/USER/public_html/portfolio/cpanel-config-cache.php`
4. In the browser, visit:  
   `https://lidoalexion.com/portfolio/cpanel-config-cache.php?token=mySecret2026Xyz`
5. Expect plain text ending with `session.path: /portfolio` and `Done.`
6. **Delete** `cpanel-config-cache.php` from the server immediately.

#### Option B — cPanel Terminal (if your plan includes SSH/Terminal)

1. cPanel → **Terminal** (or use an SSH client).
2. Run:

```bash
cd /home/USER/public_html/lidoportfolio
php artisan config:clear
php artisan config:cache
```

#### Option C — File Manager only (clears cache; skips re-cache)

If you cannot run PHP from the command line or browser script:

1. cPanel → **File Manager**
2. Open `public_html/lidoportfolio/bootstrap/cache/`
3. Delete `config.php` if it exists

Laravel will read `.env` fresh on each request (slightly slower, but config changes apply). Option A or B is preferred for production.

#### Option D — Cron Job (one-time)

1. cPanel → **Cron Jobs**
2. Add a **once-per-minute** job:

```cron
* * * * * /usr/local/bin/php /home/USER/public_html/lidoportfolio/artisan config:cache >> /home/USER/config-cache.log 2>&1
```

3. Wait 1–2 minutes, check `config-cache.log`, then **remove** the cron job.

**Step 4 — verify and test**

1. Optional: open `https://lidoalexion.com/portfolio/cpanel-diagnose.php` — confirm:
   - `session.path: /portfolio`
   - `sanctum/csrf-cookie URL:` contains `/portfolio/sanctum/csrf-cookie`
   - `app-base meta (for JS): /portfolio`
2. Hard-refresh the login page (Ctrl+F5). In DevTools → Network, filter **Fetch/XHR** (not only “Doc”).
3. Click Login — you should see **two** requests: `sanctum/csrf-cookie` then `auth/login`, both under `/portfolio/`.
4. If **no requests appear**, the form error should now say *“Could not read CSRF cookie after …”* — fix `APP_URL`, run `config:cache`, re-upload `public/build/`.
5. On the device that failed: clear site cookies (or incognito) and retry at one canonical URL (`lidoalexion.com` **or** `www`, not mixed).

**Also check**

- Force HTTPS; redirect `www` ↔ apex to one canonical host if possible.
- `SESSION_PATH` is derived from `APP_URL` (`/portfolio`) when you run `config:cache` — do not set `SESSION_PATH=/` in production.

### Login page blank; console `net::ERR_CERT_DATE_INVALID` on `/portfolio/build/assets/*`

The React app never loads because the browser **blocks all HTTPS assets** when the domain SSL certificate is expired or has invalid dates. This is **not** a Laravel/Vite bug.

**Fix on GoDaddy cPanel:**

1. Log in to **cPanel** for the hosting account.
2. Open **SSL/TLS Status** (or **Security** → **SSL/TLS Status**).
3. Find **`lidoalexion.com`** (and **`www.lidoalexion.com`** if listed separately).
4. Click **Run AutoSSL** / **Install** / **Renew** until status shows a valid certificate (not expired).
5. Wait 5–15 minutes, then hard-refresh `https://lidoalexion.com/portfolio/` (Ctrl+F5).

**Also check:**

| Check | Action |
|-------|--------|
| Certificate expiry | cPanel → **SSL/TLS** → **Manage SSL sites** → view expiry date for `lidoalexion.com` |
| Domain points to this hosting | GoDaddy DNS A record must point to this server; AutoSSL only works when DNS is correct |
| Your PC clock | Wrong system date can cause the same error — verify date/time is correct (usually affects one device only) |
| Mixed hosts | Use one URL: `https://lidoalexion.com/portfolio` **or** `https://www.lidoalexion.com/portfolio` after both have valid certs |

**Verify:** Open `https://lidoalexion.com` in Chrome → padlock → **Connection is secure** → view certificate → **Valid** dates include today.

Until SSL is fixed, the login page will stay blank (no JS loads). Session cookies also require HTTPS in production (`SESSION_SECURE_COOKIE=true`).

### Price sync / Telegram SSL (cURL 60)

```env
CURL_CAFILE=/home/USER/cacert.pem
```

Upload [cacert.pem](https://curl.se/ca/cacert.pem) outside `public_html`.

### Show register link temporarily

Register toggle uses class `.login-register-toggle` (`display: none` in `lido-app.css`). In DevTools, override with `display: inline !important`. Permanent: remove that rule and rebuild frontend.

---

## 8. Security after go-live

- [ ] `APP_DEBUG=false`
- [ ] **Delete from `public_html/portfolio/`:** all `cpanel-*.php`, `mobile-debug.html`, `portfolio-mobile-debug.html`, `portfolio-OK.txt`, `test-ok.php`, `check-server-php.php` (keep `index.php`, `.htaccess`, `build/`)
- [ ] `lidoportfolio/.htaccess` denies direct web access
- [ ] `.env` not under a public URL
- [ ] HTTPS forced
- [ ] Strong passwords; do not use seeded dev admin on production

**Mobile blank page (Jun 2026):** if assets load but React never mounts on phone, check `www` vs apex — Vite must use root-relative `/portfolio/build/...` URLs. See `implementation.md` → Production learnings.

---

## 9. Quick reference — paths (replace USER)

| Item | Path |
|------|------|
| Laravel root | `/home/USER/public_html/lidoportfolio` |
| Web entry | `/home/USER/public_html/portfolio/index.php` |
| Shared DB config | `/home/USER/config/DBConfig.php` |
| Artisan | `php /home/USER/public_html/lidoportfolio/artisan` |
| Logs | `lidoportfolio/storage/logs/laravel-*.log` |
