# StoX User Journeys — Historical Analysis and Simulation

[Back to journey index](README.md)

These journeys preserve [Analytics, Review and Backtesting](../current/analytics-review-backtesting.md). Backtests and historical replay use simulated evidence. They do not generate live recommendations, change live cash or submit broker orders. Missing data and partial results remain disclosed.

## E2E-23 — Run and review a historical strategy experiment

- Requires: An owned portfolio, an active published strategy and eligible historical inputs.
- Warning: A historical result is simulated research. Compare execution assumptions, pinned versions and dataset limitations before drawing conclusions; cancellation or failure is not completion.

1. Open `/backtests`. If history is unavailable, use Retry backtest history; an operational read failure is not proof that no runs exist.
2. Choose New Backtest, review the background-operation notice and enter Name, Period, Initial capital, Execution price, Adverse slippage, optional declared parameter overrides, Notes and Tags. Cancel closes the form without starting a run.
3. Choose Start in New Strategy Backtest. A failed submission retains the draft for correction or retry. Review the resulting run's status; processing can continue in bounded background slices after leaving the page.
4. Open the run. If the detail read is temporarily unavailable, choose Retry loading backtest. A confirmed missing run instead offers Back to Backtests.
5. Inspect strategy/version, dates, assumptions, statistics, simulated trades, transactions and snapshots. Update Name/Notes/Tags using Save name / notes / tags; failure retains the draft and does not change historical economic evidence.
6. Return to All backtests. Duplicate creates a separate experiment using stored dates, capital, notes, tags and fill assumptions with the current strategy; it does not copy old results or reinterpret the original run.
7. Select two to five runs and Compare selected. Read differing assumptions and unavailable statistics; incompatible evidence must not be labeled compatible.
8. For an in-progress run, choose Cancel and review the checkpoint confirmation. Dismissing it sends no cancellation; a failed request leaves the prior state visible. A confirmed cancelled run retains partial evidence and is not completed.
9. Use Delete only after reviewing its irreversible confirmation. Dismiss it to preserve the run; retry a failed deletion after verifying the run remains present.

**Expected result:** Each experiment retains its own evidence and lifecycle, distinct from live portfolio accounting and broker execution.

## E2E-24 — Check replay readiness and inspect isolated replay evidence

- Requires: An owned portfolio and authoritative historical inputs for the chosen period.
- Warning: Readiness limitations and missing historical reconstruction are not waived by simulated UI fixtures. Historical replay differs from forward paper simulation and live execution.

1. Open `/portfolios`, locate Portfolio Replay and choose Starting state, From/To dates, optional Starting cash, Fill method and Slippage.
2. Choose Check. Retry an unavailable readiness request. Review blockers and limitations; Start remains disabled for a confirmed blocked assessment.
3. Correct inputs and Check again. For Historical branch, inspect historical strategy versions and explicitly review any counterfactual version selection before proceeding.
4. Choose Start only after reviewing readiness. A failed request retains the form; retry through the supported action. Confirm the separate queued/running replay and its immutable assumptions.
5. Choose Details and inspect status, checkpoint/transaction/recommendation counts, fill assumptions, pinned-world evidence and limitations. A failed read does not claim that evidence was loaded; retry Details.
6. Compare selected replay runs and read compatibility/assumption disclosure rather than treating missing statistics as zero.
7. Cancel a queued/running replay only deliberately; retry a rejected cancellation while the previous state remains visible. Confirm cancelled status without presenting partial results as completed.
8. Delete a replay only after its explicit confirmation; cancelling the confirmation preserves evidence. Successful deletion retains the backend audit tombstone.

**Expected result:** Readiness, lifecycle and disclosed simulated evidence remain isolated from the live source portfolio. Automated fixtures neither start production workers nor qualify point-in-time provider data.
