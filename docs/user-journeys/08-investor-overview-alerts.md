# Investor overview, relative strength and advisory alerts

These journeys describe existing investor behavior discovered during the V9-UX-001 inventory review. Reading analytics or evaluating an advisory alert does not approve or submit a trade.

## E2E-14 — Refresh portfolio overview and recover an unavailable dashboard

- Requires: an authenticated investor with an active portfolio.
- Search synonyms: portfolio overview, refresh dashboard, dashboard unavailable.

1. Open the Dashboard at `/` and inspect Portfolio Value, Invested Value and Total Gain/Loss for the active portfolio.
2. Use Refresh dashboard to clear this portfolio's local cache and request current server data.
3. If refresh fails after data was displayed, retain the prior values and show the failure. Use Refresh dashboard again after the service recovers.
4. Confirm the successful response replaces the displayed values. Reloading or returning to the page may use the labelled local cache; explicitly refresh when current server values are needed.
5. If the initial load fails before any data exists, reload the page after the service recovers.

**Expected result:** cached or refreshed portfolio information remains scoped to the active investor and portfolio. Reading/refreshing the overview does not run price synchronization, reconciliation, execution, or order submission.

## E2E-15 — Compare a stock with its benchmark and recover missing price data

- Requires: an authenticated investor and a symbol in the local stock master.
- Search synonyms: relative strength, stock explorer, manual relative strength.

1. Open Stock Explorer at `/explorer`, select a stock and benchmark index, and calculate relative strength. A stock/benchmark deep link restores the same analysis context.
2. Inspect relative strength for the available 1, 3, 6 and 12 month periods. Analytics use existing universe price history and do not request a new provider price fetch.
3. If history is unavailable, use Manual Relative Strength Input with positive stock/index latest and six-month previous close values. The calculate action stays unavailable until all four inputs are positive.
4. Calculate the temporary six-month comparison and inspect the resulting difference between stock and benchmark growth. Manual inputs do not persist authoritative market data.
5. Retry Calculate relative strength after data is restored. Successful fetched results replace the temporary comparison.

**Expected result:** symbol/benchmark context survives navigation through its URL. Missing values are recoverable through a clearly temporary comparison; neither fetched nor manual analysis creates a transaction or order.

## E2E-16 — Maintain and evaluate an advisory alert policy

- Requires: an authenticated investor with an active portfolio.
- Search synonyms: alert policy, holdings alert, evaluate alerts.

1. Open Alert policies at `/settings/alert-policies` and choose New policy.
2. Name the policy, describe its intent, define its holding-value comparison, message and suggested action, then create it. Correct validation errors or retry a transient failure without losing the form.
3. Inspect the saved policy, edit its configuration, or disable it with Policy enabled before updating. Reload and confirm the saved enabled state.
4. Choose Run policies now to evaluate enabled policies against holdings. If evaluation fails, retry after recovery; inspect Last evaluation and its per-holding outcome instead of assuming an alert was generated.
5. For a non-system policy, choose Delete. Cancelling the confirmation preserves the policy; confirming removes it. System policies do not expose Delete.

**Expected result:** saved policies and evaluation outcomes are visible, and failed save/evaluation operations remain recoverable. A suggested action is advisory; evaluating or deleting a policy does not authorize trading.

## E2E-17 — Save optional product-email preferences and recover a failed update

- Requires: an authenticated investor using `/settings/notifications`.
- Search synonyms: optional emails, email digest, quiet hours.

1. Inspect Optional product emails, which are off by default while account/security messages remain enabled.
2. Enable optional emails, choose categories and Immediate or Daily digest delivery, then set quiet hours, digest time and timezone.
3. Save email preferences. A failed update retains the form; correct invalid inputs or retry after recovery.
4. Reload the page and confirm the saved preferences. Disabling optional emails leaves account/security messaging enabled.

**Expected result:** account-level preferences persist without changing portfolio ownership or trading permissions. A successful save is not proof of queue processing or inbox receipt; those require separate delivery evidence.
