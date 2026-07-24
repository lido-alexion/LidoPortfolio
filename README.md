# Lido Portfolio Tracker

Self-hosted **Indian stock portfolio** tracker for personal / multi-portfolio use: ledger-true holdings, market data, screeners, patterns, research notes, and Telegram alerts.

- **Backend:** Laravel (PHP 8.3+)
- **Frontend:** React + Bootstrap (Vite, served by Laravel)
- **Database:** MySQL (`portfolio_*` tables; can share an existing database)
- **Notifications:** Telegram Bot API (optional)

Production example layout: subdirectory app under cPanel (`/portfolio`) — see [deploy/DEPLOY.md](deploy/DEPLOY.md).

---

## Features

### Accounts & portfolios

- **Session auth** (Sanctum cookies) with Remember Me and multi-device session management
- **Invite-only registration** — admins create invite links; guests set a password and sign in
- **Admin password-reset links** for existing accounts (no current-password required)
- **Multi-portfolio** — named portfolios per user, header switcher, active portfolio via `X-Profile-Id`
- **Admin roles** — global settings, user management, sync tools restricted to admins
- **Profile** — display name, password change, profile photo upload
- **Themes** — light / dark / system

### Dashboard

- Portfolio value, invested, total gain/loss, and **portfolio XIRR**
- **Allocation** table + visual donut charts (market % / invested %)
- Alerts, relative strength, Nifty comparison, and **pattern signals** (holdings)
- **Upcoming calendar events** (next 31 days)
- Client-side **dashboard cache** (24h) with explicit refresh
- Manual **Sync prices for today** (admin) and portfolio history rebuild hooks

### Transactions & holdings

- Buy/sell ledger with symbol autocomplete (NSE + BSE); new symbols create master rows on first buy
- Auto **fees** from configurable fee components (Zerodha-style delivery defaults)
- **Bulk CSV import** with review table before save
- **Active** vs **Squared-off** transaction views; FIFO **realized P/L** and fees on sells
- Holdings: qty, avg buy, invested, fees, latest close, unrealized P/L, XIRR, highest close since buy, trailing stop
- **Simple / Complex** holdings views; resizable/reorderable tables (TanStack)
- Sell prefill from holdings; OHLCV price history chart + table per stock
- **Corporate actions** — guided stock split and bonus issue with OHLCV restatement
- **Analyse** button copies an AI-ready stock prompt (with recent OHLCV) to the clipboard

### Watchlists

- Multiple named watchlists per portfolio (notes, sort/filter, quick add)
- Price history panel when a stock is selected (`/watchlist/{SYMBOL}`)
- Day change, holding badge, compare strength → Explorer
- **Pattern scan** for the list (persisted icons) and **auto-scan** when opening a stock (reuses watchlist cache when fresh)

### Explorer & Indices

- **Explorer** — universe-cache analytics (1M / 3M / 6M / 1Y): price cards, relative strength, charts, normalized gain
- **Indices** — browse index pages, constituents, and comparisons against configured benchmarks (Nifty, Sensex, mid/small caps, …)

### Stock Screener

- Condition builder (nested AND/OR) with technical indicators: SMA/EMA, RSI, ROC, stochastic, MACD, ATR, Bollinger, range %, 52w high/low, volume ratios, and more
- **LHS entity** — compute the left side on the stock **or** an index (e.g. stock range % vs Nifty 50)
- **Weight factor** on comparisons (`left` vs `weight × right`)
- Scopes: holdings, watchlist, all equities, or **index constituents**
- Manual runs, run history, stacked compare matrix, optional cron schedule + Telegram of results
- **Backtest** (1y / 6m / 3m / 1m / 15d) with **per-date persistence** and stock-major series evaluation (fast reuse across runs)
- Share screens across portfolios; Guide tab with indicator definitions and Investopedia links

### Patterns

- Educational **chart + candlestick** guide with SVG sketches and deep links (`/patterns#hammer`)
- OHLCV scanners on cached daily bars (JS + PHP)
- Dashboard holdings signals; watchlist scan + per-stock matched pattern links

### Calendar

- Per-portfolio market events (F&O / options expiry templates + custom recurrence)
- Year grid with color markers; optional Telegram reminders before event day

### Knowledge Board

- Portfolio notes for market research (tags, search, pin, archive, manual order)
- Editors: **Simple / Formatted (TipTap) / Markdown** with autosave
- **Images** — resize for embed, full-size on click (lightbox)
- **Read / Manage** toggle — clean reading view vs checkboxes and action toolbar
- Bulk select, export (plain / Markdown / AI-friendly), tag management page

### Alerts & notifications

- **Alert policies** — rule builder on holdings (columns, formulas, constants); evaluate after daily sync or on demand
- Trailing-stop metrics on holdings (policies generate alerts; not a separate built-in stoploss Telegram spam path)
- Per-portfolio Telegram bot/chat, notification schedules, India VIX alert settings
- Calendar reminders and scheduled screener result messages

### Market data & sync

- Price providers: **NSE → Yahoo → Alpha Vantage** (BSE path uses bhavcopy → Yahoo → AV)
- Holdings daily sync, **universe OHLCV** batch sync, index/benchmark sync
- Stock master sync (`stocks:sync`), local-first symbol validation
- **History depth backfill** — deepens OHLCV (e.g. ~18 months) all day for longer indicator/backtest windows
- Configurable CA bundle (`CURL_CAFILE`) for outbound HTTPS on Windows / cPanel

### Ops & UX shell

- Main tabs: Dashboard, Transactions, Holdings, Watchlist, Explorer, Indices, Screener, Patterns, Calendar, Knowledge
- Collapsible bottom footer nav; branded header (**Lido Alexion**)
- Settings: Global (admin), Portfolio, Account — fees, cron timezone, external stock links, sync logs
- Structured logging (backend + frontend), request IDs, optional frontend log ingest
- Production deploy via staged upload + token-guarded `cpanel-*.php` scripts (no SSH `artisan` on cPanel)

Deep technical detail lives in **[implementation.md](implementation.md)** (agents: treat that as the source of truth and keep it updated).

<details id="project-structure">
<summary><strong>Project structure</strong> — how the repo is organized (click to expand)</summary>

The folder **`app/`** is the **application root** (Laravel + React together). It is not “API only” — the React UI lives inside it by design. This is the standard **Laravel + Vite + React SPA** pattern: one deployable app, one `composer install` + `npm install` in `app/`.

```
Browser  →  Laravel (app/)  →  app.blade.php  →  React SPA  →  /api/*  →  Services / MySQL
```

#### Top-level layout (`LidoPortfolio/`)

| Path | What it is |
|------|------------|
| **`README.md`** | Quick start and feature overview |
| **`implementation.md`** | Architecture, runbook, and agent reference (read for deep detail) |
| **`app/`** | **Full application** — Laravel API, React SPA, config, tests |
| **`deploy/`** | Production deploy scripts, `.htaccess` snippets, cPanel guides |
| **`.cursor/`** | Cursor IDE rules and skills for this project |

There is **no separate `frontend/` folder** at the repo root.

#### Inside `app/` — the main application

Think of `app/` as a normal Laravel project root (`artisan`, `.env`, `composer.json`, and `package.json` all live here).

**PHP / API (server-side)**

| Path | Contains |
|------|----------|
| **`app/Http/Controllers/Api/`** | REST API controllers (holdings, transactions, dashboard, stocks, auth, …) |
| **`app/Services/`** | Business logic (price fetch, portfolio math, XIRR, screeners, notifications, …) |
| **`app/Models/`** | Eloquent models (`Stock`, `Holding`, `Transaction`, …) |
| **`app/Jobs/`** | Background jobs (price backfill, daily sync, alerts) |
| **`app/Console/Commands/`** | CLI commands (`portfolio:daily-sync`, universe sync, history depth, …) |
| **`routes/api.php`** | API routes (`/api/...`) |
| **`routes/web.php`** | SPA catch-all — browser URLs return the React shell |
| **`database/migrations/`** | MySQL schema (`portfolio_*` tables) |
| **`database/seeders/`** | Seed data (default admin user, etc.) |
| **`config/`** | Laravel config (DB, Sanctum, portfolio settings) |
| **`tests/Feature/`**, **`tests/Unit/`** | PHP tests |

**React frontend (client-side)**

The UI is not a sibling repo — it lives in Laravel’s `resources/` tree:

| Path | Contains |
|------|----------|
| **`resources/js/app.jsx`** | React entry point — mounts the app into `#app` |
| **`resources/js/src/App.jsx`** | Root component + client-side routing |
| **`resources/js/src/pages/`** | Screens (Dashboard, Holdings, Transactions, Screener, …) |
| **`resources/js/src/components/`** | Reusable UI (header, tables, autocomplete, …) |
| **`resources/js/src/context/`** | React context (auth, theme, portfolio) |
| **`resources/js/src/api.js`** | API client (calls `/api/...`) |
| **`resources/js/src/styles/lido-app.css`** | App-specific styles |
| **`resources/views/app.blade.php`** | HTML shell that loads Vite/React |
| **`vite.config.js`** | Vite + Laravel plugin + React plugin |
| **`package.json`** | npm scripts: `dev`, `build` |
| **`public/build/`** | Compiled frontend assets (after `npm run build`) |
| **`tests/js/`** | Frontend unit tests |

**Infrastructure / runtime**

| Path | Contains |
|------|----------|
| **`public/`** | Web document root (`index.php`, built assets, fonts) |
| **`storage/`** | Logs, cache, uploaded files (profile photos, knowledge images) |
| **`.env`** | Local secrets & DB config (not in git) |

Note: Laravel’s own PHP code folder is **`app/app/`** (nested under the application root). That naming overlap is normal.

#### How the pieces connect

1. Browser hits Laravel (e.g. `http://127.0.0.1:8001/holdings`).
2. `routes/web.php` returns `resources/views/app.blade.php`.
3. That view loads React via Vite (`resources/js/app.jsx`).
4. React Router handles `/holdings`, `/transactions`, etc. on the client.
5. React calls `/api/*` endpoints from `routes/api.php`.
6. Auth uses **Sanctum session cookies** (same origin, not Bearer tokens in `localStorage`).

In development, run from `app/`:

- `php artisan serve` — Laravel (API + SPA shell)
- `npm run dev` — Vite hot reload for React  
  Or `composer run dev` to run both together.

#### Mental model

```
LidoPortfolio/              ← monorepo root (docs + deploy)
└── app/                    ← application root
    ├── app/                ← Laravel PHP (controllers, services, models)
    ├── routes/             ← API + SPA routing
    ├── database/           ← schema & seeds
    ├── resources/
    │   ├── js/src/         ← React SPA (“frontend”)
    │   └── views/          ← Blade shell for React
    ├── public/             ← web document root
    ├── vite.config.js
    └── package.json
```

**Where to edit:** UI → `app/resources/js/src/` · API / business logic → `app/app/` · Production server layout → [deploy/DEPLOY.md](deploy/DEPLOY.md) (server path is `public_html/lidoportfolio/`, not `app/`).

</details>

## Prerequisites

| Tool | Purpose |
|------|---------|
| **MySQL** | Must be running before migrations / app use |
| **PHP 8.3+** | `mbstring`, `pdo_mysql`, `openssl`, `curl`, `json`, … |
| **Composer** | PHP dependencies in `app/` |
| **Node.js + npm** | Frontend build / Vite dev server |

You do **not** need Apache or EasyPHP if you use Laravel’s built-in server (`php artisan serve`). EasyPHP/XAMPP are fine as a **MySQL** (and optional Apache) provider.

**Full runbook** (services to start, ports, `.env` pitfalls, EasyPHP notes): see **[implementation.md → Local development runbook](implementation.md#local-development-runbook-agent--future-sessions)**.

## Quick start

```powershell
cd D:\Projects\LidoPortfolio\app

# Environment (first time)
copy .env.mysql.template .env
# Set DB_* credentials; then:
php artisan key:generate

# Local HTTP cookies (important)
# In .env set:
#   APP_URL=http://127.0.0.1:8001
#   SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8001,127.0.0.1,127.0.0.1:8001
#   SESSION_SECURE_COOKIE=false

composer install
npm install

php artisan migrate --force
php artisan db:seed

# Terminal 1 — API + SPA
php artisan serve --host=127.0.0.1 --port=8001

# Terminal 2 — frontend hot reload (development)
npm run dev
```

Open **http://127.0.0.1:8001**

**Login (after seed):** `admin@lidoportfolio.local` / `password123`

### One-command dev (alternative)

```powershell
cd app
composer run dev
```

Runs Laravel server, queue worker, log tail, and Vite together. If you need port **8001** for smoke tests, use the two-terminal quick start above instead (default `artisan serve` uses port 8000).

### Production-style assets (no Vite dev server)

```powershell
npm run build
php artisan serve --host=127.0.0.1 --port=8001
```

## What to run separately (checklist)

- [ ] **MySQL** — always
- [ ] **Web** — `php artisan serve` *or* Apache with document root `app/public`
- [ ] **Vite** — `npm run dev` only during UI development (optional if `npm run build` done)
- [ ] **Queue** — `php artisan queue:listen` (optional; included in `composer run dev`)
- [ ] **Scheduler** — `php artisan schedule:work` (daily prices, universe sync, screeners, calendar reminders, history-depth backfill, notifications)

## API smoke test

With the server on port 8001:

```powershell
PowerShell -ExecutionPolicy Bypass -File app\tests\Feature\api_smoke.ps1
```

## Documentation

| File | Description |
|------|-------------|
| [Features](#features) | Product feature overview (this README) |
| [Project structure](#project-structure) | Folder layout and how Laravel + React fit together |
| [implementation.md](implementation.md) | Living technical reference (agents: read first) |
| [debugging.md](debugging.md) | Production debug hooks & agent runbook |
| [app/API_DOCUMENTATION.md](app/API_DOCUMENTATION.md) | REST API |
| [deploy/DEPLOY.md](deploy/DEPLOY.md) | **Production deploy** (lidoalexion.com/portfolio, updates) |
| [`.cursor/skills/deploy-cpanel/SKILL.md`](.cursor/skills/deploy-cpanel/SKILL.md) | Agent deploy workflow (build + upload table) |
| [DEPLOYMENT_CPANEL.md](DEPLOYMENT_CPANEL.md) | Generic cPanel notes (other hosts) |
| [DEPLOYMENT_VALIDATION_PLAN.md](DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post deploy validation checklist |

## Notes

- Table names are prefixed with `portfolio_` so the app can coexist with other projects in the same MySQL database.
- Production DB: shared `/home/USER/config/DBConfig.php` (see `deploy/DEPLOY.md`). Local dev may use `app/config/DBConfig.php`.
- **Production `/portfolio`:** build with `VITE_APP_BASE=/portfolio/build/`; use root-relative Vite URLs (see [implementation.md → Production learnings](implementation.md#deployment-validation)). Delete temporary `cpanel-*.php` and debug HTML from the server after troubleshooting (`deploy/README.md`).
