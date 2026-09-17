# AUD-004 - V6 Non-Regression / Historical Capability Reconstruction

## 1. Finding Recap

AUD-004 asked whether the V6 E4 frontend consolidation preserved the meaningful user capabilities, information and convenience actions that existed before the redesign. Route presence alone is not sufficient evidence, so this review compares a bounded pre-remediation implementation baseline with the current `master` tree at capability level.

The static comparison found no confirmed lost Investor or Admin capability. The first reconstruction used `233a427`, which is already a post-V6 integrated implementation; this correction adds the earlier per-surface baselines required to answer the actual redesign question. Across those boundaries, the named pre-redesign capabilities remain present. Seven rows are explicitly identified as added after the redesign and therefore are not used as historical preservation evidence. Browser discoverability, responsive access and deployed reachability remain runtime checks.

## 2. Historical Baseline Method

There is no single commit named “V6 frontend redesign”. The change was incremental:

| Reference | Date | Use in this review |
| --- | --- | --- |
| `233a427` | 2026-09-12 | Post-V6 / pre-audit-remediation stability baseline. It proves that the later AUD-001 through AUD-016 work did not remove those capabilities; it is not the historical pre-redesign baseline. |
| `1ee388c` | 2026-09-04 | Supplemental frontend migration boundary: introduced the incremental React/Vite migration stack. |
| `7a9095f` | 2026-07-30 | Supplemental navigation boundary: replaced horizontal tabs with the grouped sidebar without changing routes. |
| `9f5c6a1` | 2026-07-30 | Supplemental navigation metadata/favourites boundary. |
| `bdd32e8` | 2026-09-18 | AFTER baseline: current `master`, including completed AUD-001/002/003/016 remediations. |

This is intentionally a capability baseline rather than a screenshot baseline. Features added after `233a427` are not falsely treated as regressions; they are marked additive or out of comparison scope. Historical code was checked for mounting/reachability where practical, and dead/test-only helpers were not counted as user capabilities.

### Per-surface historical boundaries

| Surface | Pre-redesign SHA | Redesign/migration SHA | Why this is the correct boundary |
| --- | --- | --- | --- |
| Dashboard | `e5880a7` | `1ee388c`; earlier Dashboard redesign work includes `5b91046`, `287d6cb`, `0625a64` | Latest Dashboard implementation before the React/Vite migration window, already containing the V5 portfolio, market-health, chart and preference capabilities. |
| Holdings | `9aecfff` | `1ee388c` | Latest pre-migration Holdings implementation after ownership, watchlist and row-action work. |
| Strategy | `bed5f46` | `1ee388c` | Latest pre-migration Strategy surface after multi-strategy lifecycle work; AI prompt and registry changes are separately dated before migration. |
| Discovery/Candidates | `12de7ce` | `1ee388c`; evaluation consolidation was `755153e` | Latest pre-migration candidate/evaluation implementation after the accepted Discovery consolidation and evaluation-history work. |
| Recommendations | `88ee976` | `1ee388c` | Latest pre-migration recommendation surface after V5 lifecycle/clarity work. |
| Backtests | `4f864f8` | `1ee388c` | Latest pre-migration Backtest detail/history UI after charts, duplicate-adjacent detail improvements and scroll helpers. |
| Knowledge Board | `459b3b5` | `1ee388c` | Latest mounted Knowledge Board implementation before migration, including images, palettes, editor modes and export. Wiki did not yet exist at this boundary. |
| Admin | `1fa1dc2` plus pre-migration Admin routes in `App.jsx` | `b58fa55` role-shell split; `1ee388c` migration context | Latest pre-role-split Admin capability set. V6 Admin Audit Explorer and later V7 Fundamentals/ML pages are post-baseline additions. |
| Cross-cutting helpers/navigation | `59070c7` for analysis/copy; `9f5c6a1` for navigation registry | `7a9095f` sidebar migration and `9f5c6a1` metadata registry; `1ee388c` frontend migration | These are the last meaningful helper/navigation implementations before the relevant reorganizations, not a single page boundary. |

`233a427` remains useful evidence for post-V6 stability, but no longer serves as the sole BEFORE reference.

## 3. V6 Non-Regression Contract

V6 E4 §§2.1-2.3, 6, 7, 8 and 26 require:

- existing information, metrics, actions, links, shortcuts, filters, toggles, contextual help, warnings and diagnostics to be inventoried before redesign;
- no removal of meaningful user-visible capability without explicit product-owner review;
- external links, copy helpers and copy-ready external LLM prompts to be treated as product functionality;
- Dashboard metrics and useful actions to remain available even when reorganized into cards, tabs or expandable sections;
- responsive layouts to preserve an access path rather than hide capability without an equivalent;
- role-specific Admin utilities to remain available where legitimately present;
- changed placement or presentation to count as preservation only when the same user outcome and information remain reachable.

The comparison vocabulary below follows the audit request: `PRESERVED`, `PRESERVED_DIFFERENTLY`, `SUPERSEDED_BY_ACCEPTED_DECISION`, `MISSING_OR_REGRESSED`, `STATICALLY_UNCLEAR`, `RUNTIME_VERIFICATION_REQUIRED`, and `NOT_A_MEANINGFUL_CAPABILITY`.

## 4. Dashboard Comparison

The V6 E4 specification itself names the Dashboard baseline in §6.2. The historical Dashboard source at `e5880a7` was compared with current `master`; `DashboardPage.jsx` is also unchanged between `233a427` and current `master`, providing a separate post-V6 stability check.

| Capability | BEFORE evidence | Current evidence | Classification |
| --- | --- | --- | --- |
| Portfolio value, invested value, P&L and XIRR summary | `DashboardPage.jsx` baseline KPI/summary blocks | Same page and blocks on current `master` | `PRESERVED` |
| Available/investable cash | Dashboard cash cards and API payload rendering | Same rendering; cash-management details remain separately routed | `PRESERVED` |
| Top gainer/loser with all-time/latest-day choice | `DashboardTopMoverCard`, period state and preference key | Same component and controls | `PRESERVED` |
| Positions count, diversification and relative-strength analytics | Dashboard cards and `PercentGradientBar`/analytics sections | Same sections and helpers | `PRESERVED` |
| Market Health summary and collapsible diagnostics/gauges | Market health and gauge components | Same gauges, collapse preference and diagnostics | `PRESERVED` |
| Active alerts with acknowledge/clear-all | Alert card/action handlers | Same alert actions and notification integration | `PRESERVED` |
| Calendar/events summary | `DashboardCalendarCard` | Same card and route links | `PRESERVED` |
| Actionable holding pattern signals | Pattern scan results and `PatternSketch` links | Same pattern result path and guide links | `PRESERVED` |
| Relative Strength views | Dashboard relative-strength sections | Same sections | `PRESERVED` |
| Allocation table/visualization | `DashboardAllocationCard` and allocation data | Same component/data path | `PRESERVED` |
| Portfolio Growth chart | Recharts growth series | Same chart and detail behavior | `PRESERVED` |
| Unrealized P/L history chart | Recharts P/L history series | Same chart and source data | `PRESERVED` |
| Rebuild portfolio history | Rebuild action and mutation | Same action | `PRESERVED` |
| Snapshot access | Snapshot link/action | Same `/portfolio/snapshots` path | `PRESERVED` |
| Dashboard refresh/cache behavior | Cache read/write/clear utilities and refresh flow | Same cache utilities and refresh flow | `PRESERVED` |
| Market Depth/Patterns deep links | Dashboard links and shortcut actions | Same route helpers/components | `PRESERVED` |
| Top-mover and diagnostics preferences | local/session preference keys | Same preference behavior | `PRESERVED` |
| Broker readiness/reconciliation status | Not part of the `e5880a7` pre-redesign Dashboard baseline; readiness/reconciliation cards were added during later V6/V7 work | Same cards; no removal after introduction | `PRESERVED` (post-V6 addition, not historical preservation evidence) |

No pre-redesign Dashboard metric or action was found to be removed. The readiness/reconciliation row is a later addition and is not counted as evidence that the redesign preserved an older capability. AUD-013 still needs its own old/current inventory and browser walkthrough; this section is evidence handed off to that audit, not an AUD-013 closure.

## 5. Holdings Comparison

`HoldingsPage.jsx` at `9aecfff` was compared with current `master`; it is also unchanged from the post-V6 stability baseline. The historical/current comparison supports:

| Capability | Current evidence | Classification | Related audit |
| --- | --- | --- | --- |
| Holdings identity, quantity, price, value and gain/loss columns | Holdings table and format helpers | `PRESERVED` | — |
| Strategy/owner and unmanaged/adoption visibility | Owner/adoption columns and actions | `PRESERVED` | AUD-005 boundary remains separate |
| Stock price-history navigation | `StockPricesPage` route and row links | `PRESERVED` | — |
| Analysis/compare/watchlist actions | `AnalyseStockButton`, compare and watchlist helpers | `PRESERVED` | — |
| Filtering, sorting and table preferences | Data table controls and persisted column helpers | `PRESERVED` | — |
| Incomplete/missing price evidence | explicit unavailable/oversell displays | `PRESERVED` | Market-data contract remains separate |
| Portfolio totals and summary values | Holdings summary calculations/rendering | `PRESERVED` | Accounting remains source of truth |

Multi-strategy ownership semantics are not re-litigated here; any remaining concerns belong to AUD-005 rather than V6 presentation non-regression.

## 6. Strategy Comparison

`StrategyPage.jsx` at `bed5f46` was compared with current `master`. Current additions to registry/artifact workflows do not remove the old strategy editing capability. The artifact authoring redirect is treated separately as an accepted later decision.

| Capability | BEFORE/current evidence | Classification |
| --- | --- | --- |
| Strategy selection and current strategy visibility | Strategy selector and portfolio-scoped strategy state | `PRESERVED` |
| Strategy configuration and parameters | Strategy editor tabs and form fields | `PRESERVED` |
| Enable/disable and registry lifecycle actions | Registry/editor controls | `PRESERVED` |
| Indicator/scoring configuration | Strategy indicator and scoring sections | `PRESERVED` |
| Run/evaluate and related recommendation navigation | Strategy actions and route links | `PRESERVED` |
| Strategy guide/help and field explanations | Guide tab, hints and documentation links | `PRESERVED` |
| AI strategy prompt helper | `AIStrategyPromptBuilder`, prompt generation and clipboard path | `PRESERVED` |
| Artifact import/export/validation | registry pages and artifact helpers | `PRESERVED_DIFFERENTLY` |
| Legacy authoring paths | old routes redirect to Library Drafts | `SUPERSEDED_BY_ACCEPTED_DECISION` |

The artifact lifecycle itself is governed by the current Trading Artifacts guide and is not treated as a loss merely because its authoring entry point moved.

## 7. Discovery / Candidates Comparison

The historical consolidation commit `755153e` merged Evaluations into Discovery/Candidates before the `1ee388c` migration boundary. The current `/evaluations` redirect is therefore a known accepted route consolidation, not evidence of a missing workflow. Candidate source at `12de7ce` and current source were compared directly.

| Capability | BEFORE evidence | Current evidence | Classification |
| --- | --- | --- | --- |
| Candidate list and ranking | Discovery/candidate list and ranked rows | `CandidatesPage` list/table with rank and source | `PRESERVED` |
| Screener/discovery run | Discovery run controls and default screener path | Run discovery/default screener controls | `PRESERVED` |
| Evaluation run/history | Historical evaluation route and run data | Candidates flow and evaluation history/detail | `PRESERVED_DIFFERENTLY` |
| Score, confidence and explanation | Evaluation/candidate fields | Current candidate columns and detail panels | `PRESERVED` |
| Pattern/signal evidence | Pattern result cells/sketches | Pattern evidence and guide links | `PRESERVED` |
| Evidence/factor drill-down | Row evidence/factors actions | Current `Evidence` and `Factors` actions | `PRESERVED` |
| Search and source filtering | Candidate search/source controls | Same controls, now with labelled inputs | `PRESERVED` |
| `/evaluations` entry point | Separate historical route | Redirects to `/candidates` | `SUPERSEDED_BY_ACCEPTED_DECISION` |
| Missing/default screener recovery | Default-screener empty/recovery path | Same recovery action | `PRESERVED` |

The Batch 1 table/state migration changes presentation and loading/error vocabulary but retains candidate data, filters and actions.

## 8. Recommendations Comparison

`RecommendationsPage.jsx` at `88ee976` was compared with current `master`; it is also unchanged between `233a427` and current master. Current tests and component references cover the same decision/review surface.

| Capability | Current evidence | Classification | Related audit |
| --- | --- | --- | --- |
| Recommendation list and detail | list/detail view and route | `PRESERVED` | — |
| Rationale, score/fit and evidence | detail sections and evidence fields | `PRESERVED` | — |
| Strategy attribution | strategy metadata and labels | `PRESERVED` | AUD-005 provenance |
| Capital/funding state | `RecommendationCapitalResolution` and status blocks | `PRESERVED` | AUD-008/portfolio boundary |
| Review/approval controls | approval/review handlers | `PRESERVED` | — |
| Execution readiness/pending state | pending execution links/status | `PRESERVED` | AUD-015 safety boundary |
| Dismiss/reject/reopen behavior | existing action handlers | `PRESERVED` | lifecycle docs |
| Stock/strategy/evidence navigation | links and detail actions | `PRESERVED` | — |

No recommendation capability was found to be lost by the V6 shell work. Execution and accounting correctness remain separate audits.

## 9. Backtests Comparison

`BacktestHistoryPage.jsx` and `BacktestDetailPage.jsx` at `4f864f8` were compared with current `master`. Later V4/V5 additions are additive to that surface, and the post-V6 baseline confirms no later remediation loss.

| Capability | Current evidence | Classification |
| --- | --- | --- |
| Backtest list/history | history page and rows | `PRESERVED` |
| Create/run | backtest form and run action | `PRESERVED` |
| Detail/progress/status | detail page and status sections | `PRESERVED` |
| Metrics/charts/tables | persisted result cards, charts and trade tables | `PRESERVED` |
| Strategy linkage and parameters | stored input/detail sections | `PRESERVED` |
| Duplicate from history | Duplicate action and payload helper | `PRESERVED` |
| Cancel/background lifecycle | status/cancel controls | `PRESERVED` |
| Errors/incomplete state | detail/history state handling | `PRESERVED` |
| Top/bottom navigation helpers | local backtest navigation/scroll controls | `PRESERVED` |

Dataset pinning, replay semantics and paper simulation correctness are intentionally left to the analytics/backtesting contract and AUD-008; this is only a preservation comparison.

## 10. Knowledge Comparison

The V1-V5 Knowledge Board and later Wiki work span multiple commits. `459b3b5` is the pre-redesign Knowledge Board baseline. Wiki did not exist at that boundary, so Wiki rows below are explicitly post-V6-only validation rather than historical preservation evidence.

| Capability | BEFORE evidence | Current evidence | Classification |
| --- | --- | --- | --- |
| Knowledge Board notes/cards | `KnowledgeBoardPage`, note grid/editor and API | Same board/editor/card flow | `PRESERVED` |
| Tags and filtering | tags page and tag inputs | same tag routes/components | `PRESERVED` |
| Rich/plain editor modes | `KnowledgeSimpleEditor`/editor mode controls | same editor modes | `PRESERVED` |
| Images/lightbox/resize | image components and library paths | same image/lightbox components | `PRESERVED` |
| Pin/archive/order behavior | note card actions and ordering | same actions/state | `PRESERVED` |
| Export | `KnowledgeExportDialog` | same export dialog | `PRESERVED` |
| Wiki hierarchy/navigation | Not present in the `459b3b5` Knowledge Board baseline | Current Wiki tree, breadcrumbs and page routes | `PRESERVED` (post-V6 addition, not historical preservation evidence) |
| Wiki edit/preview/revisions | Not present in the `459b3b5` Knowledge Board baseline | Current editor, preview, compare and restore controls | `PRESERVED` (post-V6 addition, not historical preservation evidence) |
| Wiki sharing/public read | Not present in the `459b3b5` Knowledge Board baseline | Current public/wiki sharing flow | `PRESERVED` (post-V6 addition, not historical preservation evidence) |
| Contextual Notes | Added with V6 after the pre-redesign Knowledge Board baseline | Coordinated RightUtilityRail Notes utility | `PRESERVED_DIFFERENTLY` (post-V6 addition, not historical preservation evidence) |
| Search across knowledge | no current baseline capability proven in selected mounted code | no Global Search knowledge category by approved MVP | `NOT_A_MEANINGFUL_CAPABILITY` |

The final row is deliberately not a regression: no reachable pre-redesign knowledge search capability was established, and the approved Global Search MVP explicitly excludes knowledge/wiki.

## 11. Admin Comparison

Admin is compared with Admin, not with Investor surfaces. The pre-role-split Admin routes at `1fa1dc2` preserve the baseline operational families. V6 Admin Audit Explorer and V7 Fundamentals/ML Scoring are additive after the historical boundary and are not used to claim preservation of older functions.

| Capability | BEFORE evidence | Current evidence | Classification |
| --- | --- | --- | --- |
| User management | `UserManagementPage`, role-protected route | same route plus session/force-logout controls | `PRESERVED` |
| Invitations | invite/admin user flows | current admin user/invite flow | `PRESERVED` |
| Session inspection/revocation | session panel and revoke action | same capability | `PRESERVED` |
| Stocks/security master | stocks admin route/page | current stocks admin route/page | `PRESERVED` |
| Data quality/gap/corporate-action operations | data quality, gap and corporate-action admin routes | same route families | `PRESERVED` |
| Universe price sync/sync logs | sync admin pages and actions | same routes/actions | `PRESERVED` |
| Indicator registry | indicator registry list/detail | same registry list/detail | `PRESERVED` |
| Screener registry | admin screener registry | same registry and lifecycle | `PRESERVED` |
| Strategy registry | admin strategy registry | same registry and lifecycle | `PRESERVED` |
| Admin audit explorer | Not present in the `1fa1dc2` pre-role-split baseline | Current V6 admin audit route/page | `PRESERVED` (post-V6 addition, not historical preservation evidence) |
| Alert/admin operational settings | admin alerts/settings routes | same route families | `PRESERVED` |
| Fundamentals/ML administration | absent at BEFORE baseline | current V7 admin pages | `NOT_A_MEANINGFUL_CAPABILITY` |

The additive V7 rows are not used as evidence that the V6 redesign preserved an older feature.

## 12. Cross-Cutting Helpers

The historical/current search covered the following helpers and convenience patterns:

| Helper/capability | BEFORE evidence | Current disposition |
| --- | --- | --- |
| Clipboard AI analysis prompt | `AnalyseStockButton` and prompt builder | Still mounted in Holdings/Dashboard-related stock surfaces; `PRESERVED` |
| External stock links | `ExternalStockLinksSettings` and link resolver | Still present in settings/stock workflows; `PRESERVED` |
| Copy/report boot diagnostics | `BootErrorBanner` clipboard/prompt fallback | Still present; `PRESERVED` |
| Refresh/sync actions | dashboard refresh, sync logs, admin sync pages | Still present; `PRESERVED` |
| Detail/open/view links | route helpers across stock, review, backtest and registry pages | Still present; `PRESERVED` |
| Tooltips/field explanations | `FieldHint`, fee hints, gauge tooltips and guide pages | Still present; `PRESERVED` |
| Copy-ready strategy prompt | `AIStrategyPromptBuilder` | Still present; `PRESERVED` |
| Admin diagnostics/status | readiness, data-quality, sync and audit surfaces | Still present; `PRESERVED` |
| Responsive hover-only access | exact browser reachability | Source cannot prove all viewport paths | `RUNTIME_VERIFICATION_REQUIRED` |

No historical `navigator.clipboard`, external-link, analysis, prompt or diagnostic capability was found to have disappeared in the current source tree.

## 13. Main Capability Matrix

The detailed surface sections above contain the atomic comparison rows. Summary counts describe the user-facing comparison classifications; the validation ledger separately states whether each row is actually backed by pre-redesign source.

| Classification | Count |
| --- | ---: |
| `PRESERVED` | 84 |
| `PRESERVED_DIFFERENTLY` | 3 |
| `SUPERSEDED_BY_ACCEPTED_DECISION` | 2 |
| `MISSING_OR_REGRESSED` | 0 |
| `STATICALLY_UNCLEAR` | 0 |
| `RUNTIME_VERIFICATION_REQUIRED` | 1 |
| `NOT_A_MEANINGFUL_CAPABILITY` | 2 |
| **Total atomic capabilities** | **92** |

The 92 rows comprise the Dashboard, Holdings, Strategy, Discovery/Candidates, Recommendations, Backtests, Knowledge, Admin and cross-cutting inventories. `PRESERVED_DIFFERENTLY` rows are not downgraded to regression where the same outcome remains reachable. The navigation-boundary decision recorded in §16 is supporting evidence for the page-level rows rather than an additional atomic capability row.

### Baseline-validation ledger

| Existing NR rows | Surface/rows | Baseline-validation status | Evidence boundary |
| --- | --- | --- | --- |
| `NR-001..NR-017` | Dashboard information/actions before readiness/reconciliation | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `e5880a7` -> current; later stability check `233a427` -> current |
| `NR-018` | Dashboard readiness/reconciliation | `UNCHANGED_POST_V6_ONLY` | Introduced after the pre-redesign Dashboard boundary; current presence is confirmed, historical preservation is not claimed |
| `NR-019..NR-025` | Holdings | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `9aecfff` -> current |
| `NR-026..NR-033` | Strategy, registry and AI prompt capabilities | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `bed5f46`, `d035120`, `ee4a3fb` -> current |
| `NR-034` | Legacy artifact authoring redirect | `UNCHANGED_POST_V6_ONLY` | Redirect decisions `0ceac28`/`f8e45c8` are later accepted workflow decisions |
| `NR-035..NR-043` | Discovery/Candidates and evaluation consolidation | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `12de7ce`, `755153e` -> current |
| `NR-044..NR-051` | Recommendations | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `88ee976` -> current |
| `NR-052..NR-060` | Backtests | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `4f864f8` -> current |
| `NR-061..NR-066` | Knowledge Board | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `459b3b5` -> current |
| `NR-067..NR-070` | Wiki and Contextual Notes | `UNCHANGED_POST_V6_ONLY` | Wiki/Notes were introduced after the Knowledge Board baseline; current implementation is separately covered by their own audits |
| `NR-071` | Knowledge search | `NOT_A_MEANINGFUL_CAPABILITY` | No reachable pre-redesign capability established; excluded from preservation claim |
| `NR-072..NR-080` | Admin operational capabilities | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `1fa1dc2` plus pre-role-split Admin routes -> current |
| `NR-081` | Admin Audit Explorer | `UNCHANGED_POST_V6_ONLY` | Added after the historical Admin boundary; current presence is not a preservation claim |
| `NR-082` | Admin alert/operational settings | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | Pre-role-split Admin routes -> current |
| `NR-083` | Fundamentals/ML administration | `NOT_A_MEANINGFUL_CAPABILITY` | V7 addition after the historical boundary |
| `NR-084..NR-091` | Cross-cutting analysis, copy, links, export, refresh and diagnostics | `CONFIRMED_AGAINST_PRE_REDESIGN_BASELINE` | `59070c7`, `689c059`, `e7c988a`, `9f5c6a1` and related pre-migration sources -> current |
| `NR-092` | Responsive hover/touch reachability | `RUNTIME_VERIFICATION_REQUIRED` | Requires browser/viewport verification; source comparison alone is insufficient |

Validation totals: **82** rows are confirmed against true pre-redesign source, **7** are explicitly unchanged post-V6-only rows, **2** are not meaningful historical comparison rows, and **1** remains runtime-only. All 92 rows therefore have an explicit evidence status.

## 14. Confirmed Regressions

No `MISSING_OR_REGRESSED` capability was statically confirmed against the defensible pre-redesign baselines. The post-V6-only rows are not evidence of historical preservation or regression.

This conclusion is bounded by the selected baseline and source evidence. It does not claim that every historical pixel, hidden diagnostic state or browser-only interaction is preserved.

## 15. Preserved-Differently Decisions

1. **Evaluation to Candidates:** the former evaluation entry point is redirected to Candidates after the accepted discovery consolidation. Candidate evidence, ranking, confidence, explanation and evaluation detail remain reachable.
2. **Top-level tabs to grouped sidebar:** the V6 navigation migration moved peer access from horizontal tabs into the grouped sidebar and route-aware child entries. Routes were retained, so this is a navigation presentation change, not capability loss. This is supporting navigation evidence, not a separate atomic row in the 92-row count.
3. **Contextual Notes to the RightUtilityRail:** note CRUD and pathname/profile scoping remain, while desktop/mobile presentation now uses the shared utility system. This capability was added after the pre-redesign baseline and is not counted as historical preservation evidence.
4. **Artifact authoring to Library Drafts:** legacy authoring routes redirect into the immutable artifact/draft lifecycle documented in current contracts. This supports `NR-034` and is not an additional row.
5. **Candidate list table normalization:** the page now uses shared `DataTableView`/`DataState` while retaining ranking, evidence, factor actions and filters. This is a post-audit presentation refinement, not an additional row.

## 16. Superseded Capabilities

The following are supported by repository decisions rather than inferred removal:

| Historical workflow | Superseding decision | Classification |
| --- | --- | --- |
| `/evaluations` as separate discovery surface | `755153e` merged Evaluations into Discovery/Candidates; current route redirects | `SUPERSEDED_BY_ACCEPTED_DECISION` |
| Horizontal top-tab navigation | `7a9095f` grouped primary sidebar retained route structure | `SUPERSEDED_BY_ACCEPTED_DECISION` |
| Legacy artifact authoring entry points | `0ceac28`, `f8e45c8` route new definitions to V5 drafts/library | `SUPERSEDED_BY_ACCEPTED_DECISION` |

No Dashboard metric or named convenience helper was classified as intentionally removed because no corresponding product-owner removal decision was found.

## 17. Static Uncertainties

- A source comparison cannot prove that every control is visible and discoverable at every viewport or role combination.
- Historical code can prove a mounted path, but not that a production deployment exposed it to every user.
- Current `DataTableView`/`DataState` adoption is representative, not universal; this is tracked by AUD-003, not a V6 non-regression loss.
- V7 additions after the baseline are outside the preservation comparison.
- Exact Dashboard old/current information parity should be walked with realistic data for AUD-013 even though source continuity is strong.

## 18. Runtime Verification

Browser/runtime checks still required:

- Dashboard metric/action discoverability with representative data;
- Holdings, Candidates, Recommendations and Backtest detail action reachability;
- Admin role-specific route discoverability;
- mobile/tablet access to actions that were desktop controls before the redesign;
- table overflow, chart legends/tooltips and expandable diagnostics;
- keyboard order, screen-reader labels and touch targets;
- deployed bundle reachability and route redirects;
- no Page History, Contextual Notes or Global Search overlay obscures preserved controls.

These checks are verification work, not confirmed regressions.

## 19. AUD-013 Evidence Handoff

AUD-013 should consume the Dashboard rows in §4 as its baseline inventory. The strongest historical evidence is the pre-redesign Dashboard at `e5880a7`, supplemented by the continuity check that `DashboardPage.jsx` and its named child components are unchanged across `233a427..bdd32e8`. Current tests also cover dashboard cache, portfolio compare, reconciliation and related chart/analytics helpers. AUD-013 still needs the historical before/after capability walkthrough, realistic data, preference checks and browser/runtime confirmation. This document does not modify AUD-013 status.

## 20. Recommended Remediation Groups

Because no static regression was confirmed, remediation is verification-led:

### A - Lost information/metrics

No static items. Use the Dashboard baseline rows to verify metric parity with realistic data.

### B - Lost page actions/helpers

No static items. Spot-check clipboard prompts, external links, refresh, export, retry, analysis, duplicate and detail actions.

### C - Lost navigation/discoverability

Verify sidebar child routes, accepted `/evaluations` redirect, artifact draft redirects and the Investor/Admin split in the browser.

### D - Lost diagnostics/status explanation

Verify Dashboard gauges, readiness/reconciliation, data-quality and error/empty states under realistic and degraded responses.

### E - Runtime verification only

Complete the viewport, keyboard, touch, deployed-bundle and role walkthrough listed in §18.

## 21. Final AUD-004 Assessment

**Static assessment: IMPLEMENTED, with runtime verification retained.**

The bounded historical inventory covers 92 atomic capabilities across the named V6 surfaces and cross-cutting helpers. The user-facing classification remains 84 preserved, 3 preserved differently, 2 superseded atomic workflows, 1 runtime-only matrix item and 2 non-comparison rows, with no confirmed static regressions. Evidence strength is now explicit: 82 rows are confirmed against pre-redesign source, 7 are post-V6-only additions, 2 are excluded from historical comparison, and 1 requires runtime verification. Remaining uncertainty is browser/deployment evidence or a separate audit boundary (especially AUD-005, AUD-008, AUD-012 and AUD-013), not an identified V6 non-regression loss.

The master implementation audit should be updated to `IMPLEMENTED` only after this reconstruction is accepted as the authoritative AUD-004 evidence. No product code, tests, current documentation or AUD-013 status was changed by this pass.

## 22. Open Questions

1. Does product ownership want a browser walkthrough artifact attached to AUD-004, or is the static reconstruction plus AUD-013 handoff sufficient for closure?
2. Should any future capability dispute use an even narrower per-file SHA, despite the per-surface boundaries now recorded in §2?
3. Which deployed environment should supply the final role/viewport verification evidence?
