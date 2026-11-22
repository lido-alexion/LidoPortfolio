# Kite PR #392 engineering iteration — 2026-11-22

## Scope and current state

- Issue [#386](https://github.com/lido-alexion/LidoPortfolio/issues/386) calls for Kite-first live and historical pricing with provider fallback, account isolation, complete OHLCV normalization, throttling, and no Kite dependency for public/background pricing.
- Draft [PR #392](https://github.com/lido-alexion/LidoPortfolio/pull/392) is a provider foundation and says consumer routing awaits #383/#387 compatibility review.
- PR [#387](https://github.com/lido-alexion/LidoPortfolio/pull/387) was still **open, draft, and unmerged** when checked. Its branch is `feat/universe-pricing-reliability-383`.
- No consumer routing was added in this iteration. The global `PriceFetchService`, `UniversePrice`, and #383/#387 work remain untouched. No git fetch/add/commit/push, production write, deploy, or real Kite request was performed.

## Changes made

- `app/app/Services/PriceProviders/KiteAuthenticatedPriceData.php`
  - Normalizes historical request bounds to Asia/Kolkata calendar days and caps a Kite historical request at ten years, with 90-day request chunks and daily resolution.
  - Returns an empty result before instrument resolution or HTTP access when the requesting user has no usable Kite session.
  - Treats malformed, incomplete, duplicate, or internally gapped session-day candle responses as provider failures so a caller can use its fallback. Empty history remains empty for caller fallback.
  - Rejects invalid instrument tokens and symbols; live quotes must be current-session, session-time, non-future, and no older than 120 seconds. Cached quotes remain user-scoped and future-dated cache entries are rejected.
- `app/app/Services/PriceProviders/KiteCandleNormalizer.php`
  - Requires Kite's offset-bearing timestamp form and complete finite OHLCV, rejects inconsistent bars and weekends, and converts timestamps to the exchange-local date. Output remains raw/unadjusted and does not invent adjusted close.
- `app/app/Services/PriceProviders/KitePriceRequestGate.php`
  - Keeps Kite's documented pacing at one quote request per second and three historical requests per second, paced across the app's shared Kite API key. Circuit cooldown is now anchored to the first failure and does not slide forward while open.
- Tests added/extended:
  - `app/tests/Feature/Execution/KiteAuthenticatedPriceDataTest.php`
  - `app/tests/Unit/KiteCandleNormalizerTest.php`
  - `app/tests/Unit/KitePriceRequestGateTest.php`

Kite documents the quote and historical limits as 1 request/second and 3 requests/second, and documents historical candles as timestamp/OHLC/volume values (optionally OI). See [Kite API rate limits and errors](https://kite.trade/docs/connect/v3/exceptions/) and [historical candle data](https://www.kite.trade/docs/connect/v3/historical/).

## Consumer integration — issue #386, 2026-11-22

The consumer seam is `GET /api/stocks/{stock}/market-prices` in `StockPriceController::market` and `MarketPriceService::accountHistoryForStock`. It passes the authenticated user ID directly to `KiteAuthenticatedPriceData`; the adapter still does not implement the generic provider interface.

- For a usable session and deterministic NSE/BSE equity mapping, request daily Kite candles for the stored chart range (or the most recent year if no shared rows exist), capped to Kite's latest ten-year window. Account-scoped history responses are cached for 15 minutes to limit repeat chunk requests. A live quote is attempted independently and is returned only when current, fresh and in-session.
- Return a Kite series only when the adapter produces valid rows. Keep it as a single-source response marked `provider: kite` and `adjustment_basis: unadjusted`; sparse responses are allowed for new listings and legitimate gaps, while malformed and duplicate returned candles fail the response over to fallback.
- On disconnected/expired sessions, unsupported or missing mapping, no candles, partial/malformed candles, Kite 403/429/timeouts, or other adapter failures, retain the existing shared history response. Its source is marked `provider: stored` and its existing corporate-action adjustment behavior is preserved. When no shared rows exist, invoke the existing provider fallback chain without persisting its result; report that provider in `provider`.
- Never splice Kite raw bars into shared adjusted bars. This endpoint does not write to `portfolio_stock_prices`; Kite candles and quote caches are user-scoped. Cached private history is served only while the same user still has a usable Kite session. Private credentials and adapter errors are not returned to clients.
- `/stocks/{stock}/prices` portfolio presentation, global/background provider sync, official #383 exchange pricing, price integrity/invalidation, and Strategy/ML/trading fail-closed paths are unchanged. In particular, #383 official exchange pricing remains authoritative for global daily universe sync.
- The endpoint is under the authenticated API middleware. No user-supplied account ID is accepted, preventing cross-account session selection.

Focused feature coverage exercises Kite-first chart reads, sparse/new-listing history, expired-session shared-history fallback, Kite 403 fallback without token disclosure, malformed-candle fallback, no-session behavior, account-scoped quote caching, timezone normalization, and out-of-session live quote suppression.

## Focused verification

- `APP_KEY=<ephemeral test key> OTEL_SDK_DISABLED=true php artisan test --filter='KiteCandleNormalizerTest|KitePriceRequestGateTest'` — **passed**, 4 tests / 11 assertions.
- `APP_KEY=<ephemeral test key> OTEL_SDK_DISABLED=true php artisan test --filter=KiteAuthenticatedPriceDataTest` — **passed**, 9 tests / 24 assertions.
- PHP syntax checks on changed service files and the new gate test — **passed**.
- `git diff --check` — **passed**.
- The feature suite used the repository's configured in-memory SQLite test database. This is focused local verification only, not the MySQL-backed shared backend CI gate. The full shared verifier was not run.

## Remaining blockers

- No owner action is required. The feature suite could not run in this checkout because `app/vendor/autoload.php` is absent. PHP lint and diff checks passed; full backend CI verification remains required in an environment with the project dependencies and MySQL service. Global #383 sync behavior was not changed.

## ACTION REQUIRED FROM YOU

**None.**
