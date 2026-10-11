# StoX User Journeys — Portfolio Analysis and Tax Evidence

[Back to journey index](README.md)

These workflows preserve the accepted [portfolio accounting](../current/portfolio-cash-accounting.md) and [analytics](../current/analytics-review-backtesting.md) contracts. Reports consume durable evidence; missing values remain unavailable/incomplete. UI fixtures are not financial, provider-data or tax-calculation qualification.

## E2E-25 — Inspect historical holdings, compare dates and recover derived snapshots

- Requires: An owned active portfolio with dated financial source evidence.
- Warning: Historical holdings are an as-of reconstruction, snapshots are rebuildable derived views, and value change between dates is not investment return or causal attribution. Missing prices are not zero.

1. Open `/portfolio/historical-holdings`, choose As-of date and inspect quantity, cost basis, dated price, market value and completeness warnings. Refresh after an unavailable read; preserve unavailable price/value distinctions.
2. Follow Compare dates to `/portfolio/compare`, choose Date A before Date B with neither in the future, and Compare. Invalid dates cannot be submitted.
3. Inspect dated cash/holdings totals, external deposits/withdrawals/adjustments, holdings differences and transaction evidence in `(A, B]`. Retry a failed comparison without changing the requested dates.
4. Open `/portfolio/snapshots`, choose the history range and inspect the latest date, growth chart and daily values. Refresh a failed read; a failed refresh must not manufacture values or financial events.
5. If source corrections require rebuilding derived history, choose Rebuild history and review its confirmation. Dismiss to keep the existing derived state. A failed rebuild reports failure; check existing results before retrying.
6. Confirm successful rebuild and refreshed dated rows. Source ledger and live order state remain distinct from the recalculated presentation.

**Expected result:** Date-aware inputs, missing evidence and derived-state recovery stay visible without fabricating prices or rewriting economic events.

## E2E-26 — Review performance/tax scope, save preferences and preserve calculation evidence

- Requires: An owned active portfolio and the financial/benchmark evidence required by the chosen calculation.
- Warning: Account results are independently aggregated, not averaged portfolio percentages. What-if selection does not change configured inclusion. Tax estimates are not certified advice, and incomplete inputs must remain disclosed.

1. Open `/portfolio/performance-tax`, choose Performance from/to and Tax financial year, then Calculate. Correct invalid/future ranges; retry an unavailable calculation with the same reviewed inputs.
2. Inspect portfolio/account metrics, primary benchmark, attribution residual and tax completeness/limitations. Incomplete or unavailable values are not zero.
3. Review Analysis settings: Primary benchmark, comparison benchmarks, risk-free rate, annualization days and independent account performance/tax inclusion. Save settings. Failure retains the draft for retry; confirmed changes appear after reloading.
4. If needed, review and Save Account overrides separately; these are account analytics preferences, not a portfolio cash movement.
5. Enable What-if portfolio selection and choose owned portfolios. Inspect the resulting scope without changing the configured inclusion preferences; disable it to return to configured reporting.
6. Choose Preserve evidence for the intended portfolio performance, account performance or account tax calculation. Retry a failed preservation explicitly; do not claim immutable evidence was stored before success.
7. Export the desired tax dataset. A failed CSV export reports failure; retry and verify the selected dataset/financial-year scope and attachment. Downloading a report does not change financial sources.

**Expected result:** Reporting scope, preferences, missing evidence and preserved calculation evidence remain separate from economic source writes.

## E2E-27 — Record tax inputs and preview a dividend CSV before import

- Requires: An owned portfolio and actual documentary evidence for any real tax input.
- Warning: These are source-evidence writes. Inspect existing records after an uncertain response before retrying. A browser fixture cannot certify tax rules, filing eligibility or real import persistence.

1. In Record tax evidence on `/portfolio/performance-tax`, enter Manual dividend date, positive amount and source reference, then Record dividend. Failure retains the draft and reports that the write was not confirmed.
2. For Tax loss, choose term/status, enter a positive amount and the required evidence/reason for Confirmed carry-forward; Record loss. Do not confuse a calculated loss with confirmed carry-forward.
3. For Opening tax lot, select the stock, acquired date, positive quantity, cost basis and required source reason, then Record opening lot. These inputs supplement tax reconstruction; they do not place a trade.
4. Choose the supported Dividend CSV (`generic-dividend-csv.v1`) and Preview. Inspect validity, candidate rows and duplicates. A failed/invalid preview cannot enable Import validated rows.
5. Changing the file invalidates its prior preview; preview the corrected file again. Only then deliberately choose Import validated rows and inspect imported/duplicate counts.
6. After a failed write/import, inspect existing evidence before retrying. After success, verify refreshed reporting; a preview alone must not change reports or claim rows were imported.

**Expected result:** Validated inputs and explicit import remain separate from read-only calculation, preview and live broker execution.
