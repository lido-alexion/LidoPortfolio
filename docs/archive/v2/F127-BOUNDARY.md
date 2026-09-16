# F127 / Adjacent Notifications / Market Data Boundary

**Date:** 2026-08-09  
**Status:** **COMPLETE** (`F127_COMPLETE_WITH_NON_BLOCKERS`) — boundary fixed; hardening delivered  
**Purpose:** Prevent scope bleed between F127 portfolio alerts, TOS Telegram, adjacent alert products, and F042/F043 market-data ownership.  
**Related:** [F127-PORTFOLIO-ALERTS-SPEC.md](./F127-PORTFOLIO-ALERTS-SPEC.md), [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md), [F127-IMPLEMENTATION-GAP-MATRIX.md](./F127-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram

```text
┌─────────────────────┐     ┌─────────────────────┐     ┌─────────────────────┐
│ V2 F127             │     │ V1 TOS              │     │ Adjacent products   │
│ Portfolio alerts    │     │ Recommendation      │     │ (NOT F127)          │
│ policies + digest   │     │ NotificationEngine  │     │ India VIX           │
│ portfolio_alerts    │     │ tos_notifications   │     │ Admin operational   │
└──────────┬──────────┘     └──────────┬──────────┘     │ Screener Telegram   │
           │                           │                │ Calendar reminders  │
           └─────────────┬─────────────┘                └──────────┬──────────┘
                         │                                         │
                         ▼                                         ▼
              ┌──────────────────────────┐          (same transport)
              │ Shared V1 infrastructure │◄─────────────────────────┘
              │ TelegramNotificationSvc  │
              │ Profile Telegram creds   │
              │ TradingCalendar          │
              │ Holdings / OHLCV         │
              │ Cron timezone            │
              └──────────────────────────┘

F042 / F043 = Market Data Quality (separate ownership; soft price input only)
```

---

## 2. F127 owns

| Capability |
|------------|
| Portfolio alert **policies** CRUD (`portfolio_alert_policies`) |
| Holding-condition evaluation (`AlertPolicyEvaluationService`, formula helpers) |
| Persisted portfolio alerts (`portfolio_alerts`) |
| In-app alert lifecycle (list, acknowledge, expire-all, expiration reasons) |
| Scheduled **portfolio alert digest** Telegram (`AlertNotificationService` / `portfolio:send-notifications`) |
| F127 alert policy UI (`/settings/alert-policies`) and Dashboard active-alert display for policy alerts |
| Formal V2 semantics for the above once policies close |

---

## 3. F127 does NOT own

| Capability | Owner |
|------------|-------|
| TOS recommendation Telegram / history | V1 `NotificationEngine` / `portfolio_tos_notifications` |
| India VIX threshold Telegram | `IndiaVixAlertService` (Indices settings) |
| Admin operational alerts | `AdminOperationalAlertService` / `/settings/admin-alerts` |
| Screener run Telegram | Screener scheduler (`telegram_enabled`) |
| Calendar reminder Telegram | `CalendarReminderService` |
| Email / webhook / SMS channels | **OUT_OF_SCOPE** (SD-009) |
| Generic Telegram transport redesign | V1 shared infra |
| F042 Data Quality issues / resolution | F042 |
| F043 OHLCV repair / F020 single-writer | F043 / V1 F020 |
| RBAC / multi-tenancy | OUT_OF_SCOPE |
| Decision pipeline scheduling | V1 TOS |

---

## 4. Shared infrastructure (must remain shared)

| Shared component | Used by | F127 rule |
|------------------|---------|-----------|
| `TelegramNotificationService` | F127, TOS, VIX, ops, screener, calendar | Consume; do not fork |
| Profile `telegram_bot_token` / `telegram_chat_id` | Multiple products | Same destination may receive independent messages |
| `TradingCalendar` | Daily sync, F127 digests, others | Reuse equity-session / holiday rules |
| Holdings calculation / enrichment | F127 evaluation, Dashboard, etc. | Read-only consumer for conditions |
| OHLCV / daily sync | Prices feeding holdings | Soft input; no F042/F043 ownership |
| Cron timezone / `PORTFOLIO_CRON_*` | Schedulers | V1 ops config |

---

## 5. Same Telegram destination — collision note

F127 and TOS (and other products) **MAY** use the same bot/chat credentials.

**CURRENT:** Independent notifications can arrive in the same chat with different schedules and formats.

**V2 stance for this pack:** Document the collision. **Do not** merge queues, suppress cross-product messages, or redesign transport in F127. Any product preference for quieter digests is a **lifecycle policy** (e.g. PD-F127-09), not a transport redesign.

---

## 6. F042 / F043

| Claim | Status |
|-------|--------|
| Hard dependency F127 → F042/F043 | **False** |
| Soft / informational | **True** — OHLCV quality affects holding fields |
| F127 may gate on DQ issues | **OUT_OF_SCOPE** unless a future policy elevates it (PD-F127-18) |

---

## 7. Anti-duplication rules

1. Do not re-implement TOS recommendation notifications inside F127.
2. Do not fold India VIX or admin ops into `portfolio_alert_policies`.
3. Do not add email/webhook/SMS under F127.
4. Do not move F042/F043 repair ownership into alert evaluation.
5. Do not introduce RBAC or tenancy for alert visibility.
6. Do not treat “notification history” (TOS audit UI) as the F127 digest ledger unless a future decision explicitly expands it (PD-F127 history gap).

---

## 8. Related documents

| Doc | Role |
|-----|------|
| [F127-PORTFOLIO-ALERTS-SPEC.md](./F127-PORTFOLIO-ALERTS-SPEC.md) | Normative requirements |
| [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md) | Decision register |
| [F127-IMPLEMENTATION-GAP-MATRIX.md](./F127-IMPLEMENTATION-GAP-MATRIX.md) | Gaps / backlog |
| [V2-DEPENDENCIES.md](./V2-DEPENDENCIES.md) | Initiative-level dependency notes (historical planning) |

---

*End of F127 boundary document.*
