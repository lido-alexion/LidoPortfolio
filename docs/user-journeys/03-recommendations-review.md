# StoX User Journeys — Recommendations and Review

[Back to journey index](README.md)

## Recommendation lifecycle in plain language

For an actionable recommendation, the normal user-facing lifecycle is approximately:

**Generated -> Pending Review -> Approve/Reject/Defer -> Pending Execution -> Executed/Cancelled/Expired/Superseded**

`WATCH` and `HOLD_POSITION` are informational. They are not trades to approve.

---

## REC-01 — Generate recommendations through the decision pipeline

**Goal:** Apply enabled strategies to current eligible evidence and produce current recommendations.

1. Ensure the intended strategy is configured and enabled.
2. Ensure required market data/discovery/evaluation prerequisites are available and current.
3. Trigger the supported decision-pipeline flow, or allow its scheduled run to execute.
4. Wait for completion; do not interpret a failed/incomplete pipeline as a valid “no recommendations” result.
5. Open `/recommendations`.
6. Review newly generated records by strategy, stock, action, score/evidence, capital state and status.

**Expected result:** Each recommendation retains the strategy/version and evidence that produced it. Actionable recommendations normally enter `pending_review`; informational WATCH/HOLD results are published without trade approval.

---

## REC-02 — Review new recommendations

**Goal:** Decide what to do with current actionable recommendations.

1. Open `/recommendations` or `/review` as appropriate to the current UI flow.
2. Focus on open/current recommendations before browsing historical records.
3. Select a recommendation.
4. Confirm the stock and strategy.
5. Read the proposed action and quantity/amount.
6. Review the evidence, failed checks, market-gate result, sizing and capital status.
7. Choose **Approve**, **Reject** or **Defer** according to your decision.
8. Add review notes where useful.

**Expected result:** StoX records the user, time, decision and notes as review history. Approval does not itself prove that a broker order has been submitted or filled.

---

## REC-03 — Understand why StoX recommended an action

**Goal:** Explain the recommendation before acting on it.

1. Open the recommendation detail.
2. Confirm the strategy and strategy version/configuration.
3. Inspect the discovery/screener evidence that made the stock eligible.
4. Inspect factor facts and strategy weighting/score.
5. Inspect threshold/label interpretation.
6. Check market-gate status.
7. Check whether the strategy already owns a position in the stock.
8. Review desired position sizing and whole-share constraints.
9. Review capital status separately from investment opinion.
10. For REDUCE/EXIT, inspect the primary exit reason.

**Expected result:** You can explain both **why the strategy wants the action** and **whether/how much can currently be executed**. Those are different questions.

---

## REC-04 — Understand OPEN, INCREASE, REDUCE and EXIT

- **OPEN_POSITION** — The strategy wants to initiate its own position where it currently owns none.
- **INCREASE_POSITION** — The strategy already owns a position and policy permits adding to it.
- **REDUCE_POSITION** — The strategy wants to sell part of its own position.
- **EXIT_POSITION** — The strategy wants to close its own position.

Before approval, verify that the action matches the current strategy-owned position. With multiple strategies, another strategy owning the same security does not give this strategy authority over that other position.

---

## REC-05 — Understand WATCH and HOLD

**WATCH** means the security is worth presenting/monitoring under policy but there is no executable trade intent.

**HOLD_POSITION** means retain the strategy-owned position; it is informational rather than an approval/execution request.

Do not look for an Approve-to-trade flow for WATCH/HOLD. If such a legacy/inconsistent record appears as executable, treat it as a lifecycle/data problem rather than normal behavior.

---

## REC-06 — Preview a recommendation for one stock

**Goal:** Ask what a selected strategy would currently conclude for one stock without creating a live recommendation.

1. Open the UI surface that exposes recommendation preview for the selected stock/strategy.
2. Select the stock.
3. Select the strategy.
4. Run/view the preview.
5. Read eligibility, evaluation, market-gate and explanation information.
6. If prerequisites are unavailable, read the reason instead of treating the preview as a valid neutral result.

**Expected result:** Preview is diagnostic only. It does not persist/supersede recommendations, reserve money, approve a trade or submit an order.

---

## REC-07 — Approve a recommendation

**Goal:** Authorize an actionable recommendation to proceed to execution when readiness rules permit.

1. Open the actionable recommendation.
2. Verify stock, strategy, action and intended quantity/amount.
3. Review the evidence and capital state.
4. Select **Approve**.
5. Complete any confirmation/review-note step presented by the UI.
6. Confirm the resulting status.

**Expected result:** A capital-ready actionable recommendation moves to `pending_execution` and relevant cash reservation is recorded where applicable.

**Important:** `pending_execution` means **approved and waiting for execution workflow**. It does not mean the broker filled an order.

---

## REC-08 — Reject a recommendation

**Goal:** Record that the current actionable recommendation should not proceed.

1. Open the recommendation.
2. Review the evidence.
3. Select **Reject**.
4. Record a note when the reason will be useful later.
5. Confirm the recommendation is no longer in the normal approval queue.

**Expected result:** The rejection and review history are preserved. The record is not deleted merely because the user declined the trade.

---

## REC-09 — Defer and later reopen

**Goal:** Postpone a decision without permanently rejecting it.

1. Open the recommendation and select **Defer**.
2. Add a note explaining what you are waiting for if useful.
3. Later locate the deferred recommendation/history.
4. Confirm it is still eligible to be reopened.
5. Select the supported **Reopen** action.
6. Review it again using current context before approving/rejecting.

**Expected result:** StoX retains both the original defer decision and later reopen decision as lifecycle history.

---

## REC-10 — Handle partial funding

**Goal:** Understand a recommendation whose desired investment is larger than presently fundable capital.

1. Open the recommendation detail.
2. Compare the **desired/target amount** with the **currently fundable/actual execution amount**.
3. Inspect own cash, recall/lending/capital-resolution information where relevant.
4. Do not interpret partial funding as a weaker investment score unless the strategy evidence separately says so.
5. Proceed to approval only when the lifecycle says the capital state is eligible.
6. In Pending Execution, verify the executable amount/quantity again.

**Expected result:** StoX preserves the original desired target and separately shows the amount that can actually be funded/executed.

---

## REC-11 — Handle an unfunded recommendation

**Goal:** Preserve valid investment intent while no executable funding is currently available.

1. Open the recommendation.
2. Confirm that the underlying action is still OPEN/INCREASE rather than assuming “no cash = WATCH”.
3. Inspect the funding gap and capital-resolution state.
4. Resolve funding through the supported cash/recall/lending flow if desired.
5. Return to the recommendation after capital status changes.
6. Approve only when readiness rules permit movement to pending execution.

**Expected result:** Investment opinion and funding readiness remain separate and auditable.

---

## REC-12 — Handle a superseded recommendation

**Goal:** Avoid acting on stale intent after a materially changed recommendation replaces it.

1. When a recommendation is marked superseded, do not treat it as the current executable instruction.
2. Follow the old-to-new relationship to the replacement recommendation.
3. Compare action, target, quantity, reason and strategy version/evidence.
4. Review the replacement recommendation normally.
5. Confirm reservations/readiness associated with the old intent have been reconciled according to lifecycle rules.

**Expected result:** History remains visible, but only the current valid intent proceeds. Re-running the pipeline with an unchanged target should not manufacture a fresh lifetime merely to keep stale intent alive.

---

## What comes next

After approval, continue with [Execution and Transactions](04-execution-transactions.md).
