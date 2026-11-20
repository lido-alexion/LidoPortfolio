# Zerodha reconciliation and Cash statement pagination

## Prepared code scope

- Cash statement clients can opt into newest-first order with `order=desc`; the existing API default remains oldest-first.
- Cash Management uses server pagination, provides 25/50/100 page sizes, total and page counts, and previous/next controls. Running balances remain chronological.
- Reconciliation boundaries, fee evidence limits, cash opening provenance and the no-double-counting rule are documented in [the current reconciliation guide](../current/zerodha-reconciliation.md).

## Deferred financial data work

This code change does not claim exact trade-level reconstruction from daily aggregate contract-note lines. It does not recalculate existing fees or edit the current cash ledger. A source-linked, profile-checked dry-run proposal and review of the existing opening/residual entries remain required before any data correction. Full historical fee-rate support requires dated, source-verified rate schedules and must not use today's configured rates for old trades.
