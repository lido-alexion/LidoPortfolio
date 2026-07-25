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
│   └── 06-Engine-Overview.md
│
├── engines/                           【 INTENT — contracts & engines 】
│   ├── System-Domain-Model.md
│   ├── Database-Schema-Specification.md
│   ├── REST-API-Specification.md
│   ├── Application-Architecture-Specification.md
│   ├── Data-Engine-Specification.md
│   ├── Evaluation-Engine-Specification.md
│   ├── Recommendation-Engine-Specification.md
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
7. [engines/System-Domain-Model.md](engines/System-Domain-Model.md)  
8. [engines/Database-Schema-Specification.md](engines/Database-Schema-Specification.md)  
9. [engines/REST-API-Specification.md](engines/REST-API-Specification.md)  
10. [engines/Application-Architecture-Specification.md](engines/Application-Architecture-Specification.md)  
11. [engines/Data-Engine-Specification.md](engines/Data-Engine-Specification.md)  
12. [engines/Evaluation-Engine-Specification.md](engines/Evaluation-Engine-Specification.md)  
13. [engines/Recommendation-Engine-Specification.md](engines/Recommendation-Engine-Specification.md)  
14. [engines/Notification-Engine-Specification.md](engines/Notification-Engine-Specification.md)  
15. [engines/Execution-Engine-Specification.md](engines/Execution-Engine-Specification.md)  
16. [engines/Review-Engine-Specification.md](engines/Review-Engine-Specification.md)  
17. [engines/Implementation-Roadmap.md](engines/Implementation-Roadmap.md)

### Phase B — Governance (implemented V1.0)

18. [governance/DOCUMENT_PRECEDENCE.md](governance/DOCUMENT_PRECEDENCE.md)  
19. [governance/SPECIFICATION_DECISIONS.md](governance/SPECIFICATION_DECISIONS.md)  
20. [governance/MVP_SCOPE.md](governance/MVP_SCOPE.md)  
21. [governance/VERSION_1_BASELINE.md](governance/VERSION_1_BASELINE.md)  
22. [governance/PRODUCT_BACKLOG.md](governance/PRODUCT_BACKLOG.md)

### Phase C — Status, demo, audit

23. [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md)  
24. [MVP_DEMO_CHECKLIST.md](MVP_DEMO_CHECKLIST.md)  
25. [audit/README.md](audit/README.md) → prefer starting at [audit/MVP_VERDICT.md](audit/MVP_VERDICT.md)

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
