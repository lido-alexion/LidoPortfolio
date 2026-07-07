# Deploy assets

| File | Purpose |
|------|---------|
| **[DEPLOY.md](DEPLOY.md)** | **Start here** — full deploy & update guide |
| **[DEPLOY.md §2.1](DEPLOY.md#21-build-folders-explained-pc-vs-server)** | **Build folders** — `app/public/build/` vs `deploy/staging/`, why two server copies |
| **`prepare-upload.ps1`** | Builds frontend + stages `deploy/staging/` (gitignored) for cPanel upload |
| **`index.php`** | Copy → `public_html/portfolio/index.php` (Laravel front controller) |
| **`public_html-portfolio-.htaccess`** | Copy → `public_html/portfolio/.htaccess` |
| **`public_html-lidoportfolio-.htaccess`** | Copy → `public_html/lidoportfolio/.htaccess` (Deny web access) |
| **`public_html-root-portfolio-snippet.htaccess`** | Paste into root `public_html/.htaccess` (before other rewrite rules) |

### One-time / maintenance (upload → run → **delete from server**)

| File | When |
|------|------|
| `cpanel-once-setup.php` | First deploy: key, migrate, config:cache |
| `cpanel-migrate.php` | After uploading new migrations |
| `cpanel-backfill-sell-realizations.php` | After migration `2026_07_09_000001` — backfill sell P/L columns (optional `?profile=`) |
| `cpanel-config-cache.php` | After `.env` or config changes |
| `cpanel-diagnose.php` | Pre-flight checks |
| `cpanel-schedule-diagnostic.php` | Scheduler timezone, due events, recent sync runs (read-only) |

### Troubleshooting only (upload → use → **delete from server**)

| File | Upload as | Notes |
|------|-----------|--------|
| `cpanel-ping.php` | `portfolio/cpanel-ping.php` | Confirms PHP + folder path |
| `cpanel-api-probe.php` | `portfolio/cpanel-api-probe.php` | Server-side `/api/auth/me` + sessions table |
| `cpanel-mobile-debug.php` | `portfolio/cpanel-mobile-debug.php` | Mobile asset checks (`?token=…`) |
| `portfolio-mobile-debug.html` | `portfolio/mobile-debug.html` | **Rename on upload** (not `portfolio-mobile-debug.html`) |
| `portfolio-OK.txt` | `portfolio/portfolio-OK.txt` | Static path test (no PHP) |
| `test-ok.php` | `portfolio/test-ok.php` | Legacy PHP ping |

### Fix guides

| Doc | Topic |
|-----|--------|
| [FIX-404-PORTFOLIO.md](FIX-404-PORTFOLIO.md) | 404 on `/portfolio/` |
| [FIX-403-FORBIDDEN.md](FIX-403-FORBIDDEN.md) | 403 / wrong `.htaccess` |
| [FIX-BLANK-VITE-PAGE.md](FIX-BLANK-VITE-PAGE.md) | Blank page / dev Vite URLs |
| [FIX-OPEN-BASEDIR.md](FIX-OPEN-BASEDIR.md) | open_basedir |
| [FIX-MYSQL-INDEX-PRIVILEGES.md](FIX-MYSQL-INDEX-PRIVILEGES.md) | Migrate 1142 |

### Server cleanup checklist

After production is stable, remove from **`public_html/portfolio/`**:

- All `cpanel-*.php`
- `mobile-debug.html`, `portfolio-mobile-debug.html`, `portfolio-OK.txt`, `test-ok.php`, `check-server-php.php`

Keep: `index.php`, `.htaccess`, `build/`.

See **`implementation.md` → Production learnings (Jun 2026)** for root causes (mobile `www`/Vite, `.htaccess`, boot banner).
