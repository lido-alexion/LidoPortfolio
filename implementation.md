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
- Web app runs as a React SPA mounted from Laravel view `resources/views/app.blade.php`. Favicon: `public/favicon.ico` with `<link rel="icon" href="{APP_PATH}/favicon.ico">` in `app.blade.php`; production copy at `public_html/portfolio/favicon.ico` (included in `prepare-upload.ps1` staging).
- API auth is **session-based** (Sanctum SPA cookies), not Bearer tokens in `localStorage`.

## Local development runbook (agent / future sessions)

Use this section to bring the project up again on a Windows dev machine. Human-oriented summary also lives in root `README.md`.

### What must run separately

| Service              | Required?                         | Typical setup on Windows                                  | Notes                                                                                                                                                                 |
| -------------------- | --------------------------------- | --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **MySQL**            | **Yes**                           | EasyPHP / XAMPP / WAMP MySQL, standalone MySQL, or Docker | App uses `portfolio_*` tables; can share an existing DB (e.g. `lido_db`).                                                                                             |
| **PHP web**          | **Yes** (one of)                  | `php artisan serve` **or** Apache/Nginx                   | Default dev URL: `http://127.0.0.1:8001`. Document root for Apache: `app/public`.                                                                                     |
| **Node.js**          | Dev UI hot-reload                 | Installed globally                                        | Only for `npm run dev` / `npm run build`.                                                                                                                             |
| **Apache (EasyPHP)** | **No** (if using `artisan serve`) | EasyPHP control panel                                     | Optional. Use when you prefer vhost over built-in PHP server.                                                                                                         |
| **Queue worker**     | Optional locally                  | `php artisan queue:listen`                                | `QUEUE_CONNECTION=database`; needed for async jobs if not using `sync`.                                                                                               |
| **Scheduler**        | Optional locally                  | `php artisan schedule:work` or OS cron                    | `portfolio:daily-sync` (holdings prices); `portfolio:sync-universe-prices` (NSE universe OHLCV batches); `portfolio:send-notifications` per `notification_schedules`. |
| **Redis**            | No                                | —                                                         | Not used by default (`CACHE_STORE=database`).                                                                                                                         |
| **Vite dev server**  | Optional                          | `npm run dev`                                             | Hot reload for React; omit if you ran `npm run build` and only use `artisan serve`.                                                                                   |

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

| Terminal     | Command                                          |
| ------------ | ------------------------------------------------ |
| 1            | `php artisan serve --host=127.0.0.1 --port=8001` |
| 2            | `npm run dev`                                    |
| 3 (optional) | `php artisan queue:listen`                       |
| 4 (optional) | `php artisan schedule:work`                      |

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
php artisan portfolio:daily-sync     # manual daily prices + portfolio snapshots (holdings)
php artisan stocks:sync              # NSE equity master CSV import
php artisan portfolio:sync-universe-prices --mode=backfill --all   # one-time ~1y OHLCV for full NSE universe
php artisan portfolio:sync-universe-prices --mode=daily            # one batch of universe incremental sync
php artisan test                     # PHPUnit (uses sqlite in-memory)
npm run test:js                      # frontend unit tests
npm run build                        # production JS/CSS bundle

# API smoke (server must be on :8001)
PowerShell -ExecutionPolicy Bypass -File tests\Feature\api_smoke.ps1
```

### Tests vs local MySQL

`php artisan test` uses **SQLite in-memory** (`phpunit.xml`); it does not require MySQL. Local browsing and manual testing use MySQL from `.env`.

### Alert policies (Jul 2026)

Per-portfolio rules in `portfolio_alert_policies`; evaluated after daily price sync and via **Run policies now** (`POST /api/alert-policies/evaluate`). Optional `alert_definition` text (human-readable summary shown in policy list). Universe: **Holdings** only (extensible). Conditions use enriched holding fields vs column / derived formula (`{{column}}` tags + `+ - * / ( )`) / constant. Generated alerts: `alert_type=policy`, `instance_key` = `{user_id}-{profile_id}-{stock_id}-{policy_id}`, `condition_display`, `action_suggested`, `context_json` (`{ text }` rendered from optional `context_template` with same `[[ ]]`, `<< >>`, `{{column}}` syntax as message; legacy `context_columns` array still supported). Dedup on active `instance_key`. UI: Settings → Portfolio → **Manage alert policies** (`/settings/alert-policies`). **Built-in stoploss alert generation removed (Jul 2026):** `StoplossService` updates trailing-stop metrics only; alerts come from policies (or manual fixtures in tests). `GET /api/dashboard` returns active alerts under `alerts` (was `stoploss_alerts`). `AlertService::getActiveForProfile()` backs dashboard + `GET /api/alerts`. **API errors:** production JSON 500/503 responses include actionable `message` + `request_id` (not generic "Server Error"); evaluate checks schema before running.

**Evaluation logging & report (Jul 2026):** `AlertPolicyEvaluationService` logs to app channel category `AlertPolicy` via `PortfolioLoggerService::alertPolicy()` — profile start/finish (info), each holding (debug) with outcome. `POST /api/alert-policies/evaluate` returns `data.details[]` (up to 100 rows): `policy_name`, `stock_symbol`, `outcome` (`generated`, `condition_not_met`, `missing_left`, `missing_right`, `duplicate_active`, `formula_error`, `error`), `left`/`right` numeric operands, `summary` text. Alert policies page shows **Last evaluation** table after **Run policies now**. **Bug fix:** `FormulaEvaluator` used `/` as `preg_match` delimiter while `/` also appeared in the allowed-character class, causing `preg_match(): Unknown modifier '('` on every derived-formula evaluation — fixed with `#` delimiters. **Alert policy form UX:** `ColumnTagEditor` removes one tag occurrence at a time (not all duplicates); **Add column…** picker uses highlighted `.column-tag-picker` style; constant compare uses 2-decimal `NumberInput`; message template always shows column picker. **Alert message formatting:** `AlertMessageRenderer` resolves innermost `[[...]]` / `<<...>>` blocks first (no infinite loop on failure). `[[expr]]` supports math expressions (2-decimal thousands format). `<<expr>>` evaluates math (compact number; commas stripped if nested after `[[ ]]`). Plain `{{column}}` display tags last. Tips under message field in policy form. **Save validation:** `AlertPolicyTemplateValidator` checks delimiter balance, known columns, then dry-runs message (and derived formula when applicable) against the first open holding; API returns `message_template` / `compare_formula` field errors; form highlights invalid fields. **Context details:** optional multiline `context_template` (labeled column picker adds `Label: {{column}}` per line); rendered to `context_json.text` on alerts; dashboard Context column uses `white-space: pre-line`.

### Pending deploy (2026-06-21 — Knowledge Board + Explorer + profile fixes)

**Includes:** **Knowledge Board** — notes/tags CRUD, Tiptap editor, search/filter/sort, manual drag order (`localStorage` per portfolio); sort mode (`portfolio_knowledge_board_sort`), bulk select, clipboard export, tag management page; main nav tab **Knowledge**. Full-width cards with hover overlay toolbar; streamlined filter toolbar. **Explorer** — universe-cache-only analytics (no on-demand provider fetch); analyzes **1M / 3M / 6M / 1Y**; historical price cards, four RS cards, bar chart, and 1-year normalized % gain line chart. **Profile menu light theme:** account name/email visible on hover; profile photos use app-relative `/api/profile/photo` URL. **Hotfix:** `GET /api/knowledge-board/notes?archived=false` no longer 422 (`$request->boolean('archived')`).

**Migration required (first Knowledge Board deploy only):** `2026_07_05_000001` (`portfolio_knowledge_notes`, `portfolio_knowledge_tags`, `portfolio_knowledge_note_tag`). Skip migrate if tables already exist. **Explorer changes need no migration.**

**Build:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-DNP7RVSO.js`**, CSS **`lido-app-BcoBxG1q.css`**).

**Upload (cPanel File Manager or FTP):**

| Local | Server |
|-------|--------|
| `deploy/staging/lidoportfolio/*` | `public_html/lidoportfolio/` (merge — app, routes, migrations, views, bootstrap, composer.json, `public/build/`) |
| `deploy/staging/portfolio/build/` | `public_html/portfolio/build/` (replace entire folder) |
| `deploy/staging/lidoportfolio/public/build/` | `public_html/lidoportfolio/public/build/` (replace entire folder) |
| `deploy/staging/portfolio/cpanel-migrate.php` | `public_html/portfolio/cpanel-migrate.php` |

**Already uploaded Knowledge Board but notes list 422?** Upload only `deploy/staging/lidoportfolio/app/Http/Controllers/Api/KnowledgeBoardNoteController.php` → `public_html/lidoportfolio/app/Http/Controllers/Api/` — no build or migrate.

**Migrate (if not done):** `https://www.lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — confirm `2026_07_05_000001` ran; **delete** `cpanel-migrate.php` after success.

**Smoke:** Hard refresh (Ctrl+Shift+R) → **Explorer** → run analysis → see 3 RS cards (green/red) + 6-bar chart → **Knowledge** tab loads notes (no 422) → Edit/save note with tags → **Profile** menu readable in light theme.

### Pending deploy (2026-06-21 — Knowledge Board v1.0 + UI polish) — superseded by bundle above

### Pending deploy (2026-06-21 — pattern guide + OHLCV scanners + deep links) — superseded by 2026-07-01 bundle above

**Includes:** Patterns nav tab + educational guide with SVG sketches; pattern detection on cached OHLCV (JS + PHP); `GET /api/patterns/scan`; dashboard **Pattern signals (holdings)** table with pattern name → `/patterns#id`; watchlist **Scan my watchlist**; OHLCV chart **Possible patterns on this window**; new candlesticks (harami, piercing line, dark cloud cover); **deep links** `/patterns#hammer` auto-switch chart/candle section, expand card, scroll into view.

**Upload:** `deploy/prepare-upload.ps1` → staging built (JS **`app-BXGjdmr7.js`**, CSS **`lido-app-C3UwQt67.css`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders. **No migration.**

**Smoke:** Hard refresh → **Patterns** tab shows sketches; open `/patterns#hammer` (candle section) and `/patterns#double_top` (chart section); **Dashboard** → click pattern name in signals table; **Watchlist** → Scan my watchlist; holding OHLCV → patterns under chart.

### Pending deploy (2026-06-21 — toast crash fix on universe price sync)

**Fix:** `UniversePriceSyncPage` `showToast(message, variant)` — was passing object and crashing React on batch complete.

**Upload:** replace **both** `build/` folders from `deploy/staging/` (JS **`app-BEYJcvch.js`**). No migration.

**Smoke:** Settings → Universe price sync → Run backfill batch → green toast, no React error overlay.

### Pending deploy (2026-06-21 — UI: footer, bulk CSV, transactions layout)

**Includes:** Auto-hide footer (show at scroll bottom or cursor near bottom edge); transactions form stacked above table; **Bulk (CSV)** import on Transactions page.

**Upload:** `deploy/prepare-upload.ps1` → replace **both** `build/` folders only (JS **`app-BS26khuH.js`**; no migration).

**Smoke:** Transactions → **Bulk (CSV)** → paste sample → Save all; footer appears at page bottom / mouse to lower edge.

### Pending deploy (2026-06-21 — universe sync, CSRF, password reset, htaccess)

**Includes:** NSE universe OHLCV sync (CLI + admin API + `/settings/universe-price-sync`); mobile CSRF fix (`csrf-token` + 419 retry); apex→www `.htaccess`; admin password-reset links + guest `/reset-password/:token`; user-mgmt UX fixes; boot panel / viewport CSS mitigations.

**Migration required:** `2026_07_03_000001` (`portfolio_password_reset_links`).

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-B3HlGFOg.js`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders; upload `portfolio/.htaccess` and root portfolio snippet if not already applied.

**Migrate:** `https://www.lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Login on mobile (use `www` URL); Settings → **Universe price sync** → Sync stock master → Run backfill batch; Settings → **Manage users** → password reset link; guest reset page.

### Pending deploy (2026-07-02 — mobile CSRF login fix) — superseded by 2026-06-21 bundle above

**Fix:** After `/sanctum/csrf-cookie`, always load token from `GET /api/auth/csrf-token` and send `X-CSRF-TOKEN` (stops stale `XSRF-TOKEN` at path `/` on mobile). API auto-retries once on `419` after forced CSRF refresh.

**Upload:** `deploy/staging/` (JS **`app-avmKe_to.js`**). Replace **both** `build/` folders only (no migration).

**On affected device:** Clear site data for `lidoalexion.com` once, then hard refresh and login.

### Pending deploy (2026-07-02 — alert policies polish + remove built-in stoploss alerts)

**Includes:** `context_template` + `alert_definition` on policies; dashboard `alerts` key (was `stoploss_alerts`); built-in `stoploss_triggered` auto-generation removed (`StoplossService` metrics only); `AlertService` for active alert listing.

**Migrations (if not already applied):** `2026_07_01_000001`, `2026_07_01_000002`, `2026_07_02_000001`, `2026_07_02_000002`.

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (JS **`app-C95fw9u-.js`**). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate:** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Hard refresh → Dashboard alerts load; Settings → **Manage alert policies** → create/edit policy with context template → **Run policies now**; confirm no new `stoploss_triggered` rows from daily sync (policy alerts only).

### Pending deploy (2026-07-01 — alert policies)

**Migration required:** `2026_07_01_000001` + repair `2026_07_01_000002` (adds missing `portfolio_alerts` policy columns if first migration partially applied).

**Upload:** `deploy/staging/` (JS `app-Czrb7gYm.js`). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate:** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** Settings → Portfolio → **Manage alert policies** → create policy → **Run policies now** → dashboard shows new alerts with Condition/Action columns.

### Pending deploy (2026-06-30 — portfolio delete + stale-tab recovery) — applied on production

**Migration (if not already applied):** `2026_06_30_000001_add_deleted_at_to_portfolio_profiles`.

**Upload:** `deploy/prepare-upload.ps1` → `deploy/staging/` (built — JS `app-BhkLY2PL.js`). Merge `staging/lidoportfolio/` → `public_html/lidoportfolio/`; replace **both** `build/` folders.

**Migrate (first time only):** `https://lidoalexion.com/portfolio/cpanel-migrate.php?token=Lido` — delete script after success.

**Smoke:** `/portfolios` — no Delete on default or active-in-tab; delete works after switching tab; second tab recovers after delete in another tab (navigation or focus).

### Pending deploy (2026-06-29 — multi-portfolio) — applied on production

**One migration required:** `2026_06_29_000001_create_portfolio_profiles_and_migrate_data` — creates `portfolio_profiles`, migrates `portfolio_user_settings` → `portfolio_profile_settings`, backfills `profile_id` on transactions/holdings/snapshots/alerts, drops `user_id` from those tables. Uses **short index names** (`ppt_prof_stock_date_idx`, etc.) for MySQL 64-char identifier limit; migration is **idempotent** so a failed partial run can be retried safely.

**Also upload:** `bootstrap/app.php` (middleware), `composer.json` (helpers autoload; `bootstrap/app.php` also requires helpers directly).

**Deploy:** `deploy/prepare-upload.ps1` → `deploy/staging/`; run `cpanel-migrate.php?token=Lido`; delete script after success. Hard-refresh browser after upload (portfolio switcher in header).

**Smoke:** login → header shows portfolio dropdown → `/portfolios` create/rename → switch portfolio in two tabs shows different data.

### Pending deploy (2026-06-21 batch) — superseded if 2026-06-29 applied

**One migration required:** `2026_06_21_000001_add_total_fees_to_portfolio_holdings` — adds `portfolio_holdings.total_fees` and recalculates all holdings (fee-exclusive avg buy/invested).

**Deploy checklist:** `deploy/RELEASE-2026-06-21.md` (build, upload paths, `cpanel-migrate.php`, smoke tests).

**No** new env vars, routes files, or `composer.json` changes in the 2026-06-21 batch alone.

### Related docs

| Doc                             | Purpose                                                                 |
| ------------------------------- | ----------------------------------------------------------------------- |
| `README.md`                     | Short quick start                                                       |
| `deploy/DEPLOY.md`              | Production deploy (GoDaddy `/portfolio`)                                |
| `deploy/RELEASE-2026-06-21.md`  | Pending release: migration `total_fees`, holdings/transactions UI batch |
| `DEPLOYMENT_CPANEL.md`          | Generic cPanel pointer → `deploy/DEPLOY.md`                             |
| `DEPLOYMENT_VALIDATION_PLAN.md` | Pre/post deploy checks                                                  |
| `app/API_DOCUMENTATION.md`      | REST API                                                                |

## Technical Decisions

- Local environment templates aligned to MySQL-based setup.
- Added `.env` template specifically for MySQL usage in `app/.env.mysql.template`.
- **DB credentials:** `config/load_db_config.php` finds `config/DBConfig.php` by walking up directories (outermost first so `/home/USER/config/DBConfig.php` wins over `lidoportfolio/config/DBConfig.php` if a dev template was uploaded). Supports **class `DBConfig`** or **define()** constants. Optional `DB_CONFIG_PATH` in `.env`. When `DBConfig.php` is loaded, its values **take precedence over** `.env` `DB_*`. Delete `bootstrap/cache/config.php` if DB still shows `root` after fixes. `deploy/cpanel-diagnose.php` flags app-local `DBConfig.php` and cached config.
- **GoDaddy migrate 1142:** shared MySQL user may lack `INDEX` on existing tables. `2026_05_29_000001_extend_portfolio_stocks_master` adds columns always; composite unique on `(symbol, exchange)` is skipped when error 1142 (keeps `symbol` unique). See `deploy/FIX-MYSQL-INDEX-PRIVILEGES.md`.
- To share a single MySQL database with an existing project, this app uses isolated table names:
  - `portfolio_users`, `portfolio_stocks`, `portfolio_transactions`, `portfolio_holdings`
  - `portfolio_stock_prices`, `portfolio_stock_metrics`, `portfolio_portfolio_snapshots`
  - `portfolio_alerts`, `portfolio_settings`, `portfolio_profile_settings`, `portfolio_profiles`, `portfolio_system_logs`
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
- **Telegram notifications** are separate from **data syncing time** (`cron_time`). Per-profile `notification_schedules` (JSON in `portfolio_profile_settings`) drive `portfolio:send-notifications` — Laravel scheduler registers the **union** of all profiles' times; each run sends only to profiles whose schedule includes that slot. `AlertNotificationService` sends **that profile's** active alerts to **that profile's** Telegram credentials; when global **ping when clear** is enabled, empty portfolios still get a “no active alerts” confirmation at schedule time (testing). Stoploss triggers only **persist** alerts (no immediate Telegram). Settings UI: add/remove notification times; **Test telegram integration** sends **only the active profile's** alerts.
- **Per-profile settings (Jun 2026):** `portfolio_profile_settings` (`profile_id` + `setting_key`) stores per portfolio: `telegram_bot_token`, `telegram_chat_id`, `notifications_enabled`, `default_stoploss_percent`, `notification_schedules`. Replaces `portfolio_user_settings`. `GET/PUT /api/settings` merges global app settings (cron, fees, API keys, log level) with the **active profile's** personal settings. **Trailing stop %** in holdings/alerts uses the active profile's `default_stoploss_percent`.
- **Multi-portfolio architecture (Jun 2026):** `portfolio_profiles` (`user_id`, `name`, `is_default`). Portfolio data tables use `profile_id`: `portfolio_transactions`, `portfolio_holdings`, `portfolio_portfolio_snapshots`, `portfolio_alerts`. Migration `2026_06_29_000001` creates profiles (one default per user), migrates settings and backfills `profile_id`, drops `user_id` from portfolio tables. **`ResolveActivePortfolio` middleware** resolves active profile from **`X-Profile-Id`** header (alias `X-Portfolio-Id`) or `portfolio_id` query param, else user's default; **`activePortfolio()`** helper (`app/Support/helpers.php`). **`PortfolioProfileService`:** `createDefaultForUser`, `setDefault`, `listForUser`, `defaultForUser`. **`GET/POST/PUT/DELETE /api/portfolios`**, `POST /api/portfolios/{id}/set-default`. `GET /api/auth/me` includes `default_portfolio_id`. **Frontend:** `PortfolioProvider` + `sessionStorage` key `portfolio_active_id`; `api.js` sends `X-Profile-Id`; **`/portfolios`** management page; data pages listen for `portfolio-changed`.
- **Multi-portfolio UI (Jun 2026):** Header switcher shown only when user has 2+ portfolios (10px left margin); profile menu links to `/portfolios`. Settings removed from top tabs (footer nav only). Settings page scope tabs: **Global** (admin), **Portfolio**, **Account** (sessions + prominent management links). Portfolio names: letters, numbers, spaces, hyphen, underscore only (client + API validation). **Manage Portfolios** (`/portfolios`) uses theme-aware `.contentPane .card` / `.list-group-item` (no hardcoded `bg-dark`); **Set default** uses `btn-outline-primary` so label is visible in light and dark themes. **Delete portfolio** (`DELETE /api/portfolios/{id}`): confirm dialog in UI; portfolio row soft-deleted (`deleted_at` migration `2026_06_30_000001`); related profile data (transactions, holdings, snapshots, alerts, profile settings) **hard-deleted** in the same transaction — **no restore** in app (soft delete is audit-only on `portfolio_profiles`). Reusing a deleted portfolio **name** creates a new profile id with empty data. **Default** and **active-in-tab** portfolios cannot be deleted (UI + API when `X-Profile-Id` matches). **Stale tab recovery:** `GET/POST /portfolios` omit `X-Profile-Id`; on `404 Portfolio not found` for data APIs, `portfolioRecovery.js` re-bootstraps active portfolio and retries once; `BroadcastChannel` + `visibilitychange` refresh portfolio list when another tab deletes a portfolio.
- **Admin roles (Jun 2026):** `portfolio_users.is_admin` (boolean, default `false`). Migration `2026_06_27_000001` sets `is_admin = true` for all accounts that already exist at migrate time. `GET /api/auth/me` includes `is_admin`. Admins only: user management, invites, global settings write, stock master `POST/PUT`, daily sync, backfill, sync logs. Settings → **Manage users** (`/settings/users`).
- **Invite-only registration (Jun 2026):** Open `POST /api/auth/register` removed. Admins create invites (`portfolio_user_invites`, 72h expiry) via `POST /api/invites`; UI provides **Copy link** and **Copy message** (full email text). Guest routes: `GET /api/invites/{token}`, `POST /api/invites/accept` → `/invite/:token` SPA page sets password and signs in. Expired tokens deleted on access with contact-admin message. Login with pending invite returns `invite_setup_required` + `invite_token` → redirect to invite page. Admin can regenerate (new token/expiry) or revoke (delete).
- **Admin password reset links (Jul 2026):** For forgotten passwords on existing accounts. `portfolio_password_reset_links` (`user_id`, 72h token, `used_at`). Admin: Settings → **Manage users** → **Password reset links** card (user picker + table) or **Reset password** per user row; copy link/message, regenerate, revoke (same UX as invites). Guest: `GET /api/reset-password/{token}`, `POST /api/reset-password/accept` → `/reset-password/:token` SPA (new password only, no current password); signs in on success. One pending link per user.
- **Admin-only application settings:** Global keys in `portfolio_settings` (cron, fees, NSE retry, Alpha Vantage key, backend log level, sync log retention) — read/write via `GET/PUT /api/settings` for admins only. Non-admins get per-user settings + read-only `cron_timezone`. **Settings → Global (Jul 2026):** admins can edit **`cron_timezone`** (scheduler timezone; default `Asia/Kolkata`) alongside **Data syncing time** — controls daily sync, notification schedule slots, and universe maintenance window (19:00–23:45). `daily_market_sync` omitted from dashboard API for non-admins. `Alert` route binding scoped to owner. Stock catalog `POST/PUT /api/stocks` admin-only (`POST /stocks/validate` persist still allowed for transaction flow).
- Improved provider resilience with per-provider retries, backoff, and structured attempt-level failure logging.
- Dashboard UI expanded with top gainer/loser, **Alerts** card (full width; when empty, card body shows “No active alerts” only — no table headers), **Relative Strength** and **Allocation** tables side by side (`col-lg-6` each) on wide viewports and stacked full width on narrow, relative-strength trend widgets (vs **NIFTY50** benchmark: stock period return % − index return %; cached in `portfolio_stock_metrics`). Relative Strength table: **Avg. strength** = mean of available 1M/3M/6M values (whole %); default sort descending on that column. Dashboard **Alerts** table: **Context** column shows `context_json` label/value pairs from policy alerts; **Acknowledge** is a separate last column (always visible, not hideable); message column is text only.
- **Alert expiration** (`portfolio_alerts.profile_id`, `expired_at`, `expiration_reason`): alerts are **per profile** (not global per stock). Policy alerts dedupe on `instance_key`; legacy `stoploss_triggered` rows may still exist in DB but are no longer auto-created. `portfolio_stock_metrics` / holdings `stoploss_summary` still track since-buy peak and trailing stop for display and policy conditions (`latest_close` vs `trailing_stop_price` via `HoldingPresentationService`). `firstBuyDateForCurrentPosition` resets after a full exit. Dashboard alerts table shows **Date** (`created_at`). `GET /api/alerts` / dashboard `alerts` filter by active `profile_id`. Expiration: manual clear all + acknowledge (own alerts only); hourly 100h max age; new trading day after daily sync; **full sell** expires only that profile's alerts (`expireForProfileStockIfUnheld`). Active = `expired_at` IS NULL.
- Dashboard summary cards (Portfolio Value, Invested, Gain/Loss, XIRR, Top Gainer/Loser): responsive grid — **3** per row (`col-lg-4`) on wide viewports, **2** (`col-md-6`) on medium, **1** (`col-12`) on narrow. **Top Gainer** / **Top Loser** cards include an icon-only period toggle (hover titles: **All time** / **Latest day**); preference stored in `localStorage` key `portfolio_dashboard_top_mover_period`. **All time** ranks holdings by unrealized gain % since purchase (`unrealized_profit / invested_amount`); **Latest day** ranks by OHLCV day-over-day % (latest two `portfolio_stock_prices` closes per stock). Each card shows symbol plus signed % in parentheses (2 dp, green/red). API: `GET /api/dashboard` → `top_movers.all_time|latest_day.gainer|loser` with `{ symbol, name, stock_id, change_percent }`; legacy `top_gainer` / `top_loser` mirror all-time entries. Allocation **Market Value**, growth-chart axis/tooltips use `formatInrWhole` / `formatInrCompactWhole` (no paise; `₹ ` + amount, lakh grouping). **Portfolio Growth** line chart (portfolio vs invested value); **Unrealized P/L** line chart directly below (`portfolio_value − invested_value` per snapshot, zero baseline). Holdings/Explorer use `formatInr` (2 dp) via `formatTableMoney2`.
- Dashboard **Sync prices for today** → `POST /api/sync/daily` (`force: true` from UI when re-syncing same day). Skips without `force` if already synced today (cron-safe). Button stays enabled as **Sync again today** after first success; shows muted “Synced for …” hint.
- **Dashboard client cache (Jul 2026):** `GET /dashboard` + `GET /patterns/scan` responses cached in `localStorage` per user + active portfolio (`utils/dashboardCache.js`, key `portfolio_dashboard_cache_v1_{userId}_{profileId}`). **24h TTL**; unused `nifty_comparison.prices` stripped before store. On revisit within TTL, dashboard renders instantly with no API calls. **Refresh dashboard** button (left toolbar) clears cache and refetches; **Last refreshed {time}** label sits to its right when serving from cache. Cache invalidated on logout (`clearAllDashboardCaches`), transaction mutations (`notifyPortfolioDashboardRefresh`), and portfolio switch (`notifyPortfolioChanged`). Dashboard mutations (acknowledge/clear alerts, sync, rebuild) clear cache and refetch.
- **Production deploy:** Canonical steps in **`deploy/DEPLOY.md`** (first deploy + code updates), including **§2.1 Build folders explained** (one PC build, two server copies, what the browser loads).
- **Production subdirectory** (`https://lidoalexion.com/portfolio`): `APP_URL` includes path; build with `VITE_APP_BASE=/portfolio/build/`; upload `public/build/` to **`lidoportfolio/public/build/`** and **`portfolio/build/`**. Vite tags use **root-relative** paths (`AppServiceProvider::createAssetPathsUsing`) so `www` and apex both work. Delete `public/hot` on server. Troubleshooting: `deploy/DEPLOY.md` §7, `implementation.md` → Production learnings.
- Dashboard cards: **Portfolio Value** / **Total Gain/Loss** green when portfolio &gt; invested, red when less, default text when equal; **XIRR** green/red by sign. Allocation table: **Market %** (holding market value ÷ portfolio value) and **Invested %** (holding invested ÷ total invested); whole numbers; &gt;15% orange (`text-allocation-elevated`), &gt;20% red. **Allocation** card: **Table** / **Visual** toggle (preferences `portfolio_dashboard_allocation_view`, `portfolio_dashboard_allocation_mobile_metric` in `localStorage` via `dashboardPrefs.js`; restored on load); visual mode shows donut charts for market value and invested (side by side on `lg+`, single chart on narrow with invested/market switcher); table mode unchanged with column menu.
- Transactions UI now supports edit/update flow in addition to create/delete. **Fix (Jun 2026):** update/delete auth compared `user_id` with strict `!==`, so string vs int IDs from MySQL caused false 403; `Transaction` route binding now scopes to `activePortfolio()->transactions()` (SQL ownership check). FE `api.js` maps generic auth errors to a full sentence. **Delete buy guard:** before deleting a buy, `HoldingsCalculationService::assertReplayValidAfterDeleting()` dry-runs the ledger replay; if orphan sells would break recalc, API returns 422 with guidance to delete sell transaction(s) first. **Squared-off sells (Jul 2026):** `portfolio_transactions.realized_pl` and `squared_off_fees` store FIFO realized P/L (price spread only, no fees in P/L) and proportional squared-off fees (sell fees + matched buy fees by quantity) on **sell** rows; recomputed on create/update/delete via `TransactionRealizationService`. Backfill: `php artisan portfolio:backfill-sell-realizations` (`--profile=` optional) or **`deploy/cpanel-backfill-sell-realizations.php`** (no SSH; delete after use). Squared-off table (`/transactions/closed`) shows **Realized P/L** and **Fees** columns for sells.
- Holdings UI shows highest close since buy, trailing stop, unrealized P/L, and links to OHLCV price history screen. `GET /holdings` enriches each row with `unrealized_profit`, `unrealized_gain_percent`, and `stoploss_summary.daily_change_percent` (latest vs preceding OHLCV close).
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
- Tabular UIs: **TanStack Table** via `DataTableCard` / `DataTable.jsx` (columns icon in card header; show/hide + reorder panel; **drag column edges to resize** — widths persisted in `localStorage` with order/visibility via `tableColumnPrefs.js`, reset via **Reset columns**). **Fit columns** toolbar button (expand icon beside columns menu) redistributes visible column widths proportionally to fill the table container (`distributeColumnWidths` in `tableColumnPrefs.js`, target = container width − 1px to avoid border overflow scroll) — removes empty right-edge gap or horizontal scrollbar until the user resizes again. Pass `loading` while fetching — shows a spinner row instead of the empty-state message until data arrives (`TableLoadingRow`). Used on holdings, transactions, closed transactions, dashboard tables (alerts, relative strength, allocation, pattern signals), watchlist scan results, stock prices OHLCV, and corporate-action preview. Plain HTML tables on Settings and sync logs are not resizable.
- **Stocks calendar (Jul 2026):** per **active portfolio** market-event calendar for F&amp;O expiry, options expiry, and custom recurring dates. Main nav **Calendar** → `/calendar` (`CalendarPage.jsx`). **Recurrence types:** one-time, daily, weekly (day of week), monthly (day of month), monthly (nth weekday e.g. last Thursday), yearly (fixed date), yearly (nth weekday of month). Each event has configurable **color** (used on calendar day markers). **Calendar UI:** all months of the current year in a responsive grid; if current month is Oct–Dec, also shows Jan–Mar of next year. Days with events show a circular marker — single color fill or **conic-gradient pie** when multiple distinct event colors fall on the same date. Hover shows event titles; click opens a dialog listing that day's events (with edit). **Dashboard:** **Upcoming calendar events** card lists next 31 days with date + “Today” / “Tomorrow” / “N days ahead”. **Telegram reminders:** optional per event (`reminder_enabled` + `reminder_days_before` array — `0` = on the day, `N` = N days prior); uses existing per-portfolio Telegram creds (`notifications_enabled`, `telegram_bot_token`, `telegram_chat_id`). Scheduler: `portfolio:send-calendar-reminders` daily at 07:00 (`Asia/Kolkata` / cron timezone). Dedup via `portfolio_calendar_reminder_sends`. API: `GET/POST /api/calendar/events`, `PUT/DELETE /api/calendar/events/{id}`, `GET /api/calendar/occurrences?from=&to=`, `GET /api/calendar/upcoming`. Migration: `2026_07_11_000001_create_portfolio_calendar_events_table.php`. Backend: `CalendarRecurrenceService`, `CalendarEventService`, `CalendarReminderService`, `CalendarEventController`.
- Transaction form: labeled fields; **NSE × BSE** toggle; integer qty (empty by default, like price); price step **0.05** (2 dp); **Fees** auto-calculated (read-only) from Settings fee components — hover **ⓘ** for line-item breakdown; symbol validate button → `POST /api/stocks/validate` with `check_only: true` (local + in-memory cache); Save disabled until valid + symbol validated; while save API runs, submit shows **Adding…** / **Updating…** and stays disabled. After **add**, form retains stock/symbol/qty/type/date/notes; only **price** clears (blocks accidental duplicate submit). After **update**, form resets. **Bulk import (CSV):** toggle **Single** / **Bulk (CSV)** on Transactions page — paste `Stock,Quantity,Average Price,Transaction Type` rows, review editable table (exchange NSE/BSE toggle, Buy/Sell toggle, date picker, auto fees), **Save all** posts each row via `POST /transactions`. Parser: `utils/bulkTransactionCsv.js`; UI: `BulkTransactionImport.jsx`. **Transaction date** (`TransactionDateInput.jsx`): text `dd-mmm-yyyy` + picker button with calendar icon (outline + 3×3 date dots). DB/API field: `fees` (renamed from `brokerage`, Jun 2026). FE is canonical calculator (`resources/js/src/utils/feeCalculator.js`); API trusts client `fees` on save. One-time migration recalculated historical rows using PHP mirror (`FeeCalculatorService`).
- **Transaction history** table (**Active transactions**): `GET /transactions?scope=open` — only transactions for stocks with holding `quantity > 0` (recalculates holdings first). Client search in header. Link **Squared-off** → `/transactions/closed`. Columns include **Notes** (truncated with full text on hover; `—` when empty). **Squared-off** page: `scope=closed`, server search + pagination (25/page), edit navigates to main form, delete in place. **Layout:** add/edit form stacked above the active transactions table at all breakpoints.
- **Corporate actions (Jul 2026):** guided stock **split** and **bonus issue** workflow for the active portfolio. Routes: `/corporate-action` (`CorporateActionPage.jsx`); entry from Holdings row **Split/Bonus** and Transactions **Corporate action** link (prefills stock when selected). API: `POST /api/corporate-actions/preview`, `POST /api/corporate-actions`, `GET /api/corporate-actions?stock_id=`. Migration: `2026_07_10_000001_create_portfolio_corporate_actions_table.php` (`portfolio_corporate_actions`, `portfolio_transactions.corporate_action_id`). **Split:** proportional restatement — every transaction in scope gets `qty × (ratio_to/ratio_from)`, `price ÷ factor` (default scope: all ledger rows; optional `split_scope=before_ex_date`). **Bonus (Indian tax style):** existing buys/sells unchanged; one new **buy at ₹0** on ex-date for `eligible_qty × (ratio_to/ratio_from)` where `eligible_qty` = shares held on record date (inclusive replay). Preview shows per-transaction adjustments (split) or proposed bonus buy + warnings. Apply triggers holdings recalc, FIFO `TransactionRealizationService` (loads transactions after clearing stale sell columns), portfolio snapshot rebuild, **local OHLCV restatement** for rows before ex-date (`CorporateActionPriceAdjustmentService` — split divides price by factor and multiplies volume; bonus divides price by `1 + factor` and multiplies volume), and `MetricsUpdateService` refresh (highest close, trailing stop, relative strength). Holdings **highest close since buy** / **trailing stop** recompute from restated `portfolio_stock_prices` on next `GET /holdings`. **Production repair:** `CorporateActionPriceRepairService`; local dev Artisan `portfolio:repair-corporate-action-prices`. **Production (no SSH):** `deploy/cpanel-repair-corporate-action-prices.php` — scan `?token=TOKEN`, apply `&apply=1`; optional `&profile=`, `&stock=`, `&action=`, `&force=1`; delete after success (see `.cursor/rules/No-SSH-use-cpanel-web-scripts.mdc`). Bonus buys allow `price = 0` when `corporate_action_id` is set.
- **Transaction fee settings (Jun 2026):** Settings → **Transaction fees** card (collapsible, **collapsed by default**) — configurable lines: label, **Type** (% / fixed ₹), rate, **Buy/Sell** tap toggles, **NSE/BSE/Both** exchange filter, per-line **GST %** (single row per component; theme-aware `lido-fee-component-row`; compact `NumberInput` height). Defaults match Zerodha equity delivery (brokerage 0%, STT 0.1%, NSE/BSE txn charges, SEBI 0.0001% [= ₹10/crore], stamp 0.015% buy-only). Stored as JSON in `portfolio_settings.fee_components`.
- Shell UI: full-bleed black header (`AppHeader.jsx`, **Lido Alexion** in **Nulshock** via `resources/fonts/nulshock-bd.ttf`, bundled into `build/assets/` with **relative** `url(./nulshock-….ttf)` in `lido-app.css` so the font loads under `/portfolio/build/assets/` even if `npm run build` omits `VITE_APP_BASE`), profile menu (`ProfileMenu.jsx`, includes `ThemeToggle.jsx` 3-segment sun/monitor/moon theme switch; account name links to `/profile`; avatar shows uploaded photo or initial; display name = trimmed `name` or email), logged-in **Bootstrap tabs** (`AppTabs.jsx`: Dashboard, Transactions, Holdings, Watchlist, Explorer, **Patterns**, **Knowledge**), footer nav (`AppBottomNav.jsx`, `config/mainNav.js`) — hidden by default; slides in when the user scrolls to the page bottom or moves the cursor within ~32px of the browser bottom edge (stays open while hovering the footer). **Profile page** (`/profile`, `ProfilePage.jsx`): change display name, read-only username (email), change password, profile photo (JPEG/PNG only, max 5 MB) — large clickable circle (360px) opens file picker on click with tooltip; upload shows spinner overlay; **Remove photo** bright-red text link centered on hover when a photo is set. API: `GET/PUT /api/profile`, `PUT /api/profile/password`, `GET/POST/DELETE /api/profile/photo`; photos in `storage/app/profile-photos/` served only to authenticated user via session cookie. **Themes:** `light` / `dark` / `system` via `ThemeContext` + CSS variables in `lido-app.css` (`localStorage` key `lido-theme`); `app.blade.php` inline script avoids flash. Dark theme: `table-striped`, `table-hover`, and `.pagination` use Lido CSS variables (readable text on striped/hover rows; themed pagination on sync logs). Dev: `npx vite` + `@viteReactRefresh`; prod: `npm run build`.
- **Watchlist** (`/watchlist`, `WatchlistPage.jsx`): per **active portfolio** (`portfolio_watchlist_items`: `profile_id`, `stock_id`, optional `note` ≤500 chars, unique per profile+stock, max 100 items). Search via existing `StockAutocomplete` → `GET /api/stocks/search` (local NSE master). Selected stock shows reusable `PriceVolumeChart` fed by **`GET /api/stocks/{id}/market-prices`** (all cached `portfolio_stock_prices` rows — no holding required). Add / Remove / Save note via `POST/PUT/DELETE /api/watchlist`. List below: click row to load chart; shows latest close + row count when cached. Warning when no OHLCV cached (universe sync / validate backfill). Migration: `2026_07_04_000001_create_portfolio_watchlist_items.php`.
- **Pattern guide** (`/patterns`, `PatternGuidePage.jsx`): educational reference + pattern scanner docs. Main nav tab **Patterns** with **Chart patterns** / **Candlesticks** sections (`SegmentToggle`; preference `localStorage` key `portfolio_pattern_guide_section`). Static datasets: [`data/chartPatterns.js`](app/resources/js/src/data/chartPatterns.js) (cup & handle, H&S, triangles, flags, wedges, etc.) and [`data/candlestickPatterns.js`](app/resources/js/src/data/candlestickPatterns.js) (doji, hammer, shooting star, engulfing, harami, piercing line, dark cloud cover, stars, etc.). Each entry: **SVG sketch** (`PatternSketch.jsx`), characteristics, meaning, OHLCV **math rules**; glossary in [`data/ohlcvCandleTerms.js`](app/resources/js/src/data/ohlcvCandleTerms.js). UI: expandable `PatternGuideCard` + search filter. **Deep links:** each pattern card has DOM `id` = pattern id; URL fragment `/patterns#hammer` switches section, expands card, and scrolls into view (`patternGuideLinks.js`). Dashboard pattern signals link pattern name → definition.
- **Pattern detection (Jun 2026):** OHLCV scanners run on cached `portfolio_stock_prices` (daily bars). JS engine: [`utils/patternDetection/`](app/resources/js/src/utils/patternDetection/) (`candleMath`, `detectCandlesticks`, `detectChartPatterns`, `scanOhlcv`) — patterns complete on the **latest bar** of the window. PHP mirror: `PatternDetectionService` + `PatternScanService`. API: **`GET /api/patterns/scan?scope=holdings|watchlist&actionable_only=`** (default `true`) — returns `{ results: [{ stock_id, symbol, matches: [{ id, name, category, variant, bar_date }] }] }`. **Actionable** = category ≠ `neutral` (doji/spinning top excluded from dashboard). **Dashboard:** table **Pattern signals (holdings)** — actionable matches only; symbol links to OHLCV page; **Pattern** column shows `PatternSketch` SVG beside linked pattern name. **Watchlist:** **Scan my watchlist** button runs full scan (`actionable_only=false`); results table with symbol click → load chart. **OHLCV chart:** `PriceVolumeChart` footer shows **Possible patterns on this window** (all matches on filtered range, client-side `scanOhlcv`). Chart patterns use simplified heuristics (double top/bottom, triangles, flags, H&S) on last ~15–30 bars; not exhaustive vs textbook definitions.
- **Knowledge Board (Jun 2026):** per **active portfolio** notes for stock-market learnings, research, and ideas — **not** a general notes app. Routes: `/knowledge-board`, `/knowledge-board/tags`. Main nav **Knowledge**. **UI (Jul 2026 cleanup):** no title field (title auto-derived from first content line); compact collapsible toolbar (collapsed by default — **+ New** on the left, then a 6px gap, then a full-width expand strip with centered chevron; expanded action buttons use 6px gaps; toolbar has 6px top margin; expanded shows full **Knowledge Board** header + **New Note** and filters) — action buttons row includes **Manage tags**; filter row without field labels (search placeholder **Search text, tags**; sort options e.g. **Sort manually**, **Sort by date created** (persisted in `portfolio_knowledge_board_sort`)); tag-match dropdown on tag filter row (**Match any tags**, etc.); filter tag chips dark gray; full-width single-column cards; manual sort drag grip in overlay before checkbox (compact); note body text (`--lido-knowledge-note-text`, normal weight); card header toolbar uses stroke SVG icons (`KnowledgeCardIcons.jsx`, muted gray, no underline) — order clock → pin → edit → duplicate → archive → delete; card header toolbar overlays note text on hover (transparent, compact row; text starts at top); ⋮ menu on mobile; filter tags use tag colors + distinct active ring; cards show full note body (no clip); edit via toolbar only; editor **Simple / Formatted / Markdown** toggle in footer (Markdown mode: **Edit / Preview** bar above textarea; `marked` for preview + save); formatted editor ProseMirror styles restore list markers, blockquote border, and task-list flex layout (Tailwind preflight reset); inline tags row in editor footer (between mode toggle and action buttons, with separators); save status uses theme colors (visible **Saved** in dark mode); manual save button **Save (Ctrl/Cmd + S)**; footer **Delete** icon button (outline danger, left of Close) when editing a saved note (including after autosave creates one). **Export dialog:** `SegmentToggle` for Plain Text / Markdown / AI Friendly (`portfolio_knowledge_board_export_format` in `localStorage`); exports note body only (no title/tags/dates); notes always separated by dividers; footer gap between Close and Copy; dialog max-height capped to viewport (`100dvh` minus root padding) with scrollable preview area. API still stores `title` for search/sort. `is_favorite` retained in DB but hidden from UI.
- Holdings bottom-nav item stays active on OHLCV sub-routes (`/holdings/:id/prices`).
- Holdings OHLCV screen (`StockPricesPage`): reusable **`PriceVolumeChart`** ([`components/charts/PriceVolumeChart.jsx`](app/resources/js/src/components/charts/PriceVolumeChart.jsx)) above the table — Recharts `ComposedChart` with close line + volume bars (green/red by bucket vs prior close). Data from same `GET /stocks/{id}/prices` rows as the table; transforms in [`utils/ohlcvChartData.js`](app/resources/js/src/utils/ohlcvChartData.js). **Range** (footer): All (default), 1M, 3M, 6M, 1Y — calendar cutoff from latest date; clamps to available history with muted hint when shorter than selected window. **Sampling**: 1 day (default), 5 days, 10 days, 1 month (30 records) — consecutive record buckets; **close** = last row in bucket, **volume** = sum. `DataTableCard` below with `formatTransactionDateDisplay`, `formatTableMoney2` (Open/High/Low/Close), `formatTableInteger` (Volume).
- Holdings table **Stock** symbol is a direct link to OHLCV (`/holdings/:id/prices`). **Sell** primary action navigates to `/transactions` with form prefilled: symbol/name/exchange, type `sell`, quantity = holding qty, price = latest close, symbol marked validated (`sellTransactionPrefill.js` + router `location.state`). Row actions use reusable **`ComboButton`** (`components/ComboButton.jsx`, Carbon ComboButton pattern): primary **Sell** + menu chevron; menu items portaled to `document.body` and positioned with **Popper** (`bottom-end`) so dropdown does not overlap the button inside table cells; opening one combo menu broadcasts `lido-combo-button-open` so any other open combo menu closes. **Invested** column: list icon link (hidden until row/cell hover; tooltip **View transactions**) → `/transactions` with `transactionSearch` set to the stock symbol.
- Holdings **Latest Close**: `₹` whole amount; **complex** view adds day-over-day `(±N.NN%)` from the latest two OHLCV closes (`stoploss_summary.daily_change_percent`); omitted when only one price row exists. Price still red (no bold) when below trailing stop. When LTP &lt; trailing stop: **Stock** symbol uses `text-danger`; **Sell** uses solid `btn-danger` (not outline).
- Holdings **Unrealized P/L** column (simple + complex): signed `±₹` amount (2 dp, green/red). **Complex** adds secondary line `(±N.NN%)` vs invested (`unrealized_gain_percent` from API). Shows `—` when latest close unavailable.
- Holdings **Highest Close** 2nd line: `LTP: N%` = `((LTP − highest since buy) / highest) × 100`; green if ≥ 0, orange if below 0 but above −`stoploss_percent`, red if ≤ −`stoploss_percent` (from settings / `stoploss_summary.stoploss_percent`). **Complex** column sort uses that LTP % (not rupee high); **Latest Close** sort uses day-over-day %; **Unrealized P/L** sort uses `unrealized_gain_percent` (simple view sorts by rupee amounts).
- Holdings table card header shows open position count next to title, e.g. **Holdings** `(3)` — count in muted smaller type; hidden while loading.
- Holdings table default column order: Stock → Latest Close → **Unrealized P/L** → Invested → Fees → XIRR → Highest Close → **Qty** → **Avg Buy** → Trailing Stop → Realized P/L → Sell. **Fees** and **Realized P/L** hidden by default (`defaultColumnVisibility`); column prefs stored per view in `localStorage` keys `portfolio_datatable_holdings` (complex) and `portfolio_datatable_holdings-simple` (simple). **Simple / Complex** toggle in card header (before columns menu): complex = current multi-line cells; simple = primary value only (no since-buy date, price date, daily % in Latest Close, unrealized % subline, fee %, LTP drawdown, stop %, etc.). Selected view persisted in `portfolio_holdings_view`. Two table instances share the same dataset; only the active view is shown; column menu applies to the visible view only.
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
- **Dark mode disabled inputs (Jun 2026):** Bootstrap’s disabled `form-control` used a light background; profile username (read-only) was unreadable. `lido-app.css` now themes `:disabled` / `[readonly]` inputs with `--lido-input-bg` and muted text.
- **Knowledge Board list 422 (Jul 2026):** `GET /api/knowledge-board/notes?archived=false` failed Laravel `boolean` validation (query string `"false"` is not accepted by the rule). `index` now uses `$request->boolean('archived')` without strict validation — same pattern as `PatternScanController` `actionable_only`.
- **Knowledge Board editor hang + colors (Jul 2026):** Tiptap `setContent` loop when parent passed live `contentJson` as `initialJson`; autosave updated `editingNote` and re-ran modal init effect. Fixed: editor mounts once per `sessionKey`, no content re-sync effect; modal init only on `sessionKey`; skip autosave without title; don’t refresh `editingNote` on autosave for existing notes. Modal/export CSS uses Lido theme variables. **Modal click-through (Jul 2026):** Bootstrap `modal-backdrop` was rendered after `modal-dialog`, covering the dialog (dimmed UI, no focus/clicks). Fixed: portal to `document.body`, custom backdrop behind dialog. Modal `border` + `box-shadow` on `.modal-content` for visible edge in dark theme. Explicit modal header/body/footer padding. **Tag input white flash (Jul 2026):** autosave set `saving` → `disabled` on tag field; Bootstrap default disabled bg (white) outside `.contentPane`. Fixed: no `disabled` during autosave; manual save only toggles `saving`; themed `:disabled` on modal inputs.
- **Profile menu light theme (Jun 2026):** account name hover used white text on white menu; email line used `text-white-50`. Fixed theme-aware `.lido-profile-account-link` / `.lido-profile-account-email`. Profile photos use app-relative `/api/profile/photo` URL (`profilePhotoUrl.js` + `User` accessor) so avatars load under `/portfolio` subdirectory.

## Known Limitations

- `vendor:publish` for Sanctum migrations fails when `finfo` extension is unavailable.

## Pending Improvements

- Add CI workflow for backend tests and frontend build checks.
- **Stocks admin UI (open):** Stocks tab removed from SPA (May 2026). Backend `GET/POST/PUT /api/stocks` and `portfolio_stocks` table remain. Reintroduce a Stocks screen later if master-data management is needed outside Transactions.

## Wishlist (deferred — no implementation yet)

- **Single-folder deploy (`portfolio/` only):** Collapse `lidoportfolio/` into `public_html/portfolio/laravel/` so one `build/` upload suffices. Design in `deploy/DEPLOY.md` §2.2. **Deferred** — consolidating Laravel under the web-visible `portfolio/` tree increases `.env` exposure risk unless `.htaccess` and secrets handling are bulletproof; current two-folder layout (`lidoportfolio/` with Deny all + `portfolio/` web entry) is kept intentionally.
- **Production secrets handling:** Before or as part of single-folder deploy, replace or supplement on-server `.env` (e.g. env vars outside web root, cPanel secrets, or shared `config/` only — DB already uses `/home/USER/config/DBConfig.php`). Goal: no sensitive values in a path that could become web-reachable after a layout change.

## Open Items

| Item                        | Status   | Notes                                                                                              |
| --------------------------- | -------- | -------------------------------------------------------------------------------------------------- |
| Stocks tab / master UI      | Deferred | Master data via `stocks:sync` + Transactions autocomplete; no dedicated Stocks admin SPA tab.      |
| BSE master sync             | Optional | Enable `BSE_STOCK_MASTER_ENABLED=true` and `BSE_EQUITY_CSV_URL` when BSE CSV source is configured. |
| Single-folder deploy        | Wishlist | See § Wishlist; depends on secrets handling; keep `lidoportfolio/` + `portfolio/` for now.         |
| Production secrets / `.env` | Wishlist | Harden before nested Laravel under `portfolio/`; see `deploy/DEPLOY.md` §2.2.                      |

## Deployment Validation

- **Canonical deploy:** `deploy/DEPLOY.md` · checklists: `DEPLOYMENT_VALIDATION_PLAN.md`
- **Stage uploads:** `deploy/prepare-upload.ps1` → `deploy/staging/` (gitignored). Staging includes `lidoportfolio/config/*.php` (all app config except dev `DBConfig.php`); run `cpanel-config-cache.php` after upload when config changes.

### Production learnings (Jun 2026 — `/portfolio` on GoDaddy)

| Issue                                                          | Cause                                                                                                                                 | Fix                                                                                                                                                                                                        |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Mobile blank page                                              | `www` vs apex in Vite `<script type="module">` src                                                                                    | Root-relative asset URLs in `AppServiceProvider`; `config:cache` after deploy                                                                                                                              |
| Login CSRF on some devices                                     | Stale `XSRF-TOKEN` at path `/` read from `document.cookie` after `/sanctum/csrf-cookie`                                               | Deploy latest build (`csrf.js` always uses `/api/auth/csrf-token` + `X-CSRF-TOKEN`); clear site cookies once; `config:cache` with `SESSION_PATH=/portfolio`, `SESSION_DOMAIN=.lidoalexion.com`             |
| **CSRF 419 even after clear / incognito**                      | **Wrong host** (`lidoalexion.com` apex vs `www.lidoalexion.com`) — apex may have expired/missing SSL or different cookie/TLS behavior | **Always use `https://www.lidoalexion.com/portfolio/`**; deploy `.htaccess` apex→www redirect (`deploy/public_html-portfolio-.htaccess` + root snippet); `APP_URL=https://www.lidoalexion.com/portfolio`   |
| “App did not start” on `mobile-debug.html`                     | Static file missing → Laravel SPA                                                                                                     | Upload as `portfolio/mobile-debug.html`; fix `portfolio/.htaccess` + root snippet                                                                                                                          |
| 404 on whole `/portfolio/`                                     | `.htaccess` missing `index.php` rewrite                                                                                               | Use `deploy/public_html-portfolio-.htaccess`                                                                                                                                                               |
| Red “App load problem” on login                                | Stale `sessionStorage.lido_boot_error`                                                                                                | Tap Dismiss; deploy latest `BootErrorBanner` + `app.blade.php`                                                                                                                                             |
| Intermittent blank page typing in forms (e.g. user mgmt email) | Mobile keyboard / `100vw` header overflow / `backdrop-filter` repaint bug — devtools resize “fixes” it                                | Deploy Jun 2026 fix: drop `100vw` header breakout, `overflow-x: hidden`, `100dvh`, `interactive-widget=resizes-content`, solid footer nav, `scroll-margin` on inputs, `autoComplete="off"` on invite email |

**Server cleanup after troubleshooting:** delete all `cpanel-*.php`, `mobile-debug.html`, `portfolio-OK.txt`, `test-ok.php` from `public_html/portfolio/`. Keep `index.php`, `.htaccess`, `build/`.

**Optional deploy diagnostics (repo only, upload temporarily):** `cpanel-ping.php`, `cpanel-mobile-debug.php`, `cpanel-api-probe.php`, `cpanel-schedule-diagnostic.php`, `portfolio-mobile-debug.html` (upload renamed → `mobile-debug.html`). See `deploy/README.md`.

**Scheduler diagnostic (Jul 2026):** `deploy/cpanel-schedule-diagnostic.php` — read-only browser script for production cron troubleshooting. Upload to `public_html/portfolio/`, visit `https://www.lidoalexion.com/portfolio/cpanel-schedule-diagnostic.php?token=Lido` (set `SETUP_TOKEN` before upload). Reports: `cron_time` / `cron_timezone`, universe enablement, **`isMaintenanceWindowDue()`**, **schedule heartbeat** (`schedule_run_heartbeat_at` — proves `schedule:run` every minute), last probe JSON, **withoutOverlapping mutex**, in-progress flag, `schedule:list`, events due now, recent sync runs, tonight's window check. Optional query flags: `&explain=1`, `&clear_mutex=1`, `&clear_in_progress=1`. **Force one batch:** `deploy/cpanel-run-universe-maintenance.php?token=...&apply=1` (optional `&clear_guards=1`, `&skip_gap_fill=1`). **Delete after use.**

**Schedule heartbeat / probe (Jul 2026):** Every minute `portfolio:universe-maintenance-probe --write-heartbeat` updates `portfolio_settings.schedule_run_heartbeat_at` and (when `backend_log_level=debug`) writes a **debug** line to `storage/logs/scheduler-YYYY-MM-DD.log` with window/interval/mutex/in-progress/`would_skip_reason`/batch_size. On each maintenance due tick, `--explain` also writes `universe_maintenance_probe_json` + an **info** probe line. Maintenance command logs **info** start/finish and **debug** daily/gap results. Sync skips (disabled / in-progress) and last_run heal events are logged. If heartbeat debug lines are missing while evening sync is idle, cPanel cron is not invoking `schedule:run` every minute. **Retention:** scheduler channel uses `LOG_DAILY_DAYS` (default 2) — set `backend_log_level` back to `info` after debugging so minute heartbeats don’t inflate logs.

**Vite build:** `npm run build` defaults to `VITE_APP_BASE=/portfolio/build/` in production (`vite.config.js`); CSS `url()` assets (Nulshock font) use relative paths. Copy full `public/build/` (including `assets/nulshock-*.ttf`) to both `lidoportfolio/public/build/` and `portfolio/build/`.

## Logging & Debugging Architecture (May 2026)

Mandatory lightweight logging for same-day / 1–2 day debugging. **File-based only** — no log rows written to `portfolio_system_logs` (table retained for legacy; new path uses Monolog daily files).

### Goals

- Quick debugging, recent log inspection, error tracing, frontend↔backend correlation via `X-Request-ID`.
- No long-term retention; `LOG_DAILY_DAYS=2` rotates and deletes older files.

### Backend channels (`config/logging.php`)

| Channel           | File pattern                            | Purpose                                              |
| ----------------- | --------------------------------------- | ---------------------------------------------------- |
| `daily` (default) | `storage/logs/laravel-YYYY-MM-DD.log`   | Application / API / validation / security / telegram |
| `frontend`        | `storage/logs/frontend-YYYY-MM-DD.log`  | Logs from SPA via API                                |
| `provider`        | `storage/logs/provider-YYYY-MM-DD.log`  | NSE / Yahoo / Alpha Vantage failures & fallbacks     |
| `scheduler`       | `storage/logs/scheduler-YYYY-MM-DD.log` | Cron / `DailyMarketDataJob`                          |

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
- `DailyMarketDataJob`: start/end, processed/failed/skipped counts, per-stock failures; portfolio snapshot count (aggregate, not per-user rows).
- **In-app sync logs (Jun 2026):** `portfolio_sync_runs` + `portfolio_sync_logs` tables; `SyncLogService` writes DB rows when `sync_log_retention_days` &gt; 0 (default **7**, max 90; **0** disables DB writes and prunes existing rows) **and both tables exist**. File logs via `PortfolioLoggerService::scheduler()` unchanged. Jobs: `daily-market-data` (`DailyMarketDataJob` / `POST /api/sync/daily`), `stock-master` (`stocks:sync`), and `universe-price-sync` (`portfolio:sync-universe-prices`). Prune on each run start + hourly `sync-log-prune` schedule. Settings: retention field + latest run summaries on `GET /api/settings`. UI: **Settings → View sync logs** → `/settings/sync-logs` shows **Recent runs** (`GET /api/sync-logs/runs`) plus paginated log lines, filters, CSV export. **Timestamps (Jul 2026):** sync log UI formats `started_at` / `finished_at` / `logged_at` in **`cron_timezone`** from settings (API `meta.cron_timezone`) with explicit offset + IANA label, e.g. `07 Jul 2026, 05:00:04 GMT+5:30 (Asia/Kolkata)` — not browser local time. If runs cluster around 05:00 IST while timezone is `Asia/Kolkata`, they are outside the 19:00–23:45 maintenance window and point to a scheduler/code mismatch, not a display bug. **Sync runs pagination (Jul 2026):** `GET /api/sync-logs/runs` supports `page`, `per_page` (default 20, max 100), `job_name`, and `date_from`/`date_to` (cron timezone); UI **Sync runs** table has Previous/Next pagination like log entries. If runs appear but log lines are empty, apply migration `2026_06_21_000002` and re-run a sync. **cPanel:** `deploy/cpanel-migrate.php` runs `migrate --force`, repairs orphaned state (`portfolio_sync_runs` without `portfolio_sync_logs`), verifies required tables/columns, and reports `SyncLogService` readiness. Migration: `2026_06_21_000002_create_portfolio_sync_logs_tables.php`.

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
- `tests/Feature/SyncLogTest.php` — retention, disabled writes, API filters, CSV export, settings summaries.

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

| Column                                 | Purpose                                              |
| -------------------------------------- | ---------------------------------------------------- |
| `symbol`                               | Normalized ticker only (e.g. `INFY`) — not `INFY.NS` |
| `exchange`                             | `NSE` or `BSE`                                       |
| `name`, `isin`, `sector`               | Display / metadata                                   |
| `yahoo_symbol`, `alpha_vantage_symbol` | Provider-specific symbols                            |
| `is_active`, `is_benchmark`, `is_dual_listed` | Listing / NIFTY row / also on BSE (same ISIN) |
| `last_verified_at`                     | Last provider or sync verification                   |

**Unique:** `(symbol, exchange)` — same ticker may exist on NSE and BSE separately.

### Symbol normalization

| Input     | Stored                                  |
| --------- | --------------------------------------- |
| `INFY`    | symbol=`INFY`, exchange=`NSE` (default) |
| `INFY.NS` | symbol=`INFY`, exchange=`NSE`           |
| `INFY.BO` | symbol=`INFY`, exchange=`BSE`           |

Provider suffixes are resolved by `ProviderResolverService`, not stored in `symbol`.

### Services

| Service                   | Responsibility                                                                                          |
| ------------------------- | ------------------------------------------------------------------------------------------------------- |
| `ProviderResolverService` | `normalizeSymbol()`, `yahooSymbol()`, `alphaVantageSymbol()`, `isMalformed()`, `applyProviderSymbols()` |
| `StockValidationService`  | Stage 1 local lookup; stage 2 provider chain; `validateAndPersist()` upserts + backfill                 |
| `StockMasterSyncService`  | NSE + BSE master import via `stocks:sync`; ISIN dedup (NSE preferred); `is_dual_listed` flag |
| `BseEquityMasterService`  | BSE equity list via API (`BSE_EQUITY_LIST_API_URL`) or optional `BSE_EQUITY_CSV_URL` |
| `EquityUniverseService`   | Universe/search queries, ISIN dedup, `exchange_label` (`NSE+`), canonical stock resolution |
| `StockResolverService`    | Used by transactions — delegates to validation (no blind `Stock::create`)                               |

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
- **NSE:** `config('portfolio.stock_master.nse_equity_csv_url')` default NSE `EQUITY_L.csv`; EQ series only
- **BSE (Jul 2026):** enabled by default (`BSE_STOCK_MASTER_ENABLED=true`). **`BseEquityMasterService`** fetches BSE equity list from `BSE_EQUITY_LIST_API_URL` (BSE `ListofScripData` API) or optional `BSE_EQUITY_CSV_URL` override. **Deploy:** upload `config/portfolio.php` and run `config:cache` — stale cache without `bse_list_api_url` caused null URL errors; service now falls back to built-in default API URL if config is missing.
- **ISIN dedup:** when the same ISIN exists on NSE and BSE, only the **NSE** row is kept; BSE duplicate rows are skipped/deactivated. NSE rows get `is_dual_listed=true` and display as **`NSE+`** (`exchange_label` in API). BSE-only listings remain `exchange=BSE`
- Duplicates within same exchange logged and skipped; removed symbols set `is_active=false` (IDs preserved)
- **Immediate new-symbol price fill:** when stock master adds new NSE or BSE-only symbols, CLI `stocks:sync` may backfill those ids (`STOCK_MASTER_BACKFILL_ON_SYNC`, capped by `STOCK_MASTER_MAX_BACKFILL_PER_SYNC`). **UI** `POST /universe-price-sync/stock-master` imports master only (no price backfill) to avoid HTTP timeouts on shared hosting.

### Universe price sync (Jun 2026)

Bulk OHLCV for the **equity universe** (NSE + BSE-only; ISIN deduped). Reuses `portfolio_stock_prices`, `StockPriceHistoryService` gap-fill, and `PriceFetchService` provider chain (NSE → Yahoo → Alpha Vantage). No screener metrics or buy alerts in this phase.

**NIFTY50 benchmark (Explorer / RS):** Universe sync excludes `is_benchmark` rows. **`BenchmarkPriceSyncService`** keeps NIFTY50 OHLCV current: full ~12-month backfill when cache is insufficient for 6M analytics, otherwise incremental (last ~14 days). Runs automatically via (1) **`portfolio:sync-benchmark-prices`** scheduled daily at the same time as daily market sync, (2) **`portfolio:daily-sync`** (force each run), (3) first universe batch each calendar day (`syncIfNeeded` skips if already synced today). Manual: `php artisan portfolio:sync-benchmark-prices`.

**OHLCV gap checker / repair (Jul 2026):** **`PriceHistoryGapService`** scans `portfolio_stock_prices` for missing edge ranges and internal gaps (`max_internal_gap_days`, default 7) across the universe history window (max of `history_days` and 6M analytics buffer). **`portfolio:fill-price-history-gaps`** (`--scan-only` optional) processes cursor-based batches; fills via `fetchMissingHistory` (providers). Also repairs **NIFTY50** at the start of each fill batch. Admin UI: Settings → Universe price sync → **Price history gap checker** (`GET/POST /api/universe-price-sync/gaps/*`) now provides one-click **Scan all gaps** and **Fill all gaps** chaining actions (batch-by-batch with waits) and no reset button. Sync log job: `price-history-gap-fill`.

**Automated normal-day maintenance (Jul 2026):** Scheduler runs **`portfolio:run-universe-maintenance`** every **5** minutes during **19:00–23:45** (`cron_timezone`) when universe sync is enabled. Each run: (1) one universe **daily** price batch, (2) one **gap scan/fill** batch (`PriceHistoryGapService::fillBatch`) — cursor resets at **19:00** window start, then chains across the evening (~58 batches × 125 stocks ≈ full universe/night). Disable gap pass: `UNIVERSE_MAINTENANCE_GAP_FILL_ENABLED=false`. Extra gap batches still run when daily sync has failures (`UNIVERSE_MAINTENANCE_GAP_FILL_RETRIES`, default 2). CLI chain: `portfolio:fill-price-history-gaps --chain` (optional `--max-batches`, `--max-seconds`).

**Last batch card vs Sync Logs (Jul 2026):** Universe status “Last batch” used only `portfolio_settings.universe_price_sync_last_run_json`. If that settings write lagged, the UI could show an old `completed_at` while Sync Logs (`portfolio_sync_runs`) showed newer successful batches. `UniversePriceSyncService::lastRunStats()` now prefers the fresher of settings JSON vs latest `universe-price-sync` sync run (parses summary for processed/ok/fail/stored) and heals the settings row when the sync log wins.

**Daily lookback strategy (Jul 2026):** Universe daily sync uses a fixed lookback window (`UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS`, default 10). Missing ranges are still detected first; provider fetch runs only for detected gaps.

**Prerequisite:** stock master populated — `stocks:sync` CLI or **Settings → Universe price sync → Sync stock master** (or `POST /api/universe-price-sync/stock-master`).

| Command / API                                          | Purpose                                                                |
| ------------------------------------------------------ | ---------------------------------------------------------------------- |
| `portfolio:sync-universe-prices --mode=backfill --all` | Initial ~1 year history for entire scope (long run; rate-limited)      |
| `portfolio:sync-universe-prices --mode=backfill`       | Same window, one batch (repeat until cycle completes)                  |
| `portfolio:sync-universe-prices --mode=daily`          | Incremental sync (default lookback 10 days) for one batch              |
| `portfolio:run-universe-maintenance`                   | Daily batch + one gap-fill batch per tick (cursor chains nightly); extra retries on daily failures |
| `portfolio:sync-benchmark-prices`                      | Sync NIFTY50 index OHLCV (full backfill if needed, else incremental)   |
| `portfolio:fill-price-history-gaps`                    | Scan/fill OHLCV gaps in local history (`--scan-only`, cursor batches)  |
| `portfolio:check-operational-alerts`                   | Evaluate sync health; Telegram admins; update operational alert flags  |
| `POST /api/universe-price-sync/run`                    | Same as CLI batch (cPanel-friendly; one HTTP request per batch)        |
| `GET /api/universe-price-sync/status`                  | Progress, coverage, cursor, rate-limit signals, recent provider issues |
| `POST /api/universe-price-sync/stock-master`           | NSE + BSE equity master import                                         |

**Admin UI:** Settings → **Universe price sync** (`/settings/universe-price-sync`) — scope **All equities (NSE + BSE-only)** (`all_equities`). Other behavior unchanged (backfill chain, gap checker, stock master sync, toasts).

**Scope** (`UNIVERSE_PRICE_SYNC_SCOPE`, default `all_equities`):

- `all_equities` — active NSE rows + active BSE-only rows (BSE rows whose ISIN already exists on NSE are excluded)
- `all_nse` — **deprecated** alias for `all_equities` (accepted in API/CLI for backward compatibility)
- `nifty500` — intersection with NIFTY 500 NSE constituents (cached in `portfolio_settings` for 7 days)

**Rate limiting:** configurable delay between symbols (`UNIVERSE_PRICE_SYNC_DELAY_MS`, default 400ms); batches default 75 stocks/run. API throttled 12/min per admin. `rate_limit_hits` and `likely_rate_limited` on status when errors match 403/429/throttle patterns. Telegram suppressed during batch (`PriceSyncNotificationContext::withoutTelegram`).

**Schedule:** `portfolio:run-universe-maintenance` checks **`isMaintenanceWindowDue()`** every minute (explicit PHP timezone from `cron_timezone`, not host cron TZ) during **19:00–23:45** by default, running every **5** minutes (`UNIVERSE_MAINTENANCE_INTERVAL_MINUTES`, default 5). Default batch **125** stocks (`UNIVERSE_PRICE_SYNC_BATCH_SIZE`) ≈ **58 runs/night** ≈ **7,250 stocks/night** (enough for ~7k universe in one evening). Overlap guard: `withoutOverlapping(25)` + in-progress flag on `sync()`. Env: `UNIVERSE_MAINTENANCE_START_HOUR`, `UNIVERSE_MAINTENANCE_END_HOUR`, `UNIVERSE_MAINTENANCE_END_MINUTE`. Cursor resumes across nights until cycle completes.

**Admin operational alerts (Jul 2026):** **`AdminOperationalAlertService`** monitors sync health and persists active issues in `portfolio_operational_alerts`. Detects: provider rate limits, universe sync overdue/failed, daily market sync overdue/failed, stock master weekly sync overdue/failed, and scheduler inactivity (no sync runs). Telegram notifications go to **all admin users** with Telegram configured on any portfolio (deduped by bot token + chat id); re-notify at most every **6 hours** per alert while still active (`ADMIN_OPS_ALERT_TELEGRAM_COOLDOWN_HOURS`). Command: `portfolio:check-operational-alerts` (hourly schedule). Also runs after daily sync, universe maintenance, stock master sync (**CLI and UI** `POST /universe-price-sync/stock-master`). **Ping when clear (Jul 2026):** global setting `admin_ops_telegram_ping_when_clear` (`portfolio_settings`, default `false`) — admin toggle on Settings → **Global** (“Ping Telegram when there are no alerts”). When enabled: (1) **`portfolio:send-notifications`** at each profile’s scheduled notification time sends a Telegram “no active alerts” confirmation if that portfolio has none (proves notification cron ran); (2) **`POST /api/operational-alerts/run-check`** sends admin sync-health confirmation when zero operational alerts. Hourly `portfolio:check-operational-alerts` and post-sync ops checks do **not** auto-send clear pings. Disable after testing. Settings → **Admin alerts** (`/settings/admin-alerts`) — **Dismiss** acknowledges (hides from “Needs attention” while issue persists); **Clear off** manually resolves with `manually_cleared_at` suppression so the alert stays hidden until the underlying issue is fixed and then recurs. API: `POST /operational-alerts/clear`, `POST /operational-alerts/clear-dismissed`. **Warning toast:** when an admin loads the dashboard from the **server** (cache miss, **Refresh dashboard**, or post-mutation refetch — not when serving `localStorage` cache), `showAdminOperationalAlertsToastIfAny()` calls `GET /api/operational-alerts` and shows a warning toast if `unacknowledged_count > 0`. **Universe price sync** page still shows a compact operational alerts card with link to admin alerts. API: `GET /api/operational-alerts`, `POST /api/operational-alerts/acknowledge`, `POST /api/operational-alerts/acknowledge-all`, `POST /api/operational-alerts/run-check`; universe status still includes `operational_alerts`. Migration: `2026_07_06_000001_create_portfolio_operational_alerts_table.php`. Env thresholds: `ADMIN_OPS_DAILY_SYNC_STALE_HOURS` (36), `ADMIN_OPS_UNIVERSE_SYNC_STALE_HOURS` (26), `ADMIN_OPS_UNIVERSE_SYNC_STALE_MINUTES` (45 during 19:00–23:45), `ADMIN_OPS_STOCK_MASTER_STALE_DAYS` (8), `ADMIN_OPS_SCHEDULER_DEAD_HOURS` (48). **Universe overdue during maintenance window (Jul 2026 fix):** staleness is measured from the **current day's 19:00 window start** (or last run within that window), not from the previous morning/overnight run — avoids a false “overdue” alert at 19:00 when the last batch was hours earlier but before tonight's window. Dismiss sets `acknowledged_at` until the condition clears or re-triggers.

**Services:** `EquityUniverseService`, `UniverseStockResolverService` (wrapper), `Nifty500ConstituentService`, `UniversePriceSyncService`, `BseEquityMasterService`. Sync log job name: `universe-price-sync`.

**Env (optional):** `UNIVERSE_PRICE_SYNC_ENABLED`, `UNIVERSE_PRICE_SYNC_SCOPE`, `UNIVERSE_PRICE_SYNC_HISTORY_DAYS` (default 365), `UNIVERSE_PRICE_SYNC_DAILY_LOOKBACK_DAYS` (default 10), `UNIVERSE_PRICE_SYNC_DELAY_MS`, `UNIVERSE_PRICE_SYNC_BATCH_SIZE`.

### API endpoints (auth required)

| Method       | Path                                     | Purpose                                 |
| ------------ | ---------------------------------------- | --------------------------------------- |
| GET          | `/api/stocks/search?q=&exchange=&limit=` | Local master autocomplete (min 2 chars) |
| POST         | `/api/stocks/validate`                   | Explicit validation + persist           |
| GET/POST/PUT | `/api/stocks`                            | List / create (validated) / update      |

### Autocomplete UX

- `StockAutocomplete.jsx` — debounced search; shows `exchange_label` (`NSE+` for dual-listed)
- `TransactionsPage.jsx` — requires selection or validated symbol; no datalist free-text; **NSE/BSE toggle preserved** for fee calculation when dual-listed stock resolves to canonical NSE `stock_id`; validation success shows `exchange_label`
- `StockExplorerPage.jsx` — exchange toggle + selected stock label (`NSE+` when dual-listed)
- `WatchlistPage.jsx` — shows `exchange_label` instead of raw `exchange`
- `BulkTransactionImport.jsx` — per-row NSE/BSE toggle on review step
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
- BSE master uses BSE API by default; optional `BSE_EQUITY_CSV_URL`. Rows without a text trading symbol (scrip-code only) are skipped

### Tests

- `tests/Unit/ProviderResolverServiceTest.php`
- `tests/Unit/StockValidationServiceTest.php` (Http::fake)
- `tests/Unit/EquityUniverseServiceTest.php`, `tests/Unit/BseEquityMasterServiceTest.php`, `tests/Unit/StockMasterBseDedupTest.php`
- `tests/Feature/StockSearchTest.php`
- `tests/js/debounce.test.mjs` (`npm run test:js`)

### Future stock validation changes

Document provider, schema, or UX changes in this section.

## Historical Data & Exploratory Analytics (May 2026)

### Hybrid history architecture

| Type                | Detection (`StockTrackingService`)                                        | Fetch behavior                                                                        |
| ------------------- | ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Portfolio / tracked | Holdings qty &gt; 0, alerts, `tracking_active` metrics, past transactions | `ensurePortfolioHistory(buyDate)` → **buy − 3 months** → today; incremental gaps only |
| Exploratory         | Not tracked                                                               | `ensureAnalyticsHistory(months)` → ~60d (1M) / ~150d (3M) buffer; cached permanently  |

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

1. User opens `/explorer` (**Stock Analytics Explorer**) → autocomplete → **Run Analysis** (no period toggle).
2. `POST /api/analytics/explore` → `ExploratoryAnalyticsService::analyze()` — **1, 3, 6, and 12 months**; benchmark NIFTY50.
3. Symbol resolved from **local stock master only** (`validate(..., allowProvider: false)`); no on-demand provider fetch or backfill trigger.
4. Price history read from **`portfolio_stock_prices`** populated by **universe price sync** (`getCachedAnalyticsHistoryStatus` — no provider fetch). Warns in UI when cache incomplete.
5. Response includes `latest_close`, `period_closes.{1m,3m,6m,12m}`, `growth_percent`, `benchmark_growth_percent`, `relative_strength`, Recharts bar chart (4 periods: 1M/3M/6M/1Y), and **`normalized_gain_chart`** — daily % gain from the 12-month start close for stock and benchmark (line chart).
6. UI shows latest close for stock and benchmark, **historical start-close tables** (stock + index; period label + price only, no start date column) for 1M/3M/6M/1Y, **four RS cards** (1M / 3M / 6M / 1Y) color-coded green/red, bar chart, and 1-year normalized % gain line chart.
7. If any RS-required input is missing for any period, Explorer shows **Manual Relative Strength Input** (6-month period closes); available values prefilled from cache.
8. If analyze API returns validation failure (422, symbol not in local master), API returns user-facing `message` via `StockValidationUserMessage`. UI prefers `message` over raw `errors[0]`.
9. Manual form submission computes RS in-memory for 6M only (`stock_growth - benchmark_growth`); not stored in DB.
10. NIFTY50 Yahoo symbol is `^NSEI` (`ProviderResolverService` always corrects benchmark row; not `NIFTY50.NS`).
11. Explorer Run Analysis button disabled until a symbol is entered/selected.
12. When analysis fails and user uses manual RS inputs, Explorer renders summary + 6-bar chart (6M manual values where applicable).

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

`portfolio_portfolio_snapshots` rows are **materialized, rebuildable cache** — not append-only cron logs. The dashboard growth chart answers: _“What was my portfolio worth on date D given all transactions known today?”_

### Services

| Service                              | Role                                                                                                             |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------- |
| `PortfolioHistoricalHoldingsService` | Replay transactions with `transaction_date <= D` → open qty + cost basis per stock                               |
| `PortfolioSnapshotRebuildService`    | `calculatePortfolioStateForDate()`, `rebuildDateRange()`, `rebuildFromDate()`, `rebuildAfterTransactionChange()` |
| `StockPriceHistoryService`           | Gap-fill OHLCV before rebuild (`fetchMissingHistory`)                                                            |
| `StockQuoteService`                  | `latestClose(stock, asOf)` — close on or before D                                                                |

### Formulas (any historical date D)

1. **Holdings(D)** — all transactions ≤ D (buys add price×qty cost basis + qty; sells reduce qty; avg-cost invested amount; fees excluded from cost basis).
2. **portfolio_value(D)** — `SUM(quantity(D) × latest_close_on_or_before(D))` per open holding.
3. **invested_value(D)** — `SUM(remaining_cost_basis(D))` for open holdings.
4. **unrealized_pnl(D)** — `portfolio_value(D) − invested_value(D)`.

Nearest trading day: `WHERE price_date <= end_of_day(D) ORDER BY price_date DESC LIMIT 1` (weekends/holidays use prior session close). Weekend `price_date` rows from providers are ignored on ingest and when resolving closes (`TradingCalendar`). Upper-bound `price_date` filters use `endOfDay()` so same-day rows stored with a time component are not excluded (fixes flat/wrong “today” closes in snapshots).

**Portfolio growth chart dips (Jun 2026):** Yahoo sometimes stores Saturday/Sunday `price_date` rows; rebuild used those as trading days → bogus weekend snapshots with stale/wrong closes (both notional and invested could dip). Fix: skip weekends in `resolveTradingDates` / `closeFromIndex`, purge weekend snapshots on rebuild, skip weekend rows on price ingest, and use inclusive end-of-day date bounds in price queries.

### Rebuild triggers (mandatory)

After **any** transaction **create / update / delete**, `TransactionController` calls `rebuildAfterTransactionChange()` with:

`affected_start = MIN(old_transaction_date, new_transaction_date)` → rebuild **affected_start → today**.

Daily cron (`portfolio:daily-sync`) still refreshes **today** via `storeSnapshot()` → `rebuildDateRange(today, today)` but is **not** the sole source of history.

### Rebuild algorithm

1. Load ordered user transactions.
2. For each symbol, `fetchMissingHistory` from `min(first_tx, range_start)` → today (no silent skip).
3. Build trading-day list = distinct weekday `price_date` in range for held symbols + today (weekends excluded).
4. For each trading day: compute state → `updateOrCreate` snapshot; purge legacy weekend snapshots in range.
5. Log start/end, counts, missing closes, duration (`SnapshotRebuild` category).

### API

| Method | Path                             | Purpose                                                       |
| ------ | -------------------------------- | ------------------------------------------------------------- |
| POST   | `/api/portfolio/rebuild-history` | Manual full/partial rebuild (`from_date`, optional `to_date`) |

### Frontend

After transaction save/delete, `notifyPortfolioDashboardRefresh()` → Dashboard reloads `portfolio_growth` (latest 365 days, ascending). If snapshots are empty but transactions exist, `GET /dashboard` triggers a one-time lazy rebuild. **Portfolio Growth** card header always shows **Rebuild history** (browser `confirm` before `POST /portfolio/rebuild-history`).

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
- **CSRF** — `GET /sanctum/csrf-cookie` then `GET /api/auth/csrf-token`; mutations send `X-CSRF-TOKEN` (plain session token). Cookie `X-XSRF-TOKEN` is still set but not trusted client-side on subdirectory deploy (stale path `/` cookies on some mobile browsers).
- **Remember Me** — `Auth::attempt($credentials, $remember)`

### What we removed

- `localStorage.portfolio_token` and `Authorization: Bearer` headers
- API token returned from login/register responses

### Session configuration

| Setting                    | Default                          | Purpose                                                                                                     |
| -------------------------- | -------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `SESSION_DRIVER`           | `database`                       | Multi-server friendly on cPanel                                                                             |
| `SESSION_LIFETIME`         | `43200`                          | ~30 days sliding idle timeout                                                                               |
| `SESSION_SECURE_COOKIE`    | `true` in production             | HTTPS only                                                                                                  |
| `SESSION_SAME_SITE`        | `lax`                            | CSRF mitigation                                                                                             |
| `SESSION_PATH`             | derived from `APP_URL` path      | Subdirectory deploy (`/portfolio`) scopes cookies so they do not collide with other apps on the same domain |
| `SESSION_DOMAIN`           | `.your-domain.com` in production | Same login cookies on `www` and apex host                                                                   |
| `SANCTUM_STATEFUL_DOMAINS` | localhost + app host             | Sanctum treats requests as SPA                                                                              |

### Frontend flow

1. `AuthProvider` mounts → `ensureCsrfCookie()` then `GET /api/auth/me` restores user or shows login.
2. Login page → `ensureCsrfCookie({ force: true })` clears stale `XSRF-TOKEN` at `/` and `/portfolio`, hits `/sanctum/csrf-cookie`, then **always** loads the session token from `GET /api/auth/csrf-token` and sends `X-CSRF-TOKEN` (avoids reading a wrong cookie from `document.cookie` on mobile).
3. On `401` while logged in → `portfolio-unauthorized` → inline “session expired” on login (no toast); save path in `sessionStorage`. Initial `/auth/me` 401 (first visit / not logged in) is silent. `419` on API mutations retries once after forced CSRF refresh; login/invite accept skip the warning toast (AuthContext also retries login once).
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

### Tests (multi-portfolio, Jun 2026)

- PHPUnit suite uses `CreatesPortfolioProfiles` (defaultPortfolioFor, createPortfolioProfile, withProfileHeader). Portfolio rows in tests use `profile_id`; settings tests use `ProfileSettingsService` (`ProfileSettingsTest` replaces `UserSettingsTest`).
- `PortfolioMiddlewareTest`: default portfolio when `X-Profile-Id` omitted, header scoping, foreign profile 404, parallel profiles return different transaction counts.
- `bootstrap/app.php` requires `app/Support/helpers.php` (until `composer dump-autoload` picks up the file autoload entry) and sets middleware **priority** so `ResolveActivePortfolio` runs before `SubstituteBindings` (route model binding for transactions/alerts needs `activePortfolio()`).
- API controllers call `\activePortfolio()` (global helper) in namespaced classes.
