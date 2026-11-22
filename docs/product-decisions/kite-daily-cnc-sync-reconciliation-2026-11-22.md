# StoX — Kite Daily CNC Trade Sync and Reconciliation
Status: PRODUCT DECISIONS RECORDED — 2026-11-22. No implementation requested yet.

## 1. Daily trade capture
- Focus only on executed CNC (delivery-equity) trades. Ignore MIS, NRML, MTF, derivatives and other products for this feature.
- Retrieve the current trading day's Kite trade executions after market close, with a nominal end-of-day synchronization around 4:00 p.m. IST (configurable; actual availability must be checked).
- Convert new CNC executions into StoX buy/sell transactions, recording actual execution date/time, side, symbol/instrument, quantities, fill prices and broker trade/order identifiers.
- Automatic end-of-day synchronization is preferred; user may also trigger one-click sync. Notify/report the new transactions added and any exceptions; whether to require confirmation on every successful sync is not yet a fixed requirement.
- Deduplicate by broker execution IDs, support multiple fills and repeated sync attempts, and preserve provenance/audit records.
- Only import transactions not already represented in StoX; avoid double-importing manually entered transactions through appropriate matching/review.

## 2. Connection-time catch-up
- If the scheduled sync did not complete for the current trading day because Kite was disconnected, attempt that day's trade sync immediately when the user reconnects, even after market hours (e.g., 6 p.m.).
- Make the sync idempotent and track last successful trading-day sync per account.
- Kite's standard Trades API does not support arbitrary past trading days. If the trading day's trades are no longer available, flag the gap explicitly; do not silently claim success.

## 3. Holdings reconciliation
- Replace simplistic calendar-day exclusion with settlement-aware comparison.
- Reconcile StoX holdings against Kite holdings plus relevant CNC executions not yet reflected in broker holdings (including local persisted trades from earlier trading days pending settlement/reflection).
- Avoid double-counting: Kite holdings can include unsettled/T1 quantities and quantities sold, while Kite positions/trades may already reflect part of the same activity.
- Handle trading holidays/weekends using trading-day/settlement state rather than calendar date. Expose unresolved discrepancies for review.
- Existing 'exclude today's transactions' UI can remain until the new reconciliation behavior is implemented and validated; no removal decision made.

## 4. Cash and trading fees reconciliation
- Reconcile StoX cash alongside holdings after syncing trades.
- Reflect buy/sell cash movements and trade-related charges, including brokerage, statutory levies, taxes and exchange fees as applicable.
- Kite trade records do NOT provide complete per-trade fees. Do not invent exact charges: investigate available Zerodha funds/margins/charges sources and broker statements, calculate only clearly labeled estimates when necessary, and surface unresolved differences.
- Account for settlement timing, other cash ledger movements and the distinction between broker available funds and StoX cash ledger before asserting exact match.

## Scope / next steps
- These are product decisions, not a completed technical design. Exact data mapping, fee source, schedule reliability, reconciling preexisting manually recorded trades, and handling missed trading days require implementation design.
- Keep separate from frozen historical onboarding/staging decisions at docs/product-decisions/kite-holdings-onboarding-frozen-2026-11-22.md.
- User has another distinct third topic to discuss later; do not infer or record it yet.

## Addendum — 2026-11-22: Reconciliation UI and order-execution gating
- Once settlement-aware holdings reconciliation is implemented and validated, remove the existing include-today/exclude-today ComboButton choice. Provide a single Reconcile action that always accounts for today's CNC executions and unsettled prior executions without double-counting. Keep the current control until the replacement is proven.
- User reports StoX already has recently improved transaction-fee calculations. Reuse the existing charges engine when recording synchronized transactions; do not treat absence of exact charges in Kite trade records as a blocker. Verify accuracy during implementation rather than assuming exactness.
- Proposed order execution model: full holdings/cash reconciliation once at the beginning of each trading session, record successful session baseline, then avoid repeating full reconciliation before every order; perform end-of-day trade sync/reconciliation.
- Preserve per-order safeguards: current broker buying power, reserved funds/open orders, risk limits, connection health, idempotency, and confirmed execution updates. A session baseline must not bypass these safeguards or permit trading after material divergence/unknown state.
- IMPORTANT: This per-session gating model is a design recommendation pending inspection of existing StoX order placement and reconciliation code. Verify whether per-order reconciliation actually happens and whether the proposed change would weaken safety before implementing. Do not remove current safety gates blindly.
