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

Existing transactions remain excluded from grouped fee enrollment by a migration-default-false marker. New cash-backed, non-simulation trades are explicitly enrolled and their configured fee components are reallocated across enrolled same-date fills using the calculator's daily invoice boundaries (STT and stamp day groups; transaction charges by side and venue; SEBI daily). API writes, batch imports, and edits/deletes use the same backend allocator under the profile lock. Manual historical corrections and paper/cashless fills remain outside the group. DP remains independently grouped once per profile, ISIN and sell day. A fee reallocation changes each trade's stored fee and creates a signed, transaction-linked fee correction in StoX cash for any already-posted peer row.

The allocation is a deterministic estimate, not an exact broker-assessed per-fill charge: Zerodha contract notes commonly give daily aggregate component lines and do not establish each fill's fee precisely. The private comparison remains 17 fully comparable days with ₹0.21 total absolute daily fee difference; the grouped formula was not changed by the persistence work. Do not interpret grouped allocation as individual contract-note proof, and do not add a balancing cash entry to improve the comparison.

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

`cash:cleanup-reconstruction-artifacts` is a one-shot profile-1-only dry-run/apply/rollback command for the exact private cleanup proposal. Dry-run is the default. Apply requires absolute private evidence and backup paths, the exact source counts/totals, all 89 profile-owned transaction rows and independent trade cash deltas, the 203-row exact baseline SHA-256 and artifact snapshots, an admin user ID, interactive confirmation, and a transaction that locks profile, account, transactions and cash rows. It deletes only artifact IDs 244/245/248/252/253, adjusts opening row 52 to the derived residual ₹7,228,613.4225, classifies it as `derived_opening_assumption`, and rebuilds chronological `balance_after`; it never changes trades or other profiles. The rollback requires the original backup SHA plus an apply receipt matching the exact post-apply ledger hash. The opening is not a genuine bank deposit. No production cleanup was executed in this implementation task. The command fails closed when the exact baseline proof is unavailable; matching the closing balance alone is not reconciliation acceptance.

The dry-run emits a row-level SHA-256 map keyed by ledger ID plus the recipe used for the aggregate baseline hash. To reproduce it on the same deployed schema and database driver: query profile 1 ledger rows ordered by `id`; for each Eloquent row take `getAttributes()` (raw database values in hydrated column order), remove only `entry_classification`, JSON-encode with `JSON_UNESCAPED_SLASHES`, and SHA-256 the bytes. The aggregate hash is SHA-256 of the JSON-encoded list of those row attribute maps in ID order, using the same JSON flag. JSON errors throw; there is no normalization of decimal strings, nulls, timestamps or attribute order. These hashes are diagnostics, not a substitute for the pinned 203-row baseline hash, exact artifact checks, private source evidence, or locked pre-apply revalidation.
