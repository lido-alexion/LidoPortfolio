# V5 FEAT-013 — Cash-as-of, Export, and Compare Polish

**Status:** DECIDED / FROZEN  
**Date:** 2026-09-07

## Problem
StoX needs trustworthy historical cash state, historical total Portfolio value, robust exports and a simple Date-A-vs-Date-B comparison without inventing performance attribution or weakening ledger truth.

## Frozen behaviour

### Cash ledger and current cash
- Portfolio cash is authoritative through an append-only cash ledger.
- Core movement types include Deposit, Withdrawal, Adjustment, BUY cash effect and SELL cash effect.
- Trade cash postings must remain atomic with the corresponding transaction workflow.
- Cached/current cash balance is rebuildable materialization, not the source of truth.
- Recommendation reservations are soft/current-state reservations only; historical cash does not reconstruct historical reservations.
- `available cash = posted cash balance - current valid reservations` where applicable.
- Cash is Portfolio-level only; V5 does not invent Strategy cash accounts.

### Historical cash and value
- Historical cash as-of D is reconstructed from posted ledger movements effective through D.
- Backdated cash movements are allowed and intentionally change historical truth from their effective date onward; creation timestamp remains audit evidence.
- Deposit/Withdrawal/Adjustment corrections use reversal/new corrective entries rather than mutation of old financial records.
- Adjustment requires a mandatory immutable reason and is shown separately from external capital flows.
- Legacy histories lacking trustworthy opening cash use an explicit Opening Balance/Unknown boundary rather than silently assuming zero.
- Zero before first activity is allowed only where StoX has trustworthy zero-origin evidence.
- Historical total Portfolio value = historical holdings market value + historical cash.
- Historical price resolver uses the existing historical-valuation rule: latest authoritative `adjusted_close`, falling back to `close`, on or before D; never live Kite price.
- Any calendar date may be selected. State is through D; price may be the latest valid prior market date and must expose price-as-of. The UI must not silently shift the requested date.
- Future dates are prohibited.
- Component completeness is explicit: holdings state, holdings valuation, cash and total value. Unknown is `null`/incomplete, never silently zero.

### Cash Statement
Provide a complete Portfolio Cash Statement with:
- opening and closing balance;
- chronological movements ordered by effective date with created timestamp retained for audit;
- running balance;
- movement type, amount, reference, reason and audit metadata;
- pagination/filtering;
- clear separation of external flows vs Adjustments vs trade/internal effects.

### Export
V5 supports CSV export of independent logical datasets, including:
- current holdings;
- historical holdings;
- transactions;
- Cash Statement;
- Portfolio value history;
- Recommendations;
- Orders/Trades where available.

Tax-specific exports belong to FEAT-015.

Export rules:
- CSV is self-describing and schema-versioned.
- Include Portfolio/date/currency/completeness context, stable fields and raw numeric values.
- Historical datasets expose relevant price-as-of/evidence fields.
- One export uses one logical request-time cutoff so internally related datasets do not mix changing snapshots.
- Export may execute synchronously or asynchronously based on size without changing semantics.
- Generated files are temporary/private with authorization rechecked on access; no public bearer links or secrets.
- Durable audit metadata about export action may remain, but V5 does not create a permanent export-file library.

### Date comparison
- Compare is within the same Portfolio only; no cross-Portfolio comparison in V5.
- User chooses Date A and Date B with `A < B`.
- Default is approximately one month ago → today, with shortcuts `1M / 3M / 6M / YTD / 1Y / Since inception`.
- Compare is on-demand and not persisted as financial truth.
- Primary comparison uses physical holdings/cash. Strategy ownership is secondary where historically reconstructable; no backward projection of today's Strategy ownership.
- Holdings diff uses union of instruments at A/B and classifies `Entered / Exited / Increased / Reduced / Unchanged` by quantity.
- Entered/Exited rows remain complete rows, not just labels.
- Known zero is `0`; unknown is `null` with completeness disclosure.
- Show endpoint composition/value/cash and simple deltas.
- External cash-flow context over `(A,B]` shows Deposits minus Withdrawals; Adjustments are separate. Trades/internal capital movement are not external flows.
- Do not label raw wealth change as investment return.
- No causal attribution from holdings differences in FEAT-013. Canonical transactions in `(A,B]` and Cash Statement interval are drill-down evidence only.
- Compare may expose current-truth results changing after historical corrections/backfills; no belief-time snapshot is invented.
- Compare state may be authenticated/deep-linkable but not publicly shared.

## Architecture
- Ledger is source of truth; cached balances/daily value series are rebuildable.
- Existing historical holdings reconstruction remains separate from daily Portfolio snapshot/materialized value series.
- FEAT-015 may consume the aligned daily holdings+cash+completeness value series but does not become source of truth for cash.
- Historical corporate-action effects follow existing ledger/current authoritative history rather than duplicating a second restatement model here.

## UX
- Cash Statement under normal Portfolio financial surfaces.
- Compare presents date controls, endpoint summary, composition/diff, external-flow context, completeness and CSV export without performance/benchmark/attribution charts.
- Incomplete values are visibly incomplete; UI does not substitute zeros.

## Acceptance criteria
1. Current cash can be rebuilt exactly from the cash ledger.
2. Historical cash as-of arbitrary valid date is derived from effective-dated ledger entries and changes correctly after legitimate backdated corrections.
3. Historical total value propagates incomplete holdings/cash/price components rather than fabricating totals.
4. Cash Statement provides opening/closing/running balances and traceable references/reasons.
5. Deposits/Withdrawals/Adjustments are immutable financial records corrected through reversal/new entries.
6. CSV exports are authorized, schema-versioned, consistent to one logical cutoff and preserve null-vs-zero completeness.
7. Compare enforces same Portfolio and `A < B`, shows union holdings classifications and external-flow context without calling raw wealth delta a return.
8. Historical pricing uses authoritative daily historical data, never live Kite quotes.
9. Existing Portfolio/transaction/cash authorization and audit boundaries are preserved.

## Dependencies
- Existing transaction/WAVG accounting and cash infrastructure.
- Existing F014 Historical Holdings and F015 Portfolio snapshot/value foundations.
- FEAT-038 calendar/historical market-date semantics where applicable.
- FEAT-015 consumes historical value/cash outputs for performance, not vice versa.

## Non-goals
- Cross-Portfolio Compare.
- Performance/benchmark/attribution analytics (FEAT-015).
- Strategy cash accounts.
- Historical reservation reconstruction.
- Public export/share links.
- Permanent export library, scheduled exports, email/cloud backup.
- Causal trade attribution from Date-A-vs-Date-B differences.
