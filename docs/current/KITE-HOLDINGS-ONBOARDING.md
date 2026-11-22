# Kite holdings onboarding

This page documents the implementation of the frozen decisions in [Kite holdings onboarding (2026-11-22)](../product-decisions/kite-holdings-onboarding-frozen-2026-11-22.md).

## Importing a Zerodha Console tradebook

The wizard accepts CSV and TXT, XLSX workbooks, and Excel 2003 XML workbooks with an `.xls` extension. Legacy binary OLE/BIFF `.xls` workbooks are unsupported. Open one in Excel or LibreOffice and use **Save As** to create an `.xlsx` copy before uploading. For `.xlsx`, the parser reads the first worksheet as cell values only. Formulas in that sheet, macro projects, external workbook links, malformed archives, and encrypted workbooks are rejected; formulas are never calculated or executed.

Uploads are limited to 10 MB, 20,000 data rows, 64 columns, 500 XLSX archive members, and 25 MB expanded XLSX content. The import requires symbol, side, quantity, price, and a strict supported date. A malformed non-empty trade row rejects the whole upload rather than being silently ignored. Only trades for symbols currently in the Kite holdings draft are merged. Uploading another period preserves manually entered lots and removes only the provisional Kite opening lot for stocks receiving tradebook rows.

Broker trade IDs are retained in the staging record and used for repeat-import deduplication. For records without a broker ID, exact matching side, quantity, price, date, and provenance are deduplicated. Identical executions without broker IDs cannot be distinguished reliably; inspect the source tradebook when the broker omits IDs.

## Staging and commit safeguards

- Fetching Kite holdings creates editable staging rows; it does not create portfolio transactions.
- Kite opening rows have an explicitly marked placeholder date. Replace it with an evidence-based date before verification. A placeholder cannot be verified or committed.
- Verification checks each stock's net quantity, that sells are covered by staged buys, and that remaining staged FIFO cost is within the review tolerance of Kite's reported cost basis. Passing quantity alone does not establish historical accuracy.
- Import, edit, verify, and commit state is scoped to the active portfolio profile. A stock with existing StoX transaction history is blocked from onboarding for manual reconciliation.
- Commit requires individually verified stocks, rechecks current Kite quantity and average price, writes the selected stocks in one database transaction, and records an idempotency token on each created transaction. A failure rolls the entire selected batch back.
- Uncommitted staging rows do not enter the transaction ledger or affect portfolio accounting. Provisional dates are never written as transaction dates.

Corporate actions, transfers, and incomplete tradebook windows may create quantity or cost mismatches. Resolve those from evidence before verification; the wizard does not synthesize missing trades or corporate action adjustments.
