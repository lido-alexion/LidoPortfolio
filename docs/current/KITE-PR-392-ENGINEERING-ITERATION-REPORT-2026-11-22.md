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

## Consumer integration after #383/#387 merges

1. Rebase/reconcile normally after #383 merges and review its final provider-chain, cache, backoff, and circuit contracts before touching a consumer.
2. Add a user-scoped read orchestration layer for authenticated chart and portfolio-price requests. Pass the authenticated user's ID explicitly; attempt Kite only for that same user's usable session and supported NSE/BSE equity mapping.
3. Request a complete Kite daily series for the requested chart range (and a live quote only during the current equity session). On no session, missing mapping, empty/incomplete candles, HTTP 403/429, timeout, or provider error, call the existing public provider chain and preserve its result/provenance.
4. Keep Kite candles account-scoped and out of globally persisted price rows or responses to other users. Kite returns raw/unadjusted candles; do not splice them into adjusted public-provider history. Either serve one coherent source series or apply a separately verified corporate-action adjustment contract before combining sources.
5. Leave unauthenticated/public and scheduled/background reads on existing providers. Add endpoint tests for same-user isolation, fallback paths, provider provenance, adjustment-basis handling, and no Kite request without that user's session.

The existing chart read entry point is `GET /api/stocks/{stock}/market-prices` in `StockPriceController::market`; portfolio chart reads also pass through `HoldingPresentationService`. Select the final integration seam after checking #387's merged diff so it does not overlap its changed consumer or provider files.

## Focused verification

- `APP_KEY=<ephemeral test key> OTEL_SDK_DISABLED=true php artisan test --filter='KiteCandleNormalizerTest|KitePriceRequestGateTest'` — **passed**, 4 tests / 11 assertions.
- `APP_KEY=<ephemeral test key> OTEL_SDK_DISABLED=true php artisan test --filter=KiteAuthenticatedPriceDataTest` — **passed**, 9 tests / 24 assertions.
- PHP syntax checks on changed service files and the new gate test — **passed**.
- `git diff --check` — **passed**.
- The feature suite used the repository's configured in-memory SQLite test database. This is focused local verification only, not the MySQL-backed shared backend CI gate. The full shared verifier was not run.

## Remaining blockers

- Consumer-level Kite-first fallback behavior is not yet active; the required safe seam depends on #383/#387 merging and final compatibility review.
- No owner action is required for this local iteration.

## ACTION REQUIRED FROM YOU

**None.** Consumer integration is blocked by the explicitly requested #383/#387 merge boundary, not by an approval or account action.
