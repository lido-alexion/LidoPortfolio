# AUD-013 - Dashboard / Redesign Preservation Assessment

## 1. Finding Recap

AUD-013 asks whether the Dashboard preserved the information, actions, hierarchy, preferences and important state handling required by V6 E4. AUD-004 already reconstructed the broad non-regression inventory. This assessment narrows that evidence to Dashboard-specific source continuity, state semantics and runtime-sensitive presentation.

No Dashboard capability regression was found in static source review. The historical Dashboard capabilities are directly present in the current page or its retained child components. Browser verification remains necessary for hierarchy, responsive access, chart readability and real degraded-data behavior.

## 2. Historical Baseline

The defensible pre-redesign Dashboard baseline is `e5880a7` (2026-08-19), the last meaningful Dashboard implementation before the React/Vite migration boundary `1ee388c` (2026-09-04). Earlier Dashboard changes that establish the V6-era information architecture include `5b91046`, `287d6cb` and `0625a64`.

`233a427` is retained only as the post-V6/pre-audit-remediation stability checkpoint. `DashboardPage.jsx` is unchanged between `233a427` and current `master` except for later readiness/reconciliation mounts; it cannot substitute for the pre-redesign baseline.

Historical source reviewed:

- `e5880a7:app/resources/js/src/pages/DashboardPage.jsx`
- historical Dashboard child components and helpers at `e5880a7`
- current `app/resources/js/src/pages/DashboardPage.jsx`
- current Dashboard cards, gauges, calendar, pattern, readiness and reconciliation components
- current dashboard cache and shell tests

## 3. Dashboard Capability Inventory

The AUD-004 Dashboard inventory contains 18 atomic rows. Seventeen existed at the historical baseline and one readiness/reconciliation row was added later. The table below records the Dashboard-specific evidence.

| ID | Capability | Historical evidence at `e5880a7` | Current equivalent | Evidence type | Result |
| --- | --- | --- | --- | --- | --- |
| DB-001 | Portfolio value | Dashboard KPI card | Portfolio KPI card | Exact source continuity | `PRESERVED` |
| DB-002 | Invested value | Dashboard KPI card | Portfolio KPI card | Exact source continuity | `PRESERVED` |
| DB-003 | Total gain/loss and percentage | KPI plus signed percentage | Same KPI formatting | Exact source continuity | `PRESERVED` |
| DB-004 | XIRR | Dashboard KPI | Same KPI with explicit unavailable display | Exact source continuity | `PRESERVED` |
| DB-005 | Available/investable cash | Cash card and percentage context | Same cash card | Exact source continuity | `PRESERVED` |
| DB-006 | Top gainer/loser | `DashboardTopMoverCard` and period toggle | Same component and persisted period choice | Exact source continuity | `PRESERVED` |
| DB-007 | Positions count | Portfolio analytics card | Same card, including oversized-position link | Exact source continuity | `PRESERVED` |
| DB-008 | Diversification | Portfolio analytics card and gradient bar | Same card/helper | Exact source continuity | `PRESERVED` |
| DB-009 | Average relative strength | Portfolio analytics and relative-strength table | Same metric/table | Exact source continuity | `PRESERVED` |
| DB-010 | Market Health | Market analytics summary/gauges | Same summary with decision zone/exposure | Equivalent current implementation | `PRESERVED` |
| DB-011 | Active alerts | Alert table, acknowledge and clear-all | Same table/actions | Exact source continuity | `PRESERVED` |
| DB-012 | Calendar/events | Upcoming calendar card and Calendar link | Same card/link | Exact source continuity | `PRESERVED` |
| DB-013 | Holding pattern signals | Pattern table, sketches and guide link | Same table/sketch/guide path | Exact source continuity | `PRESERVED` |
| DB-014 | Relative Strength view | Relative-strength table and benchmark label | Same table/benchmark label | Exact source continuity | `PRESERVED` |
| DB-015 | Allocation | Allocation table/visualization and anchor | Same card/table/anchor | Exact source continuity | `PRESERVED` |
| DB-016 | Growth and unrealized P/L charts | Two portfolio-history charts | Same charts plus snapshots/rebuild actions | Exact source continuity | `PRESERVED` |
| DB-017 | Refresh/rebuild/snapshots | Refresh, rebuild, snapshots and cache behavior | Same actions and cache helpers | Exact source continuity | `PRESERVED` |
| DB-018 | Readiness/reconciliation | Not in the pre-redesign baseline | `KiteReadinessCard`, paper notice and `PortfolioReconciliationCard` | Post-V6 addition; checked for non-interference only | `PRESERVED` as a later addition, not historical evidence |

Historical rows reviewed: **17**. Post-V6 additions reviewed for current reachability: **1**. Confirmed regressions: **0**. Preserved-differently rows: **0** for the historical Dashboard capabilities; the current page retains the same meaningful outcomes rather than moving them into a different workflow.

## 4. KPI / Metric Preservation

| KPI / metric | Historical | Current | Location changed? | Static result | Runtime check |
| --- | --- | --- | --- | --- | --- |
| Portfolio value | Visible primary Portfolio card | Visible Portfolio card | No material change | Preserved | Confirm visibility at all target widths |
| Invested value | Visible primary Portfolio card | Visible Portfolio card | No material change | Preserved | Confirm no wrapping/occlusion |
| Total gain/loss | KPI with percentage | KPI with percentage | No material change | Preserved | Confirm negative/positive states are readable |
| XIRR | KPI | KPI, `N/A` when absent | No material loss | Preserved | Verify populated and unavailable data |
| Cash available | Cash card | Cash card with percentage context | No material loss | Preserved | Verify empty/new portfolio behavior |
| Positions | Portfolio analytics card | Same card | No material change | Preserved | Verify zero positions is not hidden |
| Diversification | Portfolio analytics card | Same card/gradient | No material change | Preserved | Verify text/value remains understandable without color |
| Average relative strength | Card/table context | Same card/table context | No material change | Preserved | Verify benchmark label and missing benchmark |
| Top mover values | Gainer/loser card | Same card/toggle | No material change | Preserved | Verify all-time/latest-day toggle |
| Market Health score/status | Market analytics section | Summary score, status, zone and exposure | Enriched, not reduced | Preserved | Verify collapsed diagnostics retain summary |
| Allocation percentages/values | Allocation visualization/table | Same visualization/table | No material loss | Preserved | Verify table access on constrained widths |
| Portfolio value/invested history | Growth chart | Same chart | No material change | Preserved | Verify chart axes/legend/readability |
| Unrealized P/L history | P/L chart | Same chart | No material change | Preserved | Verify zero line and unavailable history |

The current source continues to render unavailable scalar values as `N/A`, `—` or by omitting the card rather than converting absent KPI values to zero. One chart-specific risk remains: `growthData` maps missing numeric fields with `Number(point.portfolio_value || 0)` and the equivalent invested-value expression. This is a static data-semantics concern for malformed partial chart rows, not evidence of a historical redesign regression.

## 5. Action Preservation

| Action | Historical behavior | Current behavior | Result |
| --- | --- | --- | --- |
| Refresh dashboard | Clears dashboard cache and reloads | Same action and cache path | `PRESERVED` |
| Rebuild portfolio history | Confirmation, rebuild mutation and refresh | Same confirmation/mutation/refresh | `PRESERVED` |
| View snapshots | Link from growth section | Same `/portfolio/snapshots` link | `PRESERVED` |
| Acknowledge alert | Row action | Same row action | `PRESERVED` |
| Clear all alerts | Alert card action | Same action | `PRESERVED` |
| Jump to allocation | Oversized-position anchor | Same anchor/scroll action | `PRESERVED` |
| Open Calendar | Calendar card action | Same route action | `PRESERVED` |
| Pattern details/guide | Pattern links and guide action | Same pattern/guide links | `PRESERVED` |
| Market breadth/depth | Market analytics link | Same `/market-depth` link | `PRESERVED` |
| Stock analysis helper | `AnalyseStockButton` in Dashboard stock rows | Same helper in alert/pattern rows | `PRESERVED` |
| Admin price sync | Admin-only Dashboard action | Same role-gated action | `PRESERVED` |
| Readiness/reconciliation actions | Not historical baseline capability | Connect Kite, run reconciliation and evidence actions | Post-V6 addition; non-interference check only |

No historical Dashboard action was found missing. Exact discoverability and touch access remain browser checks.

## 6. Warnings and Safety State

Static review found the following information paths:

- reserve shortfall warning is rendered as a `role="alert"` warning;
- paper portfolios receive an explicit warning that broker submission and reconciliation are unavailable;
- automatic-mode broker readiness uses a prominent alert and Connect Kite action;
- reconciliation exposes overall/holdings/funds status, execution-blocked state, failed-sync state and evidence tables;
- Market Health has textual score, status, decision zone, suggested exposure and contributor labels in addition to color/icons;
- active alerts expose message, condition, action and context text, not only severity color.

These paths preserve safety/status information without making color the sole carrier. Execution correctness remains owned by AUD-015/AUD-011; this review only checks Dashboard presentation and access.

## 7. Loading / Empty / Degraded States

| State | Current behavior | Assessment |
| --- | --- | --- |
| Initial dashboard load | Plain `Loading dashboard...` text until primary data arrives | Functionally present; status semantics should be browser/accessibility checked |
| Primary dashboard failure | Warning text `Failed to load dashboard` | Error is visible; the container lacks an explicit `role="alert"` in current source, a narrow static accessibility concern |
| Cached data | Cached dashboard/pattern data renders with “Last refreshed” context | Preserved cache behavior; runtime freshness wording check remains |
| Pattern scan failure | Pattern rows reset to empty and the table can show its empty message | Potential unavailable-vs-empty conflation; no historical regression established |
| Calendar failure | Calendar events reset to `[]`, then card can show “No upcoming events” | Potential unavailable-vs-empty conflation; no historical regression established |
| Empty portfolio/history | KPI/table/chart fallbacks and rebuild guidance render | Verify with a new/empty portfolio |
| Missing analytics | Analytics sections are conditionally omitted or show textual placeholders | Verify that omission does not hide required summary information |
| Missing chart history | Explicit “No portfolio history yet” and rebuild guidance | Preserved and actionable |

These are targeted verification/remediation observations, not changes made in this pass. They do not justify reopening AUD-004 without evidence that a historical capability is lost.

## 8. Preferences

| Preference | Storage/current behavior | Result |
| --- | --- | --- |
| Top mover period | `localStorage` key `portfolio_dashboard_top_mover_period`, constrained to `all_time`/`latest_day` | Preserved; value is scoped to the feature key |
| Market diagnostics collapsed state | Versioned `sessionStorage` key `portfolio_dashboard_market_diagnostics_collapsed_v2` | Preserved; explicit default is collapsed and malformed/unavailable storage falls back safely |
| Data table column preferences | Dashboard table storage keys such as `dashboard-alerts-v3`, `dashboard-pattern-signals-v1`, `dashboard-rs-v2` and allocation key | Preserved feature preferences; verify user/profile isolation in browser if shared-browser concerns matter |
| Dashboard cache | User/profile keyed cache helpers | Preserved; current source scopes cache by user and profile |

No historical Dashboard preference was found silently removed. The current collapse control exposes `aria-expanded`, `aria-controls`, title and label.

## 9. Responsive Static Review

Static evidence is favorable but not a substitute for browser inspection:

- Bootstrap grid classes provide `col-12`, `col-md-6`, `col-lg-4/6` transitions for cards and panels;
- action rows use `flex-wrap` and gap classes;
- chart containers define stable height/min-height and responsive width;
- tables use the shared responsive table/card components;
- diagnostics use smaller grid tracks on constrained widths;
- readiness and reconciliation actions use wrapping flex containers;
- no Dashboard-specific fixed right-edge control was introduced; the shared RightUtilityRail remains outside the main workspace.

No static desktop-only Dashboard action without an alternative path was found. Exact overflow, chart label collision, touch targets and interaction with the utility rail require runtime verification.

## 10. Accessibility Static Review

Positive evidence:

- actions are native `button` or React Router `Link` elements;
- the diagnostics toggle has an accessible label, `aria-expanded` and `aria-controls`;
- alerts and safety warnings use visible text and, where needed, alert semantics;
- chart legends/tooltips provide some textual context;
- table headers and row actions are provided through table components;
- pattern/stock links retain text labels alongside analysis controls.

Targeted static concerns:

1. The primary `loadError` warning is a styled alert without an explicit `role="alert"`.
2. Calendar and pattern request failures collapse to empty lists, so screen-reader users may receive “No upcoming events” or an empty table rather than an unavailable state.
3. Chart accessibility and keyboard/readability cannot be established fully from source; runtime screen-reader and keyboard checks remain required.

No confirmed missing primary Dashboard action, inaccessible icon-only Dashboard action, or color-only safety state was found.

## 10A. Targeted State-Semantics Remediation Outcome

The bounded remediation addressed the three static concerns above without changing Dashboard calculations, APIs, layout, or historical capability counts:

- primary Dashboard load failure now uses explicit `role="alert"` semantics;
- Pattern and Calendar requests retain independent loading, successful-empty, and failed/unavailable states, so optional-section failures do not become valid empty results or fail the whole Dashboard;
- Dashboard chart normalization preserves actual zero values, converts valid numeric strings to numbers, and retains missing or invalid values as unavailable (`null`); chart tooltips display `—` for unavailable values rather than `₹0`.

Focused state/component tests and the Node/source suite pass. AUD-004 is unchanged. Remaining verification is browser/runtime-only: responsive geometry, touch behavior, chart and tooltip readability, real screen-reader behavior, utility-overlay coexistence, and deployed-bundle reachability.

## 11. Runtime Scenario Matrix

| Scenario | Required data/state | Dashboard checks |
| --- | --- | --- |
| Normal populated portfolio | KPIs, positions, analytics, alerts, events and chart history | Primary summary order, all tables/actions, chart legends and links |
| Empty/new portfolio | No holdings, transactions or history | No false zeros, useful empty copy, rebuild/snapshot path and no broken charts |
| Missing/stale market data | Missing prices/benchmark/pattern history | `N/A`/unavailable semantics, no fabricated zero, relative-strength explanation |
| Active alerts | At least one actionable alert | Alert text, acknowledge, clear-all and stock analysis helper |
| Reserve/cash warning | Reserve shortfall response | Warning prominence and text; no color-only interpretation |
| Reconciliation warning/block | Safe simulated mismatch/attention response | Status, blocking explanation, evidence action and no overlay obstruction |
| No calendar events | Empty upcoming-events response | Correct empty state and Calendar navigation |
| Partial chart/analytics data | Missing or incomplete chart/market fields | No misleading zero lines, readable labels, graceful omission/placeholder |

## 12. Cross-Audit Boundaries

- AUD-004 owns broad historical non-regression and remains accepted; this document does not modify it.
- AUD-003 owns general page anatomy, DataState and accessibility foundations; the Dashboard observations above are limited to Dashboard-specific evidence.
- AUD-001/AUD-002/AUD-016 own Page History, Contextual Notes and Global Search. Runtime checks must ensure they do not obscure Dashboard actions, but their semantics are not reopened here.
- AUD-005/AUD-008 own strategy/accounting and analytics semantics.
- AUD-011/AUD-015 own reconciliation, halt and execution safety correctness; this document checks only visible Dashboard status/action access.

## 13. Confirmed Regressions

No Dashboard capability or historical information regression was confirmed.

The readiness/reconciliation surfaces are later additions, not missing historical features. The three static state/accessibility observations in §§7 and 10 are bounded implementation concerns and do not establish a V6 non-regression failure.

## 14. Runtime Verification Checklist

Run the following with a real browser and representative data:

### Desktop: 1440px+

1. Confirm KPI, cash and warning hierarchy above secondary analytics.
2. Exercise refresh, alert actions, snapshots, rebuild, pattern, breadth, Calendar and stock-analysis links.
3. Expand/collapse diagnostics and confirm the summary remains visible.
4. Open Page History, Notes and Global Search; confirm no Dashboard action is obscured.
5. Confirm charts, tables, legends, tooltips and long labels remain readable.

### Desktop boundary: approximately 1200px

1. Confirm header/sidebar/utility rail coexistence.
2. Confirm action rows wrap without hiding refresh/admin controls.
3. Confirm allocation, alerts and relative-strength tables remain reachable.

### Tablet: approximately 768-1024px

1. Confirm card stacking and chart widths.
2. Confirm table overflow/detail access and touch targets.
3. Confirm diagnostics and warning states remain visible.

### Mobile: approximately 390-430px

1. Confirm primary KPIs and cash remain visible without horizontal page overflow.
2. Confirm refresh, alert, Calendar, pattern, snapshot and rebuild actions remain reachable.
3. Confirm charts are readable and do not obscure text or controls.
4. Confirm Page History/Notes/Search modal surfaces do not cover essential Dashboard actions unexpectedly.
5. Confirm keyboard/screen-reader focus and touch behavior where applicable.

## 15. Final AUD-013 Assessment

**Static assessment: IMPLEMENTED, with targeted runtime verification retained.**

The Dashboard-specific review covered **18 atomic capabilities**: **17 historical capabilities confirmed preserved**, **1 post-V6 addition confirmed present without interference**, **0 preserved-differently rows**, and **0 confirmed regressions**. Remaining work is browser/runtime verification of hierarchy, responsive access, degraded-data semantics, chart/table readability, keyboard/screen-reader behavior and coexistence with the completed shell utilities.

The master audit verdict is now `IMPLEMENTED` based on this Dashboard-specific evidence, with the runtime checks above retained. AUD-004 remains unchanged.
