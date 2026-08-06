# Architecture Specifications

**Parent indexes:** [../README.md](../README.md) (specs hub) · [../../DOCS.md](../../DOCS.md) (repository documentation map)  
**Authority:** [governance/DOCUMENT_PRECEDENCE.md](governance/DOCUMENT_PRECEDENCE.md)

This folder is the **domain-driven architecture hub** for StoX / Lido Portfolio. Documents are grouped by domain; filenames and document numbers are unchanged.

---

## Overview

Architecture specs describe **long-term intent** (vision through engine contracts, UI structure, indicators, portfolio workspaces, data, live trading, governance, and audit evidence). They are **not** a claim that every SHALL matches production code — accepted deviations live in [governance/SPECIFICATION_DECISIONS.md](governance/SPECIFICATION_DECISIONS.md).

---

## Folder descriptions

| Folder | Purpose |
|--------|---------|
| [platform/](platform/) | Foundational intent: vision, principles, concepts, system architecture, daily pipeline, engine overview (01–06) |
| [ui/](ui/) | UI architecture: Trading OS pages & flow (07), sidebar navigation (15) |
| [indicators/](indicators/) | Indicator & trading-artifact architecture (08–11, 13–14) |
| [portfolio/](portfolio/) | Portfolio-domain specs: dashboard, cash, discovery, analytics, holdings, watchlist |
| [data/](data/) | Database schema and Data Engine |
| [engines/](engines/) | Engine contracts, REST API, domain model, strategy/screener/artifact specs, roadmap |
| [live-trading/](live-trading/) | Live trading & order execution subsystem (kept in place) |
| [governance/](governance/) | V1.0 authority, decisions, scope, baseline, backlog |
| [audit/](audit/) | Freeze audit evidence pack |

---

## Document index

### platform/

| Doc | Title |
|-----|-------|
| [platform/01-Vision.md](platform/01-Vision.md) | Vision |
| [platform/02-Guiding-Principles.md](platform/02-Guiding-Principles.md) | Guiding Principles |
| [platform/03-Core-Concepts.md](platform/03-Core-Concepts.md) | Core Concepts |
| [platform/04-System-Architecture.md](platform/04-System-Architecture.md) | System Architecture |
| [platform/05-Daily-Decision-Pipeline.md](platform/05-Daily-Decision-Pipeline.md) | Daily Decision Pipeline |
| [platform/06-Engine-Overview.md](platform/06-Engine-Overview.md) | Engine Overview |

### ui/

| Doc | Title |
|-----|-------|
| [ui/07-Trading-OS-Pages-and-Flow.md](ui/07-Trading-OS-Pages-and-Flow.md) | Trading OS Pages and Flow |
| [ui/15-Sidebar-Navigation-Architecture.md](ui/15-Sidebar-Navigation-Architecture.md) | Sidebar Navigation Architecture |

### indicators/

| Doc | Title |
|-----|-------|
| [indicators/08-Indicator-Architecture-Analysis.md](indicators/08-Indicator-Architecture-Analysis.md) | Indicator Architecture Analysis (as-built) |
| [indicators/09-Indicator-Registry.md](indicators/09-Indicator-Registry.md) | Indicator Registry (target) |
| [indicators/10-Indicator-Registry-Implementation-Plan.md](indicators/10-Indicator-Registry-Implementation-Plan.md) | Indicator Registry Implementation Plan |
| [indicators/11-Trading-Artifact-Framework.md](indicators/11-Trading-Artifact-Framework.md) | Trading Artifact Framework |
| [indicators/13-Indicator-Lifecycle.md](indicators/13-Indicator-Lifecycle.md) | Indicator Lifecycle |
| [indicators/14-Indicator-Registry-Diagrams.md](indicators/14-Indicator-Registry-Diagrams.md) | Indicator Registry Diagrams |

### portfolio/

| Doc | Title |
|-----|-------|
| [portfolio/Dashboard-Specification.md](portfolio/Dashboard-Specification.md) | Dashboard |
| [portfolio/Cash-Management-Specification.md](portfolio/Cash-Management-Specification.md) | Cash Management |
| [portfolio/Discovery-Specification.md](portfolio/Discovery-Specification.md) | Discovery |
| [portfolio/Analytics-Architecture-Specification.md](portfolio/Analytics-Architecture-Specification.md) | Analytics Architecture |
| [portfolio/Portfolio-Specification.md](portfolio/Portfolio-Specification.md) | Portfolio |
| [portfolio/Portfolio-Analytics-Specification.md](portfolio/Portfolio-Analytics-Specification.md) | Portfolio Analytics |
| [portfolio/Watchlist-Specification.md](portfolio/Watchlist-Specification.md) | Watchlist |

### data/

| Doc | Title |
|-----|-------|
| [data/Database-Schema-Specification.md](data/Database-Schema-Specification.md) | Database Schema |
| [data/Data-Engine-Specification.md](data/Data-Engine-Specification.md) | Data Engine |

### engines/

Engine contracts, REST, strategy/screener/artifact specifications, migrations, AI guide, and [engines/Implementation-Roadmap.md](engines/Implementation-Roadmap.md). See [engines/](engines/) and [engines/artifacts/](engines/artifacts/).

### live-trading/

| Doc | Title |
|-----|-------|
| [live-trading/README.md](live-trading/README.md) | Live Trading hub |
| [live-trading/00-glossary.md](live-trading/00-glossary.md) | Glossary |
| [live-trading/01-overview.md](live-trading/01-overview.md) | Overview |
| [live-trading/02-execution-architecture.md](live-trading/02-execution-architecture.md) | Execution Architecture |
| [live-trading/03-security-and-authentication.md](live-trading/03-security-and-authentication.md) | Security and Authentication |

### governance/ · audit/

See [governance/README.md](governance/README.md) and [audit/README.md](audit/README.md).

---

## Recommended reading order

1. **Platform foundation:** `platform/01` → `06`
2. **UI map (optional early):** `ui/07` (pages & recommendation path)
3. **Indicators / artifacts (as needed):** `indicators/08` → `11`, then `13`–`14`
4. **Navigation (implementers):** `ui/15`
5. **Domain contracts:** `engines/System-Domain-Model`, `data/Database-Schema`, `engines/REST-API`, `engines/Application-Architecture`
6. **Pipeline engines:** Data → Evaluation → Recommendation → Notification → Execution → Review (under `data/` + `engines/`)
7. **Portfolio workspaces:** `portfolio/*` interleaved with related engine specs
8. **Live trading (new subsystem):** `live-trading/README` → `00` → `03`
9. **Governance:** `governance/DOCUMENT_PRECEDENCE` → decisions → scope → baseline → backlog
10. **Status & audit:** `../IMPLEMENTATION_PROGRESS.md`, `../MVP_DEMO_CHECKLIST.md`, then `audit/`

Full repository reading recipes: [../../DOCS.md](../../DOCS.md).

---

## Migration note

One-time domain reorganization (documentation only). See [MIGRATION-SUMMARY.md](MIGRATION-SUMMARY.md).
