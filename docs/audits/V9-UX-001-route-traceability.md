# V9-UX-001 route and workflow traceability

Route and annotation crosswalk reviewed 2026-10-16 against `app/resources/js/src/App.jsx`, current journey guides and their browser suites. The frozen V9 specification §19–20 governs scope: all major Investor workflows plus Admin prerequisites that directly enable them. This inventory identifies documented and automated links; it is not a claim of full conformance, accepted deployment or completion. Fresh exact-head canonical CI, required visual comparisons, current nightly and external production/integration qualification remain separate gates in the implementation audit.

Paths below are the actual product routes. Tests are under `app/tests/e2e/`; guides are under `docs/user-journeys/`. Stable IDs are annotated in the named journey suites. Each guide links through the index/automation contract; generated metadata and HTML preserve those IDs. Crosscutting suites supplement rather than replace the named happy/recovery scenarios.

| Product routes / workflow | Stable journey IDs | Guide | Main browser evidence |
| --- | --- | --- | --- |
| Login, `/invite/:token`, `/request-account`, `/request-account/verify/:token`, `/reset-password/:token`, `/profile` | AUTH-01–08 | 00-account-entry | auth-entry, account-recovery, onboarding-journeys, profile-recovery |
| `/settings/account`: sessions, execution code, own API tokens | AUTH-03, AUTH-09–11 | 00-account-entry | account-channel-journeys |
| `/`: overview/refresh and scoped dashboard variants, local/named layout lifecycle, import/export | E2E-14, E2E-48–50 | 08-investor-overview-alerts, 18-dashboard-combo-charts | investor-overview-journeys, dashboard-combo-journeys |
| `/screeners`, `/screeners/:id`: definition CRUD/rules/results/AI/filter/import | SCR-01–09 | 01-screeners | screener-journeys, screener-investor-workflow |
| `/screeners/registry`, `/screeners/registry/:id`: owned/shared runtime compatibility, versions, export/copy/import | E2E-54 | 20-runtime-registry-compatibility | registry-catalog-journeys |
| `/strategy`: named runtime definitions, eligibility/scoring, enabled strategies and historical attribution | STR-01–16 | 02-strategies | strategy-journeys, composite-journeys |
| `/strategy/registry`, `/strategy/registry/:id`: runtime status, portable references, versions and validated import | STR-15, E2E-55 | 02-strategies, 20-runtime-registry-compatibility | strategy-journeys, registry-catalog-journeys |
| `/candidates`, `/recommendations`: discovery, evaluation, review/approval/dismissal and failed actions | REC-01–12, E2E-01–07 | 03-recommendations-review, 05-end-to-end | recommendation-journeys, tos-recommendations, composite-journeys, end-to-end-journeys |
| `/candidates`: stored evaluation history; `/evaluations` redirects here | E2E-53 | 19-persisted-history | persisted-history-journeys |
| `/transactions`, `/transactions/pending`: actual-fill evidence, execution safeguards and recorded transaction correction/CSV import | EXE-01–16, E2E-28 | 04-execution-transactions, 13-financial-source-lifecycle | execution-journeys, financial-source-journeys |
| `/transactions/closed`: stored squared-off transactions and deliberate correction/deletion | EXE-16, E2E-52 | 13-financial-source-lifecycle, 19-persisted-history | persisted-history-journeys, financial-source-journeys |
| `/cash`: ledger evidence and reservations | E2E-22 | 10-portfolio-cash | portfolio-cash-journeys |
| `/corporate-action`: reviewed split/bonus preview/apply | E2E-30 | 13-financial-source-lifecycle | financial-source-journeys |
| `/holdings`: active-account scope and unmanaged attribution adoption | E2E-13, E2E-29 | 07-supporting-investor, 13-financial-source-lifecycle | supporting-investor-journeys, financial-source-journeys |
| `/holdings/:stockId/prices`: dated history without sync authority | E2E-35 | 15-research-persisted-review | research-review-journeys |
| `/watchlist/:symbol?`: membership/research, embedded insight and combo controls/preferences | E2E-09, E2E-51, AI-07–08, VIZ-01 | 07-supporting-investor, 18-dashboard-combo-charts, 06-assistant, 07-stock-details-combo-charts | supporting-investor-journeys, dashboard-combo-journeys, embedded-ai, `v9-viz001-combo-charts.spec.js` (desktop keyboard and mobile/touch; VIZ-01) |
| `/fundamentals/insights/:symbol?`, `/patterns`: dated observational fundamentals and educational references | E2E-39 | 15-research-persisted-review | research-review-journeys, fundamental-insights-browser |
| `/explorer`: dated relative strength/benchmark comparison | E2E-15 | 08-investor-overview-alerts | investor-overview-journeys |
| `/indices`, `/market-depth`: local comparison controls and disclosed missing observations | E2E-36–37 | 15-research-persisted-review | research-review-journeys |
| `/artifact-library`, `/artifact-library/:uuid`: unpublished drafts, immutable publication/dependencies, bindings, shared forks and Bundle plans | E2E-31–34 | 14-artifact-library-governance | artifact-library-journeys |
| `/backtests`, `/backtests/:id`: historical experiments/status/cancellation; replay readiness/results | E2E-23–24 | 11-historical-analysis | historical-analysis-journeys |
| `/portfolio/historical-holdings`, `/portfolio/compare`, `/portfolio/snapshots` | E2E-25 | 12-portfolio-analysis-tax | portfolio-analysis-tax-journeys |
| `/portfolio/performance-tax`: scope/preferences/tax input/dividend import | E2E-26–27 | 12-portfolio-analysis-tax | portfolio-analysis-tax-journeys |
| `/review`, `/review/reports`, `/review/reports/:id`: observational review versus stored reports | E2E-38 | 15-research-persisted-review | research-review-journeys |
| `/notification-history`: filters/attention; `/settings/notifications`: optional/product/verified account channels | E2E-12, E2E-17, E2E-47 | 07-supporting-investor, 08-investor-overview-alerts, 17-account-delivery-settings | supporting-investor-journeys, optional-email-recovery, account-channel-journeys, comm001-notifications |
| `/knowledge-board`, `/knowledge-board/tags`: private notes/autosave/actions/bulk and tag merge/delete | E2E-40–41 | 16-personal-knowledge-calendar | personal-knowledge-calendar-journeys |
| `/knowledge-board/wiki/:pageId?`, `/wiki/shared/:token`: private authoring/history/export, public capability/revocation | E2E-42–43 | 16-personal-knowledge-calendar | personal-knowledge-calendar-journeys |
| `/calendar`: private recurrence/reminders and read-only official holidays | E2E-44 | 16-personal-knowledge-calendar | personal-knowledge-calendar-journeys |
| Contextual notes attached to Investor routes | E2E-45 | 16-personal-knowledge-calendar | personal-knowledge-calendar-journeys |
| `/portfolios`: create/rename/select/default/retire, live-to-paper clone | E2E-13, E2E-20–21 | 07-supporting-investor, 10-portfolio-cash | supporting-investor-journeys, portfolio-cash-journeys |
| `/settings/portfolio` (`/settings` redirects here): safeguards/unknown configuration recovery and controlled Kite handoff | E2E-10–11, E2E-46 | 07-supporting-investor, 17-account-delivery-settings | supporting-investor-journeys, account-channel-journeys |
| `/settings/alert-policies`: advisory policy CRUD/evaluation | E2E-16 | 08-investor-overview-alerts | investor-overview-journeys |
| `/settings/ml-scoring`: governed Production ML acceptance prerequisite | E2E-08 | 05-end-to-end | `composite-journeys.spec.js` (blocked preflight and explicit training controls; E2E-08) |
| `/documentation`, grounded help and assistant preview/review/apply/action history | AI-01–09 | 06-assistant | assistant, assistant-agent, embedded-ai |
| Export download/job/cancellation and private basket across Investor pages | E2E-18–19 | 09-data-export | export-journeys |

## Admin prerequisites and supplementary evidence

AUTH-04 includes intended-user Admin reset issuance; AUTH-08 covers access/invitation administration that enables Investor entry; E2E-08 covers the governed ML readiness/acceptance prerequisite. Its existing browser scenario validates blocked preflight and explicit training controls with deterministic fixtures; it does not perform real source mutation or qualify production ML. UX004 wizard implementation and expanded E2E-08 coverage remain with their existing owner. Frozen §20 does not include every administration console as an Investor journey. The guarded sync logs/audit, data quality/source administration, indicator administration, forward-data, VPS-health, stocks/classifications and global administration routes require their separate governed operational/domain contracts; they cannot be silently exercised as live source ingestion, training or protected service changes in this epic. Runtime Investor registries above remain in scope even though Admin also exposes compatibility routes.

`responsive-shell.spec.js`, `guided-tour-browser.spec.js`, `journey-accessibility.spec.js`, `v8-accessibility.spec.js` and component/Node contracts supplement navigation, discovery, keyboard focus, theme/viewport behavior and targeted WCAG checks. The historical Oct 11 evidence below records the then-current visual/nightly results; it is not current-head verification.

## Current traceability snapshot — 2026-10-16

- The numbered journey guides contain 129 unique stable IDs. The annotation contract test now compares that discovered set to the browser-suite annotations, including IDs supplied by supported literal-array loops; unsupported dynamic annotation forms fail closed. This is a source crosswalk check, not proof that every journey’s behavior is fully exercised.
- VIZ-01 is cross-referenced to `/watchlist/:symbol?`, its guide and `v9-viz001-combo-charts.spec.js`. E2E-08 is cross-referenced to `/settings/ml-scoring` and `composite-journeys.spec.js`; broader wizard workflow coverage remains with the active UX004 owner.
- Wishlist row V9-UX-001 remains **IMPLEMENTATION IN PROGRESS**. Remaining frozen §19 acceptance and external integration gates are tracked in the [implementation audit](V9-UX-001-implementation-audit.md); this route map does not claim epic completion.
- Current master nightly [38858064119](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38858064119) ran exact master `eebb297eb8f7b33244628f4ebb57187fec60a2de` and reported 365 passed browser cases and 57 governed skips (21.7 minutes). This verifies that recorded suite run only; it does not establish full journey conformance, current production ML acceptance, or deployed-source equivalence.

## Historical qualification evidence — recorded 2026-10-11; superseded for current status

- Historical PR #156 exact head `f7649cb24db87ecf1d0991716ecb767dfbf5fc91` passed [canonical CI 38168244547](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38168244547): 222 Node contracts, 278 components in 62 files and 350 Chromium cases without retries. Its normal runtime merge `8cf83fd0dfc14284af48bec5ecc6baa4233cdb85` passed [CI 38170602783](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38170602783) with the same counts. Audit-only successor `a6a047e` passed [scoped CI 38171104903](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38171104903), with no runtime/source changes or new activation.
- Historical normal [deployment 38170602869](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38170602869) activated that runtime SHA and passed health plus both mandatory public/authenticated smoke cases: two passed without skips/retries. Real login, guided-tour GET and Review reads remained real; only the exact automatic welcome-counter action was answered in the browser during setup and never reached production. That local response did not qualify production guided-tour persistence. Fresh 2026-10-11 20:29:53 UTC health confirmed the same SHA and HTTP 200.
- Historical [current-source nightly 38171260420](https://github.com/lido-alexion/LidoPortfolio/actions/runs/38171260420), dispatched on audit-only successor `a6a047e99b31483dbf76a3cc6079bd620b206af7` at 20:13:01 UTC, passed all 350 desktop/mobile Chromium cases at 20:28:19 UTC on 2026-10-11, without retries. The 57 skips were 55 viewport-inapplicable shell combinations plus two production-only cases; mandatory deployed smoke was independently passed. That nightly included the then-targeted visuals and asserted WCAG checks.
- Read-only deployed inspection at 2026-10-11 20:29:51 UTC found the sole Kite session expired 2026-10-06 00:30 UTC. That historical observation does not establish current session status. No provider requests, orders, cancellations or cash actions were made in that inspection.
- The historical ML report was unqualified (`evidence_current=false`, readiness false and `preflight_not_recorded` in that captured report). It does not describe the Oct 16 campaign snapshot. Production acceptance remains a separate gate; no status inference is made here.
- The actual tracker is [the repository wishlist](../archive/specs/LidoPortfolio-V9-Wishlist.md), row `V9-UX-001`. At this dated review it is **IMPLEMENTATION IN PROGRESS**; no unavailable external tracker ID or acceptance waiver is invented.

Registry import guidance was corrected against current V8 FEAT-064, not the superseded V5 migration notes. This changes explanation to match existing runtime behavior; it does not introduce a new publication prerequisite or bypass existing Library governance.
