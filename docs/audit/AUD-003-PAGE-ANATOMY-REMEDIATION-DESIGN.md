# AUD-003 — Page Anatomy / Component Consistency Remediation Design

## 1. Finding Recap

The accepted V6 E4 contract defines a common page language for the Investor application: route-derived page chrome, meaningful headings, predictable action placement, appropriate tabs or segmented controls, explicit loading/empty/error states, responsive behavior, and accessible controls. The current implementation has a real shared shell and several reusable primitives, but adoption inside page bodies is uneven.

This is an audit and design document only. No product code, CSS, tests, or current product documentation is changed by this pass.

Current audit conclusion:

- `PageChrome`, route metadata, the left navigation, `DataTable`, `TablePagination`, `SegmentToggle`, chart components, `RightUtilityRail`, Page History, and Contextual Notes are implemented anchors.
- Page-level loading, empty, error, table, form, and mode-selection patterns remain mixed and are often implemented locally.
- The audit found no statically proven financial/accounting source-of-truth mutation caused by page presentation.
- Browser visual, touch, chart-sizing, overflow, and full keyboard-flow behavior remain runtime verification items.

Confirmed findings: **6** — **0 Critical, 0 High, 4 Medium, 2 Low**.

## 2. Accepted Page Anatomy Contract

V6 E4 requires the following contract, with conditional items applied only when a page needs them:

| Concern | Required contract |
| --- | --- |
| Page identity | A meaningful route-derived title; breadcrumbs where hierarchy is useful; optional context/subtitle. |
| Actions | Primary actions are discoverable and use button semantics; navigation uses links; destructive actions are explicit. |
| Selection | Route-backed peer views use tabs; two to four local mutually exclusive choices use a segmented control; larger/searchable choices use a dropdown/combobox. |
| Content | KPI summaries precede primary charts/tables; advanced detail is progressively disclosed; contextual detail uses a pane/drawer where appropriate. |
| Data states | Loading, empty, zero, unknown, unavailable, incomplete, not-applicable, and error are distinct. Missing data must not become `0`. |
| Tables/charts | Tables preserve access to information on narrow screens; charts expose exact values and handle missing data explicitly. |
| Forms | Labels, validation, disabled/submitting states, safe destructive confirmation, and keyboard order are required. |
| Responsive behavior | Mobile uses single-column flows and sheets/drawers; tablet reduces chrome before squeezing core content; desktop may use multi-column context. |
| Accessibility | Keyboard access, visible focus, accessible names, semantic controls, non-color-only state, touch alternatives to hover, and reduced-motion support. |
| Long pages | Scroll-to-top is used where a page is sufficiently long and the control targets the correct scroll container. |
| Preferences | UI-only preferences may persist with versioned, tolerant storage; business and security state must not be stored as UI preference. |

This contract does not require every page to have tabs, charts, a drawer, a CTA on every empty state, or identical visual composition.

## 3. Shared Component Inventory

| Shared primitive | Evidence | Adoption assessment | Candidate migration |
| --- | --- | --- | --- |
| `PageChrome` | `components/navigation/PageChrome.jsx`; mounted once in `AuthenticatedShell` before Investor and Admin routes. | Consistent shell anchor. Body headings still duplicate or supplement it on many pages. | Keep shell title/breadcrumb authority; review only misleading or redundant page headings. |
| Navigation catalog/helpers | `navigationTree.js`, `findActiveNavItem`, `getPageTitle`, `buildBreadcrumbs`. | Strong route metadata foundation. | Use as the source for page/action labels rather than local title reconstruction. |
| `DataTable` / `DataTableView` / `DataTableCard` | `components/DataTable.jsx`; used by transactions, holdings, dashboard allocation, screeners, stock prices, registry and review surfaces. | Meaningful adoption, but not universal. | Migrate high-volume data pages incrementally where behavior is materially inconsistent. |
| `TablePagination` | `components/TablePagination.jsx`; used by several list pages. | Partial adoption; some pages use local pagination or no pagination. | Prefer for comparable server-paginated lists. |
| `SegmentToggle` | `components/SegmentToggle.jsx`; used by transactions, holdings, patterns, market depth, corporate actions, knowledge, charts, settings. | Existing foundation, with some page-local toggles still present. | Replace only equivalent ad hoc controls. |
| Chart foundations | `components/charts`, `components/backtest`, `components/indices`, portfolio chart components. | Domain-specific wrappers exist; no single universal chart container is proven. | Standardize accessibility/loading shells around existing chart implementations, not chart internals. |
| Forms and inputs | `NumberInput`, `TimeInput`, `TransactionDateInput`, `StockAutocomplete`, `FieldHint`, feature form components. | Good specialist coverage; page-local Bootstrap forms remain common. | Establish representative form patterns before broad migration. |
| Empty/loading/error surfaces | `DataTable` loading/empty support and page-local `spinner`, `Loading…`, `alert`, and blank-state markup. | No single general `EmptyState` or skeleton primitive was confirmed in the inspected frontend. | Add or strengthen a small shared state vocabulary only where repeated patterns justify it. |
| Modal/sheet/dialog patterns | Page-local Bootstrap modal markup plus the completed `PageHistorySheet`, `ContextualNotesSheet`, and calendar dialogs. | Functional but heterogeneous. | Reuse existing utility/sheet patterns; do not introduce a general modal framework in this pass. |
| Scroll-to-top | Backtest detail has page-local top/bottom controls; no common `ScrollToTop` shell primitive was confirmed. | Underused/fragmented. | Add a focused shared control only for demonstrably long page families. |
| `RightUtilityRail` | `components/navigation/RightUtilityRail.jsx`; current Investor shell mount for Page History and Contextual Notes. | Current shared shell foundation. | Preserve; page work must not add competing fixed right controls. |

No new design system is required for AUD-003. The main need is selective adoption and state/accessibility hardening around existing foundations.

## 4. Investor Route Inventory

The active `AppRoutes` contains **65 route declarations**, including aliases, detail routes, and Admin-wrapped routes. The meaningful Investor inventory is below; the redirect and Admin-only entries are called out separately.

| Page family | Active Investor routes / components |
| --- | --- |
| Portfolio/accounting | `/` Dashboard; `/transactions`, `/transactions/pending`, `/transactions/closed` `TransactionsPage`; `/cash` `CashManagementPage`; `/corporate-action` `CorporateActionPage`; `/holdings` `HoldingsPage`; `/holdings/:stockId/prices` `StockPricesPage`; `/portfolio/historical-holdings` `HistoricalHoldingsPage`; `/portfolio/compare` `PortfolioComparePage`; `/portfolio/performance-tax` `PerformanceTaxPage`; `/portfolio/snapshots` `PortfolioSnapshotsPage`; `/portfolios` `PortfoliosPage`; `/settings/portfolio`, `/settings/account`, `/settings/global` `SettingsPage`. |
| Discovery/market | `/watchlist/:symbol?` `WatchlistPage`; `/explorer` `StockExplorerPage`; `/indices` `IndicesPage`; `/market-depth` `MarketDepthPage`; `/candidates` `CandidatesPage`; `/patterns` `PatternGuidePage`; `/calendar` `CalendarPage`. |
| Screeners/strategies/artifacts | `/screeners` `ScreenersPage`; `/screeners/registry`, `/screeners/registry/:id` `ScreenerRegistryPage`/detail; `/screeners/:id` `ScreenerEditorPage`; `/strategy` `StrategyPage`; `/strategy/registry`, `/strategy/registry/:id` `StrategyRegistryPage`/detail; `/artifact-library`, `/artifact-library/:uuid` `ArtifactLibraryPage`/detail. |
| Analytics/review | `/backtests`, `/backtests/:id` `BacktestHistoryPage`/detail; `/review`, `/review/reports`, `/review/reports/:id` review pages; `/notification-history`; `/settings/notifications`; `/recommendations`. |
| Knowledge | `/knowledge-board`; `/knowledge-board/tags`; `/knowledge-board/wiki/:pageId?` `WikiPage`; `/documentation` `DocumentationPage`; `/profile`; `/settings/alert-policies`. |
| Redirect/shared boundaries | `/evaluations` redirects to `/candidates`; `/settings` redirects to `/settings/portfolio`; `AdminAppRoutes` separately exposes Admin surfaces; public `/wiki/shared/:token` and auth routes do not use the Investor page anatomy. |

## 5. Page Anatomy Matrix

The matrix records static evidence rather than assuming that a route name proves a behavior. `Shared` means the shell provides the concern; `Local` means the page owns it; `Partial` means the page has some evidence but not a uniform contract; `Runtime` means browser verification is still required.

| Route/page group | PageChrome/title | Actions and selection | Main content | Loading/empty/error | Responsive/accessibility evidence | Assessment |
| --- | --- | --- | --- | --- | --- | --- |
| Dashboard | `Shared`; page body has section headings and KPI labels. | Local refresh, period toggles, diagnostics disclosure, links. | KPI cards, charts, allocation table, alerts and calendar. | `DataTable` states plus local loading/error/empty branches. | Responsive CSS and labels exist; chart/layout behavior needs browser verification. | `ACCEPTABLE_VARIATION`; high preservation dependency. |
| Transactions / Holdings / Closed / Stock Prices | `Shared`; body headings supplement route title. | `SegmentToggle`, filters, search, import/edit actions, `DataTable`, pagination in some variants. | Data-heavy tables and modal forms. | Shared table loading/empty in some surfaces; local form and error states. | Table overflow and many controls have evidence; touch/focus sweep required. | `SHARED_COMPONENT_UNDERUSED`. |
| Cash / Corporate Action / Portfolio settings | `Shared` plus local section headings. | Local forms, `SegmentToggle` in corporate action, destructive/ledger actions. | Cards, forms, tables. | Mostly local spinners/alerts/empty branches. | Form labels are present in inspected sections; full keyboard/error consistency unproven. | `PAGE_ANATOMY_GAP` for shared state language, not domain behavior. |
| Discovery / Watchlist / Explorer / Indices / Market Depth / Candidates / Patterns / Calendar | `Shared`; several pages use their own title or section title. | Local filters, segmented controls, comboboxes, run/refresh/create actions. | Tables, gauges, charts, calendar grid, candidate evidence. | Mixed local `Loading…`, table states, alerts, and no-result messages. | Domain-specific controls are often labelled; chart/grid mobile behavior needs runtime verification. | `ACCEPTABLE_VARIATION` with `SHARED_COMPONENT_UNDERUSED`. |
| Screeners / Strategy / Artifact Library and detail pages | `Shared` plus local editor/detail heading. | Tabs/segments and editor actions are page-local or feature-specific; registry/list actions vary. | Editors, registry tables, validation/results panels. | Local loading/errors and result-empty states. | Form and editor accessibility is mixed; no single registry anatomy contract is demonstrated. | `PAGE_ANATOMY_GAP` for family-level consistency. |
| Recommendations / Review / Reports | `Shared`; body headings and report titles are present. | Review actions, filters, modal detail, report navigation, exports. | Tables/cards, detail panels, review modal, report content. | Local loading, informational no-result, attention/error branches. | Explicit status text and dialog semantics in key flows; full focus/keyboard coverage not proven. | `ACCEPTABLE_VARIATION`; targeted accessibility verification required. |
| Backtests / Performance / Compare / Historical Holdings / Snapshots | `Shared`; local titles and section headings. | Date controls, run/resume/cancel, compare, export, rebuild. | Charts, tables, analytical cards. | Local spinners, error alerts, empty tables, detail messages. | Backtest detail has explicit scroll controls; charts/tables require browser review. | `SHARED_COMPONENT_UNDERUSED`; scroll behavior fragmented. |
| Notifications / Knowledge / Wiki / Calendar | `Shared` where authenticated; Wiki has additional page breadcrumbs. | Local filters, tag/segment controls, create/edit/delete, calendar dialogs. | Cards, editors, lists, calendar grid, public/read-only render. | Local empty/loading/error branches; no universal state primitive. | Several aria labels/dialogs exist; mobile editor and calendar interaction remain runtime checks. | `ACCEPTABLE_VARIATION` with state consistency work. |
| Profile / Portfolios / Alert Policies | `Shared`; local section headings. | Forms, profile/session/security actions, policy CRUD. | Form and settings panels. | Local loading/error/disabled states. | Labels and button semantics are generally visible in inspected code; complete focus/error sweep pending. | `ACCEPTABLE_VARIATION`. |

## 6. PageChrome / Heading Findings

`PageChrome` is mounted once for every authenticated non-documentation shell, before both `AppRoutes` and `AdminAppRoutes`. It derives title, breadcrumbs, active navigation metadata, and `document.title` from the route tree. This is a strong shared baseline.

The body still contains many local `h1`/`h2` headings. That is not inherently a defect: section headings, entity names, report titles, and editor titles serve page semantics. The audit did not find evidence that all local headings are duplicate or misleading. The remediation should instead identify pages where a local heading competes with the shell title or where a detail title is the only meaningful context.

Finding linkage: `UX-003` below.

## 7. Action Placement Findings

Primary actions are generally present as named buttons near the page content: run/refresh on Candidates and Recommendations, New Backtest on Backtests, create actions on Calendar and Knowledge, and save/export actions on analytics/settings pages. Placement is not globally standardized, however. Some pages use an action row beside a local heading, some use a card header, and some place controls above tables.

This is a consistency and responsive predictability concern, not a statically proven missing capability. Destructive and high-risk actions need targeted review because modal/focus behavior is page-local.

## 8. Tabs / Segmented / Selection Findings

`SegmentToggle` is a real shared primitive and is used across several domains. There are also page-local segmented implementations, notably in Market Depth and other feature surfaces. Notification history uses a local nav-pills button group for view selection, and feature editors use their own tabs or sections.

The current evidence supports incremental consolidation of equivalent controls, not a wholesale replacement. Route-backed peer navigation and local 2–4 choice controls should be audited separately.

Finding linkage: `UX-004`.

## 9. Table Findings

`DataTable` provides controller, column, loading, empty-message, responsive wrapper, and card integration. It is used by important tables, but several pages still render tables locally, with local empty rows, pagination, sorting, or detail actions. This is acceptable where row structure or workflow is domain-specific, but it means numeric alignment, unavailable-value wording, mobile overflow, and keyboard row actions are not proven uniform.

The audit found responsive wrappers and table semantics in representative code. It did not prove every listed route preserves all secondary columns or actions on mobile.

## 10. Chart Findings

Charts are implemented through feature-specific components (`charts`, `backtest`, `indices`, and portfolio chart components), rather than one universal wrapper. This is a reasonable domain variation. Shared expectations remain: exact values must be accessible, missing data must not be silently plotted as zero, and loading/empty states must be explicit.

Static source evidence shows chart components and surrounding state branches, but not complete browser resize behavior, tooltip keyboard access, or visual fallback behavior across all charts.

## 11. Form Findings

The repository has reusable specialist inputs and many labelled Bootstrap forms. Forms remain substantially page-local, especially in settings, editors, transaction workflows, and dialogs. Required indication, validation placement, disabled/submitting wording, destructive confirmation, and mobile keyboard flow should be verified by representative family rather than normalized through a broad refactor.

No backend validation or accounting behavior is in scope for AUD-003.

## 12. Loading / Empty / Error Findings

Loading and empty behavior is materially mixed:

- `DataTable` supports shared loading and empty rendering.
- Some pages use plain `Loading…` text or a spinner.
- Some pages use local cards such as “No notifications in this view,” “No backtests yet,” or “No evaluation runs…”.
- Errors commonly use local Bootstrap alerts or toast messages.
- Dashboard and analytical pages combine stale/empty/error branches with chart/table-specific handling.

This does not prove every local implementation is wrong. It does prove that the shared V6 state vocabulary is not yet represented by a consistently adopted frontend primitive.

Finding linkage: `UX-001` and `UX-002`.

## 13. State Semantics Findings

The required vocabulary distinguishes empty, zero, unknown, unavailable, incomplete, not applicable, loading, and error. Representative code already contains explicit phrases such as “No price data,” “Incomplete,” “No holdings,” and “No actionable patterns,” which is positive evidence.

This static pass found **no confirmed cross-domain semantic bug** where missing data is definitely converted into a financial `0`. There are enough local fallbacks and compact value renderers that a focused audit is still warranted before declaring the invariant globally proven. Do not treat this as a confirmed defect or change domain calculations under AUD-003.

## 14. Drawer / Sheet / Scroll Findings

The application uses page-local dialogs, calendar dialogs, recommendation and holding modals, the Page History mobile sheet, and the Contextual Notes desktop/mobile utility surfaces. The completed right-utility foundation is now the correct home for contextual utilities; new page-level fixed right controls would be inconsistent.

Backtest detail has explicit top/bottom scroll controls. No common `ScrollToTop` primitive or shell-level route scroll policy was confirmed. Long pages should be assessed by family; not every short settings page needs one.

Finding linkage: `UX-005`.

## 15. Responsive Findings

The stylesheet contains multiple responsive breakpoints, table overflow wrappers, mobile sidebar behavior, utility-sheet rules, and reduced-motion rules. This is evidence of responsive implementation, not proof that all 65 route declarations render correctly at desktop, tablet, and mobile widths.

No new responsive defect is statically confirmed by this audit. Runtime verification is required for tables, charts, editors, calendar grids, modal stacking, utility rail overlap, and dense action rows. The Page History and Contextual Notes shell must remain unchanged while this work proceeds.

## 16. Accessibility Findings

Representative code includes semantic headings, labelled controls, `aria-label`, dialog roles, `aria-current`, and focus work in the utility surfaces. Coverage is not uniform enough to prove every page-level icon action, custom button group, chart, table action, and modal has the required keyboard and screen-reader behavior.

Concrete accessibility review should prioritize local icon-only actions, custom tabs/pills, chart exact-value fallbacks, and page-local dialogs. This audit does not promote a broad accessibility defect without a route-specific reproducer.

Finding linkage: `UX-006`.

## 17. Duplicate / Ad Hoc Pattern Findings

The main repeated patterns are page-local loading/empty/error blocks, local table markup alongside `DataTable`, local segmented/button groups alongside `SegmentToggle`, and page-specific dialog wrappers. Some duplication is justified by domain workflow; some is an opportunity to reduce maintenance.

The recommended approach is representative migration with evidence-based extraction. Do not create a parallel design system or rewrite all pages for visual uniformity.

## 18. Confirmed Findings

### UX-001 — Shared state primitives are under-adopted

- **Requirement:** Loading, empty, error, unknown, unavailable, incomplete, and zero states should be explicit and consistent.
- **Route/page:** Cross-cutting; especially data tables, Dashboard, Candidates, Recommendations, Backtests, Notifications, and analytics.
- **Evidence:** `DataTable` has shared loading/empty support, while pages also use local spinners, text, cards, alerts, and toasts; no general shared `EmptyState` or skeleton primitive was confirmed.
- **Classification:** `SHARED_COMPONENT_UNDERUSED`
- **Severity/confidence:** Medium / High.
- **User impact:** Similar situations can present different affordances and wording; missing versus empty can be harder to interpret.
- **Remediation direction:** Define a small state-surface vocabulary and migrate representative page families, preserving domain-specific copy.
- **Runtime verification:** Confirm visual hierarchy, stale-content behavior, and screen-reader announcements in browser.

### UX-002 — Table and list anatomy is only partially shared

- **Requirement:** Data-heavy pages should preserve headers, actions, pagination, numeric semantics, empty/loading states, and narrow-screen access.
- **Route/page:** Transactions, Holdings, Candidates, Recommendations, Review Reports, Notifications, registries, Backtests, and admin-adjacent shared lists.
- **Evidence:** `DataTable`/`TablePagination` are used in important surfaces, but local table markup and local pagination/empty rows remain in other pages.
- **Classification:** `SHARED_COMPONENT_UNDERUSED`
- **Severity/confidence:** Medium / High.
- **User impact:** Sorting, overflow, unavailable values, and row actions may not behave predictably across similar lists.
- **Remediation direction:** Choose one representative list per family, compare behavior, then migrate only equivalent structures.
- **Runtime verification:** Test horizontal access, sticky/scroll behavior, touch targets, and keyboard row actions.

### UX-003 — Page-level heading and action composition varies

- **Requirement:** Page identity and primary actions should be predictable while allowing meaningful section/detail headings.
- **Route/page:** Registry/editor, analytics, settings, detail, and data-list families.
- **Evidence:** `PageChrome` is global, but many pages add local `h1`/`h2` and action rows with different placement patterns. Static code alone cannot distinguish every intentional section heading from a competing page title.
- **Classification:** `PAGE_ANATOMY_GAP`
- **Severity/confidence:** Medium / Medium.
- **User impact:** Users may need to relearn where the primary action or page context appears on related routes.
- **Remediation direction:** Create a page-family checklist for title/context/action placement; retain section headings that add semantics.
- **Runtime verification:** Validate visual hierarchy and responsive action wrapping.

### UX-004 — Selection-control language is not fully normalized

- **Requirement:** Use route-backed tabs, shared segmented controls for small local choice sets, and dropdowns/comboboxes for larger sets.
- **Route/page:** Market Depth, Notification History, Transactions, Corporate Actions, Knowledge, chart controls, and feature editors.
- **Evidence:** `SegmentToggle` is reused, but local nav-pills/button groups and page-local control implementations coexist.
- **Classification:** `SHARED_COMPONENT_UNDERUSED`
- **Severity/confidence:** Medium / High.
- **User impact:** Similar choices can differ in keyboard semantics, URL behavior, and active-state communication.
- **Remediation direction:** Inventory controls by navigation semantics before replacing them; use shared primitives only for equivalent choices.
- **Runtime verification:** Keyboard arrow/tab behavior, `aria-selected`/current semantics, and mobile overflow.

### UX-005 — Long-page return behavior is fragmented

- **Requirement:** Long pages/panes should provide an unobtrusive, accessible scroll-to-top affordance when useful.
- **Route/page:** Backtest detail has local top/bottom controls; other long analytical, editor, and knowledge pages have no confirmed common control.
- **Evidence:** No common `ScrollToTop` component or shell policy was found in the inspected frontend.
- **Classification:** `SHARED_COMPONENT_UNDERUSED`
- **Severity/confidence:** Low / Medium.
- **User impact:** Long pages may require excessive manual scrolling, while adding a universal control would be noisy on short pages.
- **Remediation direction:** Identify genuinely long page families and add one focused, reduced-motion-aware primitive.
- **Runtime verification:** Correct scroll container, appearance threshold, mobile usefulness, and focus behavior.

### UX-006 — Page-level accessibility consistency is not proven

- **Requirement:** All controls, dialogs, tables, charts, and custom selection patterns need accessible names, focus, semantics, and non-hover alternatives.
- **Route/page:** Cross-cutting, with priority on page-local dialogs, icon actions, charts, custom button groups, and registry/editor forms.
- **Evidence:** Good examples exist, including labelled controls and dialog roles, but the codebase also contains many page-local interaction patterns and no cross-route accessibility contract tests.
- **Classification:** `ACCESSIBILITY_GAP`
- **Severity/confidence:** Low / Medium.
- **User impact:** A small subset of workflows may be harder to operate by keyboard or assistive technology even when the visual path works.
- **Remediation direction:** Add representative accessibility tests around shared primitives and high-risk page families; fix concrete findings as they are reproduced.
- **Runtime verification:** Keyboard traversal, focus order, screen-reader names, chart fallback, and touch interaction.

Severity summary: **Critical 0; High 0; Medium 4 (`UX-001`–`UX-004`); Low 2 (`UX-005`–`UX-006`).**

## 19. Page-Family Remediation Groups

### Group A — Data lists and tables

Transactions, Holdings, Candidates, Recommendations, Reports, Notifications, registry lists, and Backtests. Establish shared state wording, compare table behavior, and migrate only structurally equivalent tables.

### Group B — Analytical pages

Dashboard, Compare, Performance/Tax, Review, Backtests, Historical Holdings, and Snapshots. Preserve metrics, make incomplete/unavailable data explicit, standardize chart shells, and verify responsive sizing.

### Group C — Registry/editor pages

Screeners, Strategies, Artifacts, and detail/editor routes. Standardize title/context/action placement and form/validation state presentation while preserving domain-specific editors.

### Group D — Settings/forms

Profile, Portfolios, Portfolio/Account settings, Alert Policies, and notification settings. Use labelled controls, consistent submit/error states, and safe destructive confirmation.

### Group E — Knowledge/calendar

Knowledge Board, Tags, Wiki, Pattern Guide, and Calendar. Preserve editor-specific layouts; focus on mobile overflow, dialog semantics, empty states, and utility-rail compatibility.

## 20. Test Coverage Matrix

| Area | Existing evidence | What it proves | Remaining test need |
| --- | --- | --- | --- |
| Shell/page chrome | `tos-shell`, role-separated shell, app/source tests | Shell mounts and role branches; current utility rail integration. | Route-family title/breadcrumb/action contract tests. |
| Page History/Notes | `page-history.test.jsx`, `contextual-notes-shell.test.jsx` | MRU, focus/sheet behavior, utility coordination, Notes shell behavior. | Browser geometry and cross-page visual sweep. |
| Tables | `DataTable` consumers and page-specific tests such as transactions/holdings/review | Selected table workflows and source behavior. | Representative loading/empty/keyboard/mobile tests per family. |
| Controls | Feature tests plus source tests for segments/settings | Selected control behavior. | Shared semantic contract for tabs/segments/dropdowns. |
| Dashboard | `dashboardCache`, dashboard-related JS tests | Cache/preservation behavior and selected data workflows. | Visual hierarchy, all metric preservation, responsive charts. |
| Backtests/review/analytics | backtest, review, performance/compare tests | Domain workflows and API/UI behavior. | Loading/incomplete/benchmark/chart/accessibility matrix. |
| Knowledge/calendar/notifications | notification, knowledge, calendar-related tests | Selected CRUD and notification shell behavior. | Mobile editor/calendar/dialog focus and state vocabulary. |
| Accessibility | Focus tests for Page History/Notes and local aria assertions | Specific utility/dialog contracts. | Cross-route keyboard and screen-reader audit. |

Source-pattern tests are not treated as proof of visual or browser behavior.

## 21. Runtime Verification

The following remain browser/runtime checks rather than confirmed static failures:

- title/breadcrumb/action hierarchy at desktop, tablet, and mobile widths;
- table horizontal access, hidden-column detail access, sticky headers, and touch targets;
- chart resize, tooltip access, exact-value fallback, and missing-data display;
- editor/form keyboard order, soft keyboard behavior, and dialog stacking;
- route scroll restoration and scroll-to-top container targeting;
- utility rail, Notes, Page History, sidebar, toast, and page-local dialog stacking;
- reduced-motion behavior across page-local transitions;
- deployed bundle reachability and responsive behavior.

## 22. Remediation Options

### Option A — Page-by-page cleanup

Fix each route independently. This is easy to start but repeats decisions, makes consistency hard to measure, and risks creating more local variants.

### Option B — Shared primitives first, then incremental family migration

Define the smallest missing state/scroll/accessibility helpers, select representative routes, migrate by family, and add focused tests. This best balances consistency, reversibility, and preservation of domain-specific layouts.

### Option C — Broad frontend redesign

Normalize all page composition in one pass. This has the highest regression and non-regression risk, would overlap AUD-004/AUD-013, and is not justified by the static findings.

**Recommendation:** Option B.

## 23. Recommended Implementation Sequence

1. Build a page-family checklist from `PageChrome`, action, state, and accessibility requirements.
2. Confirm concrete state-semantic and accessibility examples before extracting anything.
3. Add a small shared state surface only if repeated implementations demonstrate real benefit; retain domain-specific messages.
4. Normalize one representative data-list page and one analytical page, including loading/empty/error and responsive/accessibility tests.
5. Audit equivalent selection controls and migrate only those with identical route/local semantics.
6. Add a focused `ScrollToTop` primitive for proven long-page families, not globally.
7. Migrate registry/editor and settings families incrementally.
8. Perform browser verification at desktop/tablet/mobile widths and reduced motion.
9. Update AUD-003 only with evidence from the completed batches. Keep AUD-004 and AUD-013 non-regression checks separate.

## 24. AUD-004 / AUD-013 Dependencies

Page-level changes must preserve the V6 non-regression inventory and the Dashboard preservation baseline. Do not remove or hide existing Dashboard metrics, execution/safety controls, navigation capability, or utility-rail access merely to simplify anatomy. Any relocation that could affect those contracts should be reviewed against AUD-004 and AUD-013 evidence before implementation.

## 25. Open Questions

1. Which page-local loading/empty/error patterns are sufficiently repeated to justify a shared `EmptyState` or skeleton primitive rather than a small documentation convention?
2. Which long-page families should receive scroll-to-top after measuring actual viewport and scroll-container behavior?
3. Which local button groups are route-backed tabs versus local segmented choices, especially in registry/editor surfaces?
4. Which chart and table accessibility fallbacks are required by the deployed browser support matrix?

## Batch 1 Implementation Outcome

Batch 1 implemented `UX-001` and the representative portion of `UX-002`.

- Added `components/DataState.jsx` as the single shared page-level presentation primitive for loading, empty, error, unavailable, and incomplete states. It preserves domain-owned copy and uses status/alert semantics appropriate to the variant.
- Applied the primitive to Candidates, Notification History, and Review Reports. These were selected because they represent a local discovery table, a simpler notification list, and a paginated review-report workflow with an existing empty-state action.
- Migrated the Candidates results table from local table markup to the existing `DataTableView`/`useDataTableController` path. Existing column content and actions remain intact; the existing responsive wrapper, sorting/controller behavior, and table state handling are reused.
- Preserved Review Reports pagination semantics and existing Generate action. Notification and Candidates retry/error paths remain explicit; operation-result toasts remain in place.
- Added focused `DataState` tests and retained/adjusted representative Candidates and Review Reports tests. No API, calculation, financial, or shell behavior changed.

Static outcome:

- `UX-001`: **IMPLEMENTED for the selected representative surfaces**, not a claim that every route has migrated.
- `UX-002`: **PARTIALLY_IMPLEMENTED**; the shared table path is proven on Candidates and existing consumers remain covered, but the rest of the data-list families still require incremental adoption.
- `AUD-003`: remains **PARTIALLY_IMPLEMENTED**. `UX-003` through `UX-006` were not changed.

Validation completed under Node 22:

- Focused Vitest: 3 files, 21 tests passed.
- Full Vitest: 16 files, 74 tests passed.
- Node/source tests: 157 passed.
- Production Vite build: passed; existing large-chunk warning remains.
- `git diff --check`: passed.

## Batch 2 Implementation Outcome

Batch 2 implemented the bounded `UX-003`/`UX-004` changes selected from the registry/editor, data/review, and analytical/market families.

- `PageChrome` remains the shell-level title and breadcrumb authority. Notification History’s `Notification Center` label and Market Depth’s `Market Breadth` label remain useful content context, but are now section-level `h2` headings rather than competing page-level `h1` headings.
- Market Depth’s local two-option Values control now reuses the shared `SegmentToggle`. Its `pct`/`count` state, API calls, date selection, chart behavior, and series controls are unchanged.
- Notification History’s five quick views remain its existing URL-backed query filter control. It was intentionally not converted to `SegmentToggle` because it exceeds the shared primitive’s 2–4 local-choice contract and its view state is represented in the query string.
- Registry list/detail pages already retain entity/editor headings and wrapped action groups; no redundant action or editor control was removed.
- No new action-row primitive was justified. Existing flex-wrap action rows were sufficient for this bounded batch.

Static outcome:

- `UX-003`: **IMPROVED / PARTIALLY_IMPLEMENTED**; representative heading hierarchy is clearer, while global action composition remains unfinished.
- `UX-004`: **IMPLEMENTED for the targeted equivalent Market Depth control**; Notification History’s five URL-backed views remain intentionally local/query-driven.
- `AUD-003`: remains **PARTIALLY_IMPLEMENTED**. UX-005 and UX-006 were not changed.

Validation completed under Node 22:

- Batch 2 source tests: passed.
- Full Vitest, Node/source tests, production build, and `git diff --check` were rerun after the changes.

## Batch 3 / Closure Assessment

Batch 3 completed the focused `UX-005` and `UX-006` work and performed the final static closure review. The scope remained deliberately narrow: no additional table migration, page redesign, domain/API change, or AUD-004/AUD-013/AUD-016 work was introduced.

### Long-page evidence

| Page | Likely long? | Existing return control | Scroll container | Batch 3 disposition |
| --- | --- | --- | --- | --- |
| Review Report detail | Yes; metric and methodology sections can exceed several viewports. | None found. | `.lido-shell > .lido-main`. | Added shared `ScrollToTop`. |
| Wiki page/editor | Yes; hierarchy, editor, preview and revision history are vertically dense. | None found. | `.lido-shell > .lido-main`. | Added shared `ScrollToTop`. |
| Backtest detail | Yes. | Existing page-local top/bottom `PageScrollFab` controls target the shell scroll container. | `.lido-shell > .lido-main`. | Retained as adequate feature-specific behavior. |
| Strategy/Screener editor detail | Potentially long. | No shared return control confirmed. | Shell main/page-local content. | No new control without stronger evidence; retain for runtime review. |
| Performance/Compare, Historical Holdings and registry/detail pages | Variable by data and state. | Mixed/local behavior. | Shell main or page-local content. | Runtime verification only; no broad rollout. |

`ScrollToTop` is a small shared control that appears after a meaningful scroll threshold, targets `.lido-shell > .lido-main`, uses an accessible button name, and switches to immediate scrolling under reduced motion. It is mounted only on the two newly selected long-page families; the main workspace is not resized.

### Targeted accessibility verification

The review covered Market Depth, Notification History, Candidates, Review Reports/detail, Backtest detail, Screener editor, Settings/forms, and Wiki/Knowledge interactions. Two concrete static defects were found and fixed:

- Candidates search and source filters now have programmatic accessible names.
- Wiki Markdown source now has an associated `label`/`id` pair; the preview heading is represented as a non-form label element.

No other concrete static defect was established in the selected surfaces. Browser screen-reader behavior, real focus order, touch behavior, and visual geometry remain runtime verification items rather than unproven defects.

### Final finding dispositions

| Finding | Final disposition | Evidence |
| --- | --- | --- |
| `UX-001` | **RESOLVED** for the representative surfaces; semantically correct local equivalents remain acceptable elsewhere. | `DataState` adoption and focused tests from Batch 1. |
| `UX-002` | **ACCEPTABLE_VARIATION / RUNTIME_VERIFICATION_REQUIRED**. | Shared `DataTable` adoption is proven where behavior is comparable; remaining lists are domain-specific or require browser overflow review. |
| `UX-003` | **ACCEPTABLE_VARIATION**. | `PageChrome` remains authoritative; meaningful entity/section headings and context-specific action placement were reviewed. |
| `UX-004` | **RESOLVED** for equivalent local controls. | Market Depth uses `SegmentToggle`; route-backed and five-choice Notification History views intentionally retain navigation/filter semantics. |
| `UX-005` | **RESOLVED**. | Review Report and Wiki use `ScrollToTop`; Backtest retains adequate page-local controls. |
| `UX-006` | **RESOLVED** statically. | Two concrete label defects were fixed with focused source assertions; no known static defect remains in the targeted review. |

### Closure decision

`AUD-003` is **IMPLEMENTED** as a static audit finding. Shared foundations exist, representative adoption is complete for the remediation batches, and remaining page differences were reviewed as acceptable/domain-specific or runtime-only. No known static state-semantics, page-anatomy, or targeted accessibility defect remains.

Remaining runtime verification covers responsive hierarchy, table overflow, chart behavior, keyboard order, touch interaction, actual screen-reader behavior, scroll thresholds, stacking, and deployed-bundle geometry. AUD-004, AUD-013, and AUD-016 were not changed.

Batch 3 validation includes the focused ScrollToTop and targeted accessibility tests, the full frontend Vitest suite, Node/source tests, production Vite build, and `git diff --check`. The existing production build large-chunk warning remains a separate non-blocking warning.
