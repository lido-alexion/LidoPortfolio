# StoXla VPS Deployment Runbook

| Field | Current value |
|---|---|
| Status | VPS production application deployed and responding over HTTPS |
| Current target | Hostinger VPS `stoxla-prod` / remote connector `srv1975196` |
| Public domain | `https://stoxla.in/` |
| VPS IP | `82.112.230.20` |
| Previous target | Legacy GoDaddy/cPanel `https://www.lidoalexion.com/portfolio/` |
| Created | 2026-09-12 |

This is the current production-deployment planning runbook for StoX. The older
GoDaddy/cPanel guides remain useful as historical rollback/reference material,
but they are no longer the active production target for this application.

## 1. Reason for the move

- Kite order placement requires a stable outbound IP that can be whitelisted at
  Zerodha.
- The previous shared cPanel/GoDaddy hosting plan does not provide the needed
  static-IP operational boundary and has hit space constraints.
- The old GoDaddy space remains active for other websites/applications and must
  not be modified during StoX deployment.
- StoX now targets a dedicated VPS and the new `stoxla.in` domain.

## 2. Environment facts and TBDs

Known:

- New production URL: `https://stoxla.in/`.
- VPS provider: Hostinger VPS.
- Host/device name: `stoxla-prod`.
- Remote connector device id/name: `srv1975196`.
- SSH user: `nitty`.
- VPS IP: `82.112.230.20`.
- Home directory: `/home/nitty`.
- Upload pattern: `scp <file> nitty@82.112.230.20:/home/nitty/`.
- OS: Ubuntu 24.04.5 LTS.
- Remote connector command used on the VPS:
  `npx --yes @wonderwhy-er/desktop-commander@latest remote`.
- DNS is reported configured: `stoxla.in` and `www.stoxla.in` resolve to
  `82.112.230.20`.
- TLS is configured with Let's Encrypt/Certbot for both production hostnames.
- Preferred web stack: Nginx + PHP-FPM.
- Nginx is installed and active.
- PHP 8.4 CLI/FPM is installed and active. `composer.json` still allows
  `^8.3`, but the committed `composer.lock` currently includes packages that
  require PHP `>=8.4`.
- Final application root: `/var/www/stoxla`.
- Final web/document root: `/var/www/stoxla/public`.
- MariaDB 10.11.14 is installed and active.
- MariaDB auto-start on reboot is enabled.
- MariaDB listens only on `127.0.0.1:3306`.
- Target database: `stoxla`.
- Target database user: `stoxla_app`@`localhost`.
- DB client config: `/home/nitty/.my.cnf`, permissions `600`.
- Remote DB auth was verified via `.my.cnf`.
- Target DB charset/collation: `utf8mb4` / `utf8mb4_unicode_ci`.
- Schema import to the VPS completed for 83 `portfolio_*` tables with 128
  foreign keys and 0 data rows immediately after schema import.
- `migrations` table imported and verified: 83 rows, ID range 1-83, max batch
  52.
- The historical Laravel migration baseline has been reconstructed on the
  production VPS. Previously missing framework tables were manually created
  from the current repository migration definitions without modifying existing
  `portfolio_*` tables or migration history.
- Small-table data migration completed: 83 `portfolio_*` tables exist, 59
  contain data, and 24 are empty.
- `portfolio_stock_prices` migration is complete. Target verification:
  14,943,914 rows, `MIN(id)=1`, `MAX(id)=15,675,217`,
  `COUNT(DISTINCT id)=14,943,914`, duplicate primary IDs `0`, duplicate
  `(stock_id, price_date)` pairs `0`.
- The originally observed source cutoff was ID 15,675,215. Two additional rows,
  IDs 15,675,216 and 15,675,217, were created while export was taking place and
  were intentionally imported.
- There are no remaining bulk `portfolio_stock_prices` chunks.
- If the legacy application continues writing after ID 15,675,217, handle the
  remaining source-to-target delta during the actual cutover/write-freeze step.
  Treat that as a cutover task, not as a blocker for VPS provisioning.
- The copied legacy tables still use the `portfolio_` prefix.
- V7-created tables use the canonical `stox_` prefix.
- Kite/Zerodha static IP whitelist has been confirmed for `82.112.230.20`.
- A secret-only production env supplement exists at
  `/home/nitty/stoxla.env.production`, owned by `nitty:nitty` with mode `600`.
  It contains `APP_KEY`, `DB_PASSWORD`, `KITE_API_KEY`, and `KITE_API_SECRET`.
  Do not read or print its values in agent logs.

Open after first deployment:

- Freeze legacy writes and run any final source-to-target delta after ID
  15,675,217 if the legacy application continues writing before cutover.
- Confirm login and core authenticated workflows in-browser after the first VPS
  deployment.

## 3. Database namespace posture

Do not treat the VPS migration as an automatic legacy-table rename.

- Existing V1-V6 runtime tables remain `portfolio_*` until a dedicated,
  verified cutover plan is prepared.
- V7 FEAT-055 currently enforces `stox_` for new V7-owned tables and validates
  new migrations.
- A full `portfolio_*` to `stox_*` legacy cutover would affect application code,
  migrations, production data, backups, operational scripts, and rollback. It
  must be planned and verified separately.
- Any deployment steps must explicitly preserve existing table mappings unless a
  later frozen migration plan supersedes this rule.

## 4. Deployment model

The preferred VPS model is a normal Laravel deployment rather than browser-run
cPanel helpers:

```text
/var/www/stoxla/              Laravel application root
├── artisan
├── app/
├── bootstrap/
├── config/
├── public/                   Nginx document root
├── resources/
├── routes/
├── storage/
├── vendor/
└── .env                      production secrets, not committed
```

If release symlinks are introduced later, keep shared secrets and storage
outside release directories. The first VPS deployment assumes the direct app
root above.

Application deployment uses normal SSH commands:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:clear
php artisan view:cache
php artisan schedule:run
```

The cPanel upload package and `cpanel-*.php` helper flow are legacy-only unless
the new VPS panel unexpectedly requires that compatibility route.

Recommended PHP packages:

```bash
php8.4-cli
php8.4-fpm
php8.4-mysql
php8.4-mbstring
php8.4-xml
php8.4-curl
php8.4-zip
php8.4-bcmath
php8.4-intl
php8.4-gd
```

The repository currently declares PHP `^8.3`, Laravel `^13.8`, Sanctum,
Google2FA, and QR-code support. The committed lockfile currently requires PHP
8.4-compatible infrastructure packages, so the VPS runtime is PHP 8.4.

## 5. Database bootstrap baseline

Resolved on the production VPS.

The imported migration history includes Laravel's generic infrastructure
migrations:

- `0001_01_01_000000_create_users_table`
- `0001_01_01_000001_create_cache_table`
- `0001_01_01_000002_create_jobs_table`
- `2019_12_14_000001_create_personal_access_tokens_table`

The VPS migration originally focused on `portfolio_*` application tables. Some
framework tables were therefore missing while their migrations were already
marked as executed. The missing tables have now been manually created from the
current repository migration definitions:

- `sessions`
- `password_reset_tokens`
- `cache`
- `cache_locks`
- `personal_access_tokens`

All five tables exist and are currently empty, as intended. Existing
`portfolio_*` tables and migration history were not modified.

During deployment, run `php artisan migrate --force` normally so Laravel applies
only migrations newer than the imported migration-history baseline. Do not
attempt to rerun or repair the historical starter migrations.

## 6. Production environment baseline

Production `.env` belongs on the VPS only and must not be committed or packaged
with real secrets.

The prepared `/home/nitty/stoxla.env.production` file is a secret-only
supplement, not a complete Laravel `.env`. During deployment, merge those four
secret values into the baseline below.

```dotenv
APP_NAME=StoX
APP_ENV=production
APP_KEY=<GENERATE_ON_SERVER>
APP_DEBUG=false
APP_URL=https://stoxla.in

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=stoxla
DB_USERNAME=stoxla_app
DB_PASSWORD=<PRODUCTION_DB_PASSWORD>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
SESSION_DOMAIN=null

SANCTUM_STATEFUL_DOMAINS=stoxla.in,www.stoxla.in

CACHE_STORE=database
QUEUE_CONNECTION=database

KITE_API_KEY=<SECRET>
KITE_API_SECRET=<SECRET>
KITE_REDIRECT_URL=https://stoxla.in/api/v1/broker/kite/callback
```

Keep the existing StoX-specific universe and trading configuration from
`app/.env.example` unless a later deployment decision intentionally changes it.

## 7. Scheduler and queue

Scheduler cron assumption:

```cron
* * * * * cd /var/www/stoxla && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Do not run the queue worker from cron. Use a persistent service such as systemd
for:

```bash
/usr/bin/php /var/www/stoxla/artisan queue:work
```

## 8. VPS provisioning runbook

These steps record the VPS operating environment. The initial production app
deployment has now been completed.

### 8.0 Execution status

Current as of 2026-09-13:

- Preflight confirmed `nitty@stoxla-prod`, Ubuntu 24.04.5, public IPv4
  `82.112.230.20`, and DNS for both `stoxla.in` and `www.stoxla.in` resolving
  to `82.112.230.20`.
- MariaDB is active.
- Project-isolated Node has been installed under `/home/nitty/.nvm`.
- `nvm` alias `stoxla` points to Node `v22.23.2`; npm is `10.9.8`.
- `nvm` default alias has been reset to `system` so the project Node remains
  opt-in for StoX build shells.
- Nginx `1.24.0` is installed, enabled, active, and serving the `stoxla` site.
- PHP `8.4.25` CLI and PHP-FPM are installed. `php8.4-fpm` is enabled and
  active.
- Required PHP extensions verified: `bcmath`, `ctype`, `curl`, `dom`,
  `fileinfo`, `gd`, `intl`, `mbstring`, `mysqli`, `openssl`, `pdo_mysql`,
  `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `xsl`, `zip`, and core runtime
  modules.
- Composer `2.10.3` is installed at `/usr/local/bin/composer`.
- Certbot `2.9.0` and the Nginx plugin are installed.
- Let's Encrypt certificate `stoxla.in` is issued for both `stoxla.in` and
  `www.stoxla.in`; expiry: 2026-12-11 16:32:43 UTC. `certbot renew --dry-run`
  succeeded.
- Certbot was registered without a notification email because none was provided
  during provisioning.
- `/var/www/stoxla` contains the deployed Laravel application.
- `/var/www/stoxla`, `/var/www/stoxla/public`, `/var/www/stoxla/storage`, and
  `/var/www/stoxla/bootstrap/cache` are owned by `nitty:www-data` with mode
  `2775`.
- `/var/www/stoxla/.env` exists with mode `600`; do not print its values.
- `/var/www/specs/architecture/domains` exists and is owned by
  `nitty:www-data` so the production frontend build can mirror the generated AI
  guide beside the deployed app root.
- Nginx redirects HTTP `stoxla.in`, HTTP `www.stoxla.in`, and HTTPS
  `www.stoxla.in` to canonical `https://stoxla.in/`.
- Scheduler cron is installed for `nitty`.
- Queue worker service is installed as `/etc/systemd/system/stoxla-queue.service`;
  it is enabled and active.
- Temporary passwordless sudo used for provisioning was removed. The remote
  connector again receives `sudo: a password is required`.

### 8.1 Install base packages

Run package installation with sudo from an interactive SSH session as `nitty`
or another sudo-capable user:

```bash
sudo apt update
sudo apt install -y \
  nginx \
  certbot \
  python3-certbot-nginx \
  unzip \
  curl \
  git \
  acl \
  php8.4-cli \
  php8.4-fpm \
  php8.4-mysql \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-zip \
  php8.4-bcmath \
  php8.4-intl \
  php8.4-gd
```

Verify services and versions:

```bash
php -v
php -m | sort
systemctl status php8.4-fpm --no-pager
systemctl status nginx --no-pager
```

### 8.2 Install Composer

Install Composer from the official installer and place it in
`/usr/local/bin/composer`:

```bash
cd /tmp
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
rm composer-setup.php
composer --version
```

### 8.3 Install project-isolated Node 20+

Keep the default machine Node untouched. Use `nvm` under `/home/nitty` for this
project:

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
export NVM_DIR="$HOME/.nvm"
[ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"
nvm install 22
nvm use 22
node -v
npm -v
```

When running future frontend build commands for StoX, load `nvm` in that shell
and explicitly `nvm use 22` first.

### 8.4 Create application root

Create the final application root without copying production app code yet:

```bash
sudo mkdir -p /var/www/stoxla
sudo chown -R nitty:www-data /var/www/stoxla
sudo chmod 2775 /var/www/stoxla
```

After application code is deployed later, Laravel writable paths should be owned
or group-writable by the web server:

```bash
sudo chown -R nitty:www-data /var/www/stoxla
sudo find /var/www/stoxla -type d -exec chmod 2755 {} \;
sudo find /var/www/stoxla -type f -exec chmod 0644 {} \;
sudo chmod -R ug+rwX /var/www/stoxla/storage /var/www/stoxla/bootstrap/cache
```

Run the final `storage` and `bootstrap/cache` permission command only after
those directories exist.

### 8.5 Configure Nginx

Create `/etc/nginx/sites-available/stoxla`:

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name stoxla.in www.stoxla.in;
    root /var/www/stoxla/public;
    index index.php index.html;

    access_log /var/log/nginx/stoxla.access.log;
    error_log /var/log/nginx/stoxla.error.log;

    client_max_body_size 20m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable and validate:

```bash
sudo ln -s /etc/nginx/sites-available/stoxla /etc/nginx/sites-enabled/stoxla
sudo nginx -t
sudo systemctl reload nginx
```

If the default Nginx site captures the domain, disable it:

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

### 8.6 Provision TLS

After DNS resolves to `82.112.230.20` and Nginx is serving HTTP for both names:

```bash
sudo certbot --nginx -d stoxla.in -d www.stoxla.in
sudo certbot renew --dry-run
```

Choose the redirect option so HTTP redirects to HTTPS. Preserve
`stoxla.in` as the canonical app URL; `www.stoxla.in` should redirect or
canonicalize to `stoxla.in`.

### 8.7 Scheduler cron

Install the scheduler under the account that owns the app checkout, expected to
be `nitty`:

```bash
crontab -e
```

Cron entry:

```cron
* * * * * cd /var/www/stoxla && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Enable this only after the application code and production `.env` are in place.

### 8.8 Queue worker systemd service

Create `/etc/systemd/system/stoxla-queue.service`:

```ini
[Unit]
Description=StoX Laravel queue worker
After=network.target mariadb.service

[Service]
User=nitty
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/stoxla
ExecStart=/usr/bin/php /var/www/stoxla/artisan queue:work --sleep=3 --tries=3 --timeout=120
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Enable this only after the application code, production `.env`, and database
queue tables are ready:

```bash
sudo systemctl daemon-reload
sudo systemctl enable stoxla-queue
sudo systemctl start stoxla-queue
sudo systemctl status stoxla-queue --no-pager
```

During future deployments, restart the worker after code changes:

```bash
sudo systemctl restart stoxla-queue
```

## 9. Application deployment

The first VPS production application deployment was explicitly authorized and
completed on 2026-09-13.

### 9.1 Deployment method

Use a clean server-side git checkout under `/var/www/stoxla`, unless a later
operator decision chooses rsync/artifact upload instead.

Expected source:

- Repository branch: `master`.
- Remote state: `origin/master`.
- Local deployable application root in this repo: `app/`.
- VPS application root: `/var/www/stoxla`.
- VPS document root: `/var/www/stoxla/public`.

### 9.2 Production build base

The legacy GoDaddy/cPanel subdirectory deployment uses
`VITE_APP_BASE=/portfolio/build/`. The `stoxla.in` VPS serves the Laravel app at
the domain root, so build root-domain assets with:

```bash
export VITE_APP_BASE=/build/
```

Do not use the legacy `/portfolio/build/` base for the VPS unless the app is
later moved under a subdirectory.

### 9.3 Historical preflight before first code deployment

Before the first code copy, this preflight was used:

```bash
cd /var/www/stoxla
test -z "$(find . -mindepth 1 -maxdepth 1 -print -quit)"
php -v
composer --version
export NVM_DIR="$HOME/.nvm"
. "$NVM_DIR/nvm.sh"
nvm use stoxla
node --version
npm --version
nginx -t
certbot certificates
```

For future deployments, `/var/www/stoxla` is no longer empty; use this as a
historical reference only.

### 9.4 Cutover deployment sequence

Executed first-deployment sequence:

1. Freeze legacy application writes.
2. Run the final source-to-target database delta after ID 15,675,217 if the
   legacy app wrote new rows.
3. Clone or sync the approved `master` revision into `/var/www/stoxla`.
4. Create the production `.env` on the VPS from approved runtime values.
5. Install PHP dependencies:

   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   ```

6. Install/build frontend assets with the VPS base:

   ```bash
   export NVM_DIR="$HOME/.nvm"
   . "$NVM_DIR/nvm.sh"
   nvm use stoxla
   npm ci --registry=https://registry.npmjs.org
   VITE_APP_BASE=/build/ npm run build
   ```

7. Apply only new migrations:

   ```bash
   php artisan migrate --force
   ```

8. Build Laravel caches:

   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

9. Set writable permissions:

   ```bash
   sudo chown -R nitty:www-data /var/www/stoxla
   sudo find /var/www/stoxla -type d -exec chmod 2755 {} \;
   sudo find /var/www/stoxla -type f -exec chmod 0644 {} \;
   sudo chmod -R ug+rwX /var/www/stoxla/storage /var/www/stoxla/bootstrap/cache
   ```

10. Enable the scheduler cron and queue worker only after the app responds
    correctly over HTTPS.

### 9.5 Rollback sketch

The exact git revision deployed is recorded below. The previous legacy app was
left untouched. If a future VPS smoke check fails, leave DNS/TLS/VPS
infrastructure in place, stop scheduler and queue if needed, and roll forward or
restore the previous known-good app contents from the recorded source revision.

### 9.6 Local verification snapshot

Latest pre-deployment preparation verification, run on 2026-09-13 from current
local `master` with no production deployment:

- `php artisan test` with the default 128 MB PHP memory limit failed before
  completion due to memory exhaustion while loading routes.
- `php -d memory_limit=512M vendor/bin/phpunit` passed: 1,572 tests, 9,799
  assertions.
- `npm run test:js` under isolated Node 22 passed: 154 Node tests and 62 Vitest
  tests.
- `npm run typecheck` under isolated Node 22 passed.
- `VITE_APP_BASE=/build/ npm run build` under isolated Node 22 passed and wrote
  production assets to `app/public/build/`. Vite emitted only the existing large
  chunk warning.
- `php artisan openapi:v1 --check` passed; `/api/v1` OpenAPI document is up to
  date with 183 operations.

The production build regenerates static documentation under `app/public/docs/`
and mirrors the AI guide to
`specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md`. The current
regeneration produced timestamp/whitespace-only changes; no content delta was
identified with a whitespace-insensitive diff.

### 9.7 Deployment status

Production application deployment was authorized and completed on 2026-09-13.

Completed:

- Exact source revision `810c53728febcc7c3c39567231ea8bbd59f350d3` was cloned
  to `/home/nitty/LidoPortfolio-deploy-src`.
- Laravel app contents from repository `app/` were copied into
  `/var/www/stoxla`, excluding `.env`, `vendor/`, and `node_modules/`.
- Complete production `.env` was created at `/var/www/stoxla/.env` by combining
  `app/.env.example`, VPS production overrides, and the secret-only supplement
  at `/home/nitty/stoxla.env.production`.
- `.env` values were not printed. File permissions were verified:
  `nitty:www-data`, mode `600`.
- PHP 8.4 was provisioned from the Ondrej PHP PPA because the committed lockfile
  contains packages that require PHP `>=8.4`. Production did not run
  `composer update`.
- Composer install completed from the committed lockfile with
  `--no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress`.
- Frontend dependencies were installed with `npm ci` under project-isolated Node
  `v22.23.2`.
- Production frontend assets were built with `VITE_APP_BASE=/build/ npm run
  build`. Vite emitted only the existing large chunk warning.
- The build generated static docs under `/var/www/stoxla/public/docs` and
  mirrored the AI guide to
  `/var/www/specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md`.
- `php artisan migrate --force` applied only migrations newer than the imported
  historical baseline. Latest applied batch is `53`.
- Read-only database shape check after migration: 121 `portfolio_*` tables, 8
  `stox_*` tables, 114 migration rows, max migration batch `53`.
- Laravel config, route, view, and event caches were built successfully.
- Nginx was updated to PHP-FPM socket `/run/php/php8.4-fpm.sock`; config test
  passed and Nginx was reloaded.
- `https://stoxla.in/` returns `200 OK`.
- `https://www.stoxla.in/` redirects to canonical `https://stoxla.in/`.
- Scheduler cron is installed for `nitty`.
- `stoxla-queue.service` is enabled and active.
- Temporary passwordless sudo used during provisioning/deployment was removed
  again after deployment; `sudo -n true` reports password required.

## 10. Remaining operations checklist

- Confirm whether any final source-to-target delta after ID 15,675,217 is still
  needed if the legacy application continued writing before cutover.
- Kite/Zerodha whitelist for `82.112.230.20` is confirmed.
- DNS for both `stoxla.in` and `www.stoxla.in` resolves to `82.112.230.20`.
- TLS is valid for `stoxla.in` and `www.stoxla.in`.
- Production `.env` exists only on the VPS and was created from approved
  runtime values.
- Use `nvm use stoxla` for Node-backed StoX build commands.
- Build frontend assets for the VPS with `VITE_APP_BASE=/build/` during future
  deployments.
- Run the V7 verification gate locally before future production deployments.

## 11. Post-deployment smoke checks

- `GET https://stoxla.in/` renders the SPA login shell over HTTPS.
- Browser loads JS/CSS from `/build/assets/...`, not `/portfolio/build/...`.
- Login works with Sanctum cookies on `stoxla.in`.
- Admin Settings, Sync Logs, Fundamental Data Admin, and ML Admin pages load.
- `php artisan schedule:list` shows StoX scheduled jobs.
- Scheduler heartbeat updates after cron/systemd is enabled.
- Kite readiness detects the whitelisted static IP path.
- A read-only database check confirmed 121 `portfolio_*` tables and 8 `stox_*`
  tables are present after deployment migrations.
- No temporary debug helper is web-accessible.

## 12. GitHub Actions CI/CD

Conservative CI/CD scaffolding exists, but production deployment is still
manual-only. The workflow is intentionally triggered only by
`workflow_dispatch`; it does not deploy on push.

Implemented files:

- `.github/workflows/deploy-stoxla-production.yml`
- `deploy/scripts/stoxla-deploy-release.sh`
- `deploy/scripts/stoxla-rollback-release.sh`

### 12.1 Deployment model

The GitHub Actions pipeline uses a GitHub-hosted runner to test, build, package,
and upload a release archive over SSH. The VPS does not git pull from GitHub and
does not need GitHub repository access.

Production secrets remain only in the VPS `.env`. The workflow must not receive
`APP_KEY`, database credentials, Kite credentials, or other Laravel runtime
secrets.

Only these GitHub Actions secrets are required for deployment access:

```text
STOXLA_HOST=82.112.230.20
STOXLA_SSH_USER=nitty
STOXLA_SSH_PRIVATE_KEY=<private key for an SSH public key authorized for nitty>
```

The workflow has production deployment concurrency:

```text
group: stoxla-production-deploy
cancel-in-progress: false
```

This prevents two production deployments from overlapping.

### 12.2 Workflow gates

The deployment job is blocked until all verification and packaging jobs pass.
If CI fails, production is not touched.

Backend verification:

- Install Composer dependencies from `app/composer.lock` on PHP 8.4.
- Prepare a Laravel test environment from `.env.example`.
- Validate the full MySQL migration and seed chain against a disposable MySQL
  8.4 service.
- Run the backend test suite with `php -d memory_limit=512M vendor/bin/phpunit`.
- Run `php artisan openapi:v1 --check`.

Frontend verification:

- Install Node 22 dependencies with `npm ci`.
- Run `npm run test:js`.
- Run `npm run typecheck`.
- Build root-domain production assets with `VITE_APP_BASE=/build/ npm run
  build`.

Packaging:

- Rebuilds production dependencies and frontend assets.
- Creates `stoxla-release.tgz` from `app/`.
- Excludes `.env`, `node_modules`, `storage`, `public/storage`, and
  `public/hot`.

Deployment:

- Uploads the artifact and deploy script to
  `/home/nitty/.stoxla-deploy/<release-id>/`.
- Runs `deploy/scripts/stoxla-deploy-release.sh` on the VPS.
- Runs a final health check against `https://stoxla.in/`.

### 12.3 VPS release layout

The first CI/CD deployment converts the existing direct-root layout into a
release layout inside the current app root, without changing Nginx, scheduler
cron, or the queue service paths:

```text
/var/www/stoxla/
├── current -> releases/<active-release>
├── releases/
├── shared/
│   ├── .env
│   └── storage/
├── public -> current/public
├── artisan -> current/artisan
├── app -> current/app
└── ...
```

This keeps these already-live paths valid:

- Nginx document root: `/var/www/stoxla/public`
- Scheduler working directory: `/var/www/stoxla`
- Queue service working directory: `/var/www/stoxla`
- Queue service artisan path: `/var/www/stoxla/artisan`

On the first CI/CD deployment only, the script:

- Copies the existing production `.env` into `/var/www/stoxla/shared/.env` if
  that shared file does not already exist.
- Copies the existing Laravel `storage` directory into
  `/var/www/stoxla/shared/storage` if that shared directory does not already
  exist.
- Moves the previous direct-root application files into a timestamped
  `/var/www/stoxla/.pre-cicd-root-<timestamp>/` backup.
- Creates top-level symlinks back through `/var/www/stoxla/current`.

The script refuses to continue if required paths are missing or if a conflicting
non-symlink path would be overwritten.

### 12.4 Release activation order

For each deployment, the remote script:

1. Creates a deployment lock at `/var/www/stoxla/.deploy-lock`.
2. Extracts the archive to `/var/www/stoxla/releases/<release-id>`.
3. Links the release `.env` to `../../shared/.env`.
4. Links the release `storage` to `../../shared/storage`.
5. Runs `php artisan optimize:clear`.
6. Runs `php artisan migrate --force`.
7. Builds Laravel caches:
   `config:cache`, `route:cache`, `view:cache`, and `event:cache`.
8. Atomically switches `/var/www/stoxla/current` to the new release.
9. Refreshes top-level symlinks.
10. Runs `php artisan queue:restart` so the systemd-managed worker reloads
    gracefully.
11. Checks `https://stoxla.in/`.
12. Prunes old releases, keeping the latest five by default.

The script does not modify MariaDB configuration, does not create or print
runtime secrets, and does not change the scheduler cron.

### 12.5 Required one-time GitHub setup

Create an SSH key dedicated to GitHub Actions deployment from your local
machine:

```bash
ssh-keygen -t ed25519 -C "github-actions-stoxla-deploy" -f ~/.ssh/stoxla_github_actions
```

Install the public key for `nitty` on the VPS:

```bash
ssh-copy-id -i ~/.ssh/stoxla_github_actions.pub nitty@82.112.230.20
```

Confirm that the key can connect without a password:

```bash
ssh -i ~/.ssh/stoxla_github_actions -o IdentitiesOnly=yes nitty@82.112.230.20 'hostname && test -f /var/www/stoxla/.env && test -d /var/www/stoxla/storage'
```

Then add these repository secrets in GitHub:

```text
STOXLA_HOST
STOXLA_SSH_USER
STOXLA_SSH_PRIVATE_KEY
```

`STOXLA_SSH_PRIVATE_KEY` should contain the full private key from
`~/.ssh/stoxla_github_actions`. Do not add Laravel `.env` values to GitHub.

### 12.6 First manual deployment

Run the first deployment manually from GitHub:

1. Open the repository on GitHub.
2. Go to `Actions`.
3. Select `Deploy StoXla Production`.
4. Click `Run workflow`.
5. Select `master`.
6. Start the workflow.

Watch the jobs in order:

- `Backend verification`
- `Frontend verification`
- `Package release artifact`
- `Deploy to VPS`

After the workflow completes, verify:

```bash
curl -I https://stoxla.in/
ssh nitty@82.112.230.20 'readlink /var/www/stoxla/current && ls -1 /var/www/stoxla/releases | tail'
ssh nitty@82.112.230.20 'systemctl status stoxla-queue --no-pager'
```

### 12.7 Rollback

Rollback switches the `current` symlink to a previous release and restarts the
queue worker gracefully. It does not run database down migrations. If a release
contains irreversible database changes, prefer a forward fix unless a specific
database rollback plan has been prepared.

The workflow copies the rollback helper to
`/home/nitty/.stoxla-deploy/stoxla-rollback-release.sh`.

To roll back to the previous retained release:

```bash
ssh nitty@82.112.230.20 'bash /home/nitty/.stoxla-deploy/stoxla-rollback-release.sh'
```

If that path is not available, upload or run the committed
`deploy/scripts/stoxla-rollback-release.sh` script manually:

```bash
scp deploy/scripts/stoxla-rollback-release.sh nitty@82.112.230.20:/home/nitty/
ssh nitty@82.112.230.20 'bash /home/nitty/stoxla-rollback-release.sh'
```

To roll back to a specific retained release:

```bash
ssh nitty@82.112.230.20 'ls -1 /var/www/stoxla/releases'
ssh nitty@82.112.230.20 'bash /home/nitty/.stoxla-deploy/stoxla-rollback-release.sh <release-id>'
```

### 12.8 Enabling deploy-on-push later

Do not enable automatic deploy-on-push until manual deployments are proven.

When ready, add the production branch trigger to
`.github/workflows/deploy-stoxla-production.yml`:

```yaml
on:
  push:
    branches: [master]
  workflow_dispatch:
```

Keep the deployment concurrency group unchanged.

### 12.9 Public-to-private repository implications

This pipeline is compatible with either a public or private GitHub repository.
The VPS never pulls from GitHub, so converting the repository to private does
not require adding GitHub credentials to the server.

After conversion to private, verify:

- GitHub Actions remains enabled for the repository.
- The repository still has sufficient GitHub Actions minutes/storage.
- The deployment secrets remain present.
- Any required production environment approvals are still configured as
  intended.
