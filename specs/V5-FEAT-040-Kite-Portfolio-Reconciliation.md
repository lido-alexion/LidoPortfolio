# V5 FEAT-040 — Kite Portfolio Reconciliation

**Status:** DECIDED / PO COMPLETE

## Problem

Semi-Automatic and Automatic trading depends on StoX's logical portfolio state corresponding to the physical holdings and cash visible in the connected Kite account. Out-of-band broker activity, missing or incorrect StoX transactions, and cash-ledger differences can cause divergence. StoX needs a safe verification mechanism without allowing broker data to silently rewrite its transaction ledger, Strategy ownership, cost basis, fees, capital accounting, or history.

## Frozen behaviour

1. Reconciliation is diagnostic, not corrective. Kite data is evidence. FEAT-040 never creates adjustment transactions or silently modifies StoX state.
2. The Investor resolves discrepancies through normal StoX workflows (add/edit/delete holdings transactions or cash transactions) and reruns reconciliation.
3. Reconciliation is portfolio-level. Only Semi-Automatic/Automatic portfolios expose/run it.
4. At most one portfolio per Investor account may be in Semi-Automatic or Automatic mode at a time. All other portfolios must be Manual.
5. Activating a Semi/Automatic portfolio is immediate; reconciliation is not a precondition. Activation triggers an immediate reconciliation afterward.
6. On every TradingCalendar trading day, all portfolio-mode changes are prohibited from one hour before the Admin-configured market open until one hour after the Admin-configured market close. There is no V5 emergency override through mode changes.
7. Holdings and Funds have independent statuses. Overall status is Reconciled only when all applicable checks reconcile.
8. Holdings reconciliation compares durable Kite holdings with aggregate durable holdings of the active StoX portfolio by instrument. Kite intraday positions may be retained as diagnostic evidence but do not determine holdings reconciliation status.
9. FEAT-040 verifies broker-verifiable aggregate physical ownership, not internal Strategy allocation.
10. Compare the union of instruments on both sides; absence means quantity zero.
11. Only StoX-supported V5 NSE Capital Market equities affect reconciliation/blocking. Other Kite instruments are informational only.
12. Share quantity must match exactly. Holding monetary/cost differences may use a small absolute rupee tolerance where Kite exposes a semantically comparable cost value. If not comparable, cost is informational and quantity is authoritative.
13. Funds reconciliation compares Kite current cash balance with the portfolio's final StoX logical cash balance. A global Admin-configured absolute rupee tolerance applies.
14. Kite available cash/current cash is the intended cash comparison concept, not broader available margin/collateral buying power.
15. Kite Connect does not supply the historical Zerodha cash/funds statement required to explain a discrepancy. V5 provides no Console CSV/XLSX import. The Investor may manually consult Zerodha's Funds Statement and correct StoX cash transactions.
16. Global Admin settings define holding-cost tolerance and funds tolerance. No percentage, Strategy, or portfolio-specific tolerances in V5.
17. A confirmed holdings mismatch immediately blocks all new Semi/Automatic execution for the entire portfolio, including pending intents not yet submitted. Existing broker-submitted orders remain owned by Order Lifecycle.
18. Cash mismatch is Action required but does not block execution; FEAT-039's broker-funds safeguards remain authoritative for physical BUY affordability.
19. A holdings block clears only after a later successful Kite reconciliation confirms that all applicable holdings match. Editing StoX data alone never clears it.
20. Failed or stale reconciliation does not itself block execution and does not overwrite the last successful reconciliation result. Record sync failure separately.
21. Out-of-band Kite trades are allowed. A resulting holdings mismatch blocks execution until the Investor records/corrects the appropriate Strategy transactions in StoX and a fresh reconciliation matches.
22. Every confirmed discrepancy creates/maintains a deduplicated FEAT-004 Action-required condition. First detection follows the notification policy, continuing mismatch updates the same condition, and a later successful green reconciliation auto-resolves it. FEAT-003 owns persistent presentation where applicable.
23. Reconciliation triggers: manual; immediately after Semi/Automatic activation; scheduled once per eligible trading day after market close; and event-driven after StoX-managed broker activity with actual execution reaches terminal state. Zero-fill rejection does not require holdings reconciliation.
24. Scheduled timing is a global Admin-configured delay after the configured market close, not an independent clock time. No scheduled run on weekends/Trade Holidays.
25. Closely related post-trade triggers may be coalesced to avoid unnecessary broker calls.
26. One reconciliation may run concurrently per portfolio. Automated triggers may coalesce into a necessary follow-up. Manual invocation during an active run reports Reconciliation in progress. Older/slower results must never replace newer status.
27. Every run is immutable history: timestamp, trigger, Kite snapshot/evidence, StoX comparison snapshot, tolerances used, discrepancies, result, and failure details. Corrections produce a new run; historical runs are never recomputed.
28. Status is tied to a successful run timestamp. Green means Reconciled as of that snapshot, not permanently reconciled.

## Architecture

- Broker Integration performs read-only Kite holdings/positions/funds retrieval and preserves broker communication evidence.
- A portfolio reconciliation service compares normalized Kite evidence with a point-in-time StoX portfolio snapshot.
- StoX remains authoritative for transactions, Strategy ownership, cost basis, cash transactions and historical accounting.
- Reconciliation publishes status/discrepancy state consumed by Execution gating and FEAT-004 notifications.
- FEAT-039 checks the holdings-block state before every new broker submission.
- FEAT-038 TradingCalendar and Admin market timings determine scheduled eligibility and the mode-change blackout.

## Algorithms

### Holdings

1. Fetch durable Kite holdings successfully.
2. Snapshot aggregate StoX holdings for the active live portfolio.
3. Restrict blocking comparison to supported V5 NSE Capital Market equities.
4. Build the union of Kite and StoX instruments; missing side = zero.
5. Require exact quantity equality.
6. Where comparable cost data exists, compare monetary cost using configured absolute tolerance; otherwise retain it as informational evidence.
7. Any applicable holdings discrepancy sets Holdings = Mismatch and portfolio execution_blocked_by_reconciliation = true.
8. Only a later successful all-match run clears the block.

### Funds

1. Fetch Kite current/available cash.
2. Snapshot the portfolio's final StoX logical cash balance.
3. Compare absolute difference with global funds tolerance.
4. Outside tolerance => Funds = Mismatch; within tolerance => Funds = Reconciled.
5. Funds mismatch does not set the holdings execution block.

### Overall

Overall Reconciled iff every applicable category is Reconciled. Otherwise Attention required. A fetch/sync failure is Unknown/Sync failed evidence, not a fabricated mismatch and does not erase the prior successful result.

## UX

- Show Holdings, Funds, Overall status and last successful reconciliation timestamp.
- Green Reconciled state when applicable comparisons match.
- For mismatches show instrument/cash values from StoX and Kite and the difference; retain actual monetary differences even within tolerance.
- Tell Investor to correct normal StoX transactions/cash transactions and rerun; do not offer Apply Kite values.
- Cash mismatch guidance may direct the Investor to review Zerodha Console Funds Statement manually.
- Show unsupported/non-managed Kite instruments separately as informational broker holdings.
- Provide immutable run history and run detail sufficient to understand when divergence appeared, what differed, and when it resolved.
- Show sync failures separately from mismatch status.

## Acceptance criteria

- Reconciliation cannot mutate investment/cash history.
- Only one Semi/Automatic portfolio can exist per Investor account.
- Mode changes are rejected during the trading-day blackout window.
- Activation triggers reconciliation without introducing a pending-activation state.
- Holdings quantity mismatch blocks all new execution portfolio-wide immediately.
- Cash-only mismatch does not block execution.
- A block survives StoX edits until a successful fresh reconciliation matches.
- Manual, scheduled, activation and qualifying post-trade triggers work and are auditable.
- Failed/stale runs do not create false mismatches or clear confirmed blocks.
- Instrument union detects holdings existing on only one side.
- Unsupported instruments do not affect green/red status.
- Concurrent triggers cannot race status backward.
- Discrepancy notifications deduplicate and auto-resolve after verified recovery.
- Every run preserves immutable comparison evidence and outcome.

## Dependencies

- FEAT-037 Kite readiness/authentication.
- FEAT-038 TradingCalendar and Admin market timing.
- FEAT-039 execution gating/order lifecycle integration.
- FEAT-004 notification service.
- FEAT-003 persistent presentation where severity/policy requires it.
- Existing transaction, Strategy attribution and cash-transaction correction workflows.

## Non-goals

- Silent broker-to-StoX correction.
- Automatic Strategy attribution of broker discrepancies.
- Reconciliation adjustment transactions.
- Zerodha Console statement import/parsing.
- Validation of internal Strategy capital/loan/recall correctness from Kite.
- Intraday positions as durable holdings truth.
- Unsupported instrument reconciliation/blocking.
- Historical reconciliation analytics beyond run history/details.
- Emergency trading control through V5 portfolio-mode changes.

## V6 follow-ups identified during planning

Execution safety must become a first-class account-level Execution State separate from portfolio mode:

- Normal execution state.
- Emergency Halt entered by the Kite kill switch; blocks new execution immediately.
- Explicit recovery / "Get-a-life" control to leave Emergency Halt after required checks; no automatic recovery.
- Existing V6 Disconnect Kite and Emergency cancel-open-orders-then-disconnect controls transition into Emergency Halt.
- If any portfolio in the Investor account has Semi-Automatic or Automatic mode enabled, the kill switch must be persistently visible and quickly accessible on every Investor-app page and while viewing every portfolio, including Manual portfolios. When halted, the corresponding recovery control must likewise remain accessible.
