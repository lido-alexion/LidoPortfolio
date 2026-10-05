# NIFTY 500 automatic exchange fallback and per-stock manual fetch

Date: 2026-10-05
Status: Product decision; implementation staged behind existing exchange access gates

- Yahoo remains the first source.
- Automatic exchange fallback is limited to NSE/NSE+ stocks in a fresh, already-cached NIFTY 500 constituent list. A missing or stale list disables automatic NSE fallback; membership checks do not trigger network refreshes.
- Automatic BSE fallback is not run for the wider universe. An authenticated user may request one active stock at a time from its matching configured exchange feed when no fundamental facts exist.
- Manual requests are limited to three per user per hour, serialized per stock, and rejected if the stock already has facts or a request is in progress.
- Shared exchange requests are serialized, spaced by at least five seconds, capped at 200 per day, and paused for at least six hours after HTTP 403/429 (or longer when Retry-After says so). NSE filing documents per symbol/cadence are capped at four.
- Direct NSE website access remains disabled unless the operator explicitly configures both the direct-access feature flag and access authorization. Approved normalized feed routes remain separately configured and admin-enabled. Pacing is a load safeguard, not a substitute for exchange authorization.
- No production feed was enabled and no exchange request was made as part of implementation.

## Interim exchange access approval gate

Status: **PROPOSED — awaiting PO confirmation**

The proposed interim arrangement is IP allowlisting by NSE/BSE while the full production license and price negotiation are in progress. It is not approved or active yet. The PO will confirm after receiving the exchange approval letter; that confirmation is the operational gate to enable the relevant exchange access. An IP allowlist or successful network request alone is not treated as evidence of PO authorization.

Until that confirmation:

- Keep NSE/BSE exchange access and production feed settings disabled.
- Do not send any exchange requests.
- Keep the implementation ready for the interim arrangement: Yahoo first; automatic NSE fallback limited to NIFTY 500; non-NIFTY stocks fetched only by an explicit single-stock manual request; retain the configured pacing and daily/document caps.

After PO confirmation, first verify connectivity and data handling through the approved route, without exceeding the agreed limits. That technical test confirms reachability and parsing only; the approval letter and PO confirmation establish authorization. This interim setup remains in place only until the full license is obtained and configured.
