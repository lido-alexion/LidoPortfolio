# Frontend And Navigation

## 1. Purpose And Scope

This document is the authoritative current product and technical contract for the StoX Investor application shell, frontend navigation, responsive behavior, shared page anatomy, contextual UI utilities, common interaction states, and incremental frontend evolution.

It distinguishes:

- **Required product contract** — accepted behavior StoX is required to provide, whether or not current implementation alignment has been verified.
- **Current implementation anchor** — the component, context, hook, catalog, route, style foundation, or test that currently implements or is intended to implement that contract.

This document does not own portfolio accounting, recommendation policy, broker authority, execution safety semantics, market-data calculations, or Knowledge-note data semantics. Those belong to the relevant current domain documents. Frontend presentation must preserve those domain contracts and must not create parallel business state.

## 2. Application Shell

### Required Product Contract

The authenticated Investor application has five architectural zones:

1. **Top Header** — global identity, status, safety, account, search and contextual utilities.
2. **Left Navigation** — primary application-area navigation.
3. **Main Workspace** — page hierarchy, controls and task content.
4. **Right Utility Rail / Contextual Pane** — Page Visit History, Contextual Notes and future contextual utilities.
5. **Low-priority Footer** — noncritical information that may collapse, hide or be omitted in task-focused layouts.

On standard desktop, the left navigation participates in the layout and the right utility rail remains compact. Major right-side panes overlay the workspace instead of permanently shrinking it. On constrained widths, navigation and contextual utilities transform into explicit drawers, sheets, menus or buttons. Capability must remain reachable even when its geometry changes.

### Current Implementation Anchor

`app/resources/js/src/App.jsx` owns the authenticated shell and currently mounts `AppHeader`, `CriticalNotificationBanner`, `Sidebar`, `PageChrome`, route content and `ContextualNotesPane`. `SidebarProvider` and `NotificationProvider` provide shell state. Shared shell styling is primarily in `app/resources/js/src/styles/lido-app.css`.

The running shell must be checked against the complete five-zone contract during implementation audits; a mounted component alone does not prove the contract is complete.

### Top Header

The header contract includes:

- StoX identity and a link to the Dashboard;
- compact Global Search that expands without disruptive page reflow;
- contextual Help for the current page;
- the existing `ThemeToggle` / `ThemeContext` mechanism;
- notifications and global status where applicable;
- account/profile and portfolio controls;
- execution-state indicators and emergency/safety controls where required;
- constrained-width overflow or compact presentation for secondary utilities.

Contextual Help must continue to resolve documentation for the current route; replacing it with only a generic documentation-home link is not equivalent. Safety-critical state and actions must remain reachable when secondary header controls collapse.

Current anchors include `AppHeader.jsx`, `HeaderHelpButton.jsx`, `NotificationBell.jsx`, `ExecutionSafetyControls.jsx`, `PortfolioSwitcher.jsx`, `ProfileMenu.jsx`, `ThemeToggle.jsx`, `ThemeContext.jsx`, and `GlobalSearch.jsx`. `ThemeToggle` is currently presented through the profile menu. Global Search is mounted for the Investor shell; exact geometry, overflow and deployed reachability remain runtime checks.

Implementation alignment: Global Search is implemented for the approved shell surface; constrained-width overflow and representative responsive geometry are covered by the mocked Chromium Playwright matrix. Deployed reachability and future visual regressions remain normal runtime checks.

### Left Navigation

Navigation is metadata-driven. Expanded desktop navigation uses icon-and-label rows, grouped hierarchy, visible active state and explicit collapse control. Collapsed navigation remains icon-based with hover and focus labels, comfortable targets and an obvious active destination. Collapse state and group state are presentation preferences, not business state.

Constrained widths use an overlay drawer with an explicit open/close action, backdrop, Escape handling, focus containment and body-scroll management. No navigation capability may depend solely on hover. Small desktop may collapse; ultrawide layouts may keep navigation expanded.

Current anchors:

- `app/resources/js/src/navigation/routes.js` — canonical `ROUTES` constants and route builders;
- `app/resources/js/src/config/navigation.js` — core navigation catalog;
- `app/resources/js/src/navigation/registry.js` and `bootstrap.js` — navigation registry and bootstrap;
- `app/resources/js/src/utils/navigationTree.js` — tree building, matching, breadcrumbs and titles;
- `app/resources/js/src/components/sidebar/**` — sidebar rendering, favourites and quick actions;
- `app/resources/js/src/context/SidebarContext.jsx` — desktop collapse, overlay mode, breakpoints and persisted state;
- `app/resources/js/src/navigation/constants.js` — navigation storage keys.

The current layout threshold is defined in `SidebarContext`: layout mode begins at 1200 px and ultrawide mode at 1600 px. These values are implementation anchors, not permission to replace responsive product behavior with device detection.

### Main Workspace

The main workspace must use available width intelligently. Prose and focused forms may retain readable maximum widths; tables, charts and analytical views should use width aggressively. Large displays should expose useful parallel context instead of stretching controls or cards to excessive widths.

`PageChrome.jsx` currently supplies breadcrumbs, page title, badges/tags and `document.title`. Route content is mounted by `AppRoutes` or `AdminAppRoutes` in `App.jsx`.

### Right Utility Rail / Contextual Pane

The required rail initially contains:

- Page Visit History;
- Contextual Notes;
- a reserved extension point for future contextual utilities.

The rail is compact by default. Normally only one major contextual pane is open at a time. A desktop pane overlays the workspace rather than permanently reducing its width. Mobile replaces the rail with explicit actions that open a menu, drawer or sheet.

`ContextualNotesPane.jsx` and `PageHistoryRail.jsx` are mounted through `RightUtilityRail` in the authenticated Investor shell. Their exact browser geometry, touch behavior and deployed reachability remain runtime checks.

Implementation alignment: the unified rail, one-pane coordination and Page Visit History are covered by source tests and representative Chromium browser checks; deployed reachability remains normal runtime monitoring.

### Low-priority Footer

The footer is optional in task-focused layouts and may collapse or hide when it adds clutter. It must not consume significant workspace merely for visual symmetry, and it must never contain the only path to a critical function. Footer visibility must not cause disruptive workspace movement.

## 3. Navigation Taxonomy And Routing

### Routing Contract

`ROUTES` is the canonical source for app path constants, quick actions and deep links. Navigation-related code should import route constants or route builders rather than repeat path literals. The navigation catalog owns page metadata, grouping, sidebar visibility, favourites eligibility, permissions, badges/tags and active-route matching.

Top-level sidebar workflows use `showInSidebar: true`. Editor, detail, settings and pipeline-inspection routes may remain routable and participate in breadcrumbs/highlighting while being omitted from the primary sidebar. Route-backed tabs and links must support direct navigation, refresh, browser history and meaningful deep links.

The Laravel web route remains the SPA fallback for frontend paths. Unknown `/api/*` paths must remain API 404 responses and must not fall through to the SPA.

### Current Taxonomy

Portfolio:

- Dashboard `/`
- Holdings `/holdings`, with stock-price detail under `/holdings/:stockId/prices`
- Watchlist `/watchlist`
- Transactions `/transactions`, pending execution and closed transactions
- Performance & Tax `/portfolio/performance-tax`
- Historical Holdings `/portfolio/historical-holdings`
- Portfolio Compare `/portfolio/compare`
- Portfolio Snapshots `/portfolio/snapshots`
- Cash `/cash`
- Corporate Actions `/corporate-action`

Market:

- Stock Explorer `/explorer`
- Patterns `/patterns`
- Indices `/indices`
- Calendar `/calendar`
- Market Depth `/market-depth`
- Discovery/Candidates `/candidates` remains routable for pipeline inspection but is not sidebar-first.

Trading:

- Recommendations `/recommendations`
- Review `/review` and reports under `/review/reports`
- Artifact Library `/artifact-library`
- Strategies `/strategy`
- Strategy Registry `/strategy/registry`
- Backtests `/backtests`
- Screeners `/screeners`
- Screener Registry `/screeners/registry` as an internal/sub-route.

Knowledge:

- Knowledge Board `/knowledge-board`
- Knowledge Tags `/knowledge-board/tags`
- Wiki `/knowledge-board/wiki`

Administration and account surfaces include Settings, alert policies, users, stocks, sync logs, data quality, indicators, admin alerts, audit explorer, universe price sync, fundamentals, ML scoring, notification history/settings, portfolios, profile and documentation. `AdminRoute` protects admin-only Investor-shell routes; `AdminAppRoutes` provides the role-separated Admin route set.

`/evaluations` currently redirects to `/candidates`; the older standalone Evaluations navigation is superseded and must not be revived as V1-style navigation.

Active highlighting is determined by catalog `match` predicates and navigation-tree helpers, not by visual components guessing from labels.

## 4. Standard Page Anatomy

The preferred hierarchy is:

`Breadcrumbs → Page / Entity Header → Entity Tabs → Local Selector / Filter Controls → Main Content → Advanced / Detail Regions`

- Use breadcrumbs when route or entity hierarchy helps orientation. Omit them where they add no useful hierarchy, especially on constrained mobile layouts.
- Use a page/entity header for title, identity, status and primary actions.
- Use route-backed tabs for peer views of the same entity when deep links and browser history matter.
- Use segmented controls for approximately two to four mutually exclusive local choices.
- Use a dropdown or combobox for larger or searchable choice sets.
- Use cards for meaningful conceptual grouping, not for every label or row.
- Use drawers or contextual panes for secondary detail that should remain near the current task.
- Use accordions/expandable sections for local secondary detail.
- Use modals only for short, focused decisions.
- Use links for navigation and buttons for actions. Controls that look like links or buttons must retain the corresponding semantics and keyboard behavior.

`PageChrome.jsx` and `navigationTree.js` are the current breadcrumb/title anchors. Shared tab, segmented-control, drawer and state-component adoption remains incremental; new pages should extend an existing foundation before introducing a parallel visual language.

## 5. Page Visit History

Page Visit History is a required shell capability.

Desktop contract:

- display a right-edge vertical rail;
- retain at most the last 12 page visits;
- order visits most recent first;
- collapse consecutive duplicates;
- mark the current page active;
- render bars extending leftward with a thicker, rounded-end treatment;
- use the StoX active accent `#1e90ff` and a visually quiet inactive state;
- reveal labels on hover and keyboard focus;
- truncate long labels with tooltip/focus treatment that preserves the full destination name;
- render entries as links with pointer behavior and normal browser navigation semantics.

Mobile contract:

- a persistent rail is not required;
- an explicit History action opens recent pages in a menu, sheet or drawer;
- the same history order, duplicate-collapse and link behavior applies.

Current implementation anchors: `PageHistoryRail.jsx`, `PageHistorySheet.jsx`, `RightUtilityRail.jsx`, `usePageVisitHistory.js`, and `page-history.test.jsx`. Source tests establish state semantics; browser geometry and deployed reachability remain runtime checks.

Implementation alignment: requires verification under the V1-V7 implementation audit. This note does not make the requirement optional.

## 6. Contextual Notes Integration

This section owns Notes placement in the shell. Note ownership, context keys, persistence, account/portfolio scoping and authorization belong in [Knowledge And Documentation](./knowledge-and-documentation.md).

Desktop contract:

- expose a persistent Notes action in the right utility rail;
- open a narrow right overlay pane, roughly navigation-pane width or slightly wider;
- overlay rather than resize the main workspace;
- allow a slightly transparent idle presentation and full opacity on hover/focus-within where this does not impair readability;
- use a short functional slide from the right;
- respect `prefers-reduced-motion`.

Mobile contract:

- use a near-full-width drawer or bottom sheet;
- remain fully opaque;
- do not depend on hover behavior.

Current implementation anchors include `ContextualNotesPane.jsx`, its mount in `AuthenticatedShell`, and `.lido-notes-rail-button` / `.lido-context-notes-pane` styles. The component derives a stable route context from the current pathname and uses the active portfolio when requesting notes.

Implementation alignment: rail integration, coordinated one-pane behavior, opacity/motion and mobile sheet behavior are covered by source tests and representative Chromium browser checks; exact visual tuning remains ordinary frontend regression work.

## 7. Responsive Contract

Responsiveness is based on viewport capability, not device identity.

### Mobile

- single-column primary flow;
- drawer navigation;
- contextual tools become explicit drawers/sheets/actions;
- fewer persistent labels and breadcrumbs where appropriate;
- tables hide secondary columns, expose row detail, transform suitable rows into cards or scroll horizontally when tabular relationships matter;
- safety state and actions remain reachable.

### Tablet / Small Desktop

- one or two content columns;
- navigation overlays or collapses when needed;
- contextual panes usually overlay;
- secondary chrome reduces before core content is squeezed.

### Standard Desktop

- persistent or expandable left navigation;
- multi-column workspace where useful;
- right utility rail;
- full tabs and filter rows when space permits.

### Ultrawide / Large Display

- add meaningful parallel context, columns and side-by-side detail;
- widen analytical tables and charts;
- retain readable widths for prose and focused forms;
- do not simply stretch cards to fill the viewport.

Capability preservation matters more than geometrical similarity. Every hover-only discovery or action must have a focus and touch-accessible equivalent.

## 8. Shared Component And Interaction Contracts

| Need | Required contract | Current implementation anchor |
|---|---|---|
| Global Search | Compact desktop control expands without reflow; mobile opens a full-width surface/sheet | `GlobalSearch.jsx`, `AppHeader.jsx`, and focused shell tests; viewport geometry and deployed reachability require verification |
| Contextual Help | Global header action resolves help for the current route | `HeaderHelpButton.jsx`, documentation-link utilities |
| Theme | Reuse Light/System/Dark theme mechanism | `ThemeToggle.jsx`, `ThemeContext.jsx`, `themeInit` |
| Primary navigation | Metadata-driven collapsible/overlay navigation | `Sidebar.jsx`, `SidebarContext.jsx`, navigation catalog/registry |
| Breadcrumbs/page title | Shared route-derived page chrome | `PageChrome.jsx`, `navigationTree.js` |
| Tabs | One consistent language; route-backed where navigation state matters | Existing page tab implementations; adoption must be checked per surface |
| Segmented controls | Two to four local peer choices, never destructive actions | Existing page-local controls; shared adoption requires verification |
| Toggle | Persistent binary state | Existing Bootstrap/StoX control foundations |
| Dropdown/combobox | Larger or searchable option sets | Existing form and autocomplete foundations |
| Integer input | Reuse the existing StoX integer spinner where appropriate | Existing strategy/settings numeric controls |
| Charts | Reuse the current chart foundation and theme behavior | Existing chart components under feature component directories |
| Tables | Responsive data presentation preserving access to information | Existing `.table-responsive` and page table foundations |
| Contextual detail | Right pane/drawer on desktop; drawer/sheet on mobile | `ContextualNotesPane` is one current anchor |
| Loading | Skeleton for content-heavy regions; focused spinner for narrow actions | Existing page-local skeleton/spinner patterns; consistency requires verification |
| Empty state | Explain the absence and provide a relevant next action where possible | Existing page-local empty states; shared `EmptyState` adoption requires verification |
| ScrollToTop | Appear after meaningful scroll, target the correct container and respect reduced motion | No common anchor confirmed; test/implementation alignment required |
| Status chip | Semantic color plus text/icon; never color alone | Existing badges/status components |
| Icon button | Accessible name, focus treatment and tooltip where meaning is not obvious | Existing `lido-icon-action` and Lucide-based actions |

Existing reusable controls and foundations should be extended before a parallel implementation is introduced.

## 9. Shared UI State Vocabulary

Pages and components must distinguish:

- **Loading** — data or content is actively being obtained; use a skeleton for content-heavy regions and a focused spinner for narrow actions.
- **Empty** — the query completed successfully and no records exist; explain the condition and offer a relevant next step when possible.
- **Zero** — a known numeric result is exactly zero.
- **Unknown** — the value cannot currently be determined.
- **Unavailable** — the value or capability exists but its source/provider/precondition is unavailable.
- **Incomplete** — some required inputs, periods or workflow stages are missing.
- **Not applicable** — the concept does not apply to this record or mode.
- **Error** — the operation failed; preserve useful prior context and a safe retry/recovery path where possible.

Missing data must not be converted to `0`. Loading, empty and failure states must not collapse into the same blank surface. Disabled controls should expose why an action is unavailable when the reason is not obvious.

## 10. Dashboard Non-Regression Contract

The Dashboard is presentation and aggregation, not the source of truth for portfolio calculations, cash accounting, snapshot generation or market analytics.

The minimum preservation baseline includes:

- portfolio value, invested value, P&L and XIRR;
- cash available and investable cash;
- top gainer/loser with all-time/latest-day switching;
- position count, diversification and average relative-strength analytics;
- Market Health and collapsible gauges/diagnostics;
- active alerts with acknowledge and clear actions;
- upcoming calendar/events;
- actionable pattern signals on current holdings;
- Relative Strength views;
- allocation table/visualization;
- Portfolio Growth chart;
- unrealized P/L history chart;
- portfolio-history rebuild action;
- path/action to snapshots;
- refresh/cache behavior;
- relevant admin-only price-sync/status controls;
- useful links to deeper pages such as Market Depth and Patterns;
- existing local preferences such as mover period, allocation display mode and collapsible diagnostics.

**Existing Dashboard information or convenience functionality must not be removed solely for visual simplification without explicit product-owner review.**

Noncritical widgets may support show/hide, order, responsive placement and simple local sizing. Defaults must retain a useful complete Dashboard and a reset-to-default path. Emergency and safety controls are never hideable widgets. Widget preferences are UI state, not business state.

Implementation alignment: the preserved-capability inventory and preference/reset behavior require verification under the V1-V7 implementation audit.

## 11. General UI Non-Regression Contract

Before a materially redesigned surface is considered complete, its pre-change capabilities must be inventoried and accounted for as one of:

- retained;
- retained with different presentation;
- moved with a clear access path;
- intentionally removed with recorded product-owner approval.

This inventory includes major workflows and small conveniences. Preserve contextual and external links, copy helpers, ready-made LLM prompts, shortcuts, status explanations, contextual documentation, quick analysis/review actions and compact admin/diagnostic utilities. They may move into tooltips, compact action groups, overflow menus, helper sections or contextual panes, but they must not disappear merely because they do not fit a new visual composition.

This is a current engineering completion gate, not historical process commentary.

## 12. Tables, Charts And Forms

### Tables

- use available desktop width and comfortable row density;
- hide genuinely secondary columns first at constrained widths;
- expose hidden information through expansion/detail;
- transform rows into cards only when the relationships remain understandable;
- use horizontal scrolling when tabular semantics must remain intact;
- preserve filtering, search, sorting and exact-value access when presentation changes.

### Charts

- reuse the existing StoX chart foundation rather than adding a library for visual variety;
- support responsive sizing and the active theme;
- provide legends, tooltips, range selectors and hover/tap interactions where relevant;
- expose accessible exact values where practical;
- distinguish loading, empty, unavailable and error states.

### Forms

- binary setting → toggle;
- two to four peer choices → segmented, radio or selectable control;
- larger/searchable set → dropdown or combobox;
- integer value → existing integer spinner where appropriate;
- destructive or high-risk action → icon plus text where recognition and safety matter.

## 13. Preferences, Theme, Accessibility And Motion

### Preferences

Stable UI-only preferences may use `localStorage`; short-lived navigation position may use `sessionStorage`. Preferences should be namespaced by application/page/component, versionable, tolerant of missing/stale values and safely resettable. Incompatible values should be migrated or discarded safely.

Never store auth tokens, execution authority, broker authority, financial state or other business state in the UI preference mechanism.

Current anchors include sidebar collapsed/group state, sidebar favourites, theme preference and selected page-local display preferences.

### Theme

Use the existing `ThemeToggle` and `ThemeContext` for Light, System and Dark themes. Do not introduce a competing selector or persistence model. Semantic success, warning, error, information and neutral states remain distinct, and color alone must not communicate state.

### Accessibility

- keyboard-accessible controls and visible focus treatment;
- adequate pointer/touch targets;
- accessible names for icon-only actions;
- focus/touch alternatives to hover-only labels and actions;
- no state conveyed only through color;
- accessible exact chart values where practical;
- focus containment and restoration for modal drawers/sheets where appropriate.

### Motion

Motion should be short, functional and free of decorative delay. Avoid hover-driven layout shift. Navigation, panes, drawers, accordions and scrolling must respect `prefers-reduced-motion`.

## 14. Frontend Architecture

StoX uses a React SPA served by Laravel. React owns app navigation; Laravel serves the SPA fallback for non-API routes. The main anchors are:

- `app/resources/js/src/App.jsx` — shell and route mounting;
- `app/resources/js/src/navigation/routes.js` — route constants/builders;
- `app/resources/js/src/config/navigation.js` — navigation metadata;
- `app/resources/js/src/navigation/**` — registry, access context, quick actions and storage constants;
- `app/resources/js/src/utils/navigationTree.js` — route matching, tree, breadcrumb and title derivation;
- `app/resources/js/src/components/navigation/PageChrome.jsx` — page chrome;
- `app/resources/js/src/components/sidebar/**` and `SidebarContext.jsx` — responsive navigation shell;
- `app/resources/js/src/utils/documentationLinks.js` — contextual help URLs;
- `app/resources/js/src/queryClient.ts` and feature hooks — shared query/cache foundations;
- `app/resources/js/src/styles/lido-app.css` — shell and shared visual foundations.

Frontend logs post to `/api/logs/frontend`. Authentication/session changes clear user-sensitive caches through the auth/query foundations.

**Do not perform a big-bang frontend rewrite merely for uniformity.**

When changing a surface:

1. inventory its current capabilities and conveniences;
2. identify existing shared components and domain contracts;
3. reuse or extend those foundations;
4. migrate the touched surface incrementally;
5. compare the before/after capability inventory;
6. avoid rewriting unrelated functioning screens.

## 15. Test And Verification Anchors

Meaningful current anchors include:

- `app/tests/js/tos/tos-shell.test.jsx` — selected authenticated Trading OS shell/page behavior;
- `app/tests/js/tos/helpers/renderTosApp.jsx` — test shell mounting `AppHeader`, `Sidebar` and `PageChrome`;
- navigation-tree and catalog tests under `app/tests/js` where present — route metadata, page title, breadcrumb and matching behavior;
- `app/tests/js/auth-redirect.test.mjs` — authentication redirect-path behavior;
- `app/tests/Feature/V6ContextualNotesTest.php` — Contextual Notes API, scope and domain behavior, not shell geometry;
- Laravel route/auth feature tests — server-side access and SPA/API boundaries.

These tests do not by themselves prove full responsive behavior, visual reachability or every V6 E4 acceptance criterion.

Test coverage gaps — implementation audit follow-up:

- Page Visit History rail and mobile surface geometry/reachability beyond the existing state and source tests;
- complete five-zone shell behavior;
- right-utility one-pane coordination;
- Contextual Notes overlay geometry, mobile sheet, opacity and reduced motion;
- Global Search viewport geometry and constrained-width behavior beyond the existing source tests;
- Dashboard and general old-vs-new non-regression inventory;
- route-wide standard page anatomy and loading/empty/error-state adoption;
- mobile, tablet, desktop and ultrawide visual regression coverage;
- shared ScrollToTop behavior.

## 16. Debugging Sources

- **Missing page:** check `ROUTES`, `AppRoutes`/`AdminAppRoutes`, catalog registration, permissions and the Laravel SPA fallback.
- **Bad sidebar highlighting:** check the catalog `match` predicate, `findActiveNavItem`, `findActiveSidebarPageId` and parent/group metadata.
- **Page title or breadcrumb mismatch:** check `PageChrome.jsx` and `navigationTree.js` route resolution.
- **Shell component not mounted:** check `AuthenticatedShell` in `App.jsx`, role branch, documentation-route exclusion and component conditional rendering.
- **Sidebar collapse/drawer problem:** check `SidebarContext.jsx`, media queries, storage keys, overlay state, focus trap and `lido-app.css` shell styles.
- **Contextual Help mismatch:** check `HeaderHelpButton.jsx`, `documentationLinks.js`, `app/public/docs`, `DocumentationPage.jsx` and route-context mapping.
- **Contextual Notes problem:** check `ContextualNotesPane.jsx`, active portfolio context, context-key derivation, `/api/contextual-notes` and notes-pane CSS.
- **Preference problem:** check navigation constants, `localStorage`/`sessionStorage` reads, stale JSON handling and reset/default behavior.
- **Responsive problem:** check `SidebarContext` media queries, component conditional rendering and relevant `lido-app.css` media/hover rules.
- **Theme problem:** check `ThemeContext.jsx`, `ThemeToggle.jsx`, `themeInit` and stored preference.
- **Auth redirect:** check `AuthContext.jsx`, login/reset/invite pages, `AdminRoute`, session probes and `auth-redirect.test.mjs`.
- **Broken generated docs link:** check `app/public/docs`, `appDocumentation.js`, documentation-link utilities and the documentation build/generation path.
- **Frontend exception or boot failure:** check `ErrorBoundary`, `BootErrorBanner`, `/api/logs/frontend`, browser console and server frontend logs.

## 17. Implementation Alignment Notes

The following accepted contracts require runtime or implementation verification. This list records alignment questions; it is not a defect register and does not weaken the requirements:

- Page Visit History/right-edge rail and mobile History action;
- unified right utility rail and one-major-pane coordination;
- complete Contextual Notes overlay/mobile/reduced-motion behavior;
- Global Search constrained-width overflow and viewport geometry;
- five-zone shell completeness, including footer treatment;
- standard page-anatomy adoption across routes;
- shared Skeleton, EmptyState and ScrollToTop adoption;
- Dashboard preservation baseline and preference reset behavior;
- general old-vs-new capability inventory gate;
- responsive table/chart/form conventions across mobile through ultrawide;
- automated coverage of V6 E4 UX requirements.

Implementation status belongs in `docs/audit/V1-V7-IMPLEMENTATION-AUDIT.md` and subsequent audit artifacts. This current document remains the authoritative accepted contract.

## 18. Related Docs And Historical Context

Related current documentation:

- [Product Overview](./product-overview.md)
- [Knowledge And Documentation](./knowledge-and-documentation.md)
- [Administration, Security, And API](./administration-security-api.md)
- [Analytics, Review, And Backtesting](./analytics-review-backtesting.md)
- [Execution, Broker, And Safety](./execution-broker-safety.md)

Earlier specifications organized navigation chronologically around V1 Trading OS pages. The current feature-oriented taxonomy and metadata-driven sidebar supersede that structure. Archived V6 E4 material remains the source of accepted shell and UX contracts promoted here; chronology, implementation-pass narration and superseded navigation labels remain historical context rather than current structure.
