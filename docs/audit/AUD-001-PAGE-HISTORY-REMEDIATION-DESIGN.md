# AUD-001 Page History Remediation Design

## 1. Finding Recap

`AUD-001` is a confirmed V6 E4 implementation gap. The accepted Investor UX
contract requires Page Visit History in a persistent desktop right-edge rail
and an explicit mobile History action. The Phase 2 audit found no history
component, state, route observer, shell mount or meaningful automated test.

This design restores that contract without treating the current absence as a
reason to weaken it. It also creates a small right-utility foundation that can
support the later `AUD-002` Notes placement work without coupling Page History
to the Notes data API.

Primary contract evidence:

- `docs/archive/specs/V6-E4-Investor-UX-Client-Evolution.md`, sections 3.1,
  3.5, 8, 9, 10 and 17;
- `docs/current/frontend-and-navigation.md`, sections 2, 5, 6, 7, 13 and 17;
- `docs/audit/V1-V7-IMPLEMENTATION-AUDIT.md`, `V6-REQ-007` through
  `V6-REQ-010` and `AUD-001` through `AUD-003`.

## 2. Current Shell Architecture

| Zone | Current component | Mounted where | Desktop behavior | Mobile/constrained behavior | Notes |
| --- | --- | --- | --- | --- | --- |
| Top Header | `AppHeader` | `AuthenticatedShell` in `App.jsx` | StoX identity, portfolio switcher for Investors, execution controls, notifications, Help and profile menu | Sidebar toggle remains available; header utilities do not currently have an overflow surface | No Global Search or History action. Header can hide on scroll. |
| Left Navigation | `Sidebar` inside `SidebarProvider` | Authenticated non-documentation shell | Participates in the flex layout at `min-width: 1200px`; 260px expanded or 64px collapsed | Fixed focus-trapped drawer below 1200px | Collapse and group preferences use local storage; scroll position uses session storage. |
| Main Workspace | `.lido-main`, `PageChrome`, `AppRoutes` or `AdminAppRoutes` | Authenticated shell | The sole flexible shell column; independently scrollable | Same scroll container after sidebar becomes overlay | `.lido-shell > .lido-main` is not currently resized by Notes. |
| Right Utility Rail / Pane | `ContextualNotesPane` only | Authenticated non-documentation shell, Investor-only render | Fixed bottom-right Notes trigger opens a fixed 360px right overlay | The same fixed button/pane geometry remains; no sheet/drawer transformation | This is a partial Notes implementation, not the V6 shared rail. |
| Footer | No mounted shell component | None | Legacy footer CSS exists but no authenticated-shell mount was found | N/A | Low-priority zone remains unimplemented/unverified separately. |

Relevant anchors are `app/resources/js/src/App.jsx` (`AuthenticatedShell`),
`components/AppHeader.jsx`, `components/sidebar/Sidebar.jsx`,
`components/navigation/PageChrome.jsx`, `components/ContextualNotesPane.jsx`
and `styles/lido-app.css`. The shell is a flex layout with `.lido-main` as the
scroll container; the current Notes pane is fixed at a `top` of `4.75rem`,
with `z-index` 1045. The header is `z-index` 1000, while mobile sidebar
backdrop/drawer use 1040/1050.

## 3. Accepted Page History Contract

### Desktop

The V6 E4 contract requires all of the following:

- a persistent narrow vertical rail at the right edge;
- the last 12 page visits, most-recent first;
- collapse of consecutive duplicate visits only;
- a visibly active current page;
- link semantics and pointer behavior for entries;
- indicators/bars that extend leftward, with thicker rounded ends;
- active StoX accent `#1e90ff` and a quiet inactive appearance;
- a label revealed on hover and keyboard focus;
- long-label truncation and a full-label tooltip/focus treatment; and
- keyboard accessibility.

The rail and any major contextual pane must overlay the workspace rather than
permanently reduce `.lido-main` width. Normally only one major contextual pane
is open at a time.

### Mobile

The desktop rail must not be forced onto narrow layouts. An explicit History
icon/button opens the same logical history in a menu, drawer or sheet. The
archive does not select one of those three mobile presentation forms, but it
does require that capability and touch access remain available.

### Contract interpretation

E4 specifies retention and display behavior but does not explicitly state
whether history survives refresh, browser restart, logout, portfolio switch or
account switch. Those are implementation decisions and are recorded in section
6 rather than inferred from the acceptance criteria.

## 4. Visit Identity And Label Model

### Recommended visit identity

Use a stable client-side descriptor:

```text
visitKey = normalized pathname
destination = normalized pathname
label = route metadata label or a safe detail-route label
```

- Normalize leading/trailing slashes; use `/` for Dashboard.
- Ignore hash fragments and query strings by default. Filters, sort order,
  pagination and ephemeral view parameters are local page state, not separate
  page visits.
- Do not derive identity from React component state or API payloads.
- Allow an explicit future metadata override only where a route genuinely has
  peer route-level views distinguished by a query parameter. No such override
  is needed for the initial implementation.

`App.jsx` is the authoritative rendered-route list. The existing navigation
catalog plus `findActiveNavItem()` and `getPageTitle()` in
`utils/navigationTree.js` are the first label source for catalogued routes.
The future history helper should centralize descriptor resolution rather than
copy PageChrome logic into every page.

### Labels

For normal routes, use the navigation item title, such as `Holdings`,
`Recommendations` or `Backtests`. For recognized detail patterns, provide
safe generic labels such as `Holding Prices`, `Screener`, `Strategy Registry
Detail`, `Backtest Detail`, `Review Report` and `Wiki Page`. Do not use a raw
numeric ID, UUID, ticker-derived identifier or fetched private entity name as
the sole visible label in the first version.

This avoids brittle title-casing and avoids storing sensitive entity payloads.
It also leaves a later enhancement free to add explicitly supplied entity
labels after a normal authorized page load.

## 5. History Semantics

The state is an ordered array, newest first, with at most 12 records.

1. On every eligible location change, resolve a visit descriptor.
2. If its `visitKey` matches the current first record, leave the array
   unchanged. This is the required consecutive-duplicate collapse.
3. Otherwise prepend the new descriptor, retaining older occurrences even if
   they have the same key. E4 does not authorize global de-duplication.
4. Truncate after the twelfth record.
5. Derive active state from the current normalized pathname, not from a
   persisted `active` flag. Browser back/forward, direct loads and router
   navigation therefore update it naturally.

The route observer belongs once in the Investor authenticated shell, using
React Router's `useLocation()`. That covers direct deep links, `Link` clicks,
programmatic `navigate()`, redirects after their final location settles, and
browser history without page-level instrumentation.

Redirect-only aliases must not leave a transient entry. The initial descriptor
resolver should exclude `/evaluations` and `/settings`, which immediately
redirect in `AppRoutes`, rather than attempting broad router-event timing
workarounds.

## 6. Persistence And User Isolation

### Recommended decision

Persist the minimal history in `sessionStorage`, not `localStorage`:

```text
stox-page-history:v1:investor:<user-id>
```

The entries contain only `visitKey`, `destination` and `label`; no profile ID,
API response, auth material, financial state or entity payload is persisted.

This choice preserves history across a refresh in the current browser tab,
but intentionally discards it when the browser session ends. It aligns the
feature with short-lived navigation context and with the existing sidebar
scroll/active-portfolio session-storage conventions. V6 E4 permits stable
preferences in local storage but does not require browser-restart persistence
for Page History.

The user ID in the key prevents User B from inheriting User A's history when
the same browser is reused. The state is user-scoped rather than
portfolio-scoped: it is navigation context, and normal active-profile,
authorization and data loading rules still decide whether a destination is
usable after a portfolio switch. The history control never stores or restores
old page data.

### Storage safety

- Read malformed, incompatible or oversized JSON as an empty list.
- Validate array shape and route strings before using it.
- Discard unknown schema versions rather than attempting speculative
  migration.
- Catch storage access failures so private-mode/browser-policy errors leave
  in-memory history usable.
- Re-read when the authenticated user ID changes; never reuse a prior state
  under a new key.

## 7. Shared Right Utility Rail Design

Introduce a small shell-level `RightUtilityRail` foundation, not a second
floating-control system. It owns presentation and open-utility coordination;
individual utilities own their data and content.

```text
RightUtilityRail
  desktop: compact edge rail
    PageHistoryRail
    reserved utility slots
  constrained/mobile: explicit utility action surface
    History action -> History sheet
    future Notes action -> Notes sheet/pane
```

Responsibilities:

- render a fixed, edge-aligned rail only at desktop layout width;
- render an explicit touch-friendly History action below that breakpoint;
- coordinate a single `activeUtility` for future large panes/sheets;
- own backdrop, Escape and focus-return behavior for its own mobile surface;
- expose a small utility registration/slot API rather than a general plugin
  framework; and
- never change the flex sizing or width of `.lido-main`.

Page History itself does not need a large desktop pane: its bars and revealed
labels are the rail. On mobile, it is the active utility and opens a modal
bottom sheet. The Notes data layer is deliberately outside this component.

## 8. Contextual Notes Compatibility

`ContextualNotesPane` currently:

- derives `contextKey` from pathname;
- uses the active portfolio and `/api/contextual-notes`;
- loads only when opened;
- persists notes through the existing API; and
- renders an Investor-only fixed trigger and 360px fixed pane.

AUD-001 must not alter those persistence or API semantics. The initial rail
foundation should reserve a `notes` utility slot and document the adapter
boundary, but the complete migration of the Notes trigger/pane, reduced-motion
behavior, route/entity context and mobile sheet belongs to `AUD-002`.

For the AUD-001 implementation, retain the existing Notes control and ensure
the History mobile action does not overlap it. The preferred temporary layout
is a shared shell-controlled mobile utility-action stack with separate
positions for History and the legacy Notes trigger. The later AUD-002 change
can move the Notes trigger into `RightUtilityRail` and supply its existing
pane content through a controlled `open` / `onOpenChange` adapter.

This avoids rewriting Notes merely to add history, while avoiding a permanent
second rail architecture.

## 9. Desktop Interaction

At the same desktop breakpoint that makes the sidebar participate in layout
(`min-width: 1200px`), render a fixed rail approximately 48px wide, aligned to
the viewport right edge and below the header. It should use its own vertical
stacking context above workspace content and below current modal/toast layers;
the exact CSS values must be verified against the current header, Notes pane,
sidebar backdrop and scroll-to-top controls.

Each entry is a React Router `Link` with a predictable 44px-or-greater hit
target. Visually it renders as a left-extending horizontal bar; it does not
consume layout width. The bar is rounded and thicker than a hairline. The
current location uses the accepted `#1e90ff` accent and `aria-current="page"`.
Inactive bars use a quiet neutral color. On pointer hover and keyboard focus,
show a left-opening label bubble with a single-line ellipsis; its full string
is available from the link's accessible name and `title` attribute.

Opening any future utility pane must overlay above `.lido-main`; it must not
change its `flex`, padding, max width or scroll-container dimensions.

## 10. Mobile Interaction

Below the desktop rail breakpoint, hide the persistent bars. Render a labelled
History icon button in the rail's mobile action surface, with a minimum 44px
target and visible text/tooltip equivalent for touch and assistive technology.

Selecting it opens a near-full-width bottom sheet with:

- a labelled heading and close button;
- the same MRU history entries as desktop;
- link rows with active state and full labels;
- a backdrop; and
- a scrollable content area constrained to the visual viewport.

Escape closes the sheet, focus moves to the heading/close button when it
opens, and focus returns to the invoking History action when it closes. A
history-link activation navigates with React Router and closes the sheet.
The sheet must not rely on hover, and it must respect `prefers-reduced-motion`
by disabling slide/fade transitions.

The exact visual placement of the mobile History action is an implementation
detail to validate beside the existing fixed Notes button; it must have no
overlap with that button, backtest floating controls or any future footer.

## 11. Accessibility

- Use links for history destinations, buttons only for opening/closing the
  mobile surface.
- Give the desktop rail an `aria-label`, such as `Recent pages`.
- Give each link a full accessible name; retain `aria-current="page"` for the
  active route.
- Keep label reveal available on `:focus-visible`, not only `:hover`.
- Do not use color as the only active marker; include shape/weight or an
  accessible current-page cue.
- The mobile sheet uses `role="dialog"`, `aria-modal="true"`, focus trap,
  Escape close and focus restoration, following the existing mobile Sidebar
  pattern.
- Respect reduced motion for rail/sheet transitions and avoid animated layout
  shifts.

## 12. Route Inclusion / Exclusion

Record visits only when all conditions hold:

- user is authenticated;
- user is an Investor, not an Admin;
- the route renders within the non-documentation Investor shell; and
- the normalized pathname has an approved descriptor.

Include Investor workspace routes, settings/profile/notification routes and
recognized detail routes. These are ordinary destinations and remain subject
to normal route/API authorization when selected.

Exclude:

- guest login, invite, reset and other unauthenticated paths;
- public wiki share routes;
- documentation/help routes, which use the documentation shell;
- all Admin shell routes, because Page History originated in the Investor UX
  contract and must not be silently broadened;
- redirect-only aliases `/evaluations` and `/settings`;
- callback/status-only or not-found routes; and
- hash/modal-only state, which is not a page visit.

The descriptor resolver should be an explicit allowlist/pattern map over the
Investor `AppRoutes`, with a safe fallback only for known internal details.
That makes new routes a conscious Page History review point instead of
recording arbitrary paths.

## 13. Admin / Investor Scope

Page Visit History is Investor-shell-only in this remediation. The V6 source
is explicitly an Investor UX epic, while the current Admin shell deliberately
has a different route set and has no Investor portfolio ownership. Do not add
history to `AdminAppRoutes` as incidental shared-shell behavior.

Shared account pages accessed by an Investor remain eligible. An Admin's
shared profile/notification routes do not gain Page History in this patch.

## 14. Implementation Options

| Option | Design | Advantages | Risks / limitations |
| --- | --- | --- | --- |
| A. Independent rail | Add `PageHistoryRail` beside the current fixed Notes button | Smallest textual diff | Creates two unrelated right-side control systems and makes AUD-002 harder. Mobile collision/focus coordination remains unresolved. |
| B. Thin reusable rail foundation | Add `RightUtilityRail`, Page History state/desktop rail/mobile sheet, and a reserved Notes integration boundary | Satisfies AUD-001, preserves Notes API, gives AUD-002 a coherent shell home, limits churn | Requires a small shell component and temporary coexistence handling for the legacy Notes trigger. |
| C. Full AUD-001 + AUD-002 shell rewrite | Implement history and fully replace Notes with coordinated rail/panes/sheets now | Most complete five-zone outcome | Broadens scope, risks Notes regression, and obscures the independent history acceptance work. |

## 15. Recommended Design

Choose **Option B**.

Implement a deliberately thin `RightUtilityRail` at the authenticated
Investor shell level. It observes no business data itself. A `usePageVisitHistory`
hook records route descriptors and supplies the desktop rail and mobile sheet.
The component reserves an explicit Notes utility integration boundary but does
not change Notes persistence, API calls or full pane behavior in AUD-001.

This is the smallest design that meets the accepted Page History contract
without institutionalizing a competing floating-control pattern. It keeps the
next AUD-002 change localized to the utility presentation adapter rather than
requiring a shell replacement.

## 16. State/Data Model

```js
// sessionStorage schema: stox-page-history:v1:investor:<user-id>
{
  version: 1,
  visits: [
    {
      visitKey: '/backtests/42',
      destination: '/backtests/42',
      label: 'Backtest Detail'
    }
  ]
}
```

In-memory state derives `isActive` from `location.pathname`; it is never
persisted. `recordVisit(descriptor)` applies only the consecutive-duplicate
rule and maximum 12 cap. The storage adapter is pure and defensive, so the
history reducer/hook can be tested without rendering the entire app.

Suggested implementation anchors:

- `navigation/pageHistory.js` or `utils/pageVisitHistory.js` for descriptor,
  normalization and storage helpers;
- `hooks/usePageVisitHistory.js` for shell-level route observation;
- `components/navigation/RightUtilityRail.jsx` and
  `components/navigation/PageHistoryRail.jsx` for presentation;
- `App.jsx` for the sole Investor-shell mount; and
- `styles/lido-app.css` for new, isolated utility classes only.

## 17. Test Plan

### Pure state and descriptor tests

- empty state, first visit and MRU prepend;
- consecutive duplicate is collapsed;
- a non-consecutive duplicate is retained;
- thirteenth visit removes only the oldest record;
- path normalization, query/hash exclusion and redirect exclusion;
- catalog/static label and safe dynamic-detail label resolution;
- malformed, wrong-version and oversized stored state resets safely; and
- user-keyed storage prevents User B from reading User A's history.

### Component/router tests

- authenticated Investor shell mounts the rail; Admin and documentation shells
  do not;
- current location has `aria-current="page"` and the active visual class;
- a history link performs client-side navigation;
- browser back/forward changes active state through `useLocation()`;
- desktop link has full accessible label and truncation/title treatment;
- constrained layout hides desktop rail and exposes the History action;
- mobile action opens/closes dialog, supports Escape and returns focus;
- reduced-motion media query disables sheet transition; and
- existing Notes trigger remains reachable with no overlapping active target.

### Regression/browser verification

Use the existing Node test style for pure helpers and shell-source coverage;
use the existing Vitest/Testing Library setup for interactive router/focus
behavior. Add a Playwright desktop and mobile visual check for rail geometry,
scrollbar/overlay interaction and actual touch layout. No test should infer
completion merely from a component filename.

## 18. Regression Risks

- fixed rail or mobile action overlaps the scrollbar, header, legacy Notes
  button, backtest floating controls, footer or modal layers;
- rail changes `.lido-main` width, padding, flex basis or scroll behavior;
- route observer records a redirect intermediate, records repeatedly, or
  incorrectly globally de-duplicates visits;
- browser back/forward leaves a stale active marker;
- storage state leaks across user switch, contains inaccessible path data or
  throws in restricted storage contexts;
- a mobile sheet fails to trap/restore focus or conflicts with sidebar drawer;
- label resolution exposes raw IDs or breaks on dynamic routes; and
- a future Notes migration assumes history's mobile sheet is already a full
  contextual-pane implementation.

## 19. Implementation Sequence

1. Add pure route descriptor, normalizer and versioned session-storage helper
   tests.
2. Add `usePageVisitHistory` with the one shell-level `useLocation()` observer.
3. Add `PageHistoryRail` desktop rendering and link/accessibility tests.
4. Add the thin `RightUtilityRail` shell foundation and mount it only for the
   authenticated non-documentation Investor shell.
5. Add the constrained/mobile History action and bottom sheet with focus and
   reduced-motion behavior.
6. Position the temporary mobile action surface around the legacy Notes
   trigger; confirm notes remains reachable.
7. Run desktop/mobile visual checks for workspace width, stacking and scrolling.
8. Update `AUD-001` evidence and static verdict only after tests prove the
   acceptance criteria; leave `AUD-002`, `AUD-003` and `AUD-016` untouched.

## 20. Open Questions

1. Should an Investor's visit history be session-scoped as recommended, or is
   browser-restart persistence a product requirement that needs explicit
   product-owner confirmation?
2. Should recognized dynamic routes later display authorized entity names, or
   are stable generic detail labels sufficient for the initial acceptance
   implementation?
3. At constrained tablet widths, should the History action live in a new
   utility-action stack or the header's future overflow surface? The current
   header has no overflow system, so the initial stack is lower risk.
4. Should the later AUD-002 work move Notes into the same bottom-sheet
   primitive, or retain a side drawer on larger tablets? E4 permits a
   drawer/sheet but does not settle that breakpoint-level choice.

## 21. Implementation Outcome

AUD-001 was implemented without changing the Notes data layer or the broader
page-anatomy and search work. The Investor authenticated shell now mounts a
thin `RightUtilityRail`; its `PageHistoryRail` provides the desktop edge
controls and its mobile History action opens `PageHistorySheet`. The
`usePageVisitHistory` hook observes React Router location changes and persists
only validated route descriptors under the user-keyed session-storage key
`stox-page-history:v1:investor:<user-id>`.

Static tests cover normalization, approved-route descriptors, consecutive and
non-consecutive MRU behavior, the twelve-entry limit, storage validation and
user isolation, active `aria-current` links, client-side navigation, mobile
dialog Escape/focus containment/restoration, and the 44px interaction-target
CSS contract. The existing Contextual Notes control remains mounted
independently. Browser verification remains required for exact rail geometry,
responsive/touch rendering, stacking and deployed-bundle reachability.
