# V5 FEAT-031 — Production secrets and single-folder deploy hardening

| Field | Value |
|---|---|
| Status | **IMPLEMENTED; production cutover verification pending** |
| Implemented | 2026-09-04 |
| Production target | GoDaddy/cPanel, `/portfolio` subdirectory |
| Related | [`../deploy/SINGLE-FOLDER-DEPLOY.md`](../deploy/SINGLE-FOLDER-DEPLOY.md) |

## Problem

The legacy production shape uses sibling `public_html/lidoportfolio` and
`public_html/portfolio` directories and requires two copies of every Vite build.
It also stores Laravel `.env` below `public_html`. The Laravel sibling is denied
by Apache, but duplicated assets create atomicity risk and a future layout error
could expose secrets.

## Frozen behaviour

- The target is one web directory: `public_html/portfolio`, with Laravel nested
  at `portfolio/laravel` and exactly one public build at `portfolio/build`.
- Application secrets live outside the web tree in
  `/home/USER/config/LidoPortfolio.env`. Existing external `DBConfig.php`
  remains the database credential source of truth.
- An explicitly configured `LIDO_ENV_PATH` wins; otherwise StoX discovers the
  external file by walking deployment ancestors. Outermost candidates win over
  any accidental web-tree copy.
- The parent web rule denies `laravel/` and dot paths before real-file/directory
  pass-through. `laravel/.htaccess` independently denies all web access.
- Laravel's public path is rebound to `portfolio/`, so framework manifest reads
  and browser asset reads use the same `build/` release.
- Existing two-folder production remains a supported rollback path during the
  controlled migration. No application request automatically deletes or moves
  production files.

## Architecture

- `ProductionEnvironment` owns deterministic external environment discovery.
- `bootstrap/app.php` selects the resolved file before Laravel environment
  bootstrap; absent external configuration retains Laravel's normal local
  `.env` behavior for development and legacy production.
- The single-folder front controller boots `portfolio/laravel` and calls
  `usePublicPath(portfolio)` before handling the request.
- A dedicated PowerShell packager builds assets and assembles code, runtime
  directories, web rules, temporary cPanel helpers, and an external environment
  example into separate upload roots.
- All cPanel helpers resolve the new nested Laravel path first and retain the
  legacy sibling fallback.

## Algorithms

1. Resolve the explicit external environment path when readable.
2. Otherwise scan ancestor `config/LidoPortfolio.env` candidates outermost
   first; use the first readable regular file.
3. Otherwise allow Laravel's ordinary `.env` fallback.
4. During packaging, build once, copy the build once, exclude secrets and local
   DB templates, create empty writable runtime directories, and fail if a
   forbidden artifact exists.
5. During migration, stage beside production, install locked dependencies,
   atomically swap the web directory, update the one scheduler path, verify, and
   retain the old directory until the rollback window closes.

## UX

This feature has no investor-facing UI. The operator receives explicit package
output, a migration checklist, exposure probes, and rollback instructions.
Temporary browser-run cPanel helpers remain token-gated and must be deleted
after use.

## Acceptance criteria

- Release package contains one `portfolio/build` and no `.env`, `DBConfig.php`,
  `public/hot`, or `laravel/public/build`.
- External environment selection is deterministic and tested, including
  preference over an accidental web-tree copy.
- Direct HTTP requests for Laravel, secrets, storage, and dot paths are denied
  by the shipped parent rules, with nested deny-all defense in depth.
- Front controller, diagnostics, migration, setup, Composer, and operational
  helpers work with `portfolio/laravel`; legacy fallback remains intact.
- Full application tests, frontend tests, and production build pass.
- Production is not marked verified until the live cutover checklist and cron
  path are confirmed.

## Dependencies

- FEAT-030 supplies the full CI gate.
- Hosting must permit `/home/USER/config` through `open_basedir`; existing
  `DBConfig.php` proves this path is already supported on the target host.
- Production cutover requires cPanel/file access and preservation of the
  existing `APP_KEY`.

## Non-goals

- No automatic production file deletion, cron mutation, credential rotation,
  database rollback, hosting-provider API integration, containerization, or
  deployment service.
- No change to `/portfolio` URLs, Sanctum behavior, application features, or
  database schema.
- FEAT-031 does not begin FEAT-032–040 work.
