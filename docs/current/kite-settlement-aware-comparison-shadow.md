# Kite settlement-aware holdings comparison: shadow slice

Status: isolated pure comparison implementation; not wired into user-visible reconciliation or order execution.

## Decision and boundary

`KiteSettlementHoldingsEvidence` is a non-mutating evidence classifier. It never changes broker quantities, StoX transactions or reconciliation status, and it cannot be used as an execution gate. It reports exact pending-settlement explanations separately from unresolved gaps. The existing include/exclude-today ComboButton and reconciliation implementation remain unchanged.

The comparator accepts one profile/account ID, an as-of date, StoX holdings, a Kite snapshot, locally persisted trade evidence, and an explicit trade completeness flag. The broker snapshot must include the `holdings` array (including an explicit empty array for a genuinely empty account), strict `holdings_complete: true`, `account_id` or `profile_id` matching the requested account, and a valid `captured_at` timestamp on the as-of date. It must also provide `positions.complete: true` with explicit `net` and `day` arrays, including empty arrays when no positions exist. Missing or mismatched provenance/completeness keeps the result in review. The caller must query imports for that profile only and map each fill's settlement date using `TradingCalendar::addSessions`; this class does not infer exchange sessions from calendar days. This makes weekends, declared holidays and special Saturday sessions testable through the adapter's session calendar. A date such as `settles_by` is evidence supplied by the adapter, not an assertion made by the comparator.

## Comparison rules

- StoX lots for the same ISIN (or exchange and symbol when ISIN is absent) are summed.
- Kite comparison quantity is `quantity + t1_quantity - used_quantity`. Raw quantity, T1 and used quantities remain visible in output.
- Exact repeated broker holding rows are one observation. Conflicting rows for an instrument are unresolved; rows are never added as though they were independent inventory.
- Persisted CNC fills are isolated by profile ID and broker trade ID. Identical duplicate IDs are counted once; differing content for one ID is a conflict. Only fills executed by the as-of date and not past their supplied settlement date are active.
- Fill quantity can classify a gap as `pending_settlement_exact_evidence` only when active fills are all on one side, their net exactly equals the gap, the positions snapshot is explicitly complete with no nonzero overlap, and the caller marks trade evidence complete. Opposing buys and sells are left unresolved because a matching net can hide different reflection of each side. The comparator does not apply a trade delta to a quantity.
- A pending settlement keeps the overall shadow result at `review_required`; only fully matched rows with complete broker holdings evidence return `evidence_consistent`.
- Partial gaps, incomplete broker holdings or positions, unknown evidence, conflicting broker rows or trade IDs, malformed, negative or non-finite quantity rows, invalid StoX lots, mixed-side CNC activity, and nonzero overlapping net/day position evidence remain unresolved. Missing trade account scope is unknown evidence, never a global trade. Positions are never added to holdings because their relationship to holdings and trades can overlap.
- Cash is always reported separately. Unless the snapshot explicitly marks cash comparable, the result says `unknown_non_comparable_broker_funds`; settlement matching does not assert that sale proceeds or available buying power match StoX accounting cash.

## Fixture coverage

Unit fixtures cover same-day split fills, repeated holding appearance, prior-day fills spanning a weekend/reconnect, an explicit special-Saturday/holiday settlement boundary, quantity gaps larger than fill evidence, unknown trade completeness, offsetting buy/sell activity, overlapping positions, identical and conflicting duplicate IDs, expired and non-CNC trades, malformed/incomplete snapshots, T1/used quantities, and account isolation. They prove classifier behavior for supplied evidence. They do not prove Kite's live payload semantics or settlement-date calculation; those remain adapter and provider-validation work.

## Safe integration sequence

1. Add a read-only adapter that loads active StoX holdings, the exact connected profile's persisted `imported`/matched CNC fills and the broker holdings/positions snapshot. Preserve status/review exclusions and completeness/availability metadata instead of silently treating absent data as zero.
2. Compute settlement dates per execution using the authoritative `TradingCalendar`; test weekends, configured holidays, and special sessions. Confirm whether Kite T1/used quantities and positions are already reflected for real examples.
3. Persist or attach shadow evidence to reconciliation runs without changing comparison holdings, status, notifications, blocking, or UI. Compare shadow outcomes against existing discrepancies over representative live-account snapshots, with owner review of unresolved categories.
4. Only after measured proof, design a separate production integration change and tests for row-level reflected/partially reflected fills. Keep same-day selector and all execution safeguards until that change passes release verification and explicit acceptance. Never use this comparison to authorize orders.

## Open proof gaps

- Broker `quantity`, `t1_quantity`, and `used_quantity` semantics need confirmation from Kite payloads and Console examples, especially when same-day CNC buy/sell trades net against each other.
- Positions may duplicate, net, or otherwise overlap holdings and trades. This slice conservatively marks overlap unresolved; it does not infer a reflected quantity from positions.
- The persisted import table currently records sync/import outcomes, not an authoritative per-fill settlement/reflection state. Manual or legacy transactions without a matched broker fill cannot be used as settlement evidence.
- `TradingCalendar` models configured weekdays and holidays; exceptional sessions must be represented correctly in that source before its output can be considered authoritative.
- Cash proceeds and charges are deliberately outside holdings comparison. Funds reconciliation and existing fee calculation require separate evidence and must not be inferred from fill quantities.
- No production account/provider evidence was read or modified in this work.
