# Kite settlement diagnostics (read-only, unwired)

`KiteSettlementDiagnosticReport` is a pure service for a single StoX profile, explicit Kite account ID, and trading day. It consumes already-loaded arrays and performs no database access, broker requests, persistence, reconciliation updates, notifications, or execution gating. It is not connected to routes or the reconciliation UI.

## Evidence boundaries

- `portfolio_portfolio_snapshots` is keyed by StoX user and date, not Kite account. It can establish only that a portfolio-level snapshot exists.
- `kite_cnc_trade_imports` and sync runs are keyed by StoX profile and date. Imports have broker trade IDs, but no persisted broker account ID. They remain profile-attributed and cannot be silently promoted to broker-account evidence.
- Funds snapshots include profile, explicit broker account, date, freshness and payout-semantics state. Foreign account rows are excluded. These aggregate funds values are not holdings or settlement proof, and payout semantics remain a reported uncertainty until verified.
- The repository has no durable daily Kite holdings/positions snapshot with independently verified provenance. A supplied in-memory capture candidate is sent through `KiteSettlementEvidencePreparation` and `KiteSettlementHoldingsEvidence` only when the caller also provides an explicit verified profile/account binding. A capture receipt validates internal lineage fields; it is not independent proof of brokerage settlement.
- Without independent broker statements or settlement confirmation, this report never states that StoX and Kite settlement matched.

The caller must scope database reads to the authenticated profile and active connection, provide the real broker account ID from a trusted connection source (never derive it from a profile ID), and pass normalized evidence rows with their true profile/date scope. For a holdings comparison, it must also pass `stoxHoldingsProfileId` from the trusted query scope; without it the comparison is omitted. Each StoX holding row must carry that exact profile ID. If a future import row has `broker_account_id`, it must match the requested Kite account; legacy rows without it remain unbound. The existing portfolio snapshot table uses `user_id`, so any profile normalization must come from a trusted profile ownership relation.

IDs are compared without permissive integer casts. Date-only fields must be exact valid `YYYY-MM-DD` values. Timestamp fields must be valid, bounded timestamps and are assigned to a trading day after conversion to `Asia/Kolkata`; malformed timestamps are excluded. Sync completeness uses the newest run by `started_at`, requires a valid completion timestamp and zero reviews, and fails closed when any run timestamp is malformed. A failed newer run therefore cannot inherit completion from an older run. Explicitly foreign-account import rows are excluded; legacy rows without account IDs are retained only as profile-level counts and flagged unbound. Inputs are capped at 5,000 rows per list. The returned object contains only sanitized classifications/counts; raw provider receipts and broker evidence payloads are not copied into diagnostics.

An empty holdings list means a verified query returned no holdings only when accompanied by the trusted `stoxHoldingsProfileId`. Omitting the list or its source profile ID means the true holdings source is unavailable and no comparison is generated. Profile-level snapshots and legacy trade imports remain explicitly profile-attributed; neither establishes account-level settlement evidence. The report is diagnostic only and should remain unwired until evidence storage and account-binding provenance are independently reviewed.

## Status vocabulary

The top-level result intentionally remains `diagnostic_only_review_required`. Its evidence counts describe candidates that match the profile/account/day filters; they are not completeness assertions. The nested holdings classifier can identify exact pending-settlement explanations from complete supplied evidence, but unresolved or pending rows do not become actual brokerage settlement matches.
