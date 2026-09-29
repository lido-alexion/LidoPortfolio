# StoX V9 Customizable Summary Fields & Dashboard Layouts Specification

| Field | Value |
|---|---|
| **Epic** | `V9-UX-003` — Customizable Summary Fields & Dashboard Layouts |
| **Version target** | V9 |
| **Status** | FROZEN — implementation-ready |
| **Owner** | Product / Architecture |
| **Parent register** | `docs/archive/specs/LidoPortfolio-V9-Wishlist.md` |

## 1. Product intent

Allow investors to personalize the main StoX dashboard without turning the product into a generic page-builder. Customization must remain bounded, predictable, forward-compatible, accessible, and safe across product evolution.

V9-UX-003 applies to the **main dashboard only**.

## 2. Scope

In scope:

- summary-field visibility and order on the main dashboard;
- dashboard-card visibility, order, supported size preset and layout position;
- at least one dashboard card must remain visible;
- a small StoX-defined core summary set must always remain visible;
- drag-and-drop rearrangement with accessible move controls;
- separate desktop and mobile layout variants;
- one unnamed/current working layout stored locally;
- multiple named dashboards stored server-side and account-scoped;
- named dashboard lifecycle: create, rename, edit, duplicate, delete, export/import;
- optional persistent lock/unlock state;
- JSON portability for dashboard definitions;
- forward-compatible migration when cards/features are added, changed or retired.

Out of scope:

- customization of non-dashboard pages;
- arbitrary custom widgets or user-authored formulas;
- free-form unconstrained resizing;
- dashboard version history;
- public/shared live dashboards or sharing account-specific data;
- favorites/manual ordering for named dashboards.

## 3. Persistence model

### 3.1 Unnamed working layout

The unnamed/current dashboard is device/browser-specific and stored in `localStorage`.

Requirements:

- store both summary-field preferences and dashboard-card configuration locally;
- scope local-storage keys by authenticated account/user identifier so users sharing a browser do not inherit each other's layout;
- maintain separate desktop and mobile variants;
- persist lock state locally;
- preserve schema/version metadata so local definitions can be migrated.

### 3.2 Named dashboards

Named dashboards are account-scoped and stored on the server.

Each named dashboard contains:

- name and stable dashboard identifier;
- desktop layout variant;
- mobile layout variant;
- supported shared card/field configuration;
- lock state;
- dashboard schema/version;
- created/updated timestamps.

Named dashboards auto-save. The UI must expose clear `Saving`, `Saved`, and `Failed` state.

No version history is required; the server stores the latest saved definition only.

## 4. Default-loading behavior

On dashboard load:

1. If an unnamed local layout exists for the current account/device, use it.
2. Otherwise, if the account has a named dashboard marked as default, use that named dashboard.
3. Otherwise, use the current StoX factory/default dashboard.

A one-click **Reset to defaults** action restores the current StoX default configuration for the active unnamed layout.

## 5. Dashboard-card customization

Users may:

- show/hide supported cards;
- reorder cards;
- choose among card-specific supported size presets such as Small, Medium, Large/Full-width;
- lock/unlock a dashboard to prevent accidental rearrangement/resizing.

Rules:

- at least one dashboard card must remain visible;
- each card exposes only size presets that make sense for its content;
- desktop drag-and-drop must have accessible keyboard/mobile fallback controls;
- editing is bounded by the StoX grid/layout model rather than arbitrary CSS positioning.

## 6. Summary-field customization

The dashboard summary area supports field visibility and ordering.

Rules:

- StoX defines a small mandatory core set that cannot be hidden;
- non-core supported fields may be shown, hidden and reordered;
- all summary-field settings participate in the same local/named dashboard persistence model.

## 7. Desktop and mobile variants

Desktop and mobile layouts are independently configurable.

A named dashboard is still one logical dashboard containing both variants rather than separate named dashboard records.

The unnamed layout likewise stores separate desktop/mobile variants under the same account-scoped local definition.

## 8. Named dashboard lifecycle

Users may:

- create a new named dashboard;
- rename it;
- edit it with auto-save;
- duplicate it into another named dashboard;
- delete it after confirmation;
- export it as JSON;
- import a JSON definition as a new named dashboard;
- set one named dashboard as the account default;
- duplicate a named dashboard into the unnamed local working layout;
- promote the current unnamed local layout using **Save as named dashboard**.

Named dashboards use a simple alphabetical/list ordering. No favorites or manual ordering are required.

## 9. JSON import/export

Dashboard JSON represents configuration only. It must not include live portfolio/account data.

Requirements:

- include an explicit dashboard schema/version identifier;
- validate imported JSON before persistence;
- import always creates a new named dashboard and never silently overwrites an existing one;
- compatible configuration is preserved;
- unsupported/unknown cards or fields do not fail the entire import;
- unsupported elements are skipped/disabled with a clear compatibility warning;
- exported definitions remain account-data-free and portable.

## 10. Forward compatibility and product evolution

Saved layouts must remain flexible as StoX evolves.

### New cards/features

When StoX introduces a new dashboard card:

- automatically add it to existing unnamed and named dashboards using sensible StoX default-placement rules;
- preserve all existing user customization;
- let the user subsequently move, resize or hide the new card.

### Retired cards

When a card is permanently retired:

- remove it automatically from saved definitions;
- preserve the rest of the layout unchanged;
- do not render dead placeholders or make the entire dashboard incompatible.

### Card schema/configuration changes

When an existing card's configuration model changes:

- migrate compatible settings automatically;
- drop only obsolete/incompatible properties;
- apply current sensible defaults where needed;
- keep the dashboard usable without requiring manual migration.

## 11. Locking

Each layout supports optional lock/unlock state.

- Locking prevents accidental drag/drop/resizing or equivalent structural edits.
- Lock state persists with the layout.
- One lock state is shared by desktop/mobile variants of the same logical layout.

## 12. Implementation guidance

Implementation-level decisions should use recommended defaults:

- use a responsive grid library that supports bounded card sizes and deterministic serialization;
- debounce server auto-save for named dashboards and guard against stale-write races;
- validate all server-stored dashboard definitions against a typed schema;
- version both local and server-side dashboard definitions;
- provide deterministic migrations for known older schema versions;
- use account-scoped localStorage keys and clear/ignore another user's local state after account switch;
- keep named-dashboard authorization account-scoped;
- avoid storing derived/live portfolio values inside layout configuration.

## 13. Acceptance criteria

V9-UX-003 is complete only when:

- the main dashboard supports card visibility/order/size customization;
- at least one card always remains visible;
- mandatory StoX core summary fields cannot be hidden;
- summary-field visibility/order is configurable;
- desktop and mobile variants are independently configurable;
- unnamed local layouts persist correctly per account/browser;
- multiple named dashboards persist server-side and sync across devices;
- named dashboard CRUD/duplicate/default/auto-save flows work;
- named dashboards can be duplicated into local working copies and local layouts can be promoted to named dashboards;
- dashboard JSON export/import works with schema validation and no account data leakage;
- new cards are automatically added to old layouts without destroying existing customization;
- retired cards and changed card schemas migrate safely;
- lock state persists correctly;
- reset-to-default behavior restores current StoX defaults;
- drag-and-drop has an accessible fallback;
- all authorization and persistence behavior has automated tests.

## 14. Frozen PO decisions

- Main dashboard only.
- Customize summary fields plus dashboard card visibility/order/layout.
- Account-wide product concept, but unnamed preferences are device/browser-local.
- Reset to current StoX defaults is required.
- At least one dashboard card must remain visible.
- StoX defines a mandatory core summary-field set.
- Use supported card-size presets, not free-form resizing.
- Drag-and-drop plus accessible fallback controls.
- Desktop and mobile layouts are separate variants.
- Summary-field and unnamed dashboard preferences use account-scoped `localStorage`.
- Support multiple named dashboards stored server-side.
- New device without local state loads the account-default named dashboard if one exists, otherwise factory default.
- Dashboard sharing is definition-only through JSON export/import.
- Import creates a new named dashboard and never silently overwrites.
- Named dashboards support full lifecycle management.
- One named dashboard contains both desktop and mobile variants.
- A named dashboard can be set as account default.
- Named dashboard edits auto-save.
- No dashboard version history.
- JSON includes explicit schema/version metadata.
- Unsupported imported elements are skipped/flagged rather than failing the whole import.
- Newly introduced StoX cards are automatically added to existing layouts.
- Retired cards are automatically removed while preserving the remaining layout.
- Changed card schemas migrate compatible settings and use defaults only where required.
- Optional lock/unlock is supported and persisted.
- Named dashboards may be duplicated to an unnamed local working layout.
- Unnamed layouts may be promoted to new named dashboards.
- Named dashboard selector uses simple alphabetical/list ordering.
