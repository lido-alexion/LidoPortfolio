# AGENTS.md

Primary project references: `README.md` → `DOCS.md` (ingestion tree) and `implementation.md` (living technical reference). Read those for architecture and product behavior. This file only adds environment/runbook context for automated agents.

## Cursor Cloud specific instructions

The dependency-refresh update script (`composer install` + `npm install` in `app/`) already runs on VM startup. System packages (PHP 8.4, Composer, MySQL), the local `.env`, and the SQLite dev database persist in the VM snapshot, so the notes below are about running/serving the app, not first-time installation.

### Layout
- The application root is `app/` (Laravel + React together). Run all `php`, `composer`, `artisan`, and `npm` commands from `/workspace/app`, not the repo root. Laravel's own PHP lives under `app/app/`.

### PHP version (non-obvious)
- Requires **PHP 8.4**, even though `composer.json` says `php: ^8.3`. `composer.lock` pins Symfony 8 packages that require `php >= 8.4`, so `composer install` fails on 8.3. PHP 8.4 (CLI) is the default `php` here.

### Database (non-obvious)
- Local dev uses **SQLite**, not MySQL: `.env` has `DB_CONNECTION=sqlite` and `DB_DATABASE=/workspace/app/database/database.sqlite`. This matches the project's own CI/test suite (`phpunit.xml` uses sqlite).
- MySQL is intentionally avoided for fresh setup: the committed migration `database/migrations/2026_08_09_180001_create_portfolio_transaction_import_batches_tables.php` produces an auto index name (`portfolio_transaction_import_batch_items_batch_id_sort_order_index`, 66 chars) that exceeds MySQL's 64-char identifier limit, so `php artisan migrate` fails on MySQL 8. SQLite has no such limit and runs the full migration set cleanly. (Do not "fix" this by editing the migration unless explicitly asked.)
- After schema changes, re-run migrations yourself: `php artisan migrate` (or `php artisan migrate:fresh --seed` to reset). Seed creates the admin user.

### Running the app (two processes)
- Backend: `php artisan serve --host=127.0.0.1 --port=8001` (API + SPA shell on port 8001).
- Frontend: `npm run dev` (Vite dev server; writes `public/hot`, which the Blade `@vite` directive reads).
- Gotcha: if neither the Vite dev server is running nor a production build exists, the app returns HTTP 500 `ViteManifestNotFoundException`. Either keep `npm run dev` running or run `npm run build` once.
- One-command alternative documented in `README.md`: `composer run dev` (runs serve + queue + log tail + Vite via concurrently, but on the default port 8000).

### Login
- Seeded admin: `admin@lidoportfolio.local` / `password123`.

### Core flows / gotchas
- Buying a stock requires available cash first, or the API returns "Insufficient cash balance". Deposit via the Cash page or `POST /api/cash/deposit` before creating Buy transactions.
- Stock symbol validation and price sync call external providers (NSE → Yahoo → Alpha Vantage). Outbound internet works in this environment (e.g. Yahoo returns live prices), but these calls can be slow; some pages that fetch market data may take a few seconds to load.
- Auth is Sanctum session cookies (same-origin). The login endpoint is `POST /api/auth/login` (not `/api/login`).

### Tests & lint
- PHP tests: `php artisan test` (sqlite in-memory via `phpunit.xml`). Full suite passes (~721 tests, 1 skipped).
- JS tests: `npm run test:js`. Two tests in `tests/js/schedulerTimestamp.test.mjs` fail on current Node/ICU because they assert an older hardcoded `Intl` date-format string; this is pre-existing test brittleness tied to the Node/ICU version, not an environment problem.
- Lint: `./vendor/bin/pint` (add `--test` to check only). The repo is not currently Pint-clean — `--test` reports many pre-existing style deviations across the codebase. The tool itself works.
