# Project Statistics

**Audit date:** 2026-07-25  
**Measured from:** `D:\Projects\LidoPortfolio\app` (unless noted)  
**Method:** File counts / line counts via PowerShell; approximate LOC (physical lines including blanks/comments)

---

## Counts

| Metric | Value | Notes |
|--------|------:|-------|
| Total backend source files (PHP under `app/app`) | **217** | Excludes `vendor/`, `storage/`, `bootstrap/cache` |
| Total frontend source files (`resources/js` `*.js/jsx/ts/tsx`) | **161** | Excludes `node_modules` |
| Total database tables (Schema::create in migrations) | **~58** | Create statements across migrations; live DB may differ |
| Trading OS tables (`portfolio_tos_*`) | **12** | Including reviews + pipeline |
| Total REST endpoints (Trading OS `/api/v1`) | **29** | See API_INVENTORY.md |
| Legacy `/api/*` routes | **Many (100+)** | Full portfolio product surface |
| Total React pages (`src/pages`) | **33** | |
| Trading OS React pages | **5** | Candidates, Evaluations, Recommendations, Review, Notify log |
| Total React components (`src/components`) | **62** | |
| Total migrations | **48** | `database/migrations/*.php` |
| Total scheduled job registrations | **16** | `Schedule::` occurrences in `routes/console.php` (includes conditional TOS pipeline) |
| Total PHPUnit test files (`*Test.php`) | **99** | |
| Trading OS dedicated feature tests | **1** | `TradingOsPipelineTest.php` (4 scenarios) |
| Total Vitest / frontend unit tests | **0** | No `*.test.*` / `*.spec.*` under `resources/js` |
| Eloquent models | **46** | `app/Models` |
| Controllers | **38** | |
| Services | **88** | |
| Engine PHP files | **9** | 7 engines + Pipeline + ApiEnvelope |
| Jobs | **3** | |
| Middleware classes | **4** | |
| Repositories | **0** | Directory absent |

---

## Approximate lines of code

| Area | Approx. LOC |
|------|------------:|
| Backend PHP (`app/app`) | **29,975** |
| Engines only | **1,903** |
| Frontend (`resources/js/src` incl. CSS) | **30,372** |
| PHP tests (`tests/`) | **13,274** |
| Frontend tests | **0** |

*LOC are approximate physical lines, not statement counts.*

---

## Third-party libraries

### PHP (`composer.json` require)

| Package | Role |
|---------|------|
| `php` (^8.x) | Runtime |
| `laravel/framework` | Application framework |
| `laravel/sanctum` | SPA session authentication |
| `laravel/tinker` | REPL |

*(Transitive Laravel ecosystem packages via framework.)*

### JavaScript (`package.json` dependencies)

| Package | Role |
|---------|------|
| `react`, `react-dom`, `react-is` | UI |
| `react-router-dom` | Routing |
| `axios` | HTTP client |
| `bootstrap`, `@popperjs/core` | Styling / overlays |
| `recharts` | Charts |
| `@tanstack/react-table` | Tables |
| `@dnd-kit/*` | Drag-and-drop |
| `@tiptap/*`, `marked` | Rich text / markdown (knowledge board) |

### JavaScript devDependencies

| Package | Role |
|---------|------|
| `vite`, `@vitejs/plugin-react`, `laravel-vite-plugin` | Build |
| `tailwindcss`, `@tailwindcss/vite` | CSS toolchain |
| `concurrently` | Dev process runner |

**Not present vs Application Architecture Spec:** JWT library, TanStack Query, AG Grid, Chart.js / Lightweight Charts (Recharts used instead), TypeScript.
