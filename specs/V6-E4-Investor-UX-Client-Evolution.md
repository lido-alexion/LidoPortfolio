# V6 E4 — Investor UX & Client Evolution

| Field | Value |
|---|---|
| **Status** | **DRAFT FOR PO REVIEW** |
| **Scope** | V4-FEAT-043, V4-FEAT-016, V4-FEAT-035 |
| **Purpose** | StoX V6 UX fit-and-finish, responsive/client evolution, and incremental frontend standardization |
| **Brand accent** | `#1e90ff` |
| **Related** | `LidoPortfolio-V6-Wishlist.md` |

## 1. Intent

V6 E4 is primarily a **UX consolidation and fit-and-finish epic**, not a frontend rewrite and not a change to StoX investment/accounting semantics.

The product should feel spacious, visual, hierarchical, responsive, icon-rich and progressively disclosed. Existing proven StoX components and workflows should be standardized and reused rather than replaced merely for visual novelty.

The UX should keep the default workspace clean and low-distraction while making secondary capabilities available with a small interaction when needed.

A useful design-reference philosophy is StockEdge's spacious, layered, visual financial UX, but StoX must **not copy StockEdge literally**. StoX retains its own workflows, components, brand and information architecture.

## 2. Governing UX principles

### 2.1 Spacious over compact

- Prefer generous page margins, card padding, vertical spacing and touch targets.
- Prefer comfortable row heights and larger readable typography.
- Do not optimize for fitting the maximum possible information on one screen.
- Reduce **perceived information density** without hiding useful information.
- Rounded corners are preferred over hard-edged boxes.

### 2.2 Hierarchical over flat

Large UI monoliths must be decomposed into meaningful layers.

Preferred hierarchy:

`application area -> entity/page -> tabs -> sub-tabs/segmented views -> sections/cards -> expandable detail`

Use:

- separate pages for meaningfully distinct functions;
- tabs for peer views within the same entity;
- sub-tabs or segmented controls for local peer views;
- cards/sections for related content;
- drawers/expanders/accordions for secondary or advanced detail.

Avoid solving a complex entity by adding many top-level tabs or one very long page.

### 2.3 Progressive disclosure

Show the most useful view first. Secondary complexity should appear only when requested.

Prefer:

- collapsible panes;
- drawers;
- expandable cards;
- accordions;
- advanced sections;
- contextual side panes;
- local sub-tabs/segmented controls;
- compact filter controls with advanced filters hidden by default.

### 2.4 Visual-first communication

Where practical, communicate state visually before using prose.

Examples:

- trend -> chart/sparkline;
- allocation -> bar/donut;
- score -> gauge/visual marker;
- status -> icon + semantic color + short label;
- positive/negative -> directional icon + semantic color;
- comparison -> chart/heatmap;
- progress -> progress indicator.

Exact values and textual explanation must remain available for accessibility and precision.

### 2.5 Icons as first-class UI language

Use icons liberally for:

- navigation;
- entity types;
- actions;
- page/menu items;
- status;
- categories;
- metrics;
- contextual tools.

Prefer **icon + tooltip** over unnecessary persistent text when the meaning is sufficiently familiar.

For uncommon, important, destructive or high-risk actions, prefer **icon + text**.

On mobile, do not depend on hover-only tooltips; unfamiliar icon actions must have accessible labels and may show text inside opened menus/sheets.

### 2.6 Navigation semantics

- **Links** navigate to another page/location.
- **Buttons** perform an action.
- **Toggles** change boolean state.
- **Tabs** switch peer views at one hierarchy level.
- **Segmented controls** switch between a small set of mutually exclusive local views/options.
- **Breadcrumbs** show hierarchy where viewport space permits.

Do not use buttons merely as styled links.

## 3. Brand and visual language

### 3.1 Primary brand color

StoX primary brand/accent blue is:

`#1e90ff`

Blue and white form the main visual foundation.

Other colors remain available and should be used meaningfully. Semantic states must retain distinct semantic colors rather than forcing every state into blue:

- green: success/healthy/positive;
- amber/yellow: warning/attention;
- red: danger/error/negative;
- blue: primary/information;
- neutral greys: secondary/inactive.

Do not rely on color alone; pair semantic color with icon, label, shape or other accessible encoding.

### 3.2 Themes

Support at minimum:

- Light;
- Dark;
- System.

Theme implementation should derive appropriate shades/tints around the StoX brand blue while preserving semantic state colors.

### 3.3 Motion and effects

Use small CSS transitions/animations to communicate interaction and state:

- hover;
- click/press;
- drawer/pane open and close;
- accordion expand/collapse;
- card/action emphasis;
- chart/loading/state changes where useful.

Motion must remain short and unobtrusive and respect `prefers-reduced-motion`.

## 4. Responsive architecture

### 4.1 Responsive by viewport class, not device label

StoX must adapt to available viewport size using responsive CSS/media/container-query techniques as appropriate.

Different desktop resolutions must receive different layout treatment; desktop is not one fixed breakpoint.

Representative behavior:

#### Mobile

- single-column primary flow;
- collapsed global navigation;
- drawers/bottom sheets/expandable controls for secondary functions;
- more icon buttons and less persistent text;
- aggressively reduce simultaneous UI;
- omit nonessential chrome when space is constrained.

#### Tablet / small desktop

- compact multi-column layouts where useful;
- fewer simultaneous side panes;
- collapse or hide secondary navigation/chrome before compressing core content excessively.

#### Standard desktop

- persistent navigation where useful;
- main workspace plus optional contextual side pane;
- multi-column cards/charts/tables where meaningful.

#### Large / ultrawide / 4K

Use additional width **judiciously** rather than centering a narrow application with very large empty side margins.

Extra width may be used to:

- increase meaningful column count;
- show contextual/detail panes side-by-side;
- widen analytical tables and charts;
- show filters/help/detail simultaneously;
- provide richer dashboard composition;
- expose more secondary context in parallel.

Do not make prose lines or simple forms arbitrarily wide. Extra space should increase useful information parallelism, not merely stretch controls.

### 4.2 Mobile may use different components

Mobile does not need to render desktop controls in a smaller shape.

The same capability may have a different mobile interaction component.

Examples:

- desktop page-history rail -> mobile floating History button opening a menu/sheet;
- desktop persistent filter area -> mobile filter icon opening a bottom sheet;
- desktop contextual right pane -> mobile full-width/near-full-width drawer or bottom sheet;
- desktop multi-level visible navigation -> mobile user-invoked menus/drawers.

### 4.3 Mobile capability priority

When space requires compromise:

1. preserve the primary task;
2. preserve critical execution/safety state and controls;
3. preserve essential navigation;
4. collapse secondary functionality;
5. omit nice-to-have chrome.

Features such as breadcrumbs and scroll-to-top may be omitted on constrained mobile views if they do not justify their space.

## 5. Adaptive, low-distraction chrome

StoX should prefer UI elements that are present when needed but quiet otherwise.

Examples:

- collapsible left navigation;
- footer that hides/collapses when unnecessary;
- contextual side panes;
- secondary actions revealed on hover/focus where appropriate;
- expandable toolbars;
- collapsible filters/advanced sections;
- search icon/button that expands into a search field when invoked.

On smaller desktop windows as well as mobile, hide/collapse nonessential headers, secondary toolbars, footers and contextual chrome before making core content uncomfortably dense.

The governing rule is:

> Keep the default workspace visually clean, but keep important controls one lightweight interaction away.

## 6. Navigation and information architecture

### 6.1 Multi-level navigation is allowed

Different hierarchy levels may use different navigation mechanisms simultaneously, provided they do not compete for the same level.

Potential layers include:

- global app navigation;
- contextual/entity navigation;
- page tabs;
- local section tabs;
- segmented controls;
- breadcrumbs.

### 6.2 Standardize tab design

Current tab implementations are inconsistent and must converge toward a shared StoX tab pattern with consistent:

- typography;
- spacing;
- active/inactive treatment;
- icon usage;
- responsive behavior;
- overflow behavior;
- routing/deep-link semantics where applicable.

Strategy is an explicit example: continue decomposing Strategy into logical tabs/sections, but use one standardized tab language rather than page-specific variants.

### 6.3 Segmented control standard

Use **segmented controls** (also commonly called segmented tabs/pill tabs) for a small mutually exclusive set, usually 2-4 items.

Good examples:

- `Stock split | Bonus issue`;
- `Value | %`;
- `Gainers | Losers`;
- `Overview | Details`.

Avoid segmented controls for long lists, destructive actions or page-level navigation with many destinations.

### 6.4 Breadcrumbs

Use breadcrumbs to show page hierarchy on desktop/adequate viewports where they add orientation value.

They may be simplified or omitted on constrained mobile layouts.

### 6.5 Existing contextual Help behavior

Retain the existing Help icon in the top header.

It must continue opening documentation **contextually based on the user's current page** rather than a generic static help landing page.

If responsive layouts collapse the top header, the Help capability must remain accessible through an equivalent compact/overflow control.

## 7. Components and reuse

### 7.1 Reuse before replacing

Prefer existing proven StoX components over page-specific duplicates.

Standardize first, then reuse.

This applies especially to:

- tabs;
- integer inputs;
- charts;
- toggles;
- status indicators;
- cards;
- drawers;
- filters;
- action bars;
- confirmations.

### 7.2 Integer spinner

For integer numeric inputs, use the **existing StoX integer spinner** already used in forms such as Add Transaction.

Do not introduce competing integer-number controls without a genuine requirement.

### 7.3 Chart component

Reuse the existing StoX chart component as the standard visualization foundation.

Extend/configure that component where needed rather than introducing competing chart implementations.

Standardize through the shared component:

- visual styling;
- legends;
- tooltips;
- responsiveness;
- empty/loading states;
- interactions;
- theme behavior.

### 7.4 Controls

Prefer:

- toggle for binary state;
- segmented/radio/selectable buttons for a few mutually exclusive options;
- dropdown/combobox for larger/searchable option sets.

Do not replace a dropdown with many buttons when that reduces scanability or responsiveness.

### 7.5 Cards, drawers and modals

- Cards should group meaningful concepts, not create "card soup".
- Drawers/side panes are preferred for inspecting detail while retaining page context.
- Modals should be reserved for short, focused decisions; large workflows/forms should not live in large modals.

## 8. Visualizations and analytical presentation

- Prefer charts, sparklines, mini-bars, progress indicators, allocation graphics and heatmaps where they communicate faster than prose.
- Charts should be interactive where useful: hover/tap details, legends, time-range controls and drill-down.
- Visual summaries should support `summary -> interact -> detail` drill-down.
- Exact numeric values must remain available through labels, tooltips, detail views or tables.
- Existing chart component remains the standard implementation foundation.

## 9. Contextual side panes

A right-side contextual utility area/pane is an accepted StoX pattern for capabilities that should remain available without dominating the main workspace.

Potential uses include:

- contextual help/details;
- audit/detail inspection;
- Notes;
- notifications/activity;
- future contextual utilities.

Right panes should generally be collapsible and may overlay instead of permanently shrinking the main content when a lightweight interaction is more appropriate.

Mobile may replace them with drawers/bottom sheets.

## 10. Page Visit History

### 10.1 Desktop history rail

Introduce a compact **right-edge Page Visit History Rail**.

Behavior:

- show at most the last **12 page visits**;
- right-aligned;
- visually mirrored relative to a left-edge history rail;
- active bars extend leftward;
- page label/link reveals/extends leftward;
- thicker bars with rounded ends;
- active/accent state uses StoX blue `#1e90ff`;
- inactive styling uses low-emphasis theme-appropriate treatment;
- pointer/hand cursor on hover;
- smooth hover/active transitions;
- entries are links because they navigate;
- current page is highlighted;
- consecutive duplicate visits should be collapsed;
- long names truncate gracefully with full value available on hover/focus.

### 10.2 Mobile history replacement

The rail is optional on mobile and should normally be replaced rather than squeezed.

Preferred mobile pattern:

- floating/compact History icon/button;
- tapping opens recent pages as a menu, drawer or sheet;
- no persistent rail required.

The capability matters more than preserving the desktop component.

## 11. Search

Search should support low-distraction presentation.

Where appropriate:

- show a compact search icon/button by default;
- expand into the search field when clicked/invoked;
- collapse again when interaction is complete if that does not harm usability.

On mobile, search may open a dedicated sheet/full-width search surface.

## 12. Scroll-to-top

Show a scroll-to-top control only on sufficiently long pages or scrollable panes where it provides real value.

- appear after meaningful scroll distance;
- remain unobtrusive;
- work against the correct scroll container;
- use standard icon + accessible label/tooltip behavior;
- animate gently unless reduced motion is requested;
- may be omitted on constrained mobile views.

## 13. Responsive tables and data-heavy views

On desktop:

- use available width intelligently;
- show useful columns without artificial narrow max-width constraints.

On constrained layouts:

- hide secondary columns where appropriate;
- allow column chooser/overflow detail;
- transform suitable rows into stacked cards/detail views;
- use horizontal scrolling only when it is genuinely the least harmful option.

Primary data and actions must remain obvious.

## 14. Filters and advanced controls

- Keep primary filters visible.
- Move advanced filters into expandable panels/drawers.
- Summarize active filters using removable chips where useful.
- On mobile, filters should commonly open from a Filter icon into a drawer/bottom sheet.

## 15. Empty/loading states

- Prefer skeleton loading for content-heavy cards, tables, dashboards and charts rather than large blocking spinners.
- Empty states should explain what is missing and provide the next useful action where appropriate.
- Do not present `0` where the real state is unknown/unavailable/incomplete.

## 16. UI preference persistence

UI-only preferences should persist in browser `localStorage` so StoX remembers the user's working style.

Candidates include:

- left navigation expanded/collapsed state;
- right/contextual pane state;
- expanded/collapsed sections;
- dashboard widget visibility/order;
- selected local tabs/sub-tabs where appropriate;
- search expanded/collapsed state where useful;
- table column visibility;
- chart display preferences;
- theme;
- other purely presentational component state.

Requirements:

- UI preference schema must be versioned/migratable/resettable;
- defaults apply when state is absent/stale/incompatible;
- scope preferences per page/component where necessary;
- never store auth tokens, execution authority, financial/business state or other security-sensitive state in this UI-preference storage.

## 17. Dashboard organization

Dashboard reorganization must follow the same hierarchical, spacious and visual-first principles.

- Widgets are user-facing presentation units, not domain state.
- Noncritical widgets may support show/hide and ordering.
- Preferences may be persisted locally as UI preferences unless a future cross-device requirement explicitly moves them server-side.
- Safety-critical controls such as Emergency Halt controls are **not ordinary widgets** and cannot be hidden through Dashboard customization.
- Avoid one dense monolithic dashboard; use meaningful sections and responsive composition.

## 18. Footer/header behavior

Headers and footers are not sacred fixed chrome.

- retain them where they provide navigation, orientation, safety state or useful actions;
- collapse/hide secondary header/footer content when viewport space is constrained or content is not useful;
- behavior applies to smaller desktop windows as well as mobile;
- critical execution/safety controls must not disappear merely to save space.

## 19. Accessibility and interaction quality

- Provide sufficient touch targets.
- Provide keyboard/focus access to interactive controls.
- Do not rely on color alone.
- Do not rely on hover alone for mobile/touch capabilities.
- Icon-only controls require accessible names.
- Respect reduced-motion preferences.
- Preserve exact textual/numeric access behind visual representations.

## 20. Frontend migration boundary

FEAT-035 remains **incremental**.

- Continue TypeScript/TanStack Query/AG Grid adoption where touched surfaces justify it.
- Do not perform a big-bang rewrite.
- Do not replace working migrated V5 surfaces merely to make the codebase cosmetically uniform.
- UX standardization may refactor/reuse shared components where needed, but frozen V3-V5 business behavior must remain unchanged unless an explicit V6 requirement supersedes it.

## 21. Non-goals

E4 does not by itself:

- change accounting, Strategy, Recommendation, Risk or execution semantics;
- create a new design system from scratch when existing StoX components can be standardized;
- mandate a native mobile application;
- mandate a PWA;
- turn every page into a dashboard;
- make every control icon-only regardless of comprehension/safety;
- force desktop and mobile to use identical components;
- remove textual accessibility in favor of visualization;
- persist security/business state in `localStorage`.

## 22. Acceptance criteria

1. Core Investor screens adapt meaningfully across mobile, small desktop, standard desktop, ultrawide and 4K viewport classes rather than merely scaling one fixed-width layout.
2. Large monolithic surfaces are decomposed using clear hierarchy; Strategy and other multi-view entities use standardized StoX tabs/navigation patterns.
3. Existing integer spinner and chart components are reused as the standard foundations for their domains.
4. Primary brand/accent styling uses `#1e90ff` with theme-aware variants and accessible semantic colors.
5. Light/Dark/System themes are supported consistently across standardized components.
6. Links navigate and buttons act; binary/few-choice controls follow toggle/segmented-control guidance.
7. Secondary complexity uses progressive disclosure; mobile reduces simultaneous UI and may replace desktop controls with drawers/sheets/menus.
8. Smaller desktop layouts collapse nonessential chrome before compressing core content excessively; ultrawide layouts use additional width for useful parallel context rather than wasting it as margins.
9. Existing contextual Help behavior remains available and page-aware.
10. Page Visit History supports a right-edge desktop rail (max 12 visits) and an appropriate compact mobile replacement.
11. Scroll-to-top appears only where useful and may be omitted on constrained mobile screens.
12. UI-only presentation preferences persist safely in versioned `localStorage` state; no sensitive/business state is stored there.
13. Dashboard personalization cannot hide safety-critical Emergency controls.
14. Motion, icon use, visualizations and responsive interactions meet accessibility requirements and degrade appropriately for reduced motion/touch.
15. FEAT-035 migration remains incremental and does not silently alter frozen V3-V5 business behavior.

## 23. PO review status

No additional major architecture decision is intentionally raised in this draft. Routine details such as exact breakpoint pixel values, padding tokens, animation duration, icon library selection, tooltip copy, tab overflow mechanics and local component layout are implementation/design-system decisions constrained by this specification.

The PO should review this document as a single UX baseline and flag only concepts that should change. Once accepted, mark E4/its relevant planning items `DECIDED` and treat this specification as the V6 UX baseline.
