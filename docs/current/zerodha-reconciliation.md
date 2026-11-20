# Zerodha Trade Fee And Cash Reconciliation

## Source boundaries

Zerodha is the evidence source for trade-level costs, broker cash movements and closing balance. StoX uses its own transaction and cash ledgers to represent the trades and cash events recorded in the selected portfolio. These ledgers have different scopes: StoX does not import every off-platform broker trade, and the broker's net-equity settlement is an aggregate across its trading account.

- Calculate a StoX buy cash effect as negative gross trade value and fees; calculate a sell cash effect as positive gross trade value less fees.
- Do not add an aggregate Zerodha net-settlement event when the StoX trade cash effects already represent the StoX trades.
- Record bank transfers and account charges only from their source rows. Do not infer a bank transfer from a residual balance difference.
- At most one opening amount may be derived by reverse-walking from an explicitly selected anchor. Its provenance must say `derived assumption`; it is not a verified deposit.
- StoX supports four decimal places for cash and trade fields. Zerodha may report six decimal places. Differences below one paise cannot be represented in StoX and must be disclosed rather than corrected with invented precision.
- DP charges already represented in a transaction's stored fees must not be charged again from a separate broker debit row.

Zerodha defines a funds-statement net-settlement entry as the debit or credit for settling equity trades. Its statement also identifies DP as a per-debit charge, and describes virtual contract notes as daily approximations of trading charges. See [funds statement entry definitions](https://support.zerodha.com/category/console/ledger/articles/what-do-these-ledger-entries-mean), [virtual contract notes](https://support.zerodha.com/category/console/reports/contract-note/articles/virtual-contract-note), and [current charge schedule](https://zerodha.com/charges).

## Fee evidence and limits

Contract-note summary charges are available by day and component. When several fills share a component summary, that invoice does not uniquely identify each fill's exact fee. A per-fill StoX breakdown in that case is a deterministic allocation estimate, not a broker-confirmed fee. For a single comparable fill group, the contract-note component amount is stronger evidence and should be compared directly.

STT rounds to the nearest rupee according to Zerodha's published rounding explanation. GST and statutory charge rounding can occur at invoice aggregation boundaries. Daily component totals therefore need to be compared with the sum of StoX's fills, not treated as independent invoice evidence for every transaction. Zerodha's rates can change over time; for example, its published transaction-charge notice records an NSE equity-delivery revision effective 2024-10-01. Current settings must not be applied retroactively to historical trades. See [STT calculation and rounding](https://support.zerodha.com/category/account-opening/resident-individual/ri-charges/articles/how-is-the-securities-transaction-tax-stt-calculated) and [2024 transaction-charge revision](https://zerodha.com/marketintel/bulletin/391488/revision-in-transactions-charges-from-1st-october-2024).

No fee change in this release rewrites existing transaction fees. Existing manually reconciled per-ID transaction, exchange and fee corrections remain authoritative. DP remains grouped once per profile, ISIN and sell day for newly enrolled execution rows. The current calculator is an estimate from configured rates; it does not claim to reconstruct historical contract notes or the daily aggregated invoice exactly.

## Comparison procedure

For a privacy-safe reconciliation, keep raw contract notes, broker CSV rows, transaction exports and profile identifiers outside the repository. Normalize only the following fields into a temporary or ignored fixture:

1. Fill date, side, venue, quantity and unit price; use an opaque local fill key instead of a broker order ID or symbol.
2. Invoice date and available component totals (brokerage, STT, exchange/IPF, SEBI, stamp, GST and DP).
3. Source cash event date, signed amount and a local event category.
4. Calculated StoX fees and cash, source amount, absolute variance in paise, and the evidence level (`single-fill invoice`, `daily aggregate allocation`, or `unavailable`).

Compare invoice-only components at the daily level when the note aggregates them. Show component variances separately so an offset in one fee cannot hide a different fee error. Do not insert a cash adjustment to make the ending balances equal. Explain residuals caused by off-StoX activity, 6dp versus 4dp precision, missing evidence and historical rates.

The deterministic comparison core is `app/scripts/zerodha-fee-comparator.mjs`; run it with `node scripts/zerodha-fee-comparator.mjs <sanitized-input.json>` from `app/`. Its anonymized schema example is `app/tests/fixtures/zerodha-fee-reconciliation-sample.json`. The tool consumes already-normalized inputs; it deliberately does not parse private notes or export identifiers. Invoice components are compared at daily granularity, and source cash events are compared only when explicitly paired.

## Opening balance and existing ledger review

An unknown opening balance stays unknown in historical cash-as-of output. A chosen present-day broker balance can be reverse-walked over proven broker cash movements and StoX-owned trade effects to derive a single opening assumption. The resulting opening must retain its anchor, calculation time and provenance. It cannot be called a bank deposit without bank evidence.

No reconciliation command in this release mutates financial rows. The existing account's synthetic opening adjustment and precision/residual adjustments have not been reversed or replaced. Before any future cleanup, a source-backed proposal must link every included event to its source, include all trades in scope, exclude aggregate settlement duplication and already-included DP charges, show per-component fee comparison, verify profile ownership, export the exact before-state, and pass a dry run. Applying such a proposal requires an explicit guarded transaction and rollback record. Until that proposal is independently verified, leave the existing ledger unchanged.
