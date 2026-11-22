# Kite session evidence shadow

## Scope

The first stage stores account-scoped evidence for each live StoX profile and IST session date. Kite `user_id` from the authenticated connection is retained as the broker account identity; access tokens, API keys, checksums and provider error bodies are never persisted in this record. Evidence can be refreshed with `POST /api/v1/broker/session-evidence/refresh` and read through the existing `GET /api/v1/broker/status` response's `session_evidence` field. Both paths are authenticated and refresh only issues read requests.

The record keeps StoX ledger cash separate from Kite equity available buying power. The buying-power value is informational and is never treated as accounting cash. Local StoX open/partial/unknown orders are shown as such, independently from Kite holdings and positions. A numeric match does not establish settlement or accounting equivalence.

## Shadow status and current limits

The current gateway retrieves holdings, positions and equity margins. It does not fetch Kite's account-wide order or trade book. Consequently these sources remain unknown/incomplete and carry explicit gap reasons. Settlement comparability is unproven, including for mixed same-day/prior-day settlement. Captures with missing or older-than-five-minute broker data are unknown; otherwise the session remains incomplete. No status is promoted to matched in this stage. The records do not affect existing portfolio reconciliation flags or order execution.

The snapshot currently available from the gateway uses one capture time for holdings, positions and buying power. Source timestamps identify that shared time, not independent provider response timestamps. Local ledger and open-order timestamps are captured separately. A profile without initialized cash reports the ledger source as unknown.

## Safe next activation gate

A future block-only session gate must remain behind `KITE_SESSION_EVIDENCE_GATE_ENABLED=false` by default. It may block new submissions only after all of the following are demonstrated: account identity cannot cross; every required source has fresh independent timestamps; Kite order/trade data is captured and reconciled idempotently; open/partial fills and unsettled CNC activity are represented without double counting; settlement-comparable local cash and holdings are established; stale, missing and ambiguous evidence fails closed; disconnect/reconnect and account-switch behavior is verified; and the existing per-buy funds refresh, quantity floor, reservations, risk controls, emergency stop and confirmed order reconciliation remain enforced. Enabling must be a separate reviewed release decision. Existing live portfolios are not blocked by this shadow stage.
