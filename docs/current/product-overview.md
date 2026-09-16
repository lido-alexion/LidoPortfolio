# Product Overview

## Current Behaviour

StoX is a portfolio and trading operating system for Indian equity investing. It combines portfolio accounting, market-data maintenance, screeners, strategy configuration, recommendations, execution workflow, analytics, backtesting, knowledge capture, and administration in one authenticated web application.

The product is a Laravel API plus React single-page application. React owns app navigation; Laravel serves an SPA fallback for non-API routes. Unknown `/api/*` paths must remain API 404s rather than falling through to the SPA shell.

The operating model is portfolio-scoped. A user may have multiple portfolio profiles, and most business data is tied to the active profile. Admin features are role-gated. Some newer Trading OS features are user-scoped where the feature concerns broker identity, execution safety, entitlement, sessions, or global user state.

StoX is designed around deterministic, explainable workflows:

- cached market data powers screeners, strategy, recommendations, charts, and analytics;
- strategy configuration defines scoring, eligibility, thresholds, exits, portfolio rules, and capital rules;
- recommendations are generated records with review and execution lifecycle state;
- execution is human-controllable and can be halted;
- audits, sync logs, data-quality issues, review reports, and knowledge notes preserve operational context.

## Technical Contract

- Backend framework: Laravel, Sanctum-authenticated API, migrations under `app/database/migrations`.
- Frontend framework: React SPA under `app/resources/js/src`.
- API entry points: `app/routes/api.php`; SPA fallback: `app/routes/web.php`.
- Frontend routes: `app/resources/js/src/navigation/routes.js`.
- Sidebar/navigation catalog: `app/resources/js/src/config/navigation.js`.
- OpenAPI generation: `app/app/Console/Commands/WriteOpenApiV1Command.php`, output at `app/openapi/v1.json` and `app/public/docs/openapi-v1.json`.
- Product-served help pages live in `app/public/docs` and must not be archived as source specs.

## Current Feature Areas

- Portfolio: holdings, transactions, watchlists, cash, snapshots, historical holdings, compare, performance/tax, corporate actions.
- Market: explorer, patterns, indices, calendar, market depth, price/data sync, fundamentals, ML scoring.
- Trading: recommendations, review, artifact library, strategies, strategy registry, backtests, screeners, screener registry.
- Knowledge: Knowledge Board notes, tags, wiki pages, images, exports, public sharing.
- Administration: settings, users, invites, sessions, alert policies, stock admin, sync logs, data quality, indicators, fundamentals, ML, audit explorer, notification settings/history.

## Related Docs

- [Frontend And Navigation](./frontend-and-navigation.md)
- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

The project began as LidoPortfolio and later evolved into StoX. Current user-facing branding in the app header and browser titles is StoX. Legacy names remain in repository path, CSS prefixes, database table names, and old docs. Archived chronological documents are under [../archive/](../archive/).

