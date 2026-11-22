# Kite Funds Snapshots and Cash Reconciliation

## Scope and separation

StoX stores account-scoped Kite equity-margin snapshots in `portfolio_kite_funds_snapshots`. `available_balance`, `opening_balance`, `intraday_payin` and `utilised_payout` remain separate values with capture time, account, source, completeness and provenance. These snapshots do not write cash entries. The existing per-order broker funds check remains the source for available buying power; projected StoX ledger cash is not used as a substitute.

The connect callback captures immediately. A scheduled command captures every two hours, at 23:55 IST as a best-effort late-day attempt, and at 09:20 IST with a previous-session reconciliation. Captures are idempotent per account, profile, minute and source. Expired sessions and failed reads are skipped; the next-day review records missing or stale evidence rather than treating it as zero.

## Reconciliation rules

The morning check selects the latest snapshot from the preceding NSE session (weekends and configured holidays are skipped) and re-evaluates unresolved reconciliation dates from the past 30 days so later manual edits are reflected. Only complete `late_day` captures made from 23:30 through 23:59 IST qualify as final. Snapshot lookup is scoped to the connected broker account as well as profile and date. Snapshot net flow is pay-in minus payout; gross components remain separate in stored snapshot records. StoX manual deposit/withdrawal rows are netted by effective date, excluding reversed rows; withdrawals already carry a negative ledger amount.

- Equal manual and Kite net flows are marked reconciled.
- Any partial or differing manual flow is a warning. StoX does not post a difference.
- Missing, incomplete, stale, unverified-payout, and zero-net mixed activity remain review statuses.
- With the feature disabled, a reliable nonzero unmatched flow is marked `adjustment_disabled`.
- With both rollout flags enabled, a reliable nonzero unmatched flow automatically posts one signed adjustment in the same transaction as its terminal `adjustment_applied` reconciliation record. The entry is classified `kite_net_adjustment`, appears in the ordinary adjustment-filtered cash statement, and can be edited or reversed through existing cash management. The user can confirm this already-applied adjustment; confirmation changes only reconciliation status and evaluation time, never the ledger or balance. Repeated confirmation is idempotent. The reconciliation row retains its terminal applied/confirmed state and ledger link, so rechecks do not recreate it. The account row is locked and manual transfers are rechecked under that lock before posting.

For an automatic adjustment, confirmation is an approval action on the already-posted entry. The separate legacy proposal path labels its action “Post proposed adjustment” and remains rollout-gated; confirming that proposal posts the single ledger entry. Dismissal applies only to an unposted proposal. To replace an automatically posted adjustment, edit or reverse that flagged ledger entry, then enter the manual transfers. The terminal reconciliation state prevents it from appearing again on a recheck. The statement includes a dedicated classification filter for `kite_net_adjustment` entries; edits retain that classification on the replacement row.

Payouts are not asserted to be bank transfers. StoX must not set `KITE_FUNDS_PAYOUT_SEMANTICS_VERIFIED=true` until actual Zerodha Console examples establish whether `utilised.payout` is consistently populated and what timing/date it represents. The config flag records operator validation; it is not set by code or inferred from a successful API response.

## Rollout gate

Defaults are both `false`:

```dotenv
KITE_FUNDS_AUTO_ADJUSTMENTS_ENABLED=false
KITE_FUNDS_PAYOUT_SEMANTICS_VERIFIED=false
```

Before changing either value, retain Console evidence for multiple same-day deposits, withdrawals, mixed flows, payout-only days, partial manual entries, missing/expired late sessions, weekend/holiday rechecks and corrected/deleted StoX movements. Verify gross totals against Console across repeated captures and confirm that payout timing matches the Kite trading date. Exercise zero-net mixed transfers, manual-match, mismatch-warning, proposal/confirmation, duplicate confirmation, dismissal tombstones and reversal/recheck paths in a non-production test account. If payout cannot be validated, keep the feature disabled; manual cash management remains authoritative. These local tests do not establish Console semantics, production idempotency under load, or permission to enable either production flag.

No live broker operation, database/config deployment, automatic migration, scheduler change on production, or production adjustment is performed by this code change.
