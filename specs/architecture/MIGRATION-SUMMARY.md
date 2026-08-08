# Architecture specs migration summary

**Type:** Documentation-only reorganization (no implementation code changes)  
**Method:** `git mv` to preserve history; filenames and document numbers unchanged

---

## Pass 2 — Final cleanup (2026-08-06)

### Objectives

- Rename `engines/` → `domains/` (folder holds contracts and domain specs, not only engines)
- Move `System-Domain-Model.md` into `platform/` (platform-wide shared entities)
- Add `integrations/` placeholder domain
- Refresh architecture hub README and indexes

### Folders renamed

| From | To |
|------|----|
| `specs/architecture/engines/` | `specs/architecture/domains/` |

### Files moved

| File | From | To |
|------|------|----|
| `System-Domain-Model.md` | `specs/architecture/domains/` | `specs/architecture/platform/` |

### Files / folders created

| Path | Purpose |
|------|---------|
| `specs/architecture/integrations/README.md` | Placeholder for external integration specs |
| `specs/architecture/README.md` | Updated for domains + integrations + SDM location |

### References updated

| Area | Change |
|------|--------|
| `DOCS.md` | `engines/` → `domains/`; SDM → `platform/`; integrations index; folder diagram |
| `specs/README.md` | Tree, reading order, checklist paths |
| `specs/architecture/README.md` | Full hub rewrite |
| Cross-links under `specs/architecture/**` | `../engines/` → `../domains/`; SDM → `../platform/` |
| `implementation.md` | Spec path strings |
| `app/public/docs/stox-trading-artifacts-ai-guide.md` | Repo copy path |
| `app/scripts/generate-static-docs.mjs` | Output path for AI guide mirror |
| Governance / audit notes | Folder location strings |

### Validation (Pass 2)

- Relative markdown links under `specs/` + `DOCS.md` + `implementation.md`: **440 checked, 0 broken** (excluding deploy-relative `*.html` links in the StoX AI guide)
- `node scripts/generate-static-docs.mjs`: succeeded; AI guide mirror written to `specs/architecture/domains/StoX-Trading-Artifacts-AI-Guide.md`
- Confirmed absent: `specs/architecture/engines/`, `specs/engines/`
- Confirmed present: `domains/`, `platform/System-Domain-Model.md`, `integrations/README.md`
- No specification filenames renumbered or renamed

---

## Pass 1 — Domain-folder layout (2026-08-06)

### Objective

Reorganize architecture specifications into a domain-driven folder hierarchy under `specs/architecture/`, keep content intact, and update all affected references.

### Target structure (after Pass 1; Pass 2 renames `engines/` → `domains/`)

```text
specs/architecture/
├── README.md
├── MIGRATION-SUMMARY.md
├── platform/
├── ui/
├── indicators/
├── portfolio/
├── data/
├── engines/          → later renamed domains/
├── live-trading/
├── governance/
└── audit/
```

### Files moved (Pass 1)

#### platform/ (from `specs/architecture/`)

- `01-Vision.md` … `06-Engine-Overview.md`

#### ui/

- `07-Trading-OS-Pages-and-Flow.md`
- `15-Sidebar-Navigation-Architecture.md`

#### indicators/

- `08` … `11`, `13`, `14` indicator/artifact architecture docs

#### portfolio/ (from `specs/engines/`)

- Dashboard, Cash, Discovery, Analytics, Portfolio, Portfolio-Analytics, Watchlist specs

#### data/ (from `specs/engines/`)

- `Database-Schema-Specification.md`
- `Data-Engine-Specification.md`

#### Folder moves

| From | To (Pass 1) | Later (Pass 2) |
|------|-------------|----------------|
| `specs/engines/` (remainder) | `specs/architecture/engines/` | `specs/architecture/domains/` |
| `specs/governance/` | `specs/architecture/governance/` | — |
| `specs/audit/` | `specs/architecture/audit/` | — |

### Ambiguous classifications (Pass 1)

| Document | Classification | Notes |
|----------|----------------|-------|
| `07-Trading-OS-Pages-and-Flow.md` | **ui/** | Page map / UX flow |
| `Watchlist-Specification.md` | **portfolio/** | Research workspace |
| Indicator/Artifact **detail** specs | **domains/** (was engines) | Architecture overviews in **indicators/** |
| `System-Domain-Model.md` | **platform/** (Pass 2) | Initially kept with contracts; moved as platform-wide |

### Intentionally not updated (implementation code)

Path mentions in PHP comments / route headers left unchanged:

- `app/routes/api.php`
- `app/database/migrations/2026_07_25_000002_create_portfolio_tos_engine_tables.php`
- `app/app/Engines/Pipeline/DailyDecisionPipeline.php`

---

## Current structure (after Pass 2)

```text
specs/architecture/
├── README.md
├── MIGRATION-SUMMARY.md
├── platform/          (+ System-Domain-Model.md)
├── domains/           (renamed from engines/)
├── live-trading/
├── portfolio/
├── indicators/
├── data/
├── ui/
├── integrations/      (README placeholder)
├── governance/
└── audit/
```
