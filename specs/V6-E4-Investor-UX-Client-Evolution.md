# V6 E4 — Investor UX & Client Evolution

| Field | Value |
|---|---|
| **Status** | **FROZEN / DECIDED** |
| **Scope** | V4-FEAT-043, V4-FEAT-016, V4-FEAT-035 |
| **Purpose** | Canonical StoX V6 UI architecture, responsive behavior, component placement, frontend standardization and non-regression rules |
| **Brand accent** | `#1e90ff` |
| **Related** | `LidoPortfolio-V6-Wishlist.md`, `V6-E6-Contextual-Notes.md` if/when persisted, `docs/v2.1/DASHBOARD-SPEC.md` |

## 1. Scope and intent

V6 E4 is the UI architecture and fit-and-finish epic for the Investor application. It does **not** change StoX investment, accounting, execution, reconciliation or security semantics.

The target UX is:

> **Spacious, hierarchical, visual-first, icon-rich, progressively disclosed, responsive and low-distraction.**

The workspace should be clean by default, while important secondary controls remain one small interaction away. StockEdge is a useful design-language reference for spaciousness, layered navigation and visual density, but StoX must not copy it literally.

This epic is fundamentally a **presentation, interaction and frontend-consolidation effort**, not a functional simplification exercise. Existing useful information and convenience features are part of the current product and are preserved unless explicitly reviewed with the PO.

---

## 2. Non-regression is a first-class V6 requirement

### 2.1 Functional preservation rule

E4 must not functionally regress an existing StoX surface merely to make it cleaner, simpler or more visually consistent.

When redesigning a page, the implementation must first inspect the current page and inventory:

- displayed information and metrics;
- actions and workflow entry points;
- links and shortcuts;
- convenience utilities;
- external links;
- copy-ready/readymade prompts intended for use with external LLMs or other tools;
- filters, toggles and view selectors;
- contextual help affordances;
- status, warning and diagnostic information;
- admin-only utilities where legitimately present;
- local preference behavior already implemented.

### 2.2 PO review gate for removals

The following rule is mandatory:

> **If E4 would remove existing user-visible information, an existing capability, or an existing convenience feature, that removal requires explicit PO review.**

No separate PO review is required when the same information/capability is retained and only its presentation, placement, component, hierarchy or responsive treatment changes.

Examples that do **not** require review:

- table → chart while retaining the underlying information and access to exact values;
- dense card → expandable section;
- text action → icon + tooltip/menu where equally reachable;
- relocating a handy link into a contextual action group;
- moving an existing prompt into a cleaner copy/open action;
- splitting one large page into tabs while retaining all capabilities.

Examples that **do** require PO review:

- dropping an existing metric from Dashboard;
- removing a handy external link because it appears secondary;
- removing a readymade LLM prompt or helper action;
- hiding existing evidence such that it is no longer reasonably accessible;
- deleting a current admin utility from a redesigned surface.

### 2.3 Implementation inventory gate

Before a materially redesigned page is considered complete, engineering must compare old and new capability inventories and account for each existing item as one of:

- retained in place;
- retained with new presentation;
- moved elsewhere with a clear access path;
- intentionally removed with recorded PO approval.

This comparison is part of E4 acceptance, not optional cleanup work.

---

## 3. Global application shell

### 3.1 Desktop shell

The default desktop composition is:

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ TOP HEADER                                                                  │
├──────────────┬──────────────────────────────────────────────┬────────────────┤
│ LEFT NAV     │ MAIN WORKSPACE                               │ RIGHT UTILITY  │
│ collapsible  │                                              │ RAIL / PANE    │
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

### 3.2 Top Header

The top header is global application chrome and should remain visually light.

Preferred content:

- StoX identity;
- compact Global Search;
- existing contextual Help;
- existing theme selector;
- notification/global status utilities where applicable;
- Investor/profile/account menu;
- execution-state indicator and emergency actions when required by E1.

Rules:

- Existing contextual Help behavior must be retained: Help opens documentation relevant to the current page.
- The existing theme selector component must be reused; E4 must not create a parallel/new theme selector merely for visual redesign.
- The current implementation is `ThemeToggle.jsx`, backed by `ThemeContext`, and already supports Light / System / Dark.
- Safety-critical execution controls remain reachable even when nonessential header elements collapse.
- On constrained widths, secondary utilities may move into overflow rather than disappear.

### 3.3 Left Navigation

Expanded state:

- icon + text label;
- nested navigation where hierarchy genuinely requires it;
- clear active state using StoX brand blue;
- subtle expand/collapse animation.

Collapsed state:

- icon-only rail;
- labels revealed by tooltip/hover/focus where useful;
- comfortable pointer targets;
- active destination remains obvious.

Interaction:

- explicit collapse/expand control;
- optional hover expansion where it does not create disruptive layout shift;
- stable preference persisted in `localStorage`;
- small desktop may default to collapsed;
- mobile uses a drawer rather than a fixed rail.

### 3.4 Main Workspace

Preferred vertical hierarchy:

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

Rules:

- use available desktop width intelligently;
- do not constrain the entire app to a narrow centered column on wide displays;
- prose/forms may retain readable max widths;
- tables/charts/analytical views should use width aggressively;
- large/ultrawide displays should expose useful parallel context rather than merely stretch controls.

### 3.5 Right Utility Rail

Initial V6 utilities:

- Page Visit History;
- Contextual Notes;
- reserved extension point for future contextual utilities.

Rules:

- compact by default;
- only one large contextual pane normally open at once;
- overlay the workspace rather than permanently shrinking it in standard layouts;
- mobile transforms utilities into buttons, menus, drawers or sheets.

### 3.6 Footer

The footer is intentionally low priority.

- may auto-hide/collapse or be omitted in task-focused layouts;
- must not consume significant space merely for symmetry;
- must never contain the only path to a critical function.

---

## 4. Standard page anatomy and navigation hierarchy

Preferred hierarchy:

`application area → entity/page → tabs → sub-tabs/segmented views → sections/cards → expandable detail`

Use:

- left navigation for major application areas;
- tabs for peer views of one entity;
- segmented controls for 2–4 local mutually exclusive views/options;
- dropdown/combobox for larger/searchable sets;
- breadcrumbs for hierarchy;
- accordion/expandable section/drawer for secondary detail.

### 4.1 Tabs

Use one shared StoX tab language:

- consistent spacing and typography;
- consistent active/inactive style;
- consistent icon behavior;
- responsive overflow behavior;
- route-backed tabs support deep-linking and browser back/forward.

The Strategy surface is a key reference for eliminating inconsistent tab implementations.

### 4.2 Segmented controls

Use a **segmented control** for approximately 2–4 mutually exclusive peer choices.

Examples:

- `Stock split | Bonus issue`
- `Value | %`
- `Gainers | Losers`
- `Basic | Advanced`

Do not use segmented controls for long option lists or destructive actions.

### 4.3 Cards and sections

Use cards for meaningful conceptual grouping, not every row or label.

Prefer:

- KPI summaries near the top;
- primary charts/tables below;
- advanced detail in expandable sections;
- contextual detail in drawers/panes.

Avoid card soup.

---

## 5. Core component rules

| Need | Preferred component / placement |
|---|---|
| Global Search | Top header; compact icon/control expands on demand |
| Contextual Help | Existing Help icon in top header |
| Theme | **Existing `ThemeToggle` component**; do not duplicate |
| Primary app navigation | Collapsible left navigation |
| Entity navigation | Shared tabs below page header |
| 2–4 local peer choices | Segmented control |
| Binary setting | Toggle |
| Many/searchable choices | Dropdown/combobox |
| Integer input | Existing StoX integer spinner |
| Analytical charts | Existing StoX chart foundation; extend/reuse |
| Contextual detail | Right drawer/pane |
| Contextual Notes | Right utility rail → overlay pane; mobile drawer/sheet |
| Page history | Right-edge rail; mobile History action |
| Long-page return | Scroll-to-top after meaningful scroll |
| Loading | Skeleton for content-heavy regions |
| Navigation | Link semantics |
| Action | Button semantics |

Existing reusable controls should be extended before introducing parallel implementations.

---

## 6. Dashboard architecture — FEAT-043

### 6.1 Dashboard is presentation/aggregation, not a financial SoT

Dashboard restructuring remains presentation-oriented. It must not become the owner of portfolio calculations, cash accounting, snapshot generation, market analytics or other domain calculations.

### 6.2 Current Dashboard capability baseline

The current Dashboard is an explicit preservation baseline for E4. Current implemented user-visible capability includes:

- live portfolio summary: portfolio value, invested value, P&L and XIRR;
- cash available / investable cash;
- top gainer and top loser with all-time/latest-day switching;
- positions count, diversification and average relative-strength analytics;
- Market Health and collapsible market gauges/diagnostics;
- active Alerts with acknowledge and clear-all actions;
- upcoming calendar/events;
- actionable pattern signals on current holdings;
- Relative Strength views;
- Allocation table/visualization;
- Portfolio Growth chart;
- Unrealized P/L history chart;
- rebuild portfolio history action;
- view snapshots path/action;
- Dashboard refresh/cache behavior;
- admin-only daily-price sync/status where currently applicable;
- links from Dashboard to related deeper pages such as Market Depth/Patterns where currently exposed;
- existing local Dashboard preferences such as top-mover period, allocation display mode and collapsible diagnostics.

This list is a **minimum preservation baseline**, not permission to ignore smaller utilities discovered in implementation.

### 6.3 Dashboard redesign rule

The PO is interested in the information already present on the Dashboard. Therefore:

> **No existing Dashboard data/metric should be removed without PO review.**

Presentation may change freely within the frozen UX architecture without separate review, provided the data remains clearly accessible.

Examples:

- KPI → richer visual KPI: allowed;
- table → chart + exact-value detail: allowed;
- move secondary diagnostic into expandable section: allowed;
- relocate a Dashboard metric to another Dashboard tab/section while keeping it easily reachable: allowed;
- remove a metric entirely: PO review required.

### 6.4 Widget behavior

Noncritical Dashboard widgets may support:

- show/hide;
- ordering;
- responsive placement;
- optional local sizing where simple.

Rules:

- emergency controls are not widgets and can never be hidden;
- widget state is UI preference, not business state;
- defaults must retain a useful complete Dashboard;
- reset-to-default is required;
- avoid a complex free-form dashboard designer unless a real use case emerges.

### 6.5 Large-display behavior

Additional width should increase useful parallelism:

- more widget columns;
- larger/wider charts;
- side-by-side detail;
- additional useful table columns.

Do not merely stretch cards to very large widths.

---

## 7. Existing micro-features and convenience affordances

StoX already contains many small, useful product conveniences accumulated over time. These must be treated as product functionality, not incidental clutter.

Examples include, but are not limited to:

- handy contextual links;
- direct links to deeper analysis pages;
- readymade prompts intended to be copied/reviewed in ChatGPT or another external LLM;
- copy helpers;
- quick analysis/review shortcuts;
- small status explanations;
- contextual guide/document links;
- page-specific utility actions;
- compact admin/diagnostic controls where legitimately available.

E4 should retain these as much as reasonably possible. They may be reorganized into cleaner mechanisms such as:

- icon + tooltip;
- compact action menu;
- contextual action group;
- expandable helper section;
- right-side contextual pane;
- copy/open split action;
- overflow menu on constrained layouts.

A convenience feature must not disappear merely because it does not fit a new visual composition. If genuine removal is proposed, PO review is required.

---

## 8. Page Visit History

Desktop:

- right-edge vertical rail;
- maximum last **12 page visits**;
- most recent first;
- consecutive duplicates collapsed;
- current page active;
- bars extend leftward;
- thicker bars with rounded ends;
- active accent `#1e90ff`;
- inactive state visually quiet;
- label reveal on hover/focus;
- long labels truncate with tooltip/focus treatment;
- entries are links with pointer cursor.

Mobile:

- no persistent rail required;
- History icon/button opens recent pages in menu/sheet/drawer.

---

## 9. Contextual Notes placement

Detailed Notes behavior belongs to E6. E4 defines integration into the shell.

Desktop:

- persistent Notes icon in right utility rail;
- narrow right overlay pane, roughly navigation-pane width or slightly wider;
- pane overlays rather than resizes main workspace;
- slightly transparent while idle;
- fully opaque on hover/focus-within;
- short CSS slide-in/slide-out from right;
- respect `prefers-reduced-motion`.

Mobile:

- near-full-width drawer/bottom sheet;
- fully opaque;
- no hover-dependent behavior.

---

## 10. Search interaction

Desktop:

- compact Search icon/control by default;
- click/focus expands into search field;
- expansion should not cause disruptive page reflow;
- collapse after Escape/close/completion where appropriate.

Mobile:

- Search icon opens a full-width search surface or sheet.

Search is an example of the wider low-distraction principle: functionality should be immediately available without permanently occupying visual space.

---

## 11. Contextual Help

Retain existing behavior:

- Help remains a global top-header capability;
- Help routes to documentation appropriate to the current page/context;
- constrained layouts may move the control into compact/overflow presentation;
- never replace contextual routing with only a generic documentation-home link.

---

## 12. Tables and analytical views

Desktop:

- use available width;
- comfortable row height and whitespace;
- avoid narrow centered analytical layouts;
- column chooser acceptable for secondary fields.

Constrained layouts should prefer, in order:

1. hide genuinely secondary columns;
2. expose hidden fields through expansion/detail;
3. transform suitable rows into cards;
4. use horizontal scrolling when tabular structure must remain intact.

The non-regression rule still applies: responsive hiding must preserve a reasonable access path to the underlying information.

---

## 13. Charts and visual communication

Reuse the existing StoX chart implementation/component foundation rather than adding a new charting library for stylistic variety.

Standardize:

- responsive sizing;
- theme support;
- legends;
- tooltips;
- loading/empty states;
- range selectors;
- hover/tap interaction;
- accessible exact values.

Prefer visual representation where useful:

- trend → line/sparkline;
- allocation → donut/bar;
- status → icon + semantic color + label;
- comparison → chart/heatmap;
- progress → progress indicator.

---

## 14. Forms and controls

- Persistent binary state → toggle.
- 2–4 mutually exclusive options → segmented/radio/selectable control.
- Large/searchable set → dropdown/combobox.
- Integer input → reuse existing StoX integer spinner.
- Link → navigation.
- Button → action.
- Destructive/high-risk action → icon + text where recognition/safety matters.

Use drawers/panes for contextual detail, accordions for local secondary detail, bottom sheets for mobile contextual actions, and modals only for short focused decisions.

---

## 15. Adaptive low-distraction chrome

Patterns include:

- collapsible left navigation;
- hover/focus reveal of secondary labels/actions;
- expandable Search;
- contextual right panes;
- hidden/collapsible footer;
- collapsible filters/advanced toolbars;
- secondary row/card actions shown on interaction.

Touch devices must always have an explicit tap-accessible equivalent; no capability may depend solely on hover.

---

## 16. Responsive architecture

Responsiveness is based on viewport, not device identity.

### Mobile

- single-column primary flow;
- drawer navigation;
- contextual tools become drawers/sheets;
- fewer persistent labels;
- breadcrumbs usually omitted;
- tables transform/hide secondary columns with detail access preserved;
- safety state/actions remain reachable.

### Tablet / small desktop

- 1–2 content columns;
- nav collapsed when needed;
- contextual panes usually overlay;
- secondary header/footer chrome reduces before core content is squeezed.

### Standard desktop

- expandable/persistent left nav;
- multi-column workspace;
- right utility rail;
- full tabs and filter rows where space permits.

### Ultrawide / 4K

- more parallel context;
- additional meaningful columns;
- wider charts/tables;
- side-by-side filters/detail where useful;
- readable max widths retained for prose/forms.

Mobile may use substantially different components from desktop; capability preservation matters more than geometrical similarity.

---

## 17. UI preference persistence

Stable UI-only preferences should persist in browser `localStorage`.

Examples:

- nav expanded/collapsed state;
- contextual pane state where useful;
- expanded/collapsed sections;
- Dashboard widget visibility/order;
- local display modes;
- table columns;
- chart range/display preferences;
- theme;
- other stable presentation preferences.

Requirements:

- version preference schema;
- namespace by application/page/component where appropriate;
- tolerate missing/stale/incompatible values;
- safe reset to defaults;
- migrate or discard incompatible preference data safely;
- never store auth tokens, execution authority, financial/business state or other sensitive state in this preference mechanism.

Where an existing component already owns its localStorage key/behavior, prefer compatibility/migration rather than arbitrarily replacing it.

---

## 18. Theme and visual system

Primary accent: `#1e90ff`.

Blue/white forms the base identity, while semantic colors remain distinct:

- green — success/positive;
- amber/yellow — warning/attention;
- red — error/danger/negative;
- blue — primary/information;
- neutral — secondary/inactive.

Never rely on color alone.

Themes:

- Light;
- Dark;
- System.

**Implementation rule:** use the existing `ThemeToggle` + `ThemeContext` theme mechanism. Do not introduce a competing theme selector or parallel persistence model as part of E4.

Visual shape:

- rounded corners preferred;
- subtle elevation/shadow where layering needs clarity;
- generous spacing;
- avoid heavy skeuomorphism.

---

## 19. Motion

Use CSS motion sparingly for:

- nav expand/collapse;
- drawer/pane slide;
- accordion expansion;
- hover/focus transition;
- button feedback;
- chart transition where useful.

Rules:

- short and functional;
- no decorative delay;
- avoid large hover-driven layout shift;
- respect `prefers-reduced-motion`.

---

## 20. Loading, empty, unknown and unavailable states

Prefer skeletons for content-heavy Dashboard cards, charts, tables and panes. Use small spinners for focused action waits.

Empty/unavailable states must distinguish:

- genuine zero;
- unknown;
- unavailable;
- incomplete;
- not applicable.

Do not manufacture `0` because data is missing.

---

## 21. Scroll-to-top

Use on sufficiently long pages/panes only.

- appears after meaningful scroll;
- unobtrusive placement;
- targets correct scroll container;
- icon + tooltip/accessibility label;
- smooth unless reduced-motion applies;
- may be omitted on constrained mobile if not useful.

---

## 22. Accessibility

Minimum requirements:

- keyboard-accessible controls;
- visible focus treatment;
- adequate touch targets;
- accessible names for icon-only actions;
- no capability dependent solely on hover;
- no state conveyed solely by color;
- reduced-motion support;
- tap-accessible alternative to hover tooltips/actions;
- accessible exact values for charts.

---

## 23. Frontend component architecture — FEAT-035

V6 does not authorize a big-bang frontend rewrite.

When E4 touches a surface:

1. inspect current implementation and capability inventory;
2. inspect whether suitable shared components already exist;
3. reuse/extend those components where reasonable;
4. migrate the touched surface incrementally;
5. compare old/new capability inventories;
6. do not rewrite unrelated functioning screens merely for uniformity.

Priority reusable foundations include:

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
- existing chart foundation;
- existing integer spinner;
- **existing `ThemeToggle` / `ThemeContext`;**
- Skeleton/EmptyState;
- ScrollToTop;
- PageHistoryRail;
- localStorage-backed preference utility/hook.

Component styling should derive from shared tokens rather than page-specific ad-hoc values.

---

## 24. Suggested design tokens

Implementation may map these into the current styling system rather than introduce a new framework.

- brand primary: `#1e90ff`;
- shared semantic success/warning/error/info tokens;
- light/dark surface/background/text tokens;
- consistent spacing scale, preferably an 8 px page-level rhythm with smaller subdivisions where required;
- consistent radius family;
- shared short/medium motion durations/easing.

---

## 25. Non-goals

E4 does **not** introduce:

- new investment/accounting semantics;
- new execution authority;
- big-bang frontend rewrite;
- new chart library without demonstrated need;
- duplicate theme selector/theme system;
- general-purpose free-form Dashboard designer;
- silent removal of current Dashboard information;
- silent removal of existing links, shortcuts, LLM prompts or other convenience features;
- mandatory persistence of every transient UI gesture;
- desktop-only capability with no mobile-equivalent path;
- admin impersonation or weakening of authorization boundaries.

---

## 26. Acceptance criteria

### Functional preservation

- every materially redesigned page has a before/after capability inventory;
- existing metrics, actions, links, shortcuts and helper affordances are accounted for;
- removals have explicit PO approval;
- presentation-only relocation/change does not require repeated PO review;
- small convenience features, including external-analysis/LLM prompts and handy links, are retained wherever reasonably possible.

### Dashboard

- existing Dashboard information baseline is preserved unless PO-approved otherwise;
- redesign may reorganize/visualize information without changing its domain ownership;
- existing Dashboard preferences and useful actions are preserved or migrated;
- Emergency controls cannot be hidden as widgets.

### Shell

- responsive shared shell exists;
- left nav has expanded/collapsed/mobile forms;
- contextual Help behavior is preserved;
- expandable low-distraction Search is implemented;
- right utility area standardized;
- footer can de-emphasize/hide without losing critical capability.

### Navigation/components

- common page anatomy and tab language are used;
- segmented controls used consistently for small peer choices;
- existing chart foundation reused;
- existing integer spinner reused;
- existing `ThemeToggle`/`ThemeContext` reused;
- avoidable duplicate components are removed/consolidated when touched.

### Responsive behavior

- mobile is purpose-designed rather than simply scaled desktop;
- small desktop removes secondary chrome before core content becomes cramped;
- ultrawide uses width for useful parallel context;
- responsive transformations preserve capabilities.

### Preferences

- stable UI preferences use a versioned `localStorage` approach;
- existing preference keys/behavior are migrated or retained safely;
- security/business state excluded;
- safe reset/fallback behavior exists.

### Quality

- skeleton/empty/unavailable states consistent;
- accessibility requirements met;
- reduced-motion respected;
- important controls remain easy to reach without visual clutter.

### Safety compatibility

- Emergency Halt and related controls remain globally reachable where required;
- responsive transformations cannot remove execution-safety capability.

---

## 27. Freeze status

**FROZEN / DECIDED — PO approved on 2026-09-08.**

This specification is the canonical V6 E4 UX architecture. Ordinary spacing, styling, component-internal and implementation details may proceed as engineering decisions provided they remain within this specification. Any change that materially alters the frozen product behavior, removes preserved functionality, or changes the stated UX architecture requires explicit PO review before implementation.