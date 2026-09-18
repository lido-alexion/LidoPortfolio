# AUD-010 - Calendar / Holiday / Timezone Runtime Verification

## 1. Finding Recap

AUD-010 began as `RUNTIME_VERIFICATION_REQUIRED` because repository tests could
not establish that production was using a current official NSE equity holiday
dataset or that the deployed calendar reached execution-date calculations.

Production verification on 2026-09-18 establishes that chain without creating
holidays, changing overrides, executing recommendations, or running a manual
sync. The result is `IMPLEMENTED` with bounded operational observations noted
below.

## 2. Contract

FEAT-038 makes the official NSE Capital Market (CM) holiday feed the canonical
provider input. It retains global Admin correction, protects dates that have
already begun in IST, and never removes known state after a provider failure.
FEAT-039 consumes the same `TradingCalendar`: weekends and active Trade
Holidays are not equity sessions, and Day #1 / Day #2 are counted as eligible
NSE sessions rather than calendar days.

## 3. Production Runtime Context

AUD-009 already verified the scheduler invocation and business timezone. This
audit reused those facts: Laravel application time is UTC, the business
scheduler and server use `Asia/Kolkata`, and India has stable UTC+05:30 without
DST transitions.

`portfolio_calendar_events.anchor_date` and the persisted execution anchor and
eligible-date fields are SQL `date` columns. Holiday and session identity are
therefore exchange dates (`YYYY-MM-DD`), not UTC instants that can shift by a
day.

## 4. Holiday Provider

`NseHolidaySyncService` calls the official NSE endpoint:

```text
https://www.nseindia.com/api/holiday-master?type=trading
```

It requires the `CM` response array, maps `tradingDate` and `description`,
uses stable `CM:YYYY-MM-DD` keys, and sends a JSON accept header plus the StoX
user agent. The request has a 20-second timeout and two retry attempts with a
500 ms interval. This is `official`, not fallback or manual-only behavior.

A read-only production-host fetch succeeded with 20 CM records for 2026, from
15-Jan-2026 through 25-Dec-2026. No provider payload or credential was stored
in audit evidence.

## 5. Holiday Sync Runtime

The command is `portfolio:sync-nse-holidays`, scheduled weekly Sunday at 01:30
in the configured business timezone. Production NSE rows report
`last_synced_at` as 2026-09-13 01:30:02 IST, matching that cadence and six days
before this audit. The source service clears the `TradingCalendar` cache only
after successful reconciliation.

There is no dedicated holiday-sync application-log line in the inspected log
set. `last_synced_at` on every imported row is sufficient successful-sync
evidence here, but it is less convenient operational observability than a
separate run record.

## 6. Persisted Holiday Inventory

Production active global Trade Holidays:

| Measure | Result |
| --- | --- |
| Active / total rows | 20 / 20 |
| Years represented | 2026 only |
| Date range | 2026-01-15 to 2026-12-25 |
| Past/current at audit time | 14 |
| Future at audit time | 6 |
| 2026 NSE source rows | 20 |
| Admin overrides | 0 |

All active rows are `source=nse`; the representative past date 2026-09-14 and
future date 2026-10-02 have `external_key` values `CM:2026-09-14` and
`CM:2026-10-02`, respectively, with `sync_override=false` and the same Sunday
sync provenance.

## 7. Provenance / Overrides

Imported rows carry `source`, `external_key`, `sync_override`, and
`last_synced_at`. A unique `(source, external_key)` constraint prevents
duplicate CM dates. Admin edits to an NSE row set `sync_override=true`; an
Admin deletion deactivates that row and also sets the override. Future,
non-overridden dates absent from a successful provider response may be
deactivated. Existing rows at or before the current IST date are immutable to
automatic sync.

`NseHolidaySyncServiceTest` proves idempotent import, persistent Admin
correction, and future-only deactivation. `CalendarEventTest` proves global
holiday visibility and Admin-only mutation. This is the accepted false/missing
holiday recovery path: use a durable Admin correction rather than altering the
provider record or inferring a holiday from price data.

## 8. TradingCalendar Verification

Production `TradingCalendar` calculations matched the official CM data:

| Date | Expected | Session | Holiday | Normalize to session | Next session |
| --- | --- | ---: | ---: | --- | --- |
| 2026-09-11 (Friday) | Normal NSE session | true | false | 2026-09-11 | 2026-09-11 |
| 2026-09-13 (Sunday) | Weekend closed | false | false | 2026-09-11 | 2026-09-15 |
| 2026-09-14 (Ganesh Chaturthi) | Official CM holiday | false | true | 2026-09-11 | 2026-09-15 |
| 2026-09-15 (Tuesday) | Adjacent NSE session | true | false | 2026-09-15 | 2026-09-15 |
| 2026-10-02 (Gandhi Jayanti) | Future official CM holiday | false | true | 2026-10-01 | 2026-10-05 |

`normalizeToSessionDate()` is intentionally backward-looking; the next-session
helper is forward-looking. This explains the distinct Sunday/holiday results
without ambiguity.

## 9. IST / Timezone Verification

`RecommendationExecutionLifetime::TIMEZONE` is `Asia/Kolkata`; runtime
configuration reports a 09:15 start and 15:30 cutoff in that timezone. A
read-only runtime calculation with input `2026-09-11T20:00:00Z` produced IST
anchor date 2026-09-12, classification `day_1`, Day #1 2026-09-15, and Day #2
2026-09-16. The UTC date did not leak into exchange-date selection.

## 10. Day #1 / Day #2 Verification

At the real Friday/Monday-holiday boundary:

| Generated at | Anchor | Day #1 | Day #2 | Expiry |
| --- | --- | --- | --- | --- |
| 2026-09-11 14:00 IST | Day #0, 2026-09-11 | 2026-09-11 | 2026-09-15 | 2026-09-15 15:30 IST |
| 2026-09-11 16:00 IST | Day #1, 2026-09-11 | 2026-09-15 | 2026-09-16 | 2026-09-16 15:30 IST |

The Monday holiday and weekend do not consume an eligible-session opportunity.
There were no persisted production execution-intent rows at audit time, so
this is a real deployed service calculation plus focused lifecycle tests rather
than an observation of an in-flight recommendation.

## 11. Execution Integration

The runtime path is:

```text
RecommendationGenerationPipeline
-> RecommendationExecutionLifetime::initialize()
-> frozen anchor / eligible session dates
-> LiveBrokerExecutionService
-> RecommendationExecutionLifetime::isExecutionOpportunity()
-> TradingCalendar
```

`LiveBrokerExecutionService` checks the opportunity before preparing execution
and repeats it after fresh state revalidation, before broker submission. The
same calendar gate rejects weekends and active Trade Holidays. Future calendar
corrections can recalculate only future eligible dates; they never rewrite the
frozen anchor or a date whose IST day has begun.

## 12. Historical / Future Mutation Semantics

Provider sync preserves past/current existing NSE rows and all Admin overrides.
It may update or deactivate only future non-overridden provider rows after a
successful CM response. This matches FEAT-038 and supports FEAT-039's frozen
execution window semantics. No production state was mutated to test this;
service tests cover both the override and future-date branches.

Weekend special sessions, Muhurat trading, derivatives, settlement, commodity,
and BSE-specific calendars are not in the FEAT-038 scope. `TradingCalendar`
therefore treats weekends as closed; this is an `ACCEPTABLE_VARIATION` under
the frozen CM-equity contract, not a finding.

## 13. Failure / Stale Provider Semantics

An unavailable/non-CM provider response throws before reconciliation. Existing
calendar rows are unchanged, and manual Admin holiday entry remains the
documented fallback. The execution calendar consequently continues from the
last known state; it does not interpret a failed fetch as an empty holiday set.
This is the accepted FEAT-038 policy, not a fail-open removal of holidays.

## 14. Production Logs

The inspected application logs contained no holiday-sync success, failure,
parser, duplicate, or timezone lines. No provider failure was observed during
the safe fetch. Row-level `last_synced_at` provides the successful scheduled
sync evidence; dedicated sync-run logging would improve diagnostics but is not
required by the frozen contract.

## 15. Gap Register

| ID | Finding | Classification | Severity | Evidence |
| --- | --- | --- | --- | --- |
| CAL-001 | Official CM source, weekly sync, date-only persistence, provenance, and duplicate control | `IMPLEMENTED` | High | Official NSE fetch, production row inventory, source code, migration, sync tests |
| CAL-002 | Weekend/holiday session exclusion and forward/backward normalization | `IMPLEMENTED` | High | Production comparisons around 2026-09-14 and 2026-10-02; `TradingCalendarTest` |
| CAL-003 | IST anchor, two-session lifetime, cutoff, and execution-time calendar revalidation | `IMPLEMENTED` | High | Production derivation plus `RecommendationExecutionLifetimeTest` and live execution tests |
| CAL-004 | Future refresh, Admin override priority, and post-session immutability | `IMPLEMENTED` | High | Service rules and focused sync/lifetime tests |
| CAL-005 | Dedicated operational sync log/event stream is absent | `IMPLEMENTED_WITH_LIMITATION` | Low | Row-level `last_synced_at` proves success; logs did not add a separate run record |
| CAL-006 | Special/Muhurat weekend sessions | `ACCEPTABLE_VARIATION` | Low | Explicit FEAT-038 non-goal for the CM-equity calendar |

No confirmed High or Medium runtime defect was found.

## 16. Cross-Audit Evidence

- AUD-009: confirms the existing scheduler/runtime foundation now has a fresh
  production holiday-sync result.
- AUD-011: calendar/session gating is proven for the execution path; this does
  not replace broker reconciliation verification.
- AUD-006: calendar data can support scheduled reminder semantics; this audit
  did not verify a calendar reminder delivery.

## 17. Final AUD-010 Assessment

**Disposition: `IMPLEMENTED` (Medium severity, High confidence).**

Production reaches the official NSE CM source, holds a current 2026 dataset
with provenance, excludes real weekends and provider holidays from canonical
sessions, protects historical/overridden rows, and applies IST-aware calendar
sessions to the two-day execution lifecycle and final execution gate. Browser
Calendar presentation and future provider-change operation remain ordinary
runtime monitoring, not known defects.

`V5-REQ-014` and `V5-REQ-015` remain `IMPLEMENTED`; no downgrade is warranted.

## 18. Open Questions

- Is a dedicated persisted sync-run/error record desirable for operator
  diagnostics beyond row-level `last_synced_at`?
- Should a future product expansion add explicit NSE special-session/Muhurat
  support, which is outside the current CM-equity holiday contract?
