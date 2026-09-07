# V6 E4 — Investor UX & Client Evolution

| Field | Value |
|---|---|
| **Status** | **DRAFT FOR PO REVIEW** |
| **Scope** | V4-FEAT-043, V4-FEAT-016, V4-FEAT-035 |
| **Purpose** | Canonical StoX V6 UI architecture, responsive behavior, component placement and frontend standardization |
| **Brand accent** | `#1e90ff` |
| **Related** | `LidoPortfolio-V6-Wishlist.md`, `V6-E6-Contextual-Notes.md` if/when persisted |

## 1. Scope and intent

V6 E4 is the UI architecture and fit-and-finish epic for the Investor application. It does **not** change StoX investment, accounting, execution, reconciliation or security semantics.

The target UX is:

> **Spacious, hierarchical, visual-first, icon-rich, progressively disclosed, responsive and low-distraction.**

The workspace should be clean by default, while important secondary controls remain one small interaction away. StockEdge is a useful design-language reference for spaciousness, layered navigation and visual density, but StoX must not copy it literally.

This specification converts those principles into placement rules, shell architecture, page anatomy, responsive transformations and reusable component behavior.

---

## 2. Global application shell

### 2.1 Desktop shell

The default desktop composition is:

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ TOP HEADER                                                                  │
├──────────────┬──────────────────────────────────────────────┬────────────────┤
│ LEFT NAV     │ MAIN WORKSPACE                               │ RIGHT UTILITY  │
│ collapsible  │                                              │ RAIL / PANE    │
│              │                                              │                │
│              │                                              │                │
├──────────────┴──────────────────────────────────────────────┴────────────────┤
│ FOOTER — low priority, auto-hide/collapse where appropriate                 │
└──────────────────────────────────────────────────────────────────────────────┘
```

The shell has five architectural zones:

1. **Top Header** — global utilities and contextual Help.
2. **Left Navigation** — primary application navigation.
3. **Main Workspace** — page hierarchy and task content.
4. **Right Utility Rail / Contextual Pane** — page history, Notes and future contextual utilities.
5. **Footer** — low-priority information, hidden/collapsed when it adds clutter.

### 2.2 Top Header

The top header is global application chrome and should remain visually light.

Preferred content order:

**Left side**
- StoX logo / application identity.
- Optional compact page/application context when useful.

**Center / flexible area**
- Global Search control.
- Search is compact by default and expands when invoked.

**Right side**
- Existing contextual **Help** icon.
- Theme control where exposed.
- Notification/global status utilities where applicable.
- Investor/profile/account menu.
- Global execution-state indicator and emergency actions when required by E1 safety rules.

Rules:
- Existing contextual Help behavior must be retained: Help opens documentation relevant to the current page.
- Safety-critical execution controls must remain reachable even if nonessential header elements collapse.
- On narrow desktop/mobile, secondary utilities may move into overflow, but Help and safety-critical controls remain accessible.

### 2.3 Left Navigation

The left navigation is the primary application-level navigation mechanism.

#### Expanded state
- Icon + text label.
- Supports nested navigation when hierarchy genuinely requires it.
- Clear active item treatment using StoX brand blue.
- Subtle animation for expand/collapse.

#### Collapsed state
- Icon-only rail.
- Tooltips or revealed labels on hover/focus.
- Active destination remains visually obvious.
- Width should be sufficient for comfortable pointer targets, not an ultra-thin decorative strip.

#### Interaction
- Collapse/expand by explicit control.
- Optional temporary expansion on hover is acceptable where it does not cause accidental layout shift.
- Persistent expanded/collapsed preference is stored in `localStorage`.
- On smaller desktop widths, default may become collapsed.
- On mobile, the left nav becomes a drawer rather than remaining as a fixed rail.

### 2.4 Main Workspace

The main workspace is the primary task area and follows a consistent vertical anatomy:

```text
Breadcrumbs (when useful)
↓
Page / Entity Header
↓
Entity-level Tabs
↓
Local View Selector / Segmented Control / Filter Bar
↓
Primary Content
↓
Expandable / Advanced / Detail Sections
```

Main workspace rules:
- Use available width intelligently.
- Avoid narrow fixed-width desktop layouts with excessive empty margins.
- Forms and prose can retain readable max widths.
- Tables, charts and analytical workspaces should use width aggressively.
- Large/ultrawide displays should expose more useful parallel context rather than merely stretching controls.

### 2.5 Right Utility Rail

The right edge hosts contextual utilities that should be available without dominating the workspace.

Initial V6 utilities:
- **Page Visit History Rail**.
- **Contextual Notes** entry point.
- Reserved extension point for future contextual utilities.

Rules:
- Utility icons remain compact.
- Only one large right-side contextual pane should normally be open at a time.
- Opening a contextual pane should generally overlay the main workspace instead of permanently shrinking it.
- On mobile, right-rail capabilities transform into buttons/menus/drawers.

### 2.6 Footer

The footer is intentionally low priority.

- It may contain legal links, version information, Help/support links or secondary information.
- It may auto-hide, collapse or be omitted in task-focused layouts.
- It must not consume meaningful vertical space merely for visual symmetry.
- It must never contain the only access path to a critical function.

---

## 3. Standard page anatomy

Every major Investor page should use the following hierarchy unless a specific workflow requires otherwise.

### 3.1 Breadcrumbs

Use breadcrumbs when they improve orientation, especially for nested entities.

Example:

`Portfolio > Strategy > Momentum Large Cap`

Rules:
- Desktop: show when useful.
- Tablet: optional depending on width.
- Mobile: usually simplify or omit.
- Breadcrumbs are links, not buttons.

### 3.2 Page / Entity Header

The page header contains:
- page/entity title;
- concise optional subtitle/description;
- key status chip(s) where relevant;
- primary page-level action(s) aligned to the action area;
- secondary actions as icon buttons or overflow.

The header must not become a dense toolbar.

### 3.3 Entity-level Tabs

Tabs are used for peer views of the same entity.

Examples:

**Portfolio**
- Overview
- Holdings
- Performance
- Transactions
- Analytics
- Recommendations

**Strategy**
- Overview
- Design
- Execution
- Performance
- History

The exact existing route set remains authoritative where already defined; this spec standardizes presentation rather than inventing domain behavior.

Rules:
- One shared StoX tab component and visual language.
- Same spacing, typography, active/inactive treatment, icon rules and responsive overflow behavior.
- Route-backed tabs should support deep-linking/back-forward navigation.
- Do not use different tab styles on different pages without a strong reason.

### 3.4 Local view selectors

Inside a tab or section, use a **segmented control** for 2–4 mutually exclusive peer views.

Examples:
- `Stock split | Bonus issue`
- `Value | %`
- `Gainers | Losers`
- `Basic | Advanced`

Placement:
- immediately above the content it controls;
- usually left-aligned with the controlled section;
- may be right-aligned in a chart header when acting as a compact display mode/time range selector.

Do not use segmented controls for long navigation lists or destructive actions.

### 3.5 Sections and cards

Use cards to group a meaningful concept, not every piece of content.

Preferred composition:
- summary KPI cards near the top;
- primary chart/table/content below;
- advanced detail in expandable sections;
- contextual detail in a side pane/drawer.

Avoid “card soup” where every row or label is boxed independently.

---

## 4. Navigation hierarchy

StoX may intentionally have multiple navigation levels, provided each represents a different hierarchy.

```text
Global app navigation
  ↓
Entity/page navigation
  ↓
Tabs
  ↓
Sub-tabs / segmented controls
  ↓
Sections
  ↓
Expandable detail
```

This layered structure is preferred over one flat mega-page or excessive top-level tabs.

### Placement rule

| Navigation need | Component |
|---|---|
| Move between major application areas | Left navigation |
| Move between peer views of one entity | Tabs |
| Choose 2–4 local peer views/options | Segmented control |
| Choose from many/searchable options | Dropdown/combobox |
| Show hierarchy | Breadcrumbs |
| Reveal advanced/local detail | Accordion/expandable section/drawer |
| Navigate to recent page | Page Visit History |

---

## 5. Core component placement rules

| Component / capability | Preferred placement |
|---|---|
| Global Search | Top header; compact icon/control expands on demand |
| Contextual Help | Existing Help icon in top header |
| Primary app navigation | Collapsible left navigation |
| Entity navigation | Tabs below page header |
| 2–4 local mutually-exclusive options | Segmented control near controlled content |
| Primary page action | Page header action area or sticky local action area |
| Secondary page actions | Icon toolbar / overflow menu |
| Destructive/high-risk action | Icon + text; explicit confirmation where required |
| Filters | Compact filter row; advanced filters expandable |
| Mobile filters | Filter icon → drawer/bottom sheet |
| Contextual detail | Right pane/drawer |
| Contextual Notes | Right utility rail → overlay pane; mobile drawer/sheet |
| Page history | Right-edge rail; mobile History button/menu/sheet |
| Long-page return | Scroll-to-top button after meaningful scroll |
| Integer numeric input | Existing StoX integer spinner |
| Binary setting | Toggle |
| 2–4 choice setting | Segmented/radio/selectable control |
| Large/searchable choice set | Dropdown/combobox |
| Analytical visualization | Existing shared chart component |
| Detailed tabular evidence | Responsive table/grid |
| Loading of content-heavy regions | Skeleton state |

---

## 6. Representative desktop layouts

### 6.1 Portfolio / Dashboard-style overview

Recommended composition:

```text
Breadcrumb
Portfolio                                          [Portfolio selector] [Primary action]
Short supporting text / status

[Overview] [Holdings] [Performance] [Transactions] [Analytics] [Recommendations]

[KPI] [KPI] [KPI] [KPI]

[Portfolio Value / Performance chart            ] [Top Holdings / Allocation]

[Cash / Exposure / Strategy summaries           ] [Contextual secondary panel]
```

On large displays:
- allow more KPI columns;
- show chart and holdings/allocation side-by-side;
- optionally expose detail context without navigation away.

On smaller desktop:
- reduce columns before reducing padding aggressively;
- collapse nonessential contextual panels.

### 6.2 Strategy page

Recommended architecture:

```text
Breadcrumb
Strategy name                           [Status] [Primary action] [⋯]
Short description

[Overview] [Design] [Execution] [Performance] [History]

Inside Design:
  [Rules | Indicators | Capital]   ← segmented or local tabs depending complexity

  Main configuration section
  Advanced configuration ▸
  Evidence / preview ▸
```

Strategy is the model example for standardizing tabs across the application.

### 6.3 Data-heavy page

For holdings, transactions, audit-style history and similar pages:

```text
Page header
Tabs if applicable

[Search] [Primary filters] [Advanced filters ▾]           [Column settings] [Export]
[Active filter chips]

┌──────────────────────────────────────────────────────────┐
│ Responsive table/grid                                   │
└──────────────────────────────────────────────────────────┘
```

Detail inspection should prefer a side drawer/pane so the current list context is retained.

---

## 7. Dashboard architecture — FEAT-043

Dashboard customization is presentation-only and must not become a domain-state system.

### 7.1 Dashboard zones

Recommended order:
1. Portfolio/account summary KPIs.
2. Performance/value visualization.
3. Holdings/allocation/risk summaries.
4. Recommendations/execution context where appropriate.
5. Secondary informational widgets.

### 7.2 Widget behavior

Noncritical widgets may support:
- show/hide;
- ordering;
- responsive placement;
- optional local sizing if implementation remains simple.

Rules:
- Emergency controls are **not widgets** and cannot be hidden.
- Widget preferences are UI preferences and may remain local to the browser in V6.
- Provide a sensible default layout and reset-to-default capability.
- Do not expose a complex free-form dashboard designer unless there is a genuine use case.

### 7.3 Large-display behavior

Additional width should increase useful parallelism:
- more widget columns;
- larger/wider charts;
- side-by-side detail;
- more table columns where valuable.

Do not simply stretch cards to huge widths.

---

## 8. Right-side Page Visit History

### 8.1 Desktop rail

A compact vertical history rail is anchored to the right edge.

Behavior:
- maximum last **12 page visits**;
- most recent first;
- consecutive duplicate visits collapsed;
- current page is active;
- bars extend leftward from the right edge;
- thicker bars with rounded ends;
- active accent `#1e90ff`;
- compact/inactive state visually quiet;
- label reveals leftward on hover/focus where useful;
- long labels truncate with full value available through tooltip/focus treatment;
- entries use pointer cursor and are links.

### 8.2 Mobile replacement

No persistent rail is required.

Use:
- History icon/button;
- tap opens recent destinations as menu, drawer or sheet.

Capability is preserved; desktop geometry is not.

---

## 9. Contextual Notes placement

The detailed Notes feature is specified separately under E6. E4 defines only its shell placement and visual integration.

### Desktop
- persistent Notes icon in the right utility rail;
- click opens a narrow overlay pane from the right;
- pane approximately navigation-pane width or slightly wider;
- pane overlays rather than permanently resizes main content;
- slightly transparent while idle;
- becomes fully opaque on hover/focus-within;
- short CSS slide-in/slide-out animation from right;
- respects `prefers-reduced-motion`.

### Mobile
- Notes action opens a near-full-width drawer or bottom sheet;
- no hover-dependent transparency;
- pane is fully opaque.

---

## 10. Search interaction

Global Search follows the low-distraction principle.

### Desktop
- compact Search icon/control in top header by default;
- click/focus expands into search field;
- field may collapse again after completion/escape/blur where usability is not harmed;
- expansion should not cause disruptive page reflow.

### Mobile
- Search icon opens a dedicated full-width search surface or sheet.

Search state may be remembered locally only when that meaningfully improves the experience; a permanently expanded field should not be forced simply because the previous session ended that way.

---

## 11. Contextual Help

The existing Help icon and page-context mapping are retained exactly in behavioral intent.

- Help remains in the top header on normal desktop layouts.
- It opens documentation appropriate to the current route/page context.
- If header space is constrained, Help may move into a compact/overflow presentation but must remain directly reachable.
- Do not replace contextual documentation routing with a generic documentation home link.

---

## 12. Tables and analytical views

### Desktop
- analytical tables should use available width;
- do not force narrow centered max-width containers;
- preserve comfortable row height and whitespace;
- column chooser is acceptable for secondary fields.

### Constrained layouts
Use this order of preference:
1. hide clearly secondary columns;
2. expose hidden data through row expansion/detail;
3. transform suitable table content into stacked cards;
4. use horizontal scrolling when the table structure itself must remain tabular.

Primary values and actions must remain visible.

---

## 13. Charts and visualizations

Reuse the existing StoX chart component as the standard chart foundation.

Standardize:
- responsive sizing;
- theme support;
- legends;
- tooltips;
- loading/empty states;
- time-range selectors;
- hover/tap interaction;
- accessible exact values.

Visual communication is preferred where it improves understanding:
- trend → line/sparkline;
- allocation → donut/bar;
- status → icon + color + short label;
- comparison → chart/heatmap;
- progress → progress indicator.

Do not add another chart library merely for stylistic variety.

---

## 14. Forms and controls

### 14.1 Binary state
Use a **toggle** for persistent on/off state.

### 14.2 Small mutually-exclusive choice
Use:
- segmented control;
- radio/selectable buttons where better suited.

Target range is generally 2–4 options.

### 14.3 Large/searchable choice set
Use dropdown/combobox.

### 14.4 Integer inputs
Reuse the existing StoX integer spinner used in flows such as Add Transaction.

### 14.5 Action semantics
- Link = navigation.
- Button = action.
- Toggle = state change.
- Tabs = peer-view navigation.
- Destructive/high-risk actions should use text with icon rather than relying on icon recognition alone.

---

## 15. Drawers, panes, accordions and modals

Preferred hierarchy:

- **Accordion / expandable section** — local secondary detail.
- **Right-side drawer/pane** — inspect contextual detail while preserving page context.
- **Bottom sheet** — mobile contextual options/details.
- **Modal** — short focused decision or confirmation only.

Large forms and multi-step workflows should not be placed inside oversized modals.

---

## 16. Adaptive low-distraction chrome

StoX should reveal secondary UI when needed rather than showing all controls continuously.

Patterns include:
- collapsed left navigation;
- hover/focus reveal of labels/actions;
- expandable Search;
- contextual right panes;
- hidden/collapsible footer;
- collapsible filter/advanced toolbars;
- secondary row/card actions revealed on hover/focus on pointer devices.

On touch devices, hover-only actions must have an explicit tap-accessible equivalent.

The default screen should remain visually calm without making important functions difficult to discover.

---

## 17. Responsive architecture

Responsiveness is based on available viewport, not device identity.

### 17.1 Behavioral classes

#### Mobile
- single-column primary task flow;
- hamburger/drawer navigation;
- contextual tools become drawers/sheets;
- fewer persistent labels;
- breadcrumbs generally omitted;
- tables transform/hide secondary columns where possible;
- critical safety state/actions remain reachable.

#### Tablet / small desktop
- 1–2 content columns depending content;
- left nav collapsed by default where needed;
- contextual side pane generally overlays;
- secondary header/footer chrome reduced before core content becomes cramped.

#### Standard desktop
- expandable/persistent left nav;
- multi-column workspace;
- right utility rail available;
- contextual pane as overlay;
- full entity tabs and filter rows where space allows.

#### Large / ultrawide / 4K
- expose more parallel context;
- increase meaningful column count;
- widen charts/tables;
- optionally expose filters/detail side-by-side;
- preserve readable max width for prose/forms.

### 17.2 Responsive transformation matrix

| Component | Mobile | Tablet / small desktop | Standard desktop | Ultrawide / 4K |
|---|---|---|---|---|
| Left nav | Drawer | Collapsed rail / drawer | Expandable sidebar | Expanded/sidebar |
| Top header | Compact | Reduced | Full | Full |
| Search | Icon → full surface | Icon → expanded field | Expandable field | Expandable or persistent if useful |
| Breadcrumbs | Usually hidden | Conditional | Visible when useful | Visible |
| Entity tabs | Scroll/overflow/menu if needed | Compact tabs | Full tabs | Full tabs |
| Segmented controls | Scroll/stack only if unavoidable | Inline | Inline | Inline |
| Right utility rail | Replaced by buttons/menu | Compact rail where viable | Full rail | Full rail |
| Context pane | Drawer/bottom sheet | Overlay | Overlay right pane | Overlay or side-by-side where useful |
| History | Icon → sheet/menu | Compact rail/button | Right rail | Right rail |
| Tables | Cards/reduced columns | Reduced columns | Full grid | Wider/more columns |
| Charts | Single column | 1–2 columns | Multi-column | Multi-panel / richer context |
| Filters | Drawer/sheet | Collapsible | Inline + advanced | Inline, more exposed |
| Footer | Usually hidden | Conditional | Visible if useful | Visible if useful |

### 17.3 Mobile priority order

When compromise is necessary:
1. primary task;
2. critical safety state/actions;
3. essential navigation;
4. secondary functionality;
5. decorative/nice-to-have chrome.

---

## 18. UI preference persistence

UI-only preferences should persist in browser `localStorage`.

Examples:
- left-nav expanded/collapsed state;
- right/contextual pane state where useful;
- expanded/collapsed sections;
- dashboard widget visibility/order;
- selected local display mode where sensible;
- table column visibility;
- chart range/display preferences;
- theme;
- other presentational state.

Architecture requirements:
- version the preference schema;
- namespace values by application/component/page where appropriate;
- tolerate missing/stale/incompatible values;
- support safe reset to defaults;
- migrate or discard incompatible preferences after frontend changes;
- never store auth tokens, execution authority, financial/business state or other security-sensitive data in UI-preference storage.

Not every transient UI gesture needs persistence. Persist stable user preference, not accidental temporary state.

---

## 19. Theme and visual system

### 19.1 Brand
Primary accent:

`#1e90ff`

Blue/white is the base identity.

### 19.2 Semantic colors
- green — success/positive/healthy;
- amber/yellow — warning/attention;
- red — error/danger/negative;
- blue — primary/information;
- neutral tones — secondary/inactive.

Never rely on color alone; pair it with icon, text, shape or another accessible signal.

### 19.3 Themes
Support at minimum:
- Light;
- Dark;
- System.

### 19.4 Shape/elevation
- rounded corners preferred;
- subtle elevation/shadow for overlays/cards where it clarifies layering;
- avoid heavy skeuomorphic shadowing;
- generous spacing is part of the visual identity.

---

## 20. Motion

Use CSS motion sparingly to communicate state:
- nav expand/collapse;
- drawer/pane slide;
- accordion expansion;
- hover/focus transitions;
- compact button feedback;
- chart state transitions where useful.

Rules:
- short and functional;
- never delay task completion for decorative animation;
- respect `prefers-reduced-motion`;
- avoid large layout shifts during hover.

---

## 21. Loading, empty and unavailable states

### Loading
Prefer skeletons for:
- dashboard cards;
- charts;
- tables;
- content-heavy panes.

Use small spinners only for focused action-level waits where appropriate.

### Empty
Empty states should:
- explain what is absent;
- distinguish genuine zero from unavailable/unknown/incomplete;
- offer the next useful action when one exists.

StoX must not display `0` merely because data is unavailable.

---

## 22. Scroll-to-top

Use only on sufficiently long pages/panes.

Rules:
- appears after meaningful scroll;
- anchored unobtrusively;
- targets the correct scroll container;
- icon + tooltip/accessibility label;
- smooth scroll unless reduced-motion preference applies;
- optional/omittable on constrained mobile.

---

## 23. Accessibility

Minimum interaction requirements:
- keyboard-accessible controls;
- visible focus treatment;
- adequate touch targets;
- accessible names for icon-only actions;
- no capability dependent solely on hover;
- no state conveyed solely by color;
- reduced-motion support;
- tooltips supplemented by tap-accessible labels/menus on touch devices;
- chart values available textually or via accessible detail.

---

## 24. Frontend component architecture — FEAT-035

V6 does not authorize a big-bang frontend rewrite.

When E4 touches an existing surface:
1. inspect whether a suitable shared component already exists;
2. standardize/extend it where reasonable;
3. migrate the touched surface incrementally;
4. avoid rewriting unrelated already-working screens solely for aesthetic uniformity.

Priority shared components:
- AppShell;
- TopHeader;
- LeftNavigation;
- PageHeader;
- Breadcrumbs;
- Tabs;
- SegmentedControl;
- StatusChip;
- IconButton;
- ExpandableSearch;
- ContextDrawer;
- FilterBar;
- ResponsiveDataView/Table;
- Card/KPICard;
- Chart wrapper around existing chart implementation;
- IntegerSpinner wrapper/reuse;
- Skeleton/EmptyState;
- ScrollToTop;
- PageHistoryRail;
- localStorage-backed UI preference utility/hook.

Component styling should derive from shared tokens rather than per-page ad-hoc values.

---

## 25. Suggested design tokens

The implementation may map these into the existing styling system rather than inventing a new framework.

### Core
- `brand-primary: #1e90ff`
- semantic success/warning/error/info tokens
- light/dark surface/background/text tokens

### Spacing
Use a consistent spacing scale, preferably based on an **8 px rhythm** for page-level spacing, with smaller subdivisions where controls require them.

### Radius
Prefer a consistent small family of radii rather than arbitrary per-page values.

### Motion
Define shared short/medium transition durations and easing tokens.

Exact token names may follow the current frontend convention.

---

## 26. Representative interaction behavior

### 26.1 Left navigation
1. User opens StoX.
2. Previously saved nav state is restored.
3. Collapsed nav exposes icons only.
4. Hover/focus reveals label/context without distracting from workspace.
5. Explicit expand action opens full nav and persists preference.

### 26.2 Search
1. Header shows compact Search control.
2. User activates Search.
3. Field expands without disruptive reflow.
4. Search results appear in an appropriate overlay/list.
5. Escape/close restores compact state.

### 26.3 Context pane
1. User selects Notes/history/detail utility.
2. Pane animates in from right.
3. Main page remains visible beneath/behind it.
4. Pane can close via explicit close, Escape and appropriate outside interaction.
5. Mobile uses drawer/sheet equivalent.

---

## 27. Non-goals

E4 does **not** introduce:
- new investment/accounting semantics;
- new execution authority;
- a big-bang frontend rewrite;
- a new charting library without demonstrated need;
- a general-purpose free-form dashboard designer;
- mandatory persistence of every transient UI gesture;
- desktop-only capability hidden from mobile without an equivalent path;
- admin impersonation or weakening of existing authorization boundaries.

---

## 28. Acceptance criteria

E4 is implementation-ready when the following are true:

### Shell
- shared responsive application shell exists;
- left navigation has expanded/collapsed/mobile forms;
- contextual Help behavior is retained;
- Search follows expandable low-distraction behavior;
- right utility location is standardized;
- footer can de-emphasize/hide without affecting critical functions.

### Navigation
- common page anatomy is used;
- tab styling/behavior is standardized;
- segmented controls are used consistently for small local peer choices;
- breadcrumbs are responsive and semantically correct.

### Responsive behavior
- mobile is purpose-designed rather than merely scaled down;
- small desktop removes secondary chrome before core content becomes cramped;
- ultrawide uses extra width for useful parallel context;
- tables/charts have explicit responsive behavior.

### Components
- existing spinner and chart foundations are reused;
- shared components replace avoidable page-specific duplicates;
- visual tokens and common interaction patterns are consistent.

### Preferences
- stable UI preferences are persisted through a versioned `localStorage` mechanism;
- security/business state is excluded;
- reset/fallback behavior exists.

### Quality
- skeleton/empty/unavailable states are implemented consistently;
- accessibility requirements are met;
- reduced-motion behavior is respected;
- important controls are reachable without visual clutter.

### Safety compatibility
- Emergency Halt and related safety controls remain globally reachable when required;
- dashboard customization cannot hide them;
- responsive transformations do not remove execution safety capability.

---

## 29. PO review status

The architecture above consolidates the currently discussed E4 UX direction into an implementable UI structure.

The document remains **DRAFT FOR PO REVIEW** until the PO accepts the architecture as a whole. After acceptance, E4 can be marked `DECIDED`; ordinary spacing/component implementation details may then proceed as engineering decisions unless they materially change the product behavior described here.
