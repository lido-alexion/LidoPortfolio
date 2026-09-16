# User Calendar — Policy Decisions (Retrospective)

**Document:** V2.1 Retrospective POLICY register  
**Date:** 2026-08-10  
**Status:** CURRENT formalization  
**Companions:** SPEC, BOUNDARY, GAP  

Status: **DECIDED** | **CURRENT** | **DECISION_REQUIRED** | **DEFERRED** | **OOS** | **TECHNICAL DEBT**

Do not invent policies. Document CURRENT behaviour only.

---

## Register

| ID | Topic | Status | Decision / CURRENT behaviour | Evidence |
|----|-------|--------|------------------------------|----------|
| **UC-01** | Event ownership | **CURRENT** | Calendar owns user + admin holiday rows; does not own ledger/CA/alert/rec events | Service inventory |
| **UC-02** | User-created events | **CURRENT** | Any auth user creates portfolio-scoped events | Controller + tests |
| **UC-03** | Recurring events | **CURRENT** | Seven recurrence types; occurrences computed on read | `CalendarRecurrenceService` |
| **UC-04** | Trade holidays | **CURRENT** | Admin-only global `trade_holiday`; visible everywhere; feeds TradingCalendar | Model + service + tests |
| **UC-05** | Notifications / reminders | **CURRENT** | Optional Telegram for portfolio events; daily 07:00 cron; dedup table; holidays skipped (no profile) | Reminder service + schedule |
| **UC-06** | Quiet days for trade-alert digests | **CURRENT** | Weekends + trade holidays skip scheduled trade-alert Telegram | `TradingCalendar` + notification path |
| **UC-07** | Timezone | **CURRENT** | Date-only events; scheduler uses `cron_timezone`; “today” via Carbon | Console schedule |
| **UC-08** | Presets vs exchange truth | **CURRENT** | F&O/Options presets are UX helpers, not live exchange calendars | `CALENDAR_EVENT_PRESETS` |
| **UC-09** | Auto-derived trading events | **OOS** / **NOT FOUND** | No auto events from transactions/CA/alerts/recs/watchlist | Code inventory |
| **UC-10** | Retention | **DEFERRED** / **NOT FOUND** | Events + reminder sends kept until deleted | Schema |
| **UC-11** | Exchange holiday feed | **OOS** / **DEFERRED** | Manual admin holidays only | — |
| **UC-12** | Calendar as presentation | **CURRENT** | Presentation + event store; market-session side effect only via holidays | BOUNDARY |
| **UC-13** | Reminder test coverage | **TECHNICAL DEBT** | Dedicated reminder tests thin/absent | Test inventory |

---

## Canonical ownership statement

**Status: CURRENT**

1. User Calendar is the SoT for **calendar event definitions** it stores.  
2. It is **not** SoT for transactions, cash, corporate actions, alerts, or recommendations.  
3. Occurrences are **derived** from definitions + recurrence rules.  
4. Global trade holidays are **also** inputs to `TradingCalendar` session/quiet-day checks.

---

## Open items

| ID | Ask | Notes |
|----|-----|-------|
| UC-10 | Retain forever vs purge old reminder_sends? | No product pressure — **DEFERRED** |
| UC-11 | Import NSE holiday calendar? | Would be new product work — **OOS** here |

No blocking `DECISION_REQUIRED` to describe CURRENT behaviour.

---

## Out of scope

- Adding reminders channels beyond existing Telegram  
- Redesigning recurrence  
- Auto-generating events from other domains  
- V3 calendar marketplace  

---

*End of policy register.*
