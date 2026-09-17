# AUD-005 — Multi-Strategy Ownership Remediation Design

## 1. Finding Recap

AUD-005 is `PARTIALLY_IMPLEMENTED` (High severity, Medium confidence). StoX has the principal multi-strategy accounting, ownership, adoption, capital-resolution, lending/recall, recommendation, and execution components. The unresolved question is whether an Investor can follow the complete lifecycle coherently through the current application, with enough state explanation to avoid confusing logical allocation, physical cash, ownership, and execution status.

This audit traced eleven lifecycle steps:

`strategy configuration → allocation → recommendation provenance → funding/reservation → review → ownership/adoption → same-stock handling → lending/recall → execution → accounting → Investor explanation`

No confirmed financial-ledger fabrication or cross-profile ownership bypass was found in the inspected paths. The main gaps are reachability, presentation clarity, and end-to-end evidence.

## 2. Accepted Ownership Model

The current contract establishes:

- One physical cash pool exists per portfolio. Strategy percentages are logical capital-allocation policy, not separate bank accounts.
- Multiple enabled strategies may operate concurrently. Each strategy has its own identity, version, configuration, allocation context, recommendation evidence, and ownership boundary.
- A holding is logically owned by a strategy or by the unmanaged owner. A strategy may not manage, reduce, exit, or trail another strategy's holding.
- Existing/manual holdings are unmanaged until explicit, auditable adoption.
- Multiple strategies may own the same security when they own distinct episodes. Basis, quantity, targets, exit authority, and recommendation provenance must not be blended.
- Adoption into a strategy may merge same-security ownership under the accepted weighted-average rules without inventing cost basis or erasing source history.
- Recommendation opinion, capital readiness, approval, pending execution, and execution are separate states.
- BUY reservations reduce available cash and are released or converted according to lifecycle state. Pending SELL proceeds are not automatically live broker cash.
- Lending is an inter-strategy capital claim. It does not create a second cash pool, a holding, or an executed trade. Recall and bridge activity remain auditable accounting workflows.

Primary current authority: [Portfolio, Cash, And Accounting](../current/portfolio-cash-accounting.md) §§5–15 and [Strategy And Recommendations](../current/strategy-and-recommendations.md) §§2, 10, 17, 21, 24–29.

## 3. State Model

| State or transition | Evidence | Assessment |
| --- | --- | --- |
| Unmanaged holding | `Holding::OWNER_UNMANAGED`, null `strategy_id`, `Holding::isUnmanaged()` | Implemented |
| Strategy-owned holding | `strategy_id`, `owner_key`, owner-scoped holding calculation/presentation | Implemented |
| Pending adoption | Modal-local selection in `HoldingsPage`; no durable pending-adoption state | UI workflow is immediate confirmation, not a persisted state |
| Adopted/merged holding | `HoldingAdoption`, `HoldingAdoptionService`, weighted-average merge | Implemented; UI reachability exists |
| Pending BUY reservation | Recommendation reservation fields, cash reservation queries, pending-execution views | Implemented; visible in Cash Management and recommendation detail |
| Capital request / committed loan | `CapitalRequest` statuses and `CapitalLoan` statuses | Implemented in backend/API; lender actions are reachable from recommendation detail |
| Recall pending/settled | `CapitalRecall` and recall services/presenter; Cash Management panel | Implemented; exact initiation policy is largely automated/API-driven |
| Pending sale proceeds | `PendingSaleProceeds`, availability/application services and Cash Management table | Implemented; UI explains that sale execution is not immediate spendable cash |
| Executed/closed | recommendation execution statuses, transactions, holdings recalculation, attribution services | Implemented in domain paths; complete routed journey remains under-tested |
| Disabled/archived strategy | registry status and enable/archive operations | Implemented for registry lifecycle; downstream ownership/recommendation presentation needs broader workflow evidence |

No separate `pending transfer` holding state was found. Ownership adoption is a transactionally applied operation with an audit row, not a long-lived user-visible transfer queue.

## 4. Data Model / Source of Truth

| Concept | Model/table | Important fields | Source of truth |
| --- | --- | --- | --- |
| Portfolio boundary | `PortfolioProfile` and `profile_id` foreign keys | active profile, user ownership | Active-profile middleware and profile-scoped queries |
| Strategy identity | `TradingStrategy` | `profile_id`, `status`, `allocation_pct`, `active_version_id` | Strategy model/registry and configuration services |
| Strategy policy provenance | `TradingStrategyVersion` | version identity and configuration | Active version consumed by recommendation generation/execution |
| Holding ownership | `Holding` / `portfolio_holdings` | `strategy_id`, `owner_key`, quantity, basis, target, filled amount | Holding ledger-derived position plus owner key |
| Adoption audit | `HoldingAdoption` / `portfolio_holding_adoptions` | source/destination owner, strategy, user, attribution recommendation, evidence | `HoldingAdoptionService` audit row |
| Recommendation | `TradingRecommendation` / `portfolio_tos_recommendations` | `strategy_version_id`, security, action, status, target/funding, reservation, execution fields | Recommendation lifecycle and generation services |
| Reservation | recommendation reservation fields and cash ledger queries | reserved amount/status/timestamps | `RecommendationLifecycleService`, `CashManagementService` |
| Capital snapshot | `PortfolioCapitalAccountingService` | physical/available cash, reserve, investable capital, strategy allocation, owned MV, reserved/lent/borrowed/retained | `GET /api/v1/capital` and Cash Management presentation |
| Capital request | `CapitalRequest` / `portfolio_tos_capital_requests` | borrower/lender strategy, recommendation, amount, status | Capital request/approval services |
| Loan | `CapitalLoan` / `portfolio_tos_loans` | principal, outstanding, lender/borrower, status | Lending services and ledger evidence |
| Recall/bridge/proceeds | `CapitalRecall`, `RecallBridgeLoan`, `PendingSaleProceeds` | state, amounts, lender/borrower, availability/application | Recall/proceeds services and Cash Management panel |
| Execution/accounting attribution | orders, execution decisions, transactions, internal transfers | recommendation IDs, owner keys, strategy/version evidence | Execution realization and transaction write paths |

## 5. Strategy Configuration

The current Investor surfaces are split between the Strategy Registry and Strategy page:

- `StrategyRegistryPage` lists registry/runtime strategies and provides Edit, Enable, Archive, import/export, and validation actions.
- Enablement is additive; the UI explicitly says other enabled strategies remain enabled.
- Archive is blocked for the last enabled strategy by the frontend and registry API lifecycle rule.
- `CashManagementPage` edits allocation percentages for every enabled strategy through `PUT /api/v1/capital/allocations`.
- The backend requires the submitted strategy set to equal all active strategies and requires the percentages to sum to 100. A zero-strategy allocation is rejected by the `min:1` request validation and an empty enabled set has no allocation editor.
- The UI disables saving when its draft sum is not 100 and presents the stored sum when the persisted set is inconsistent.
- Saving configuration does not itself generate recommendations; generation remains a separate pipeline action.

Changing allocations updates logical policy only. No inspected service automatically rebalances existing holdings. Existing ownership and deployed capital therefore need to be understood as current state while future allocation/funding uses the new percentages.

## 6. Capital Accounting

`PortfolioCapitalAccountingService::snapshot()` derives one physical cash balance, reservations, required reserve, investable cash/capital, unmanaged market value, strategy-owned market value, allocation, unused allocation, pending reservation, lent capital, borrowed capital, retained capital, and available-for-lending values. The Cash Management page presents these values and explicitly explains that strategy allocation is not separate cash.

| Metric | Backend source | UI surface | Missing/zero semantics | User action linked? |
| --- | --- | --- | --- | --- |
| Physical cash | `CashManagementService::balance` | Cash Management cash balance | `money()` shows `—` for missing/non-numeric | Cash movement form |
| Reserved cash | cash reservation query | Cash Management + reservation details | Missing falls back to display helper; reservation rows are inspectable | Pending execution link |
| Available physical cash | capital snapshot | Cash Management | Displayed separately from raw balance | Allocation/funding context |
| Required reserve / shortfall | reserve policy | Cash Management warning | Explicit warning; not treated as a second cash account | Replenish guidance |
| Investable capital | capital snapshot | Cash Management | Displayed as a derived amount | Recommendation/capital context |
| Unallocated cash | capital snapshot, presentation-only | Cash Management | Label explains it is not a ledger bucket | No direct lending action |
| Per-strategy allocation | active strategy `allocation_pct` | Cash Management editor | Draft sum must equal 100 | Save allocations |
| Owned market value | holding ownership grouping | Per-strategy capital table | Derived from quotes; no complete unavailable-price walkthrough proven | Indirect only |
| Unused allocation | snapshot strategy row | Per-strategy capital table | Numeric display helper | Indirect lending context |
| Lent/borrowed/retained capital | loan/accounting snapshot | Per-strategy capital table | Retained capital explicitly marked non-physical | Recall/lending panels |
| Pending sale proceeds | proceeds service | Capital Recall panel | Availability date/status shown; copy warns not immediately spendable | Proceeds application is service/automation-owned |

The main static concern is not an incorrect formula in the inspected service; it is that the full capital snapshot, recommendation resolution, and holding ownership are distributed across pages rather than presented as one traceable Investor journey.

## 7. Holdings Ownership UX

`HoldingsPage` loads `/holdings`, and `HoldingPresentationService` adds `strategy_id`, `owner_key`, `is_unmanaged`, target/filled/remaining amounts, price/return data, and protection state. The table visibly labels unmanaged holdings as `Unmanaged`. Strategy-owned holdings currently display the raw `owner_key` such as `strategy:12`, rather than consistently resolving the strategy name and ownership explanation.

Unmanaged rows expose an `Adopt` action. The adoption modal explains same-stock merge, weighted-average cost, preserved target, entry history, and risk-window continuity. Strategy-owned rows retain strategy-specific protection/sell behavior; adoption is not offered for them.

What is present:

- owner identity is returned from the backend;
- unmanaged and strategy-owned rows are distinguishable;
- adoption is reachable from the Holdings table;
- stock detail/price navigation and analysis helpers remain available;
- target/filled/remaining amounts are exposed.

What is not fully proven:

- a user-friendly strategy name/provenance explanation across all views;
- a single ownership history view linking current holding, adoption audit, recommendation, and source transactions;
- consistent explanation of why another strategy cannot act on a sibling-owned episode.

## 8. Unmanaged Adoption

The route is `POST /api/holdings/{holding}/adopt`. `HoldingController` scopes the holding to `activePortfolio()`, validates `strategy_id`, and delegates to `HoldingAdoptionService`.

The service:

1. verifies the holding and destination strategy belong to the active profile;
2. rejects non-unmanaged holdings, zero-quantity holdings, and strategies without an active version;
3. creates an attribution recommendation with `HOLD_POSITION` and executed status;
4. attributes previously unmanaged BUY transactions to that recommendation;
5. recalculates owner lots from the ledger;
6. assigns target/filled amounts and records a `HoldingAdoption` audit row;
7. records same-stock merge evidence and preserves the destination target when merging;
8. performs protection synchronization after the accounting transaction without undoing adoption if that sync fails.

This is a real UI-reachable workflow, not merely an API. Existing tests include `HoldingAdoptionTest`, ownership backfill tests, and related corporate-action ownership tests. The remaining evidence gap is a browser-level journey from the modal through refreshed ownership/provenance displays.

## 9. Same-Stock Policy

The current accepted rule is **both allowed and isolated**:

- multiple strategies may own the same security as distinct ownership episodes;
- their basis, quantity, target, exits, and recommendation records must not be blended;
- adopting an unmanaged holding into a strategy that already owns that stock uses the accepted merge/weighted-average behavior for the destination strategy, with an audit row and preserved source history;
- adoption does not merge into a sibling strategy or give the destination strategy authority over sibling-owned holdings;
- a recommendation generated for one strategy cannot supersede another strategy's live recommendation solely because the security is the same.

The backend implementation matches this policy through `owner_key`, strategy-scoped recommendation supersession, and adoption service logic. The UI explains the unmanaged-to-destination merge but does not yet provide a dedicated cross-strategy conflict/ownership timeline. This is a presentation/evidence gap, not a confirmed accounting contradiction.

## 10. Recommendation Provenance

| Lifecycle point | Provenance evidence | Status |
| --- | --- | --- |
| Strategy configuration | strategy and active version IDs | Implemented |
| Evaluation/generation | strategy-version-scoped evaluation and recommendation generation | Implemented/tested |
| Recommendation | `strategy_version_id`, security, evidence, action, target and capital fields | Implemented; visible in recommendation detail where payload fields exist |
| Capital resolution | recommendation ID, capital request/loan linkage, capital resolution state | Implemented; compact detail component is UI-reachable |
| Review/approval | recommendation review history and pending-execution transition | Implemented; recommendations page exposes review actions |
| Execution | recommendation IDs in execution decisions/orders/fills and strategy validity checks | Implemented in services; complete UI evidence remains distributed |
| Transaction | `recommendation_id`, owner attribution and realization path | Implemented/tested |
| Holding | strategy owner key/strategy ID and recalculated owner lots | Implemented; current UI labels need clearer naming |

No inspected path intentionally rewrites a strategy identity into portfolio-only ownership. The adoption path creates an explicit attribution recommendation rather than silently changing old transactions without evidence.

## 11. Funding / Reservation Lifecycle

The current contract distinguishes:

`recommendation exists → opinion/actionable state → capital allocation status → capital resolution → review approval → pending execution → execution/fill → transaction/holding`

The recommendations page displays strategy name, allocation information, capital-allocation badges, target/capital-ready/remaining amounts, execution progress, and a capital-resolution component. `RecommendationLenderActions` loads eligible lenders and states that committing lender capital does not approve the trade. `RecommendationCapitalResolution` displays own, recalled, bridge, borrowed, and remaining capital values.

Reservation behavior is owned by `RecommendationLifecycleService` and the cash ledger: approval reserves applicable own-funded BUY amount; cancellation, expiry, supersession, or reopening releases unconsumed reservation; execution converts only actual realized amount; partial execution is not silently presented as a fully funded target.

The main missing proof is a single UI test/journey that follows one recommendation through each state while observing the Cash Management reservation and strategy allocation views.

## 12. SELL / Cash Semantics

The current contracts and execution services explicitly keep pending/submitted SELL proceeds separate from live broker cash. `PendingSaleProceeds` records actual proceeds, availability, applied and remaining amounts; the Cash Management panel displays sold time, available-at time, applied/remaining amounts, and status. The panel copy states that sale execution does not mean proceeds are immediately usable cash.

This is implemented at the accounting/execution boundary. Runtime verification should confirm that the recommendation/capital UI does not present pending proceeds as immediately fundable BUY cash in a real partial/settlement scenario.

## 13. Lending / Recall

Lending is implemented in backend services and is partially surfaced in the Investor UI:

- `CapitalRequest` enforces profile scope and distinct borrower/lender strategy IDs.
- eligible lender discovery and approve/reject actions are reachable from recommendation detail through `RecommendationLenderActions`;
- approval creates one `CapitalLoan` and does not itself execute the recommendation;
- `CapitalRecallPanel` displays recalls, bridge loans, and pending sale proceeds with strategy names, amounts, status, and detail views;
- recall settlement, liquidation, bridge, proceeds availability, and repayment are handled by dedicated services/commands and tests;
- current UI copy accurately says recall/proceeds operations are automated and that proceeds are not immediately spendable.

The API also exposes recall creation and bridge operations, but the inspected Cash Management UI is primarily a status/presentation surface. That is acceptable if automation is the accepted product path; it is not sufficient evidence for a user-initiated recall workflow. The current docs describe automated/eligibility-driven recall, so this is classified as API/internal reachability rather than an automatic missing-feature finding.

## 14. Strategy Disable / Allocation Change

Registry enable/archive behavior is reachable and preserves sibling enabled strategies. Archive is prevented for the last enabled strategy. Current lifecycle code and recommendation cancellation reasons cover inactive strategy handling, including cancellation/release of unsubmitted intent where applicable.

An allocation change updates enabled strategy percentages and requires a complete 100% set. No automatic rebalance of existing positions was found, and no requirement says it should happen. Existing owned market value, pending reservations, lent capital, and borrowed capital remain visible in the snapshot; the UI does not provide a dedicated deficit/over-allocation resolution workflow.

This is a boundary to verify rather than a reason to invent automatic rebalancing. A future remediation should improve explanation if a changed allocation leaves existing deployed capital outside the new logical percentages.

## 15. UI Reachability Map

| User goal | Route | Component | API | Backend service | Status |
| --- | --- | --- | --- | --- | --- |
| See unmanaged/strategy ownership | `/holdings` | `HoldingsPage` | `GET /holdings` | `HoldingPresentationService` | `UI_REACHABLE` |
| Adopt unmanaged holding | `/holdings` modal | `HoldingsPage` | `POST /holdings/{holding}/adopt` | `HoldingAdoptionService` | `UI_REACHABLE` |
| Configure/enable/archive strategies | `/strategy-registry`, `/strategy` | `StrategyRegistryPage`, `StrategyPage` | strategy registry/config APIs | registry/configuration services | `UI_REACHABLE` |
| Configure allocation | `/cash` | `CashManagementPage` | `GET /capital`, `PUT /capital/allocations` plus `/cash` | `PortfolioCapitalAccountingService` | `UI_REACHABLE` |
| Inspect physical/reserved cash | `/cash` | `CashManagementPage` | `/cash`, `/cash/statement` | `CashManagementService` | `UI_REACHABLE` |
| Inspect recommendation provenance/funding | `/recommendations` | `RecommendationsPage`, `RecommendationCapitalResolution` | recommendation and capital-resolution APIs | recommendation/lending services | `UI_REACHABLE` |
| Approve/reject lender request | `/recommendations` detail | `RecommendationLenderActions` | capital lender APIs | `CapitalRequestService`, `CapitalRequestApprovalService` | `UI_REACHABLE` |
| Approve/reject/defer trade | `/recommendations` detail | `RecommendationsPage` | recommendation review APIs | `RecommendationLifecycleService` | `UI_REACHABLE` |
| Inspect pending execution/reservations | `/transactions/pending`, `/cash` | pending transactions/Cash Management | recommendation/cash APIs | cash/lifecycle services | `UI_REACHABLE` |
| Inspect recall/bridge/proceeds status | `/cash` | `CapitalRecallPanel` | recall/bridge/proceeds APIs | recall/proceeds services | `UI_REACHABLE` |
| Initiate/force recall settlement | no confirmed dedicated Investor UI | API/commands | recall/bridge APIs and scheduler | `RecallService`, settlement command | `API_ONLY` / automated |
| Resolve same-stock ownership explanation | Holdings/adoption modal only | HoldingsPage | holdings/adoption APIs | adoption/holding services | `PARTIAL_UI_REACHABLE` |
| Execute broker order | pending execution workflow | execution services and recommendation actions | execution APIs | execution engine/broker services | `UI_REACHABLE`, full journey unproven |

## 16. Error / Conflict States

| Condition | Source behavior | User-facing evidence | Assessment |
| --- | --- | --- | --- |
| Allocation set does not sum to 100 | validation exception / frontend draft guard | Draft sum and save error | Implemented |
| Unknown/non-profile strategy for adoption | validation error | Adoption modal error | Implemented |
| Strategy has no active version | validation error | Adoption failure message | Implemented backend; UI receives message |
| Holding already strategy-owned | adoption rejects except same-strategy idempotency | Adoption error | Implemented |
| Same-stock destination already exists | service merge/weighted-average path | Modal explains merge | Implemented; provenance timeline not prominent |
| Insufficient capital | recommendation retains opinion and capital status | Recommendation badges/detail | Implemented, end-to-end continuity not fully tested |
| Reserve shortfall | snapshot warning | Cash Management warning | Implemented |
| Pending reservation | reservation query/status | Cash reservation details | Implemented |
| Lender unavailable/rejected | capital request status/error | Lender action error/status | Implemented backend/UI component |
| Recall unavailable/pending | recall state and detail | Recall table/detail | Implemented |
| Strategy inactive/stale recommendation | lifecycle cancellation/release rules | recommendation status/reason where payload exposes it | Partially proven in UI |
| Missing price/market value | quote-dependent calculations | `—` in many presentation helpers; no complete multi-strategy degraded-data walkthrough | Runtime verification required |
| Cross-profile adoption/resource | profile-scoped query and service rejection | 404/validation response | Implemented/tested at API boundary; broader route matrix belongs to AUD-012 |

## 17. Authorization

The inspected operations consistently begin with `activePortfolio()` or profile-scoped queries. Adoption checks both holding and strategy profile. Capital request/lender operations constrain the request and lender strategy to the active profile. Recommendation and strategy APIs are active-profile scoped.

This audit does not duplicate AUD-012's route/object matrix. Remaining concerns are evidence gaps around every nested resource and token composition, not a confirmed multi-strategy ownership bypass. Investor runtime verification should use two profiles and foreign holding/recommendation/strategy IDs.

## 18. Concurrency / Stale State

Evidence exists for transaction boundaries and idempotency in adoption, reservation/lifecycle, lending, recall, and execution services. The main unproven composite races are:

- two adoption requests for the same unmanaged holding;
- allocation update concurrent with recommendation generation or pending execution;
- recall settlement concurrent with execution/funding resolution;
- strategy disable/archive while a recommendation is pending;
- same-security recommendations from two strategies during ownership/funding changes.

These should be tested as state-transition scenarios before claiming the complete Investor workflow is closed. This design does not propose a locking change.

## 19. Test Coverage

| Requirement | Existing tests | What they prove | Missing coverage |
| --- | --- | --- | --- |
| Multi-strategy generation/isolation | `V3RecommendationGenerationTest` | Independent strategy generation and holding isolation | Complete routed UI journey |
| Holding adoption/merge | `HoldingAdoptionTest`, `HoldingOwnershipBackfillTest` | Adoption validation, attribution, ownership/backfill behavior | Browser refresh/provenance explanation |
| Capital accounting | `V3CapitalAccountingTest`, `V3CapitalLendingAccountingTest` | Single pool, reserve, allocation, lending calculations | UI continuity across pages |
| Capital request/lending | `CapitalRequestServiceTest`, `CapitalRequestApprovalServiceTest`, `RecommendationLendingLifecycleTest` | Request status, lender selection, idempotent commitment and funding states | UI error/recovery matrix |
| Recall/bridge/proceeds | Recall Phase 1/2/3A suites and `RecallGapClosureTest` | Eligibility, settlement, bridge, notification and API behavior | Real browser discoverability and partial settlement walkthrough |
| Recommendation funding/provenance | allocator/lifecycle/execution tests | Partial/unfunded preservation, reservation and execution attribution | One recommendation traced to holding in UI |
| Strategy lifecycle | `StrategyRegistryApiTest` | Draft/active/archive/last-enabled rules | Existing owned holdings and pending work presentation |
| Authorization | ownership/adoption/profile tests and AUD-012 suites | Profile boundary and foreign-resource rejection in tested paths | Full multi-strategy nested-resource matrix |
| Frontend | `capitalRecallUi.test.mjs`, recommendation/Holdings UI coverage | Labels and selected component/source behavior | Dedicated multi-strategy journey tests |

## 20. Runtime Verification

Use two enabled strategies in one Investor profile and representative unmanaged/owned positions. Verify at minimum:

1. Configure two strategies and save a 60/40 allocation; confirm the same physical cash pool and per-strategy logical values.
2. Generate a recommendation for each strategy and verify strategy/version provenance in list/detail, capital resolution, pending execution, transaction, and holding views.
3. Adopt an unmanaged holding into Strategy A; verify weighted merge, target preservation, audit/history, and that Strategy B cannot act on it.
4. Repeat with the same symbol already owned by Strategy B; verify distinct ownership episodes and the accepted destination merge behavior.
5. Approve an underfunded recommendation, inspect reservation and lender request, approve a lender, and verify that capital commitment remains distinct from trade approval/execution.
6. Verify cancellation, expiry, supersession, and partial execution release/consume reservations correctly.
7. Create/observe lending and recall states, including pending held, bridge, and pending sale proceeds; confirm proceeds are not shown as live broker cash.
8. Disable/archive a strategy with existing holdings and pending recommendations; verify the documented lifecycle outcome and preserved history.
9. Change allocation after positions/reservations exist; verify no silent rebalance and that any logical deficit/over-allocation is explained.
10. Repeat ownership and mutation attempts with a second profile/token; confirm AUD-012 boundary behavior.

## 21. Gap Register

### MS-001 — Complete multi-strategy journey is not proven as one reachable workflow

- **Requirement:** An Investor can follow strategy configuration, allocation, recommendation provenance, capital resolution, ownership, execution, and accounting without losing state meaning.
- **Evidence:** The route/component/API map shows each major segment, but no current test or single UI walkthrough links one recommendation through all segments.
- **Classification:** `RUNTIME_VERIFICATION_REQUIRED`
- **Severity:** High
- **Confidence:** High
- **User impact:** Users may be unable to reconcile a capital/funding state with the resulting owner, transaction, and strategy provenance even when each subsystem works.
- **Smallest remediation direction:** Add a representative end-to-end browser/feature journey and a compact cross-link/state contract between Recommendations, Cash, Pending Execution, Holdings, and Transactions.

### MS-002 — Strategy ownership is exposed as a raw owner key in Holdings

- **Requirement:** Investor can identify the owning strategy and understand unmanaged/strategy-owned boundaries.
- **Evidence:** `HoldingsPage` renders `Unmanaged` or raw `owner_key` such as `strategy:<id>`; the API returns `strategy_id` but the table does not consistently render a resolved strategy name or provenance link.
- **Classification:** `PARTIALLY_IMPLEMENTED`
- **Severity:** Medium
- **Confidence:** High
- **User impact:** Ownership is technically present but difficult to interpret, especially when the same security has multiple strategy episodes.
- **Smallest remediation direction:** Return/use the owning strategy display name and link to the strategy/recommendation context without changing ownership semantics.

### MS-003 — Same-stock conflict/provenance explanation is not a dedicated user-facing surface

- **Requirement:** Same-security ownership must remain isolated and adoption/merge consequences must be understandable.
- **Evidence:** Backend policy and adoption modal copy are correct; there is no ownership-episode/history view showing sibling strategy ownership, basis/provenance, and why a strategy cannot act on another episode.
- **Classification:** `PARTIALLY_IMPLEMENTED`
- **Severity:** Medium
- **Confidence:** Medium
- **User impact:** A user can complete adoption but may not understand the resulting ownership split or conflict boundary.
- **Smallest remediation direction:** Add contextual ownership/provenance details to Holdings/adoption and recommendation detail; do not blend holdings or invent a new ownership state.

### MS-004 — Allocation changes with deployed capital lack explicit deficit/excess explanation

- **Requirement:** Allocation changes must not silently imply that existing positions were rebalanced or that logical allocation equals physical cash.
- **Evidence:** Allocation save is validated and snapshot shows deployed/unused/lent/borrowed values, but no explicit UI state explains when existing deployed capital no longer fits a newly saved percentage.
- **Classification:** `RUNTIME_VERIFICATION_REQUIRED`
- **Severity:** Medium
- **Confidence:** Medium
- **User impact:** A user may interpret a saved 70/30 policy as an automatic rebalance of existing 50/50 positions.
- **Smallest remediation direction:** First verify the existing snapshot behavior with a changed-allocation scenario; if unclear, add explanatory copy/status only. Do not auto-rebalance without an accepted decision.

### MS-005 — Full cross-state error/recovery and unavailable-data presentation is not proven

- **Requirement:** Financially meaningful missing, stale, blocked, and failed states must remain distinct from zero/empty and expose recovery paths.
- **Evidence:** Core services preserve explicit statuses and many UI helpers use `—`, but the complete multi-strategy surfaces have no unified test for missing quote, lender failure, stale recommendation, recall pending, and partial execution.
- **Classification:** `RUNTIME_VERIFICATION_REQUIRED`
- **Severity:** Medium
- **Confidence:** Medium
- **User impact:** Composite pages may be technically correct while still presenting an ambiguous capital/ownership state.
- **Smallest remediation direction:** Add scenario-based UI tests and browser checks for the highest-risk states; keep domain calculations unchanged.

## 22. Remediation Groups

### A — Ownership visibility

MS-002 and the ownership part of MS-003: resolve strategy names, link provenance, and explain unmanaged/owned episode boundaries.

### B — Adoption/conflict workflow

MS-003: preserve current weighted merge and add a concise ownership-history/conflict explanation. Do not change the accepted same-stock policy.

### C — Capital/reservation visibility

MS-001, MS-004, and the reservation portion of MS-005: connect recommendation funding, Cash Management reservations, pending execution, and actual holding/transaction outcomes.

### D — Lending/recall

No confirmed accounting gap. Retain API/internal and automated recall behavior as a reachability/runtime verification item under MS-001/MS-005.

### E — Recommendation provenance/funding state

MS-001 and MS-005: add representative journey evidence and ensure strategy/version, funding, review, execution, and accounting labels remain distinct.

### F — Runtime verification only

Browser geometry, route discoverability, cross-page continuity, two-profile authorization, and degraded-data behavior remain runtime checks rather than automatic code changes.

## 23. Recommended Order

1. Verify MS-001 with a two-strategy, two-profile representative journey before changing product behavior.
2. Improve Holdings ownership naming and provenance links (MS-002).
3. Add same-stock adoption/episode explanation without changing merge/accounting semantics (MS-003).
4. Exercise allocation changes with existing positions and decide whether explanatory status is sufficient (MS-004).
5. Add focused state/error/recovery coverage for funding, reservations, lending, recall, stale recommendations, and missing prices (MS-005).
6. Retain AUD-012 authorization and AUD-015 execution-safety boundaries; do not duplicate their remediation.

## 24. Final AUD-005 Assessment

**`PARTIALLY_IMPLEMENTED` — High severity, Medium confidence.**

The ownership/accounting model, adoption/merge implementation, recommendation provenance fields, capital reservation lifecycle, lending/recall services, execution attribution, and representative UI surfaces are present. Adoption, allocation, recommendation capital resolution, and recall/proceeds status are UI-reachable; low-level recall/settlement initiation is API/automation-owned. The remaining confirmed design gaps are primarily ownership presentation and same-stock provenance explanation, while the highest-risk financial lifecycle continuity is not yet proven as one executable Investor journey.

Closure requires evidence that no financial correctness gap remains, strategy ownership is understandable in Holdings, same-stock outcomes are explained, reservations/funding remain distinct from approval/execution, and the representative two-strategy workflow survives through accounting. Runtime browser verification may remain after static remediation, but the current evidence does not support `IMPLEMENTED`.

## 25. Open Questions

1. Should Holdings display a resolved strategy name and a direct ownership/provenance link, or is `strategy:<id>` intentionally an internal/debug label?
2. When allocation changes below already-deployed capital, is an explanatory deficit state sufficient, or should a future product decision define rebalancing/transfer behavior?
3. Is recall initiation intentionally automation-only for Investors, with the API reserved for orchestration/admin paths, or should an Investor request/recall action be surfaced?
4. What is the accepted browser-level canonical path for reviewing one recommendation from funding request through transaction and final holding ownership?

## 26. Batch 1 Implementation Outcome — MS-001

**Status:** `IMPLEMENTED` for the executable lifecycle assurance scope; AUD-005 remains `PARTIALLY_IMPLEMENTED` overall.

Added `app/tests/Feature/MultiStrategyLifecycleAssuranceTest.php`, a deterministic Laravel feature suite using the existing `RecommendationLifecycleService`, `ExecutionEngine`, `PortfolioCapitalAccountingService`, `HoldingAdoption` route/service, and active-portfolio middleware.

The primary scenario uses one Investor, one portfolio, two enabled strategies at 60/40, one physical cash pool, a fixed-price stock, and the following real transitions:

1. initial capital snapshot with one physical cash account and zero reservations;
2. Strategy A recommendation in `pending_review` with active strategy-version provenance;
3. approval through `recordReview()` into `pending_execution`, creating a Strategy A reservation;
4. explicit assertion that approval creates no transaction or holding;
5. deterministic paper/manual execution through `ExecutionEngine::recordOrder()`;
6. transaction and holding attribution to Strategy A;
7. reservation conversion and final capital snapshot reconciliation.

Secondary scenarios prove:

- two recommendations for the same stock produce separate Strategy A and Strategy B ownership episodes and retain separate strategy-version provenance;
- unmanaged same-stock adoption through `/api/holdings/{holding}/adopt` merges only into the selected strategy, preserves weighted cost/history evidence, and leaves the sibling strategy untouched;
- cancelling an approved BUY releases the reservation without creating a transaction or holding;
- a second Investor cannot adopt a foreign holding or review a foreign recommendation, while the foreign resources remain unchanged.

The focused suite passes: **5 tests, 52 assertions**. No production code was required and no pending-SELL behavior was changed; `V4Spec004CashLedgerSpecialMovementsTest` and the existing capital/lending suites remain the authoritative coverage for delayed sale-proceeds availability and recall accounting. Browser discoverability and composite multi-page continuity remain runtime work. MS-002 through MS-005 remain open as previously described.
