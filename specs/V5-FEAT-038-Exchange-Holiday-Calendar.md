# V5-FEAT-038 — Exchange holidays in Calendar with automatic sync

Status: **FROZEN / IMPLEMENTED**
Date: 2026-09-04

## Problem

StoX already had global admin-defined Trade Holidays and a canonical `TradingCalendar`, but official NSE holidays still required manual entry. Scheduling therefore depended on an operator maintaining the list.

## Frozen behaviour

- Global Trade Holidays remain visibly amber and appear on every portfolio Calendar.
- Admins may create, edit, deactivate, or delete manual holidays.
- StoX refreshes the official NSE **Capital Market (CM)** trading-holiday feed weekly and exposes a manual CLI command.
- Synced rows store source, stable external key, and last-sync time.
- Admin edits/deletes of an NSE row become durable overrides and are never overwritten or recreated by later syncs.
- A successful feed may create new holidays and refresh/deactivate future NSE dates.
- Once an exchange date begins in IST, an existing canonical row is immutable to automatic sync. Explicit admin correction remains available.
- Feed failure changes nothing; manual entry remains the fallback.

## Architecture

- Source: official NSE holiday-master JSON endpoint, segment `CM`.
- `NseHolidaySyncService` maps `tradingDate` and `description` into global `trade_holiday` events.
- `source=nse`, `external_key=CM:YYYY-MM-DD`, `sync_override`, and `last_synced_at` provide provenance/idempotency.
- `portfolio:sync-nse-holidays` runs weekly Sunday 01:30 in the configured application timezone.
- Existing `TradingCalendar` cache is cleared after every successful reconciliation.

## Algorithms

1. Fetch and validate a successful JSON response containing a CM array.
2. Parse every CM date and derive its stable key.
3. Preserve overridden rows and already-begun existing dates.
4. Upsert new/future rows; deactivate only future, non-overridden NSE rows absent from a successful feed.
5. Never modify stored rows on HTTP/schema/parser failure.

## UX

Existing Calendar UX remains authoritative: Trade Holiday badge, fixed amber marker, global visibility, and admin-only editing. Synced entries behave like other Trade Holidays; editing one freezes the correction against future imports.

## Acceptance criteria

- Official CM rows import idempotently and affect `TradingCalendar::isEquitySessionDate`.
- Portfolio users see global holidays but cannot mutate them.
- Admin corrections and deletions survive refresh.
- Missing future rows are deactivated only after a successful feed.
- Existing dates at or before today IST are not automatically rewritten.
- Scheduler/manual command, service, calendar, authorization, and UI behaviour remain tested.

## Dependencies

- Existing Calendar events/recurrence, global Trade Holiday category, and `TradingCalendar`.
- Official NSE endpoint availability; manual admin entry is the operational fallback.

## Non-goals

- Settlement, derivatives, currency, commodity, or special-session calendars.
- Inferring holidays from absent price bars.
- Removing manual administration.
- FEAT-039 execution lifecycle changes.
