# Persisted transaction and evaluation history

These are stored financial/analytical observations. Recovery must distinguish an unavailable read from an authoritative empty result and preserve source identity. Editing/deleting a recorded transaction is distinct from placing/cancelling a broker order.

## E2E-52 — Recover squared-off transaction evidence and deliberate corrections

- Requires: An authenticated Investor with stored squared-off transaction records.
- Warning: Deleting stored financial evidence affects portfolio calculations. Inspect the intended record and confirm deliberately. An unconfirmed deletion is not success; no broker order is placed or cancelled by this flow.

1. Open Squared-off transactions (`/transactions/closed`). A failed read explains unavailability and offers Retry, without claiming there are no transactions.
2. Retry the current read and inspect dated transaction type, quantity, price, realization and fees. Search by symbol/name using the current closed scope and pagination.
3. A failed filtered read retains known rows with an unavailable warning; it does not fabricate an empty result. Retry uses the same filter.
4. Edit uses the existing Transactions form and its retained-draft safeguards (EXE-16). Inspect the target stored evidence before changing it.
5. Delete only the intended record after confirmation. Cancelling sends no delete request. A rejected deletion keeps the row and explains the unconfirmed operation.
6. Inspect/retry deliberately. Confirmed deletion removes only the target, refreshes the current filtered view and invalidates affected dashboard data. Other transactions and strategy attribution remain intact.

**Expected result:** Unavailable, cancelled, unconfirmed and confirmed states are distinct and financial evidence is retained until confirmation.

## E2E-53 — Inspect a stored evaluation run and recover unavailable observations

- Requires: An authenticated Investor with access to portfolio discovery/evaluation history.
- Warning: Stored evaluation scores are observations; opening or retrying history does not create recommendations, run training, place orders or move cash.

1. Open Candidates (`/candidates`). Current discovery failure/retry remains the existing recommendation/discovery journey; historical evaluations are a separate named panel.
2. An unavailable history list explains the read failure and offers Retry evaluation history. It does not report that no runs exist.
3. Retry and choose a specific run by its stable identifier/status/result count.
4. If that run's result read fails, preserve its selection and existing known observations. Explain unavailability rather than declaring no results; Retry selected evaluation run reads the same identity.
5. Inspect rank, symbol, score, confidence and explanation from the stored result. Source observations remain unchanged.
6. Select a different run. A successfully read empty run may correctly report no results, while the previous run's values are never relabelled as the new identity.

**Expected result:** Current and stored evaluations remain separate, with accurate empty/error semantics and explicit non-destructive recovery.
