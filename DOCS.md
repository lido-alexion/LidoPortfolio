# Documentation Map

This is the repository documentation entry point.

## Start Here

- Current product/source-of-truth docs: [docs/current/README.md](docs/current/README.md)
- Living technical runbook: [implementation.md](implementation.md)
- Debugging notes: [debugging.md](debugging.md)
- Deployment planning: [deploy/STOXLA-VPS-DEPLOY.md](deploy/STOXLA-VPS-DEPLOY.md)
- Deployment runbooks: [deploy/README.md](deploy/README.md)
- Product-served static help: [app/public/docs/](app/public/docs/)

## Current Product Docs

The current docs are feature-oriented rather than version-oriented:

1. [Product Overview](docs/current/product-overview.md)
2. [Frontend And Navigation](docs/current/frontend-and-navigation.md)
3. [Portfolio, Cash, And Accounting](docs/current/portfolio-cash-accounting.md)
4. [Market Data And Data Quality](docs/current/market-data-and-data-quality.md)
5. [Discovery, Screeners, And Registries](docs/current/discovery-screeners-registries.md)
6. [Strategy And Recommendations](docs/current/strategy-and-recommendations.md)
7. [Execution, Broker, And Safety](docs/current/execution-broker-safety.md)
8. [Analytics, Review, And Backtesting](docs/current/analytics-review-backtesting.md)
9. [Notifications, Calendar, And Alerts](docs/current/notifications-calendar-alerts.md)
10. [Knowledge And Documentation](docs/current/knowledge-and-documentation.md)
11. [Administration, Security, And API](docs/current/administration-security-api.md)
12. [Implementation Alignment And Gaps](docs/current/implementation-alignment-and-gaps.md)
13. [StoX Trading Artifacts AI Guide](docs/current/stox-trading-artifacts-ai-guide.md)

## Archive

The old chronological/versioned specs and audits were retired to [docs/archive/](docs/archive/). They remain useful for historical context but are no longer the first source of truth for current product behaviour.

Archived groups:

- [docs/archive/specs/](docs/archive/specs/) - original specs, architecture, governance, planning registers, artifact references.
- [docs/archive/v2/](docs/archive/v2/) - V2 feature packs and reconciliation docs.
- [docs/archive/v2.1/](docs/archive/v2.1/) - V2.1 shadow/current packs and hardening audits.
- [docs/archive/audits/](docs/archive/audits/) - historical feature coverage audits.

## Product Assets

`app/public/docs/` is not archived because it is served by the app as static in-product help. Keep it in sync when a product behaviour change affects user-facing documentation.

## Rule For New Docs

New product behaviour should be added to the relevant `docs/current/*.md` file. Create a new current doc only when the topic does not fit the existing feature map. Historical notes belong in a clearly labeled `Historical Context` section.
