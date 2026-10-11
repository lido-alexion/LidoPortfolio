# V9-UX-001 route and workflow traceability

Reviewed 2026-10-11 against `app/resources/js/src/App.jsx`, current journey guides and their browser suites. The frozen V9 specification §19–20 governs scope: all major Investor workflows plus Admin prerequisites that directly enable them. This inventory identifies coverage; it is not a claim of accepted deployment or completion. Fresh exact-head canonical CI, required visual comparisons, current nightly and external production/integration qualification remain separate gates in the implementation audit.

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
| `/candidates`, `/recommendations`: discovery, evaluation, review/approval/dismissal and failed actions | REC-01–10, E2E-01–07 | 03-recommendations-review, 05-end-to-end | recommendation-journeys, tos-recommendations, composite-journeys, end-to-end-journeys |
| `/candidates`: stored evaluation history; `/evaluations` redirects here | E2E-53 | 19-persisted-history | persisted-history-journeys |
| `/transactions`, `/transactions/pending`: actual-fill evidence, execution safeguards and recorded transaction correction/CSV import | EXE-01–16, E2E-28 | 04-execution-transactions, 13-financial-source-lifecycle | execution-journeys, financial-source-journeys |
| `/transactions/closed`: stored squared-off transactions and deliberate correction/deletion | EXE-16, E2E-52 | 13-financial-source-lifecycle, 19-persisted-history | persisted-history-journeys, financial-source-journeys |
| `/cash`: ledger evidence and reservations | E2E-22 | 10-portfolio-cash | portfolio-cash-journeys |
| `/corporate-action`: reviewed split/bonus preview/apply | E2E-30 | 13-financial-source-lifecycle | financial-source-journeys |
| `/holdings`: active-account scope and unmanaged attribution adoption | E2E-13, E2E-29 | 07-supporting-investor, 13-financial-source-lifecycle | supporting-investor-journeys, financial-source-journeys |
| `/holdings/:stockId/prices`: dated history without sync authority | E2E-35 | 15-research-persisted-review | research-review-journeys |
| `/watchlist/:symbol?`: membership/research, embedded insight and combo controls/preferences | E2E-09, E2E-51, AI-07–08 | 07-supporting-investor, 18-dashboard-combo-charts, 06-assistant | supporting-investor-journeys, dashboard-combo-journeys, embedded-ai |
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
| `/documentation`, grounded help and assistant preview/review/apply/action history | AI-01–09 | 06-assistant | assistant, assistant-agent, embedded-ai |
| Export download/job/cancellation and private basket across Investor pages | E2E-18–19 | 09-data-export | export-journeys |

## Admin prerequisites and supplementary evidence

AUTH-04 includes intended-user Admin reset issuance; AUTH-08 covers access/invitation administration that enables Investor entry; E2E-08 covers the governed ML readiness/acceptance prerequisite. Frozen §20 does not include every administration console as an Investor journey. The guarded sync logs/audit, data quality/source administration, indicator administration, forward-data, VPS-health, stocks/classifications and global administration routes require their separate governed operational/domain contracts; they cannot be silently exercised as live source ingestion, training or protected service changes in this epic. Runtime Investor registries above remain in scope even though Admin also exposes compatibility routes.

`responsive-shell.spec.js`, `guided-tour-browser.spec.js`, `journey-accessibility.spec.js`, `v8-accessibility.spec.js` and component/Node contracts supplement navigation, discovery, keyboard focus, theme/viewport behavior and target WCAG checks. Existing screenshot references plus the new SCR-06 result states are retained; the desktop E2E-49 reference and corrected faithful mobile viewport have been captured and visually reviewed; a fresh exact-head comparison remains required. AUTH-08 guide steps for resend, Ignore/Reject, ban-clear and direct invitation required a further prerequisite correction in PR #151; its twelve guarded AUTH-04/08 browser cases and nine recovery regressions are integrated in the final Registry branch and require current hosted qualification before full conformance can be claimed. No previous reference/tolerance or accessibility rule is weakened to obtain green CI.

## Qualification still required

- PR #144 exact head `bde5b317ff33bbdcde4d775c5fa626fed5adb085` passes canonical CI run 38150842142 (298 browser cases, no retries) and merged as master `84b8858d37c974ac95c1cab011ff8a57ebf4b443`. Current master CI/deployment and later exact-head results require separate verification. Wiki readiness PR #150, account recovery #145 and named Dashboard recovery #146 pass their current required checks and merged normally. Master e759265 and the remaining Combo/history/Registry/Admin heads require their own current canonical execution; old cancelled/failing/superseded results remain unqualified. Cancelled, superseded or failing runs are not accepted.
- The extended Dashboard/Combo, stored-history and runtime-registry suites require actual hosted Chromium execution; local discovery is not browser execution or acceptance; the combined suite includes the Admin prerequisites and requires its own exact-head hosted result.
- A current desktop/mobile nightly must execute the accepted head; the older successful nightly does not qualify later changes.
- The dedicated read-only identity must be configured as `STOX_SMOKE_EMAIL` and `STOX_SMOKE_PASSWORD` Actions secrets, followed by normal deployment and deployed-SHA/public/authenticated health verification. A missing/skipped mandatory authenticated test is not a pass.
- Real-Kite verification must use a usable authorized session and the accepted build's controlled read-only smoke command. Synthetic broker responses qualify deterministic UI behavior only.
- Current-build ML acceptance requires the governed source/preflight/preview/review/apply evidence and specific authorization; historical sealed source rows/backfills or mock tests are not current qualification.
- The actual epic tracker is `docs/archive/specs/LidoPortfolio-V9-Wishlist.md`; its Implemented status must remain unchanged until the entire frozen §19 gate qualifies. No unavailable external tracker ID is invented.

Registry import guidance was corrected against current V8 FEAT-064, not the superseded V5 migration notes. This changes explanation to match existing runtime behavior; it does not introduce a new publication prerequisite or bypass existing Library governance.
