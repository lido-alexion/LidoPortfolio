# API Documentation

Base URL: `/api`

**`/api/v1` machine-readable contract (V4-FEAT-025):** [`openapi/v1.json`](openapi/v1.json) (OpenAPI 3.0.3). Regenerated with `php artisan openapi:v1`. A copy is also at [`public/docs/openapi-v1.json`](public/docs/openapi-v1.json). This file remains the human notes for legacy `/api` (non-v1) routes.

**TOS list pagination (V4-FEAT-028):** `GET /api/v1/securities`, `/price-bars`, `/recommendations`, `/orders`, `/transactions`, `/notifications`, `/reviews` accept `page` (default 1) and `pageSize` (alias `per_page`). Response `meta` includes `{page, pageSize, total, lastPage}`. Max page size 200 (price-bars 500). Candidates, evaluations, positions, and pending-execution are not paginated.

## Auth (session cookies — no Bearer token)

Uses Laravel Sanctum SPA mode. Browser must send cookies (`credentials: include`). Call `GET /sanctum/csrf-cookie` before login.

- `POST /auth/login` — body: `email`, `password`, optional `remember`. If email has a pending invite, returns `422` with `invite_setup_required: true` and `invite_token` (redirect user to `/invite/{token}`).
- `POST /auth/logout` (session)
- `GET /auth/me` (session) — includes `user.is_admin` (boolean) and `user.default_portfolio_id`

## Active portfolio (multi-portfolio)

Portfolio-scoped endpoints (transactions, holdings, dashboard, alerts, settings personal keys, analytics, rebuild-history) operate on the **active portfolio** resolved by middleware:

- Header **`X-Profile-Id`**: portfolio id (integer). Also accepts legacy `X-Portfolio-Id`.
- Query `portfolio_id` (optional alternative)
- If omitted, the user's **default** portfolio is used (`portfolio_profiles.is_default`)

Active portfolio is **per browser tab** on the SPA (`sessionStorage` key `portfolio_active_id`); Laravel session cookies are shared across tabs, so the server does not store active portfolio in session.

### Portfolios (user-scoped)

- `GET /portfolios` (auth) — list user's portfolios (`id`, `name`, `is_default`)
- `POST /portfolios` (auth) — body: `name`
- `GET /portfolios/{portfolio}` (auth)
- `PUT /portfolios/{portfolio}` (auth) — rename
- `DELETE /portfolios/{portfolio}` (auth) — soft-deletes portfolio (`deleted_at`); purges related transactions, holdings, snapshots, alerts, and profile settings. Not allowed for the **default** portfolio, if it is the user's only portfolio, or if `X-Profile-Id` matches the portfolio being deleted (active in the requesting tab). No restore API — deleted data is not recoverable via the app. Creating a portfolio with the same name as a deleted one inserts a **new** row (new id); it does not link to the soft-deleted profile or its data. **SPA stale tab:** other tabs with a deleted portfolio id recover automatically (cross-tab `BroadcastChannel`, API 404 retry, tab focus refresh).
- `POST /portfolios/{portfolio}/set-default` (auth)

Account routes (`/profile`, auth sessions, admin users/invites) ignore portfolio header.

- `GET /auth/sessions` (session) — active devices
- `POST /auth/sessions/logout-others` (session)
- `DELETE /auth/sessions/{sessionId}` (session)

**Registration is invite-only** — no public `POST /auth/register`.

### Invites (guest)

- `GET /invites/{token}` — validate invite; `410` if expired (row deleted); `409` if already accepted
- `POST /invites/accept` — body: `token`, `password`, `password_confirmation`, optional `name`, `remember`; creates user, marks invite accepted, establishes session
- `GET /reset-password/{token}` — validate admin-issued password reset link; `410` if expired (row deleted); `409` if already used
- `POST /reset-password/accept` — body: `token`, `password`, `password_confirmation`, optional `remember`; updates password, marks link used, establishes session

Responses return `user` only (no `token` field).

## Stocks

- `GET /stocks` (auth) — optional `?q=` filter
- `GET /stocks/search?q=inf&exchange=NSE&limit=20` (auth, throttled) — local master autocomplete, min 2 chars
- `POST /stocks/validate` (auth, throttled) — body: `{ "symbol", "exchange?", "name?", "check_only"? }`; persist when not `check_only` (transaction flow)
- `GET /stocks/{stock}` (auth)
- `POST /stocks` (auth, **admin**) — creates via validation pipeline
- `PUT /stocks/{stock}` (auth, **admin**)

Stock master sync (CLI): `php artisan stocks:sync` (weekly scheduled)

Universe OHLCV sync (CLI): `php artisan portfolio:sync-universe-prices` — `--mode=backfill|daily`, `--scope=all_equities|nifty500` (`all_nse` deprecated alias), `--all` for full-universe backfill.

## Transactions

- `GET /transactions` (auth) — paginated ledger; sell rows may include persisted `exit_reason` (`strategy_exit` | `stop_loss` | `trailing_stop` | `horizon_expiry`) copied from the recommendation primary attribution on fill
- `POST /transactions` (auth) — provide `stock_id` **or** `symbol` (optional `name`, `exchange`); unknown symbols create a stock master row automatically on buy
- `GET /transactions/{transaction}` (auth) — includes `exit_reason` when set
- `PUT /transactions/{transaction}` (auth)
- `DELETE /transactions/{transaction}` (auth)

## Exploratory Analytics

- `POST /analytics/explore` (auth, throttled) — analyze any symbol with cached history

```json
{
    "symbol": "INFY",
    "exchange": "NSE",
    "benchmark_symbol": "NIFTY50",
    "periods": [1, 3]
}
```

Returns growth %, relative strength vs benchmark, chart series, cache/fetch metadata.

## Holdings + Dashboard + Analytics

- `GET /holdings` (auth) — each item includes `stoploss_summary`:
    - `first_buy_date`, `highest_close_since_buy`, `highest_close_since_buy_date`
    - `trailing_stop_price` — from **portfolio trailing %** × peak raw close (owner-scoped episode)
    - `stop_loss_price` — from **portfolio stop-loss %** × weighted-average actual fill cost (current ownership episode)
    - `weighted_average_fill_cost`, `stoploss_percent`, `portfolio_trailing_percent`
    - `latest_close`, `price_row_count`, `has_price_history`, `latest_price_date`, `previous_price_date`, `daily_change_percent`
    - Portfolio trailing is **not** unrealized-%, **not** `default_stoploss_percent`, and **not** strategy `trailing_stop` JSON
    - OD-12 fields (strategy-owned lots): `target_amount`, `filled_amount` (actual invested cost), `remaining_target_amount` = max(0, target − filled)
    - Ownership: `owner_key`, `strategy_id`, `is_unmanaged`
- `POST /holdings/{id}/adopt` (auth) — body `{ "strategy_id": int }`. Explicit unmanaged → one strategy (§10.4).
    - Initializes `target_amount` / `filled_amount` from invested cost so remaining BUY/INCREASE is 0
    - Preserves entry history (OD-15) via HOLD_POSITION attribution (does **not** start OD-11 BUY cooldown)
    - **422** when destination strategy already owns the same stock (merge cost-basis unspecified — blocked)
    - Idempotent when the holding is already owned by that strategy
- `GET /stocks/{stock}/prices` (auth) — OHLCV rows for held stocks with `range=all|since_buy`
    - `range=all` (default): all available cached history for the instrument (OD-17)
    - `range=since_buy`: current position episode history (legacy since-buy view)
- `GET /stocks/{stock}/market-prices` (auth) — all cached OHLCV rows from `portfolio_stock_prices` (no holding required; used by Watchlist)
- `GET /dashboard` (auth) — `portfolio_growth` uses transaction-aware rebuilt snapshots (up to 365 trading days); `alerts` = active alerts for the portfolio (policy-generated and any legacy rows)

## Watchlist (active portfolio)

- `GET /watchlist` (auth) — list items with `stock`, optional `note`, `latest_close`, `price_count`, `has_price_history`
- `POST /watchlist` (auth) — body `{ "stock_id": int, "note"?: string }` (max 100 items per profile)
- `PUT /watchlist/{id}` (auth) — update `note`
- `DELETE /watchlist/{id}` (auth) — remove item

## Knowledge Board (active portfolio)

Stock-market notes, tags, and relationships — scoped per active portfolio (`profile_id`).

### Notes

- `GET /knowledge-board/notes` (auth) — query: `q` (title/content/tag name), `archived` (bool, default false), `tag_ids[]`, `tag_match` (`any`|`all`|`exclude`), `sort` (`updated_at`|`created_at`|`title`|`pinned_first`)
- `POST /knowledge-board/notes` (auth) — body: `title` (required), `content_json`, `content_html`, optional `tag_ids`, `is_pinned`, `is_favorite`, `is_archived`
- `GET /knowledge-board/notes/{id}` (auth)
- `PUT /knowledge-board/notes/{id}` (auth) — partial update of note fields and `tag_ids`
- `DELETE /knowledge-board/notes/{id}` (auth)
- `POST /knowledge-board/notes/{id}/duplicate` (auth)
- `POST /knowledge-board/notes/bulk` (auth) — body: `action` (`archive`|`delete`), `note_ids` (array of ints)

Manual card order is **client-only** (`localStorage`); not stored via API.

### Tags

- `GET /knowledge-board/tags` (auth)
- `POST /knowledge-board/tags` (auth) — body: `name`, optional `color`
- `PUT /knowledge-board/tags/{id}` (auth) — rename / recolor
- `DELETE /knowledge-board/tags/{id}` (auth)
- `POST /knowledge-board/tags/merge` (auth) — body: `source_id`, `target_id` (moves note links, deletes source)

## Dashboard + Analytics (continued)

- `POST /portfolio/rebuild-history` (auth) — manual snapshot rebuild; body `{ "from_date": "YYYY-MM-DD", "to_date": "YYYY-MM-DD" }` (both optional)
- `GET /analytics/portfolio` (auth)
- `GET /analytics/stocks/{stock}` (auth)

## Alerts

- `GET /alerts` (auth) — active alerts for the active portfolio (`profile_id`, `expired_at` is null).
- `POST /alerts/expire-all` (auth) — expire all active alerts for the active portfolio (`expiration_reason`: `manual_all`).
- `POST /alerts/{id}/acknowledge` (auth) — expire one alert owned by the active portfolio (`acknowledged`).

Automatic expiration (`AlertExpirationService`):

| Reason           | Trigger                                                                           |
| ---------------- | --------------------------------------------------------------------------------- |
| `manual_all`     | Dashboard **Clear all**                                                           |
| `acknowledged`   | Per-row **Acknowledge**                                                           |
| `max_age_100h`   | Hourly `portfolio:expire-alerts`                                                  |
| `data_refresh`   | Successful daily sync when latest portfolio price date advances (new trading day) |
| `holding_closed` | Profile fully sells — no open holding left for that profile/stock                 |

Policy-generated alerts (`alert_type=policy`) include `condition_display`, `action_suggested`, `context_json`, and `instance_key` (`user_id-profile_id-stock_id-policy_id`) for deduplication.

## Alert policies (active portfolio)

- `GET /alert-policies/meta` (auth) — column list, operators, compare types, action types
- `GET /alert-policies` (auth) — list policies for active portfolio
- `POST /alert-policies` (auth) — create (`name` unique per portfolio)
- `GET /alert-policies/{id}` (auth)
- `PUT /alert-policies/{id}` (auth)
- `DELETE /alert-policies/{id}` (auth)
- `POST /alert-policies/evaluate` (auth) — run enabled policies against holdings (also after daily sync)

## Settings

- `GET /settings` (auth) — admins receive merged global + active portfolio settings; non-admins receive active portfolio settings plus read-only `cron_timezone`. Portfolio risk keys include independent `default_stoploss_percent` (Portfolio Stop-Loss %) and `portfolio_trailing_percent` (Portfolio Trailing Stop %, default/seed **15%** — OD-22). Trailing is not derived from stop-loss % or strategy JSON.
- `PUT /settings` (auth) — personal keys (stop-loss %, trailing %, Telegram, notification times, cash reserve, etc.) for the active portfolio; global keys (cron, fees, NSE retry, Alpha Vantage key, log level, sync log retention) **admin only** (403 if non-admin sends global fields)
- `POST /settings/test-telegram` (auth) — body: `telegram_bot_token`, `telegram_chat_id`; sends **active portfolio's** alerts digest or `No active alerts at this time`; does not require `notifications_enabled`

## Users (admin only)

- `GET /users` (auth, admin) — list accounts (`id`, `name`, `email`, `is_admin`, `created_at`)
- `PUT /users/{user}/admin` (auth, admin) — body: `is_admin` (boolean); cannot change own role
- `GET /invites` (auth, admin) — list invites with status, expiry, `invite_url`, `invite_message`
- `POST /invites` (auth, admin) — body: `email`; creates 72h invite
- `POST /invites/{invite}/regenerate` (auth, admin) — new token and 72h expiry
- `DELETE /invites/{invite}` (auth, admin) — revoke pending invite
- `GET /password-reset-links` (auth, admin) — list reset links with status, expiry, `reset_url`, `reset_message`
- `POST /password-reset-links` (auth, admin) — body: `user_id`; creates 72h link for existing user (one pending per user)
- `POST /password-reset-links/{id}/regenerate` (auth, admin) — new token and 72h expiry
- `DELETE /password-reset-links/{id}` (auth, admin) — revoke pending reset link

## Recommendations (Trading OS v1)

- `GET /v1/recommendations` / `GET /v1/recommendations/{id}` (auth) — EXIT rows may include:
    - `primary_exit_reason` — canonical §13.2 value: `strategy_exit` | `stop_loss` | `trailing_stop` | `horizon_expiry`
    - `exit_attribution` — persisted attribution object (also under `evidence` / `execution_plan`)
    - When multiple mechanisms are true, only the highest-precedence reason is the primary attribution
- OPEN/INCREASE rows may include OD-12 staggered-entry fields:
    - `position_target_amount` — persisted conviction target (not reduced by partial funding)
    - `this_cycle_amount` — first-entry % of target or remaining after filled
    - `position_filled_amount`, `remaining_target_amount`, `is_first_entry`
    - Allocator / lending still use this-cycle amounts; WS4 `actual_execution_amount` remains authoritative for what can execute
- Executed sells may expose `execution.exit_reason` from `portfolio_transactions.exit_reason` (persisted copy; not recalculated)
- BUY cooldown (OD-11): 1 calendar day per `(stock, strategy)` from recommendation `generated_at`; does not affect REDUCE/EXIT

## Frontend Logs

- `POST /logs/frontend` (auth) — accept important SPA warn/error logs (written to `storage/logs/frontend-*.log`)

Request body:

```json
{
    "level": "error",
    "message": "Failed to fetch holdings",
    "url": "/dashboard",
    "userAgent": "Mozilla/5.0 ...",
    "timestamp": "2026-05-28T12:00:00.000Z",
    "requestId": "uuid-from-x-request-id",
    "extra": { "category": "API", "api": "/api/holdings" }
}
```

- Returns `202` on success; `422` if validation fails or payload too large (body >8KB, `extra` JSON >4KB)
- Send header `X-Request-ID` on all API calls (frontend generates UUID per request)

## Sync

- `POST /sync/daily` (auth, **admin**) — body/query `force` (boolean)
- `POST /sync/backfill/{stock}` (auth, **admin**) — force fetch from user's buy date
- `GET /universe-price-sync/status` (auth, **admin**) — query `scope` (`all_equities`|`all_nse` deprecated|`nifty500`); progress, coverage, cursor, `rate_limits.likely_rate_limited`, `rate_limits.recent_issues`
- `POST /universe-price-sync/run` (auth, **admin**, throttle 12/min) — body: `mode` (`daily`|`backfill`), `scope`, `batch` (1–200), `reset_cursor`, `process_all` (avoid on cPanel — may timeout)
- `POST /universe-price-sync/stock-master` (auth, **admin**, throttle 12/min) — NSE equity master CSV import
- `GET /sync-logs`, `GET /sync-logs/runs`, `GET /sync-logs/export` (auth, **admin**)
- Settings `notification_schedules` — per active portfolio; global `cron_time` is admin-only
- `portfolio:send-notifications` — per-profile Telegram digests at scheduled times

## Response Rules

- JSON responses only
- Proper HTTP status codes
- Validation errors return 422 with field messages

## Tested Smoke Flow

- Admin invite -> Accept invite -> Login -> Create Transaction -> Holdings
- Dashboard + Portfolio Analytics + Stock Analytics + Alerts

Run via:

`PowerShell -ExecutionPolicy Bypass -File tests/Feature/api_smoke.ps1`
