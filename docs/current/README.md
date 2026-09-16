# StoX Current Product Documentation

This directory is the current source of truth for StoX product behaviour and technical contracts.

The old chronological/versioned specs have been retired to [../archive/](../archive/). Use archived specs only for historical context, source archaeology, or to understand why a decision changed. Do not treat archived wording as current behaviour when it conflicts with these documents or with the running code.

## Current Document Map

1. [Product Overview](./product-overview.md) - product identity, users, source-of-truth rules, high-level architecture.
2. [Frontend And Navigation](./frontend-and-navigation.md) - React SPA pages, sidebar taxonomy, routing, documentation/help UX.
3. [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md) - portfolios, transactions, holdings, cash ledger, corporate actions, snapshots, tax.
4. [Market Data And Data Quality](./market-data-and-data-quality.md) - stock master, OHLCV, indices, fundamentals, ML, sync jobs, quality gates.
5. [Discovery, Screeners, And Registries](./discovery-screeners-registries.md) - screeners, registry artifacts, indicator registry, discovery candidates.
6. [Strategy And Recommendations](./strategy-and-recommendations.md) - strategy configuration, scoring, recommendation lifecycle, capital resolution.
7. [Execution, Broker, And Safety](./execution-broker-safety.md) - paper/live execution, Kite, TOTP, safety halt, protections, reconciliation.
8. [Analytics, Review, And Backtesting](./analytics-review-backtesting.md) - dashboards, exploratory analytics, review reports, backtests, replay.
9. [Notifications, Calendar, And Alerts](./notifications-calendar-alerts.md) - notification center, channels, operational/user alerts, calendar.
10. [Knowledge And Documentation](./knowledge-and-documentation.md) - Knowledge Board notes, wiki, public sharing, in-app help.
11. [Administration, Security, And API](./administration-security-api.md) - auth, sessions, users, personal tokens, admin surfaces, API shape.
12. [Implementation Alignment And Gaps](./implementation-alignment-and-gaps.md) - known code/doc mismatches, residual risk, test anchors.
13. [StoX Trading Artifacts AI Guide](./stox-trading-artifacts-ai-guide.md) - AI-facing authoring contract and examples for indicator, screener, and strategy artifacts.

## Authority Rules

- These documents are feature-oriented and not version-oriented.
- Store only latest/current behaviour in the main sections.
- Use `Historical Context` only for superseded names, decisions, and lineage.
- If implementation and current docs disagree, fix one of them in the same change set and mention the mismatch in [Implementation Alignment And Gaps](./implementation-alignment-and-gaps.md).
- Deployment and environment runbooks remain outside this corpus under `deploy/` unless they define product behaviour.

## Product Identity

The current product name is StoX. The repository, database table prefixes, CSS class prefixes, and some historical docs still use `LidoPortfolio` / `lido` naming. Treat that as historical or technical legacy naming unless a UI surface explicitly brands itself as StoX.
