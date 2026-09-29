# V9 Combo Chart Support Specification

| Field | Value |
|---|---|
| **Epic** | V9-VIZ-001 — Combo Chart Support |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Version** | V9 |
| **Product** | StoX / LidoPortfolio |
| **Scope owner** | Stock Details visualization experience |

## 1. Purpose

V9-VIZ-001 extends the existing StoX Stock Details chart experience with a curated library of StoX-defined multi-series analytical charts.

This epic is **not** a runtime chart builder. Users do not arbitrarily choose metrics, axes, chart types, or metric combinations. StoX defines and codes each supported chart preset, including its series, visual types, axis behavior, cadence-alignment rules, labels, units and availability requirements.

The goal is to provide richer visual comparison while preserving the existing StoX chart interaction model and avoiding unnecessary UI proliferation.

## 2. Product principles

1. Reuse existing chart components and interaction patterns as much as practical.
2. Preserve visual and behavioral consistency across Stock Details and existing multi-series charts.
3. Show one selected chart at a time rather than rendering the entire preset library simultaneously.
4. Keep chart composition deterministic and product-defined.
5. Preserve single-metric analytical views; combo charts supplement rather than replace them.
6. Never imply data where historical coverage is insufficient.

## 3. Existing components to reuse

Implementation must inspect and reuse/refactor the current StoX chart implementations wherever practical rather than creating a parallel chart stack.

### 3.1 Stock Details Price + Volume chart

Reuse its established patterns for:

- combined price/volume presentation;
- in-chart range selection;
- in-chart sampling/frequency selection;
- responsive Stock Details chart-card behavior;
- mobile/touch interaction where applicable.

### 3.2 Indices multi-line comparison chart

Reuse its established patterns for:

- multiple simultaneous series;
- legend-driven series visibility;
- selectable/show-hide line behavior;
- responsive chart rendering and interaction conventions.

Refactoring shared primitives is preferred where it reduces duplication and preserves UX consistency.

## 4. Placement and interaction model

Combo charts are integrated into the **existing Stock Details chart area** alongside existing single-metric charts.

StoX must not create a separate parallel “Combo Charts” page or permanently render all combinations.

Exactly one preset chart is shown at a time.

### 4.1 Preset selector

The primary selector is a **dropdown**.

Preset options are grouped by analytical category, for example:

- **Market activity** — Price + Volume;
- **Valuation** — Price + P/E, Price + P/B, Price + P/S;
- **Fundamentals** — Price + EPS, Price + Revenue, Price + Net Profit.

The exact initial catalogue may be refined during implementation only within this frozen product boundary.

### 4.2 Previous / next navigation

Provide left/right arrow controls adjacent to the selector, especially for touch-device usability.

Behavior:

- arrows cycle through presets;
- navigation wraps cyclically;
- next from the final preset returns to the first;
- previous from the first returns to the final;
- arrow navigation and dropdown state remain synchronized;
- keyboard accessibility must be preserved.

Unavailable/disabled presets must not create a broken selected state; cycling should resolve to a valid available preset according to implementation-safe rules.

## 5. Initial preset scope

V9 initially supports a focused mix of market-activity, valuation and fundamental comparisons rather than a broad arbitrary-combination library.

Expected starting presets include at least:

- Price + Volume;
- Price + P/E;
- Price + P/B;
- Price + P/S;
- Price + EPS;
- Price + Revenue;
- Price + Net Profit.

Additional StoX-defined presets may be added when they are analytically coherent and supported by available historical data, without turning the feature into a user-configurable chart builder.

## 6. Chart rendering rules

### 6.1 Preset-specific chart types

Chart rendering is defined per preset rather than forced into line + line.

Examples:

- Price + P/E may use line + line;
- Price + Revenue may use price line + revenue bars/line as analytically appropriate;
- Price + Volume preserves the existing price line/candlestick + volume-style behavior where applicable.

Users do not choose the chart type.

### 6.2 Axis behavior

Dual Y-axes are used only where units or scales materially differ.

For every preset, StoX defines whether:

- series share an axis; or
- left/right axes are required.

Users do not configure axes.

### 6.3 Tooltips

Use synchronized tooltips across visible series.

A hover/tap point should show:

- aligned date/period;
- each visible series value;
- clear series label;
- relevant unit/format.

Where source cadences differ, the tooltip follows the preset's deterministic cadence/alignment rule.

### 6.4 Legend visibility toggles

Legend items are interactive.

Users may temporarily show/hide individual series for visual inspection.

This does not alter the preset definition, default chart, or persisted chart configuration.

The implementation should reuse the existing indices multi-line visibility behavior where practical.

## 7. Range and sampling controls

Range and sampling/frequency controls remain **inside the chart component**, following the established Stock Details Price + Volume pattern.

Do not introduce duplicated page-level controls solely for combo charts.

Each supported preset should honor the existing Stock Details range/frequency model where the underlying data supports it.

When series have different native cadences, StoX uses deterministic preset-specific alignment rules. These are implementation-defined but must be reproducible, explainable and must not fabricate intermediate fundamentals.

## 8. Preset catalogue and availability

StoX maintains one canonical global preset catalogue.

The catalogue does not dynamically disappear or reorder by stock.

Each preset has a per-stock availability state:

- available;
- disabled because historical data is insufficient;
- temporarily unavailable for a clearly stated data reason.

Unavailable presets remain visible in the dropdown but disabled with a concise explanation such as `Insufficient historical P/E data`.

Partial data must not be presented when coverage is too sparse to support a meaningful comparison. Exact sufficiency thresholds are implementation-level rules and should be centralized and deterministic.

## 9. Ordering, favorites and defaults

### 9.1 Ordering

Preset ordering is StoX-defined.

Users cannot manually reorder presets.

### 9.2 Favorites

There is no favorites or pinning system for combo charts.

### 9.3 User-selected default

A user may set exactly one available combo-chart preset as their **default chart**.

The chosen default is stored account-wide and follows the user across devices.

Behavior when Stock Details opens:

1. If the user has explicitly selected a default and it is available for the current stock, open that preset.
2. If the selected default is unavailable for the stock, fall back to **Price + Volume**.
3. If no explicit user default exists, use **Price + Volume**.
4. If Price + Volume itself is unavailable, use the first valid available preset as a safety fallback.

An explicit default always wins when opening Stock Details. A previously viewed chart must not silently override it on a new Stock Details open.

The UI should provide a clear, compact `Set as default` interaction without introducing favorites semantics.

## 10. Single-metric charts

Existing individual metric charts remain available.

Combo charts do not replace the simple single-metric analytical views.

The Stock Details visualization area should expose both existing individual charts and the new StoX-defined combo presets in a coherent interaction model.

## 11. Responsive and accessibility requirements

The combo-chart experience must work across existing supported desktop and mobile layouts.

At minimum:

- dropdown remains touch-friendly;
- previous/next arrows have adequate touch targets;
- legend visibility toggles work via touch and keyboard where applicable;
- controls expose accessible names/state;
- disabled presets expose the reason in an accessible form;
- synchronized tooltip behavior remains usable on touch devices;
- focus order and keyboard interaction follow existing StoX conventions.

## 12. Data integrity and cadence alignment

Combo charts consume canonical StoX data already available through existing historical price/fundamental pipelines.

They must not create new authoritative values solely for visualization.

For metrics with lower-frequency observations such as quarterly fundamentals:

- preserve the source observation semantics;
- align to the display timeline deterministically;
- do not visually imply daily fundamental observations if none exist;
- clearly preserve units and period semantics.

Any forward-fill/step-line/period anchoring behavior required for a preset must be deterministic and documented in code/tests.

## 13. Implementation guidance

The implementation should prefer a reusable preset registry/configuration structure containing product-defined metadata such as:

- preset ID;
- display label;
- category;
- constituent series;
- source metric IDs;
- chart renderer/type per series;
- axis assignment;
- unit/format;
- cadence/alignment policy;
- data sufficiency rule;
- tooltip/legend labels;
- ordering.

This registry is implementation configuration, not a runtime user-authored artifact.

Shared chart primitives should be extracted from existing components where that improves consistency and maintainability without destabilizing existing views.

## 14. Acceptance criteria

V9-VIZ-001 is complete when all of the following hold:

1. Stock Details exposes StoX-defined combo-chart presets within the existing chart experience.
2. Only one combo preset is rendered at a time.
3. A grouped dropdown selects presets.
4. Previous/next arrows cycle through presets and wrap cyclically.
5. Price + Volume remains the fallback system default.
6. Users can set one account-wide default preset.
7. The explicit user default wins on new Stock Details opens when available.
8. Unavailable presets remain visible but disabled with an explanation.
9. Range and sampling controls remain inside the chart component.
10. Preset-specific chart types and deterministic dual-axis behavior are supported.
11. Synchronized tooltips show aligned visible-series values.
12. Legend interactions allow temporary series show/hide.
13. Existing Price + Volume and Indices multi-series chart behavior/components are reused or shared-refactored where practical.
14. Existing single-metric charts remain available.
15. Mobile/touch and keyboard interactions remain usable.
16. Tests cover preset selection, cyclic navigation, availability, fallback/default precedence, legend visibility, tooltip alignment and range/frequency interaction.

## 15. Out of scope

The following are explicitly outside V9-VIZ-001:

- arbitrary user-selected metric combinations;
- runtime chart-builder UI;
- user-selected axes;
- user-selected renderer/chart type;
- preset favorites or pinning;
- user-defined preset ordering;
- saving multiple custom chart definitions;
- replacing the existing single-metric chart library;
- generating synthetic historical fundamentals solely to make a chart render.

## 16. Frozen product decisions

The following PO decisions are frozen for V9:

- combo charts are hard-coded/preset by StoX;
- integrated with existing Stock Details charts;
- initial scope includes valuation + fundamental combinations;
- one chart at a time;
- dropdown selector plus cyclic sideways arrows;
- reuse current Price + Volume and Indices multi-series components/patterns;
- range/sampling controls remain inside the chart;
- preset-specific renderer types;
- dual axes only when materially required;
- synchronized tooltips;
- legend-driven temporary series visibility;
- one canonical catalogue with per-stock availability;
- unavailable presets visible but disabled with explanation;
- catalogue grouped by analytical category;
- no favorites and no user reordering;
- one account-wide user-selected default;
- Price + Volume is fallback/default when the user has not selected one;
- explicit user default wins whenever Stock Details opens;
- unavailable explicit default falls back to Price + Volume.

Remaining implementation-level choices may be resolved by engineering as long as they preserve these frozen behaviors and existing StoX product principles.
