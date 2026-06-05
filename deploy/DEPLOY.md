# Lido Portfolio — production deploy (GoDaddy / cPanel)

**Live URL:** `https://lidoalexion.com/portfolio`  
**Account example:** `/home/p7xatiz6j0mk/` (replace with your cPanel username)

This is the **canonical** deploy guide (verified May 2026). Use it for first deploy and for code updates.

| Also see | Purpose |
|----------|---------|
| [DEPLOYMENT_VALIDATION_PLAN.md](../DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post checklists, test commands |
| [implementation.md](../implementation.md) | Architecture, auth, logging |
| [backend/.env.production.example](../backend/.env.production.example) | Production `.env` template |

---

## 1. What you are deploying

| Layer | Detail |
|-------|--------|
| Backend | Laravel 13 — contents of repo `backend/` |
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
    ├── lidoportfolio/               ← entire repo backend/ folder (not web-accessible)
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
| Document root = `backend/public` on a subdomain | Not used; app is at `/portfolio` under main domain |
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
cd D:\Projects\LidoPortfolio\backend

composer install --no-dev --optimize-autoloader
php artisan test

$env:VITE_APP_BASE='/portfolio/build/'
npm ci
npm run build
```

**Verify locally:**

- `public/build/manifest.json` exists
- `public/hot` does **not** exist (delete it if `npm run dev` created it)

**Do not upload:** `.env`, `node_modules/`, `public/hot`, `backend/config/DBConfig.php` (dev template).

### B. Upload to server (File Manager or FTP)

| Local | Server |
|-------|--------|
| `backend/*` (full tree) | `public_html/lidoportfolio/` |
| `deploy/public_html-lidoportfolio-.htaccess` | `public_html/lidoportfolio/.htaccess` |
| `deploy/public_html-portfolio-index.php` | `public_html/portfolio/index.php` |
| `deploy/public_html-portfolio-.htaccess` | `public_html/portfolio/.htaccess` |
| `backend/public/build/` (entire folder) | `public_html/lidoportfolio/public/build/` |
| same `public/build/` | `public_html/portfolio/build/` |

Create production `.env` on server from [backend/.env.production.example](../backend/.env.production.example). Set `APP_URL=https://lidoalexion.com/portfolio` and `DB_CONFIG_PATH=/home/USER/config/DBConfig.php`.

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

Copy [backend/.env.production.example](../backend/.env.production.example). Key points:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lidoalexion.com/portfolio
DB_CONFIG_PATH=/home/USER/config/DBConfig.php

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=lidoalexion.com,www.lidoalexion.com
```

**No** `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, or `DB_PASSWORD` lines.

`APP_KEY` is set by `cpanel-once-setup.php` (`key:generate`).

---

## 5. Updating code (redeploy)

Repeat whenever you change backend or frontend.

### On PC

```powershell
cd D:\Projects\LidoPortfolio\backend

composer install --no-dev --optimize-autoloader
php artisan test

$env:VITE_APP_BASE='/portfolio/build/'
npm run build
```

### Upload changed files

| Always upload after UI change | Always upload after PHP change |
|------------------------------|--------------------------------|
| `public/build/` → both `lidoportfolio/public/build/` and `portfolio/build/` | `app/`, `routes/`, `config/`, `database/migrations/`, etc. |
| Never upload `public/hot` | `vendor/` if `composer.json` / lock changed |

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
| `DEPLOY.md` | This guide |
| `cpanel-diagnose.php` | Pre-flight: PHP, DB, Vite manifests, `hot` file |
| `cpanel-once-setup.php` | One-time: `key:generate`, `migrate`, `config:cache` |
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

### Login loops / 419

- Force HTTPS; `APP_URL` must match `https://lidoalexion.com/portfolio`
- `SANCTUM_STATEFUL_DOMAINS=lidoalexion.com,www.lidoalexion.com` (no `https://`)

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
- [ ] Setup/diagnose PHP files deleted from `public_html/portfolio/`
- [ ] `lidoportfolio/.htaccess` denies direct web access
- [ ] `.env` not under a public URL
- [ ] HTTPS forced
- [ ] Strong passwords; do not use seeded dev admin on production

---

## 9. Quick reference — paths (replace USER)

| Item | Path |
|------|------|
| Laravel root | `/home/USER/public_html/lidoportfolio` |
| Web entry | `/home/USER/public_html/portfolio/index.php` |
| Shared DB config | `/home/USER/config/DBConfig.php` |
| Artisan | `php /home/USER/public_html/lidoportfolio/artisan` |
| Logs | `lidoportfolio/storage/logs/laravel-*.log` |
