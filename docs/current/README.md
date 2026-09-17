# StoX Current Product Documentation

## 1. Purpose Of Current Docs

`docs/current/**` is the feature-oriented current contract corpus for StoX. It records accepted product/technical behavior, implementation anchors, test anchors, debugging entry points, and implementation-alignment notes.

## 2. Authority And Conflict Rules

1. `docs/current/**` records the accepted current product/technical contract.
2. Verified implementation anchors describe known current implementation.
3. `implementation.md` and archived accepted specs are source archaeology for details not yet promoted.
4. A contract/code disagreement is an implementation-alignment question, not permission to rewrite the contract to match code.
5. During implementation audit, classify whether code is wrong, contract is wrong, the requirement was superseded, or runtime verification is required.
6. Change code/docs only after that decision. Do not use an archive requirement to override current accepted contract without review.

## 3. Document Map

1. [Product Overview](./product-overview.md) - system model, lifecycle, domains, invariants.
2. [Frontend And Navigation](./frontend-and-navigation.md) - shell, routing, responsive/page contracts.
3. [Portfolio, Cash, And Accounting](./portfolio-cash-accounting.md) - ledger, cash, ownership, capital.
4. [Market Data And Data Quality](./market-data-and-data-quality.md) - prices, datasets, sync, quality, fundamentals/ML inputs.
5. [Discovery, Screeners, And Registries](./discovery-screeners-registries.md) - screeners, candidates, registry/runtime bridge.
6. [Strategy And Recommendations](./strategy-and-recommendations.md) - strategy policy, lifecycle, capital/review.
7. [Execution, Broker, And Safety](./execution-broker-safety.md) - broker, modes, safety, reconciliation.
8. [Analytics, Review, And Backtesting](./analytics-review-backtesting.md) - performance, attribution, backtest/replay/simulation.
9. [Notifications, Calendar, And Alerts](./notifications-calendar-alerts.md) - notification lifecycle, alerts, calendar.
10. [Knowledge And Documentation](./knowledge-and-documentation.md) - notes, wiki, sharing, served help.
11. [Administration, Security, And API](./administration-security-api.md) - auth, Admin boundary, tokens, API governance.
12. [Trading Artifacts And Runtime Guide](./stox-trading-artifacts-ai-guide.md) - artifact envelopes, lifecycle, validation, bindings, packages, AI boundary.
13. [Implementation Alignment And Gaps](./implementation-alignment-and-gaps.md) - Phase 2 audit index and evidence status.

## 4. How To Use This Corpus

- **Implement:** start with the owning domain, follow cross-links, then use anchors/tests.
- **Debug:** use the owning document's debugging guide and implementation/test anchors.
- **Audit:** preserve accepted contract, gather code/runtime evidence, and update the alignment index/gap register.
- **Answer product questions:** start with Product Overview, then the owning domain.
- **Historical research:** use [../archive/](../archive/) only when current docs lack rationale; do not promote history automatically.

## 5. Updating Documentation

When accepted behavior changes: update the owning current doc, update cross-domain boundaries, update served help when user-facing, add/refresh implementation-alignment notes when runtime/code is unverified, and leave archive history intact.

## 6. Current Product Identity

The product is StoX. `LidoPortfolio`/`lido` repository, database, CSS, and archive names are intentional technical/history legacy unless a current UI explicitly uses them.
