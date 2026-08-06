# Specs — Documentation Hub

**Parent index:** [../DOCS.md](../DOCS.md) (repository-wide documentation map)  
**Architecture hub:** [architecture/README.md](architecture/README.md)  
**Authority rules:** [architecture/governance/DOCUMENT_PRECEDENCE.md](architecture/governance/DOCUMENT_PRECEDENCE.md)

This folder holds Trading Operating System **requirements, architecture, governance, and audit** documents.  
Read **top → bottom**. Requirements/architecture come **before** implementation status.

---

## Reading order (specs only)

```text
1. architecture/platform/     Intent — vision through engine overview
2. architecture/ui/           Pages/flow + sidebar (as needed)
3. architecture/indicators/   Indicator & artifact architecture
4. architecture/data/ + engines/   Domain, contracts, per-engine specs, roadmap
5. architecture/portfolio/    Portfolio-domain specs
6. architecture/live-trading/ Live trading subsystem
7. architecture/governance/   What V1.0 accepted vs deferred
8. progress/demo              Working status + acceptance checklist
9. architecture/audit/        Freeze audit evidence
```

---

## Tree

```text
specs/
│
├── README.md                          ← this file
├── IMPLEMENTATION_PROGRESS.md         【 STATUS 】
├── MVP_DEMO_CHECKLIST.md              【 ACCEPTANCE DEMO 】
│
└── architecture/                      【 DOMAIN-DRIVEN ARCHITECTURE HUB 】
    ├── README.md                      ← architecture index + reading order
    │
    ├── platform/                      【 INTENT — foundation 】
    │   ├── 01-Vision.md
    │   ├── 02-Guiding-Principles.md
    │   ├── 03-Core-Concepts.md
    │   ├── 04-System-Architecture.md
    │   ├── 05-Daily-Decision-Pipeline.md
    │   └── 06-Engine-Overview.md
    │
    ├── ui/
    │   ├── 07-Trading-OS-Pages-and-Flow.md
    │   └── 15-Sidebar-Navigation-Architecture.md
    │
    ├── indicators/
    │   ├── 08-Indicator-Architecture-Analysis.md
    │   ├── 09-Indicator-Registry.md
    │   ├── 10-Indicator-Registry-Implementation-Plan.md
    │   ├── 11-Trading-Artifact-Framework.md
    │   ├── 13-Indicator-Lifecycle.md
    │   └── 14-Indicator-Registry-Diagrams.md
    │
    ├── portfolio/
    │   ├── Dashboard-Specification.md
    │   ├── Cash-Management-Specification.md
    │   ├── Discovery-Specification.md
    │   ├── Analytics-Architecture-Specification.md
    │   ├── Portfolio-Specification.md
    │   ├── Portfolio-Analytics-Specification.md
    │   └── Watchlist-Specification.md
    │
    ├── data/
    │   ├── Database-Schema-Specification.md
    │   └── Data-Engine-Specification.md
    │
    ├── engines/                       【 INTENT — contracts & engines 】
    │   ├── System-Domain-Model.md
    │   ├── REST-API-Specification.md
    │   ├── Application-Architecture-Specification.md
    │   ├── Evaluation / Recommendation / … Engine specs
    │   ├── Strategy / Screener / Trading Artifact specs
    │   ├── artifacts/                 (JSON examples)
    │   └── Implementation-Roadmap.md
    │
    ├── live-trading/                  【 LIVE TRADING SUBSYSTEM 】
    │   ├── README.md
    │   ├── 00-glossary.md
    │   ├── 01-overview.md
    │   ├── 02-execution-architecture.md
    │   └── 03-security-and-authentication.md
    │
    ├── governance/                    【 V1.0 BRIDGE 】
    │   ├── README.md
    │   ├── DOCUMENT_PRECEDENCE.md
    │   ├── SPECIFICATION_DECISIONS.md
    │   ├── MVP_SCOPE.md
    │   ├── VERSION_1_BASELINE.md
    │   └── PRODUCT_BACKLOG.md
    │
    └── audit/                         【 FREEZE AUDIT 】
        ├── README.md
        ├── MVP_VERDICT.md
        └── … (traceability, inventories, debt, …)
```

---

## Linked checklist (click through)

### Phase A — Requirements & architecture

1. [architecture/platform/01-Vision.md](architecture/platform/01-Vision.md)  
2. [architecture/platform/02-Guiding-Principles.md](architecture/platform/02-Guiding-Principles.md)  
3. [architecture/platform/03-Core-Concepts.md](architecture/platform/03-Core-Concepts.md)  
4. [architecture/platform/04-System-Architecture.md](architecture/platform/04-System-Architecture.md)  
5. [architecture/platform/05-Daily-Decision-Pipeline.md](architecture/platform/05-Daily-Decision-Pipeline.md)  
6. [architecture/platform/06-Engine-Overview.md](architecture/platform/06-Engine-Overview.md)  
7. [architecture/ui/07-Trading-OS-Pages-and-Flow.md](architecture/ui/07-Trading-OS-Pages-and-Flow.md)  
9. [architecture/indicators/09-Indicator-Registry.md](architecture/indicators/09-Indicator-Registry.md) — target Indicator Registry (SD-033)  
10. [architecture/indicators/10-Indicator-Registry-Implementation-Plan.md](architecture/indicators/10-Indicator-Registry-Implementation-Plan.md) — Epics/Stories/Tasks plan  
11. [architecture/indicators/11-Trading-Artifact-Framework.md](architecture/indicators/11-Trading-Artifact-Framework.md) — Trading Artifact Framework (SD-034)  
11a. [architecture/indicators/13-Indicator-Lifecycle.md](architecture/indicators/13-Indicator-Lifecycle.md) — Indicator lifecycle + Liquidity/Tradability V1  
11b. [architecture/indicators/14-Indicator-Registry-Diagrams.md](architecture/indicators/14-Indicator-Registry-Diagrams.md) — Registry diagrams  
11c. [architecture/ui/15-Sidebar-Navigation-Architecture.md](architecture/ui/15-Sidebar-Navigation-Architecture.md) — Sidebar / navigation architecture  
12. [architecture/engines/System-Domain-Model.md](architecture/engines/System-Domain-Model.md)  
13. [architecture/data/Database-Schema-Specification.md](architecture/data/Database-Schema-Specification.md)  
14. [architecture/engines/REST-API-Specification.md](architecture/engines/REST-API-Specification.md)  
15. [architecture/engines/Application-Architecture-Specification.md](architecture/engines/Application-Architecture-Specification.md)  
16. [architecture/data/Data-Engine-Specification.md](architecture/data/Data-Engine-Specification.md)  
17. [architecture/engines/Evaluation-Engine-Specification.md](architecture/engines/Evaluation-Engine-Specification.md)  
17a. [architecture/engines/Market-Analysis-Engine-Specification.md](architecture/engines/Market-Analysis-Engine-Specification.md) (SD-032)  
18. [architecture/engines/Recommendation-Engine-Specification.md](architecture/engines/Recommendation-Engine-Specification.md)  
19. [architecture/portfolio/Cash-Management-Specification.md](architecture/portfolio/Cash-Management-Specification.md) (SD-026)  
19a. [architecture/engines/Strategy-Configuration-Specification.md](architecture/engines/Strategy-Configuration-Specification.md) / [architecture/engines/Strategy-Specification.md](architecture/engines/Strategy-Specification.md)  
19b. [architecture/portfolio/Analytics-Architecture-Specification.md](architecture/portfolio/Analytics-Architecture-Specification.md) / [architecture/portfolio/Portfolio-Analytics-Specification.md](architecture/portfolio/Portfolio-Analytics-Specification.md) / [architecture/portfolio/Dashboard-Specification.md](architecture/portfolio/Dashboard-Specification.md)  
19c. [architecture/engines/Indicator-Registry-Specification.md](architecture/engines/Indicator-Registry-Specification.md) (SD-033 — read with architecture/indicators/09)  
19c2. [architecture/engines/Indicator-Registry-API.md](architecture/engines/Indicator-Registry-API.md) (Admin indicators HTTP API)  
19d. [architecture/engines/Trading-Artifact-Framework-Specification.md](architecture/engines/Trading-Artifact-Framework-Specification.md) (SD-034 — read with architecture/indicators/11)  
19d2. [architecture/engines/Trading-Artifact-JSON-Specification.md](architecture/engines/Trading-Artifact-JSON-Specification.md) (declarative JSON + [artifacts/examples](architecture/engines/artifacts/examples/))  
19d3. [architecture/engines/Trading-Artifact-Registry-Migration.md](architecture/engines/Trading-Artifact-Registry-Migration.md) (Registry infrastructure migrate / BC)  
19d4. [architecture/engines/Screener-Registry-Migration.md](architecture/engines/Screener-Registry-Migration.md) (Screener Registry migrate / BC)  
19d5. [architecture/engines/Strategy-Registry-Migration.md](architecture/engines/Strategy-Registry-Migration.md) (Strategy Registry migrate / BC)  
19d6. [architecture/engines/StoX-Trading-Artifacts-AI-Guide.md](architecture/engines/StoX-Trading-Artifacts-AI-Guide.md) (AI authoring pack; deploy `/docs/stox-trading-artifacts-ai-guide.md`)  
19e. [architecture/engines/Screener-Specification.md](architecture/engines/Screener-Specification.md) (SD-030)  
20. [architecture/engines/Notification-Engine-Specification.md](architecture/engines/Notification-Engine-Specification.md)  
21. [architecture/engines/Execution-Engine-Specification.md](architecture/engines/Execution-Engine-Specification.md)  
22. [architecture/engines/Review-Engine-Specification.md](architecture/engines/Review-Engine-Specification.md)  
23. [architecture/engines/Implementation-Roadmap.md](architecture/engines/Implementation-Roadmap.md)

### Phase B — Governance (implemented V1.0)

24. [architecture/governance/DOCUMENT_PRECEDENCE.md](architecture/governance/DOCUMENT_PRECEDENCE.md)  
25. [architecture/governance/SPECIFICATION_DECISIONS.md](architecture/governance/SPECIFICATION_DECISIONS.md)  
26. [architecture/governance/MVP_SCOPE.md](architecture/governance/MVP_SCOPE.md)  
27. [architecture/governance/VERSION_1_BASELINE.md](architecture/governance/VERSION_1_BASELINE.md)  
28. [architecture/governance/PRODUCT_BACKLOG.md](architecture/governance/PRODUCT_BACKLOG.md)

### Phase C — Status, demo, audit

29. [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md)  
30. [MVP_DEMO_CHECKLIST.md](MVP_DEMO_CHECKLIST.md)  
31. [architecture/audit/README.md](architecture/audit/README.md) → prefer starting at [architecture/audit/MVP_VERDICT.md](architecture/audit/MVP_VERDICT.md)

---

## Outside this folder (still part of the story)

| Document | Role |
|----------|------|
| [../DOCS.md](../DOCS.md) | Full-repo documentation root |
| [../README.md](../README.md) | Product / quick start |
| [../implementation.md](../implementation.md) | Living technical reference |
| [../debugging.md](../debugging.md) | Debug runbook |
| [../deploy/DEPLOY.md](../deploy/DEPLOY.md) | Production deploy |

---

## Important

- **Architecture + engine specs** = long-term **intent**.  
- **Governance** = accepted V1.0 **decisions and scope**.  
- **Audit** = point-in-time **evidence**.  
- Do not rewrite intent docs to match code; update governance instead.
