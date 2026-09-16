# Frontend And Navigation

## Current Behaviour

StoX is a React SPA served by Laravel. The sidebar is metadata-driven and organized into Portfolio, Market, Trading, Knowledge, and Administration groups. Browser document titles use the page title plus `StoX`.

Primary route constants live in `ROUTES`. Navigation entries should be added to the catalog rather than hardcoded into page chrome. Sidebar pages expose top-level workflows; editor/detail/settings child routes can be routable without appearing as top-level sidebar entries.

The app includes a static Documentation page backed by generated/help content in `app/public/docs`. These help files are product assets and remain live. They are not the same as source specs under `docs/current`.

## Navigation Taxonomy

Portfolio:

- Dashboard `/`
- Holdings `/holdings`, with stock prices detail under `/holdings/:id/prices`
- Watchlist `/watchlist`
- Transactions `/transactions`, pending execution, and closed transactions
- Performance & Tax `/portfolio/performance-tax`
- Historical Holdings `/portfolio/historical-holdings`
- Portfolio Snapshots `/portfolio/snapshots`
- Cash `/cash`
- Corporate Actions `/corporate-action`

Market:

- Stock Explorer `/explorer`
- Patterns `/patterns`
- Indices `/indices`
- Calendar `/calendar`
- Market Depth `/market-depth`
- Discovery/candidates routes exist for pipeline inspection but are not currently a sidebar-first workflow.

Trading:

- Recommendations `/recommendations`
- Review `/review` and review reports
- Artifact Library `/artifact-library`
- Strategies `/strategy`
- Strategy Registry `/strategy/registry`
- Backtests `/backtests`
- Screeners `/screeners`
- Screener Registry as an internal/sub route.

Knowledge:

- Knowledge Board `/knowledge-board`
- Knowledge Tags `/knowledge-board/tags`
- Wiki routes under `/knowledge-board/wiki`

Administration:

- Settings, alert policies, stocks, users, sync logs, data quality, indicators, admin alerts, audit explorer, universe price sync, fundamentals, ML scoring, notification history/settings, portfolios, and profile.

## Technical Contract

- Route constants: `app/resources/js/src/navigation/routes.js`.
- Navigation catalog: `app/resources/js/src/config/navigation.js`.
- Page chrome/document titles: `app/resources/js/src/components/navigation/PageChrome.jsx`.
- Authentication context clears dashboard caches on session changes.
- Frontend logs post to `/api/logs/frontend`.
- Static documentation URL helpers live under `app/resources/js/src/utils/documentationLinks.js`.

## Debugging Sources

- For a missing page: check `ROUTES`, the navigation catalog, the React router wiring, and the Laravel SPA fallback.
- For sidebar highlighting: check each catalog item’s `match` predicate.
- For broken in-app docs: check `app/public/docs`, `DocumentationPage.jsx`, and doc presentation helpers.
- For auth redirects: check `AuthContext.jsx`, login/reset/invite pages, and Sanctum session probes.

## Related Docs

- [Product Overview](./product-overview.md)
- [Knowledge And Documentation](./knowledge-and-documentation.md)
- [Administration, Security, And API](./administration-security-api.md)

## Historical Context

Earlier specs used reading-order/version documents as the primary navigation structure. Current docs use feature areas matching the running product sidebar. Do not revive chronological spec navigation for current product behaviour.

