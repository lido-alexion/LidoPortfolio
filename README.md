# Lido Portfolio Tracker

Self-hosted Indian stock portfolio tracker:

- **Backend:** Laravel (PHP 8.3+)
- **Frontend:** React + Bootstrap (Vite, served by Laravel)
- **Database:** MySQL (`portfolio_*` tables; can share an existing database)
- **Notifications:** Telegram Bot API (optional)

<details id="project-structure">
<summary><strong>Project structure</strong> — how the repo is organized (click to expand)</summary>

The folder **`app/`** is the **application root** (Laravel + React together). It is not “API only” — the React UI lives inside it by design. This is the standard **Laravel + Vite + React SPA** pattern: one deployable app, one `composer install` + `npm install` in `app/`.

```
Browser  →  Laravel (app/)  →  app.blade.php  →  React SPA  →  /api/*  →  Services / MySQL
```

#### Top-level layout (`LidoPortfolio/`)

| Path | What it is |
|------|------------|
| **`README.md`** | Quick start and this structure guide |
| **`implementation.md`** | Architecture, runbook, and agent reference (read for deep detail) |
| **`app/`** | **Full application** — Laravel API, React SPA, config, tests |
| **`deploy/`** | Production deploy scripts, `.htaccess` snippets, cPanel guides |
| **`.cursor/`** | Cursor IDE rules for this project |

There is **no separate `frontend/` folder** at the repo root.

#### Inside `app/` — the main application

Think of `app/` as a normal Laravel project root (`artisan`, `.env`, `composer.json`, and `package.json` all live here).

**PHP / API (server-side)**

| Path | Contains |
|------|----------|
| **`app/Http/Controllers/Api/`** | REST API controllers (holdings, transactions, dashboard, stocks, auth, …) |
| **`app/Services/`** | Business logic (price fetch, portfolio math, XIRR, notifications, …) |
| **`app/Models/`** | Eloquent models (`Stock`, `Holding`, `Transaction`, …) |
| **`app/Jobs/`** | Background jobs (price backfill, daily sync, alerts) |
| **`app/Console/Commands/`** | CLI commands (`portfolio:daily-sync`, stock master sync, …) |
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
| **`resources/js/src/pages/`** | Screens (Dashboard, Holdings, Transactions, Settings, …) |
| **`resources/js/src/components/`** | Reusable UI (header, tables, autocomplete, …) |
| **`resources/js/src/context/`** | React context (auth, theme) |
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
| **`storage/`** | Logs, cache, uploaded files |
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
- [ ] **Scheduler** — `php artisan schedule:work` or Windows Task Scheduler running `php artisan portfolio:daily-sync` (for daily prices / dashboard growth chart)

## API smoke test

With the server on port 8001:

```powershell
PowerShell -ExecutionPolicy Bypass -File app\tests\Feature\api_smoke.ps1
```

## Documentation

| File | Description |
|------|-------------|
| [Project structure](#project-structure) | Folder layout and how Laravel + React fit together |
| [implementation.md](implementation.md) | Living technical reference (agents: read first) |
| [app/API_DOCUMENTATION.md](app/API_DOCUMENTATION.md) | REST API |
| [deploy/DEPLOY.md](deploy/DEPLOY.md) | **Production deploy** (lidoalexion.com/portfolio, updates) |
| [DEPLOYMENT_CPANEL.md](DEPLOYMENT_CPANEL.md) | Generic cPanel notes (other hosts) |
| [DEPLOYMENT_VALIDATION_PLAN.md](DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post deploy validation checklist |

## Notes

- Table names are prefixed with `portfolio_` so the app can coexist with other projects in the same MySQL database.
- Production DB: shared `/home/USER/config/DBConfig.php` (see `deploy/DEPLOY.md`). Local dev may use `app/config/DBConfig.php`.
