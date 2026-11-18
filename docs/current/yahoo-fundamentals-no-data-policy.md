# Yahoo fundamentals no-data and refresh policy

## Provider outcomes

Yahoo's schema-valid response with empty annual or quarterly statement frames is recorded as `no_data`. It does not create facts, advance `last_successful_check_at`, increment successful run counters, or create `fundamentals_update_failed`. Invalid JSON/schema, identity mismatch, adapter/transport failures, rate limits, HTTP failures and upstream errors remain failures. When an approved exchange fallback is enabled, it may still supply facts after Yahoo returns no data; a fallback error remains an operational failure.

The adapter tries the primary NSE symbol and its BSE alias once each within one logical company/cadence fetch. BSE statements are accepted only after the existing issuer-name identity check. A valid no-data outcome is audited by company and update run; annual and quarterly observations in the same run count once. Actual provider errors interrupt the no-data streak. Confirmed rows reset the streak.

## Automatic skip and admin controls

Twenty consecutive no-data update runs add the company stock record to the durable Yahoo skip list. Scheduled jobs omit skipped symbols; queued jobs recheck the skip state at execution. A no-data outcome receives a 30-day cooldown. Admins can inspect symbol, exchange, reason, count, skip time and recent outcome history; manually run the fetch despite a skip (three requests per admin per hour, one active fetch per symbol); import supported facts through the existing workbook pathway; and explicitly remove a skip. Removal resets the counter to zero. A successful manual fetch does not clear a skip or remove the symbol from the list; an admin must explicitly remove it. This avoids an override unexpectedly re-enabling an issuer before reviewing the source outcome.

The supported safe alternate data pathway is the existing admin XLSX importer with symbol, exchange and company confirmation. There is no automatic data entry or fabricated-fact pathway.

## Pipeline health and notifications

The admin status page reports recent observed outcomes from durable run observations. It separates confirmed statement data, valid `no_data`, retry-pending genuine errors, and exhausted errors, and reports distinct symbols, observed sessions, sample sufficiency, and error categories (rate limit, auth, connectivity, parsing and upstream). Unobserved scheduled skips are not provider responses and do not enter the health denominator. An unparseable/nonempty Yahoo `data` statement that normalizes to no supported facts is a parsing failure; it cannot be downgraded to `no_data`.

Health is `indeterminate` when observations are absent or all are valid no-data. It does not call a no-data-only cohort healthy or broken. A mixed data/error session is operational with partial failures. Systemic outage classification requires at least 8 observed company outcomes across 5 symbols, evidence from both quarterly and annual cadences, and two consecutive sessions each containing at least 3 observed companies with at least 80% genuine errors. One or two genuine failures remain retry-pending for at least 24 hours and do not create a critical alert or urgent Telegram. A failure after the three-attempt budget or a sustained systemic cohort is actionable; a new 20-observation skip produces a separate review-level admin notification. Fundamentals Telegram repeats are limited to once per 24 hours; recovery requires two consecutive clean data sessions before the failure alert is resolved. Existing non-fundamentals alert keys and routing are unchanged.

## Refresh windows

The previous fixed 3-day quarterly / 14-day annual success-check intervals are replaced with conservative period-end windows: quarterly data is due 120 days after the latest current period end; annual data is due 210 days after its latest current period end. Missing-period legacy records still use a check-ledger fallback. The due query examines the latest period end as well as the check ledger, so a late fetch does not restart a full window from fetch time. A no-data outcome uses its separate 30-day cooldown.

These generic windows avoid frequent polling but do not model issuer-specific fiscal calendars, exchange filing extensions or company reporting history. A follow-on should add an evidence-backed per-issuer expected-publication estimator with delayed/missing-report safeguards and backtests before changing these constants. Until then, the fixed period-end windows are deliberately conservative and are not claims about a specific issuer's legal deadline.

## Durable records

`stox_yahoo_fundamental_symbol_state` stores the current consecutive count and skip state. `stox_yahoo_fundamental_observations` preserves run-level outcomes and explicit admin skip removal events. Existing facts, fact revisions and provider-check records are retained.
