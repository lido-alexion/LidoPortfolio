# API Documentation

Base URL: `/api`

## Auth (session cookies — no Bearer token)

Uses Laravel Sanctum SPA mode. Browser must send cookies (`credentials: include`). Call `GET /sanctum/csrf-cookie` before login.

- `POST /auth/login` — body: `email`, `password`, optional `remember`. If email has a pending invite, returns `422` with `invite_setup_required: true` and `invite_token` (redirect user to `/invite/{token}`).
- `POST /auth/logout` (session)
- `GET /auth/me` (session) — includes `user.is_admin` (boolean)
- `GET /auth/sessions` (session) — active devices
- `POST /auth/sessions/logout-others` (session)
- `DELETE /auth/sessions/{sessionId}` (session)

**Registration is invite-only** — no public `POST /auth/register`.

### Invites (guest)

- `GET /invites/{token}` — validate invite; `410` if expired (row deleted); `409` if already accepted
- `POST /invites/accept` — body: `token`, `password`, `password_confirmation`, optional `name`, `remember`; creates user, marks invite accepted, establishes session

Responses return `user` only (no `token` field).

## Stocks

- `GET /stocks` (auth) — optional `?q=` filter
- `GET /stocks/search?q=inf&exchange=NSE&limit=20` (auth, throttled) — local master autocomplete, min 2 chars
- `POST /stocks/validate` (auth, throttled) — body: `{ "symbol", "exchange?", "name?", "check_only"? }`; persist when not `check_only` (transaction flow)
- `GET /stocks/{stock}` (auth)
- `POST /stocks` (auth, **admin**) — creates via validation pipeline
- `PUT /stocks/{stock}` (auth, **admin**)

Stock master sync (CLI): `php artisan stocks:sync` (weekly scheduled)

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
- `GET /dashboard` (auth) — `portfolio_growth` uses transaction-aware rebuilt snapshots (up to 365 trading days)
- `POST /portfolio/rebuild-history` (auth) — manual snapshot rebuild; body `{ "from_date": "YYYY-MM-DD", "to_date": "YYYY-MM-DD" }` (both optional)
- `GET /analytics/portfolio` (auth)
- `GET /analytics/stocks/{stock}` (auth)

## Alerts

- `GET /alerts` (auth) — active alerts for the authenticated user (`user_id`, `expired_at` is null).
- `POST /alerts/expire-all` (auth) — expire all active alerts for that user (`expiration_reason`: `manual_all`).
- `POST /alerts/{id}/acknowledge` (auth) — expire one alert owned by the user (`acknowledged`).

Automatic expiration (`AlertExpirationService`):

| Reason | Trigger |
|--------|---------|
| `manual_all` | Dashboard **Clear all** |
| `acknowledged` | Per-row **Acknowledge** |
| `max_age_100h` | Hourly `portfolio:expire-alerts` |
| `data_refresh` | Successful daily sync when latest portfolio price date advances (new trading day) |
| `holding_closed` | User fully sells — no open holding left for that user/stock |

## Settings

- `GET /settings` (auth) — admins receive merged global + personal settings; non-admins receive personal settings only plus read-only `cron_timezone`
- `PUT /settings` (auth) — personal keys (stoploss %, Telegram, notification times) for any user; global keys (cron, fees, NSE retry, Alpha Vantage key, log level, sync log retention) **admin only** (403 if non-admin sends global fields)
- `POST /settings/test-telegram` (auth) — body: `telegram_bot_token`, `telegram_chat_id`; sends **requesting user's** active alerts digest or `No active alerts at this time`; does not require `notifications_enabled`

## Users (admin only)

- `GET /users` (auth, admin) — list accounts (`id`, `name`, `email`, `is_admin`, `created_at`)
- `PUT /users/{user}/admin` (auth, admin) — body: `is_admin` (boolean); cannot change own role
- `GET /invites` (auth, admin) — list invites with status, expiry, `invite_url`, `invite_message`
- `POST /invites` (auth, admin) — body: `email`; creates 72h invite
- `POST /invites/{invite}/regenerate` (auth, admin) — new token and 72h expiry
- `DELETE /invites/{invite}` (auth, admin) — revoke pending invite

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
- `GET /sync-logs`, `GET /sync-logs/runs`, `GET /sync-logs/export` (auth, **admin**)
- Settings `notification_schedules` — per-user; global `cron_time` is admin-only
- `portfolio:send-notifications` — per-user Telegram digests at scheduled times

## Response Rules

- JSON responses only
- Proper HTTP status codes
- Validation errors return 422 with field messages

## Tested Smoke Flow

- Admin invite -> Accept invite -> Login -> Create Transaction -> Holdings
- Dashboard + Portfolio Analytics + Stock Analytics + Alerts

Run via:

`PowerShell -ExecutionPolicy Bypass -File tests/Feature/api_smoke.ps1`
