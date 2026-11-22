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
- With both rollout flags enabled, a reliable nonzero unmatched flow becomes a proposal. An authorized user must confirm it before StoX posts a single signed adjustment, or dismiss it; dismissal is a tombstone. A confirmed entry is classified `kite_net_adjustment`, appears in the ordinary adjustment-filtered cash statement, and can be edited or reversed through existing cash management. The reconciliation row retains its terminal confirmed/dismissed state and ledger link, so rechecks do not recreate it.

To replace an unconfirmed proposal with manual transfers, dismiss it and use the existing deposit/withdrawal form; a manual entry made while a proposal is open also records an override tombstone. To replace a confirmed adjustment, reverse or edit that flagged ledger entry first, then enter the manual transfers. The terminal reconciliation state prevents it from appearing again on a recheck. Confirm revalidates the linked snapshot's account, date, completeness, late-day capture window and stored payout-verification state under lock. Confirm and dismiss serialize on the reconciliation row; posting also serializes on the cash account used by ordinary cash management. The statement includes a dedicated classification filter for `kite_net_adjustment` entries.

Payouts are not asserted to be bank transfers. StoX must not set `KITE_FUNDS_PAYOUT_SEMANTICS_VERIFIED=true` until actual Zerodha Console examples establish whether `utilised.payout` is consistently populated and what timing/date it represents. The config flag records operator validation; it is not set by code or inferred from a successful API response.

## Rollout gate

Defaults are both `false`:

```dotenv
KITE_FUNDS_AUTO_ADJUSTMENTS_ENABLED=false
KITE_FUNDS_PAYOUT_SEMANTICS_VERIFIED=false
```

Before changing either value, retain Console evidence for multiple same-day deposits, withdrawals, mixed flows, payout-only days, partial manual entries, missing/expired late sessions, weekend/holiday rechecks and corrected/deleted StoX movements. Verify gross totals against Console across repeated captures and confirm that payout timing matches the Kite trading date. Exercise zero-net mixed transfers, manual-match, mismatch-warning, proposal/confirmation, duplicate confirmation, dismissal tombstones and reversal/recheck paths in a non-production test account. If payout cannot be validated, keep the feature disabled; manual cash management remains authoritative. These local tests do not establish Console semantics, production idempotency under load, or permission to enable either production flag.

No live broker operation, database/config deployment, automatic migration, scheduler change on production, or production adjustment is performed by this code change.
