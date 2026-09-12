# StoXla VPS Deployment Runbook

| Field | Current value |
|---|---|
| Status | VPS server provisioning complete; app deployment not authorized |
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
- TLS is not configured yet; configure Let's Encrypt/Certbot after Nginx is up.
- Preferred web stack: Nginx + PHP-FPM.
- Neither Nginx nor Apache is reported installed yet.
- PHP is not installed yet.
- Application requires PHP `^8.3`; Ubuntu 24.04's PHP 8.3 is the target.
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

Open before deployment packaging:

- Production secrets: `APP_KEY`, DB password, Kite API key/secret, and any mail
  credentials.
- Confirm the `82.112.230.20` static IP is whitelisted in Zerodha/Kite.
- Freeze legacy writes and run any final source-to-target delta after ID
  15,675,217 if the legacy application continues writing before cutover.
- Create the production `.env` on the VPS.
- Run the current-master verification/build gate before deployment.
- Explicit operator authorization to deploy the production application.

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

Deployment should support normal SSH commands:

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
php8.3-cli
php8.3-fpm
php8.3-mysql
php8.3-mbstring
php8.3-xml
php8.3-curl
php8.3-zip
php8.3-bcmath
php8.3-intl
php8.3-gd
```

The repository currently requires PHP `^8.3`, Laravel `^13.8`, Sanctum, Google2FA,
and QR-code support.

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

These steps prepare the VPS operating environment. They do not deploy the
production application code or run application migrations.

### 8.0 Execution status

Current as of 2026-09-12:

- Preflight confirmed `nitty@stoxla-prod`, Ubuntu 24.04.5, public IPv4
  `82.112.230.20`, and DNS for both `stoxla.in` and `www.stoxla.in` resolving
  to `82.112.230.20`.
- MariaDB is active.
- Project-isolated Node has been installed under `/home/nitty/.nvm`.
- `nvm` alias `stoxla` points to Node `v22.23.2`; npm is `10.9.8`.
- `nvm` default alias has been reset to `system` so the project Node remains
  opt-in for StoX build shells.
- Nginx `1.24.0` is installed, enabled, active, and serving the `stoxla` site.
- PHP `8.3.6` CLI and PHP-FPM are installed. `php8.3-fpm` is enabled and
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
- `/var/www/stoxla/public` exists as an empty document root. This intentionally
  returns `403 Forbidden` until application code is deployed.
- `/var/www/stoxla` and `/var/www/stoxla/public` are owned by `nitty:www-data`
  with mode `2755`.
- Nginx redirects HTTP `stoxla.in`, HTTP `www.stoxla.in`, and HTTPS
  `www.stoxla.in` to canonical `https://stoxla.in/`.
- Scheduler cron was prepared only as
  `/home/nitty/stoxla-scheduler.cron.template`; no crontab entry was installed.
- Queue worker service was prepared as
  `/etc/systemd/system/stoxla-queue.service`; it is disabled and inactive.
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
  php8.3-cli \
  php8.3-fpm \
  php8.3-mysql \
  php8.3-mbstring \
  php8.3-xml \
  php8.3-curl \
  php8.3-zip \
  php8.3-bcmath \
  php8.3-intl \
  php8.3-gd
```

Verify services and versions:

```bash
php -v
php -m | sort
systemctl status php8.3-fpm --no-pager
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
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
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

## 9. Application deployment plan

This section is a prepared deployment plan only. Do not execute it until the
operator explicitly authorizes production application deployment.

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

### 9.3 Preflight before code deployment

Before copying or cloning app code:

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

The directory should still be empty except for `public/` until the operator
authorizes deployment.

### 9.4 Cutover deployment sequence

Only after explicit production deployment authorization:

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

### 9.5 Immediate rollback sketch

Before the first deployment, capture the exact git revision deployed and keep
the previous legacy app untouched. If the VPS smoke check fails before launch,
leave DNS/TLS/VPS infrastructure in place, keep scheduler and queue disabled,
and continue serving the legacy application until the issue is fixed.

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

## 10. Pre-deployment checklist

- Freeze legacy writes and run any final source-to-target delta after ID
  15,675,217 if the legacy application continues writing before cutover.
- Confirm `82.112.230.20` is whitelisted in Zerodha/Kite Developer Console.
- Reconfirm DNS for both `stoxla.in` and `www.stoxla.in` still points to
  `82.112.230.20` at cutover time.
- Reconfirm TLS is valid for `stoxla.in` and `www.stoxla.in`.
- Create production `.env` outside git and verify secrets are not copied from
  local templates accidentally.
- Use `nvm use stoxla` for Node-backed StoX build commands.
- Build frontend assets for the VPS with `VITE_APP_BASE=/build/`.
- Run migrations against a copy or staging database before production cutover.
- Run the V7 verification gate locally before packaging.
- Do not prepare or upload a production package until the operator confirms the
  remaining open gates above.

## 11. Post-deployment smoke checks

- `GET https://stoxla.in/` renders the SPA login shell over HTTPS.
- Browser loads JS/CSS from `/build/assets/...`, not `/portfolio/build/...`.
- Login works with Sanctum cookies on `stoxla.in`.
- Admin Settings, Sync Logs, Fundamental Data Admin, and ML Admin pages load.
- `php artisan schedule:list` shows StoX scheduled jobs.
- Scheduler heartbeat updates after cron/systemd is enabled.
- Kite readiness detects the whitelisted static IP path.
- A read-only database check confirms both legacy `portfolio_*` tables and V7
  `stox_*` tables are present as expected.
- No temporary debug helper is web-accessible.

## 12. Future CI/CD

Automatic build/deploy on GitHub push is planned after the first VPS deployment
is stable. It is not part of the immediate manual cutover unless a separate
frozen deployment automation plan is created.
