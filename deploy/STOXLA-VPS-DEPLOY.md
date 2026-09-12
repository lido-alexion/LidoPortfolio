# StoXla VPS Deployment Runbook

| Field | Current value |
|---|---|
| Status | Environment reconciliation in progress; do not deploy without explicit authorization |
| Current target | New VPS hosting space |
| Public domain | `https://stoxla.in/` |
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
- SSH/remote-desktop access is available from the operator.
- Existing data has been copied from the old application environment.
- All tables except the large prices table have been migrated so far.
- The prices table contains roughly 13 million rows and is still a migration
  consideration.
- The copied legacy tables still use the `portfolio_` prefix.
- V7-created tables use the canonical `stox_` prefix.

TBD before deployment packaging:

- VPS IP address and hostname.
- SSH user, authentication method, and application root.
- Web server: Nginx or Apache, document root, TLS provider and renewal path.
- PHP version and extensions.
- Composer and Node versions available on the VPS.
- MySQL/MariaDB version, database name, application user and privilege model.
- Queue/scheduler mechanism: cron, systemd timer, supervisor worker, or equivalent.
- Exact `APP_URL`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, and cookie policy.
- Zerodha/Kite static-IP whitelist confirmation.

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
/var/www/stoxla/              (example only; final path TBD)
├── current/                  Laravel application root
│   ├── artisan
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── public/               web document root
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   └── vendor/
└── shared/
    ├── .env                  production secrets, not committed
    └── storage/              optional shared storage if release symlinks are used
```

Final layout may be adjusted to the VPS control panel, but deployment should
support normal SSH commands:

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

## 5. Pre-deployment checklist

- Confirm the prices-table migration strategy and expected cutover time.
- Confirm the VPS public outbound IP and whitelist it in Zerodha/Kite.
- Confirm DNS for `stoxla.in` points to the VPS.
- Provision TLS for `stoxla.in` before setting secure production cookies.
- Create production `.env` outside git and verify secrets are not copied from
  local templates accidentally.
- Verify PHP extensions: `mbstring`, `pdo_mysql`, `openssl`, `curl`, `json`,
  `tokenizer`, `xml`, `ctype`, `fileinfo`.
- Run migrations against a copy or staging database before production cutover.
- Run the V7 verification gate locally before packaging.
- Do not prepare or upload a production package until the operator confirms the
  VPS facts above.

## 6. Post-deployment smoke checks

- `GET https://stoxla.in/` renders the SPA login shell over HTTPS.
- Login works with Sanctum cookies on `stoxla.in`.
- Admin Settings, Sync Logs, Fundamental Data Admin, and ML Admin pages load.
- `php artisan schedule:list` shows StoX scheduled jobs.
- Scheduler heartbeat updates after cron/systemd is enabled.
- Kite readiness detects the whitelisted static IP path.
- A read-only database check confirms both legacy `portfolio_*` tables and V7
  `stox_*` tables are present as expected.
- No temporary debug helper is web-accessible.

## 7. Future CI/CD

Automatic build/deploy on GitHub push is planned after the first VPS deployment
is stable. It is not part of the immediate manual cutover unless a separate
frozen deployment automation plan is created.
