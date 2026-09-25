# StoX User Journeys — End to End

[Back to journey index](README.md)

These journeys connect the smaller task journeys into realistic daily workflows. Follow the linked detailed journey when a step needs field-level explanation.

---

## E2E-01 — New idea to first BUY

**Goal:** Start with an investment rule that does not yet exist in StoX and finish with a correctly recorded BUY.

**Example idea:** “Consider stocks above MA200 with RSI(14) below 70, then apply my momentum strategy rules before buying.”

1. Create and validate the discovery rule using [SCR-02](01-screeners.md#scr-02--create-a-multi-condition-and-screener).
2. Run it once and inspect representative matches using [SCR-06](01-screeners.md#scr-06--run-a-screener-and-inspect-matches).
3. Create the strategy and attach the screener using [STR-02](02-strategies.md#str-02--create-a-strategy-when-the-required-screener-does-not-exist).
4. Configure scoring/thresholds, sizing, exit policy and market gates as required.
5. Enable the strategy using [STR-13](02-strategies.md#str-13--enable-a-strategy).
6. Run/wait for the decision pipeline using [REC-01](03-recommendations-review.md#rec-01--generate-recommendations-through-the-decision-pipeline).
7. Open `/recommendations` and inspect the generated OPEN/INCREASE recommendation and its evidence.
8. Confirm capital state and actual executable amount.
9. Approve it using [REC-07](03-recommendations-review.md#rec-07--approve-a-recommendation).
10. Open `/transactions/pending` and perform the final check using [EXE-05](04-execution-transactions.md#exe-05--review-pending-execution).
11. Execute manually or through the configured broker-assisted mode.
12. Verify the resulting transaction and strategy-owned holding using [EXE-15](04-execution-transactions.md#exe-15--verify-transaction-and-holding-after-execution).

**Success condition:** The final holding can be traced backward to the actual transaction, broker/manual fill, approved recommendation, strategy/version and discovery evidence.

---

## E2E-02 — Existing screener to BUY

**Goal:** Create a new strategy without duplicating a discovery definition that already exists.

1. Find and inspect the existing screener under `/screeners` or `/screeners/registry`.
2. Validate/reuse it using [SCR-07](01-screeners.md#scr-07--reuse-or-import-an-existing-screener).
3. Create the strategy using [STR-01](02-strategies.md#str-01--create-a-strategy-using-an-existing-screener).
4. Configure and enable it.
5. Run the decision pipeline.
6. Review the generated recommendation.
7. Approve if desired.
8. Review Pending Execution.
9. Execute.
10. Verify the resulting transaction and holding.

**Success condition:** The new strategy reuses the intended screener/version while retaining its own independent policy and ownership.

---

## E2E-03 — Exit signal to closed transaction

**Goal:** Start with an existing strategy-owned holding and finish with a correctly attributed exit.

1. Confirm the holding belongs to the intended strategy.
2. Ensure the strategy's exit policy is current.
3. Run/wait for the decision pipeline.
4. Open `/recommendations` and locate the `EXIT_POSITION` or `REDUCE_POSITION` recommendation.
5. Inspect the primary exit reason and quantity.
6. Confirm an entry market gate has not incorrectly suppressed the exit.
7. Approve the actionable exit recommendation.
8. Open `/transactions/pending`.
9. Verify the SELL quantity belongs to this strategy's position.
10. Submit/record the SELL.
11. Wait for actual fill/reconciliation.
12. Inspect `/transactions` and `/transactions/closed`.
13. Verify the strategy-owned holding is reduced or closed as intended.

**Success condition:** The realized transaction retains enough evidence to explain which strategy exited and why.

---

## E2E-04 — Strategy change and supersession

**Goal:** Change policy and safely replace materially stale live intent.

1. Open the existing strategy and make the intended policy change using [STR-10](02-strategies.md#str-10--edit-an-existing-strategy).
2. Save the strategy. Confirm that saving alone did not create or cancel recommendations.
3. Run the decision pipeline separately.
4. Open `/recommendations`.
5. If the new decision materially changes the same strategy/security target, inspect the old recommendation's superseded state and its link to the replacement.
6. Confirm the old recommendation is no longer treated as current executable intent.
7. Review the new recommendation normally.
8. Confirm old reservations/readiness were reconciled appropriately.

**Success condition:** Historical intent remains visible, while only the current policy-derived intent can proceed.

---

## E2E-05 — Approve, cancel and retry

**Goal:** Approve a valid recommendation, stop an execution attempt safely, and retry later without duplicating a trade.

1. Approve the recommendation and confirm `pending_execution`.
2. Start execution.
3. If no broker submission occurred, cancel using [EXE-08](04-execution-transactions.md#exe-08--cancel-before-broker-submission).
4. If a broker order was submitted, request cancellation using [EXE-09](04-execution-transactions.md#exe-09--cancel-a-submitted-broker-order).
5. Wait for final broker confirmation. “Cancellation requested” is not final cancellation.
6. Confirm whether any quantity filled before cancellation.
7. Reconcile partial fills exactly once.
8. Confirm the recommendation remains current/eligible for retry according to lifecycle policy.
9. Retry using [EXE-14](04-execution-transactions.md#exe-14--retry-without-creating-a-duplicate-order).

**Success condition:** The retry does not duplicate a prior fill and the recommendation/capital reservation remain internally consistent.

---

## E2E-06 — Insufficient capital to execution

**Goal:** Handle a valid BUY recommendation when available capital is below the desired target.

1. Open the recommendation and confirm the underlying action is OPEN/INCREASE.
2. Compare desired target with currently fundable amount.
3. Inspect the capital-resolution path: own capital, recall, lending/bridge or other supported source.
4. Do not rewrite the investment opinion merely because funding is unavailable.
5. Complete the supported capital-resolution step if you choose to fund it.
6. Return to the recommendation and confirm capital state/readiness changed.
7. Approve when lifecycle rules permit.
8. In `/transactions/pending`, verify actual executable amount and whole-share quantity.
9. Execute.
10. Verify actual transaction, cash and holding state.

**Success condition:** StoX preserves both the desired target and the actual funded/executed amount, with capital evidence explaining the difference.

---

## E2E-07 — Same stock in two strategies

**Goal:** Exit one strategy's position without damaging another strategy's ownership of the same security.

**Example:** Strategy A owns 10 shares and Strategy B owns 15 shares of the same stock. Strategy A generates EXIT while Strategy B remains HOLD.

1. Open Strategy A's recommendation.
2. Confirm it is `EXIT_POSITION` and its owned quantity is 10 in this example.
3. Confirm Strategy B remains a separate 15-share position/context.
4. Approve Strategy A's exit.
5. In Pending Execution, verify the SELL quantity is 10, not the portfolio-wide 25.
6. Execute and reconcile the actual fill.
7. Verify Strategy A's holding episode closes.
8. Verify Strategy B still owns its 15 shares and retains its own basis/policy/evidence.

**Success condition:** Same-security positions remain isolated by strategy throughout recommendation, execution and accounting.

---

## Review checklist for future expansion

When reviewing this catalogue, add a new journey whenever a user can reach a materially different outcome because of:

- a different lifecycle state;
- a different execution mode;
- capital/funding state;
- partial or uncertain broker outcome;
- multiple-strategy ownership;
- stale/superseded/expired intent;
- a meaningful recovery action; or
- a workflow that requires the user to cross several pages and is not obvious from a single page.

Simple read-only navigation and ordinary settings belong in a later secondary/trivial-journeys chapter unless they become prerequisites for one of the money-moving flows above.
