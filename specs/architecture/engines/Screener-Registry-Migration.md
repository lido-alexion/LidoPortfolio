# Screener Registry — Migration Notes

| Field | Value |
|-------|-------|
| **Document** | Screener Registry Migration |
| **Version** | 1.0 |
| **Date** | 2026-07-30 |
| **Status** | Implementation landed |
| **Related** | [./Trading-Artifact-Registry-Migration.md](./Trading-Artifact-Registry-Migration.md) · [./Trading-Artifact-JSON-Specification.md](./Trading-Artifact-JSON-Specification.md) |

---

# 1. Goal

Evolve existing Screeners into **first-class reusable artifacts** without redesigning Screener execution.

| Kept | Added |
|------|-------|
| `definition_json` condition tree | Registry metadata (`slug`, `intent`, `summary`, `tags_json`, `artifact_status`) |
| `ScreenerRunService` / schedules / backtests | Versioning (`artifact_version`, `definition_hash`, `portfolio_screener_versions`) |
| `/api/screeners*` editor + run APIs | Dedicated `/api/v1/screener-registry*` + Registry UI |

Existing Screeners continue to work **without modification**. Shared Screeners (`is_shared`) appear naturally in the Registry as read-only entries.

---

# 2. Database

**Migration:** `app/database/migrations/2026_07_30_000200_screener_registry_metadata_and_versions.php`

### Columns on `portfolio_screeners` (additive)

| Column | Purpose |
|--------|---------|
| `slug` | Stable registry id (unique per portfolio); backfilled from `factory_key` or name |
| `artifact_version` | Monotonic version integer (starts at 1) |
| `definition_hash` | Canonical hash of `definition_json` |
| `intent` / `summary` | Artifact metadata |
| `tags_json` | Free-form tags array |
| `artifact_status` | `active` / `draft` / … |

### New table `portfolio_screener_versions`

Snapshots of definition (+ metadata) per version. Cascade-deletes with the screener.

### Backfill behaviour

On migrate, every existing screener gets:

1. A unique `slug`
2. `artifact_version = 1` and current `definition_hash`
3. An initial `portfolio_screener_versions` row (notes: *Initial version (Screener Registry backfill)*)

No definition trees are rewritten.

### Production (cPanel)

1. Upload the migration + PHP/JS build artifacts.
2. Run migrate in the browser:

`https://YOUR-DOMAIN/portfolio/cpanel-migrate.php?token=YOUR_TOKEN`

3. Delete `cpanel-migrate.php` after success.

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
| GET | `/screener-registry/meta` | Counts + filter enums |
| GET | `/screener-registry` | Own + shared available (filters: `q`, `status`, `ownership`, `origin`) |
| GET | `/screener-registry/{id}` | Id, slug, or factory_key |
| GET | `/screener-registry/{id}/versions` | Owned screeners only |
| POST | `/screener-registry` | Create from envelope |
| PUT | `/screener-registry/{id}` | Update owned envelope |
| POST | `/screener-registry/validate` | Validate envelope (no write) |
| POST | `/screener-registry/import` | Validate + create new screener |
| POST | `/screener-registry/{id}/export` | Export envelope JSON |
| POST | `/screener-registry/shared/{sourceId}/import` | Copy shared → own portfolio |

Generic `/api/v1/artifacts/screener*` remains available (same registry service).

**Unchanged:** `/api/screeners*` run, schedule, backtest, classic CRUD.

---

# 4. JSON format

Uses the approved Trading Artifact envelope (`artifact_type: screener`):

- `definition.root` — existing group/condition tree (same as `definition_json`)
- `metadata.universe` maps to Screener `scope` (`all_active_equities` → `all_equities`)
- Indicator refs must resolve in the Indicator Registry when validated

Examples: `specs/architecture/engines/artifacts/examples/screener-*.json`

---

# 5. Shared Screeners → Registry

| Before | After |
|--------|-------|
| Shared tab lists `is_shared` from other portfolios | Registry list includes them with `metadata.ownership = shared`, `read_only = true` |
| `POST /api/screeners/shared/{id}/import` | Also `POST /api/v1/screener-registry/shared/{id}/import` |
| Copied row is a normal portfolio screener | Copy receives registry slug/version like any new screener |

No data migration of shared flags is required — projection is read-time.

---

# 6. UI

| Route | Audience |
|-------|----------|
| `/screeners/registry` | All portfolio users |
| `/screeners/registry/:id` | Detail + versions + export |
| `/settings/screener-registry` | Admin (same UI) |

Classic `/screeners` gains a **Screener Registry** button. Settings (Global admin) links the admin page.

---

# 7. Behaviour matrix (BC)

| Action | Behaviour |
|--------|-----------|
| Existing run / schedule / backtest | Unchanged |
| Classic create/update via `/api/screeners` | Still works; create/update now also maintains slug + version snapshots when definition changes |
| Registry import | Always **creates** a new screener (never overwrites by slug) |
| Strategy references | Still by screener id / factory_key — no embed of condition trees |

---

# 8. Rollback

1. Stop using `/api/v1/screener-registry*` and Registry UI routes.
2. Optionally roll back migration `2026_07_30_000200_*` (drops versions table + metadata columns).
3. Classic `/api/screeners*` continues without those columns if rollback completes before new code relies on them — prefer forward-only: leave columns, ignore Registry UI.

---

# 9. Verification checklist

- [ ] Migrate succeeds; existing screeners have `slug` and a v1 version row
- [ ] Run an existing screener — results unchanged
- [ ] Export → Validate → Import creates a second screener with same definition tree
- [ ] Shared screener appears in Registry; Import copy works
- [ ] Edit definition in classic editor → `artifact_version` increments
