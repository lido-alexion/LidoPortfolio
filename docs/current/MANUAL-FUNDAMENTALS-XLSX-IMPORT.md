# Manual fundamental data workbook import

The Admin → Fundamental Data page accepts StoX's version 2.1 .xlsx workbook for an individual stock. The import is manual and does not call Yahoo, NSE, or BSE.

## How to use

1. Enter the stock symbol and choose its exchange.
2. Choose whether the uploaded figures are standalone or consolidated.
3. Select the matching workbook and confirm that its company name matches the selected stock.
4. Upload. The result reports how many facts were imported and how many unsupported rows were skipped.

The importer reads only the worksheet named **Data Sheet** (case-insensitive). It does not use the other tabs. It accepts sparse histories: a blank year/quarter is left blank and is not filled or interpolated.

## Mapped rows

| Workbook section / row | StoX fact |
| --- | --- |
| Annual and quarterly Profit & Loss: Sales | revenue |
| Annual and quarterly Profit & Loss: Net profit | net_income |
| Annual and quarterly Profit & Loss: Operating Profit | operating_profit |
| Annual and quarterly Profit & Loss: Interest | interest_expense |
| Balance Sheet: Equity Share Capital + Reserves | equity (sum, only when both components exist for that date) |
| Balance Sheet: Borrowings | debt |
| Balance Sheet: Net Block | property_plant_equipment |
| Balance Sheet: Capital Work in Progress | capital_work_in_progress |
| Balance Sheet: Receivables | trade_receivables |
| Balance Sheet: Inventory | inventory |
| Balance Sheet: Cash & Bank | cash_and_equivalents |
| Balance Sheet: No. of Equity Shares | shares_outstanding |
| Cash Flow: Cash from Operating Activity | operating_cash_flow |
| Cash Flow: Cash from Investing Activity | investing_cash_flow |
| Cash Flow: Cash from Financing Activity | financing_cash_flow |

Other row labels remain unimported and are counted as skipped. No values are inferred. The workbook's financial values are interpreted as INR crore and converted to INR; share counts remain share counts.

## Provenance and revision handling

Imported records use provider manual_spreadsheet. The uploader, original filename, workbook SHA-256, selected statement basis, and template version are retained in source metadata. The upload date is used as the availability date because the template does not provide publication dates; this avoids making the figures available to historical backtests before the upload. Re-uploading unchanged values is deduplicated through StoX's existing fact storage logic; changed values create a new revision.

## Safeguards

- The upload is limited to .xlsx, 5 MB compressed, 500 ZIP entries, and 12 MB expanded content.
- The workbook's current template version must be 2.1.
- The endpoint is admin-only and limited to five uploads per minute.
- The selected stock and exchange are explicit, and the administrator must confirm the company name before import.
- The importer parses the XLSX XML locally, with external entity resolution and network access disabled.
