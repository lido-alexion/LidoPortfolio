# Architecture specs migration summary

**Date:** 2026-08-06  
**Type:** Documentation-only reorganization (no implementation code changes)  
**Method:** `git mv` to preserve history; filenames and document numbers unchanged

---

## Objective

Reorganize architecture specifications into a domain-driven folder hierarchy under `specs/architecture/`, keep content intact, and update all affected references.

---

## Target structure

```text
specs/architecture/
├── README.md                 (new)
├── MIGRATION-SUMMARY.md      (this file)
├── platform/
├── ui/
├── indicators/
├── portfolio/
├── data/
├── engines/                  (moved from specs/engines/)
├── live-trading/             (unchanged location)
├── governance/               (moved from specs/governance/)
└── audit/                    (moved from specs/audit/)
```

---

## Files moved

### platform/ (from `specs/architecture/`)

| File |
|------|
| `01-Vision.md` |
| `02-Guiding-Principles.md` |
| `03-Core-Concepts.md` |
| `04-System-Architecture.md` |
| `05-Daily-Decision-Pipeline.md` |
| `06-Engine-Overview.md` |

### ui/ (from `specs/architecture/`)

| File |
|------|
| `07-Trading-OS-Pages-and-Flow.md` |
| `15-Sidebar-Navigation-Architecture.md` |

### indicators/ (from `specs/architecture/`)

| File |
|------|
| `08-Indicator-Architecture-Analysis.md` |
| `09-Indicator-Registry.md` |
| `10-Indicator-Registry-Implementation-Plan.md` |
| `11-Trading-Artifact-Framework.md` |
| `13-Indicator-Lifecycle.md` |
| `14-Indicator-Registry-Diagrams.md` |

### portfolio/ (from `specs/engines/`)

| File |
|------|
| `Dashboard-Specification.md` |
| `Cash-Management-Specification.md` |
| `Discovery-Specification.md` |
| `Analytics-Architecture-Specification.md` |
| `Portfolio-Specification.md` |
| `Portfolio-Analytics-Specification.md` |
| `Watchlist-Specification.md` |

### data/ (from `specs/engines/`)

| File |
|------|
| `Database-Schema-Specification.md` |
| `Data-Engine-Specification.md` |

### Folder moves

| From | To |
|------|----|
| `specs/engines/` (remainder) | `specs/architecture/engines/` |
| `specs/governance/` | `specs/architecture/governance/` |
| `specs/audit/` | `specs/architecture/audit/` |

### Unchanged

| Path | Notes |
|------|-------|
| `specs/architecture/live-trading/` | Left in place per instructions |
| `specs/IMPLEMENTATION_PROGRESS.md` | Remains at `specs/` root |
| `specs/MVP_DEMO_CHECKLIST.md` | Remains at `specs/` root |

### New files

| File | Purpose |
|------|---------|
| `specs/architecture/README.md` | Architecture hub: overview, folder descriptions, index, reading order |
| `specs/architecture/MIGRATION-SUMMARY.md` | This summary |

---

## Links / indexes updated

| Area | Updates |
|------|---------|
| `DOCS.md` | All §2–§4 paths; folder diagram; architecture hub pointer; live-trading index row |
| `specs/README.md` | Rewritten tree + Phase A–C checklist for new paths |
| `specs/architecture/README.md` | New hub |
| `implementation.md` | Spec paths + reorganization note |
| Cross-links inside moved markdown | Relative `../architecture/NN`, `./sibling`, up-links to `DOCS.md` / specs `README.md` |
| `app/resources/js/src/data/appDocumentation.js` | Path strings for sidebar + trading-os-flow topics |
| `app/public/docs/overview.html`, `trading-os-flow.html` | Matching path strings |
| `app/public/docs/stox-trading-artifacts-ai-guide.md` | Repo copy path |
| `app/resources/js/src/navigation/README.md` | Canonical sidebar spec path |
| Governance / audit / engines internals | Cross-domain relative links |

---

## Ambiguous / judgment classifications

| Document | Classification chosen | Notes |
|----------|----------------------|-------|
| `07-Trading-OS-Pages-and-Flow.md` | **ui/** | Page map / UX flow (not portfolio-only) |
| `Watchlist-Specification.md` | **portfolio/** | Research workspace; not listed explicitly; grouped with portfolio domain |
| `Analytics-Architecture-Specification.md` | **portfolio/** | Listed under Portfolio “Analytics” |
| `Market-Analysis-Engine-Specification.md` | **engines/** | Remains an engine contract (not moved to portfolio) |
| `Indicator-Registry-Specification.md` + API | **engines/** | Detailed engine/contract specs; architecture overview docs live in **indicators/** |
| `Trading-Artifact-*-Specification.md` + migrations + AI guide | **engines/** | Contract / migrate / AI pack; architecture overview is **indicators/11** |
| `Application-Architecture-Specification.md` | **engines/** | Could be argued as platform; kept with contracts |
| `System-Domain-Model.md`, `REST-API-Specification.md` | **engines/** | Domain/API contracts |
| `Implementation-Roadmap.md` | **engines/** | Historical intent timeline |

---

## Intentionally not updated (implementation code)

Path mentions in PHP comments / route headers were left unchanged to avoid non-doc code edits:

- `app/routes/api.php` (comment referencing `specs/engines/REST-API-Specification.md`)
- `app/database/migrations/2026_07_25_000002_create_portfolio_tos_engine_tables.php` (comment `specs/engines/*`)
- `app/app/Engines/Pipeline/DailyDecisionPipeline.php` (comment `architecture/05-...`)

These are stale comment paths only; functional behavior is unchanged.

---

## Validation

- Relative markdown links under `specs/` + `DOCS.md` + `implementation.md` resolved against the filesystem (excluding `/docs/*.html` deploy-relative links inside the StoX AI guide).
- Key files verified present in each domain folder.
- Live Trading folder not moved.
