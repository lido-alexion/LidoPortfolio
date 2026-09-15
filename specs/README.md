# Specs — Documentation Hub

**Parent index:** [../DOCS.md](../DOCS.md)  
**Architecture hub:** [architecture/README.md](architecture/README.md)  
**Authority rules:** [architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md) · [architecture/governance/DOCUMENT_PRECEDENCE.md](architecture/governance/DOCUMENT_PRECEDENCE.md)

This folder contains StoX / Trading Operating System requirements, architecture, governance, version registers, feature specifications and audit/status evidence.

## Current product/version status

As reconciled on **2026-09-15**:

| Version | Current state | Canonical register |
|---|---|---|
| V3 | **STRICTLY COMPLETE** | [LidoPortfolio-V3-Specification.md](LidoPortfolio-V3-Specification.md) |
| V4 | **COMPLETE / CLOSED — 18/18 active features** | [LidoPortfolio-V4-Wishlist.md](LidoPortfolio-V4-Wishlist.md) |
| V5 | **14 COMPLETE / 2 IN PROGRESS / 2 SUPERSEDED**. Only FEAT-008 rollout/final-audit evidence and FEAT-042 production ownership audit remain formal closure gates. | [LidoPortfolio-V5-Wishlist.md](LidoPortfolio-V5-Wishlist.md) |
| V6 | **COMPLETE — 12/12 features** | [LidoPortfolio-V6-Wishlist.md](LidoPortfolio-V6-Wishlist.md) |
| V7 | **Implemented/deployed.** FEAT-018 ML and FEAT-053 Fundamentals are implemented and included in the live VPS release. FEAT-055 DB namespace is closed because the shared-database premise was superseded by the dedicated StoX database. | [LidoPortfolio-V7-Wishlist.md](LidoPortfolio-V7-Wishlist.md) |
| V8 | **Future / early planning.** Standalone Telemetry Platform + Historical Fundamental Data Bootstrap. | [LidoPortfolio-V8-Wishlist.md](LidoPortfolio-V8-Wishlist.md) |
| V9 | **Future / early planning.** AI Assistant + Instrument Expansion + StoX/Telemetry integration. | [LidoPortfolio-V9-Wishlist.md](LidoPortfolio-V9-Wishlist.md) |

## Current production baseline

StoX production has moved from the historical GoDaddy/cPanel target to the dedicated **`stoxla.in` VPS**.

The successful production deployment on 2026-09-13 used the GitHub Actions VPS release flow and passed:

- full MySQL migration/seed validation;
- backend test suite;
- OpenAPI contract verification;
- frontend tests;
- TypeScript validation;
- production frontend build;
- release packaging;
- VPS release activation; and
- post-deploy HTTPS health check.

Current deployment authority:

- [../deploy/STOXLA-VPS-DEPLOY.md](../deploy/STOXLA-VPS-DEPLOY.md)
- `../.github/workflows/deploy-stoxla-production.yml`
- `../deploy/scripts/stoxla-deploy-release.sh`
- `../deploy/scripts/stoxla-rollback-release.sh`

The old GoDaddy/cPanel deployment documents remain historical reference only.

## Reading order

For product/architecture work, use this order:

1. **Current version register** relevant to the work being discussed.
2. **Dedicated feature specification**, when one exists.
3. **V3 product specification** for inherited product behavior.
4. **Architecture/domain specifications** under `architecture/`.
5. **Governance and document precedence** rules.
6. **Implementation/status evidence** such as `IMPLEMENTATION_PROGRESS.md`, feature implementation-status files and deployment records.

When status prose conflicts, the newest canonical version register and dedicated feature specification should be preferred over older implementation-history notes.

## Core architecture map

```text
specs/
├── LidoPortfolio-V3-Specification.md
├── LidoPortfolio-V4-Wishlist.md
├── LidoPortfolio-V5-Wishlist.md
├── LidoPortfolio-V6-Wishlist.md
├── LidoPortfolio-V7-Wishlist.md
├── LidoPortfolio-V8-Wishlist.md
├── LidoPortfolio-V9-Wishlist.md
├── IMPLEMENTATION_PROGRESS.md
├── MVP_DEMO_CHECKLIST.md
│
└── architecture/
    ├── platform/        vision, principles, core concepts, system architecture
    ├── ui/              pages, flow and navigation architecture
    ├── indicators/      Indicator Registry and Trading Artifact architecture
    ├── portfolio/       Portfolio, cash, analytics, dashboard, discovery, watchlist
    ├── data/            database/data-engine specifications
    ├── domains/         engines, APIs, Strategy/Screener/artifact contracts
    ├── live-trading/    live execution/security architecture
    ├── integrations/    external integration architecture
    ├── governance/      authority, decisions, version baselines, backlog
    └── audit/           point-in-time freeze/audit evidence
```

## Key current feature specifications

### V5 closure-sensitive

- [V5-FEAT-008-Trading-Artifact-Framework.md](V5-FEAT-008-Trading-Artifact-Framework.md)
- [V5-FEAT-008-IMPLEMENTATION-STATUS.md](V5-FEAT-008-IMPLEMENTATION-STATUS.md)
- [V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md](V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md)
- [V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md](V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md) — historical cPanel feature, now superseded by VPS deployment architecture

### V6

Use [LidoPortfolio-V6-Wishlist.md](LidoPortfolio-V6-Wishlist.md) as the status authority. All 12 V6 features are complete.

### V7

- [V7-ML-Scoring-Models-Specification.md](V7-ML-Scoring-Models-Specification.md)
- [V7-Fundamental-Data-Integration-Specification.md](V7-Fundamental-Data-Integration-Specification.md)
- [V7-StoX-Database-Namespace-Specification.md](V7-StoX-Database-Namespace-Specification.md) — closed/superseded at current partial implementation because StoX now uses a dedicated database

### V8 / V9

- [V7-Telemetry-Platform.md](V7-Telemetry-Platform.md) — historical filename; roadmap target is V8 and the platform is a separate application/product
- [LidoPortfolio-V8-Wishlist.md](LidoPortfolio-V8-Wishlist.md)
- [LidoPortfolio-V9-Wishlist.md](LidoPortfolio-V9-Wishlist.md)

## Foundational architecture references

Important long-lived architecture remains under `architecture/`, including:

- `architecture/platform/01-Vision.md`
- `architecture/platform/02-Guiding-Principles.md`
- `architecture/platform/04-System-Architecture.md`
- `architecture/platform/05-Daily-Decision-Pipeline.md`
- `architecture/platform/System-Domain-Model.md`
- `architecture/data/Database-Schema-Specification.md`
- `architecture/data/Data-Engine-Specification.md`
- `architecture/domains/REST-API-Specification.md`
- `architecture/domains/Application-Architecture-Specification.md`
- `architecture/domains/Evaluation-Engine-Specification.md`
- `architecture/domains/Recommendation-Engine-Specification.md`
- `architecture/domains/Execution-Engine-Specification.md`
- `architecture/domains/Review-Engine-Specification.md`
- `architecture/domains/Strategy-Specification.md`
- `architecture/domains/Screener-Specification.md`
- `architecture/domains/Trading-Artifact-Framework-Specification.md`
- `architecture/portfolio/Cash-Management-Specification.md`
- `architecture/portfolio/Portfolio-Specification.md`
- `architecture/portfolio/Portfolio-Analytics-Specification.md`
- `architecture/live-trading/README.md`

## Important documentation rule

- **Architecture/specification documents** describe product intent and frozen behavior.
- **Version registers** describe which version owns a feature and its current state.
- **Implementation-status documents** provide evidence and remaining closure work.
- **Deployment documentation** describes the current production topology and operational state.
- **Historical notes must not override newer canonical status.**
