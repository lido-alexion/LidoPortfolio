# Strategy Registry — Migration Notes

| Field | Value |
|-------|-------|
| **Document** | Strategy Registry Migration |
| **Version** | 1.0 |
| **Date** | 2026-07-30 |
| **Status** | Implementation landed |
| **Related** | [Trading-Artifact-Registry-Migration.md](./Trading-Artifact-Registry-Migration.md) · [Screener-Registry-Migration.md](./Screener-Registry-Migration.md) · [Trading-Artifact-JSON-Specification.md](./Trading-Artifact-JSON-Specification.md) |

---

# 1. Migration summary

## What changed

| Area | Change |
|------|--------|
| DB | Additive columns on `portfolio_tos_strategies`: `slug`, `definition_hash`, `intent`, `summary`, `tags_json`; `definition_hash` on versions |
| Minervini | Auto-migrate: `factory_key=momentum_factory` → slug `momentum_strategy`; eligibility linked to `minervini_trend_template` Screener (unchanged seed path) |
| API | New `/api/v1/strategy-registry*` (list, meta, selection, CRUD, validate, import, export, versions, **activate**) |
| UI | `/strategy/registry` (+ detail); admin `/settings/strategy-registry` |
| Export | Portable only — `screener_slug` / `screener_factory_key` + Indicator registry keys; **no** portfolio `screener_id` |
| Import | Creates **draft**; does not change Recommendations until **Select/activate** |
| BC | `GET/PUT /api/v1/strategy` + Strategy editor Save remain the active in-place editor |

## What did **not** change

- Recommendation scoring / allocation / exit algorithms
- Screener execution
- Rule: Strategies **never** embed Screener condition trees
- Exactly **one** active Strategy per portfolio

## Migration file

`app/database/migrations/2026_07_30_000300_strategy_registry_metadata.php`

### Production (cPanel)

1. Upload migration + PHP/JS build.
2. Run: `https://YOUR-DOMAIN/portfolio/cpanel-migrate.php?token=YOUR_TOKEN`
3. Delete `cpanel-migrate.php` after success.

### Local

```text
php artisan migrate
```

Existing portfolios: first `ensureActive` / Strategy Registry list call continues to seed Minervini if missing and backfills registry slug/metadata via seed + `ensureRegistryFields`.

---

# 2. Architecture summary

```text
┌─────────────────────┐     references (slug / factory_key)     ┌──────────────────┐
│ Strategy Registry   │ ──────────────────────────────────────► │ Screener Registry│
│ portfolio_tos_*     │     never embeds definition_json         │ portfolio_screeners│
└─────────┬───────────┘                                         └────────┬─────────┘
          │ select exactly one active                                      │
          ▼                                                                │
┌─────────────────────┐     Indicator registry ids (scoring keys)          │
│ Active Strategy     │ ◄──────────────────────────────────────────────────┘
│ PUT /v1/strategy    │     uses_indicator deps
│ Recommendation Eng. │
└─────────────────────┘
```

| Layer | Responsibility |
|-------|----------------|
| `StrategyArtifactRegistry` | Envelope list/get/create/update/validate/export/import/activate |
| `StrategyRegistrySupport` | Portable eligibility resolve/export, activate, hash/slug |
| `StrategyConfigurationService` | Active editor BC (`ensureActive`, `updateActiveConfig`, Minervini seed) |
| `StrategyEligibilityService` | Junction sync + Minervini factory Screener ensure |
| UI `/strategy` | Edit **active** strategy |
| UI `/strategy/registry` | Catalogue, JSON I/O, **Select** |

**Selection:** `POST .../activate` archives other actives and sets one `STATUS_ACTIVE`.

**Versioning:** Draft definition-hash changes append `portfolio_tos_strategy_versions` rows. Active editor Save remains **in-place** (BC).

---

# 3. API surface (additive)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/strategy-registry/meta` | Counts + enums |
| GET | `/strategy-registry/selection` | Current active envelope |
| GET | `/strategy-registry` | List (filters: `q`, `status`, `origin`) |
| GET | `/strategy-registry/{id}` | Id, slug, or factory_key |
| GET | `/strategy-registry/{id}/versions` | Version history |
| POST | `/strategy-registry` | Create draft from envelope |
| PUT | `/strategy-registry/{id}` | Update (active → `updateActiveConfig`; draft → version fork on hash change) |
| POST | `/strategy-registry/validate` | Validate only |
| POST | `/strategy-registry/import` | Validate + create draft |
| POST | `/strategy-registry/{id}/export` | Portable export |
| POST | `/strategy-registry/{id}/activate` | Select as sole active |

Generic `/api/v1/artifacts/strategy*` still works.

---

# 4. Remaining future enhancements

1. **Shared Strategy packs** across portfolios (like shared Screeners).
2. **Publish fork for active** — optional new version row on every active Save (today: in-place BC).
3. **Package bundle UI** — one-click import of Strategy + required Screeners + Indicator drafts.
4. **Dependency health dashboard** — broken Screener refs / missing Indicator capability flags.
5. **Rollback activate** — restore previous active by version id from UI.
6. **System-scoped factory catalogue** — read-only global strategies not copied per portfolio (today: per-portfolio seed of Minervini).
7. **AI generation guards** — enforce `metadata.ai.forbidden_changes` at validate time beyond current tree/weight checks.

---

# 5. Verification checklist

- [ ] Migrate succeeds; Minervini has slug `momentum_strategy`
- [ ] `GET /api/v1/strategy` still returns active Minervini config
- [ ] Export contains `screener_slug` / `screener_factory_key`, no `screener_id`
- [ ] Import creates draft; Recommendations unchanged until Select
- [ ] Select switches active; previous becomes archived
- [ ] Strategy editor Save still works on the selected strategy
