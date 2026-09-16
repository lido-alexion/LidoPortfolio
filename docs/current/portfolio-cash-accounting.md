# Portfolio, Cash, And Accounting

## Current Behaviour

Portfolio profiles are the main business boundary. A user can create, update, delete, set default, and clone portfolios, including paper portfolios. Most holdings, transactions, watchlists, snapshots, cash, corporate actions, and analytics are tied to the active portfolio profile.

Transactions are the durable accounting input for holdings and realized outcomes. Transaction import supports bulk CSV batches with item tracking. Sell realization and ownership attribution are persisted so closed positions, performance, tax, and historical holdings can be explained.

Holdings are calculated from transaction history, corporate-action adjustments, fees, prices, and ownership episodes. Users can adopt holdings where ownership realignment is needed.

Cash management has a ledger model. Deposits, withdrawals, adjustments, reservations, statements, as-of balances, special cash movements, pending sale proceeds, capital requests, lending, recalls, and bridge loans are modeled explicitly rather than treated as loose annotations. Ledger entries can be reversed through a linked reversal reference.

Corporate actions cover split, bonus, rights, and related adjustment workflows. Corporate action price repair and data-quality detection are part of the broader market-data/data-quality system.

Snapshots and historical holdings give point-in-time portfolio views. Rebuild operations are explicit and can be used when transaction, price, or corporate-action history changes.

Performance and tax features include XIRR, money-weighted return, benchmarks, dividends, tax losses, opening lots, account performance, attribution, and exportable tax datasets.

## Technical Contract

Key API routes:

- `/api/portfolios`, `/api/portfolios/{portfolio}`, `/api/portfolios/{portfolio}/set-default`, `/api/portfolios/{portfolio}/clone-as-paper`
- `/api/transactions`, `/api/transactions/bulk`
- `/api/holdings`, `/api/holdings/{holding}/adopt`
- `/api/cash`, `/api/cash/reservations`, `/api/cash/ledger`, `/api/cash/statement`, `/api/cash/as-of`, `/api/cash/deposit`, `/api/cash/withdraw`, `/api/cash/adjust`, `/api/cash/ledger/{entry}/reverse`
- `/api/corporate-actions`, `/api/corporate-actions/preview`
- `/api/portfolio/rebuild-history`, `/api/portfolio/snapshots`, `/api/portfolio/historical-holdings`, `/api/portfolio/compare`
- `/api/analysis/performance`, `/api/analysis/account-performance`, `/api/analysis/attribution`, `/api/tax/*`
- `/api/v1/capital/*` for capital allocations, lending, recall, bridge loans, pending sale proceeds, and recommendation capital resolution.

Primary models include `PortfolioProfile`, `Transaction`, `Holding`, `HoldingAdoption`, `CashAccount`, `CashLedgerEntry`, `CapitalRequest`, `CapitalLoan`, `CapitalRecall`, `RecallBridgeLoan`, `PendingSaleProceeds`, `CorporateAction`, `Dividend`, `TaxLoss`, `OpeningTaxLot`, `PortfolioSnapshot`, `PortfolioReplayRun`, and `PortfolioReplayCheckpoint`.

Primary services include `PortfolioProfileService`, `PortfolioCalculationService`, `HoldingsCalculationService`, `TransactionWriteService`, `TransactionRealizationService`, `SellAttributionService`, `HoldingOwnershipBackfill`, `HoldingAdoptionService`, `CashManagementService`, `PortfolioCapitalAccountingService`, `CapitalRequestService`, `CapitalResolutionService`, `RecallService`, `RecallBridgeLoanService`, `ProceedsApplicationService`, `CorporateActionService`, `CorporateActionPriceAdjustmentService`, `PortfolioSnapshotRebuildService`, `PortfolioHistoricalHoldingsService`, `PortfolioPerformanceService`, `AccountPerformanceService`, `PortfolioAttributionService`, `FifoTaxLotCalculator`, and `XirrService`.

## Data Rules

- Portfolio data must be scoped to the active portfolio unless a route is explicitly user/admin/global.
- Transaction writes are the preferred source for accounting changes; derived holdings/snapshots must be rebuildable.
- Cash availability and recommendation affordability must use the ledger/reservation/capital model rather than raw cash fields.
- Sell proceeds can be pending before availability and may participate in capital resolution.
- Historical/as-of views should use date-aware holdings, prices, transactions, and benchmark evidence.

## Debugging Sources

- Holdings mismatch: inspect transactions, corporate actions, price history, holding adoption records, and `HoldingsCalculationService`.
- Cash mismatch: inspect cash ledger entries, reversals, reservations, pending sale proceeds, special movements, and `CashManagementService`.
- Tax/performance mismatch: inspect opening lots, dividends, tax losses, transaction realization, benchmark prices, and analysis preferences.
- Snapshot mismatch: inspect rebuild job/status, latest snapshot rows, and whether transaction/price history changed after the snapshot.

## Related Docs

- [Market Data And Data Quality](./market-data-and-data-quality.md)
- [Strategy And Recommendations](./strategy-and-recommendations.md)
- [Analytics, Review, And Backtesting](./analytics-review-backtesting.md)
- [Execution, Broker, And Safety](./execution-broker-safety.md)

## Historical Context

Older specs separated cash, snapshots, historical holdings, corporate actions, and lending across V2/V2.1/V3/V4/V5 documents. Current behaviour treats them as one accounting domain because bugs in one usually surface in another.

