# StoX User Journeys — Execution and Transactions

[Back to journey index](README.md)

## Safety boundary

Recommendation approval and broker execution are deliberately separate. Before submitting or recording an execution, verify the stock, strategy, side, quantity, price/order type and broker/session state shown by StoX.

A broker submission, broker acknowledgement, fill and StoX accounting transaction are distinct events. When broker state is uncertain, reconcile before retrying.

---

## EXE-01 — Execute an approved recommendation manually

**Goal:** Carry out an approved recommendation outside broker automation and then bring the actual fill back into StoX.

1. Open `/transactions/pending`.
2. Select the approved recommendation.
3. Verify action, stock, strategy, quantity and expected amount.
4. Place the corresponding trade manually with the broker.
5. Wait for the actual broker result.
6. Record the actual execution in StoX using the supported transaction flow.
7. Use actual quantity/price/fees where required rather than copying an estimate blindly.
8. Confirm the pending recommendation is reconciled to the resulting transaction/holding.

**Expected result:** StoX accounting reflects the real trade, not merely the recommended target.

---

## EXE-02 — Record a manually executed transaction

**Goal:** Add actual trade evidence to the StoX ledger.

1. Open `/transactions`.
2. Start the supported add/record transaction flow.
3. Select the correct stock and BUY/SELL side.
4. Enter the actual trade date/time, quantity and execution price as required.
5. Enter charges/fees or other required accounting data where supported.
6. If the transaction completes a pending recommendation, use the supported linkage/reconciliation path rather than creating unrelated duplicate intent.
7. Save.
8. Verify holdings/cash/closed-position effects.

**Expected result:** The ledger and holdings reflect actual execution evidence.

---

## EXE-03 — Execute in Semi-Automatic mode

**Goal:** Let StoX submit an approved trade to Kite while requiring explicit user authorization.

1. Ensure the Zerodha/Kite connection is valid for the day/session.
2. Open `/transactions/pending`.
3. Select the approved recommendation/order candidate.
4. Review stock, strategy, BUY/SELL side, quantity, order type and estimated amount.
5. Start the Semi-Automatic execution action.
6. Complete the StoX execution authorization challenge when prompted.
7. Confirm submission.
8. Wait for broker acknowledgement/state rather than assuming a click means a fill.
9. Review the resulting order status and later fill/reconciliation.

**Expected result:** StoX submits only after explicit authorization and tracks the broker order independently from the recommendation approval record.

---

## EXE-04 — Authorize a Semi-Automatic execution

**Goal:** Complete the correct security challenge without confusing different authentication codes.

StoX/Kite can involve more than one authentication concept. Follow the label shown by the UI:

1. **Kite authentication/session:** Used to establish the broker connection. UI text should explicitly identify this as Kite when a Kite-provided code/login is required.
2. **StoX execution authorization:** Used to authorize sensitive Semi-Automatic trade submission. Use the authenticator application registered for StoX; the UI should identify the registered authenticator name where available.
3. If a recovery flow is being used, use a StoX recovery code only in the recovery-code field; an ordinary rotating authenticator code is not a recovery code.

**Expected result:** The user can tell which security system is requesting a code before entering it.

---

## EXE-05 — Review Pending Execution

**Goal:** Perform the final human check between recommendation approval and execution.

1. Open `/transactions/pending`.
2. Locate the approved recommendation.
3. Confirm the strategy and stock.
4. Confirm side/action: OPEN/INCREASE normally implies BUY; REDUCE/EXIT implies SELL.
5. Confirm actual executable quantity and amount rather than only the original desired target.
6. Confirm the execution window/lifetime has not expired.
7. Confirm capital reservation/readiness for a BUY.
8. Confirm the broker session is usable for broker-assisted modes.
9. Continue with execution or cancel the pending intent.

**Expected result:** Only current, eligible and intentionally reviewed work proceeds to execution.

---

## EXE-06 — Submit a BUY order

**Goal:** Submit a broker BUY corresponding to an approved OPEN/INCREASE recommendation.

1. Start from `/transactions/pending`.
2. Verify recommendation identity and strategy.
3. Verify stock/instrument mapping, quantity, variety and order type shown by the UI.
4. Confirm available/reserved capital and actual execution amount.
5. Complete required execution authorization.
6. Submit once.
7. Wait for the broker acknowledgement/status.
8. If the response is uncertain, do not immediately resubmit; follow EXE-13.
9. After fill, verify the StoX transaction and strategy-owned holding.

---

## EXE-07 — Submit a REDUCE or EXIT SELL order

**Goal:** Sell only the quantity authorized for the strategy-owned position.

1. Open the pending REDUCE/EXIT item.
2. Verify strategy identity and the holding episode it owns.
3. Confirm whether the intent is partial REDUCE or full EXIT.
4. Verify sell quantity against that strategy's owned quantity.
5. Complete required authorization and submit.
6. Monitor broker status.
7. After fill, verify the remaining holding for REDUCE or closure for EXIT.
8. If another strategy owns the same security, confirm that its position remains unchanged.

---

## EXE-08 — Cancel before broker submission

**Goal:** Stop an approved pending-execution intent before any broker order is in flight.

1. Open `/transactions/pending`.
2. Select the pending item.
3. Confirm that no broker order has already been submitted.
4. Use the supported cancel action.
5. Confirm the recommendation becomes cancelled and relevant reservation is released/reconciled.

**Expected result:** No broker order is created, and the cancelled intent remains in history rather than disappearing.

---

## EXE-09 — Cancel a submitted broker order

**Goal:** Request cancellation after broker submission without falsely claiming success before Kite confirms it.

1. Open the submitted order/execution detail.
2. Verify the broker order is still cancellable.
3. Request cancellation.
4. Treat **cancellation requested** as an intermediate state while broker confirmation is pending.
5. Wait for reconciliation/confirmation from Kite.
6. Only after broker confirmation treat the order as cancelled.
7. Verify recommendation status, fills and reserved capital are reconciled correctly.

**Expected result:** StoX does not report a final cancellation merely because a cancel request was sent.

---

## EXE-10 — Handle an unfilled or cancelled order

**Goal:** Preserve the investment decision and accounting correctly when no fill occurred.

1. Inspect broker order status and confirm filled quantity is zero.
2. Confirm the cancellation/rejection is final rather than pending reconciliation.
3. Verify no transaction was created for an unfilled quantity.
4. Review the recommendation lifecycle. Where the current product policy retains the recommendation for retry, confirm it remains in the appropriate pending state and funding reservation remains consistent.
5. Retry later only through the supported execution path.

---

## EXE-11 — Handle a partial fill

**Goal:** Account for the quantity actually filled while preserving the remaining execution state correctly.

1. Inspect broker-reported ordered quantity and filled quantity.
2. Confirm StoX records the filled part once and only once.
3. Verify holding/cash changes use the actual fill.
4. Inspect the remaining unfilled quantity/order state.
5. If cancellation is requested for the remainder, wait for broker confirmation.
6. Do not recreate the already filled quantity during a retry/reconciliation.

**Expected result:** Partial fill becomes accounting truth exactly once, while the remaining quantity follows its own broker lifecycle.

---

## EXE-12 — Handle broker rejection

**Goal:** Recover safely when Kite refuses an order.

1. Read the broker rejection reason.
2. Confirm that no fill occurred.
3. Verify StoX has not created a completed transaction merely because submission was attempted.
4. Correct the cause if it is user-actionable, such as session/order/input readiness.
5. Recheck the recommendation and execution window before retrying.
6. Retry through the existing intent rather than creating a duplicate recommendation/order unless the lifecycle explicitly requires replacement.

---

## EXE-13 — Reconcile uncertain broker state

**Goal:** Avoid duplicate trades when StoX cannot immediately prove whether the broker accepted/cancelled an order.

1. Do **not** submit the same trade again immediately.
2. Keep the StoX execution/order record in its pending/uncertain state.
3. Trigger or wait for the supported broker reconciliation process.
4. Compare StoX order identity with Kite's order state.
5. If Kite confirms the order/fill, reconcile that existing order.
6. If Kite confirms cancellation/rejection/no submission, allow the normal lifecycle to expose the safe next action.

**Expected result:** Network/API uncertainty cannot turn into a duplicate real-money order.

---

## EXE-14 — Retry without creating a duplicate order

**Goal:** Retry an unfilled execution while preserving recommendation identity and reservation rules.

1. First complete EXE-13 when prior broker state was uncertain.
2. Confirm the previous order is finally cancelled/rejected/unfilled as required by policy.
3. Confirm the recommendation is still current, not expired or superseded.
4. Confirm the intended quantity has not already been partially filled.
5. Reuse the supported retry path for the existing recommendation/execution intent.
6. Authorize and submit once.

**Expected result:** Retry creates only the necessary new broker attempt and never repeats already filled quantity.

---

## EXE-15 — Verify transaction and holding after execution

**Goal:** Confirm that broker execution became correct portfolio/accounting state.

1. Open `/transactions` and locate the resulting transaction.
2. Confirm stock, side, quantity, actual price and linkage/provenance.
3. Open `/holdings`.
4. For BUY, verify the strategy-owned position increased/was created as expected.
5. For REDUCE, verify the remaining strategy-owned quantity.
6. For EXIT, verify the relevant holding episode is closed and inspect `/transactions/closed` where applicable.
7. Check cash/reservation effects when relevant.
8. If the same stock belongs to another strategy, verify that unrelated strategy ownership was not changed.

**Expected result:** Recommendation, broker evidence, transaction, cash and holding state tell one consistent story.

---

## What comes next

For complete workflows crossing several pages, see [End-to-End Journeys](05-end-to-end.md).
