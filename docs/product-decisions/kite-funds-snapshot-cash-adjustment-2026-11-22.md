# StoX — Kite Funds Snapshots and Net Cash Adjustment
Status: AGREED PRODUCT REQUIREMENTS — 2026-11-22. Implementation present; production rollout remains gated.

Implementation note (2026-11-22): backend snapshots, next-session reconciliation and cash-management controls are implemented. A reliable unmatched flow posts one editable adjustment automatically only when both feature flags are enabled; both remain disabled by default pending Console verification. The user can subsequently confirm the already-posted adjustment; that confirmation records approval only and never posts another ledger entry or changes cash. See [Kite Funds Snapshots and Cash Reconciliation](../current/kite-funds-snapshot-reconciliation.md) for schedule, status rules and Console rollout criteria. The payout-field verification requirement remains open and is not claimed as satisfied.

## Objective
Maintain an accurate StoX cash balance for trading decisions, not a complete bank-transfer history. CNC buy/sell cash movements and charges are already handled by StoX transaction recording and its existing fee calculator. This feature concerns bank-to-broker pay-ins and broker-to-bank payouts.

## Snapshot capture
- On Kite connection/reconnection, capture funds data.
- Refresh every 1–2 hours while connected, and best-effort late-day snapshot (e.g. around 23:55 IST, if a valid session is available). Do not assume session remains valid.
- Persist snapshots by broker account, trading date and capture timestamp, with separate pay-in, payout, opening and available balances and relevant metadata; retain latest and provenance.
- Do not create cash ledger entries immediately from intraday snapshots.
- Kite figures are aggregate and may be provisional; exact payout-field semantics/reliability must be validated against Zerodha Console before automatic adjustment rollout.

## Next-day comparison
- On the following day, compare prior trading day's best reliable snapshot with StoX manual deposit and withdrawal records for that broker account/date.
- Compare signed net flow (pay-in minus payout), but preserve the two gross values separately in snapshot data.
- If manually recorded net matches: mark reconciled; no cash entry created.
- If some manual entries exist but net differs: notify/flag mismatch with source values and freshness; do not silently overwrite or create an automatic difference entry.
- If NO relevant manual entries exist: when snapshot is sufficiently reliable and movement nonzero, create a SINGLE editable, clearly identified net cash adjustment (deposit if positive, withdrawal if negative) for that date. Do not create from stale/uncertain snapshots; flag for review instead.
- If Kite data missing/unreliable: flag uncertainty, do not assert reconciliation success or invent bank movements.
- Never double-count previously auto-created entries; rechecks should be idempotent per account/date and update reconciliation status rather than insert duplicates.

## User controls
- Automatically created adjustments are visibly flagged, filterable and auditable, with source/date/status.
- User can confirm, edit, delete, or replace with one or more manual deposits/withdrawals.
- Manual cash-management functionality remains available as fallback.
- Maintain audit history and prevent an auto-adjustment from reappearing after the user replaces/deletes it without explicit reconciliation logic.

## Trading and accounting safety
- A net adjustment is a convenience accounting entry, not evidence of individual bank transfers.
- Broker buying power must be checked before every buy; estimated StoX ledger values never override broker funds.
- Differentiate accounting cash, available margin and collateral; other broker fees/adjustments may also cause discrepancies.
- Reconcile with existing StoX cash-management and daily CNC-sync design; do not implement overlapping transaction creation.
- Keep existing live trading gates and safeguards intact.

## Acceptance / validation before implementation
- Verify Kite intraday_payin and utilised.payout semantics using actual Console examples, especially whether payouts are consistently populated.
- Test multiple same-day transfers, mixed in/out, partial manual entries, missing late snapshot, weekends/holidays, reconnection, repeat checks, edited/deleted automatic entries and zero-net movement.
- Do not activate automatic adjustments until data reliability and idempotency are demonstrated.

Related decision: docs/product-decisions/kite-daily-cnc-sync-reconciliation-2026-11-22.md
