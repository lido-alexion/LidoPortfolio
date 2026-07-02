# API Documentation

Base URL: `/api`

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

Universe OHLCV sync (CLI): `php artisan portfolio:sync-universe-prices` — `--mode=backfill|daily`, `--scope=all_nse|nifty500`, `--all` for full-universe backfill. Scheduled every 15 min 19:00–23:45 when enabled. See `implementation.md` → Universe price sync.

## Transactions

- `GET /transactions` (auth)
- `POST /transactions` (auth) — provide `stock_id` **or** `symbol` (optional `name`, `exchange`); unknown symbols create a stock master row automatically on buy
- `GET /transactions/{transaction}` (auth)
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
    - `first_buy_date`, `highest_close_since_buy`, `highest_close_since_buy_date`, `trailing_stop_price`, `stoploss_percent`
    - `price_row_count`, `has_price_history`, `latest_price_date`
- `GET /stocks/{stock}/prices` (auth) — OHLCV rows from current position buy date
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

- `GET /settings` (auth) — admins receive merged global + active portfolio settings; non-admins receive active portfolio settings plus read-only `cron_timezone`
- `PUT /settings` (auth) — personal keys (stoploss %, Telegram, notification times) for the active portfolio; global keys (cron, fees, NSE retry, Alpha Vantage key, log level, sync log retention) **admin only** (403 if non-admin sends global fields)
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
- `GET /universe-price-sync/status` (auth, **admin**) — query `scope` (`all_nse`|`nifty500`); progress, coverage, cursor, `rate_limits.likely_rate_limited`, `rate_limits.recent_issues`
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
