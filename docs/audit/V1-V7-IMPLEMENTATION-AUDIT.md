# V1-V7 Requirement-to-Code Implementation Audit

**Date:** 2026-09-17
**Scope:** Phase 1 requirements marked `CURRENT`, `POSSIBLY-LOST-DURING-CONSOLIDATION`, or `NEEDS-INVESTIGATION`.
**Method:** Repository evidence only. No application code, tests, specifications, configuration, migrations, or deployment files were changed.

## 1. Executive Summary

The repository has substantial domain, API, and test coverage, but that is not equivalent to full product-contract coverage. AUD-001 has now been remediated statically: the Investor shell mounts a session-backed Page Visit History rail and mobile History sheet with focused state and router tests. The same shell still has no Global Search implementation. Contextual Notes are materially different: an investor-only trigger and fixed overlay pane are mounted, backed by API and persistence, but their placement and responsive behavior do not meet the complete E4 contract.

The original highest-risk static concern was in the live broker path: admission-time validation did not repeat at `BrokerGateway::placeOrder()`. AUD-015 was subsequently remediated with a fresh, non-consuming final policy check before every regular broker placement attempt, including bounded insufficient-funds retries. Focused race tests now cover post-admission halt, entitlement, reconciliation, readiness, window, retry, and multi-order changes. A controlled broker/runtime safety drill remains required; static evidence is not evidence that an unsafe order occurred.

The older V1/V2 feature-coverage audit is useful evidence of historical implementation claims, not proof of current reachability. It explicitly reports no V1 missing rows while also recording test failures and no React test suite; this audit therefore does not inherit its `IMPLEMENTED` conclusions without tracing current code and workflow.

## 2. Audit Method

- Phase 1 inventory: `docs/audit/V1-V7-REQUIREMENT-TRACEABILITY.md`.
- Current authority: `docs/current/README.md`, `docs/current/frontend-and-navigation.md`.
- E4 acceptance: `docs/archive/specs/V6-E4-Investor-UX-Client-Evolution.md`, especially §§3.1-3.5, 5, 8, 9, and the component foundations table.
- Historical implementation matrix: `docs/archive/audits/2026-08-09-feature-coverage-final/FEATURE-COVERAGE-MATRIX.md` and `SPECIFIED-BUT-UNIMPLEMENTED.md`.
- React shell/routes: `app/resources/js/src/App.jsx`, `app/resources/js/src/components/navigation/PageChrome.jsx`, `app/resources/js/src/components/ContextualNotesPane.jsx`, `app/resources/js/src/components/sidebar/Sidebar.jsx`.
- API/backend evidence: `app/routes/api.php`, `app/app/Http/Controllers`, `app/app/Services`, `app/app/Models`, `app/database/migrations`.
- Test evidence: `app/tests/Feature`, `app/tests/Unit`, and `app/tests/js`.

Evidence labels in the matrix mean: **direct** = code path and workflow are identifiable; **partial** = some criteria are evidenced but acceptance is incomplete; **test-only** = evidence is isolated to tests/support code; **runtime** = repository evidence cannot establish deployed behavior.

The audit reviewed every Phase 1 row marked `CURRENT`, `POSSIBLY-LOST-DURING-CONSOLIDATION`, or `NEEDS-INVESTIGATION` (85 total). `docs/current/**` supplies the accepted current contract. The Phase 1 traceability artifact supplies the originating requirement ID and acceptance detail. Source and test evidence were traced through React/routes, Laravel routes/controllers/services/models/migrations, scheduler registrations, and targeted tests where applicable. Archive material was used only to recover acceptance detail that current documentation intentionally references or preserves.

Static inspection cannot establish browser geometry, provider behavior, production scheduler/queue execution, deployment-secret configuration, or live-broker responses; those cases use `RUNTIME_VERIFICATION_REQUIRED` rather than an implementation claim.

## 3. Verdict Definitions

| Verdict | Meaning in this audit |
|---|---|
| `IMPLEMENTED` | Direct code and reachable workflow evidence cover the material acceptance criteria; deployment-only conditions may still require a runtime check. |
| `PARTIALLY_IMPLEMENTED` | A real implementation exists, but one or more material acceptance criteria, reachability paths, or state variants are absent or unproven. |
| `NOT_IMPLEMENTED` | No production implementation evidence exists for the accepted behavior after alternate paths were searched. |
| `IMPLEMENTED_BUT_NOT_WIRED` | Code exists but is not mounted, routed, linked, or otherwise reachable through its intended workflow. |
| `IMPLEMENTED_DIFFERENTLY` | Current behavior differs in form but is covered by an accepted superseding decision. |
| `TEST_ONLY` | Evidence is confined to tests, helpers, fixtures, or demo support and does not establish production reachability. |
| `DEAD_CODE` | The examined implementation appears superseded or unreachable. No code was removed. |
| `RUNTIME_VERIFICATION_REQUIRED` | Repository evidence is insufficient to decide a deployment/provider/browser-dependent contract. |
| `SPEC_CONFLICT` | Current accepted contracts conflict materially and cannot both be evaluated without a product decision. |

## 4. Domain Summary

| Domain | Implemented | Partial | Missing | Not wired | Runtime verify | Conflicts | Risk |
|---|---:|---:|---:|---:|---:|---:|---|
| Frontend / navigation | 2 | 5 | 2 | 0 | 1 | 0 | High |
| Portfolio / accounting / capital | 9 | 3 | 0 | 0 | 0 | 0 | High |
| Market data / data quality | 6 | 0 | 0 | 0 | 1 | 0 | High |
| Discovery / screeners / registries | 5 | 1 | 0 | 0 | 0 | 0 | Medium |
| Strategy / recommendations | 5 | 2 | 0 | 0 | 0 | 0 | High |
| Execution / broker / safety | 3 | 2 | 0 | 0 | 5 | 0 | Critical |
| Analytics / backtesting / simulation | 3 | 4 | 0 | 0 | 0 | 0 | High |
| Notifications / calendar / alerts | 1 | 2 | 0 | 0 | 0 | 0 | Medium |
| Knowledge / documentation | 3 | 1 | 0 | 0 | 0 | 0 | Medium |
| Administration / security / API | 5 | 1 | 0 | 0 | 1 | 0 | Critical |
| Trading artifacts / V7 | 4 | 1 | 0 | 0 | 4 | 0 | High |

The domain figures are orientation aids, not a second requirement count: several Phase 1 requirements deliberately span domains. The authoritative disposition remains the 85-row matrix below.

## 5. Detailed Requirement Audit

The following rows cover every applicable Phase 1 requirement. Related requirements are kept as separate rows even when their evidence is shared. “Missing/partial criteria” records the acceptance detail that code inspection could not prove.

| ID | Requirement | Verdict | Code Evidence | Test Evidence | Missing/Partial Criteria | Runtime Verification Needed | Notes |
|---|---|---|---|---|---|---|---|
| V1-REQ-001 | Market-data substrate for discovery/evaluation | IMPLEMENTED | Data sync, stock/price models and API paths exist | Data/sync feature tests | Full deployed freshness/query workflow | Yes | Domain path is present; deployment state is not proven |
| V1-REQ-002 | Discovery runs, candidates, evidence and filters | IMPLEMENTED | Discovery routes/pages/services | Discovery/screener tests | UI empty/error behavior | Yes | Reachable at `/candidates` |
| V1-REQ-004 | Five-stage recommendations and decision states | PARTIALLY_IMPLEMENTED | Recommendation page, services and APIs | Recommendation tests | Full approve/reject/defer and pending-execution UX not proven end to end | Yes | Pagination tests are not workflow proof |
| V1-REQ-005 | Cash ledger, reservations and release semantics | IMPLEMENTED | Cash page, controllers, ledger migrations/services | Cash/financial integrity tests | Browser and retry/error states | Yes | Backend evidence strong |
| V1-REQ-006 | Telegram notifications, idempotency, retry/history | PARTIALLY_IMPLEMENTED | Notification routes/provider/history page | Notification API/provider tests | Legacy skip/retry/history UX and runtime channel behavior | Yes | FEAT-004 supersedes channel implementation details |
| V1-REQ-007 | Manual execution with traceability | IMPLEMENTED | Transactions/pending routes and services | Execution/transaction tests | Live role/runtime verification | Yes | Safety requirements audited separately |
| V1-REQ-008 | Review dashboard/reports/outcomes | IMPLEMENTED | Review routes/pages/controllers | Review/report tests | Empty/loading/error UX | Yes | Report routes are explicitly wired |
| V1-REQ-009 | Strategy configuration and scoring weights | IMPLEMENTED | Strategy pages/registry/services | Strategy tests | Multi-strategy UX breadth | Yes | V3 row below remains separate |
| V1-REQ-010 | Sanctum auth and password reset | IMPLEMENTED | Auth context/routes/reset pages | Auth/password tests | Browser recovery flow | Yes | No evidence of JWT substitution |
| V1-REQ-011 | Corporate actions preserve ledger correctness | PARTIALLY_IMPLEMENTED | Corporate action page/services/migrations | Corporate action tests have recorded SQLite failures | Successful full apply path and fixture correctness | Yes | Historical audit records 3 errors and 1 API failure |
| V1-REQ-012 | Screener backtesting hit matrix | IMPLEMENTED | Screener editor/backtest services | Screener backtest tests | Responsive long-running state UX | Yes | Resumable backend path evidenced |
| V1-REQ-013 | Strategy backtesting | PARTIALLY_IMPLEMENTED | `/backtests` routes and simulation services | Unit coverage; no complete API feature proof | API workflow and runtime persistence | Yes | Historical audit notes no API feature tests |
| V1-REQ-015 | Engine/API/pipeline/deployment baseline | RUNTIME_VERIFICATION_REQUIRED | Laravel routes, console schedule, API v1 | Schedule/API tests | Deployed scheduler and cPanel/runtime parity | Yes | Repository cannot prove production operation |
| V2-REQ-001 | Admin invite lifecycle | IMPLEMENTED | User management/invite routes and page | Invite/auth tests | Email/token delivery deployment | Yes | AuthZ tests present |
| V2-REQ-002 | Session listing/revocation | IMPLEMENTED | Account settings/session APIs | Session tests | Multi-browser interaction | Yes | Admin force logout separate |
| V2-REQ-003 | Data-quality detection/resolution center | IMPLEMENTED | Admin page, issue APIs/services | Data-quality tests | Full operator workflow and failure states | Yes | Need deployed role check |
| V2-REQ-004 | Corporate-action price repair | IMPLEMENTED | Repair/history pages/services | Data-quality/corporate-action tests | Production data and audit trail | Yes | Code path exists |
| V2-REQ-005 | Portfolio alert policies | PARTIALLY_IMPLEMENTED | Alert policy page/API | Alert policy tests | Digest/ack/clear UI and delivery semantics | Yes | FEAT-004 details are more specific |
| V2-REQ-006 | Bulk CSV transaction import | IMPLEMENTED | Import endpoint/service/UI path | Bulk import tests | Large-file and partial-failure UI | Yes | Shared write path evidenced |
| V2-REQ-007 | Historical holdings reconstruction | IMPLEMENTED | Historical page/API/services | Historical holdings tests | As-of empty/error UX | Yes | Reachable route exists |
| V2-REQ-008 | Shared screener import | IMPLEMENTED | Registry/import APIs and pages | Registry/auth tests | Cross-user runtime authorization | Yes | Test evidence includes isolation |
| V2-REQ-009 | Non-mutating recommendation preview | IMPLEMENTED | Preview endpoint/service | Preview tests | UI discoverability and no-side-effect browser proof | Yes | Backend contract is clear |
| V2-REQ-010 | Contextual help/docs synchronization | PARTIALLY_IMPLEMENTED | Documentation page and generated public docs | Limited docs/API tests | Stale/orphan detection and contextual link coverage | Yes | Current doc records less than archived acceptance |
| V2-REQ-011 | Knowledge Board notes/tags/images/search/export | IMPLEMENTED | Knowledge pages/API/migrations | Wiki/knowledge tests | Complete accessibility/responsive behavior | Yes | Contextual Notes is separate V6 contract |
| V3-REQ-001 | Many enabled strategies per portfolio | PARTIALLY_IMPLEMENTED | Strategy registry/binding models/services | Strategy/artifact tests | UI proof of concurrent strategy operation | Yes | Backend and artifact bindings are present |
| V3-REQ-002 | Fit/outcome/ranking/capital separation | IMPLEMENTED | Strategy/recommendation services | Strategy/performance tests | Representative production data | Yes | Deterministic semantics appear preserved |
| V3-REQ-003 | Strategy-owned holding identity | IMPLEMENTED | Portfolio/holding models/services | Financial integrity/ownership tests | Full multi-owner browser workflow | Yes | High-risk boundary |
| V3-REQ-004 | Unmanaged holdings/adoption | PARTIALLY_IMPLEMENTED | Holding adoption/domain paths | Some ownership tests | Adoption discoverability and conflict/error UX | Yes | Current docs are broad |
| V3-REQ-005 | Capital allocation/partial funding | IMPLEMENTED | Cash allocation/execution services | Cash/execution tests | Live partial execution verification | Yes | Safety runtime needed |
| V3-REQ-006 | Lending/recall/bridge accounting | PARTIALLY_IMPLEMENTED | Service/domain references found | Limited targeted evidence | End-to-end UI, notifications, FIFO recall | Yes | Acceptance is mostly archived |
| V3-REQ-007 | Exit/SL/trailing/horizon attribution | IMPLEMENTED | Strategy/execution services | Strategy/execution tests | Broker/runtime halt behavior | Yes | Must inspect live mode separately |
| V3-REQ-008 | Single cash pool/reserve warning | IMPLEMENTED | Cash/reserve calculations/pages | Financial integrity tests | Dashboard warning and withdrawal edge UX | Yes | Presentation contract is not fully tested |
| V3-REQ-009 | Historical corpus/ranking constraints | IMPLEMENTED | Backtest/evaluation services | Backtest/evaluation tests | Production dataset provenance | Yes | No product-depth cap inferred from code |
| V3-REQ-010 | Multi-strategy UI/product surfaces | PARTIALLY_IMPLEMENTED | Strategy, holdings, capital and recall pages/routes exist, backed by ownership and lending services | `V3CapitalLendingAccountingTest`, recall/capital API tests and JS UI checks cover portions | No complete investor workflow evidence connects concurrent strategies, owned/unmanaged holdings, capital badges, lending/recall and lifecycle edge states | Yes | `AUD-005`; route existence is not treated as complete surface proof |
| V4-REQ-001 | Broker/live execution automation | RUNTIME_VERIFICATION_REQUIRED | Broker adapter, execution modes, TOTP paths | Fake-broker/safety tests | Broker credentials, entitlement and deployed mode | Yes | Financially sensitive |
| V4-REQ-002 | GTT/advanced orders | PARTIALLY_IMPLEMENTED | Order services/routes/migrations | Order/broker tests | Partial-fill and manual-mode runtime behavior | Yes | Must verify no unintended auto placement |
| V4-REQ-003 | Market regime assessment | IMPLEMENTED | Market analysis services and scoring use | Analytics tests | Unavailable-input behavior in UI | Yes | Formula acceptance needs representative data |
| V4-REQ-004 | Liquidity/tradability calculators | IMPLEMENTED | Calculator services and discovery UI | Calculator tests | Null/insufficient-input presentation | Yes | Screenability needs runtime sample |
| V4-REQ-005 | Review report list/detail | IMPLEMENTED | Both routes are mounted in `App.jsx` | Report tests | Pagination/empty states in browser | Yes | No filter/sort must remain intentional |
| V4-REQ-006 | Unattended daily operations | RUNTIME_VERIFICATION_REQUIRED | `routes/console.php`, scheduler and pipeline commands | Schedule tests | Production cron, idempotency and alert delivery | Yes | Code cannot establish deployment |
| V4-REQ-007 | Stocks admin SPA | IMPLEMENTED | Admin route/page/API | Stock admin tests | Admin production role | Yes | Search/pagination tested |
| V4-REQ-008 | Backtest duplicate | IMPLEMENTED | Backtest pages/services | Backtest tests | Browser reachability of duplicate action | Yes | Inspect button/link in runtime |
| V4-REQ-009 | Strategy params into evaluation | IMPLEMENTED | Strategy/evaluation services | Strategy/evaluation tests | Data provenance in UI | Yes | No contradiction found |
| V4-REQ-010 | Hard dataset freshness gate | IMPLEMENTED | Dataset version/gate services | Dataset/pipeline tests | Deployed stale-data failure path | Yes | High operational importance |
| V4-REQ-011 | Immutable dataset versions | IMPLEMENTED | Dataset migrations/models/services | Dataset tests | Production immutability and retention | Yes | Backend evidence strong |
| V4-REQ-012 | `markExecuted` ownership | IMPLEMENTED | Recommendation/execution APIs | Execution ownership tests | Cross-role browser verification | Yes | Inspect authorization middleware |
| V4-REQ-013 | OpenAPI `/api/v1` | PARTIALLY_IMPLEMENTED | Public docs/OpenAPI asset and routes | API contract tests | Generated spec freshness and complete parity | Yes | Static artifact can drift |
| V4-REQ-014 | UI smoke tests | TEST_ONLY | JS test helpers and selected component tests | Tests exist | Does not prove production route coverage | Yes | No runtime conclusion from tests |
| V4-REQ-015 | Controller split/hooks | IMPLEMENTED | Laravel controller/service structure | Feature tests | No acceptance gap found | No | Architectural evidence only |
| V4-REQ-016 | Logging/pagination consistency | PARTIALLY_IMPLEMENTED | Shared API pagination patterns | Pagination tests | All screens and error envelopes | Yes | Spot-check needed |
| V4-REQ-017 | Pluggable evaluation rules | IMPLEMENTED | Rule services/registries | Evaluation tests | Admin configuration discoverability | Yes | No direct UI acceptance proof |
| V4-REQ-018 | Repository layer | IMPLEMENTED | Repository/service classes | Unit/feature tests | No independent completeness proof | No | Structural requirement |
| V4-SPEC-001 | Same-stock adoption merge | PARTIALLY_IMPLEMENTED | Holding/adoption code paths | Ownership tests | Conflict resolution and attribution UI | Yes | Audit `AUD-005` |
| V5-REQ-002 | Channel-neutral notification service | PARTIALLY_IMPLEMENTED | Notification settings, providers, history | Notification tests | Critical banner/read semantics/retry/reminder UX | Yes | `AUD-006` |
| V5-REQ-003 | Indicator registry versioning | IMPLEMENTED | Admin registry routes/page/API | Registry tests | Version rollback UX and role access | Yes | Inspect live admin route |
| V5-REQ-004 | Trading Artifact Framework | RUNTIME_VERIFICATION_REQUIRED | Artifact library/pages, models, bindings, deployment services | Extensive artifact tests | Full rollout gates, representative data and UI workflow | Yes | `AUD-007`; historical closure still had verification gates |
| V5-REQ-005 | Admin force logout | IMPLEMENTED | Admin routes/services | Admin session tests | Production multi-session behavior | Yes | Requires admin runtime |
| V5-REQ-006 | Cash-as-of/export/compare | IMPLEMENTED | Cash/history/compare pages and APIs | Cash/export tests | Download/browser error states | Yes | Route reachability inspect |
| V5-REQ-007 | Tax/attribution/benchmarks | PARTIALLY_IMPLEMENTED | Performance/tax page/services | Performance tests | Full benchmark and export acceptance | Yes | Current docs are summarized |
| V5-REQ-008 | Paper/Replay/Backtest | PARTIALLY_IMPLEMENTED | Backtest routes/simulation services | Unit/feature tests | Distinct semantics and UI controls need runtime proof | Yes | `AUD-008` |
| V5-REQ-009 | CI workflow | RUNTIME_VERIFICATION_REQUIRED | Repository workflow/config evidence | CI files are not execution proof | Current CI status and deployed artifact | Yes | Inspect external CI only in Phase 2 follow-up |
| V5-REQ-010 | Production secrets/single-folder deploy | RUNTIME_VERIFICATION_REQUIRED | Deploy scripts and config docs | No test can prove production secret placement | Live deploy path, secrets and cache | Yes | `AUD-009` |
| V5-REQ-011 | Discovery inline default screener | IMPLEMENTED | Candidates/discovery pages/routes | Discovery tests | Responsive and empty states | Yes | Verify discoverability |
| V5-REQ-012 | Richer evaluation history UX | IMPLEMENTED_DIFFERENTLY | `/evaluations` redirects to `/candidates`; review/history routes exist | API/history tests | Exact historical UX contract changed by FEAT-034 | Yes | This is an accepted superseding implementation |
| V5-REQ-013 | Dashboard broker readiness | PARTIALLY_IMPLEMENTED | Dashboard and broker readiness services | Dashboard/broker tests | Stale/empty/entitlement UI | Yes | E4 non-regression applies |
| V5-REQ-014 | Exchange holidays | IMPLEMENTED | Calendar migrations/services/pages | Calendar tests | External sync credentials/runtime | Yes | `AUD-010` only if production sync fails |
| V5-REQ-015 | Holiday-aware execution | IMPLEMENTED | Scheduler/execution checks | Schedule/execution tests | Production timezone/holiday behavior | Yes | Financial safety |
| V5-REQ-016 | Kite reconciliation | RUNTIME_VERIFICATION_REQUIRED | Reconcile command/services and schedule | Reconciliation/schedule tests | Broker data, halt/attention and live behavior | Yes | `AUD-011` |
| V5-REQ-017 | Linked Markdown Wiki | IMPLEMENTED | Wiki pages/routes/API | Wiki tests | Public/share role and rendering runtime | Yes | Sanitization tested |
| V5-REQ-018 | Role-separated Admin/Investor apps | PARTIALLY_IMPLEMENTED | `AdminAppRoutes`, `AdminRoute`, role branches in `App.jsx` | AuthZ/admin tests | Complete page/API matrix and production data ownership | Yes | `AUD-012` |
| V6-REQ-001 | Execution safety controls | RUNTIME_VERIFICATION_REQUIRED | Emergency/safety services and routes | Safety tests | Broker/live mode, halt, idempotency and operator UI | Yes | Critical financial path |
| V6-REQ-002 | Paper experimentation | PARTIALLY_IMPLEMENTED | Paper/backtest pages/services | Paper tests | End-to-end isolation and controls | Yes | Related to V5-REQ-008 |
| V6-REQ-003 | Admin audit explorer | IMPLEMENTED | Admin route/page/API | Audit explorer tests | Production authorization and pagination | Yes | Mounted under admin route |
| V6-REQ-004 | Responsive client support | PARTIALLY_IMPLEMENTED | Responsive CSS/components and mobile branches | Limited JS tests | E4 page-by-page mobile criteria | Yes | No broad visual regression evidence |
| V6-REQ-005 | Frontend stack migration | IMPLEMENTED | React/Vite shell is active | JS/component tests | Production build/runtime parity | Yes | Structural, not UX completeness |
| V6-REQ-006 | Dashboard/UX reorganization and non-regression | RUNTIME_VERIFICATION_REQUIRED | Dashboard and redesigned routes are mounted, but no old-versus-current capability inventory or product-owner removal record was found | Related page tests cover individual paths only | Preservation of every named metric, shortcut, external/copy/LLM helper, status explanation and diagnostic convenience cannot be decided statically from route presence | Yes | `AUD-013`; this is an evidence and historical-comparison gap, not proof every surface regressed |
| V6-REQ-007 | Global shell: header, left nav, workspace, right rail/pane | PARTIALLY_IMPLEMENTED | `AuthenticatedShell` mounts `AppHeader`, `Sidebar`, `.lido-main`, `RightUtilityRail`, and `ContextualNotesPane`; Notes remains a fixed overlay | `roleSeparatedShell.test.mjs` inspects shell source; Page History tests cover the new rail independently | No Global Search component/state; no mounted low-priority footer; Notes is a floating button rather than a demonstrated shared utility rail | Yes | `AUD-002`, `AUD-016`; constrained-width behavior requires browser inspection |
| V6-REQ-008 | Page Visit History/right-edge rail | IMPLEMENTED | `RightUtilityRail` mounts `PageHistoryRail` for the authenticated Investor shell; `usePageVisitHistory` observes normalized React Router paths, stores user-keyed session history, and applies 12-entry MRU/consecutive-collapse semantics | `page-history.test.jsx` covers state semantics, persistence validation, active links, router navigation and mobile Escape/focus behavior | Exact desktop geometry, touch layout, stacking and visual acceptance require browser verification; source tests do not prove CSS media-query rendering | Yes | `AUD-001`; statically resolved, with browser/runtime verification retained |
| V6-REQ-009 | Contextual Notes shell placement | PARTIALLY_IMPLEMENTED | `ContextualNotesPane.jsx` creates a fixed right/bottom trigger and fixed 360px overlay; `App.jsx:266` mounts it for investor routes; profile-scoped API is routed | `V6ContextualNotesTest` proves personal/profile-scoped CRUD, not UI behavior | No persistent vertical utility rail, slide/focus treatment, pane-specific reduced-motion rule, or mobile drawer/sheet implementation; route-derived context key loses parameter/entity distinction | Yes | `AUD-002`; the overlay does not resize `.lido-main`, which is aligned |
| V6-REQ-010 | Standard page anatomy/components | PARTIALLY_IMPLEMENTED | PageChrome/breadcrumbs mounted; route pages exist | Some JS helpers | Tabs/segmented controls/drawers/skeletons/EmptyState/ScrollToTop/PageHistory adoption is inconsistent/unproven | Yes | `AUD-003` |
| V6-REQ-011 | Trusted scoped API tokens | IMPLEMENTED | Token routes/services/models | Token/auth tests | Production revocation and execution gates | Yes | Security-sensitive |
| V6-REQ-012 | Contextual Notes content | IMPLEMENTED | Notes pane, API and migration | `V6ContextualNotesTest` | Full browser scope/account/portfolio and responsive behavior | Yes | Shell placement is separate |
| V7-REQ-001 | Point-in-time-safe ML scoring/lifecycle | RUNTIME_VERIFICATION_REQUIRED | ML admin/page/services/models/migrations | V7 ML tests | Trained model artifacts, promotion/rollback/drift in deployed environment | Yes | Local verification does not prove production |
| V7-REQ-002 | Fundamental data integration | RUNTIME_VERIFICATION_REQUIRED | Fundamental admin/page/provider/services/migrations | V7 fundamental tests | Provider freshness, revisions and historical bootstrap boundary | Yes | Historical bootstrap is deferred; live current data still needs check |
| V7-REQ-003 | `stox_` namespace enforcement | PARTIALLY_IMPLEMENTED | The V7 fundamentals/ML migration creates `stox_` tables; legacy `portfolio_*` tables remain by design | `StoxNamespaceValidationTest` asserts only that one V7 migration's created tables are prefixed | No all-new-object validation, deployed-schema inventory, or enforcement across later migrations | Yes | The targeted test is meaningful but deliberately narrow; full legacy cutover is not the current requirement |

## 6. Gap Register

Only findings requiring attention are listed here. Resolved items remain for historical traceability when a later remediation changes their static disposition. Severity is provisional and confidence reflects repository evidence, not business impact alone.

| Audit ID | Requirement | Area/domain | Expected behavior | Actual implementation observed | Verdict | Severity candidate | Confidence | Source specification | Relevant code paths | Relevant tests | Missing test coverage | Runtime/manual verification required | Dependencies/related findings | Suggested remediation direction |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| AUD-001 | V6-REQ-008 | Navigation / page chrome | Desktop right-edge history rail and mobile History action with 12-entry semantics | Resolved statically. `RightUtilityRail` mounts `PageHistoryRail` and the mobile History sheet in the Investor shell; `usePageVisitHistory` provides normalized route observation, user-keyed session persistence, 12-entry MRU ordering and consecutive-duplicate collapse | IMPLEMENTED | High | High | `V6-E4-Investor-UX-Client-Evolution.md` §§3.5, 8 | `App.jsx`; `components/navigation/RightUtilityRail.jsx`; `PageHistoryRail.jsx`; `PageHistorySheet.jsx`; `hooks/usePageVisitHistory.js`; `utils/pageVisitHistory.js`; `styles/lido-app.css` | `page-history.test.jsx` covers state, storage, active link, navigation, mobile dialog/Escape/focus | Browser visual geometry, responsive/touch behavior, stacking and deployed-bundle reachability | Investor desktop/mobile runtime verification | AUD-002, AUD-003, AUD-016 | Remediation implemented. Verify browser geometry and deployed behavior; keep AUD-002, AUD-003 and AUD-016 separate |
| AUD-002 | V6-REQ-007, V6-REQ-009 | Global shell / contextual notes | Shared right utility rail opens an overlay pane without resizing desktop workspace; mobile uses drawer/sheet and respects reduced motion | `ContextualNotesPane.jsx` mounts an investor-only fixed bottom-right button and a fixed 360px overlay. It does not create a persistent shared rail, slide animation, pane reduced-motion rule, or mobile sheet. `.lido-main` is not resized. | PARTIALLY_IMPLEMENTED | Medium | High | E4 §§3.1-3.5, 9 | `App.jsx:253-270`; `ContextualNotesPane.jsx`; `styles/lido-app.css:275-315` | `V6ContextualNotesTest` is API/domain-oriented | Shell interaction, focus management, visual/mobile behavior | Desktop/mobile runtime inspection | AUD-001, AUD-003 | Reconcile the mounted overlay with the accepted rail/pane contract after human review |
| AUD-003 | V6-REQ-010 | UX consistency | Standard page anatomy and component foundations adopted consistently | PageChrome/breadcrumbs are mounted, but adoption of tabs, segmented controls, drawers, skeletons, EmptyState, ScrollToTop and preference utilities is not demonstrated globally | PARTIALLY_IMPLEMENTED | Medium | Medium | E4 component foundations and page anatomy sections | `PageChrome.jsx`; page components; `App.jsx` | Selected JS tests only | Page-by-page adoption and state coverage | Desktop/mobile route sweep | AUD-001, AUD-002, AUD-013 | Build an evidence inventory by route and compare it with E4 required anatomy |
| AUD-004 | V6-REQ-006 | Non-regression | Dashboard, Holdings, Strategy, Discovery, Recommendations, Backtests, Knowledge and Admin retain prior capabilities | No old-vs-current inventory or executable gate was found; routes alone cannot prove retained controls | RUNTIME_VERIFICATION_REQUIRED | High | Medium | E4 non-regression gate §§2-3 | `App.jsx`; named page components | Related page tests, no comparison gate | Before/after capability evidence | Runtime walkthrough plus git-history comparison | AUD-003 | Produce a capability-by-capability comparison before any remediation |
| AUD-005 | V3-REQ-010, V4-SPEC-001 | Multi-strategy UX / ownership | Users can discover and operate strategy-owned/unmanaged holdings, capital badges, lending/recall and lifecycle controls | Backend, API and discrete UI surfaces exist, but the complete investor workflow and conflict states are not proven as one reachable journey | PARTIALLY_IMPLEMENTED | High | Medium | V3 specification §§1-2 and V4 adoption decisions | Strategy/holding/recommendation pages and services | Ownership/financial tests and recall/capital UI checks cover pieces | Full UI and cross-strategy acceptance | Multi-portfolio runtime workflow | AUD-003 | Trace each surface from nav to API to persistence and role boundary |
| AUD-006 | V5-REQ-002, V1-REQ-006 | Notifications | Critical persistence/read semantics, channel health, retry and reminder behavior are reachable and clear | Providers/settings/history/banner exist, but acceptance detail is spread across archived docs and not proven as one workflow | PARTIALLY_IMPLEMENTED | Medium | Medium | `V5-FEAT-004-Notification-Service.md` | notification components, routes, services | Notification settings/provider tests | Browser UX, delivery failure and read semantics | Configured/unconfigured channel tests in deployed environment | AUD-010 | Verify notification lifecycle from domain event through banner/history/channel |
| AUD-007 | V5-REQ-004 | Trading artifacts | Authoring, versioning, dependencies, bindings, rollout, rollback and evidence gates are complete | Extensive implementation and tests exist; historical status still calls for representative/full rollout verification | RUNTIME_VERIFICATION_REQUIRED | High | Medium | V5 FEAT-008 spec, rollout runbook, implementation status | artifact controllers/services/models/pages/migrations | Artifact library/binding/package/deployment tests | Full rollout and production data evidence | Staged representative artifact and rollback drill | AUD-012 | Perform the documented rollout evidence check; do not infer closure from unit tests |
| AUD-008 | V5-REQ-008, V6-REQ-002 | Backtest/paper semantics | Backtest, replay and paper execution are distinct, safely isolated and reachable | Routes/services exist, but the product-level distinctions and controls are not established by current tests | PARTIALLY_IMPLEMENTED | Medium | Medium | V5 FEAT-020 and V6 E2 specs | backtest pages/services/routes | Unit and selected feature tests | UI semantics, isolation and error states | Run each workflow with representative portfolio | AUD-013 | Compare UI controls and persisted event model against the three-mode contract |
| AUD-009 | V5-REQ-010 | Deployment/secrets | Production uses the documented single-folder secret/deploy arrangement | Repository scripts/docs exist; live deployment and secret placement cannot be proven locally | RUNTIME_VERIFICATION_REQUIRED | High | High | V5 FEAT-031 and deployment docs | `deploy/`, `DEPLOYMENT_*`, config/bootstrap | No repository test proves live deployment | Current production artifact, secret path and cache | Inspect deployed host and run non-destructive probes | V4-REQ-006, V7-REQ-001/002 | Verify deployed commit, secrets, scheduler and build output against the runbook |
| AUD-010 | V5-REQ-014, V5-REQ-015 | Calendar/execution | Exchange holiday sync and holiday-aware execution behave correctly in production timezone | Code/schedule tests exist; external sync and live timezone behavior remain unverified | RUNTIME_VERIFICATION_REQUIRED | Medium | Medium | V5 FEAT-038/039 | calendar/execution services and scheduler | Calendar/schedule tests | External provider and timezone edges | Test next holiday/market-day boundary in staging | AUD-009, AUD-011 | Verify actual provider data and scheduler timezone |
| AUD-011 | V5-REQ-016, V6-REQ-001 | Reconciliation/live safety | Reconciliation mismatch halts unsafe execution, creates attention state and is operator-visible | Services/jobs/schedule/tests exist; no production broker mismatch drill evidence | RUNTIME_VERIFICATION_REQUIRED | High | High | V5 FEAT-040 and V6 E1 | reconcile command, broker services, scheduler | Reconciliation/schedule/safety tests | Real broker payloads, halt clearing, retry/idempotency | Controlled staging mismatch and recovery | AUD-009, AUD-010 | Conduct a read-only or sandbox reconciliation drill and capture halt/recovery evidence |
| AUD-012 | V5-REQ-018 | Authorization/security | Admin and investor applications expose only intended routes/data and preserve ownership | High-risk actor/resource assurance now covers profile selection aliases, foreign portfolio members, Admin/shared routes, PAT ownership/scope, contextual notes and Kite state expiry/initiator binding. `PortfolioProfile` binding uses the Sanctum guard so a valid bearer token can resolve only its owner's member route. The complete route/API/data matrix and deployed behavior remain unproven. | RUNTIME_VERIFICATION_REQUIRED | High | Medium | V5 FEAT-042 | `App.jsx`; `AdminRoute`; `ResolveActivePortfolio.php`; `PortfolioProfile.php`; auth middleware/controllers | `RoleSeparatedApplicationTest`, `PortfolioMiddlewareTest`, `V6PersonalApiTokenTest`, `V6ContextualNotesTest`, `KiteCallbackTest` | Unenumerated member/nested routes, execution PAT composition, artifact/grant matrix and production behavior | Test both roles against full route matrix with production-like data | AUD-005, AUD-009 | AUTH-001/AUTH-002 assurance remediation is partially complete; retain AUTH-003 deployment verification and expand the remaining route families before declaring the matrix closed. |
| AUD-013 | V6-REQ-006, V5-REQ-013 | Dashboard and redesign | Redesign retains old controls, information, shortcuts and clear state handling | Current pages/routes exist, but repository has no complete old/current inventory gate | RUNTIME_VERIFICATION_REQUIRED | High | Medium | V6 E4 non-regression inventory | dashboard and named page components | Page-specific tests only | All eight named surfaces and state variants | Historical git comparison plus runtime walkthrough | AUD-003, AUD-004 | Reconstruct the before/after capability matrix from repository history |
| AUD-014 | V7-REQ-003 | Database governance | New StoX objects use canonical namespace and automated checks prevent future drift | The only discovered validation reads `2026_09_12_100001_v7_stox_fundamentals_and_ml.php` and asserts its `Schema::create` names start `stox_`; it does not inventory all new objects or deployed schema. | PARTIALLY_IMPLEMENTED | Medium | High | V7 StoX Database Namespace Specification | `database/migrations`; `tests/Unit/V7/StoxNamespaceValidationTest.php` | Targeted migration-prefix test passes | Complete object inventory and future enforcement | Inspect deployed schema | AUD-009 | Compare migrations/models/schema against the namespace decision and record intentional exceptions |
| AUD-015 | V4-REQ-001, V5-REQ-015, V6-REQ-001 | Live execution safety | Authority, halt, reconciliation, mode, TOTP and broker readiness are revalidated immediately before every broker submission | Resolved statically. `ExecutionGate::assertCurrentBrokerSubmissionState()` reloads User/Profile state without consuming TOTP; `submitOne()` reruns order-specific state/window checks and this pure gate after sizing/duplicate checks, before decision/order creation and `BrokerGateway::placeOrder()`. | IMPLEMENTED | High | High | `docs/current/execution-broker-safety.md` §4 and §15; V6 E1 | `ExecutionGate.php`; `LiveBrokerExecutionService.php`; `FakeBrokerGateway.php` test seam | `LiveExecutionFeatureTest` now proves post-admission halt, entitlement, reconciliation, broker-disconnect, window-close, MarginException-retry, multi-order, and stock-invalidation behavior; related unattended, reconciliation, GTT, readiness, controller, and TOTP suites pass | Controlled provider/concurrency safety drill remains repository-unprovable | Controlled broker/runtime safety drill | AUD-011, AUD-012 | Remediation implemented. Keep GTT/protection final-gate policy as a separate follow-up; do not infer it from this regular-order change. |
| AUD-016 | V6-REQ-007 | Header / navigation | Header provides compact Global Search that expands without disruptive reflow and an appropriate constrained-width form | `AppHeader.jsx` renders brand, portfolio switcher, safety controls, notification bell, Help and profile menu; no search component, state, handler, API call, or test exists. The only current-doc anchor explicitly says none is confirmed. | NOT_IMPLEMENTED | Medium | High | V6 E4 §3.2; `docs/current/frontend-and-navigation.md:39-51, 261` | `app/resources/js/src/components/AppHeader.jsx:41-78`; React search for `GlobalSearch`/search controls | No meaningful test found | Desktop expansion, mobile surface and a11y acceptance | Browser check only to rule out an externally injected deployed control | AUD-001, AUD-002 | Reconstruct the accepted global-search workflow and header overflow behavior after human approval |

The table above is the complete gap register. It contains only confirmed implementation/alignment concerns or bounded runtime-verification concerns, not every incomplete test assertion.

## 7. Wiring / Reachability Findings

- **Not implemented in the active shell:** header Global Search. AUD-001 Page Visit History is now mounted for the Investor shell and covered by focused state/router tests; exact responsive geometry remains a runtime question.
- **Mounted but contract-partial:** `ContextualNotesPane` is rendered from `AuthenticatedShell` for non-documentation Investor routes. Its `ContextualNoteController` API is profile-scoped and tested, but the visible control is a floating button and fixed pane, not the E4 shared rail/mobile-sheet interaction.
- **Mounted structural zones:** `AppHeader`, `Sidebar`, `PageChrome`, and `.lido-main` are directly mounted in `App.jsx`. The CSS contains `.lido-bottom-nav` rules but no JSX mount or `lido-footer-visible` producer was found, so the low-priority footer zone is not evidenced as active.
- **Reachable by route but not necessarily discoverable:** review reports, artifact library, backtests, admin registries and several settings pages are explicitly routed; sidebar discoverability still requires role-specific runtime inspection.
- **Intentionally redirected:** `/evaluations` redirects to `/candidates`; this is consistent with the Phase 1 superseding decision, not an unwired feature.
- **Test-only evidence:** source-reading JS tests such as `roleSeparatedShell.test.mjs` and `notificationCenterShell.test.mjs` establish selected source patterns, not mounted-browser or responsive acceptance.

## 8. Dead / Legacy Code

- The `.lido-bottom-nav` / `html.lido-footer-visible` stylesheet has no corresponding JSX reference in `app/resources/js/src`. This is **candidate dead CSS**, not proof of a removed feature; it is recorded because the accepted shell includes a low-priority footer zone.
- Legacy `/api/*` paths and the `/evaluations` redirect are intentional compatibility/supersession paths, not dead code findings.
- No other service was classified `DEAD_CODE` solely because its route or test coverage was incomplete.

## 9. Runtime Verification Checklist

1. Inspect Investor desktop and mobile at the deployed build for shell geometry, sidebar collapse/drawer behavior, notes focus trapping/escape behavior, constrained header overflow, and all loading/empty/error surfaces.
2. Run a controlled Kite sandbox or read-only production drill for connection expiry, reconciliation mismatch, emergency halt/recovery, partial fill, insufficient funds, and protection preservation.
3. Verify the scheduler/queue worker, market timezone, holiday sync, data freshness, reconciliation, artifact deployment, ML/fundamental jobs, and delivery workers are actually executing the deployed commit.
4. Test Sanctum cookie/CSRF, invite/reset/email verification, callback return handling, personal-token revocation, role/profile ownership, and scope enforcement against production-like identities.
5. Exercise configured and unconfigured Telegram, email and webhook channels without allowing a notification failure to mutate the source domain state.
6. Run representative backtest, replay, and paper workflows to establish UI reachability, isolated persistence, pinning, cancel/retry behavior, and historical-data discipline.

## 10. Cross-Domain Consistency Findings

- **Final broker gate versus documented invariant:** AUD-015 now revalidates fresh authority/state at the regular `placeOrder()` boundary. Provider behavior and concurrent production safety changes still require the documented controlled runtime drill.
- **Shell contract versus implementation:** current frontend documentation correctly retains the V6 E4 rail, Page History, Global Search and footer contract. The mounted React shell now implements Page History statically; Global Search, footer and the complete Notes rail contract remain separate alignment items (`AUD-002`, `AUD-016`).
- **Notes context precision:** the notes API accepts `subject_type` and `subject_id` (`V6ContextualNotesTest`), but the mounted pane derives only a sanitized pathname and never supplies subject values. Entity-level contextual notes therefore require workflow verification beyond page-scoped CRUD.
- **Accounting/execution funds boundary:** broker funds are fetched immediately before BUY sizing and pending SELL proceeds are not used as broker funds in `LiveBrokerExecutionService`; no contrary static path was found. Production broker-field semantics remain runtime verification.
- **State versus notification boundary:** notification delivery tests prove bounded retry and condition suppression; no static path was found that turns delivery failure/read state into a recommendation, accounting, halt, or broker mutation. Browser/banner semantics remain partial.

## 11. Test Gaps

No meaningful automated test covers the complete V6 E4 header Global Search contract. `page-history.test.jsx` proves Page History state, persistence validation, active links, client-side navigation and mobile dialog behavior; it does not prove desktop/mobile CSS geometry, touch layout, stacking or deployed-bundle reachability. `V6ContextualNotesTest` proves personal/profile-scoped CRUD only; it does not prove the E4 rail, mobile transformation, motion, focus, or entity context behavior. The following accepted contracts also have insufficient end-to-end test evidence:

Targeted audit checks passed on 2026-09-17: `V6ContextualNotesTest`, the Admin-investor rejection case in `RoleSeparatedApplicationTest`, the emergency-halt case in `LiveExecutionFeatureTest`, the bounded notification-retry case in `NotificationDeliveryProcessorTest`, and `StoxNamespaceValidationTest`. These targeted passes validate only their stated cases and do not change any primary verdict.

Other acceptance areas with no complete test evidence include:

- V3 multi-strategy investor surfaces and the full unmanaged/adoption workflow.
- V6 E4 non-regression across the eight named redesigned areas.
- Page-by-page adoption of the E4 standard anatomy and state components.
- Production secrets/deployment, production scheduler, external holiday sync, broker reconciliation and live safety behavior.
- Full V5 FEAT-042 role/data ownership matrix.
- V7 namespace drift prevention across the full schema.

- Final safety-gate revalidation between batch admission and broker `placeOrder()`.
- Low-priority footer mounting and any footer-only information placement.

## 12. V6 E4 Findings

1. **V6-REQ-006:** No old/current capability inventory or recorded product-owner removal evidence was found. This is a verification failure, not proof that every named page regressed.
2. **V6-REQ-007:** Header, collapsible sidebar, main workspace and notes overlay are mounted. Global Search, Page History and a mounted footer zone are absent; a shared right utility rail is not evidenced.
3. **V6-REQ-008:** Page Visit History is statically resolved by AUD-001; desktop/mobile geometry and deployed-bundle reachability remain runtime checks.
4. **V6-REQ-009:** Contextual Notes have a reachable overlay and tested data model, but differ from the rail/mobile/reduced-motion contract.
5. **V6-REQ-010:** Breadcrumbs/PageChrome are active. Global adoption of tabs, segmented controls, drawers, skeletons, EmptyState, ScrollToTop and preference utilities is not demonstrated.

## 13. Phase 1 Assumptions Disproven Or Narrowed By Code Inspection

- “Contextual Notes may only exist as a filename hint” is too weak: the pane is mounted and has a migration/test/API path. Its full E4 UX is still unproven.
- “Current route presence implies product availability” is false: `/evaluations` is intentionally a redirect, and many routed pages still need sidebar/role/runtime verification.
- “A passing or existing test proves feature completeness” is false: historical coverage reports record failures and omit broad React/responsive coverage.
- “V7 namespace enforcement is complete” is unsupported: repository evidence shows a mixed legacy/new naming landscape and only partial enforcement claims.

## 14. Summary Counts

Applicable requirements audited: **85** (75 `CURRENT`, 5 `POSSIBLY-LOST-DURING-CONSOLIDATION`, 5 `NEEDS-INVESTIGATION`, including the Phase 1 V7 namespace row whose status formatting was inconsistent).

| Primary verdict | Count |
|---|---:|
| IMPLEMENTED | 47 |
| PARTIALLY_IMPLEMENTED | 25 |
| NOT_IMPLEMENTED | 0 |
| IMPLEMENTED_BUT_NOT_WIRED | 0 |
| IMPLEMENTED_DIFFERENTLY | 1 |
| DEAD_CODE | 0 |
| TEST_ONLY | 1 |
| RUNTIME_VERIFICATION_REQUIRED | 11 |
| SPEC_CONFLICT | 0 |

The matrix verdict counts are intentionally conservative: `RUNTIME_VERIFICATION_REQUIRED` means repository evidence is insufficient to claim `IMPLEMENTED`, even where code and tests are substantial.

| Provisional gap severity | Count |
|---|---:|
| Critical | 0 |
| High | 9 |
| Medium | 7 |
| Low | 0 |

## 15. Highest-Priority Findings

**High**

1. `AUD-011`: broker reconciliation/halt recovery has no production-drill evidence.
2. `AUD-012`: the complete Admin/Investor route and object-ownership matrix is not proven.
3. `AUD-004`: V6 E4 non-regression evidence for redesigned core pages is missing.
4. `AUD-009`: production secrets, deployed commit, scheduler and build output remain runtime questions.
5. `AUD-007`: Trading Artifact Framework closure needs rollout and representative-data verification.
6. `AUD-005`: multi-strategy ownership/adoption/lending investor surfaces are not fully traceable end to end.
7. `AUD-014`: namespace enforcement is partial and lacks a complete drift inventory.

**Medium**

10. `AUD-016`: accepted Global Search is absent from the active header.

**Resolved since the original audit**

- `AUD-001`: Page Visit History/right-edge rail is implemented for the Investor shell with session persistence and focused tests; browser geometry/runtime reachability remains to be verified.
- `AUD-015`: fresh final regular-order safety revalidation is implemented and covered by focused post-admission race tests. A controlled broker/runtime safety drill remains required. GTT/protection policy is intentionally out of scope.

## 16. Recommended Remediation Sequence

1. Run broker reconciliation/halt recovery drills, validate AUD-015 against a controlled broker/runtime environment, and complete the ownership matrix.
2. Establish production runtime evidence for deployment, scheduler, queue, holidays, providers, callbacks and tokens.
3. Restore or explicitly supersede missing V6 shell capabilities, starting with Page History and Global Search; complete the non-regression inventory before redesign work.
4. Trace multi-strategy/adoption/lending and backtest/replay/paper workflows through the Investor UI with realistic data.
5. Complete artifact rollout, V7 ML/fundamental operating evidence, and namespace drift inventory.
6. Close test gaps after product decisions; do not use unit or source-reading tests as substitutes for browser/runtime acceptance.

No remediation was performed during the original audit. AUD-015 was subsequently remediated and is retained above with its original finding history and current static evidence.
