# Kite settlement evidence preparation adapter

This isolated pure mapper prepares synthetic or already retrieved data for the shadow comparator. It performs no provider calls, database writes, portfolio quantity changes, reconciliation decisions, or execution gating. It is internal diagnostic code and does not prove anything about a live Kite account.

## Required provenance

The first `prepare` argument is the numeric StoX profile ID. The second is the exact string Kite `broker_user_id`; these identities are never interchangeable. A snapshot needs `provider: kite`, that exact `broker_user_id`, a capture instant, and a structured `source_proof` receipt containing Kite provider, the same broker user ID and capture instant, a bounded capture ID, and successful requested `holdings` and `positions` source results. The receipt is caller-supplied and shape-checked, not cryptographic proof or a live authentication step. The adapter emits no signed account identity or broker-validation assertion. Its completeness flags mean only that the supplied receipt and row shapes passed local checks; they do not establish that Kite returned the data or authenticated the account. Missing, wrong-provider, mismatched, incomplete, or out-of-scope source data cannot establish completeness.

Snapshots require bounded list-shaped holdings and both net and day position buckets. Each identity and quantity is validated. Holdings require finite positive quantities and finite nonnegative T1/used quantities. Position quantities must be finite and bounded. Symbols, exchanges and supplied ISINs are pattern checked. Cash is always non-comparable.

Each persisted import must carry the current numeric `profile_id` and exact string `broker_user_id`. The current durable CNC import schema does not store the latter, so legacy rows without separately supplied verified provenance are deliberately excluded and make trade evidence incomplete. Matching profile ownership alone does not establish which Kite account created a fill. Duplicate broker trade IDs make the batch incomplete; conflicting or repeated observations are not silently accepted as additional evidence.

## Historical coverage and dates

Trade completeness requires a separate coverage record with source `persisted_historical_imports`, an explicit `from` and `through` range, `complete: true`, and `active_account_broker_user_id` equal to the current account. A completed current-day Kite Trades request is not historical coverage and must not be supplied as such. Unknown gaps, unavailable active account identity, malformed records, and account switches leave trade evidence incomplete.

UTC or offset timestamps are parsed as instants and converted to Asia/Kolkata before assigning a session date. Settlement dates use `TradingCalendar::addSessions`; only dates recognized as sessions by that calendar are emitted. An execution on an unmodeled Saturday or other non-session date remains unresolved. Exceptional Saturday sessions require authoritative calendar support; this mapper does not invent them or settlement dates.

The row limit is 5,000. Invalid or oversized input fails closed. Positions stay in separate buckets and are never added to holdings. The adapter's output remains internal to the shadow comparator. No live Kite payload, broker settlement behavior, or account evidence is asserted.
