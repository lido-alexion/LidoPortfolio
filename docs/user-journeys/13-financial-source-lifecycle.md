# StoX User Journeys — Financial Source Lifecycle

[Back to journey index](README.md)

These flows preserve the accepted [accounting contract](../current/portfolio-cash-accounting.md). Actual financial source writes require real documentary evidence and the correct owned portfolio. Synthetic UI fixtures do not qualify accounting outcomes. Broker operations remain separate.

## EXE-16 — Correct or delete recorded transaction evidence

- Requires: An owned portfolio and the actual original transaction/source evidence.
- Warning: Corrections/deletions change financial source state and derived accounting. Deleting a recommendation-linked transaction can return its intent to Pending Execution; it does not cancel a broker fill.

1. Open `/transactions`, find the recorded row and choose Edit. Inspect its stock, side, actual date, quantity, price and notes.
2. Cancel Edit to leave source evidence unchanged, or enter a supported correction and choose Update Transaction.
3. If saving is unconfirmed, retain the draft and inspect existing evidence before explicit retry. A correction updates the same transaction, not a new trade.
4. Verify the confirmed row and derived holdings/cash/history through the accounting contract.
5. If deletion is required, choose Delete and review its ledger/recommendation warning. Dismiss to preserve the row; confirm only the intended correction.
6. A failed deletion preserves the visible row for inspection/retry. After confirmation inspect refreshed source and any reopened Pending Execution intent.

**Expected result:** Explicit corrections/deletions remain attributable to the original event and never submit broker orders.

## E2E-28 — Review and import an idempotent transaction CSV batch

- Requires: An owned portfolio and actual transaction evidence in the supported CSV format.
- Warning: Import writes all reviewed rows atomically through the financial write path. An uncertain response is not proof that nothing committed. Retry the same batch identity; use a new batch only for a separate import.

1. Open `/transactions`, choose Bulk (CSV), and paste Stock, Quantity, Average Price and Transaction Type columns, optionally Owner (`unmanaged` or `strategy:ID`).
2. Choose Parse & review. Correct invalid rows, whole positive quantities/prices, side, exchange and actual dates. Dates default to today and must be reviewed.
3. Back to CSV abandons that review/batch identity. Reparse only when intending a new review; no source write occurs before Save all.
4. Choose Save all once. If unavailable/unconfirmed, inspect source evidence and retry the same reviewed batch. Validation failures retain rows and their batch identity for correction.
5. A committed/already-committed response locks the batch and removes Save all. Verify refreshed transactions and derived accounting.
6. Choose New import only for additional, separate financial evidence.

**Expected result:** Repeating the same idempotent batch after an uncertain response cannot intentionally duplicate the financial event; real domain persistence is verified independently from UI fixtures.

## E2E-29 — Adopt an unmanaged holding into one enabled strategy

- Requires: An owned unmanaged holding and an enabled eligible destination strategy.
- Warning: Adoption changes ownership-aware future lifecycle. Same-stock destination ownership can merge under accepted weighted-average rules; sibling strategies remain separate. It is not a trade.

1. Open `/holdings`, identify the unmanaged ownership episode, open its Sell menu and choose Adopt.
2. Review the destination strategy and any merge/sibling disclosures. No eligible enabled strategy leaves Adopt unavailable; close/cancel preserves ownership.
3. Choose the intended Destination strategy and Adopt explicitly.
4. On failure retain the selected destination and unchanged visible ownership; inspect existing adoption evidence before retrying.
5. After success inspect the refreshed ownership episodes and confirm other strategies' same-stock quantities/basis are unchanged.

**Expected result:** Adoption is explicit, scoped and auditable, without creating economic quantity or authority over a sibling strategy.

## E2E-30 — Preview and explicitly apply a split or bonus

- Requires: An owned portfolio, affected stock and official action/effective-date evidence for any real application.
- Warning: Financial ledger application is separate from historical price repair. Rights subscriptions are normal buys at the actual subscription price, not an automatic split/bonus.

1. From `/holdings`, choose Split/Bonus in the intended holding's menu, or open `/corporate-action` and select the stock.
2. Choose Stock split or Bonus issue, enter its ratio, actual Ex-date / record date, supported split scope and source Notes.
3. Preview affected transactions, warnings, blocking errors and estimated post-state. A failed preview leaves inputs available; changing any input invalidates the prior preview.
4. Apply without a preview first obtains one; blocking errors cannot apply ledger changes. Review a valid preview before applying.
5. Choose Apply and review the stock/ratio/ledger confirmation. Dismiss to preserve source state. A failed application does not claim success; inspect existing action evidence before retrying.
6. After confirmed application, review the matching transactions/holdings. Verify cost/quantity/ownership consequences through accounting tests, not a synthetic browser response.

**Expected result:** Preview, explicit confirmation and actual application remain distinct, and no broker order or price-repair operation is triggered.
