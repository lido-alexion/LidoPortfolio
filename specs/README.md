# Specs — Documentation Hub

**Parent index:** [../DOCS.md](../DOCS.md) (repository-wide documentation map)  
**Architecture hub:** [architecture/README.md](architecture/README.md)  
**Authority rules:** [architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) (incl. mandatory authoring principles) · [architecture/governance/DOCUMENT_PRECEDENCE.md](architecture/governance/DOCUMENT_PRECEDENCE.md)

This folder holds Trading Operating System **requirements, architecture, governance, and audit** documents.  
Read **top → bottom**. Requirements/architecture come **before** implementation status.

**V3:** [LidoPortfolio-V3-Specification.md](LidoPortfolio-V3-Specification.md) is the source of truth for V3 product behavior (**v0.28**; **V3 STRICTLY COMPLETE** as of 2026-08-26). Recall / capital-resolution build plan (historical + shipped): [architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md](architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md).

**V4:** [LidoPortfolio-V4-Wishlist.md](LidoPortfolio-V4-Wishlist.md) — genuine new V4 features + genuine unresolved product/spec decisions only. Not a V3 deferral bin.

---

## Reading order (specs only)

```text
0. LidoPortfolio-V3-Specification.md   V3 product SoT (V3 STRICTLY COMPLETE)
0a. LidoPortfolio-V4-Wishlist.md       Genuine V4 features + SPEC decisions only
1. architecture/platform/       Intent — vision through engine overview + system domain model
2. architecture/ui/             Pages/flow + sidebar (as needed)
3. architecture/indicators/     Indicator & artifact architecture
4. architecture/data/ + domains/  Schema, contracts, pipeline & artifact specs, roadmap
5. architecture/portfolio/      Portfolio-domain specs
6. architecture/live-trading/   Live trading subsystem
7. architecture/integrations/   External integrations (placeholder)
8. architecture/governance/     What V1.0 accepted vs deferred
9. progress/demo                Working status + acceptance checklist
10. architecture/audit/         Freeze audit evidence
```

---

## Tree

```text
specs/
│
├── README.md                          ← this file
├── LidoPortfolio-V3-Specification.md  【 V3 SoT — V3 STRICTLY COMPLETE 】
├── LidoPortfolio-V4-Wishlist.md       【 V4 features + SPEC decisions only 】
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
    │   ├── 06-Engine-Overview.md
    │   └── System-Domain-Model.md     (platform-wide shared entities)
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
    ├── domains/                       【 INTENT — contracts & domains 】
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
    ├── integrations/                  【 EXTERNAL INTEGRATIONS 】
    │   └── README.md                  (placeholder — brokers, exchanges, notifications, AI)
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

### Phase A0 — V3 specification (current product direction)

0. [LidoPortfolio-V3-Specification.md](LidoPortfolio-V3-Specification.md) — **V3 source of truth** (**v0.28**; multi-strategy, ownership, lending, recall/bridge, ranking, exits, charts). Where it conflicts with V1 engine specs / SD-029, V3 wins for new work.
0a. [architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md](architecture/V3-WS4-Recall-Bridge-Implementation-Delta.md) — WS4 recall / capital-resolution / Recall Bridge Loan implementation delta (spec-only; next coding pass).

### Phase A — Requirements & architecture (V1 intent)

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
12. [architecture/platform/System-Domain-Model.md](architecture/platform/System-Domain-Model.md)  
13. [architecture/data/Database-Schema-Specification.md](architecture/data/Database-Schema-Specification.md)  
14. [architecture/domains/REST-API-Specification.md](architecture/domains/REST-API-Specification.md)  
15. [architecture/domains/Application-Architecture-Specification.md](architecture/domains/Application-Architecture-Specification.md)  
16. [architecture/data/Data-Engine-Specification.md](architecture/data/Data-Engine-Specification.md)  
17. [architecture/domains/Evaluation-Engine-Specification.md](architecture/domains/Evaluation-Engine-Specification.md)  
17a. [architecture/domains/Market-Analysis-Engine-Specification.md](architecture/domains/Market-Analysis-Engine-Specification.md) (SD-032)  
18. [architecture/domains/Recommendation-Engine-Specification.md](architecture/domains/Recommendation-Engine-Specification.md)  
19. [architecture/portfolio/Cash-Management-Specification.md](architecture/portfolio/Cash-Management-Specification.md) (SD-026)  
19a. [architecture/domains/Strategy-Configuration-Specification.md](architecture/domains/Strategy-Configuration-Specification.md) / [architecture/domains/Strategy-Specification.md](architecture/domains/Strategy-Specification.md)  
19b. [architecture/portfolio/Analytics-Architecture-Specification.md](architecture/portfolio/Analytics-Architecture-Specification.md) / [architecture/portfolio/Portfolio-Analytics-Specification.md](architecture/portfolio/Portfolio-Analytics-Specification.md) / [architecture/portfolio/Dashboard-Specification.md](architecture/portfolio/Dashboard-Specification.md)  
19c. [architecture/domains/Indicator-Registry-Specification.md](architecture/domains/Indicator-Registry-Specification.md) (SD-033 — read with architecture/indicators/09)  
19c2. [architecture/domains/Indicator-Registry-API.md](architecture/domains/Indicator-Registry-API.md) (Admin indicators HTTP API)  
19d. [architecture/domains/Trading-Artifact-Framework-Specification.md](architecture/domains/Trading-Artifact-Framework-Specification.md) (SD-034 — read with architecture/indicators/11)  
19d2. [architecture/domains/Trading-Artifact-JSON-Specification.md](architecture/domains/Trading-Artifact-JSON-Specification.md) (declarative JSON + [artifacts/examples](architecture/domains/artifacts/examples/))  
19d3. [architecture/domains/Trading-Artifact-Registry-Migration.md](architecture/domains/Trading-Artifact-Registry-Migration.md) (Registry infrastructure migrate / BC)  
19d4. [architecture/domains/Screener-Registry-Migration.md](architecture/domains/Screener-Registry-Migration.md) (Screener Registry migrate / BC)  
19d5. [architecture/domains/Strategy-Registry-Migration.md](architecture/domains/Strategy-Registry-Migration.md) (Strategy Registry migrate / BC)  
19d6. [architecture/domains/StoX-Trading-Artifacts-AI-Guide.md](architecture/domains/StoX-Trading-Artifacts-AI-Guide.md) (AI authoring pack; deploy `/docs/stox-trading-artifacts-ai-guide.md`)  
19e. [architecture/domains/Screener-Specification.md](architecture/domains/Screener-Specification.md) (SD-030)  
20. [architecture/domains/Notification-Engine-Specification.md](architecture/domains/Notification-Engine-Specification.md)  
21. [architecture/domains/Execution-Engine-Specification.md](architecture/domains/Execution-Engine-Specification.md)  
22. [architecture/domains/Review-Engine-Specification.md](architecture/domains/Review-Engine-Specification.md)  
23. [architecture/domains/Implementation-Roadmap.md](architecture/domains/Implementation-Roadmap.md)

### Phase B — Governance (implemented V1.0)

24. [architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) — repository constitution  
24a. [architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md](architecture/governance/ARCHITECTURE_REPOSITORY_BASELINE_V1.md) — Architecture Repository V1.0 Frozen  
24b. [architecture/governance/SPECIFICATION_HEADER_TEMPLATE.md](architecture/governance/SPECIFICATION_HEADER_TEMPLATE.md) — header template for new specs  
24c. [architecture/platform/README.md](architecture/platform/README.md) — Platform Architecture entry point  
25. [architecture/governance/DOCUMENT_PRECEDENCE.md](architecture/governance/DOCUMENT_PRECEDENCE.md)  
26. [architecture/governance/SPECIFICATION_DECISIONS.md](architecture/governance/SPECIFICATION_DECISIONS.md)  
27. [architecture/governance/MVP_SCOPE.md](architecture/governance/MVP_SCOPE.md)  
28. [architecture/governance/VERSION_1_BASELINE.md](architecture/governance/VERSION_1_BASELINE.md)  
29. [architecture/governance/PRODUCT_BACKLOG.md](architecture/governance/PRODUCT_BACKLOG.md)

### Phase C — Status, demo, audit

30. [IMPLEMENTATION_PROGRESS.md](IMPLEMENTATION_PROGRESS.md)  
31. [MVP_DEMO_CHECKLIST.md](MVP_DEMO_CHECKLIST.md)  
32. [architecture/audit/README.md](architecture/audit/README.md) → prefer starting at [architecture/audit/MVP_VERDICT.md](architecture/audit/MVP_VERDICT.md)

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
