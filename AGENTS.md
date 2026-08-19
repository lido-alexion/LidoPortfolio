# AGENTS.md

Project overview, requirements, and code/testing guidance live in `README.md` and
`implementation.md` (source of truth). Read `implementation.md` before diving into code.

## Cursor Cloud specific instructions

These notes cover non-obvious startup/run caveats in this cloud environment. Dependencies
(PHP 8.4, Composer, Node, MySQL) are already installed and refreshed by the startup update
script (`composer install` + `npm install` inside `app/`). Standard commands are in `README.md`
and `app/composer.json` / `app/package.json`.

### Application root

- The whole app (Laravel API + React SPA) lives in **`app/`** — `artisan`, `.env`,
  `composer.json`, and `package.json` are all there. Laravel's own PHP is nested at `app/app/`.
  Run every command below from `/workspace/app`.

### PHP version (important)

- Requires **PHP 8.4**, not 8.3. `composer.lock` pins Symfony 8 packages that need `php >= 8.4`,
  so `composer install` fails on PHP 8.3 even though `README.md` says "8.3+". PHP 8.4 is the
  default `php` in this environment.

### Local dev database: SQLite (not MySQL)

- Local dev and the automated test suite both use **SQLite**. `app/.env` sets
  `DB_CONNECTION=sqlite` with `DB_DATABASE=/workspace/app/database/database.sqlite`.
- **MySQL migration currently fails**: `database/migrations/2026_08_09_180001_create_portfolio_transaction_import_batches_tables.php`
  produces the auto index name `portfolio_transaction_import_batch_items_batch_id_sort_order_index`
  (66 chars), exceeding MySQL's 64-char identifier limit. SQLite has no such limit, so the full
  schema migrates cleanly there (this is why the PHPUnit suite, which uses SQLite `:memory:`, never
  caught it). README/production use MySQL; if you must use MySQL locally, that migration needs a
  shortened explicit index name first.
- The SQLite DB file persists in the VM snapshot. **After pulling new migrations**, run
  `php artisan migrate` (and `php artisan db:seed` for fresh data) manually — migrations are
  intentionally not in the startup update script.

### Running the app (development)

- Two services, both from `app/`:
  - API + SPA shell: `php artisan serve --host=127.0.0.1 --port=8001`
  - Frontend HMR: `npm run dev` (Vite, strict port 5173)
- Open `http://127.0.0.1:8001`. Seeded admin login: `admin@lidoportfolio.local` / `password123`.
- `.env` is already configured for plain-HTTP local cookies (`APP_URL=http://127.0.0.1:8001`,
  matching `SANCTUM_STATEFUL_DOMAINS`, and `SESSION_SECURE_COOKIE=false`). Do not set
  `SESSION_SECURE_COOKIE=true` locally — cookie login over HTTP breaks.

### Behavior gotchas

- **Buy transactions require cash first.** A fresh portfolio has ₹0 cash, so saving a buy shows
  "Insufficient cash balance". Deposit cash via the Cash page or
  `POST /api/cash/deposit {"amount": ...}` (send `X-Profile-Id: <id>` and Sanctum CSRF) before
  creating buys.
- **External price providers (NSE/Yahoo/Alpha Vantage) are unreachable** without outbound internet.
  Price sync / history backfill flows log warnings and can be slow (multi-second timeouts) but the
  app still runs on locally stored/seeded prices. Occasional frontend "Network Error" toasts during
  such calls are transient, not backend failures.

### Lint / test / build status

- Lint (PHP): `./vendor/bin/pint` (check-only: `pint --test`). There is no `pint.json`; against the
  default preset it reports many pre-existing style deviations across committed code. The tool runs
  fine — do not mass-reformat existing files.
- PHP tests: `php artisan test` (SQLite `:memory:`, no MySQL needed).
- JS tests: `npm run test:js`. Two `schedulerTimestamp` tests fail under Node 22 due to ICU/`Intl`
  date-format differences (hardcoded expected format vs `Intl.DateTimeFormat` output); this is
  Node-version sensitivity, unrelated to app changes.
- Frontend build: `npm run build` (runs `generate-static-docs.mjs` then `vite build`). Note it
  regenerates committed `public/docs/*.html`; discard those incidental changes unless you meant to
  update docs.
