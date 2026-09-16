# User Calendar — Retrospective CURRENT Specification

**Document:** V2.1 Retrospective CURRENT Spec  
**Location:** `docs/v2.1/CALENDAR-SPEC.md`  
**Date:** 2026-08-10  
**Status:** CURRENT (runtime formalization — not a new feature)  
**Related:** WS-C [`WS-C-SHADOW-FEATURE-INVENTORY.md`](./WS-C-SHADOW-FEATURE-INVENTORY.md); Dashboard [`DASHBOARD-SPEC.md`](./DASHBOARD-SPEC.md); `TradingCalendar` support helper  

**No new F-number.** Do not redesign Calendar or start V3.

---

## 1. Purpose

Formalize the **CURRENT** User Calendar (`/calendar` + `/api/calendar/*`) so operators and docs agree on:

- what events exist and who owns them  
- how occurrences are computed  
- how trade holidays interact with market-session helpers  
- what Calendar does **not** auto-ingest from ledger / alerts / recommendations  

Calendar is primarily a **persisted event store + recurrence expansion + UI**, with a side effect that **global trade holidays** feed `TradingCalendar` session detection.

---

## 2. Current capability

| Area | Status |
|------|--------|
| Per-portfolio user-created events | **IMPLEMENTED** |
| Recurrence expansion (none/daily/weekly/monthly/yearly variants) | **IMPLEMENTED** |
| Year-grid UI + day dialog + create/edit form | **IMPLEMENTED** |
| Quick presets (F&O / Options expiry last Thursday) | **IMPLEMENTED** (UI form helpers only — not exchange sync) |
| Optional Telegram reminders (`reminder_days_before`) | **IMPLEMENTED** (portfolio events) |
| Dashboard upcoming card (next 31 days) | **IMPLEMENTED** |
| Admin global trade holidays (`category=trade_holiday`, `profile_id` null) | **IMPLEMENTED** |
| Trade holidays suppress equity session / trade-alert digests | **IMPLEMENTED** via `TradingCalendar` |
| Auto events from transactions | **NOT IMPLEMENTED / NOT FOUND** |
| Auto events from corporate actions | **NOT IMPLEMENTED / NOT FOUND** |
| Auto events from alerts / recommendations / watchlist | **NOT IMPLEMENTED / NOT FOUND** |
| Exchange holiday feed import | **NOT IMPLEMENTED** |
| Time-of-day / timed events | **NOT IMPLEMENTED** (date-only) |
| Event retention / archival policy | **NOT FOUND** |

---

## 3. User workflows (CURRENT)

### 3.1 Open Calendar

1. Operator opens **Market → Calendar** (`/calendar`).  
2. Client computes visible months: all months of current year; if current month is Oct–Dec, also Jan–Mar of next year.  
3. Parallel load:
   - `GET /api/calendar/events` — event definitions visible to active portfolio  
   - `GET /api/calendar/occurrences?from=&to=` — expanded dates in range  
4. Days with occurrences show color marker (solid or conic pie for multiple colors).  
5. Click day → dialog lists titles; edit allowed for own events (trade holidays: admin only).

### 3.2 Create / edit / delete

1. Form: title, description, color, anchor date, recurrence type/config, end date, active flag, optional reminders.  
2. Admins may check **Trade holiday (global)** → forces amber color, `category=trade_holiday`, `profile_id=null`.  
3. Presets fill title/color/recurrence for “F&O expiry” / “Options expiry” (last Thursday monthly) — still ordinary portfolio events until saved.  
4. Mutations: `POST/PUT/DELETE /api/calendar/events…` then reload.

### 3.3 Dashboard upcoming

1. `GET /api/calendar/upcoming` (31 days from today).  
2. Card shows date + Today/Tomorrow/N days ahead; **Open calendar** navigates to `/calendar`.

### 3.4 Reminders (existing — not new work)

1. Event has `reminder_enabled` + `reminder_days_before` (0 = day of, N = N days prior).  
2. Scheduler: `portfolio:send-calendar-reminders` daily 07:00 in cron timezone.  
3. Dedup via `portfolio_calendar_reminder_sends`.  
4. Global trade holidays have `profile_id=null` → reminder send **skips** (no profile Telegram target).

---

## 4. Event generation model

| Kind | Meaning in CURRENT |
|------|---------------------|
| **Persisted events** | Rows in `portfolio_calendar_events` (portfolio-scoped or global holiday) |
| **Generated / derived occurrences** | Computed at read time by `CalendarRecurrenceService` — **not** stored per day |
| **External events** | **NOT FOUND** (no broker/exchange calendar ingest) |
| **Ledger/CA/alert-derived events** | **NOT FOUND** |

---

## 5. Event categories (CURRENT)

| Category | Who creates | Scope | Notes |
|----------|-------------|-------|-------|
| `null` (ordinary) | Any authenticated user for active portfolio | `profile_id` = active portfolio | Custom titles; presets are ordinary events |
| `trade_holiday` | **Admin only** | Global (`profile_id` null) | Fixed color `#b45309`; visible to all portfolios |

No other categories are accepted by API validation.

---

## 6. Date / time semantics

- Events are **calendar-date only** (no start/end time).  
- `anchor_date` + recurrence rules define occurrence dates.  
- Optional `recurrence_end_date` caps expansion.  
- Inactive events (`is_active=false`) excluded from occurrences.  
- Occurrence queries use `Carbon` date bounds; app/cron timezone applies to “today” for upcoming/reminders (`cron_timezone` for scheduled command).  
- Weekends are **not** auto-skipped for ordinary events (an event can fall on Saturday). Trade holidays are explicit dates/recurrences that mark non-session days for **TradingCalendar**, separate from weekend logic.

---

## 7. Filtering

| Filter | CURRENT |
|--------|---------|
| Profile visibility | `visibleToProfile` = own events ∪ global trade holidays |
| Active only | Occurrences require `is_active` |
| Date range | Required on `/occurrences`; upcoming = today → +31d |
| Category filter UI | **NOT FOUND** (holidays distinguished in UI by badge) |
| Search | **NOT FOUND** |

---

## 8. Profile ownership & permissions

| Action | Ordinary event | Trade holiday |
|--------|----------------|---------------|
| List / occurrences / upcoming | Visible if own or global holiday | Visible to all portfolios |
| Create | Active portfolio | Admin only |
| Update / delete | Owner profile only | Admin only |
| Cross-portfolio mutate | Blocked (404/validation) | N/A |

Route model binding resolves active-profile events **or** global trade holidays.

---

## 9. Empty states

- Year grid with no markers when no occurrences in range.  
- Day dialog empty list if opened on empty day (typically only event days are clicked).  
- Dashboard card shows empty upcoming list when none.

---

## 10. Data model (CURRENT)

### `portfolio_calendar_events`

| Column | Role |
|--------|------|
| `profile_id` | Portfolio owner; **null** for global holidays |
| `category` | `trade_holiday` or null |
| `title`, `description`, `color` | Display |
| `anchor_date` | Recurrence seed / one-time date |
| `recurrence_type`, `recurrence_config`, `recurrence_end_date` | Rules |
| `reminder_enabled`, `reminder_days_before` | Telegram reminders |
| `is_active` | Soft disable without delete |

### `portfolio_calendar_reminder_sends`

Dedup key: `(event_id, occurrence_date, days_before)`.

---

## 11. APIs (CURRENT)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/calendar/events` | List definitions |
| GET | `/api/calendar/occurrences` | Expand range |
| GET | `/api/calendar/upcoming` | Next 31 days + `days_ahead` |
| POST | `/api/calendar/events` | Create |
| PUT | `/api/calendar/events/{id}` | Update |
| DELETE | `/api/calendar/events/{id}` | Delete |

---

## 12. Frontend inventory

| Path | Role |
|------|------|
| `pages/CalendarPage.jsx` | Year grid |
| `components/calendar/CalendarDayMarker.jsx` | Color markers |
| `components/calendar/CalendarDayEventsDialog.jsx` | Day list + Dashboard upcoming card |
| `components/calendar/CalendarEventFormDialog.jsx` | Create/edit |
| `utils/calendarEvents.js` | Months, presets, grouping |

---

## 13. Ownership matrix (critical)

| Event / item type | Current owner | Data source | Calendar responsibility |
|-------------------|---------------|-------------|-------------------------|
| User custom event | **User Calendar** | `portfolio_calendar_events` | CRUD + expand + UI |
| F&O / Options preset | **User Calendar** (UI preset → saved event) | Same table after save | Form helper only — **not** NSE expiry feed |
| Global trade holiday | **User Calendar** (admin) + consumed by **TradingCalendar** | Same table (`profile_id` null) | Persist/expand; clear holiday cache on mutate |
| Telegram calendar reminder | **User Calendar** + TelegramNotificationService | Event + reminder sends | Schedule send |
| Transaction date | **Ledger / Transactions** | `portfolio_transactions` | **None** — not calendar events |
| Corporate action | **F020** (+ F043 prices) | CA tables / ledger | **None** |
| Alert instance | **F127** | `portfolio_alerts` | **None** (Dashboard alerts ≠ calendar) |
| Recommendation lifecycle | TOS / Recommendation | Recommendations | **None** |
| Watchlist item | Watchlist | Watchlist tables | **None** |
| Cash movement | Cash Management | Cash ledger | **None** |
| Weekend non-session | **TradingCalendar** (builtin) | Code | Calendar may still show ordinary events on weekends |

---

## 14. Current limitations

1. No auto-sync from exchange/corporate-action/ledger.  
2. Presets can drift from real exchange holiday/expiry calendars.  
3. Reminders for global holidays not sent (by design — no profile).  
4. Thin recurrence unit tests (monthly last Thu + yearly fixed).  
5. No retention policy for old events / reminder send rows.  
6. Distinct from Data Engine “trading calendar product” historical OOS — this UI **did** ship as CURRENT.

---

## 15. Explicit out of scope

- Adding new reminder channels or redesigning recurrence  
- Importing broker/exchange calendars  
- Auto-generating events from transactions/CA/alerts/recommendations  
- Absorbing TradingCalendar weekend logic into Calendar UI  
- V3 marketplace calendars  

---

## 16. Test coverage summary

| Area | Coverage |
|------|----------|
| CRUD + occurrences + upcoming | `CalendarEventTest` |
| Profile isolation | Same |
| Trade holiday admin/non-admin | Same |
| Recurrence expand (sample) | `CalendarRecurrenceServiceTest` |
| TradingCalendar holidays | `TradingCalendarTest` (sibling) |
| Reminder send / dedup | **TEST GAP** (thin/absent dedicated) |
| Dashboard upcoming card | Indirect / Dashboard pack |

See GAP matrix.
