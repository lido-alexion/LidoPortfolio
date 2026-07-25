# Repository Overview

**Audit date:** 2026-07-25  
**Root:** `D:\Projects\LidoPortfolio`

---

## Top-level layout

| Path | Description |
|------|-------------|
| `app/` | Application root (Laravel + React Vite SPA). Renamed from historical `backend/`. |
| `specs/` | Architecture + engine specifications and this audit pack |
| `specs/audit/` | Implementation audit deliverables |
| `deploy/` | cPanel/GoDaddy upload scripts and `cpanel-*.php` maintenance tools |
| `implementation.md` | Living technical reference for agents and humans |
| `README.md` | Quick start and project structure |
| `.cursor/` | Agent rules and skills (deploy, commit-push) |

---

## Backend folder structure (`app/`)

| Path | Description |
|------|-------------|
| `app/app/Console/Commands/` | Artisan commands including `RunDecisionPipelineCommand` (`portfolio:decision-pipeline`) |
| `app/app/Engines/` | Trading OS business engines (see below) |
| `app/app/Http/Controllers/` | HTTP controllers; TOS under `Api/V1/TradingOsController.php` |
| `app/app/Http/Middleware/` | Auth/session/portfolio middleware (e.g. active portfolio) |
| `app/app/Jobs/` | Queued jobs (legacy/async work; TOS primarily synchronous in request/command) |
| `app/app/Models/` | Eloquent models including `portfolio_tos_*` entities |
| `app/app/Services/` | Domain services (sync, patterns, screeners, holdings, Telegram, calculations) — **no Repositories directory** |
| `app/app/Providers/` | Service providers |
| `app/config/` | Laravel config including `trading_os.php` |
| `app/database/migrations/` | Schema migrations (48 files; TOS 2026-07-25_*) |
| `app/database/seeders/` | Seed data (admin user, etc.) |
| `app/routes/api.php` | REST routes including `/api/v1` |
| `app/routes/console.php` | Scheduler definitions |
| `app/tests/` | PHPUnit feature/unit tests |
| `app/public/` | Web document root |
| `app/resources/views/` | Blade shell for SPA |
| `app/resources/js/` | React frontend source |
| `app/vendor/` | Composer dependencies (not audited as product code) |

---

## Engines (`app/app/Engines/`)

| Path | Description |
|------|-------------|
| `Data/DataEngine.php` | Market dataset status, securities, price bars, import trigger |
| `Discovery/DiscoveryEngine.php` | Candidate generation from patterns/screener/membership |
| `Evaluation/EvaluationEngine.php` | Indicators, scoring, ranking, evidence |
| `Recommendation/RecommendationEngine.php` | Recommendation generation + user review lifecycle |
| `Notification/NotificationEngine.php` | Telegram queue/send/retry/history |
| `Execution/ExecutionEngine.php` | Orders pending/execute/cancel; positions/transactions |
| `Review/ReviewEngine.php` | Dashboard, outcomes, report generation |
| `Pipeline/DailyDecisionPipeline.php` | End-to-end stage orchestrator |
| `Support/ApiEnvelope.php` | Standard JSON success/error envelope |

---

## Services (major groups under `app/app/Services/`)

Approximately 88 PHP service classes. Major groups used by TOS:

- Market sync / price providers  
- Pattern scan  
- Screener + technical indicators + relative strength  
- Holdings / transaction realization / portfolio snapshots & calculations  
- Telegram notifications  
- Profile settings / logging  

Engines wrap these rather than reimplementing them.

---

## Repositories

**None.** Persistence via Eloquent models in `app/app/Models/`.

---

## Jobs (`app/app/Jobs/`)

Small set (3 PHP files at audit time). TOS pipeline runs synchronously via HTTP/Artisan; existing notification/sync schedules use commands more than dedicated TOS jobs.

---

## Middleware (`app/app/Http/Middleware/`)

Includes Sanctum session pipeline integration and active portfolio resolution used by `/api/v1`.

---

## Frontend folder structure (`app/resources/js/src/`)

| Path | Description |
|------|-------------|
| `App.jsx` | Router and auth-gated routes |
| `api.js` | Axios client (Sanctum cookies) |
| `config/mainNav.js` | Main navigation including TOS pages |
| `pages/` | 33 page components (5 TOS) |
| `components/` | 62 shared UI components (charts, screener, calendar, etc.) |
| `auth/` | CSRF/login helpers |
| `context/` / contexts | Auth and app state |
| `styles/` | Including `lido-app.css` |

**Not present (vs Application Architecture Spec):** `features/`, `store/`, `layouts/`, `types/` as first-class trees; TypeScript.

---

## Components (summary)

Reusable UI lives under `components/` (tables, charts, screener builders, knowledge board, indices, etc.). TOS MVP pages are self-contained and do not yet extract dedicated TOS component modules.

---

## Specs tree

| Path | Description |
|------|-------------|
| `specs/architecture/` | Vision, principles, concepts, system architecture, pipeline, engine overview |
| `specs/engines/` | Per-engine + REST + schema + roadmap + domain model |
| `specs/IMPLEMENTATION_PROGRESS.md` | Working MVP log + accepted assumptions |
| `specs/MVP_DEMO_CHECKLIST.md` | Demo acceptance checklist |
| `specs/audit/` | This audit pack |
