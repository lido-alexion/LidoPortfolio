# StoX User Journeys — Portfolio Lifecycle and Cash

[Back to journey index](README.md)

These workflows preserve the accepted [portfolio and cash contract](../current/portfolio-cash-accounting.md). Portfolio accounting records are distinct from transfers at a bank or broker. Historical replay and forward paper simulation remain distinguishable from live execution.

## E2E-20 — Create, rename, select, default and retire a portfolio

- Requires: An authenticated investor who owns the affected portfolios.
- Warning: Deleting a portfolio permanently removes its transactions, holdings, alerts, snapshots and settings. Review the confirmation; cancellation must leave it intact.

1. Open `/portfolios` and enter a valid name using letters, numbers, spaces, hyphens or underscores.
2. Choose Live and Create. A failed request retains the entered name; correct validation or retry without assuming a new portfolio exists.
3. Confirm the new portfolio in the list. Choose Rename, enter its new name and Save. A failed save retains the editor; Cancel preserves the saved name.
4. Choose Use in this tab to select an owned portfolio. Selection is per tab, separately from the default preference.
5. On another owned portfolio, choose Set default and confirm the Default badge after a successful response. A failed response must not claim the preference changed.
6. Delete only an inactive, non-default portfolio while another portfolio exists. Read the irreversible confirmation; dismiss it to keep the portfolio. Retry a failed deletion only after checking that the portfolio remains present.
7. Reload and verify the saved names, default and surviving portfolios.

**Expected result:** Only confirmed changes alter the portfolio list. No broker order or external funds movement follows from portfolio management.

## E2E-21 — Clone a live portfolio into a distinct paper simulation

- Requires: An owned source portfolio.
- Warning: Paper simulation is simulated evidence, not a live order or proof of live execution. Copy holdings is explicit; source ownership and accounting remain intact.

1. Open `/portfolios`, locate the source and choose Clone as Paper.
2. Review Paper name, positive Simulated cash, Fill price and Copy holdings. Cancel abandons the form without cloning.
3. Choose Clone. A failed request leaves the draft available for correction or retry.
4. Confirm a separate PAPER portfolio with the chosen name; the original LIVE portfolio remains present.
5. Pause simulation and confirm its action changes to Resume simulation only after success. Retry a rejected pause while the previous state remains visible.
6. Resume simulation and verify the active simulation state after reload. Its worker follows effective-session checkpoints and must never submit a live broker order.

**Expected result:** The paper portfolio and its simulation state remain distinct from the live source. UI fixtures validate this boundary without starting production simulations.

## E2E-22 — Record cash evidence and inspect reservations without trading

- Requires: An owned active portfolio and actual accounting evidence for any real cash record.
- Warning: Record cash movement updates the StoX ledger; it does not transfer money at a bank or broker. Available cash and reserved cash differ. A reserve shortfall is a warning, not a second cash account or an independent withdrawal prohibition.

1. Open `/cash`, inspect cash balance, Reserved cash and Available cash. Use See reservation details to inspect recommendation identity and the reserved amount; do not infer a fill from reservation alone.
2. Choose Deposit, enter a positive whole-rupee Amount, Transaction date and optional Remarks, then submit. A failed write retains the input and unchanged statement; retry only after checking whether the record exists.
3. Confirm the successful entry in Cash account statement with its effective date, running balance and remarks.
4. Choose Withdraw. An amount above available cash cannot be recorded. Record a valid withdrawal using the same explicit form, then inspect the statement.
5. Choose Adjust, enter a non-zero signed whole-rupee amount and a required reason. Correct rejected input or retry a failed write while the draft remains visible. Confirm a separate adjustment entry rather than rewriting a prior economic event.
6. Reload and verify the statement and balances. No order, fill or external transfer should be created by these actions.

**Expected result:** Confirmed ledger events and reservations remain distinguishable. Browser automation uses a local deterministic ledger, never production cash mutation.
