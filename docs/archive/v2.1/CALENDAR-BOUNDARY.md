# User Calendar — Boundary

**Document:** V2.1 Retrospective BOUNDARY  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companion:** [`CALENDAR-SPEC.md`](./CALENDAR-SPEC.md)

---

## 1. What User Calendar owns

| Owns | Notes |
|------|-------|
| Persisted portfolio calendar events | CRUD for active portfolio |
| Admin global trade-holiday events | `category=trade_holiday`, `profile_id` null |
| Recurrence expansion for display / upcoming / reminders | `CalendarRecurrenceService` |
| Calendar SPA + Dashboard upcoming consumer API | `/calendar`, `/api/calendar/*` |
| Optional Telegram reminders for **portfolio** events | `CalendarReminderService` + dedup table |
| Clearing `TradingCalendar` holiday cache on holiday mutate | Side effect of admin holiday CRUD |

---

## 2. What User Calendar does **not** own

| Does not own | Owner |
|--------------|-------|
| Transaction ledger / buy-sell dates as events | Transactions / `TransactionWriteService` |
| Cash deposits/withdrawals | Cash Management |
| Corporate action apply / price repair | F020 / F043 |
| Alert policy evaluation / alert instances | F127 |
| Recommendation generate/approve/execute | TOS / Recommendation / F137 |
| Watchlist membership | Watchlist |
| Weekend / session math (except holiday lookup consumption) | `TradingCalendar` (+ weekends builtin) |
| Daily market sync scheduling | Scheduler / DailyMarketDataJob |
| Dashboard composition | Dashboard (only embeds upcoming card) |
| Exchange holiday master feed | **NOT IMPLEMENTED** |

---

## 3. Transactions

- Ledger dates are **not** mirrored into calendar events.  
- Calendar remains presentation/orchestration of **user-authored** (and admin holiday) rows unless future product decides otherwise.

---

## 4. Cash Management

- No cash events on calendar.  
- No dependency.

---

## 5. Corporate Actions (F020) / F043

- CA workflows do not write calendar rows.  
- Operators may manually create a reminder event; that is ordinary User Calendar data, not F020 ownership.

---

## 6. Alerts (F127)

- Active alerts appear on **Dashboard Alerts**, not as calendar occurrences.  
- Trade-alert Telegram quiet days **consume** trade holidays via `TradingCalendar` — Calendar owns holiday **definitions**; F127/notification schedule owns digest send gating.

---

## 7. Recommendations

- No recommendation lifecycle dates on calendar.  
- Do not reopen F137.

---

## 8. Watchlist

- No watchlist-derived calendar items.

---

## 9. Dashboard

- Dashboard **consumes** `GET /api/calendar/upcoming`.  
- Does not own event CRUD or recurrence.  
- See [`DASHBOARD-BOUNDARY.md`](./DASHBOARD-BOUNDARY.md).

---

## 10. TradingCalendar vs User Calendar

```text
User Calendar (portfolio_calendar_events)
        │
        ├─ UI / occurrences / reminders (portfolio events)
        │
        └─ trade_holiday rows ──► TradingCalendar::isTradeHoliday
                                      │
                                      ▼
                         isEquitySessionDate / scheduled market-data gates
                         + trade-alert Telegram quiet days
```

**Do not confuse** User Calendar UI with a full exchange holiday product. Holidays are **admin-maintained** rows, not an NSE feed.

---

## 11. Deliberately not owned

- Auto-ingest from ledger/CA/alerts/recommendations  
- Broker calendar sync  
- Timed (intraday) events  
- Making Calendar the SoT for market open/close beyond stored holidays  

---

*End of Calendar boundary.*
