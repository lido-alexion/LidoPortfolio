# StoX Current Documentation Knowledge-Gap Audit

**Date:** 2026-09-16  
**Phase:** D1 - Current-vs-archive knowledge migration audit  
**Scope:** Audit only. No `docs/current/**`, product code, tests, configuration, or deployment files were modified.

## Method and authority

`docs/current/README.md` establishes `docs/current/**` as the current documentation authority and the archive as source archaeology. This audit therefore promotes only stable current knowledge that is supported by current code, tests, implementation notes, or an accepted specification that has not been superseded. Historical changelog detail is marked `HISTORICAL_ONLY` or `KEEP_ARCHIVED` rather than promoted automatically.

Sources inspected include all current domain documents, `implementation.md`, `debugging.md`, deployment/runbook material, `app/public/docs/**`, and the V6 E4 specification. Code and tests were used only to distinguish a durable contract from obsolete or historical implementation detail.

## A. Coverage assessment

Ratings are qualitative and intentionally separate. A document can be strong on API inventory while weak on UX or state behavior.

| Current document | Product behaviour | UX | Implementation mechanics | Data/domain rules | Invariants | State/lifecycle | Tests | Debugging |
|---|---|---|---|---|---|---|---|---|
| `product-overview.md` | STRONG | WEAK | MODERATE | MODERATE | WEAK | WEAK | WEAK | WEAK |
| `frontend-and-navigation.md` | MODERATE | WEAK | MODERATE | WEAK | WEAK | WEAK | WEAK | MODERATE |
| `administration-security-api.md` | STRONG | MODERATE | STRONG | MODERATE | MODERATE | MODERATE | MODERATE | STRONG |
| `analytics-review-backtesting.md` | STRONG | MODERATE | STRONG | STRONG | MODERATE | MODERATE | MODERATE | STRONG |
| `discovery-screeners-registries.md` | STRONG | MODERATE | STRONG | STRONG | MODERATE | STRONG | MODERATE | STRONG |
| `execution-broker-safety.md` | STRONG | MODERATE | STRONG | STRONG | STRONG | STRONG | MODERATE | STRONG |
| `knowledge-and-documentation.md` | STRONG | MODERATE | MODERATE | MODERATE | MODERATE | MODERATE | MODERATE | MODERATE |
| `market-data-and-data-quality.md` | STRONG | MODERATE | STRONG | STRONG | STRONG | MODERATE | MODERATE | STRONG |
| `notifications-calendar-alerts.md` | STRONG | MODERATE | MODERATE | MODERATE | MODERATE | WEAK | MODERATE | MODERATE |
| `portfolio-cash-accounting.md` | STRONG | MODERATE | STRONG | STRONG | STRONG | STRONG | MODERATE | STRONG |
| `strategy-and-recommendations.md` | STRONG | MODERATE | STRONG | STRONG | STRONG | STRONG | MODERATE | STRONG |
| `stox-trading-artifacts-ai-guide.md` | STRONG | MODERATE | STRONG | STRONG | STRONG | STRONG | WEAK | MODERATE |
| `implementation-alignment-and-gaps.md` | MODERATE | WEAK | MODERATE | WEAK | WEAK | WEAK | STRONG | STRONG |
| `README.md` | MODERATE | WEAK | WEAK | WEAK | WEAK | WEAK | WEAK | MODERATE |

## Gap inventory by current document

### `docs/current/product-overview.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-001 | `product-overview.md` | STATE_MACHINE_MISSING | The canonical recommendation-to-approval-to-execution-to-fill lifecycle and its terminal/non-terminal states | `docs/current/execution-broker-safety.md`; `implementation.md` FEAT-039 sections | Product overview explicitly presents recommendations and execution as core operating model | `## Current Behaviour` or new `## Lifecycle Map` | High |
| DOCGAP-002 | `product-overview.md` | INVARIANT_MISSING | Portfolio/profile scoping, user-scoped broker identity, and separation of simulated state from live accounting | Current domain docs; V6/V5 implementation sections | These boundaries determine how every feature is interpreted | `## Technical Contract` | High |

### `docs/current/frontend-and-navigation.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-003 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Five-zone shell: top header, collapsible left navigation, main workspace, right utility rail/contextual pane, low-priority footer | E4 §§3.1-3.6 | Active shell architecture is a current product contract, not historical navigation taxonomy | `## Application Shell` | High |
| DOCGAP-004 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Page Visit History: desktop right-edge rail, last 12 visits, duplicate collapse, active state, labels, links, mobile History action | E4 §8 | Accepted V6 E4 behavior is absent from current docs and is required to understand the shell gap | `## Contextual Navigation` | High |
| DOCGAP-005 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Contextual Notes rail/pane placement, overlay-not-resize rule, mobile drawer/sheet, reduced-motion behavior | E4 §9; V6 E6 | Current code mounts `ContextualNotesPane`; current docs need to describe its contract | `## Contextual Utilities` | High |
| DOCGAP-006 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Standard page anatomy: breadcrumbs, page/entity header, route-backed tabs, segmented controls, filters, sections, drawers | E4 §§4, 5 | This is the reusable navigation language for every current page | `## Page Anatomy` | High |
| DOCGAP-007 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Responsive behavior for mobile, tablet/small desktop, standard desktop, and ultrawide layouts | E4 §16 | Viewport behavior changes reachability and information preservation | `## Responsive Contract` | High |
| DOCGAP-008 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Loading, empty, unknown, unavailable, incomplete and not-applicable state conventions; skeleton and spinner rules | E4 §§13, 20 | Current pages need a shared state vocabulary to avoid manufacturing zeros or inconsistent loading UI | `## Shared UI States` | High |
| DOCGAP-009 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Table/chart/form conventions, responsive table fallback, exact chart values, control-selection rules, accessibility and reduced motion | E4 §§12-14, 18-22 | These rules govern current product surfaces and future maintenance | `## Shared Component Contracts` | High |
| DOCGAP-010 | `frontend-and-navigation.md` | INVARIANT_MISSING | UI-only preference persistence: namespacing/versioning/reset, and prohibition on storing auth/execution/financial state in localStorage | E4 §17 | Existing app preferences and theme behavior depend on this boundary | `## Preferences` | High |
| DOCGAP-011 | `frontend-and-navigation.md` | UX_CONTRACT_MISSING | Dashboard preservation baseline and old-vs-new capability inventory gate, including micro-features and convenience affordances | E4 §§6-7 | E4 makes preservation an acceptance rule; omission permits silent UX regression | `## Non-Regression Contract` | High |
| DOCGAP-012 | `frontend-and-navigation.md` | IMPLEMENTATION_DETAIL_MISSING | Incremental frontend migration rule and shared foundation ownership, including reuse of `ThemeToggle`/`ThemeContext` | E4 §23 | Prevents future agents from starting parallel shell/theme/component systems | `## Frontend Architecture` | High |

### `docs/current/administration-security-api.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-013 | `administration-security-api.md` | SECURITY_RULE_MISSING | Complete Admin-vs-Investor route/data ownership matrix and the rule that admin users cannot own investor resources | V5 FEAT-042; `implementation.md` role-separation sections | Role boundaries are security-critical and current docs only summarize them | `## Authorization Matrix` | High |
| DOCGAP-014 | `administration-security-api.md` | STATE_MACHINE_MISSING | Invite, password-reset, session-revocation, token, TOTP and execution-entitlement state transitions | V2 specs; V5/V6 implementation notes | These flows have expiry/revocation semantics that are easy to break | `## Security Lifecycles` | High |
| DOCGAP-015 | `administration-security-api.md` | TEST_ANCHOR_MISSING | Named test anchors for auth, role separation, token scopes, ownership audit, and OpenAPI drift | Current `app/tests/Feature` and `implementation.md` | The document already promises debugging guidance; named tests make it actionable | `## Test Anchors` | High |

### `docs/current/analytics-review-backtesting.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-016 | `analytics-review-backtesting.md` | STATE_MACHINE_MISSING | Backtest/replay lifecycle: queued, running, resumable, cancelled, failed, completed, persisted and comparable | V5 FEAT-020; `implementation.md` simulation sections | Operators and maintainers need to distinguish a partial run from a failed run | `## Simulation Lifecycles` | High |
| DOCGAP-017 | `analytics-review-backtesting.md` | INVARIANT_MISSING | Point-in-time evidence, pinned artifacts, benchmark source, assumptions and no mutable-live-state leakage | Current data rules; artifact runtime guide | Reproducibility and financial interpretation depend on these invariants | `## Reproducibility Invariants` | High |
| DOCGAP-018 | `analytics-review-backtesting.md` | ERROR_RECOVERY_MISSING | Cancellation/resume, partial persistence, missing benchmark bars, and stale/empty dashboard behavior | `implementation.md`; public help pages | These are stable operational behaviors, not historical anecdotes | `## Recovery And Failure States` | Medium |

### `docs/current/discovery-screeners-registries.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-019 | `discovery-screeners-registries.md` | ALGORITHM_RULE_MISSING | Lookback/missing-volume semantics, operator constraints, normalization and runtime eligibility rules | Trading artifact AI guide §§6, 10; current evaluator code anchors | Authors and debuggers need the exact difference between invalid, unavailable and false | `## Runtime Evaluation Rules` | High |
| DOCGAP-020 | `discovery-screeners-registries.md` | STATE_MACHINE_MISSING | Artifact lifecycle and binding resolution: draft, publish, fork, archive, bind, upgrade, rollback and exact version selection | V5 FEAT-008; artifact guide | Registry correctness depends on lifecycle semantics, not route names | `## Artifact Lifecycle` | High |
| DOCGAP-021 | `discovery-screeners-registries.md` | SECURITY_RULE_MISSING | Share-grant, same-user cross-portfolio copy, system artifact, and private binding access rules | V2 F060; V5 artifact specs | Incorrect access scope can leak strategy/screener definitions | `## Ownership And Access` | High |

### `docs/current/execution-broker-safety.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-022 | `execution-broker-safety.md` | STATE_MACHINE_MISSING | Recommendation, intent, order lifecycle, fill, reconciliation, expiry and finalization transitions | `implementation.md` FEAT-039; V5 FEAT-040 | Live execution cannot be safely maintained from a feature summary alone | `## Execution State Machines` | High |
| DOCGAP-023 | `execution-broker-safety.md` | INVARIANT_MISSING | Internal matching before broker submission, residual-gap accounting, funds refresh, quantity flooring, and no pending-Sell proceeds assumption | `implementation.md` FEAT-039 section | These are explicit safety invariants | `## Broker Safety Invariants` | High |
| DOCGAP-024 | `execution-broker-safety.md` | ERROR_RECOVERY_MISSING | Insufficient-funds mapping, 95% retry limit, halt/attention/recovery behavior, idempotency and order-window expiry | `implementation.md`; V5/V6 safety specs | Failure handling is the product behavior for live trading | `## Failure And Recovery` | High |
| DOCGAP-025 | `execution-broker-safety.md` | TEST_ANCHOR_MISSING | Exact schedule, broker, reconciliation, emergency-cancel and safety test anchors | `app/tests/Feature/ScheduleRegistrationTest.php` and related tests | Existing tests are valuable operational documentation | `## Test Anchors` | High |

### `docs/current/knowledge-and-documentation.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-026 | `knowledge-and-documentation.md` | DATA_MODEL_MISSING | Stable page-context key, account/portfolio scoping, note ownership and public wiki token boundary | V6 E6 migration/test; V2 F144; current models | Contextual notes and Knowledge Board are different scopes that must not be conflated | `## Scope And Context Keys` | High |
| DOCGAP-027 | `knowledge-and-documentation.md` | CURRENT_BEHAVIOUR_MISSING | Documentation generation/serving path and source-vs-product-help distinction | `implementation.md`; `app/public/docs/README.txt`; current README | Engineers need to know which docs are generated assets and which are authority | `## Documentation Runtime` | High |

### `docs/current/market-data-and-data-quality.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-028 | `market-data-and-data-quality.md` | STATE_MACHINE_MISSING | Sync, gap-fill, ignored-gap, data-quality issue and corporate-action repair transitions | V2 F042/F043; implementation notes; current migrations | Operational data quality is not a static CRUD feature | `## Sync And Repair Lifecycles` | High |
| DOCGAP-029 | `market-data-and-data-quality.md` | INVARIANT_MISSING | Dataset freshness/publish gate, immutable dataset versioning and point-in-time read rules | V4 FEAT-022/023; current analytics rules | Downstream evaluations/backtests rely on these gates | `## Data Integrity Invariants` | High |
| DOCGAP-030 | `market-data-and-data-quality.md` | CONFIGURATION_MISSING | Provider adapter, scheduler cadence, market calendar, holiday and source fallback configuration | V5 FEAT-038/039; deployment docs | Runtime behavior changes with provider/calendar config | `## Runtime Configuration` | Medium |

### `docs/current/notifications-calendar-alerts.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-031 | `notifications-calendar-alerts.md` | STATE_MACHINE_MISSING | Notification lifecycle: domain event, channel selection, delivery, intentional skip, retry, health, read/acknowledge and history | V5 FEAT-004; V3 implementation notes | Delivery state is distinct from domain state and must remain so | `## Notification Lifecycle` | High |
| DOCGAP-032 | `notifications-calendar-alerts.md` | UX_CONTRACT_MISSING | Critical banner persistence/read semantics, channel settings verification, retry/history UX and reminder behavior | V5 FEAT-004 and V3 notification sections | These are user-facing accepted details absent from the summary | `## Notification UX Contract` | High |
| DOCGAP-033 | `notifications-calendar-alerts.md` | INVARIANT_MISSING | Notification state must not become recommendation/domain status; idempotency and no notification-on-notification failure | FEAT-004 non-goals and implementation | Prevents subtle business-state corruption | `## Delivery Invariants` | High |

### `docs/current/portfolio-cash-accounting.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-034 | `portfolio-cash-accounting.md` | INVARIANT_MISSING | Single physical cash pool, reserve formula, soft reservations, available-for-lending caps and close-at-actual accounting | V3/V4 implementation sections | These formulas govern recommendations and execution safety | `## Accounting Invariants` | High |
| DOCGAP-035 | `portfolio-cash-accounting.md` | STATE_MACHINE_MISSING | Deposit/withdraw/adjust, reservation, approval, cancellation, expiry, execute, loan/recall/bridge and repayment transitions | V1/V2.1/V3/V4 implementation notes | The document describes outcomes but not transitions | `## Cash And Capital State Machines` | High |
| DOCGAP-036 | `portfolio-cash-accounting.md` | TEST_ANCHOR_MISSING | Named tests for financial integrity, ownership attribution, corporate actions, import and snapshot rebuild | Current test tree; historical audit | Accounting maintainers need direct regression anchors | `## Test Anchors` | High |

### `docs/current/strategy-and-recommendations.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-037 | `strategy-and-recommendations.md` | ALGORITHM_RULE_MISSING | Exact scoring, eligibility, thresholds, exit precedence, market-gate and ownership rules | Trading artifact guide appendix; V3/V4 implementation sections | These are deterministic product behavior | `## Deterministic Runtime Rules` | High |
| DOCGAP-038 | `strategy-and-recommendations.md` | STATE_MACHINE_MISSING | Candidate → evaluation → recommendation, capital status, lending, approval and execution lifecycle | `implementation.md` V3 closure and FEAT-039 | The UI and notification behavior depends on these states | `## Recommendation Lifecycle` | High |
| DOCGAP-039 | `strategy-and-recommendations.md` | CURRENT_BEHAVIOUR_MISSING | Multi-strategy create/enable/edit/run/archive journey and last-enabled protection | `implementation.md` V3 product-surface closure | This was a later current UI correction not visible in the condensed doc | `## Strategy Lifecycle` | High |

### `docs/current/stox-trading-artifacts-ai-guide.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-040 | `stox-trading-artifacts-ai-guide.md` | TEST_ANCHOR_MISSING | Concrete validation, runtime-resolution, package, binding and deployment test anchors | `app/tests/Feature/Artifact*`; V5 FEAT-008 runbook | The guide is normative but hard to verify from it alone | `## Verification Anchors` | High |
| DOCGAP-041 | `stox-trading-artifacts-ai-guide.md` | ERROR_RECOVERY_MISSING | Import validation failures, dependency incompatibility, rollback and disabled-binding behavior | V5 FEAT-008 specification/status | These are required to safely author and deploy artifacts | `## Failure And Rollback` | Medium |

### `docs/current/implementation-alignment-and-gaps.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-042 | `implementation-alignment-and-gaps.md` | DEBUGGING_KNOWLEDGE_MISSING | A domain-indexed map from common symptom to route, service, log, command, migration and test anchor | `debugging.md`; current domain docs; implementation.md | This file is the natural cross-domain operational index | `## Debugging Matrix` | High |
| DOCGAP-043 | `implementation-alignment-and-gaps.md` | CURRENT_BEHAVIOUR_MISSING | Which listed gaps are historical, resolved, runtime-only, or still accepted limitations | Implementation chronology and current docs | Without classification, engineers can treat old debt as live behavior | `## Status And Evidence Rules` | High |

### `docs/current/README.md`

| Gap ID | Current Doc | Category | Missing Knowledge | Source | Why It Is Still Current | Recommended Destination Section | Confidence |
|---|---|---|---|---|---|---|---|
| DOCGAP-044 | `README.md` | CURRENT_BEHAVIOUR_MISSING | Explicit rule that current docs must capture stable contracts from `implementation.md` and `app/public/docs`, not only feature summaries | `implementation.md` policy; `DOCS.md` | The authority document needs to explain the migration boundary to prevent repeat loss | `## Authority Rules` | High |
| DOCGAP-045 | `README.md` | TEST_ANCHOR_MISSING | Requirement that each feature document link to meaningful test/debug anchors where they exist | Current domain docs and implementation policy | This would make current docs maintainable without reopening source archaeology | `## Authority Rules` | Medium |

## B. Archive promotion map

| Source archived document/section | Current destination document | Current destination section | Action |
|---|---|---|---|
| `V6-E4-Investor-UX-Client-Evolution.md` §§3-5 | `frontend-and-navigation.md` | Application Shell, Page Anatomy, Shared Component Contracts | PROMOTE |
| V6 E4 §§6-7 Dashboard baseline and convenience preservation | `frontend-and-navigation.md` | Non-Regression Contract | PROMOTE |
| V6 E4 §§8-10 Page History, Notes, Search | `frontend-and-navigation.md` | Contextual Navigation and Contextual Utilities | PROMOTE |
| V6 E4 §§12-22 tables, charts, forms, responsive, preferences, theme, motion, states, accessibility | `frontend-and-navigation.md` | Shared UI Contracts | PROMOTE |
| V6 E4 §§23-26 migration/non-goals/acceptance | `frontend-and-navigation.md` | Frontend Architecture and Non-Regression Contract | MERGE |
| `V5-FEAT-004-Notification-Service.md` lifecycle and non-goals | `notifications-calendar-alerts.md` | Notification Lifecycle and Delivery Invariants | PROMOTE |
| `V5-FEAT-008-Trading-Artifact-Framework.md` lifecycle/runtime/rollout | `discovery-screeners-registries.md` and artifact guide | Artifact Lifecycle and Verification Anchors | MERGE |
| `V5-FEAT-031-Production-Secrets-Single-Folder-Deploy.md` stable deploy contract | `implementation-alignment-and-gaps.md` or deployment doc | Deployment Verification | SUMMARIZE |
| `V5-FEAT-040-Kite-Portfolio-Reconciliation.md` halt/reconciliation rules | `execution-broker-safety.md` | Reconciliation And Recovery | PROMOTE |
| `V5-FEAT-042-Role-Separated-Admin-Investor-Applications.md` authorization matrix | `administration-security-api.md` | Authorization Matrix | PROMOTE |
| V3 specification sections on ownership/capital/lending/exit | `portfolio-cash-accounting.md`, `strategy-and-recommendations.md` | Accounting Invariants, Recommendation Lifecycle | MERGE |
| V4/V5 implementation sections on dataset gates and artifact pinning | `market-data-and-data-quality.md`, `analytics-review-backtesting.md` | Data Integrity, Reproducibility | MERGE |
| Chronological closure notes in `implementation.md` | Relevant feature docs | Stable contract sections only | SUMMARIZE |
| Historical bug symptoms and superseded hosting assumptions | Current feature docs | None | KEEP_ARCHIVED |
| V1 JWT/old TOS navigation language | Current auth/frontend docs | Historical Context | SUPERSEDED |

## C. `implementation.md` promotion map

`implementation.md` contains a large amount of stable technical knowledge mixed with chronology. The following should be promoted into current feature docs after human review:

| Stable knowledge | Destination | Promotion treatment |
|---|---|---|
| Multi-strategy create/enable/edit/archive lifecycle and last-enabled protection | `strategy-and-recommendations.md` | PROMOTE |
| Capital badges, allocation precedence, max holdings and portfolio max-position ceiling | `strategy-and-recommendations.md`, `portfolio-cash-accounting.md` | MERGE |
| Lending caps, recall, bridge loans, proceeds application and close-at-actual semantics | `portfolio-cash-accounting.md` | PROMOTE |
| Notification event taxonomy and HOLD/WATCH/unfunded notification distinctions | `notifications-calendar-alerts.md` | PROMOTE |
| Success-criteria evaluator and persisted backtest benchmark flags | `analytics-review-backtesting.md` | SUMMARIZE |
| Dataset freshness gate and immutable dataset version mechanics | `market-data-and-data-quality.md`, `analytics-review-backtesting.md` | MERGE |
| Broker execution windows, residual-gap matching, funds refresh and bounded retry | `execution-broker-safety.md` | PROMOTE |
| Reconciliation, halt, attention and finalization behavior | `execution-broker-safety.md` | PROMOTE |
| Controller/service/repository boundaries and shared hooks | `product-overview.md`, `implementation-alignment-and-gaps.md` | SUMMARIZE |
| Named test suites and commands | Each relevant current domain doc | PROMOTE as test anchors |
| One-off migration repair, historical fixture pollution and upload instructions | `implementation.md` / deployment runbook | KEEP_ARCHIVED unless still operational |

The following should remain chronological rather than being copied into feature docs: dates, “done in this pass” narration, superseded UI wording, one-time upload reminders, bug symptoms whose fix is already represented, and old hosting assumptions that no longer define the deployed target.

## D. Missing document structure

The existing feature map is sufficient. A new document for every lifecycle would create another fragmentation layer. Enrich the existing documents with consistent sections:

1. Current behavior.
2. User-facing UX contract.
3. Routes/API and ownership scope.
4. State machine or lifecycle.
5. Data model and invariants.
6. Error/retry/recovery behavior.
7. Configuration and scheduling.
8. Test anchors.
9. Debugging sources.
10. Historical boundaries and superseded decisions.

One small addition may be justified: a current `docs/current/operations-and-runtime.md` only if deployment, scheduler, broker, secret layout, health checks and incident/debugging material cannot fit coherently in `implementation-alignment-and-gaps.md` and the domain documents. Do not create a separate UX document; `frontend-and-navigation.md` is the correct home for the E4 contract.

## E. Highest-risk documentation losses

1. Execution and reconciliation state transitions, especially halt/recovery and residual-gap accounting.
2. Admin/Investor authorization and ownership matrix.
3. Portfolio cash/reservation/lending invariants.
4. Page Visit History and right utility rail acceptance contract.
5. V6 E4 non-regression and Dashboard preservation baseline.
6. Notification lifecycle separation from domain state.
7. Point-in-time and pinned-artifact reproducibility rules.
8. Dataset freshness and immutable-version gates.
9. Multi-strategy ownership/adoption and last-enabled strategy behavior.
10. Responsive state conventions and loading/empty/unknown distinctions.

These omissions are dangerous because a future engineer can produce a locally plausible change that violates a financial, authorization, reproducibility or UX invariant without seeing the original contract.

## F. V6 E4 reconciliation

The following current requirements from `V6-E4-Investor-UX-Client-Evolution.md` are missing or materially underrepresented in `docs/current/frontend-and-navigation.md`:

### Shell and navigation

- Five-zone application shell: top header, left navigation, main workspace, right utility rail/contextual pane, low-priority footer.
- Top header contents and preservation of contextual Help, existing theme selector, notification/status/account utilities and safety actions.
- Secondary header utilities may move to overflow at constrained widths but must not disappear.
- Left navigation expanded/collapsed states, tooltips/focus labels, active-state treatment, explicit toggle, persisted preference, mobile drawer behavior.
- Right utility rail as an extension point, compact by default, one large pane at a time, overlay-not-resize behavior, mobile menu/drawer/sheet transformation.
- Footer low-priority/collapsible behavior and prohibition on placing the only critical path there.

### Page anatomy and controls

- Breadcrumbs → page/entity header → tabs → segmented control/filter bar → primary content → advanced/detail hierarchy.
- Route-backed tabs with deep links and browser back/forward behavior.
- Segmented-control rule for 2–4 peer choices and dropdown/combobox rule for larger sets.
- Cards as conceptual grouping rather than card-soup; KPI/chart/table ordering; drawers/accordions for secondary detail.
- Shared component rules for Search, Help, ThemeToggle, tabs, status chips, icon buttons, filters, responsive tables, charts, integer spinner, ContextDrawer, Skeleton/EmptyState, ScrollToTop and PageHistoryRail.

### Page History and Contextual Notes

- Page Visit History desktop right-edge rail.
- Maximum last 12 visits, most-recent-first order and consecutive duplicate collapse.
- Current-page active state, bar geometry, `#1e90ff` active accent, quiet inactive state.
- Hover/focus label reveal, truncation tooltip/focus behavior and link semantics.
- Mobile History action opening a menu/sheet/drawer.
- Notes icon in the right utility rail, desktop overlay pane, no workspace resize, idle opacity/focus opacity and slide animation.
- Mobile near-full-width Notes drawer/sheet, fully opaque and no hover dependency.
- `prefers-reduced-motion` behavior for contextual panes.

### Dashboard and non-regression

- Dashboard as presentation/aggregation, not financial source of truth.
- Explicit preservation baseline: portfolio summary, cash/investable cash, movers, positions/diversification/relative strength, market health/gauges, alerts, calendar, holding patterns, allocation, growth, unrealized P/L history, rebuild history, snapshots, admin sync/status, deep links, refresh/cache behavior and local display preferences.
- No existing Dashboard metric removed without product-owner review.
- Noncritical widget visibility/order/placement preferences, useful complete defaults and reset-to-default; safety controls never hideable.
- Wide-display parallelism rather than stretched cards.
- Old-vs-new capability inventory gate before/after redesign.
- Preservation of micro-features: contextual links, analysis shortcuts, copy helpers, prompts, status explanations, guides and compact utility actions.

### Search, tables, charts and forms

- Expandable desktop Search and full-width/sheet mobile Search without disruptive reflow.
- Contextual Help remains page-relevant, not merely a generic docs-home link.
- Wide analytical tables, secondary-column hiding/detail expansion/card transformation/horizontal scroll fallback.
- Existing chart foundation, theme support, legends, tooltips, range selectors, hover/tap and accessible exact values.
- Toggle/segmented/dropdown/integer-spinner/link/button semantics and drawer/accordion/modal selection rules.

### Responsive, preferences, visual system and accessibility

- Mobile, tablet/small desktop, standard desktop and ultrawide behavior.
- Capability preservation over geometric similarity; touch-accessible alternatives for hover behavior.
- Versioned, namespaced, resettable localStorage preferences that never contain auth, execution authority or financial state.
- Existing `ThemeToggle`/`ThemeContext`, semantic colors, no color-only state, shared tokens and motion rules.
- Skeletons for content-heavy regions, focused spinners, and distinction between zero, unknown, unavailable, incomplete and not-applicable.
- ScrollToTop behavior and correct scroll-container targeting.
- Keyboard access, visible focus, touch targets, accessible names, reduced motion, no hover-only capability and exact chart values.

### Architecture and non-goals

- Incremental migration rule: inspect inventory, reuse foundations, migrate touched surfaces, compare old/new capability inventories and avoid unrelated rewrites.
- Non-goals: no new accounting/execution semantics, no big-bang rewrite, no duplicate theme system, no free-form dashboard designer, no silent data/link/convenience removal, no desktop-only capability, no weakened authorization.

## Final assessment

The current documentation set is a useful feature index and a reasonable first-pass product overview, but it is not yet a self-sufficient engineering knowledge base. The main migration failure is not that entire domains disappeared; it is that implementation-significant contracts were compressed away. The first enrichment target should be `frontend-and-navigation.md` with the E4 contract, followed by execution safety, portfolio accounting, authorization, notifications, and reproducibility/state-machine sections in the domain documents.

No source documentation was modified by this audit.
