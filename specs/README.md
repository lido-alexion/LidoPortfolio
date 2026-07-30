# Specs — Documentation Hub

**Parent index:** [../DOCS.md](../DOCS.md) (repository-wide documentation map)  
**Authority rules:** [governance/DOCUMENT_PRECEDENCE.md](governance/DOCUMENT_PRECEDENCE.md)

This folder holds Trading Operating System **requirements, architecture, governance, and audit** documents.  
Read **top → bottom**. Requirements/architecture come **before** implementation status.

---

## Reading order (specs only)

```text
1. architecture/     Intent — vision through engine overview
2. engines/          Domain, contracts, per-engine specs, roadmap
3. governance/       What V1.0 accepted vs deferred
4. progress/demo     Working status + acceptance checklist
5. audit/            Freeze audit evidence
```

---

## Tree

```text
specs/
│
├── README.md                          ← this file
│
├── architecture/                      【 INTENT — read first 】
│   ├── 01-Vision.md
│   ├── 02-Guiding-Principles.md
│   ├── 03-Core-Concepts.md
│   ├── 04-System-Architecture.md
│   ├── 05-Daily-Decision-Pipeline.md
│   ├── 06-Engine-Overview.md
│   ├── 07-Trading-OS-Pages-and-Flow.md   (UI page map + recommendation path)
│   ├── 08-Indicator-Architecture-Analysis.md  (as-built indicator system)
│   ├── 09-Indicator-Registry.md               (target Registry architecture; SD-033)
│   ├── 10-Indicator-Registry-Implementation-Plan.md  (Epics/Stories/Tasks)
│   ├── 11-Trading-Artifact-Framework.md       (target Artifact Framework; SD-034)
│   ├── 13-Indicator-Lifecycle.md              (lifecycle + Liquidity/Tradability V1)
│   ├── 14-Indicator-Registry-Diagrams.md      (class/component/deps diagrams)
│   └── 15-Sidebar-Navigation-Architecture.md  (sidebar registry, config, how-to)
│
├── engines/                           【 INTENT — contracts & engines 】
│   ├── System-Domain-Model.md
│   ├── Database-Schema-Specification.md
│   ├── REST-API-Specification.md
│   ├── Application-Architecture-Specification.md
│   ├── Data-Engine-Specification.md
│   ├── Evaluation-Engine-Specification.md
│   ├── Recommendation-Engine-Specification.md
│   ├── Cash-Management-Specification.md   (SD-026)
│   ├── Strategy-Configuration-Specification.md (SD-027)
│   ├── Screener-Specification.md              (SD-030)
│   ├── Analytics-Architecture-Specification.md (SD-031)
│   ├── Market-Analysis-Engine-Specification.md (SD-032)
│   ├── Indicator-Registry-Specification.md    (SD-033)
│   ├── Indicator-Registry-API.md              (Admin indicators HTTP API)
│   ├── Trading-Artifact-Framework-Specification.md (SD-034 design)
│   ├── Trading-Artifact-JSON-Specification.md (declarative JSON formats)
│   ├── Trading-Artifact-Registry-Migration.md (infrastructure phase BC / migrate)
│   ├── Screener-Registry-Migration.md (Screener Registry phase — versions + UI)
│   ├── Strategy-Registry-Migration.md (Strategy Registry — selection + portable JSON)
│   ├── StoX-Trading-Artifacts-AI-Guide.md (AI download pack; /docs/stox-trading-artifacts-ai-guide.md)
│   ├── artifacts/                             (JSON examples for AI / I/O design)
│   ├── Notification-Engine-Specification.md
│   ├── Execution-Engine-Specification.md
│   ├── Review-Engine-Specification.md
│   └── Implementation-Roadmap.md      (+ Version 1.0 Baseline appendix)
│       (no Discovery-Engine-Specification.md — see governance SD-005)
│
├── governance/                        【 V1.0 BRIDGE — after intent 】
│   ├── README.md
│   ├── DOCUMENT_PRECEDENCE.md         ← read first in this folder
│   ├── SPECIFICATION_DECISIONS.md
│   ├── MVP_SCOPE.md
│   ├── VERSION_1_BASELINE.md
│   └── PRODUCT_BACKLOG.md
│
├── IMPLEMENTATION_PROGRESS.md         【 STATUS 】
├── MVP_DEMO_CHECKLIST.md              【 ACCEPTANCE DEMO 】
│
└── audit/                             【 FREEZE AUDIT — after governance 】
    ├── README.md
    ├── MVP_VERDICT.md                 ← start here in audit
    ├── SPECIFICATION_TRACEABILITY.md
    ├── ARCHITECTURE_COMPLIANCE.md
    ├── DATABASE_MAPPING.md
    ├── API_INVENTORY.md
    ├── UI_INVENTORY.md
    ├── MVP_TEST_SCRIPT.md
    ├── KNOWN_LIMITATIONS.md
    ├── TECHNICAL_DEBT.md
    ├── REPOSITORY_OVERVIEW.md
    └── PROJECT_STATISTICS.md
```

---

## Linked checklist (click through)

### Phase A — Requirements & architecture

1. [architecture/01-Vision.md](architecture/01-Vision.md)  
2. [architecture/02-Guiding-Principles.md](architecture/02-Guiding-Principles.md)  
3. [architecture/03-Core-Concepts.md](architecture/03-Core-Concepts.md)  
4. [architecture/04-System-Architecture.md](architecture/04-System-Architecture.md)  
5. [architecture/05-Daily-Decision-Pipeline.md](architecture/05-Daily-Decision-Pipeline.md)
6. [architecture/06-Engine-Overview.md](architecture/06-Engine-Overview.md)
7. [architecture/07-Trading-OS-Pages-and-Flow.md](architecture/07-Trading-OS-Pages-and-Flow.md)
9. [architecture/09-Indicator-Registry.md](architecture/09-Indicator-Registry.md) — target Indicator Registry (SD-033)  
10. [architecture/10-Indicator-Registry-Implementation-Plan.md](architecture/10-Indicator-Registry-Implementation-Plan.md) — Epics/Stories/Tasks plan  
11. [architecture/11-Trading-Artifact-Framework.md](architecture/11-Trading-Artifact-Framework.md) — Trading Artifact Framework (SD-034)  
11a. [architecture/13-Indicator-Lifecycle.md](architecture/13-Indicator-Lifecycle.md) — Indicator lifecycle + Liquidity/Tradability V1  
11b. [architecture/14-Indicator-Registry-Diagrams.md](architecture/14-Indicator-Registry-Diagrams.md) — Registry diagrams
11c. [architecture/15-Sidebar-Navigation-Architecture.md](architecture/15-Sidebar-Navigation-Architecture.md) — Sidebar / navigation architecture
12. [engines/System-Domain-Model.md](engines/System-Domain-Model.md)  
13. [engines/Database-Schema-Specification.md](engines/Database-Schema-Specification.md)  
14. [engines/REST-API-Specification.md](engines/REST-API-Specification.md)  
15. [engines/Application-Architecture-Specification.md](engines/Application-Architecture-Specification.md)  
16. [engines/Data-Engine-Specification.md](engines/Data-Engine-Specification.md)  
17. [engines/Evaluation-Engine-Specification.md](engines/Evaluation-Engine-Specification.md)  
17a. [engines/Market-Analysis-Engine-Specification.md](engines/Market-Analysis-Engine-Specification.md) (SD-032)  
18. [engines/Recommendation-Engine-Specification.md](engines/Recommendation-Engine-Specification.md)  
19. [engines/Cash-Management-Specification.md](engines/Cash-Management-Specification.md) (SD-026)  
19a. [engines/Strategy-Configuration-Specification.md](engines/Strategy-Configuration-Specification.md) / [Strategy-Specification.md](engines/Strategy-Specification.md)  
19b. [engines/Analytics-Architecture-Specification.md](engines/Analytics-Architecture-Specification.md) / [Portfolio-Analytics-Specification.md](engines/Portfolio-Analytics-Specification.md) / [Dashboard-Specification.md](engines/Dashboard-Specification.md)  
19c. [engines/Indicator-Registry-Specification.md](engines/Indicator-Registry-Specification.md) (SD-033 — read with architecture/09)  
19c2. [engines/Indicator-Registry-API.md](engines/Indicator-Registry-API.md) (Admin indicators HTTP API)  
19d. [engines/Trading-Artifact-Framework-Specification.md](engines/Trading-Artifact-Framework-Specification.md) (SD-034 — read with architecture/11)  
19d2. [engines/Trading-Artifact-JSON-Specification.md](engines/Trading-Artifact-JSON-Specification.md) (declarative JSON + [artifacts/examples](engines/artifacts/examples/))  
19d3. [engines/Trading-Artifact-Registry-Migration.md](engines/Trading-Artifact-Registry-Migration.md) (Registry infrastructure migrate / BC)
19d4. [engines/Screener-Registry-Migration.md](engines/Screener-Registry-Migration.md) (Screener Registry migrate / BC)
19d5. [engines/Strategy-Registry-Migration.md](engines/Strategy-Registry-Migration.md) (Strategy Registry migrate / BC)  
19d6. [engines/StoX-Trading-Artifacts-AI-Guide.md](engines/StoX-Trading-Artifacts-AI-Guide.md) (AI authoring pack; deploy `/docs/stox-trading-artifacts-ai-guide.md`)  
19e. [engines/Screener-Specification.md](engines/Screener-Specification.md) (SD-030 — Screener artifact evolution §8)  
20. [engines/Notification-Engine-Specification.md](engines/Notification-Engine-Specification.md)  
21. [engines/Execution-Engine-Specification.md](engines/Execution-Engine-Specification.md)  
22. [engines/Review-Engine-Specification.md](engines/Review-Engine-Specification.md)  
23. [engines/Implementation-Roadmap.md](engines/Implementation-Roadmap.md)

### Phase B — Governance (implemented V1.0)

24. [governance/DOCUMENT_PRECEDENCE.md](governance/DOCUMENT_PRECEDENCE.md)  
25. [governance/SPECIFICATION_DECISIONS.md](governance/SPECIFICATION_DECISIONS.md)  
26. [governance/MVP_SCOPE.md](governance/MVP_SCOPE.md)  
27. [governance/VERSION_1_BASELINE.md](governance/VERSION_1_BASELINE.md)  
28. [governance/PRODUCT_BACKLOG.md](governance/PRODUCT_BACKLOG.md)

### Phase C — Status, demo, audit

29. [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md)  
30. [MVP_DEMO_CHECKLIST.md](MVP_DEMO_CHECKLIST.md)  
31. [audit/README.md](audit/README.md) → prefer starting at [audit/MVP_VERDICT.md](audit/MVP_VERDICT.md)

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
