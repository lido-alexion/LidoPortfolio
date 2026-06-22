# implementation.md

Living reference for Lido Portfolio. **Update this file whenever code changes.**  
`design_doc.md` was removed (May 2026); requirements come from user chat + this file.

## Agent / documentation policy (May 2026)
- Do not use or recreate `design_doc.md` or removed phase/report/spec files.
- **Canonical docs:** `implementation.md` (technical), `README.md` (quick start), **`deploy/DEPLOY.md`** (production deploy & updates), `DEPLOYMENT_VALIDATION_PLAN.md`, `portfolio-history-rebuild-report.md`, `app/API_DOCUMENTATION.md`.
- Cursor rule `.cursor/rules/Always-update-implementation-details-in-implementation-md-file.mdc` enforces: read this file first; update it after code changes.
- Persistent instructions across sessions: project rules in `.cursor/rules/` (`alwaysApply: true`) + optional User Rules in Cursor Settings.

## Architecture Notes
- Backend scaffold is Laravel (PHP) with API-first structure and service-layer business logic.
- Frontend: React + Bootstrap (Vite build served by Laravel).
- Web app runs as a React SPA mounted from Laravel view `resources/views/app.blade.php`.
- API auth is **session-based** (Sanctum SPA cookies), not Bearer tokens in `localStorage`.

## Local development runbook (agent / future sessions)

Use this section to bring the project up again on a Windows dev machine. Human-oriented summary also lives in root `README.md`.

### What must run separately

| Service | Required? | Typical setup on Windows | Notes |
|---------|-----------|--------------------------|-------|
| **MySQL** | **Yes** | EasyPHP / XAMPP / WAMP MySQL, standalone MySQL, or Docker | App uses `portfolio_*` tables; can share an existing DB (e.g. `lido_db`). |
| **PHP web** | **Yes** (one of) | `php artisan serve` **or** Apache/Nginx | Default dev URL: `http://127.0.0.1:8001`. Document root for Apache: `app/public`. |
| **Node.js** | Dev UI hot-reload | Installed globally | Only for `npm run dev` / `npm run build`. |
| **Apache (EasyPHP)** | **No** (if using `artisan serve`) | EasyPHP control panel | Optional. Use when you prefer vhost over built-in PHP server. |
| **Queue worker** | Optional locally | `php artisan queue:listen` | `QUEUE_CONNECTION=database`; needed for async jobs if not using `sync`. |
| **Scheduler** | Optional locally | `php artisan schedule:work` or OS cron | `portfolio:daily-sync` (prices/snapshots); `portfolio:send-notifications` per `notification_schedules`. |
| **Redis** | No | — | Not used by default (`CACHE_STORE=database`). |
| **Vite dev server** | Optional | `npm run dev` | Hot reload for React; omit if you ran `npm run build` and only use `artisan serve`. |

**Minimum to use the app:** MySQL running + Laravel reachable (usually `php artisan serve`) + frontend assets (`npm run dev` **or** `npm run build`).

### Prerequisites

- **PHP 8.3+** with extensions: `mbstring`, `pdo_mysql`, `openssl`, `curl`, `json`, `tokenizer`, `xml`, `ctype` (recommended: `fileinfo`).
- **Composer** (project uses `app/composer.json`; `composer.phar` may exist at repo root).
- **Node.js 18+** and npm (in `app/`).
- **MySQL 5.7+ / 8.x** listening on `127.0.0.1:3306` (or your host/port).
- Verify PHP: `cd app && php -v && php -m`

Confirm the **same** `php.exe` is used for CLI and (if applicable) Apache/EasyPHP, or extension/session issues will confuse debugging.

### Repository layout

**Folder rename (Jun 2026):** The application root was renamed from `backend/` to **`app/`** — it holds the full Laravel + React stack (not “API only”). Setting keys like `backend_log_level` are unchanged (they mean server-side logging, not the old folder name).

**Human-readable structure guide:** [README.md → Project structure](README.md#project-structure) (collapsible section with folder tables and data-flow notes).

```
LidoPortfolio/
  README.md                 ← short quick start
  implementation.md         ← this file (read first for agents)
  app/                      ← application root (Laravel + React; artisan, .env, public/)
    app/                    ← Laravel PHP code (controllers, services, models)
    resources/js/src/       ← React SPA source
    .env.mysql.template     ← copy to .env
    config/DBConfig.php     ← optional MySQL constants (keep out of git if secrets)
    public/                 ← web root if using Apache
  deploy/                   ← cPanel deploy scripts & guides (see deploy/DEPLOY.md)
  .gitignore                ← root ignore (secrets, vendor, node_modules, build)
```

**Git (Jun 2026):** Monorepo at project root (`LidoPortfolio/`). Remote: `https://github.com/lido-alexion/LidoPortfolio` (private). Branch `master` tracks `origin/master`. Secrets excluded: `app/.env`, `app/config/DBConfig.php`, env backups.

### First-time setup (clean machine)

From PowerShell:

```powershell
cd D:\Projects\LidoPortfolio\app

# 1) Environment
copy .env.mysql.template .env
# Edit .env: DB_*, APP_KEY (or run key:generate), APP_URL, SANCTUM_STATEFUL_DOMAINS, SESSION_SECURE_COOKIE

php artisan key:generate

# 2) Optional legacy DB constants (only if your workflow uses them)
# copy config\DBConfig.php.template config\DBConfig.php  # then fill DB_HOST, DB_NAME, etc.

# 3) Dependencies
composer install
npm install

# 4) Database (MySQL must already be running)
php artisan migrate --force
php artisan db:seed

# 5) Frontend assets (required if not using `npm run dev` in a second terminal)
npm run build
```

After any React/CSS UI change, run `npm run build` again (or keep `npm run dev` running). The Lido shell styles live in `resources/js/src/styles/lido-app.css`.

**Local `.env` values that commonly bite:**

```env
APP_URL=http://127.0.0.1:8001
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8001,127.0.0.1,127.0.0.1:8001
SESSION_SECURE_COOKIE=false
```

(`SESSION_SECURE_COOKIE=true` in `.env.mysql.template` breaks cookie login over plain `http://`.)

**Default seeded login** (`DatabaseSeeder`):

- Email: `admin@lidoportfolio.local`
- Password: `password123`

### Every time you return to the project

1. Start **MySQL** (EasyPHP / XAMPP / Windows service).
2. In `app/`:

```powershell
cd D:\Projects\LidoPortfolio\app
```

3. Choose **one** dev mode:

**Option A — all-in-one (recommended):**

```powershell
composer run dev
```

Starts concurrently: `php artisan serve`, `queue:listen`, log tail (`pail`), `npm run dev`. Default serve URL is `http://127.0.0.1:8000` unless you customize; this repo’s smoke scripts use **port 8001**, so prefer Option B or run:

```powershell
php artisan serve --host=127.0.0.1 --port=8001
```

in a separate terminal alongside `composer run dev` (only one process can bind a port).

**Option B — manual terminals (matches port 8001):**

| Terminal | Command |
|----------|---------|
| 1 | `php artisan serve --host=127.0.0.1 --port=8001` |
| 2 | `npm run dev` |
| 3 (optional) | `php artisan queue:listen` |
| 4 (optional) | `php artisan schedule:work` |

4. Open **`http://127.0.0.1:8001`** in the browser (not Vite’s port; Laravel serves the SPA and proxies Vite assets in dev).

**React + `npm run dev`:** `resources/views/app.blade.php` must include `@viteReactRefresh` **before** `@vite` (Laravel 13 + `@vitejs/plugin-react`). Without it, the console shows `can't detect preamble` and the SPA fails to load. On Windows, if `npm run dev` exits immediately, use `npx vite` from `app/` instead.

### Using EasyPHP / Apache instead of `artisan serve`

1. Start **MySQL** from EasyPHP.
2. Point the site **document root** to `D:\Projects\LidoPortfolio\app\public`.
3. Set `APP_URL` in `.env` to that vhost URL (e.g. `http://localhost/lidoportfolio/public` or a virtual host).
4. Add that host (and port if any) to `SANCTUM_STATEFUL_DOMAINS`.
5. Run `npm run build` (no Vite dev server required).
6. `php artisan migrate --force` still runs from CLI in `app/`.

You do **not** need a separate Node server in production-style Apache mode after `npm run build`.

### Outbound HTTPS (NSE / Yahoo / Telegram)

If price sync fails with `cURL error 60`, set in `.env`:

```env
CURL_CAFILE=C:\path\to\cacert.pem
```

See `DEPLOYMENT_VALIDATION_PLAN.md` § SSL.

### Useful commands

```powershell
cd D:\Projects\LidoPortfolio\app

php artisan migrate --force          # after pulling new migrations
php artisan db:seed                  # re-seed admin + default settings
php artisan portfolio:daily-sync     # manual daily prices + portfolio snapshots
php artisan stocks:sync              # NSE equity master CSV import
php artisan test                     # PHPUnit (uses sqlite in-memory)
npm run test:js                      # frontend unit tests
npm run build                        # production JS/CSS bundle

# API smoke (server must be on :8001)
PowerShell -ExecutionPolicy Bypass -File tests\Feature\api_smoke.ps1
```

### Tests vs local MySQL

`php artisan test` uses **SQLite in-memory** (`phpunit.xml`); it does not require MySQL. Local browsing and manual testing use MySQL from `.env`.

### Pending deploy (2026-06-21 batch)

**One migration required:** `2026_06_21_000001_add_total_fees_to_portfolio_holdings` — adds `portfolio_holdings.total_fees` and recalculates all holdings (fee-exclusive avg buy/invested).

**Deploy checklist:** `deploy/RELEASE-2026-06-21.md` (build, upload paths, `cpanel-migrate.php`, smoke tests).

**No** new env vars, routes files, or `composer.json` changes in this batch.

### Related docs

| Doc | Purpose |
|-----|---------|
| `README.md` | Short quick start |
| `deploy/DEPLOY.md` | Production deploy (GoDaddy `/portfolio`) |
| `deploy/RELEASE-2026-06-21.md` | Pending release: migration `total_fees`, holdings/transactions UI batch |
| `DEPLOYMENT_CPANEL.md` | Generic cPanel pointer → `deploy/DEPLOY.md` |
| `DEPLOYMENT_VALIDATION_PLAN.md` | Pre/post deploy checks |
| `app/API_DOCUMENTATION.md` | REST API |

## Technical Decisions
- Local environment templates aligned to MySQL-based setup.
- Added `.env` template specifically for MySQL usage in `app/.env.mysql.template`.
- **DB credentials:** `config/load_db_config.php` finds `config/DBConfig.php` by walking up directories (outermost first so `/home/USER/config/DBConfig.php` wins over `lidoportfolio/config/DBConfig.php` if a dev template was uploaded). Supports **class `DBConfig`** or **define()** constants. Optional `DB_CONFIG_PATH` in `.env`. When `DBConfig.php` is loaded, its values **take precedence over** `.env` `DB_*`. Delete `bootstrap/cache/config.php` if DB still shows `root` after fixes. `deploy/cpanel-diagnose.php` flags app-local `DBConfig.php` and cached config.
- **GoDaddy migrate 1142:** shared MySQL user may lack `INDEX` on existing tables. `2026_05_29_000001_extend_portfolio_stocks_master` adds columns always; composite unique on `(symbol, exchange)` is skipped when error 1142 (keeps `symbol` unique). See `deploy/FIX-MYSQL-INDEX-PRIVILEGES.md`.
- To share a single MySQL database with an existing project, this app uses isolated table names:
  - `portfolio_users`, `portfolio_stocks`, `portfolio_transactions`, `portfolio_holdings`
  - `portfolio_stock_prices`, `portfolio_stock_metrics`, `portfolio_portfolio_snapshots`
  - `portfolio_alerts`, `portfolio_settings`, `portfolio_system_logs`
  - queue tables: `portfolio_jobs`, `portfolio_job_batches`, `portfolio_failed_jobs`
- Added Sanctum migration manually from vendor due `finfo` extension issue with `vendor:publish`.
- Added `Schema::defaultStringLength(191)` for MySQL index compatibility in current environment.
- Added frontend toast event bus (`portfolio-toast`) and confirmation dialog for transaction delete UX polish.
- Added lightweight bar visualization for portfolio growth in dashboard without introducing heavy chart dependencies.
- Added deeper automated tests around provider fallback and scheduler failure path.
- **Logging architecture (May 2026):** file-based Monolog daily channels, `PortfolioLoggerService`, `X-Request-ID` correlation, frontend `logger.js` + Error Boundary, `POST /api/logs/frontend`. See **Logging & Debugging Architecture** below.
- **Stock validation & master (May 2026):** local-first validation, `portfolio_stocks` extended fields, `stocks:sync` weekly job, `StockValidationService` / `StockMasterSyncService` / `ProviderResolverService`, autocomplete UI. See **Stock Validation Architecture** below.
- **Session authentication (May 2026):** Sanctum SPA cookies (no JWT/localStorage tokens), Remember Me, multi-device sessions UI, `AuthProvider`. See **Authentication Architecture** below.
- Added Recharts-based interactive line chart for portfolio growth trends on dashboard.
- Enabled `sqlite3` + `pdo_sqlite` extensions in active PHP runtime and removed stoploss test skip guard; DB-bound stoploss persistence test now executes.
- Scheduler now resolves `cron_time` and `cron_timezone` from `portfolio_settings` with safe env fallback.
- **Telegram notifications** are separate from **data syncing time** (`cron_time`). `notification_schedules` (JSON array of `HH:mm`) drives `portfolio:send-notifications` at each time in `cron_timezone`. Job uses `AlertNotificationService` → same alerts as `GET /api/alerts`; **silent skip** when none. Stoploss triggers only **persist** alerts (no immediate Telegram). Removed daily summary Telegram from `DailyMarketDataJob`. Settings UI: add/remove notification times.
- Improved provider resilience with per-provider retries, backoff, and structured attempt-level failure logging.
- Dashboard UI expanded with top gainer/loser, **Alerts** card (stoploss today; extensible), and relative-strength trend widgets (vs **NIFTY50** benchmark: stock period return % − index return %; cached in `portfolio_stock_metrics`).
- **Alert expiration** (`portfolio_alerts.user_id`, `expired_at`, `expiration_reason`): alerts are **per user** (not global per stock). Stoploss creates one alert per holder per day (`user_id` + `stock_id` dedup). `GET /api/alerts` / dashboard filter by `user_id`. Expiration: manual clear all + acknowledge (own alerts only); hourly 100h max age; new trading day after daily sync; **full sell** expires only that user's alerts (`expireForUserStockIfUnheld`). Active = `expired_at` IS NULL.
- Dashboard summary cards, allocation **Market Value**, and growth-chart axis/tooltips use `formatInrWhole` / `formatInrCompactWhole` (no paise; `₹ ` + amount, lakh grouping). Holdings/Explorer use `formatInr` (2 dp) via `formatTableMoney2`.
- Dashboard **Sync prices for today** → `POST /api/sync/daily` (`force: true` from UI when re-syncing same day). Skips without `force` if already synced today (cron-safe). Button stays enabled as **Sync again today** after first success; shows muted “Synced for …” hint.
- **Production deploy (May 2026):** Canonical steps in **`deploy/DEPLOY.md`** (first deploy + code updates). GoDaddy layout: `public_html/lidoportfolio/` + `public_html/portfolio/`; DB via `/home/USER/config/DBConfig.php`; browser setup via `cpanel-diagnose.php` / `cpanel-once-setup.php` / **`cpanel-config-cache.php`** (config:cache only, after `.env` edits). Obsolete: Laravel outside `public_html`, `DB_*` in production `.env`, document root = `app/public` on main domain, `route:cache` under `/portfolio`.
- **Production subdirectory** (`https://lidoalexion.com/portfolio`): `APP_URL` includes path; build with `VITE_APP_BASE=/portfolio/build/`; upload `public/build/` to **`lidoportfolio/public/build/`** and **`portfolio/build/`**. Vite tags use **root-relative** paths (`AppServiceProvider::createAssetPathsUsing`) so `www` and apex both work. Delete `public/hot` on server. Troubleshooting: `deploy/DEPLOY.md` §7, `implementation.md` → Production learnings.
- Dashboard cards: **Portfolio Value** / **Total Gain/Loss** green when portfolio &gt; invested, red when less, default text when equal; **XIRR** green/red by sign. Allocation **%** is whole numbers; &gt;15% orange (`text-allocation-elevated`), &gt;20% red.
- Transactions UI now supports edit/update flow in addition to create/delete. **Fix (Jun 2026):** update/delete auth compared `user_id` with strict `!==`, so string vs int IDs from MySQL caused false 403; `Transaction` route binding now scopes to `auth()->user()->transactions()` (SQL ownership check). FE `api.js` maps generic auth errors to a full sentence. **Delete buy guard:** before deleting a buy, `HoldingsCalculationService::assertReplayValidAfterDeleting()` dry-runs the ledger replay; if orphan sells would break recalc, API returns 422 with guidance to delete sell transaction(s) first.
- Holdings UI shows highest close since buy, trailing stop, and links to OHLCV price history screen.
- `GET /stocks/{stock}/prices` and force sync via `POST /sync/backfill/{stock}`; buy transactions trigger synchronous backfill.
- Price providers use `App\Support\ExternalHttp` with `CURL_CAFILE` / `config/portfolio.php` CA bundle for SSL.
- Sync failures return 422 with provider error details; UI shows informative messages (not silent "0 rows").
- Transactions can create stocks inline: POST `/api/transactions` accepts `symbol` (+ optional `name`, `exchange`) instead of requiring a prior Stocks screen entry (`StockResolverService`).
- Transactions UI uses symbol autocomplete; new symbols auto-create master row on first buy.
- Transactions table: **Qty** via `formatTableInteger`; **Price** via `formatTableMoney2` (`₹ ` + `en-IN` grouping, 2 dp).
- Stocks SPA tab removed; `GET /api/stocks` still used by Transactions autocomplete. Stock CRUD APIs retained for future UI.
- Holdings table shows **Latest Close** (from most recent OHLCV row since buy date, with metrics fallback).
- Holdings **Avg Buy** and **Invested** exclude transaction fees (price × qty only). **`total_fees`** on `portfolio_holdings` sums buy + sell fees for the current open position; **Fees** column shows absolute amount and `% of invested` (1 dp). Recalc via `HoldingsCalculationService` on each `GET /holdings`.
- Holdings **XIRR** from backend (`XirrService` via `GET /holdings`); per-holding terminal value uses `qty × latest_close` where **latest_close** is the same figure shown in the holdings row (since-buy OHLCV, else metrics fallback — not a separate global history lookup). Dashboard portfolio XIRR uses the same terminal as displayed **Portfolio Value**. Portfolio XIRR includes all historical buy/sell transactions (closed positions too), so it only equals a single holding’s XIRR when that stock is the only one ever traded.
- Tabular UIs: **TanStack Table** via `DataTableCard` / `DataTable.jsx` (columns icon in card header; show/hide + reorder panel).
- Transaction form: labeled fields; **NSE × BSE** toggle; integer qty (empty by default, like price); price step **0.05** (2 dp); **Fees** auto-calculated (read-only) from Settings fee components — hover **ⓘ** for line-item breakdown; symbol validate button → `POST /api/stocks/validate` with `check_only: true` (local + in-memory cache); Save disabled until valid + symbol validated; while save API runs, submit shows **Adding…** / **Updating…** and stays disabled. After **add**, form retains stock/symbol/qty/type/date/notes; only **price** clears (blocks accidental duplicate submit). After **update**, form resets. **Transaction date** (`TransactionDateInput.jsx`): text `dd-mmm-yyyy` + picker button with calendar icon (outline + 3×3 date dots). DB/API field: `fees` (renamed from `brokerage`, Jun 2026). FE is canonical calculator (`resources/js/src/utils/feeCalculator.js`); API trusts client `fees` on save. One-time migration recalculated historical rows using PHP mirror (`FeeCalculatorService`).
- **Transaction history** table (**Active transactions**): `GET /transactions?scope=open` — only transactions for stocks with holding `quantity > 0` (recalculates holdings first). Client search in header. Link **Squared-off** → `/transactions/closed`. Columns include **Notes** (truncated with full text on hover; `—` when empty). **Squared-off** page: `scope=closed`, server search + pagination (25/page), edit navigates to main form, delete in place.
- **Transaction fee settings (Jun 2026):** Settings → **Transaction fees** card (collapsible, **collapsed by default**) — configurable lines: label, **Type** (% / fixed ₹), rate, **Buy/Sell** tap toggles, **NSE/BSE/Both** exchange filter, per-line **GST %** (single row per component; theme-aware `lido-fee-component-row`; compact `NumberInput` height). Defaults match Zerodha equity delivery (brokerage 0%, STT 0.1%, NSE/BSE txn charges, SEBI 0.0001% [= ₹10/crore], stamp 0.015% buy-only). Stored as JSON in `portfolio_settings.fee_components`.
- Shell UI: full-bleed black header (`AppHeader.jsx`, **Lido Alexion** in Nulshock via `resources/fonts/nulshock-bd.ttf`, bundled by Vite into `build/assets/`), profile menu (`ProfileMenu.jsx`, includes `ThemeToggle.jsx` 3-segment sun/monitor/moon theme switch), logged-in **Bootstrap tabs** (`AppTabs.jsx`), footer nav (`AppBottomNav.jsx`, `config/mainNav.js`). **Themes:** `light` / `dark` / `system` via `ThemeContext` + CSS variables in `lido-app.css` (`localStorage` key `lido-theme`); `app.blade.php` inline script avoids flash. Dev: `npx vite` + `@viteReactRefresh`; prod: `npm run build`.
- Holdings bottom-nav item stays active on OHLCV sub-routes (`/holdings/:id/prices`).
- Holdings OHLCV screen (`StockPricesPage`): `DataTableCard` with `formatTransactionDateDisplay`, `formatTableMoney2` (Open/High/Low/Close), `formatTableInteger` (Volume).
- Holdings table **Sell** button navigates to `/transactions` with form prefilled: symbol/name/exchange, type `sell`, quantity = holding qty, price = latest close, symbol marked validated (`sellTransactionPrefill.js` + router `location.state`).
- Holdings **Latest Close**: `₹` whole amount + rounded `(+N%)` vs avg buy in `small` plain text; green/red for gain/loss %; price still red (no bold) when below trailing stop. When LTP &lt; trailing stop: **Stock** symbol uses `text-danger`; **Sell** uses solid `btn-danger` (not outline).
- Holdings **Highest Close** 2nd line: `LTP: N%` = `((LTP − highest since buy) / highest) × 100`; green if ≥ 0, orange if below 0 but above −`stoploss_percent`, red if ≤ −`stoploss_percent` (from settings / `stoploss_summary.stoploss_percent`).
- Holdings table default column order: Stock → Latest Close → Invested → Fees → XIRR → Highest Close → **Qty** → **Avg Buy** → Trailing Stop → Realized P/L (hidden) → OHLCV → Sell. **Realized P/L** hidden by default (`defaultColumnVisibility` on `DataTableCard`); user prefs in `localStorage` key `portfolio_datatable_holdings`.
- SPA routing: `routes/web.php` catch-all serves `app` view for all non-API paths so browser refresh on `/holdings`, `/transactions`, etc. works (React `BrowserRouter`).
- `AppTabs` uses `useLocation().pathname` for active tab state (NavLink `className` callback does not receive `location` in React Router v6).

## Change Requests
- Track user requests in chat; this section is not a full history log.

## Deviations From Spec
- Table names are prefixed with `portfolio_` to avoid clashes with existing tables in the same DB.
- `throttleApi` middleware removed because the `api` limiter was not defined and caused runtime 500s.

## Bugs Fixed
- Table prefix migration fallout (validators, `HoldingController`, XIRR/Carbon 3, dashboard eager-load, PHPUnit/mbstring, health route).
- **Production mobile blank page (Jun 2026):** Vite emitted absolute script URLs on `lidoalexion.com` while users opened `www.lidoalexion.com` — ES modules failed cross-origin. Fix: `Vite::createAssetPathsUsing()` → root-relative `/portfolio/build/...` in `AppServiceProvider`.
- **Boot probe noise (Jun 2026):** diagnostic “Module script” lines were saved to `sessionStorage` and shown in `BootErrorBanner` after successful load; now only real failures persist and success clears storage.
- **`GET /api/auth/me` (Jun 2026):** moved outside `auth:sanctum` so guests get `{ user: null }` (200), not 401/500 during SPA boot.

## Known Limitations
- `vendor:publish` for Sanctum migrations fails when `finfo` extension is unavailable.

## Pending Improvements
- Add CI workflow for backend tests and frontend build checks.
- **Stocks admin UI (open):** Stocks tab removed from SPA (May 2026). Backend `GET/POST/PUT /api/stocks` and `portfolio_stocks` table remain. Reintroduce a Stocks screen later if master-data management is needed outside Transactions.

## Open Items
| Item | Status | Notes |
|------|--------|-------|
| Stocks tab / master UI | Deferred | Master data via `stocks:sync` + Transactions autocomplete; no dedicated Stocks admin SPA tab. |
| BSE master sync | Optional | Enable `BSE_STOCK_MASTER_ENABLED=true` and `BSE_EQUITY_CSV_URL` when BSE CSV source is configured. |

## Deployment Validation
- **Canonical deploy:** `deploy/DEPLOY.md` · checklists: `DEPLOYMENT_VALIDATION_PLAN.md`
- **Stage uploads:** `deploy/prepare-upload.ps1` → `deploy/staging/` (gitignored)

### Production learnings (Jun 2026 — `/portfolio` on GoDaddy)

| Issue | Cause | Fix |
|-------|--------|-----|
| Mobile blank page | `www` vs apex in Vite `<script type="module">` src | Root-relative asset URLs in `AppServiceProvider`; `config:cache` after deploy |
| Login CSRF on some devices | Stale `XSRF-TOKEN` at path `/`, cookie not readable after `/sanctum/csrf-cookie` | Clear site cookies; deploy latest build (`/api/auth/csrf-token` fallback + `X-CSRF-TOKEN` header); `config:cache` with `SESSION_PATH=/portfolio`, `SESSION_DOMAIN=.lidoalexion.com` |
| “App did not start” on `mobile-debug.html` | Static file missing → Laravel SPA | Upload as `portfolio/mobile-debug.html`; fix `portfolio/.htaccess` + root snippet |
| 404 on whole `/portfolio/` | `.htaccess` missing `index.php` rewrite | Use `deploy/public_html-portfolio-.htaccess` |
| Red “App load problem” on login | Stale `sessionStorage.lido_boot_error` | Tap Dismiss; deploy latest `BootErrorBanner` + `app.blade.php` |

**Server cleanup after troubleshooting:** delete all `cpanel-*.php`, `mobile-debug.html`, `portfolio-OK.txt`, `test-ok.php` from `public_html/portfolio/`. Keep `index.php`, `.htaccess`, `build/`.

**Optional deploy diagnostics (repo only, upload temporarily):** `cpanel-ping.php`, `cpanel-mobile-debug.php`, `cpanel-api-probe.php`, `portfolio-mobile-debug.html` (upload renamed → `mobile-debug.html`). See `deploy/README.md`.

**Vite build:** `$env:VITE_APP_BASE='/portfolio/build/'` before `npm run build`; copy `public/build/` to both `lidoportfolio/public/build/` and `portfolio/build/`.

## Logging & Debugging Architecture (May 2026)

Mandatory lightweight logging for same-day / 1–2 day debugging. **File-based only** — no log rows written to `portfolio_system_logs` (table retained for legacy; new path uses Monolog daily files).

### Goals
- Quick debugging, recent log inspection, error tracing, frontend↔backend correlation via `X-Request-ID`.
- No long-term retention; `LOG_DAILY_DAYS=2` rotates and deletes older files.

### Backend channels (`config/logging.php`)
| Channel | File pattern | Purpose |
|---------|----------------|---------|
| `daily` (default) | `storage/logs/laravel-YYYY-MM-DD.log` | Application / API / validation / security / telegram |
| `frontend` | `storage/logs/frontend-YYYY-MM-DD.log` | Logs from SPA via API |
| `provider` | `storage/logs/provider-YYYY-MM-DD.log` | NSE / Yahoo / Alpha Vantage failures & fallbacks |
| `scheduler` | `storage/logs/scheduler-YYYY-MM-DD.log` | Cron / `DailyMarketDataJob` |

Env: `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=2`, `LOG_LEVEL=debug` (Monolog floor; app-level filter is separate).

### Dynamic backend log level
- Setting key: `backend_log_level` in `portfolio_settings` (`debug` | `info` | `warning` | `error`).
- Default: `info` (`SettingsService`).
- Editable in Settings UI and `PUT /api/settings`.
- `PortfolioLoggerService::shouldLog()` filters before writing; Monolog always receives normalized level when allowed.

### Backend services
- `App\Services\PortfolioLoggerService` — categories: API, Scheduler, Provider, Telegram, Validation, Security; methods `api()`, `scheduler()`, `provider()`, `frontend()`, `telegram()`, `validation()`, `security()`, `logFrontendPayload()`.
- `App\Services\SystemLogService` — backward-compatible facade; maps legacy categories to channels; **no DB writes**.
- `App\Support\RequestContext` — holds current request ID.
- `App\Http\Middleware\AssignRequestId` — reads or generates `X-Request-ID`, shares `request_id` in `Log::shareContext()`, echoes header on response. Registered on `api` + `web` in `bootstrap/app.php`.
- Uncaught exceptions: `bootstrap/app.php` `report()` callback logs via `PortfolioLoggerService::api()`.

### Request correlation flow
1. Frontend `api.js` interceptor: `createRequestId()` → header `X-Request-ID` on every Axios call; stored on `config.metadata.requestId`.
2. Middleware preserves client ID or assigns UUID.
3. All `PortfolioLoggerService` entries include `request_id` in context.
4. Frontend errors shipped to backend include `requestId` (and use same header on log POST).

### Frontend logger (`resources/js/src/services/logger.js`)
- Methods: `logger.debug|info|warn|error`, `setLevel` / `getLevel` via `localStorage.logLevel`.
- Local console output respects level; **only `warn` and `error`** are queued to `POST /api/logs/frontend` (async, batched, non-blocking).
- Redacts password/token/secret patterns before ship.
- Do **not** use `console.log` in app code — use `logger`.

### Frontend error boundary
- `resources/js/src/components/ErrorBoundary.jsx` wraps authenticated app in `App.jsx`.
- Catches render errors, user-friendly fallback + reload; logs via `logger.error` → backend.

### Frontend logging API
- `POST /api/logs/frontend` (Sanctum auth required).
- Controller: `FrontendLogController` — max body 8KB, field validation, extra JSON cap 4KB, sanitization via `PortfolioLoggerService`.
- Payload: `level`, `message`, `url`, `userAgent`, `timestamp`, `requestId`, `extra` (e.g. `category`: API | UI | Validation | Navigation).

### Provider & scheduler logging
- `PriceFetchService`: logs failures, zero-row responses, fallback activation to `provider` channel with symbol, provider name, attempt, request time, failure reason.
- `DailyMarketDataJob`: start/end, processed/failed/skipped counts, per-stock failures.

### Error handling policy
- Never silent failures on API (Axios interceptor + toast + `logger.error`).
- Important failures always logged server- or client-side.
- Retries remain in price providers / HTTP layer where already implemented.

### Security
- Strip tags / newlines from messages; redact secrets in context JSON.
- No passwords, tokens, cookies, or API keys in logs.

### Tests
- `tests/Unit/PortfolioLoggerServiceTest.php` — level filter, request_id, sanitization.
- `tests/Feature/RequestCorrelationTest.php` — `X-Request-ID` header.
- `tests/Feature/FrontendLogControllerTest.php` — validation, auth, accept path.
- `tests/Unit/PriceFetchServiceTest.php` — provider logging mock.
- `tests/Feature/DailyMarketDataJobTest.php` — scheduler logging mock.

### Debugging (local)
```bash
cd app
php artisan test
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
tail -f storage/logs/provider-$(date +%Y-%m-%d).log
```
Set frontend verbosity in browser: `localStorage.setItem('logLevel','debug')`.

### Debugging (cPanel)
- File Manager or SSH → `app/storage/logs/`.
- Open today’s `laravel-*.log`, `frontend-*.log`, `provider-*.log`, `scheduler-*.log`.
- Search by `request_id` from browser Network tab (`X-Request-ID`) across files.
- Ensure `storage/logs` is writable; cron output is not duplicated to DB.

### Future logging changes
Document any new channel, endpoint, or retention change in this section.

### Related docs
- API: `app/API_DOCUMENTATION.md` → Frontend logs section

## Stock Validation Architecture (May 2026)

### Design principle
All validation is **local-first**. Live providers (NSE → Yahoo → Alpha Vantage) run only when the symbol is missing from `portfolio_stocks`. This minimizes API usage and improves UX.

### Schema (`portfolio_stocks`)

Migration `2026_05_29_000001_extend_portfolio_stocks_master.php` adds provider symbol fields and replaces unique(`symbol`) with unique(`symbol`, `exchange`).

| Column | Purpose |
|--------|---------|
| `symbol` | Normalized ticker only (e.g. `INFY`) — not `INFY.NS` |
| `exchange` | `NSE` or `BSE` |
| `name`, `isin`, `sector` | Display / metadata |
| `yahoo_symbol`, `alpha_vantage_symbol` | Provider-specific symbols |
| `is_active`, `is_benchmark` | Listing / NIFTY row |
| `last_verified_at` | Last provider or sync verification |

**Unique:** `(symbol, exchange)` — same ticker may exist on NSE and BSE separately.

### Symbol normalization

| Input | Stored |
|-------|--------|
| `INFY` | symbol=`INFY`, exchange=`NSE` (default) |
| `INFY.NS` | symbol=`INFY`, exchange=`NSE` |
| `INFY.BO` | symbol=`INFY`, exchange=`BSE` |

Provider suffixes are resolved by `ProviderResolverService`, not stored in `symbol`.

### Services
| Service | Responsibility |
|---------|----------------|
| `ProviderResolverService` | `normalizeSymbol()`, `yahooSymbol()`, `alphaVantageSymbol()`, `isMalformed()`, `applyProviderSymbols()` |
| `StockValidationService` | Stage 1 local lookup; stage 2 provider chain; `validateAndPersist()` upserts + backfill |
| `StockMasterSyncService` | NSE CSV import via `stocks:sync`; optional BSE; deactivate missing symbols |
| `StockResolverService` | Used by transactions — delegates to validation (no blind `Stock::create`) |

### Provider fallback flow
```
User input → normalize → local DB hit? → return valid
                      → miss → NSE (retry nse_retry_count)
                      → fail → Yahoo (meta quote)
                      → fail → Alpha Vantage GLOBAL_QUOTE
                      → fail → 422 / validation error
```

### Provider-specific symbol mapping
- NSE API uses normalized `symbol`
- Yahoo uses `yahoo_symbol` on stock row or `{SYMBOL}.NS` / `.BO`
- Alpha Vantage uses `alpha_vantage_symbol` or Yahoo-style symbol
- `PriceFetchService` passes per-provider symbols from `ProviderResolverService::providerSymbolsForStock()`

### Stock master sync
- Command: `php artisan stocks:sync` (`SyncStockMasterCommand`)
- Schedule: weekly Sunday 02:00 (timezone from settings / env)
- Source URL: `config('portfolio.stock_master.nse_equity_csv_url')` default NSE archive CSV
- EQ series only; duplicates logged and skipped; removed symbols set `is_active=false` (IDs preserved)

### API endpoints (auth required)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/stocks/search?q=&exchange=&limit=` | Local master autocomplete (min 2 chars) |
| POST | `/api/stocks/validate` | Explicit validation + persist |
| GET/POST/PUT | `/api/stocks` | List / create (validated) / update |

### Autocomplete UX
- `StockAutocomplete.jsx` — debounced search (300ms), min 2 chars, loading + empty states
- `TransactionsPage.jsx` — requires selection or validated symbol; no datalist free-text
- Unknown symbol on save triggers backend provider validation

### Rate limits & security
- `stock-search`: 60/min per user
- `stock-validate`: 15/min per user
- No provider calls on page load — only search (local DB), validate, add, and scheduled sync
- Input sanitization and malformed-symbol rejection via `ProviderResolverService::isMalformed()`

### Validation retry logic
- NSE: `nse_retry_count` from settings (default 3) with incremental backoff (`usleep`)
- Yahoo/Alpha: single attempt each after NSE exhaustion
- All failures logged to `provider` channel via `PortfolioLoggerService`

### Revalidation
- `last_verified_at` set on provider upsert and master sync
- `STOCK_REVALIDATION_DAYS` (default 7) in `config/portfolio.php` for future explicit revalidation job
- Standard `validate()` does **not** re-call providers when local row exists (performance)

### Known provider limitations
- NSE endpoints may block datacenter IPs; Yahoo/Alpha used as fallback
- Alpha Vantage rate limits (`Note` / `Information` responses treated as failure)
- BSE CSV sync disabled by default until `BSE_STOCK_MASTER_ENABLED` and URL configured

### Tests
- `tests/Unit/ProviderResolverServiceTest.php`
- `tests/Unit/StockValidationServiceTest.php` (Http::fake)
- `tests/Unit/StockMasterSyncServiceTest.php`
- `tests/Feature/StockSearchTest.php`
- `tests/js/debounce.test.mjs` (`npm run test:js`)

### Future stock validation changes
Document provider, schema, or UX changes in this section.

## Historical Data & Exploratory Analytics (May 2026)

### Hybrid history architecture

| Type | Detection (`StockTrackingService`) | Fetch behavior |
|------|-----------------------------------|----------------|
| Portfolio / tracked | Holdings qty &gt; 0, alerts, `tracking_active` metrics, past transactions | `ensurePortfolioHistory(buyDate)` → **buy − 3 months** → today; incremental gaps only |
| Exploratory | Not tracked | `ensureAnalyticsHistory(months)` → ~60d (1M) / ~150d (3M) buffer; cached permanently |

**Never delete** OHLCV when buy date changes or stock is sold. Wider local history is acceptable; gaps are not.

### Local cache strategy

- DB (`portfolio_stock_prices`) is the **primary** analytics source.
- Providers are **gap-fillers** via `PriceFetchService::fetchHistoricalWithFallback()` called only from `StockPriceHistoryService::fetchMissingHistory()`.
- Cache hit: missing ranges empty → log + skip HTTP (`cache_hit: true`).
- Cache miss: fetch only missing `{from,to}` segments; merge adjacent ranges; optional internal gap detection (`max_internal_gap_days`).

### Incremental fetch flow

1. `getAvailableHistoryRange(stock)` → min/max `price_date` or null.
2. `getMissingHistoryRanges(stock, requiredFrom, requiredTo)` → prefix/suffix gaps (+ internal gaps if &gt;7 calendar days between rows).
3. `fetchMissingHistory()` → provider fetch per range → `updateOrCreate` rows (no mass delete).

`PriceFetchService::syncStock()` delegates to `fetchMissingHistory()` (daily cron uses same path).

### Buy backfill

`BackfillHistoricalDataJob` → `ensurePortfolioHistory($stock, $buyDate)` (not raw buy date only).

### Nearest trading day

```sql
WHERE stock_id = ? AND price_date <= ? ORDER BY price_date DESC LIMIT 1
```

Implemented as `getCloseOnOrBeforeDate()`; uses `adjusted_close_price` fallback to `close_price`.

### RS & growth

- Growth %: `(close_end - close_start) / close_start * 100` using on-or-before dates.
- RS: `stock_growth% - benchmark_growth%` (simple difference per product spec).
- `RelativeStrengthService` delegates to `StockPriceHistoryService`.

### Exploratory analytics flow

1. User opens `/explorer` (**Stock Analytics Explorer**) → autocomplete → period toggle (1 / 3 / 6 months) → **Run Analysis**.
2. `POST /api/analytics/explore` → `ExploratoryAnalyticsService::analyze()` (single selected period; benchmark NIFTY50).
3. Validate symbol (local or provider) → ensure stock + NIFTY50 history caches.
4. Response includes `latest_close`, `period_closes.{Nm}` (stock/benchmark start & end closes), `growth_percent`, `benchmark_growth_percent`, `relative_strength`, and Recharts comparison bars.
5. UI shows latest close and period-ago close (2 dp via `formatTableMoney2`) for stock and NIFTY50, plus growth % for both and RS.
6. If any RS-required input is missing (`stock latest`, `index latest`, `stock period-ago`, `index period-ago`), Explorer shows a **Manual Relative Strength Input** form with all four fields; available values are prefilled, missing values left blank.
7. If analyze API returns validation/provider failure (422, including invalid symbol), API returns a user-facing `message` via `StockValidationUserMessage` (deduped provider errors; e.g. invalid symbol vs NSE 403 block). UI prefers `message` over raw `errors[0]`. NSE calls use `NseHttpClient` (homepage cookie warmup) to reduce HTTP 403 from anti-bot.
8. Manual form submission computes RS in-memory (`stock_growth - benchmark_growth`) and updates displayed RS for that view only; values are **not** stored/cached in DB.
9. NIFTY50 Yahoo symbol is `^NSEI` (`ProviderResolverService` always corrects benchmark row; not `NIFTY50.NS`).
10. Subsequent runs reuse local prices (minimal API).
11. Explorer Run Analysis button is disabled until a symbol is entered/selected.
12. When analysis fails and user uses manual RS inputs, Explorer now renders the same summary cards and Growth % chart from manual values; the manual form auto-collapses after successful calculate and can be expanded to edit/recalculate.

Rate limit: `analytics-explore` 20/min.

### Schema migration

`2026_05_30_000001_extend_stock_prices_history.php` — `adjusted_close_price`, `provider_source` (copied from `data_source`).

### Config (`config/portfolio.php`)

```php
'history' => [
    'portfolio_lookback_months' => 3,
    'analytics_buffer_days' => ['1m' => 60, '3m' => 150, '6m' => 210],
    'max_internal_gap_days' => 7,
],
```

### Tests

- `tests/Unit/StockPriceHistoryServiceTest.php`
- `tests/Unit/StockTrackingServiceTest.php`
- `tests/Feature/ExplorerAnalyticsTest.php`

### Future changes

Document cache, retention, or analytics behavior changes in this section.

## Historical snapshot rebuild architecture (May 2026)

Report: `portfolio-history-rebuild-report.md`.

### Philosophy

`portfolio_portfolio_snapshots` rows are **materialized, rebuildable cache** — not append-only cron logs. The dashboard growth chart answers: *“What was my portfolio worth on date D given all transactions known today?”*

### Services

| Service | Role |
|---------|------|
| `PortfolioHistoricalHoldingsService` | Replay transactions with `transaction_date <= D` → open qty + cost basis per stock |
| `PortfolioSnapshotRebuildService` | `calculatePortfolioStateForDate()`, `rebuildDateRange()`, `rebuildFromDate()`, `rebuildAfterTransactionChange()` |
| `StockPriceHistoryService` | Gap-fill OHLCV before rebuild (`fetchMissingHistory`) |
| `StockQuoteService` | `latestClose(stock, asOf)` — close on or before D |

### Formulas (any historical date D)

1. **Holdings(D)** — all transactions ≤ D (buys add price×qty cost basis + qty; sells reduce qty; avg-cost invested amount; fees excluded from cost basis).
2. **portfolio_value(D)** — `SUM(quantity(D) × latest_close_on_or_before(D))` per open holding.
3. **invested_value(D)** — `SUM(remaining_cost_basis(D))` for open holdings.
4. **unrealized_pnl(D)** — `portfolio_value(D) − invested_value(D)`.

Nearest trading day: `WHERE price_date <= D ORDER BY price_date DESC LIMIT 1` (weekends/holidays use prior session close).

### Rebuild triggers (mandatory)

After **any** transaction **create / update / delete**, `TransactionController` calls `rebuildAfterTransactionChange()` with:

`affected_start = MIN(old_transaction_date, new_transaction_date)` → rebuild **affected_start → today**.

Daily cron (`portfolio:daily-sync`) still refreshes **today** via `storeSnapshot()` → `rebuildDateRange(today, today)` but is **not** the sole source of history.

### Rebuild algorithm

1. Load ordered user transactions.
2. For each symbol, `fetchMissingHistory` from `min(first_tx, range_start)` → today (no silent skip).
3. Build trading-day list = distinct `price_date` in range for held symbols + today.
4. For each trading day: compute state → `updateOrCreate` snapshot.
5. Log start/end, counts, missing closes, duration (`SnapshotRebuild` category).

### API

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/portfolio/rebuild-history` | Manual full/partial rebuild (`from_date`, optional `to_date`) |

### Frontend

After transaction save/delete, `notifyPortfolioDashboardRefresh()` → Dashboard reloads `portfolio_growth` (latest 365 days, ascending). If snapshots are empty but transactions exist, `GET /dashboard` triggers a one-time lazy rebuild. Empty chart UI offers **Rebuild portfolio history** (`POST /portfolio/rebuild-history`).

### Tests

- `tests/Unit/PortfolioHistoricalHoldingsServiceTest.php`
- `tests/Feature/PortfolioSnapshotRebuildTest.php`

### Future snapshot / history changes

Document in this section and `portfolio-history-rebuild-report.md`.

## Authentication Architecture (May 2026)

### Stack (mandatory)
- **Laravel Sanctum** SPA mode (`bootstrap/app.php` → `statefulApi()`)
- **Session guard** (`web`) — not Bearer tokens in JS
- **HTTP-only cookies** + `axios` `withCredentials: true`
- **CSRF** — `GET /sanctum/csrf-cookie` before login; mutations send `X-XSRF-TOKEN` (cookie value) or `X-CSRF-TOKEN` (plain session token from API fallback)
- **Remember Me** — `Auth::attempt($credentials, $remember)`

### What we removed
- `localStorage.portfolio_token` and `Authorization: Bearer` headers
- API token returned from login/register responses

### Session configuration
| Setting | Default | Purpose |
|---------|---------|---------|
| `SESSION_DRIVER` | `database` | Multi-server friendly on cPanel |
| `SESSION_LIFETIME` | `43200` | ~30 days sliding idle timeout |
| `SESSION_SECURE_COOKIE` | `true` in production | HTTPS only |
| `SESSION_SAME_SITE` | `lax` | CSRF mitigation |
| `SESSION_PATH` | derived from `APP_URL` path | Subdirectory deploy (`/portfolio`) scopes cookies so they do not collide with other apps on the same domain |
| `SESSION_DOMAIN` | `.your-domain.com` in production | Same login cookies on `www` and apex host |
| `SANCTUM_STATEFUL_DOMAINS` | localhost + app host | Sanctum treats requests as SPA |

### Frontend flow
1. `AuthProvider` mounts → `ensureCsrfCookie()` then `GET /api/auth/me` restores user or shows login.
2. Login page → `ensureCsrfCookie({ force: true })` clears stale `XSRF-TOKEN` at `/` and `/portfolio`, hits `/sanctum/csrf-cookie`, waits up to ~5s for cookie; if still unreadable (some mobile browsers / stale cookies), falls back to `GET /api/auth/csrf-token` and sends plain token via `X-CSRF-TOKEN`.
3. On `401` while logged in → `portfolio-unauthorized` → inline “session expired” on login (no toast); save path in `sessionStorage`. Initial `/auth/me` 401 (first visit / not logged in) is silent. `419` triggers CSRF retry once, then toast.
4. **Subdirectory URLs:** JS resolves API paths from `<meta name="app-base">`, `window.__LIDO_APP_BASE__`, or `/portfolio` in the URL. `api.js` sets `baseURL` on every request (not only at import time).
5. After login → redirect to saved path (`auth/redirect.js`).

### API (session guard)
- `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`
- `GET /api/auth/me` — **guest-safe** (returns `{ user: null }` when logged out; not behind `auth:sanctum`)
- `GET /api/auth/csrf-token` — **guest-safe**; returns `{ token }` (session CSRF token) when cookie is not readable client-side
- `GET /api/auth/sessions`, `POST /api/auth/sessions/logout-others`, `DELETE /api/auth/sessions/{id}` — auth required

### Active sessions
- `SessionManagementService` reads `sessions` table (device label from user-agent).
- Settings UI: list sessions, revoke one, log out all other devices.
- `DELETE /api/auth/sessions/{id}` — cannot revoke another user's session.
- Multi-device simultaneous sessions allowed; `personal_access_tokens` table retained for legacy but login does not issue API tokens.

### Security
- Login/register: `throttle:login` (10/min/IP).
- `AuthAuditService` logs success/failure with masked email (no passwords/cookies).
- Compatible with future PIN/biometric/2FA (not implemented).

### Tests
- `tests/Feature/AuthSessionTest.php`
- `tests/js/auth-redirect.test.mjs`
- All feature tests use `$this->actingAs($user)` instead of `Sanctum::actingAs`.

### HTTPS / production cookies
1. Force HTTPS on the domain (cPanel AutoSSL + redirect).
2. `.env`: `APP_URL=https://your-domain/portfolio` (include subdirectory path), `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.your-domain.com`, `SANCTUM_STATEFUL_DOMAINS` = hostnames without scheme.
3. `SESSION_PATH` auto-derives from `APP_URL` (`/portfolio`); run `php artisan config:cache` after `.env` changes.
4. Run `php artisan migrate` so `sessions` table exists.
5. Serve SPA and API from the same origin (`app/public` document root or `/portfolio` entry).
6. **419 / CSRF mismatch on some devices:** often stale or colliding `XSRF-TOKEN` at cookie path `/`, or mixing `www` vs apex. Deploy latest `csrf.js` + `/api/auth/csrf-token` fallback; run `config:cache`; user clears site cookies once; always open the same hostname (`https://www.lidoalexion.com/portfolio/`). SSL warning in the address bar blocks `Secure` cookies — fix AutoSSL/redirect first. Upload table: `deploy/DEPLOY.md` §7 “Login loops / 419”.

See also `DEPLOYMENT_CPANEL.md` § HTTPS.

### Future authentication changes
Document in this section (PIN / 2FA not implemented; architecture allows future guards).
