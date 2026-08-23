# Architecture Specifications

**Parent indexes:** [../README.md](../README.md) (specs hub) · [../../DOCS.md](../../DOCS.md) (repository documentation map)  
**Authority:** [governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) · [governance/DOCUMENT_PRECEDENCE.md](governance/DOCUMENT_PRECEDENCE.md)

This folder is the **domain-driven architecture hub** for StoX / Lido Portfolio. Documents are grouped by architectural domain; filenames and document numbers are unchanged.

**Mandatory for all new specs:** [governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) §7 *Specification Authoring Principles* and the **Golden Rule** — never duplicate an approved concept; reference the canonical specification instead. Platform-wide concepts belong under [platform/](platform/) — start at [platform/README.md](platform/README.md). New docs SHOULD use [governance/SPECIFICATION_HEADER_TEMPLATE.md](governance/SPECIFICATION_HEADER_TEMPLATE.md). **Architecture Repository V1.0 is Frozen:** [governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md](governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md).

---

## V3 product direction

| Doc | Title |
|-----|-------|
| [../LidoPortfolio-V3-Specification.md](../LidoPortfolio-V3-Specification.md) | V3 product SoT (**v0.28**) |
| [V3-WS4-Recall-Bridge-Implementation-Delta.md](V3-WS4-Recall-Bridge-Implementation-Delta.md) | WS4 recall / capital-resolution / Recall Bridge Loan implementation delta |

---

## Overview

Architecture specs describe **long-term intent** across platform foundations, business domains, UI, data, integrations, live trading, governance, and audit evidence. They are **not** a claim that every SHALL matches production code — accepted deviations live in [governance/SPECIFICATION_DECISIONS.md](governance/SPECIFICATION_DECISIONS.md).

---

## Folder descriptions

| Folder | Purpose |
|--------|---------|
| [platform/](platform/) | **Platform Architecture** — vision, principles, concepts, system architecture, daily pipeline, engine overview, system domain model (platform-wide concepts live here) |
| [domains/](domains/) | Cross-cutting business & pipeline specifications (engine contracts, REST API, strategy/screener/artifacts, roadmap) |
| [live-trading/](live-trading/) | Live trading & order execution subsystem |
| [portfolio/](portfolio/) | Portfolio workspaces: dashboard, cash, discovery, analytics, holdings, watchlist |
| [indicators/](indicators/) | Indicator & trading-artifact architecture (08–11, 13–14) |
| [data/](data/) | Database schema and Data Engine |
| [ui/](ui/) | UI architecture: Trading OS pages & flow (07), sidebar navigation (15) |
| [integrations/](integrations/) | External integrations (brokers, exchanges, notifications, AI) — placeholder |
| [governance/](governance/) | Repository constitution, V1.0 authority, decisions, scope, baseline, backlog |
| [audit/](audit/) | Freeze audit evidence pack |

---

## Document index

### platform/

| Doc | Title |
|-----|-------|
| [README.md](platform/README.md) | **Platform Architecture entry point** (concept map + reading order) |
| [01-Vision.md](platform/01-Vision.md) | Vision |
| [02-Guiding-Principles.md](platform/02-Guiding-Principles.md) | Guiding Principles |
| [03-Core-Concepts.md](platform/03-Core-Concepts.md) | Core Concepts |
| [04-System-Architecture.md](platform/04-System-Architecture.md) | System Architecture |
| [05-Daily-Decision-Pipeline.md](platform/05-Daily-Decision-Pipeline.md) | Daily Decision Pipeline |
| [06-Engine-Overview.md](platform/06-Engine-Overview.md) | Engine Overview |
| [System-Domain-Model.md](platform/System-Domain-Model.md) | System Domain Model (shared entities) |

### domains/

Business-domain and pipeline contracts: REST API, application architecture, evaluation / recommendation / notification / execution / review engines, strategy & screener specs, trading-artifact specs & migrations, AI authoring guide, and [Implementation-Roadmap.md](domains/Implementation-Roadmap.md). See [domains/](domains/) and [domains/artifacts/](domains/artifacts/).

### live-trading/

| Doc | Title |
|-----|-------|
| [README.md](live-trading/README.md) | Live Trading hub |
| [00-glossary.md](live-trading/00-glossary.md) | Glossary |
| [01-overview.md](live-trading/01-overview.md) | Overview |
| [02-execution-architecture.md](live-trading/02-execution-architecture.md) | Execution Architecture |
| [03-security-and-authentication.md](live-trading/03-security-and-authentication.md) | Security and Authentication |

### portfolio/

| Doc | Title |
|-----|-------|
| [Dashboard-Specification.md](portfolio/Dashboard-Specification.md) | Dashboard |
| [Cash-Management-Specification.md](portfolio/Cash-Management-Specification.md) | Cash Management |
| [Discovery-Specification.md](portfolio/Discovery-Specification.md) | Discovery |
| [Analytics-Architecture-Specification.md](portfolio/Analytics-Architecture-Specification.md) | Analytics Architecture |
| [Portfolio-Specification.md](portfolio/Portfolio-Specification.md) | Portfolio |
| [Portfolio-Analytics-Specification.md](portfolio/Portfolio-Analytics-Specification.md) | Portfolio Analytics |
| [Watchlist-Specification.md](portfolio/Watchlist-Specification.md) | Watchlist |

### indicators/

| Doc | Title |
|-----|-------|
| [08-Indicator-Architecture-Analysis.md](indicators/08-Indicator-Architecture-Analysis.md) | Indicator Architecture Analysis (as-built) |
| [09-Indicator-Registry.md](indicators/09-Indicator-Registry.md) | Indicator Registry (target) |
| [10-Indicator-Registry-Implementation-Plan.md](indicators/10-Indicator-Registry-Implementation-Plan.md) | Indicator Registry Implementation Plan |
| [11-Trading-Artifact-Framework.md](indicators/11-Trading-Artifact-Framework.md) | Trading Artifact Framework |
| [13-Indicator-Lifecycle.md](indicators/13-Indicator-Lifecycle.md) | Indicator Lifecycle |
| [14-Indicator-Registry-Diagrams.md](indicators/14-Indicator-Registry-Diagrams.md) | Indicator Registry Diagrams |

### data/

| Doc | Title |
|-----|-------|
| [Database-Schema-Specification.md](data/Database-Schema-Specification.md) | Database Schema |
| [Data-Engine-Specification.md](data/Data-Engine-Specification.md) | Data Engine |

### ui/

| Doc | Title |
|-----|-------|
| [07-Trading-OS-Pages-and-Flow.md](ui/07-Trading-OS-Pages-and-Flow.md) | Trading OS Pages and Flow |
| [15-Sidebar-Navigation-Architecture.md](ui/15-Sidebar-Navigation-Architecture.md) | Sidebar Navigation Architecture |

### integrations/

| Doc | Title |
|-----|-------|
| [README.md](integrations/README.md) | Integrations domain placeholder (Zerodha, NSE/BSE, Telegram, Email, AI providers, …) |

### governance/ · audit/

See [governance/README.md](governance/README.md) and [audit/README.md](audit/README.md).

---

## Recommended reading order

For **new engineers** (first principles → specialization):

```text
Repository Overview  (this README → ../README.md → ../../DOCS.md)
        ↓
Platform Architecture  (platform/README.md → 01…06 → System-Domain-Model)
        ↓
Domains                (domains/ — engines, REST, artifacts, roadmap)
        ↓
Portfolio              (portfolio/)
        ↓
Indicators             (indicators/)
        ↓
Live Trading           (live-trading/)
        ↓
Integrations           (integrations/ — placeholder)
        ↓
UI (as needed)         (ui/07 pages & flow; ui/15 sidebar)
        ↓
Governance             (ARCHITECTURE_REPOSITORY_GOVERNANCE → precedence → decisions → scope → baseline)
        ↓
Audit                  (audit/ — freeze evidence)
```

Detailed checklist:

1. **Repository overview:** this file → [../README.md](../README.md) → [../../DOCS.md](../../DOCS.md)  
2. **Platform:** [platform/README.md](platform/README.md), then `01` → `06`, then `System-Domain-Model`  
3. **Domains:** pipeline/contracts under [domains/](domains/); schema under [data/](data/)  
4. **Portfolio:** [portfolio/](portfolio/)  
5. **Indicators:** [indicators/](indicators/)  
6. **Live trading:** [live-trading/README.md](live-trading/README.md) → `00` → `03`  
7. **Integrations:** [integrations/README.md](integrations/README.md)  
8. **UI (as needed):** [ui/07](ui/07-Trading-OS-Pages-and-Flow.md), [ui/15](ui/15-Sidebar-Navigation-Architecture.md)  
9. **Governance:** [ARCHITECTURE_REPOSITORY_GOVERNANCE](governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) (§7 + Golden Rule) → [BASELINE V1.0](governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md) → precedence → decisions → scope  
10. **Audit:** [audit/](audit/)  

**Baseline:** Architecture Repository **Version 1.0** is Frozen — [governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md](governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md). Extend; do not reorganize.

Full repository reading recipes: [../../DOCS.md](../../DOCS.md).

---

## Migration note

Documentation-only reorganizations are recorded in [MIGRATION-SUMMARY.md](MIGRATION-SUMMARY.md).
