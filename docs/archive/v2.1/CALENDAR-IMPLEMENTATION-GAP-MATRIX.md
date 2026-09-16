# User Calendar — Implementation Gap Matrix

**Document:** V2.1 Retrospective GAP matrix  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companions:** SPEC, BOUNDARY, POLICY  

Statuses: **IMPLEMENTED** | **PARTIAL** | **MISSING** | **TEST GAP** | **DOCUMENTATION GAP** | **TECHNICAL DEBT** | **DEFERRED** | **OOS**

---

## Matrix

| Behaviour / requirement | Current implementation | Status | Evidence | Tests | Risk | Recommended future action |
|-------------------------|------------------------|--------|----------|-------|------|---------------------------|
| Portfolio event CRUD | CalendarEventService + API | **IMPLEMENTED** | Controller | `CalendarEventTest` | Low | None |
| Recurrence expansion | CalendarRecurrenceService | **IMPLEMENTED** | Service | Unit sample tests | Low | Expand recurrence matrix tests |
| Year-grid UI | CalendarPage | **IMPLEMENTED** | FE | Manual | Low | None |
| Presets F&O/Options | UI helpers | **IMPLEMENTED** | `calendarEvents.js` | — | Low (operator may confuse with exchange feed) | Keep help clear |
| Upcoming for Dashboard | `/calendar/upcoming` | **IMPLEMENTED** | API + Dashboard card | Feature test upcoming | Low | None |
| Telegram reminders | ReminderService + cron | **IMPLEMENTED** | Console 07:00 | **TEST GAP** | Med | Add reminder/dedup feature tests |
| Trade holidays | category + null profile | **IMPLEMENTED** | Migration 2026_08_04 | Feature tests | Low | None |
| Holidays → TradingCalendar | isTradeHoliday | **IMPLEMENTED** | TradingCalendar | TradingCalendarTest | Low | None |
| Profile isolation | visibleToProfile | **IMPLEMENTED** | Model + service | Feature test | Low | None |
| Formal V2.1 pack | This pack | **DOCUMENTATION GAP** → **addressed** | WS-C B4 | — | — | Maintain on behaviour change |
| Help topic | `appDocumentation.js` calendar | **IMPLEMENTED** | Help | — | Low | Keep synced |
| Auto events from ledger/CA/alerts/recs | Absent | **OOS** / **MISSING** as product | Inventory | — | — | Do not invent in Calendar |
| Exchange holiday import | Absent | **OOS** / **DEFERRED** | — | — | — | Separate initiative if needed |
| Event retention | None | **DEFERRED** | — | — | Low | Decide only if storage pressure |
| Timed (intraday) events | Absent | **OOS** | Date-only model | — | — | — |
| Reminder coverage for holidays | Skipped (no profile) | **CURRENT** | ReminderService | — | Low | Documented |
| Full recurrence edge-case suite | Partial | **TEST GAP** | 2 unit cases | Med | Add cases for weekly/daily/end date |
| Filter/search UI | Absent | **MISSING** / low need | — | — | Low | DEFERRED unless requested |

---

## Findings (read-only)

| ID | Finding | Severity | Action in this pass |
|----|---------|----------|---------------------|
| **F-UC-1** | No auto-derived events from transactions/CA/alerts/recommendations despite common product expectation | Clarification, not defect | Documented as NOT FOUND / OOS |
| **F-UC-2** | F&O/Options presets are static recurrence templates, not exchange-synced | Operator clarity | Documented (UC-08) |
| **F-UC-3** | Reminder path lacks dedicated automated tests | TEST GAP | Documented; not fixed |

**No serious financial defect.** Calendar does not mutate ledger/cash.

---

## Gap priority (docs/tests only)

| Priority | Item | Class |
|----------|------|-------|
| P1 | Maintain this pack when calendar behaviour changes | DOCUMENTATION |
| P2 | Reminder send/dedup feature tests | TEST GAP |
| P2 | Broader recurrence unit matrix | TEST GAP |
| — | Exchange holiday import / auto ledger events | OOS / future product |

---

## Confirmation

- Reflects **CURRENT** runtime as of 2026-08-10.  
- No application/test/schema/frontend changes in this pass.  
- No V3; no V2 initiative reopened.  

---

*End of gap matrix.*
