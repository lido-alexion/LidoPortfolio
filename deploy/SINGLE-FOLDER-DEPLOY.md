# Lido Portfolio — hardened single-folder deployment

This is the FEAT-031 target layout for `https://www.lidoalexion.com/portfolio`.
The existing two-folder deployment remains runnable during rollback, but new
release packages should use `prepare-single-folder-upload.ps1`.

## Target layout

```text
/home/USER/
├── config/
│   ├── DBConfig.php
│   └── LidoPortfolio.env          # all Laravel/application secrets
└── public_html/
    └── portfolio/
        ├── .htaccess              # blocks laravel/ before directory pass-through
        ├── index.php              # boots portfolio/laravel
        ├── build/                 # the only Vite build copy
        ├── docs/
        ├── favicon.ico
        └── laravel/
            ├── .htaccess          # second deny-all boundary
            ├── app/, bootstrap/, config/, database/, resources/, routes/
            ├── storage/
            ├── artisan
            ├── composer.json
            ├── composer.lock
            └── vendor/
```

There is deliberately no `.env`, `DBConfig.php`, `public/hot`, or
`laravel/public/build` in the release. Laravel reads the external environment
file from `/home/USER/config/LidoPortfolio.env`; the existing external
`DBConfig.php` remains authoritative for database credentials.

## Build and inspect the package

From the repository root in PowerShell with Node 22 and npm dependencies
installed:

```powershell
pwsh -ExecutionPolicy Bypass -File deploy/prepare-single-folder-upload.ps1
```

The script builds with `VITE_APP_BASE=/portfolio/build/` and creates:

- `deploy/staging-single-folder/portfolio/` for `public_html/portfolio/`;
- `deploy/staging-single-folder/config/LidoPortfolio.env.example` as an
  operator-only template for `/home/USER/config/LidoPortfolio.env`.

The script fails instead of packaging an unsafe or incomplete release. Before
upload, verify that the package contains exactly one `build/` directory and no
secret file.

## First migration from the legacy layout

1. Back up `public_html/portfolio`, `public_html/lidoportfolio`, the database,
   and the current production environment values.
2. Create `/home/USER/config/LidoPortfolio.env` from
   `LidoPortfolio.env.example`. Copy the current `APP_KEY` unchanged; rotating
   it would invalidate encrypted sessions and stored encrypted values. Keep the
   file readable only by the account/PHP runtime.
3. Upload the staged `portfolio/` contents into a temporary sibling such as
   `public_html/portfolio-next/`. Do not overwrite the live front controller
   yet.
4. Install locked production dependencies in `portfolio-next/laravel`:

   ```text
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

5. Preserve required writable runtime data and permissions. `storage/` and
   `bootstrap/cache/` must be writable by PHP. User uploads, if any, must be
   copied deliberately; do not copy stale framework caches or logs as release
   code.
6. Put the current site into a short maintenance window, rename the current
   `portfolio/` to a dated rollback directory, and rename `portfolio-next/` to
   `portfolio/`. A same-filesystem rename keeps the cutover atomic.
7. Update the single cPanel cron entry:

   ```cron
   * * * * * /usr/local/bin/php /home/USER/public_html/portfolio/laravel/artisan schedule:run >> /dev/null 2>&1
   ```

8. Run migrations, clear stale caches, then cache configuration. The temporary
   `cpanel-migrate.php` and `cpanel-config-cache.php` helpers support both the
   new and legacy paths when SSH is unavailable.
9. Delete every uploaded `cpanel-*.php` helper immediately after use.

Do not delete `public_html/lidoportfolio` until the new layout has passed all
verification and the rollback window has closed.

## Required verification

Check all of the following before declaring the cutover complete:

- `/portfolio/` loads and signs in over the canonical `www` host;
- `/portfolio/build/manifest.json` exists and every referenced asset returns
  200;
- `/portfolio/laravel`, `/portfolio/laravel/.env`,
  `/portfolio/laravel/storage`, and `/portfolio/.anything` return 403/404 and
  never return file contents;
- `/portfolio/api/auth/me` and one authenticated API call behave normally;
- database connection uses the external `DBConfig.php`;
- configuration reports `APP_ENV=production` and `APP_DEBUG=false`;
- migrations are current, `public/hot` is absent, and scheduler output shows
  the new artisan path;
- a manual `schedule:run` completes without creating a second scheduler;
- the old `/lidoportfolio` path remains denied while it exists.

## Rollback

1. Restore the previous `public_html/portfolio` directory name.
2. Restore the cron path to
   `/home/USER/public_html/lidoportfolio/artisan schedule:run`.
3. Keep the same external database and `APP_KEY`; do not roll back committed
   database migrations unless a migration-specific rollback was separately
   reviewed.
4. Clear/cache configuration in the restored Laravel root and run smoke tests.

The external environment file is compatible with both layouts. The legacy
Laravel-root `.env` remains a fallback during migration, but should be removed
after the single-folder cutover is verified.

## Security invariants

- Secrets never ship in the upload bundle and never need to live under
  `public_html`.
- The parent `.htaccess` rejects `laravel/` before Apache's real-directory
  bypass; the nested deny-all file is defense in depth.
- The front controller is the only web entry into Laravel.
- Temporary browser-run maintenance helpers are fixed-command, token-gated,
  short-lived files—not permanent administration endpoints.
- The package is additive. It does not delete the live legacy tree, change
  credentials, rotate keys, migrate data, or modify cPanel cron automatically.
