# Trading Artifact Registry — Migration Guide

| Field | Value |
|-------|-------|
| **Document** | Trading Artifact Registry Migration |
| **Version** | 1.0 |
| **Date** | 2026-07-30 |
| **Status** | Implementation landed (infrastructure phase) |
| **Spec** | [./Trading-Artifact-Framework-Specification.md](./Trading-Artifact-Framework-Specification.md) · [./Trading-Artifact-JSON-Specification.md](./Trading-Artifact-JSON-Specification.md) |

---

# 1. What shipped in this phase

Additive **Trading Artifact Registry** infrastructure:

| Component | Role |
|-----------|------|
| `App\Services\Artifacts\*` | Envelope, hashing, validation, package I/O |
| `IndicatorArtifactRegistry` | Projects SD-033 `IndicatorRegistry` + draft table |
| `ScreenerArtifactRegistry` | Envelope CRUD over `portfolio_screeners` via `ScreenerService` |
| `StrategyArtifactRegistry` | Envelope list/get/export; draft create; update via existing config save when active |
| `ArtifactRegistry` | Umbrella list / package export / import |
| `GET|POST|PUT /api/v1/artifacts…` | New HTTP surface |

**Explicitly unchanged**

- Screener **execution** (`ScreenerRunService`, schedules, backtests)
- Strategy **scoring / recommendation** algorithms
- Recommendation Engine behaviour
- Existing `/api/screeners*` and `/api/v1/strategy*` contracts

---

# 2. Database migration

**File:** `app/database/migrations/2026_07_30_000100_create_portfolio_trading_artifact_drafts_table.php`

**Table:** `portfolio_trading_artifact_drafts`

Used only for **Indicator** create/update drafts (and imported indicator metadata). Release-shipped indicators remain in the in-code Indicator Registry (SD-028).

### Production (cPanel)

1. Upload the migration file under `lidoportfolio/database/migrations/`.
2. Upload new PHP under `lidoportfolio/app/Services/Artifacts/`, `Http/Controllers/Api/V1/ArtifactRegistryController.php`, `Models/TradingArtifactDraft.php`, `Providers/AppServiceProvider.php`, `routes/api.php`.
3. Run migrate in the browser:

`https://YOUR-DOMAIN/portfolio/cpanel-migrate.php?token=YOUR_TOKEN`

4. Delete `cpanel-migrate.php` after success.

No SSH / `php artisan` on production.

### Local

```text
php artisan migrate
```

---

# 3. API surface (additive)

Base: `/api/v1` · Auth: Sanctum + active portfolio

| Method | Path | Notes |
|--------|------|-------|
| GET | `/artifacts` | List (optional `type`, `q`, `status`) |
| GET | `/artifacts/{type}` | `indicator` \| `screener` \| `strategy` |
| GET | `/artifacts/{type}/{id}` | Id, slug, or factory_key |
| POST | `/artifacts/{type}` | Create (indicator drafts = **admin only**) |
| PUT | `/artifacts/{type}/{id}` | Update |
| POST | `/artifacts/{type}/validate` | Validate envelope |
| POST | `/artifacts/{type}/{id}/export` | Single artifact export |
| POST | `/artifacts/export` | Package (`targets[{type,id}]`) |
| POST | `/artifacts/import` | Import package → drafts / new screeners / draft strategies |
| POST | `/artifacts/validate` | Validate package artifacts |

Existing admin Indicator discovery remains:

- `GET /api/v1/indicators`, `/meta`, `/{id}` (admin)

---

# 4. Behaviour matrix (BC)

| Action | Indicator | Screener | Strategy |
|--------|-----------|----------|----------|
| List / Get | Registry projection (+ drafts) | Existing rows as envelopes | Existing strategies as envelopes |
| Create | **Draft row only** — not wired to TI/Evaluation | Creates `portfolio_screeners` via `ScreenerService` | Creates **draft** strategy — **does not** become active |
| Update | Drafts only; system indicators immutable | Updates definition via `ScreenerService` | Active → `updateActiveConfig` (same as Strategy UI Save); draft → version JSON only |
| Import | Drafts | New screeners | Draft strategies (not auto-activated) |
| Export | Metadata envelope (no calc code) | Condition tree | Config JSON + deps |

---

# 5a. Screener Registry phase (follow-on)

See **[./Screener-Registry-Migration.md](./Screener-Registry-Migration.md)** for:

- Metadata + `portfolio_screener_versions` migration (`2026_07_30_000200_*`)
- Dedicated `/api/v1/screener-registry*` APIs
- Registry UI (`/screeners/registry`) + admin settings page
- Shared screener projection into the Registry

# 5b. Strategy Registry phase (follow-on)

See **[./Strategy-Registry-Migration.md](./Strategy-Registry-Migration.md)** for:

- Metadata on `portfolio_tos_strategies` (`2026_07_30_000300_*`)
- Dedicated `/api/v1/strategy-registry*` + **activate/selection**
- Portable export (no portfolio Screener ids); Minervini auto-migrate to `momentum_strategy`
- Registry UI (`/strategy/registry`) + admin settings page

---

# 5. Rollback

1. Stop calling `/api/v1/artifacts*`.
2. `migrate:rollback` one step (or drop `portfolio_trading_artifact_drafts`).
3. Remove Artifact service/controller/route files.
4. Keep Indicator Registry (SD-033) and existing Screener/Strategy APIs.

Rollback does **not** require undoing Screener/Strategy data created through the Artifact API if those rows are still desired — they are normal screener/strategy records.

---

# 6. Follow-ups (not in this phase)

1. Activate draft Strategy → bind as portfolio active (explicit UX gate).
2. Admin UI for Artifact Registry (beyond Indicator Admin list).
3. Persist Screener/Strategy version history tables.
4. Wire imported indicator drafts into TI only via application releases.
5. AI draft UX using JSON examples under `specs/architecture/domains/artifacts/examples/`.

---

# 7. Verification checklist

- [ ] Existing screeners still run from Screener UI
- [ ] Active strategy still scores / recommends as before
- [ ] `GET /api/v1/artifacts/indicator/rsi` returns envelope
- [ ] `GET /api/v1/indicators` (admin) still works
- [ ] Migration applied; drafts table exists
- [ ] Creating a draft strategy does **not** change the active strategy
