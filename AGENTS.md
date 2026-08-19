# AGENTS.md

Primary references: `README.md` (quick start / features) and `implementation.md` (living technical reference — treat as source of truth and keep updated). The application root is `app/` (Laravel + React SPA together); run all app commands from `/workspace/app`.

## Cursor Cloud specific instructions

The dependency-refresh update script (`composer install` + `npm install`, run automatically on VM startup) is already applied before your session. The notes below are the non-obvious, durable caveats for developing/running this project in Cloud.

### Runtime / stack

- PHP: use **8.4** (default `php`), not 8.3. `composer.json` says `^8.3`, but `composer.lock` pins Symfony 8 packages that require `php >=8.4`, so `composer install` fails on 8.3. PHP 8.4 (ondrej PPA) with `pdo_mysql`/`pdo_sqlite`/`mbstring`/`curl`/`xml`/`zip`/`bcmath`/`gd`/`intl` is installed.
- Node 22 + npm are preinstalled; Composer is installed globally.

### Database — local dev uses SQLite (important)

- Local dev and the test suite run on **SQLite**, matching `phpunit.xml` (`:memory:`) and CI. `.env` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=/workspace/app/database/database.sqlite`.
- MySQL is the documented production DB (`.env.example` / README), and a MySQL 8 server is installed (start with `sudo service mysql start`). However, a **fresh MySQL migrate currently fails**: migration `2026_08_09_180001_create_portfolio_transaction_import_batches_tables` auto-generates the index name `portfolio_transaction_import_batch_items_batch_id_sort_order_index` (66 chars), exceeding MySQL's 64-char identifier limit. Use SQLite locally, or give that index an explicit short name before running fresh MySQL migrations.
- The SQLite file and `.env` are gitignored and NOT recreated by the update script. Initialize the DB once per fresh VM:
  - `php artisan migrate --force` (or `migrate:fresh --force`) then `php artisan db:seed --force`
  - Seeder creates admin login **`admin@lidoportfolio.local` / `password123`**.

### Running the app (dev)

- Two-terminal dev (preferred for port 8001, used by smoke tests): `php artisan serve --host=127.0.0.1 --port=8001` and `npm run dev` (Vite HMR on 5173, writes `public/hot`). See README "Quick start".
- `composer run dev` runs server+queue+logs+vite together but on default port 8000.
- `.env` is already set for local HTTP cookies (`APP_URL=http://127.0.0.1:8001`, `SESSION_SECURE_COOKIE=false`, `SANCTUM_STATEFUL_DOMAINS` includes `127.0.0.1:8001`). Sanctum session auth needs `GET /sanctum/csrf-cookie` before `POST /api/auth/login`.

### Build / lint / test

- Frontend build: `npm run build` (runs `scripts/generate-static-docs.mjs` then `vite build`). Note: the docs step regenerates tracked files under `app/public/docs/` — revert those generated changes if they show up unintentionally in `git status`.
- PHP tests: `php artisan test` (runs on SQLite in-memory; ~720 tests).
- JS tests: `npm run test:js`. Two tests in `tests/js/schedulerTimestamp.test.mjs` are sensitive to the runtime's ICU/`Intl` locale data and fail on this Node build (expected `05:00:04` 24h format vs runtime `5:00 AM GMT+5:30`); this is an environment/ICU difference, not app breakage.
