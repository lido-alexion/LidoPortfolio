# Lido Portfolio Tracker

Self-hosted Indian stock portfolio tracker:

- **Backend:** Laravel (PHP 8.3+)
- **Frontend:** React + Bootstrap (Vite, served by Laravel)
- **Database:** MySQL (`portfolio_*` tables; can share an existing database)
- **Notifications:** Telegram Bot API (optional)

## Prerequisites

| Tool | Purpose |
|------|---------|
| **MySQL** | Must be running before migrations / app use |
| **PHP 8.3+** | `mbstring`, `pdo_mysql`, `openssl`, `curl`, `json`, … |
| **Composer** | PHP dependencies in `backend/` |
| **Node.js + npm** | Frontend build / Vite dev server |

You do **not** need Apache or EasyPHP if you use Laravel’s built-in server (`php artisan serve`). EasyPHP/XAMPP are fine as a **MySQL** (and optional Apache) provider.

**Full runbook** (services to start, ports, `.env` pitfalls, EasyPHP notes): see **[implementation.md → Local development runbook](implementation.md#local-development-runbook-agent--future-sessions)**.

## Quick start

```powershell
cd D:\Projects\LidoPortfolio\backend

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
cd backend
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
- [ ] **Web** — `php artisan serve` *or* Apache with document root `backend/public`
- [ ] **Vite** — `npm run dev` only during UI development (optional if `npm run build` done)
- [ ] **Queue** — `php artisan queue:listen` (optional; included in `composer run dev`)
- [ ] **Scheduler** — `php artisan schedule:work` or Windows Task Scheduler running `php artisan portfolio:daily-sync` (for daily prices / dashboard growth chart)

## API smoke test

With the server on port 8001:

```powershell
PowerShell -ExecutionPolicy Bypass -File backend\tests\Feature\api_smoke.ps1
```

## Documentation

| File | Description |
|------|-------------|
| [implementation.md](implementation.md) | Living technical reference (agents: read first) |
| [backend/API_DOCUMENTATION.md](backend/API_DOCUMENTATION.md) | REST API |
| [deploy/DEPLOY.md](deploy/DEPLOY.md) | **Production deploy** (lidoalexion.com/portfolio, updates) |
| [DEPLOYMENT_CPANEL.md](DEPLOYMENT_CPANEL.md) | Generic cPanel notes (other hosts) |
| [DEPLOYMENT_VALIDATION_PLAN.md](DEPLOYMENT_VALIDATION_PLAN.md) | Pre/post deploy validation checklist |

## Notes

- Table names are prefixed with `portfolio_` so the app can coexist with other projects in the same MySQL database.
- Production DB: shared `/home/USER/config/DBConfig.php` (see `deploy/DEPLOY.md`). Local dev may use `backend/config/DBConfig.php`.
