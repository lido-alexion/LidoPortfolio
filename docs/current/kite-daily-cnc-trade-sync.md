# Kite daily CNC trade sync and reconciliation

StoX imports executed NSE/BSE equity trades with Kite product `CNC`. Other products and derivatives are excluded. Each fill is written through `TransactionWriteService`, reusing the existing fees, holdings, realization, and cash ledger behavior. Import records are unique by profile and broker trade ID, and retain broker order/trade IDs, execution data, and broker evidence.

The scheduled command runs after market close plus the configured reconciliation delay (30 minutes by default). A successful Kite reconnect attempts current-day catch-up, and users can trigger a manual sync from the reconciliation card. Kite's Trades API exposes the current day's trades only, so missing sessions are recorded as unavailable gaps when there is a prior successful sync to establish the interval. Repeated runs are idempotent.

Existing transactions are matched by stock, side, date, quantity, and execution price. A unique manual match links to the existing transaction. Ambiguous matches remain in review without another ledger write. Unmatched trades use the common transaction write path; an account linked to multiple eligible live portfolios is paused for ownership review to avoid duplicating one broker execution across portfolios.

Reconciliation uses persisted CNC fill evidence only for a settlement adjustment when its complete net quantity exactly explains the difference between StoX and the normalized Kite holdings quantity (`quantity + t1_quantity - used_quantity`). Partial or ambiguous reflection remains a discrepancy. The existing include/exclude-today selector remains available while this behavior is validated. No funds or order-execution baseline policy is changed by this slice.

Kite's available equity buying power is retained as broker evidence but is not treated as comparable accounting cash. Such a snapshot reports funds as `unknown`, even when the numeric amount happens to equal StoX cash. The existing per-order safeguards and reconciliation blocking behavior remain unchanged. This feature does not create automatic bank adjustments.
