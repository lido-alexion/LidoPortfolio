# Portfolio, Cash, And Accounting

## 1. Purpose And Scope

This document is the authoritative current contract for portfolio/profile ownership, financial transactions, holdings derivation, cash ledger events, capital movement, ownership attribution, corporate-action accounting, historical reconstruction, snapshots, and the accounting inputs to performance and tax.

It distinguishes:

- **Required product contract** — accepted behavior StoX must preserve.
- **Current implementation anchor** — the model, service, controller, command or test that currently implements or is intended to implement that behavior.

This domain does not own recommendation scoring, investment selection policy, broker submission, broker-funds availability, or live execution safety. It supplies the financial source data and accounting consequences consumed by [Strategy And Recommendations](./strategy-and-recommendations.md), [Execution, Broker, And Safety](./execution-broker-safety.md), and [Analytics, Review, And Backtesting](./analytics-review-backtesting.md).

## 2. Portfolio/Profile Boundary

`PortfolioProfile` is the principal business boundary. Most portfolio data is scoped to the active profile: transactions, holdings, watchlists, cash accounts and ledger, corporate actions, recommendations, orders, protections, capital/lending records, snapshots and historical views. A user may create, update, delete, set default and clone profiles, including cloning as paper.

- Active-profile middleware resolves portfolio-scoped routes and must reject access outside the authenticated user's profile scope.
- A default profile is a user preference/entry point, not shared global ownership.
- Paper portfolios and paper events remain distinguishable from live portfolio/broker evidence.
- User-scoped broker connections, execution entitlement and sessions do not turn a foreign portfolio into an accessible resource.
- Admin access may operate administration functions but must not become Investor ownership of portfolio resources.

Current anchors: `PortfolioProfile`, `PortfolioProfileService`, `PortfolioCloneService`, `ResolveActivePortfolio`, `PortfolioController`, and the profile/authorization feature tests.

## 3. Financial Source-Of-Truth Model

Financial transactions are the durable accounting input. Holdings, portfolio history and snapshots are derived/rebuildable views of transaction, ownership, corporate-action, price and cash evidence.

- A recommendation, broker request, order or notification is not a substitute for a financial ledger transaction.
- Broker/order evidence may update accounting only through traceable transaction/fill realization paths.
- Derived holdings and snapshots may be recalculated after corrected transaction, corporate-action or price evidence.
- Rebuildability does not authorize retrospective mutation without durable source evidence and audit context.
- Performance, tax and historical/as-of views consume date-aware financial inputs; they must not infer a source transaction from an aggregate card or a recommendation status.

Current anchors: `Transaction`, `TransactionWriteService`, `TransactionRealizationService`, `HoldingsCalculationService`, `PortfolioCalculationService`, `PortfolioSnapshotRebuildService`, `PortfolioHistoricalHoldingsService`, and `TransactionWriteService`/financial-integrity tests.

## 4. Transaction Lifecycle

Transactions represent durable financial events such as buys, sells, fees, deposits, withdrawals, adjustments and supported corporate-action effects. A transaction write uses the shared financial write path, validates scope and input, and triggers the appropriate recalculation/realization effects.

Lifecycle rules:

1. Create/import a transaction through the shared write path.
2. Apply holdings, cash, fee, realization and ownership consequences in the relevant transaction boundary.
3. Link recommendation/order evidence where the transaction realizes execution, without treating that linkage as a replacement for the transaction.
4. Rebuild or refresh derived holdings/history/snapshot state when the source event changes.
5. Update/delete/correction behavior must preserve accounting traceability, reversal/reference evidence and consistent derived state.

Bulk CSV import uses batch/item tracking and the same financial write discipline; it must not introduce a parallel ledger. Sell realization and attribution persist closed-position consequences. A correction or reversal must remain attributable to the original economic event rather than silently altering history.

Current anchors: `TransactionController`, `BulkTransactionImportController`, `BulkTransactionImportService`, `TransactionWriteService`, `TransactionRealizationService`, `SellAttributionService`, `TransactionImportBatch`, `TransactionImportBatchItem`, and transaction/import tests.

## 5. Holdings And Ownership Model

Holding identity is logical ownership by portfolio, security and owner: a strategy or the unmanaged owner. The same security may have holdings attributable to multiple strategies or unmanaged ownership within one portfolio; those ownership episodes must not be blended in a way that lets one strategy manage another's position.

- A strategy may not exit, reduce, trail or otherwise dispose of another strategy's holding.
- Cost basis, quantity, targets, trailing state, exit reasoning, realized outcome and performance attribution follow the owning position.
- Existing/manual positions are unmanaged until safely adopted.
- The physical implementation may aggregate presentation/storage where safe, but the logical owner boundary remains authoritative.
- Ownership changes preserve explainability and audit evidence; they are not a cosmetic reassignment.

Current anchors: `Holding`, `HoldingAdoption`, `HoldingsCalculationService`, `HoldingPresentationService`, `HoldingOwnershipBackfill`, `HoldingAdoptionService`, `SellAttributionService`, and ownership/adoption tests.

## 6. Holding Adoption

Manual, inherited or legacy holdings are unmanaged unless a safe adoption links them to one strategy. Adoption is explicit and auditable.

- An unmanaged holding is not subject to strategy-specific exit/reduce/increase decisions until adoption.
- Adoption assigns future ownership-aware lifecycle and attribution to the selected strategy; it does not give that strategy authority over sibling strategy holdings.
- Same-security adoption uses the accepted merge/weighted-average rules where ownership must be combined without inventing cost basis or erasing source history.
- Corporate-action quantities continue to follow the parent owner; adoption must not create duplicate economic quantity.
- Invalid cross-profile or cross-user adoption is rejected.

Current anchors: `HoldingController::adopt`, `HoldingAdoptionService`, `HoldingAdoption`, `HoldingOwnershipBackfill`, `V4Spec005SellAttributionTest`, `HoldingAdoptionTest`, `HoldingOwnershipBackfillTest`, and corporate-action ownership tests.

## 7. Cash Ledger Model

StoX has one physical cash pool per portfolio. Cash is represented through `CashAccount` and explicit `CashLedgerEntry` economic events, not loose annotations or strategy bank accounts.

The ledger records deposits, withdrawals, adjustments, reversals, recommendation reservations, trade effects, fees, pending sale proceeds, special movements and lending/recall/bridge evidence. Dedicated loan/recall/bridge ledger types provide an auditable trail; paired commitment/repayment entries can net to zero against physical portfolio cash when they represent an inter-strategy accounting claim rather than a physical deposit or withdrawal.

Each entry has an effective entry date and may link to a reversal reference. Reversal preserves the original event trail; it is not a destructive edit of accounting history.

Current anchors: `CashAccount`, `CashLedgerEntry`, `CashManagementService`, `CashController`, `SpecialCashMovementService`, `V5CashStatementTest`, and cash/financial-integrity tests.

## 8. Cash Availability And Reservations

The following values are distinct:

- **Physical cash** — the portfolio's real ledger balance.
- **Reserved cash** — physical cash committed to approved/pending BUY intent but not yet converted to a realized transaction.
- **Available/investable cash** — cash available under the ledger, reservation and capital rules; raw balance fields alone are not affordability truth.
- **Portfolio reserve** — a policy calculation/warning, not a second cash account.
- **Capital-ready amount** — the actual amount funding allows for an execution-capable recommendation.
- **Pending sale proceeds** — expected/recorded proceeds not necessarily available to spend, especially not as live broker funds.

Soft-reservation lifecycle:

1. BUY approval reserves the applicable own-funded amount.
2. Cancellation, expiry, supersession or reopening releases unconsumed reservation.
3. Execution converts/consumes reservation only to the actual realized amount.
4. Partial execution consumes only the realized portion and leaves/reconciles the unresolved amount according to the execution lifecycle.
5. A reservation is not another cash account and a loan is not a second reservation.

Raw cash balance must not be used in place of ledger/reservation/capital calculation. [Execution, Broker, And Safety](./execution-broker-safety.md) adds the separate rule that pending SELL proceeds are not assumed to be available live broker cash.

Current anchors: `CashManagementService`, `PortfolioCapitalAccountingService`, `RecommendationLifecycleService`, `ExecutionEngine`, `TradingRecommendation` reservation fields, and capital/execution integrity tests.

## 9. Portfolio Reserve And Capital Rules

The portfolio has a single physical cash pool. Required cash reserve is a policy calculation over investable capital, not a segregated bank account or a second source of cash.

- Reserve shortfall is surfaced as a warning/portfolio policy condition; it does not itself create money or silently rewrite investment opinions.
- Withdrawals are not hard-blocked solely because a reserve target would be missed; normal cash/authorization constraints still apply.
- `unallocated_cash` is a presentation allocation concept, not an additional cash pool or automatically lendable surplus.
- Available physical cash is calculated after pending-execution reservations.
- The reserve is not subtracted again when calculating available-for-lending; doing so would double count retained capital.

Current anchors: `PortfolioCapitalAccountingService`, `ProfileSettingsService`, Dashboard/Cash presentation, and `V3CapitalAccountingTest` / `V3CapitalLendingAccountingTest`.

## 10. Capital Allocation And Partial Funding

Strategy allocation, target amount, own-funded amount, lender-backed amount and capital-ready amount are distinct. A recommendation may be valid while unfunded or partially funded.

- Lack of cash must not silently demote an actionable OPEN/INCREASE opinion into WATCH/HOLD.
- UNFUNDED and PARTIALLY_FUNDED recommendations retain their investment opinion while exposing capital state.
- Capital requests/lending can move an eligible recommendation toward `capital_committed`; loan commitment alone does not execute the trade or create holdings.
- Execution uses only the amount actually funded and closes accounting at actual realized amounts, not the desired target amount.
- Sells/exits are not blocked by the BUY capital-resolution gate.
- `close_at_actual` and `hold_for_remainder=false` prevent an unresolved lending gap from masquerading as unexecuted target capital after permitted partial completion.

Current anchors: `PortfolioCapitalAccountingService`, `CapitalAllocationStrategy`, `CapitalRequestService`, `CapitalResolutionService`, `RecommendationLendingCoordinator`, `CommittedLendingExecutionAmounts`, `CapitalFillOrderService`, and capital/lending lifecycle tests.

## 11. Lending

Lending is an inter-strategy capital claim inside one portfolio, not movement into a strategy cash account.

- A lender must be distinct from the borrower and in the same profile.
- Eligibility is based on current `available_for_lending`, not raw physical cash or presentation-only unallocated cash.
- Ranking is deterministic: available-for-lending percentage descending, then amount descending, then strategy ID for exact ties.
- A committed request creates one `CapitalLoan` with principal and outstanding amount; it affects lent/borrowed accounting but does not automatically create holdings, execute a recommendation or alter allocation percentage.
- Available-for-lending is floored to whole ₹5,000 amounts after retained/committed calculation and optional portfolio caps.
- Optional settings `max_lending_pct_of_unused` and `max_lending_absolute` cap lendable surplus. Blank means the corresponding cap is not applied.

Stable formula:

`raw = max(0, unused − retained − committed)`

Then apply percentage cap, absolute cap and `FloorToRupee5000::floor(raw)`. Outstanding loans are already represented through deployed/unused capital and must not be subtracted twice.

Current anchors: `CapitalLoan`, `CapitalRequest`, `LenderEligibilityService`, `LenderRankingService`, `CapitalRequestApprovalService`, `PortfolioCapitalAccountingService`, `FloorToRupee5000`, and lending eligibility/ranking/accounting tests.

## 12. Recall

Recall returns lent capital from borrower strategy to lender strategy while retaining financial evidence.

- Recall eligibility is based on loan commitment and the current portfolio recall period; changing the period does not restart the commitment clock.
- The system maintains one active recall per eligible loan and applies a follow-up cooldown after completion.
- Immediate settlement can settle available amount while a remainder becomes `pending_held`; insufficient immediate settlement does not invent a tiny partial repayment.
- Recall processing may use liquidation/proceeds application where required and preserves lender/borrower effects, request/loan linkage, notification and audit context.
- FIFO/priority selection and recall amount are calculated from the durable loan/recall state rather than ad hoc cash transfer.

Current anchors: `CapitalRecall`, `RecallService`, `RecallEligibilityService`, `RecallAmountCalculator`, `RecallFulfilmentService`, `RecallImmediateSettlementService`, `RecallLiquidationService`, `RecallNotificationService`, `ProcessRecallSettlementsCommand`, and recall test suites.

## 13. Recall Bridge Loans

A Recall Bridge Loan is distinct from a normal investment loan. It exists when recall requires timely lender settlement but proceeds or borrower liquidity cannot settle the full recall immediately.

- Creation follows explicit recall-bridge eligibility and lender-selection rules; it is not a generic cash advance.
- The bridge records outstanding amount and its connection to the recall/borrower/lender context.
- Repayment is sourced through the approved recall/proceeds path and remains auditable.
- Bridge outstanding participates in lent/borrowed accounting but does not create a second physical cash pool.
- A bridge is created, remains outstanding and is eventually repaid/closed; it must not be silently netted away.

Current anchors: `RecallBridgeLoan`, `RecallBridgeLoanService`, `RecallBridgeEligibilityCalculator`, `RecallBridgeLenderSelector`, `GoodFaithBridgeRepaymentService`, `RecallBridgeLoan` routes and recall/bridge tests.

## 14. Pending Sale Proceeds

Pending sale proceeds represent proceeds expected from a sale that are not yet economically available for the next capital-resolution step.

- Proceeds can be created/updated by the sale/recall lifecycle and receive an availability date through `SaleProceedsAvailabilityService`.
- They may participate in capital resolution only when their domain availability rules permit it.
- They are distinct from physical cash and from live broker funds.
- Pending or submitted SELL proceeds are never automatically assumed spendable by a live broker BUY; the broker-funds rule remains authoritative in the execution domain.
- Applying proceeds updates the relevant capital/recall evidence rather than creating an untraceable cash adjustment.

Current anchors: `PendingSaleProceeds`, `SaleProceedsAvailabilityService`, `ProceedsApplicationService`, `PendingSaleProceedsController`, and lending/execution tests.

## 15. Cash And Capital State Machines

### Reservation

| State | Transition rule |
|---|---|
| Available | Cash is not reserved for a pending BUY |
| Reserved | Approval/pending execution reserves the applicable own-funded amount |
| Partially consumed | Actual partial execution consumes only realized amount; remaining intent follows its execution lifecycle |
| Consumed | Actual financial execution converts reservation into transaction/cash effect |
| Released | Cancel, expire, supersede, reopen or invalidation releases unconsumed amount |

### Capital request and loan

| State | Transition rule |
|---|---|
| Request displayed/awaiting approval | A borrower has a capital need; no physical cash transfer or loan claim yet |
| Rejected/revalidation failed/cancelled | Request cannot fund execution; no loan is created |
| Committed | Approval creates one lender/borrower `CapitalLoan`; recommendation still needs approval/execution |
| Loan outstanding | Principal is an accounting claim; it can be partially returned or returned in full |
| Partially returned/returned | Repayment reduces `outstanding`, never rewrites original principal |

### Recall and bridge

| State | Transition rule |
|---|---|
| Recall requested/active | Eligible outstanding loan is recalled |
| Immediate settlement or pending held | Available settlement is applied under recall rules; unresolved portion remains durable |
| Bridge created/outstanding | Explicit bridge covers eligible recall shortfall |
| Repaid/closed | Repayment evidence closes the relevant loan/bridge claim |

State labels above describe the current contract; controller/model constants remain the implementation authority for exact API values.

## 16. Corporate Actions

Corporate actions are financial/holding events with audit evidence. Supported workflows include split, bonus and rights-related treatment.

- Split and bonus adjustments update the affected ledger/holding position with ownership following the parent owner.
- Strategy-position restatement preserves quantity/cost/attribution explainability and must not double apply an action.
- Rights are not equivalent to automatic split/bonus restatement; rights handling is a distinct workflow.
- Corporate action ledger/holding application is separate from historical OHLCV price repair.
- Data-quality detection and factor-driven price repair belong in [Market Data And Data Quality](./market-data-and-data-quality.md); they must not alter financial ledger state merely because a market-data factor is repaired.
- All preview/apply/restatement paths must retain action, effective-date and source evidence.

Current anchors: `CorporateAction`, `CorporateActionService`, `CorporateActionPriceAdjustmentService`, `CorporateActionPriceRepairService`, `CorporateActionController`, and corporate-action/ownership/price-repair tests.

## 17. Historical Holdings

Historical holdings are an as-of reconstruction, not simply a snapshot lookup. They use date-aware transactions, ownership, corporate actions and prices to answer what the portfolio held at a selected time.

- Historical holdings differ from live holdings and from a persisted snapshot.
- Ownership attribution remains part of reconstruction; a date view must not blend strategy/unmanaged holdings merely because they share a security.
- Prices and missing-price behavior are date-aware and must preserve unknown/unavailable distinctions.
- Reconstructed output is derived from durable source evidence and can be recalculated after source correction.

Current anchors: `HistoricalHoldingsController`, `PortfolioHistoricalHoldingsService`, `HistoricalHoldingsService`, `HistoricalHoldingsTest`, `PortfolioHistoricalHoldingsServiceTest`, and historical UI tests.

## 18. Portfolio Snapshots

A portfolio snapshot is a persisted, derived point-in-time portfolio view used by UI and analytics. It is not immutable financial source-of-truth data.

- Snapshots are rebuildable after relevant transaction, price or corporate-action history changes.
- Snapshot rebuild must preserve source-ledger truth and report/soft-fail appropriately when inputs are incomplete rather than manufacture values.
- Snapshots differ from historical holdings: a snapshot is a stored derived result; historical holdings are as-of reconstruction from source evidence.
- Rebuild operations are explicit and should be auditable because they may change derived presentation/analytics without changing the underlying financial event trail.

Current anchors: `PortfolioSnapshot`, `PortfolioSnapshotRebuildService`, `PortfolioHistoryController`, `PortfolioSnapshotApiTest`, `PortfolioSnapshotRebuildTest`, and portfolio-history rebuild routes.

## 19. Portfolio Compare And Cash-As-Of

Portfolio comparison supports date A/date B views using historical holdings plus effective-dated cash. Cash-as-of uses ledger entry dates rather than current balance projection.

- Compare/export datasets should preserve the requested dates, source assumptions and missing-price context.
- Historical cash and holdings are complementary; neither may be substituted with today's values.
- Export is derived reporting data and does not change ledger/source state.

Current anchors: `PortfolioDateComparisonService`, `HistoricalHoldingsController::compare`, `CashController::asOf`, `PortfolioCsvExportService`, `V5CashStatementTest`, and historical-holdings/compare UI tests.

## 20. Performance, Attribution And Tax

Performance and tax use ledger/holding/realization evidence but do not redefine accounting truth.

- XIRR and account-performance measures use dated cash-flow/portfolio inputs.
- Realized and unrealized P&L are distinct and use transactions, holdings, prices, fees and realization evidence.
- Benchmark comparison uses selected/date-aware benchmark evidence; absent evidence remains unknown rather than zero.
- Dividends, opening lots, FIFO lot calculation, tax losses and tax rule versions support tax reporting.
- Attribution uses attributable holdings, transactions and benchmark/cash residual evidence; it must report residual/unknown conditions rather than invent allocation.
- Exportable tax/performance datasets are reports over source evidence, not write paths.

Current anchors: `PortfolioPerformanceService`, `AccountPerformanceService`, `PortfolioAttributionService`, `TransactionRealizationService`, `FifoTaxLotCalculator`, `XirrService`, `TaxLoss`, `OpeningTaxLot`, `Dividend`, tax/performance controllers, and V5 performance/tax tests.

## 21. Accounting Invariants

- The transaction ledger is the durable financial source of truth.
- Holdings, history and snapshots are derived/rebuildable state.
- Each portfolio has one physical cash pool; strategies do not have separate bank accounts.
- Reservation is a commitment against physical cash, not another cash account.
- Affordability uses ledger, reservation and capital semantics rather than raw balance fields.
- Strategy/unmanaged ownership must not be blended; a strategy cannot dispose of another owner's holding.
- Execution closes accounting at actual realized amounts, not desired target amounts.
- Loan, recall and bridge records are explicit accounting claims and must not fabricate physical cash.
- Reversals/corrections remain traceable to source evidence.
- Recommendation, broker and notification evidence cannot fabricate ledger state.
- Historical/as-of output is date-aware and must not use current values as a substitute.
- Corporate-action adjustments and price repair remain auditable and separate financial versus market-data concerns.

## 22. Data Model And Relationships

| Model | Current role and relationship |
|---|---|
| `PortfolioProfile` | User-owned portfolio boundary, active/default context, execution mode and paper/live identity |
| `Transaction` | Durable financial input linked to profile/security/recommendation/corporate-action evidence where applicable |
| `Holding` | Derived/current position presentation with logical strategy or unmanaged ownership |
| `HoldingAdoption` | Auditable reassignment/adoption evidence from unmanaged to a strategy owner |
| `CashAccount` / `CashLedgerEntry` | Physical portfolio cash pool and dated, reversible economic-event trail |
| Reservation fields/records | Pending-execution commitment against own-funded cash; consumed/released by lifecycle evidence |
| `CapitalRequest` / `CapitalLoan` / `CapitalLoanReturn` | Borrower need, one-lender committed claim and repayment history; not strategy cash accounts |
| `CapitalRecall` / `RecallBridgeLoan` | Durable recall and bridge-shortfall lifecycle/evidence |
| `PendingSaleProceeds` | Proceeds awaiting domain availability, distinct from live broker cash |
| `CorporateAction` | Financial action preview/application/restatement evidence; price repair is related but separate |
| `PortfolioSnapshot` | Persisted derived portfolio view, rebuildable from source evidence |
| `TaxLoss`, `OpeningTaxLot`, `Dividend`, tax-rule and analysis models | Tax/performance support inputs and reporting evidence |

## 23. API Contract

Portfolio-scoped routes require active-profile access unless explicitly user/admin/global.

| Area | Current routes and mutation boundary |
|---|---|
| Profiles | `/api/portfolios`, `/api/portfolios/{portfolio}`, `/{portfolio}/set-default`, `/{portfolio}/clone-as-paper` |
| Transactions/import | `/api/transactions`, `/api/transactions/bulk`; writes use shared financial write semantics |
| Holdings/adoption | `/api/holdings`, `POST /api/holdings/{holding}/adopt` |
| Cash/reservations | `/api/cash`, `/reservations`, `/ledger`, `/statement`, `/as-of`, `/deposit`, `/withdraw`, `/adjust`, `/ledger/{entry}/reverse` |
| Corporate actions | `/api/corporate-actions`, `/preview`; application is auditable and separate from price repair |
| Snapshots/history | `/api/portfolio/rebuild-history`, `/snapshots`, `/historical-holdings`, `/compare` |
| Analysis/tax | `/api/analysis/performance`, `/account-performance`, `/attribution`, `/api/tax/*` |
| Capital/lending | `/api/v1/capital/*` for allocations, requests, lender actions, recall, bridges, pending proceeds and capital resolution |

Routes are not permission to bypass profile ownership, transaction validation, recommendation execution gates or ledger invariants.

## 24. Services And Ownership

Stable ownership boundaries:

- `TransactionWriteService` — canonical financial transaction creation path.
- `TransactionRealizationService` / `SellAttributionService` — sell realization and ownership-aware attribution.
- `HoldingsCalculationService` / `PortfolioCalculationService` — derived holding/portfolio calculation.
- `HoldingAdoptionService` / `HoldingOwnershipBackfill` — ownership migration/adoption integrity.
- `CashManagementService` — deposits, withdrawals, adjustments, ledger, statement and reversals.
- `PortfolioCapitalAccountingService` — portfolio/strategy capital, reserve, availability and lending snapshot calculations.
- `CapitalRequestService`, `CapitalRequestApprovalService`, `CapitalResolutionService` and `RecommendationLendingCoordinator` — request, approval, commitment and recommendation-capital orchestration.
- Lending/recall services — lender eligibility/ranking, loan repayment, recall, bridge, immediate settlement, liquidation and proceeds application.
- `CorporateActionService` — financial ledger/holding corporate-action application; price adjustment/repair services own OHLCV repair.
- `PortfolioSnapshotRebuildService` / `PortfolioHistoricalHoldingsService` — derived snapshots and as-of reconstruction.
- Performance/attribution/tax services — reporting over source evidence, including XIRR/FIFO support.

Controllers and pages orchestrate requests; they must not replace these services with direct writes that bypass accounting consequences.

## 25. Rebuild And Consistency Mechanics

Derived state must be rebuilt when durable source evidence changes:

- transaction create/update/delete/correction may require holdings, realization, cash and snapshot refresh/rebuild;
- corporate-action financial application may require ownership-aware holding/restatement rebuild;
- price correction or OHLCV repair may require historical valuation, snapshot/history and analytics refresh without rewriting the financial ledger;
- snapshot/history rebuild recalculates derived output from source evidence; it must not create or delete source transactions;
- failed or incomplete rebuild output must remain distinguishable from a valid zero/complete portfolio view.

Current anchors: `PortfolioSnapshotRebuildService`, `PortfolioHistoryController`, `PortfolioCalculationService`, corporate-action services, historical services and snapshot/corporate-action tests.

## 26. Error And Recovery Semantics

| Condition | Required behavior |
|---|---|
| Insufficient cash/capital | Reject or retain actionable recommendation with correct UNFUNDED/PARTIAL state; do not silently turn it into WATCH or manufacture cash |
| Failed transaction update/delete | Preserve source evidence and atomic financial consistency; rebuild derived state only after valid source outcome |
| Partial import batch | Track batch/item result, apply only valid shared-write outcomes and expose errors without a parallel ledger |
| Invalid adoption/ownership | Reject cross-profile/cross-user or invalid ownership change; do not blend holdings to hide the conflict |
| Reservation mismatch | Reconcile through recommendation/execution lifecycle and ledger evidence; do not adjust raw cash blindly |
| Recall cannot settle immediately | Preserve recall/pending-held/bridge state and audit evidence; do not invent a repayment |
| Missing historical price | Preserve unknown/incomplete context in historical/performance output; do not use zero or current price as a substitute |
| Snapshot rebuild failure | Preserve source ledger and prior valid snapshot evidence; report failure/partial state for recovery |
| Corporate-action inconsistency | Preserve preview/action/effective-date evidence; separate financial correction from OHLCV repair and avoid double restatement |
| Reversal failure | Keep original event and failed recovery context; do not destructively erase accounting evidence |

## 27. Test And Verification Anchors

| Area | Meaningful test anchor | What it proves |
|---|---|---|
| Transaction write and realization | `TransactionSellRealizationTest`, `TransactionUpdateTest`, `TransactionRealizationServiceTest` | Realization/correction paths preserve transaction-driven effects |
| Financial integrity | `FinancialIntegrityHardeningTest`, `TransactionIndexScopeTest` | Shared financial write/scoping and guardrail behavior |
| Holdings and ownership | `HoldingsCalculationServiceTest`, `HoldingAdoptionTest`, `HoldingOwnershipBackfillTest`, `V4Spec005SellAttributionTest` | Derived holdings, adoption/backfill and ownership-aware sell attribution |
| Cash/reservations/capital | `V3CapitalAccountingTest`, `V3CapitalLendingAccountingTest`, `V5CashStatementTest` | Cash/capital snapshot, lending availability and dated statement semantics |
| Lending and repayment | `CapitalRequestServiceTest`, `CapitalRequestApprovalServiceTest`, `CapitalLoanRepaymentServiceTest`, lender eligibility/ranking tests | Request/approval, lender selection, outstanding/repayment and deterministic eligibility/ranking |
| Recall/bridge/proceeds | `RecallPhase1FoundationTest`, `RecallPhase2FulfilmentTest`, `RecallPhase3aApiTest`, `RecallGapClosureTest`, `V4Spec004CashLedgerSpecialMovementsTest` | Recall lifecycle, fulfilment/API, gap closure and special cash movement trail |
| Imports | `BulkTransactionImportTest`, `TransactionImportBatchMigrationIndexNamesTest` | Batch/item import through financial write path and schema resilience |
| Corporate actions | `CorporateActionApiTest`, `CorporateActionServiceTest`, `CorporateActionOd10OwnershipTest`, `CorporateActionSpec003RestatementTest` | Preview/apply, ownership quantity and strategy-position restatement behavior |
| Snapshots/historical | `PortfolioSnapshotApiTest`, `PortfolioSnapshotRebuildTest`, `HistoricalHoldingsTest`, `PortfolioHistoricalHoldingsServiceTest` | Derived snapshot rebuild and as-of holdings behavior |
| Performance/tax | `V5PortfolioPerformanceApiTest`, `V5AccountPerformanceApiTest`, `V5PortfolioAttributionApiTest`, `FifoTaxLotCalculatorTest`, tax API/export tests | Reporting inputs, attribution/FIFO/tax evidence and export behavior |

Test coverage gap — implementation audit follow-up:

- full multi-strategy, same-security ownership and adoption workflow in the running UI;
- production reconciliation between broker fills and ledger/holding effects;
- end-to-end partial execution/reservation release/close-at-actual behavior;
- real data recovery after price/corporate-action correction and snapshot/history rebuild;
- all recall bridge and pending-proceeds timing paths with production-like calendar/broker inputs;
- user-visible failure/empty/loading states for cash, historical and performance workflows.

## 28. Debugging Guide

| Symptom | Likely layers to inspect |
|---|---|
| Holding quantity or cost basis wrong | Source `Transaction` rows, fees/corporate actions, `HoldingsCalculationService`, ownership/adoption evidence and rebuild history |
| Strategy ownership wrong | `Holding`, `HoldingAdoption`, strategy/profile linkage, `HoldingOwnershipBackfill`, `SellAttributionService` and adoption tests |
| Cash available wrong | `CashLedgerEntry`, reversal links, reservation state, capital snapshot, pending proceeds and `CashManagementService` |
| Reservation stuck | Recommendation status/cancellation/expiry/supersession, execution/order evidence and reservation lifecycle fields |
| Loan/recall not settling | `CapitalLoan`, `CapitalRecall`, `RecallBridgeLoan`, outstanding/return rows, recall/proceeds services and settlement command |
| Pending proceeds wrong | `PendingSaleProceeds`, availability date, sale/recall linkage, `SaleProceedsAvailabilityService` and `ProceedsApplicationService` |
| Historical holdings disagree with live | Requested as-of date, transactions/action dates, ownership, price availability, `PortfolioHistoricalHoldingsService` and snapshot distinction |
| Snapshot stale or wrong | Source transaction/price/action changes, rebuild status, `PortfolioSnapshotRebuildService`, latest snapshot rows and rebuild tests |
| Corporate action doubled/missed | Corporate-action event/effective date, ledger/holding restatement, ownership, factor-repair delegation and corporate-action tests |
| Tax/P&L mismatch | Transaction realization, fees/dividends, opening lots/tax losses, benchmark evidence, `FifoTaxLotCalculator` and performance/attribution services |
| Import changed cash unexpectedly | Import batch/items, shared transaction write, cash ledger entries, rollback/error payload and `BulkTransactionImportTest` |

## 29. Implementation Alignment Notes

The following accepted contracts require verification under the V1-V7 implementation audit. This list does not label them defects and does not weaken the current contract:

- full UI discoverability and role-safe operation of multi-strategy ownership/adoption/lending/recall surfaces;
- production correctness of partial execution, reservation conversion/release and close-at-actual accounting;
- actual broker-fill to transaction/holding/cash reconciliation behavior;
- deployment/runtime recovery of snapshot/history rebuild after corporate-action and price repair;
- complete recall/bridge/pending-proceeds lifecycle behavior with real timing and settlement inputs;
- portfolio reserve warning/withdrawal presentation and configurable lending-cap behavior across all entry points;
- as-of cash, comparison, export, performance and tax behavior with incomplete/missing market data;
- coverage of reverse/update/import failure recovery and user-visible state handling.

## 30. Historical Context

V1 established transaction-driven holdings, cash and corporate-action foundations. V2/V2.1 added bulk import, historical holdings, snapshots, cash hardening and data-quality/corporate-action operations. V3 introduced explicit multi-strategy ownership, capital allocation, lending, recall, bridge and proceeds semantics. V4 clarified ownership attribution, special cash movements and corporate-action restatement. V5 added cash-as-of, compare, performance, attribution and tax reporting. Current behavior is defined here rather than by release chronology.
