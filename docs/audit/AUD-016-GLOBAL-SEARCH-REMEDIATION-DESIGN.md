# AUD-016 - Global Search Remediation Design

## 1. Finding Recap

`AUD-016` is a confirmed missing V6 E4 shell capability. The accepted contract calls for a compact Global Search in the top header that expands on demand without disruptive page reflow and becomes a full-width search surface or sheet on mobile. The current `AppHeader` has no search control, search state, result handler, API integration, or focused test.

This document is an audit and design record only. It does not add product code, routes, APIs, tests, or current-document changes.

Current finding status:

- Verdict: `NOT_IMPLEMENTED`
- Severity: `Medium`
- Confidence: `High`
- Recommended implementation scope: bounded Investor navigation and stock search, using existing metadata/API foundations.

## 2. Accepted Global Search Contract

The V6 E4 source and current frontend contract establish these explicit requirements:

| Requirement | Accepted behavior |
| --- | --- |
| Placement | Global Search belongs in the top header. |
| Default state | A compact Search icon/control is visible without permanently consuming header space. |
| Desktop expansion | Click or focus expands the control into a search field. Expansion must not cause disruptive page reflow or change header height. |
| Mobile/constrained state | The Search control opens a full-width search surface or sheet. Desktop hover behavior must not be required. |
| Completion | Escape, close, or completion may collapse/close the surface as appropriate. |
| Interaction | The feature must be keyboard and focus accessible, with visible focus and a usable touch path. |
| Navigation | Selecting a result uses normal application routing and authorization. Search is not an access grant. |
| Shell safety | Secondary utilities may compact at constrained widths, but safety-critical state and actions remain reachable. |

The source does **not** define a mandatory federated backend, a global keyboard shortcut, search-history persistence, semantic/vector ranking, exact entity categories, or a specific result count. Those are design choices, not current requirements.

## 3. Current Header Architecture

`AppHeader.jsx` currently renders one fixed-height `lido-header-bar` with a brand/sidebar side and a non-shrinking action group:

| Header region | Current component | Width behavior | Responsive behavior | Priority |
| --- | --- | --- | --- | --- |
| Brand and navigation toggle | `SidebarToggle`, StoX brand `Link` | Brand is non-transient; title is `nowrap` and shrinks only through surrounding constraints. | Brand typography reduces below 576px. | Highest identity/navigation. |
| Portfolio context | `PortfolioSwitcher` | Authenticated Investor only; currently in the action group. | Must remain usable for portfolio-scoped work. | High. |
| Execution safety | `ExecutionSafetyControls` | Visible action/status group; action group is `flex-shrink: 0`. | Must not be pushed behind search. | Highest safety priority. |
| Notifications | `NotificationBell` | Compact authenticated action. | May remain compact. | High user attention. |
| Help | `HeaderHelpButton` | Compact global action. | May move into compact/overflow presentation only if contextual help remains reachable. | Medium/high. |
| Account | `ProfileMenu` | Compact authenticated action with menu. | Must remain reachable. | High. |

The current CSS uses a flex header with `justify-content: space-between`, a non-shrinking `.lido-header-actions`, and no search slot or overlay. The implementation should therefore avoid inserting a permanently wide flex child between brand and actions. Search must not alter `.lido-main` or the existing shell scroll behavior.

## 4. Searchable Domain Inventory

| Category | Existing lookup/search API or metadata | Existing frontend primitive | Ownership scope | Target route | Include in MVP? |
| --- | --- | --- | --- | --- | --- |
| Investor pages/actions | `NAVIGATION_CATALOG`, `buildSidebarNavigation`, `canAccessNavItem`, `findActiveNavItem`; route constants in `routes.js` | Sidebar/navigation metadata | Role and permission visibility | Existing route | Yes |
| Stocks/security master | Authenticated `GET /stocks/search`, throttled and active-universe scoped | `StockAutocomplete` with 2-character debounce and result buttons | Global active security master read; server remains authoritative | Stock prices/explorer/watchlist context, using existing route helper | Yes |
| Holdings | Existing `/holdings` response is portfolio-scoped but no dedicated global lookup contract was found | Page-local table/search | Active profile | `/holdings` or stock detail | No, defer until a bounded lookup is defined |
| Watchlist entries | Watchlist and item APIs exist, but no global scoped lookup endpoint was found | Watchlist-local search/autocomplete | Active profile | Watchlist | No, defer |
| Strategies | Registry APIs are profile/artifact scoped and often contain lifecycle-sensitive data | Registry-local filters | Active profile or system/granted artifact | Strategy registry/detail | No, defer |
| Screeners | Registry APIs and editor routes exist, with private/shared/system rules | Registry-local filters | Active profile or explicit sharing | Screener registry/detail | No, defer |
| Trading artifacts | Artifact Library APIs have ownership, grants, binding and archive semantics | Library-local search/list | Active profile/system/grants | Artifact Library/detail | No, defer |
| Knowledge/wiki | `GET /knowledge-board/search` already searches the active profile's notes and wiki pages | Knowledge Board debounced search | Active profile | Knowledge Board/wiki page | No for MVP; a later addition can reuse the scoped endpoint |
| Review reports/backtests | Detail APIs exist but no dedicated global search endpoint was found | Page-local lists/filters | Active profile | Existing detail route | No, defer |
| Portfolio names | Portfolio APIs exist, but no separate global search contract was found | Portfolio switcher/list | User-owned profiles | Portfolio/settings route | No, defer |
| Admin objects | Admin routes and APIs exist | Admin-only pages/searches | Admin role/global resources | Admin routes | No in the Investor MVP |

The exclusion decisions are deliberate. Search must not fetch broad private datasets and filter them in the browser, and it must not turn route presence into proof that an entity is accessible.

## 5. Existing Search Infrastructure

The strongest reusable foundations are:

1. **Navigation catalog:** `config/navigation.js` is the existing route/page vocabulary. `navigation/permissions.js` and `navigationTree.js` already filter items using role/permission context. Global Search should consume this source rather than creating a second route-label registry.
2. **StockAutocomplete:** `components/StockAutocomplete.jsx` already debounces input, requires two characters, calls `/stocks/search`, limits results, handles loading, and closes on outside interaction. Its result styling and API behavior are useful references, but the global result presentation needs a separate shell-level wrapper because the header search has multiple categories.
3. **Knowledge search:** `KnowledgeBoardSearchController` searches only the active profile's non-archived notes and profile-owned wiki pages. It is a safe future integration point, but adding it to the first header implementation would introduce another category, result semantics, and page-context decision.
4. **Existing route helpers:** `ROUTES`, navigation metadata, and React Router links provide normal navigation and preserve authorization boundaries.

No current global search component, search context, result aggregator, or header test exists.

## 6. MVP Scope Options

### Option A - Navigation command search

Search visible Investor navigation pages/actions only.

Pros: smallest implementation, instant results, no API or disclosure risk, complete role filtering through existing metadata.

Cons: users cannot jump directly to a stock or security record.

### Option B - Navigation plus existing scoped stock search

Search visible Investor pages/actions locally and stocks through the existing `/stocks/search` endpoint.

Pros: remains bounded, reuses an existing throttled API and autocomplete behavior, provides high-value market lookup, avoids a new backend authorization surface.

Cons: result groups have two request paths and stock result destinations need a small, explicit route policy.

### Option C - New federated `/global-search` endpoint

Add one backend endpoint that aggregates pages, stocks, holdings, strategies, artifacts, knowledge and reports.

Pros: one request and centralized ranking.

Cons: largest scope, new authorization and information-disclosure surface, more complex partial-failure behavior, and no evidence that the current product contract requires it.

## 7. Recommended Search Scope

Recommend **Option B** for the first implementation:

- local search over role-visible Investor navigation pages and approved page actions;
- existing scoped stock search for active security-master results;
- no new backend endpoint;
- no private portfolio/entity category until a dedicated scoped lookup contract exists;
- no Admin-only results in the Investor shell.

This meets the explicit shell contract while keeping authorization straightforward. A later knowledge integration may call `/knowledge-board/search` only with the active profile and only after result labels, route behavior, and privacy expectations are explicitly accepted.

The initial result groups should be `Pages` and `Stocks`. Page results use route metadata labels and group context. Stock results use symbol, company name, exchange, and an approved destination such as the existing stock-price/explorer flow; raw database IDs are not displayed as labels.

## 8. Desktop Interaction

Recommended model:

`compact Search button -> expanded anchored search field -> result overlay`

- Render a compact, labelled Search button in the Investor header.
- On click or focus, expand into a bounded field without changing header height.
- Position the expanded field and result list as an overlay anchored to the header rather than adding a wide permanent flex item that pushes safety controls.
- Use a conservative desktop width, approximately `18rem` to `32rem` subject to the available viewport and the safety-control reserve. Exact geometry remains a browser verification item.
- Keep the safety group, portfolio switcher, notification, Help, and profile controls reachable. At constrained desktop widths, the field may use the available middle space or transition to the mobile surface rather than overlap those controls.
- Escape closes the result surface and returns focus to the Search button. Click-away closes it. Selecting a result navigates with React Router and closes the surface.
- Search expansion must not resize `.lido-main`, change shell scroll behavior, or create a second right-side utility surface.

The result overlay should be visually associated with the header but remain below global safety/critical feedback layers and above ordinary page content. It must not obscure the right utility rail's Page History/Notes behavior in a way that prevents interaction after the search closes.

## 9. Constrained / Mobile Interaction

Use the explicit V6 mobile form: the Search button opens a full-width search surface or sheet below/over the header. Do not force the desktop expanding field into the narrow action row.

Recommended behavior:

- Search opens a labelled modal surface with a full-width search input and grouped results.
- The surface uses `role="dialog"` and `aria-modal="true"` only when it actually blocks the page behind it.
- Focus enters the input on open; Escape and the close button dismiss it; focus returns to the Search trigger.
- The surface has a scrollable result region, 44px-or-larger touch targets, and no hover-only actions.
- Selecting a result navigates with React Router and closes the surface.
- Opening Search closes or prevents simultaneous full-screen History/Notes sheets so competing modal surfaces are not left active. The underlying Page History/Notes state and history entries remain unchanged.
- The header keeps safety controls and the sidebar action reachable; Search does not permanently replace them.

Whether the mobile surface is implemented as a bottom sheet or a full-width panel is a visual implementation choice. The accepted requirement is the full-width, non-hover-dependent search surface; real viewport, soft-keyboard, stacking, and touch behavior require runtime verification.

## 10. Result Model

Use a small normalized result shape at the presentation boundary:

```js
{
  kind: 'page' | 'stock',
  label: 'Holdings' | 'INFY',
  secondary: 'Portfolio' | 'Infosys Limited · NSE',
  destination: '/holdings' | '/holdings/stock-id/prices',
  sourceId: 'navigation-id-or-stock-id'
}
```

Page results should carry a route and navigation group from the existing catalog. Stock results should carry only the fields needed for display and navigation. Do not persist result objects, query history, portfolio data, or fetched entity payloads in browser storage.

The result list should group by category, cap each group to a small deterministic count, and provide an explicit no-result message. Partial stock lookup failure may leave page results usable; it must not fabricate a stock result.

## 11. Ranking and Query Semantics

For local page search:

1. trim whitespace;
2. case-fold for matching;
3. rank exact label first;
4. then prefix label/alias;
5. then word-prefix;
6. then substring;
7. break ties by existing navigation order.

Use existing titles, groups, and any already-defined route aliases. Do not create a parallel naming registry or semantic/vector/LLM ranking.

For stocks, preserve the existing API contract: no request for an empty query, minimum two characters, debounce, bounded result count, provider-independent server ordering, and stale-response suppression if a new query supersedes an older one. Query-only changes are ephemeral; no search history persistence is required.

## 12. Authorization / Information Disclosure

Global Search must obey the same boundaries as navigation and APIs:

- Investor page results come from `buildSidebarNavigation`/`canAccessNavItem` with the authenticated user's role context. Admin-only entries are excluded from the Investor result set.
- Stock results use the existing authenticated `/stocks/search` route and server-side active-universe query. The browser must not construct results from an unrestricted stock dump.
- Future knowledge, holding, strategy, screener, artifact, report, and portfolio results must use their existing active-profile/scoped APIs. A valid search query never grants access to a foreign object.
- Search result selection still passes through normal React Router guards and backend ownership checks.
- Raw IDs, private excerpts, private wiki/search results, and hidden action metadata are not shown unless the existing scope explicitly permits them.
- Admin can receive an intentionally separate search policy later; Admin role is not an automatic license to search Investor-owned resources.

The client-side filtered navigation catalog is a presentation aid, not an authorization mechanism. Backend/API authorization remains authoritative.

## 13. Keyboard and Accessibility

The desktop control should be a real button with an accessible name such as `Open global search`, and the expanded input should have a persistent label such as `Search StoX`.

Recommended interaction semantics:

- input uses `type="search"` and `role="combobox"` only if the implementation provides matching listbox behavior;
- results use a labelled list/listbox with stable option names and visible focus;
- ArrowDown/ArrowUp move the active result; Enter selects it; Escape closes and restores focus;
- a status region announces loading, no results, and lookup failure without stealing focus;
- result links/buttons remain keyboard reachable and expose category/context in their accessible name where needed;
- click-away and close controls have names; hover is never the only label path;
- mobile dialog focus is contained while open and returns to the trigger on close;
- reduced motion removes search-surface slide/fade transitions.

Do not claim a combobox contract if the first implementation uses ordinary labelled input plus links. Native, simple semantics are preferable to incomplete ARIA.

## 14. Header / Utility Coordination

Search belongs to `AppHeader`, not `RightUtilityRail`. It should be mounted only in the authenticated Investor header for the initial scope unless the product explicitly expands the contract.

Coordination rules:

- Search does not record a Page Visit History entry merely by opening or closing.
- Selecting a result records the normal destination through existing route observation.
- Search overlays must not cover emergency/safety controls during normal use.
- Search and mobile History/Notes sheets are mutually exclusive when both would be modal.
- Search closes on successful navigation; Notes route-context behavior remains unchanged.
- Documentation/public shells do not receive Investor Global Search unless separately specified.

## 15. Data Architecture Options

| Option | Boundary | Assessment |
| --- | --- | --- |
| Client-only route catalog | `NAVIGATION_CATALOG` plus role filtering | Required baseline; safe and immediate. |
| Existing scoped APIs | Route catalog plus `/stocks/search`; later optionally `/knowledge-board/search` | Recommended first implementation; no new backend surface. |
| New aggregator endpoint | New controller/service/authorization contract | Not justified by current acceptance; defer. |

The recommended architecture keeps page matching local and makes a small debounced stock request only after the query reaches the existing minimum. It should cancel or ignore stale responses and should not block route-result rendering on stock latency.

## 16. Performance

- Do not preload all stocks, portfolio records, artifacts, or wiki content.
- Debounce stock lookup using the existing 300ms pattern or a shared equivalent.
- Use the existing server limit and throttle; do not increase result volume for the header.
- Ignore stale responses when the query changes or the surface closes.
- Avoid work on empty query and avoid fetching on focus alone.
- Keep page catalog matching synchronous and small enough not to slow initial header rendering.
- Keep search state ephemeral; no persistent history or sensitive browser cache is required.

## 17. Empty / Error States

| State | Expected behavior |
| --- | --- |
| Empty query | Show a restrained prompt such as “Search pages or stocks”; do not issue a request. Recent search is not required. |
| Local page matches only | Show Pages immediately while stock lookup is pending or unavailable. |
| Loading stocks | Announce loading in a status region and keep page matches usable. |
| No matches | Show an explicit “No matches” message with the query context. |
| Stock lookup failure | Keep page results; show a bounded non-blocking stock lookup message. Do not expose provider internals. |
| Unauthorized/stale destination | Close and navigate normally; route/API authorization decides the outcome. Search does not retry by broadening scope. |

## 18. Test Plan

### Header and role scope

- Investor header renders exactly one Search trigger.
- Admin/documentation/public shells do not receive the Investor search unless an explicit shared contract is later approved.
- Search does not remove or reorder safety, notification, Help, profile, portfolio, or sidebar controls in source/component behavior.

### Route search

- Visible navigation labels and groups match deterministically.
- Admin-only navigation entries are excluded for Investor context.
- Exact/prefix/substring ranking is stable.
- Selecting a page result uses React Router and closes the surface.

### Stock search

- No request occurs below the two-character threshold or for an empty query.
- Existing `/stocks/search` parameters, debounce and limit are preserved.
- Stock results render symbol/name/exchange without raw internal IDs as primary labels.
- Stale responses cannot replace newer query results; failure leaves page results usable.

### Keyboard/mobile/accessibility

- Click/focus expansion and Escape/click-away closure work.
- Arrow navigation and Enter selection work if listbox semantics are implemented.
- Focus enters and returns from the mobile surface; focus containment is tested if modal semantics are used.
- Search and mobile History/Notes cannot leave competing modal surfaces open.
- Search input, result groups, loading, and no-result states have accessible names/roles.

### Runtime-only checks

Browser tests must separately verify header reflow, 1200px/constrained widths, mobile touch and soft keyboard, z-index with menus/toasts/utilities, real screen-reader behavior, and deployed latency.

## 19. Runtime Verification

After implementation, verify in a real browser at wide desktop, 1200px, tablet, and narrow mobile widths:

- collapsed and expanded search do not change header height or push safety controls off-screen;
- overlay width and result alignment remain usable;
- mobile surface is full-width, scrollable, touch-friendly, and keyboard-safe;
- focus order and screen-reader announcements are correct;
- Search does not conflict visually or interactively with Page History, Contextual Notes, Sidebar, profile menus, toasts, or critical safety banners;
- stock lookup latency and cancellation feel acceptable;
- deployed bundle includes the search surface and does not add disproportionate header cost.

## 20. Implementation Sequence

1. Add a small route-search helper that consumes the existing role-filtered navigation catalog and returns normalized page results.
2. Extract/reuse only the non-domain presentation pieces of `StockAutocomplete`; preserve `/stocks/search`, debounce, throttle and result limits.
3. Add an Investor-only `GlobalSearch` header control with ephemeral state and desktop overlay behavior.
4. Add the constrained/mobile full-width surface with focus, Escape, click-away and reduced-motion behavior.
5. Add result grouping, keyboard navigation, stale-request suppression and partial-error handling.
6. Add focused component/source tests for role filtering, route selection, stock request behavior, accessibility and mobile modal coordination.
7. Run existing shell/navigation/Page History/Contextual Notes suites and the production build.
8. Perform browser/runtime verification, then update only `AUD-016` evidence in the implementation audit. Do not alter AUD-004 or AUD-013.

## 21. Open Questions

1. Should the first Investor search destination for a stock be the existing stock-price route, Explorer, or a new neutral stock-detail route? The repository has multiple stock workflows but no single canonical global-search destination.
2. Should knowledge/wiki results be included in the first release using the existing active-profile search endpoint, or remain a later category after result privacy and route semantics are confirmed?
3. Should Admin eventually receive a separate global search policy, or remain intentionally outside the Investor shell contract?
4. At constrained desktop widths, should the expanded field transition to the mobile surface at the existing `1200px` breakpoint or at a narrower header-specific breakpoint? This requires browser geometry validation.

