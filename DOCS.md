# Documentation Map

**Purpose:** Root index for the major Markdown documentation in the StoX repository.  
**Audience:** Humans and AI agents re-understanding the product, architecture, implementation or operations.  
**Last reconciled:** 2026-09-15.

## Start here

| Goal | Start at |
|---|---|
| Product overview / quick orientation | [README.md](README.md) |
| Current version/feature status | [specs/README.md](specs/README.md) |
| Full product specification baseline | [specs/LidoPortfolio-V3-Specification.md](specs/LidoPortfolio-V3-Specification.md) |
| Current implementation summary | [specs/IMPLEMENTATION_PROGRESS.md](specs/IMPLEMENTATION_PROGRESS.md) |
| Living technical reference | [implementation.md](implementation.md) |
| Current production deployment | [deploy/STOXLA-VPS-DEPLOY.md](deploy/STOXLA-VPS-DEPLOY.md) |
| Architecture authority/conflicts | [specs/architecture/governance/DOCUMENT_PRECEDENCE.md](specs/architecture/governance/DOCUMENT_PRECEDENCE.md) |

## Current roadmap/status

| Version | State | Register |
|---|---|---|
| V3 | STRICTLY COMPLETE | [V3 Specification](specs/LidoPortfolio-V3-Specification.md) |
| V4 | COMPLETE / CLOSED | [V4 Register](specs/LidoPortfolio-V4-Wishlist.md) |
| V5 | COMPLETE / CLOSED — 16 complete, 2 superseded | [V5 Register](specs/LidoPortfolio-V5-Wishlist.md) |
| V6 | COMPLETE — 12/12 | [V6 Register](specs/LidoPortfolio-V6-Wishlist.md) |
| V7 | Fundamentals + ML implemented/deployed; DB namespace epic closed after dedicated-DB move | [V7 Register](specs/LidoPortfolio-V7-Wishlist.md) |
| V8 | Future: standalone Telemetry + historical fundamental bootstrap | [V8 Register](specs/LidoPortfolio-V8-Wishlist.md) |
| V9 | Future: AI Assistant + Instrument Expansion + StoX/Telemetry integration | [V9 Register](specs/LidoPortfolio-V9-Wishlist.md) |

## Current production

StoX production is the dedicated VPS deployment at **https://stoxla.in/**.

Current deployment/operations authority:

- [deploy/STOXLA-VPS-DEPLOY.md](deploy/STOXLA-VPS-DEPLOY.md)
- [.github/workflows/deploy-stoxla-production.yml](.github/workflows/deploy-stoxla-production.yml)
- [deploy/scripts/stoxla-deploy-release.sh](deploy/scripts/stoxla-deploy-release.sh)
- [deploy/scripts/stoxla-rollback-release.sh](deploy/scripts/stoxla-rollback-release.sh)

The old GoDaddy/cPanel deployment documents are historical/legacy only.

## Documentation reading order

For architecture or feature work, read in this order:

1. current version register owning the feature;
2. dedicated feature specification / implementation-status document;
3. inherited V3 specification;
4. relevant architecture/domain specifications;
5. governance/document precedence;
6. implementation/deployment evidence.

Older status prose must not override a newer canonical version register.

## Product & orientation

- [README.md](README.md) — repository/product overview
- [app/README.md](app/README.md) — application-root notes
- [app/CHANGELOG.md](app/CHANGELOG.md) — application changelog
- `app/public/docs/` — generated in-app help

## Specifications and version registers

- [specs/README.md](specs/README.md) — specs hub and current status
- [specs/LidoPortfolio-V3-Specification.md](specs/LidoPortfolio-V3-Specification.md)
- [specs/LidoPortfolio-V4-Wishlist.md](specs/LidoPortfolio-V4-Wishlist.md)
- [specs/LidoPortfolio-V5-Wishlist.md](specs/LidoPortfolio-V5-Wishlist.md)
- [specs/LidoPortfolio-V6-Wishlist.md](specs/LidoPortfolio-V6-Wishlist.md)
- [specs/LidoPortfolio-V7-Wishlist.md](specs/LidoPortfolio-V7-Wishlist.md)
- [specs/LidoPortfolio-V8-Wishlist.md](specs/LidoPortfolio-V8-Wishlist.md)
- [specs/LidoPortfolio-V9-Wishlist.md](specs/LidoPortfolio-V9-Wishlist.md)

### V5 closure evidence

- [specs/V5-FEAT-008-Trading-Artifact-Framework.md](specs/V5-FEAT-008-Trading-Artifact-Framework.md)
- [specs/V5-FEAT-008-IMPLEMENTATION-STATUS.md](specs/V5-FEAT-008-IMPLEMENTATION-STATUS.md)
- [specs/V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md](specs/V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md)

### Current V7 specifications

- [specs/V7-ML-Scoring-Models-Specification.md](specs/V7-ML-Scoring-Models-Specification.md)
- [specs/V7-Fundamental-Data-Integration-Specification.md](specs/V7-Fundamental-Data-Integration-Specification.md)
- [specs/V7-StoX-Database-Namespace-Specification.md](specs/V7-StoX-Database-Namespace-Specification.md) — closed/superseded by dedicated-DB deployment architecture

### V8 Telemetry architecture

- [specs/V7-Telemetry-Platform.md](specs/V7-Telemetry-Platform.md) — historical filename; roadmap target is V8

## Architecture tree

Architecture hub: [specs/architecture/README.md](specs/architecture/README.md)

### Platform

- `specs/architecture/platform/01-Vision.md`
- `specs/architecture/platform/02-Guiding-Principles.md`
- `specs/architecture/platform/03-Core-Concepts.md`
- `specs/architecture/platform/04-System-Architecture.md`
- `specs/architecture/platform/05-Daily-Decision-Pipeline.md`
- `specs/architecture/platform/06-Engine-Overview.md`
- `specs/architecture/platform/System-Domain-Model.md`

### UI

- `specs/architecture/ui/07-Trading-OS-Pages-and-Flow.md`
- `specs/architecture/ui/15-Sidebar-Navigation-Architecture.md`

### Indicators / artifacts

- `specs/architecture/indicators/09-Indicator-Registry.md`
- `specs/architecture/indicators/10-Indicator-Registry-Implementation-Plan.md`
- `specs/architecture/indicators/11-Trading-Artifact-Framework.md`
- `specs/architecture/indicators/13-Indicator-Lifecycle.md`
- `specs/architecture/indicators/14-Indicator-Registry-Diagrams.md`

### Portfolio / data / domains

- `specs/architecture/portfolio/`
- `specs/architecture/data/`
- `specs/architecture/domains/`

These contain the Portfolio, cash, analytics, dashboard, Discovery, Watchlist, database, Data Engine, REST API, Evaluation, Recommendation, Execution, Review, Strategy, Screener and Trading Artifact contracts.

### Live trading

- [specs/architecture/live-trading/README.md](specs/architecture/live-trading/README.md)

### Governance

- [specs/architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md](specs/architecture/governance/ARCHITECTURE_REPOSITORY_GOVERNANCE.md)
- [specs/architecture/governance/DOCUMENT_PRECEDENCE.md](specs/architecture/governance/DOCUMENT_PRECEDENCE.md)
- [specs/architecture/governance/SPECIFICATION_DECISIONS.md](specs/architecture/governance/SPECIFICATION_DECISIONS.md)
- [specs/architecture/governance/VERSION_1_BASELINE.md](specs/architecture/governance/VERSION_1_BASELINE.md)

### Audit/status

- [specs/IMPLEMENTATION_PROGRESS.md](specs/IMPLEMENTATION_PROGRESS.md)
- [specs/MVP_DEMO_CHECKLIST.md](specs/MVP_DEMO_CHECKLIST.md)
- [specs/architecture/audit/README.md](specs/architecture/audit/README.md)

## Living engineering references

- [implementation.md](implementation.md)
- [debugging.md](debugging.md)

## Documentation maintenance rule

Any new major project Markdown document should be linked from this map or the relevant subtree hub. Current status statements should be updated in the canonical version register rather than copied into unrelated architecture intent documents.
