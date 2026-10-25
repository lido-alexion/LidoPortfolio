# Daily universe exchange-file collection

Product Owner source-policy amendment, 25 October 2026, tracked in [defect #280](https://github.com/lido-alexion/LidoPortfolio/issues/280).

Daily universe OHLCV collection uses the official NSE CM UDiFF final ZIP and the existing BSE bhavcopy downloader. The full canonical active NSE + BSE-only equity scope is inspected on every daily run; the historical stock batch-size setting does not restrict it. Each exchange/session file is downloaded once and matched across that exchange's eligible stocks.

The recent recovery window remains bounded by the daily lookback configuration, with a hard limit of 31 calendar days. Weekends and active global trading holidays in the StoX calendar never generate file requests or price rows. A weekend maintenance retry can recover earlier valid sessions. This amendment changes the daily universe source policy; frozen archived specifications remain unchanged.

## Validation and persistence

- NSE archives must contain one correctly dated CSV within bounded archive and CSV sizes. Every row must have the requested trade date, exchange, and CM segment. Only stock rows from the final session are eligible.
- Schema, finite positive OHLC values, consistent high/low bounds, nonnegative integer volume, and stock identities are validated. NSE matching uses symbol/series or an unambiguous ISIN/series; BSE uses scrip code or an unambiguous ISIN. Conflicting identities are rejected. Absence from a file is not evidence of delisting or a fabricated no-trade bar.
- Daily writes insert missing stock/session rows atomically. Existing cached prices, corporate-action adjustments, and manual repairs are preserved.
- A compact receipt records validated file application and actual coverage. Stable membership and unchanged coverage reuse it; membership/identity/ignored-gap changes, removed price rows, or an explicit daily reset request trigger a fresh check. Receipts are pruned to the bounded recovery window. Raw files and price arrays are not stored in settings.
- Coverage classification continues through the existing missing-history owner, including its listing-prefix, gap-tolerance, and ignored-gap rules. Scope completion is distinct from complete price coverage. File failures, identity conflicts, and required unavailable history remain visible in sync logs and last-run diagnostics.

## Owner boundaries

Daily universe collection has no silent Yahoo/Alpha Vantage fallback. Scheduled maintenance does not immediately follow it with the old per-stock gap-fill or extra retry loop. Explicit historical, history-depth, and manual gap backfills retain their existing providers and resumable stock cursor. Daily bulk runs preserve an unfinished historical cursor.

The held-portfolio daily job, benchmark and index jobs, dataset freshness ledger, scheduling window, and alert ownership remain separate. This change does not assert that those other owners never use a per-stock provider. Existing settings enablement and in-progress handling are retained, with a database cache lock preventing concurrent universe writers.

## Verification and delivery

Tests cover parser/archive rejection, both exchange sources, identity conflicts, whole-scope collection despite a batch size of one, receipt reuse and invalidation, preserved prices and historical cursors, calendar recovery, read-only preview, locking, and suppression of automatic legacy gap retries. Hosted repository verification is the release gate; production uses the normal verified master deployment and post-deploy SHA/smoke checks.

Production qualification must report the exact deployed SHA and observed file/identity/coverage results. Missing external coverage remains explicit and must not be reported as fully ready merely because collection finished. Issue #280 remains open until delivery and bounded production checks are recorded.
