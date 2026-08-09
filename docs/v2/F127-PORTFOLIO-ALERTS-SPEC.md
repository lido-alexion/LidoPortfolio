# F127 — Portfolio Alerts / Monitoring

**Date:** 2026-08-09  
**Status:** **READY_FOR_IMPLEMENTATION** (product policies closed; hardening not started)  
**V2 initiative:** Monitoring & Alerts (Phase 2 remainder after F042/F043)  
**Classification:** Deferred from V1 by SD-035; **substantially implemented** in code  
**Related:** [F127-BOUNDARY.md](./F127-BOUNDARY.md), [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md), [F127-IMPLEMENTATION-GAP-MATRIX.md](./F127-IMPLEMENTATION-GAP-MATRIX.md)

---

## Status legend used in this document

| Label | Meaning |
|-------|---------|
| **CURRENT** | Observed shipped behaviour |
| **DECIDED** | Approved V2 target in the policy register |
| **DEFERRED** | Recognized; postponed |
| **OUT_OF_SCOPE** | Explicitly excluded from F127 |

Where **CURRENT** and **DECIDED** differ, hardening MUST implement **DECIDED** (see PD-F127-07).

Initiative readiness: **READY_FOR_IMPLEMENTATION**.

---

## 1. Purpose

Define authoritative V2 requirements for **Portfolio Alerts / Monitoring (F127)**:

Users configure **alert policies** over **current open portfolio holdings**, the system **evaluates** those policies, **persists** alerts, and delivers them through **existing in-app** and **Telegram** mechanisms — without redesigning the V1 notification transport or absorbing TOS / adjacent alert products.

---

## 2. Scope

### In scope

- Alert policy CRUD (create, read, update, delete)
- Policy enable / disable
- Holding-based condition model and formula evaluation helpers
- Policy evaluation (daily market-data workflow + manual Run now)
- Alert creation and persistence (`portfolio_alerts`)
- Duplicate suppression for active alerts
- Acknowledgement and expiration
- Implicit re-arm after expiration (**DECIDED** PD-F127-11)
- In-app alert display and actions
- Telegram **repeated** digest notifications (**DECIDED** PD-F127-09)
- Scheduling / weekend / holiday interaction with digests
- Active-profile scoping and authorization
- Alert lifecycle (including DECIDED expire→evaluate daily order)

### Explicitly out of scope

- TOS recommendation notifications
- India VIX alerts
- Administrative operational alerts
- Screener Telegram / calendar reminder Telegram
- Email, webhook, SMS (**OUT_OF_SCOPE** — SD-009 / PD-F127-01)
- RBAC / multi-tenancy
- Notification-stack redesign
- F042 / F043 implementation
- Free-form boolean DSL (**DECIDED** PD-F127-03 forbid)
- Crossing/edge detection (**DECIDED** PD-F127-05 forbid)
- Intraday/continuous evaluation (**DECIDED** PD-F127-06 forbid)
- Account-wide alert aggregation (**DECIDED** PD-F127-16 forbid)
- Built-in/`is_system` policy expansion (**DEFERRED** PD-F127-19)

---

## 3. Actors

| Actor | Capabilities |
|-------|----------------|
| Authenticated portfolio user | Manage policies and alerts on the **active** portfolio they can access |
| Admin | Same F127 self-service on own portfolios only; **no** admin cross-portfolio F127 privilege (**DECIDED** PD-F127-17) |
| Guest | No F127 APIs |

---

## 4. CURRENT implementation (inventory summary)

*Descriptive. Normative targets are in §6 and the policy register.*

| Area | CURRENT evidence |
|------|------------------|
| Policies | `AlertPolicy` / `portfolio_alert_policies`; CRUD APIs + UI |
| Evaluation | `AlertPolicyEvaluationService`; daily via `DailyMarketDataJob`; manual evaluate |
| Fields / formulas | `HoldingFieldRegistry`, `FormulaEvaluator`, message/context renderers |
| Alerts | `portfolio_alerts`; Dashboard + alerts API |
| Expiration | ack, expire-all, max-age 100h, trading-day, holding_closed |
| Telegram digest | `AlertNotificationService`; schedules HH:mm |
| Daily order | **CURRENT:** evaluate → expire (**DECIDED** changes this — PD-F127-07) |

---

## 5. Lifecycle

### CURRENT (shipped until hardened)

```text
… → evaluate enabled policies → (may duplicate_active)
  → expire trading-day / other reasons
  → digest consumes whatever remains active
```

### DECIDED V2 daily lifecycle (PD-F127-07)

```text
1. Expire stale/closed alerts
2. Evaluate enabled policies against current open holdings
3. Create / reuse active alerts (instance_key dedup)
4. Scheduled Telegram digest consumes the resulting active set
```

Manual Run now evaluates the active portfolio without inventing a second product model.

**Re-arm (DECIDED):** After expiration, a later evaluation MAY create a new instance while the condition remains true — no false→true requirement (PD-F127-11).

---

## 6. Requirements

### MUST

| ID | Requirement |
|----|-------------|
| F127-R001 | Authenticated users SHALL manage alert policies only for portfolios they are authorized to activate; F127 visibility SHALL be **active-profile** scoped (**PD-F127-16**, **PD-F127-17**). |
| F127-R002 | The product SHALL provide CRUD for portfolio alert policies, including enable/disable. |
| F127-R003 | Disabled policies SHALL NOT generate new alerts during evaluation. |
| F127-R004 | Evaluation SHALL recalculate holdings before applying policies. |
| F127-R005 | When left or right numeric operands cannot be resolved, evaluation SHALL NOT create an alert for that holding/policy pair. |
| F127-R006 | Successful matches SHALL persist an alert associated with policy, profile, and stock. |
| F127-R007 | While an alert with the same active `instance_key` exists, evaluation SHALL NOT create another active duplicate. |
| F127-R008 | Users SHALL view active alerts for the active portfolio in-app. |
| F127-R009 | Users SHALL acknowledge an alert only when the holding still exists; clear-all SHALL expire actives per expiration rules (**PD-F127-12**, **PD-F127-13**). |
| F127-R010 | Channels SHALL be in-app + Telegram only (**PD-F127-01**). |
| F127-R011 | F127 SHALL NOT implement email, webhook, or SMS. |
| F127-R012 | F127 SHALL NOT own or redesign TOS recommendation notifications. |
| F127-R013 | F127 SHALL NOT own India VIX, admin operational, or screener Telegram products. |
| F127-R014 | F127 SHALL consume shared `TelegramNotificationService`. |
| F127-R015 | F127 SHALL NOT hard-depend on F042/F043 APIs or add DQ/repair gating (**PD-F127-18**). |
| F127-R016 | F127 SHALL NOT introduce RBAC or multi-tenancy. |
| F127-R017 | F127 APIs SHALL remain Sanctum SPA session authenticated. |
| F127-R018 | Condition model SHALL be holding field + operator (`gt`/`lt`/`eq`) + comparison (column / derived formula / constant). Free-form boolean DSL SHALL NOT be introduced (**PD-F127-03**). |
| F127-R019 | Evaluation universe SHALL be **current open portfolio holdings** only (**PD-F127-04**). |
| F127-R020 | Conditions SHALL use **level** semantics (current values). Crossing/edge detection SHALL NOT be introduced (**PD-F127-05**). |
| F127-R021 | Automatic evaluation SHALL run as part of the daily market-data workflow; manual Run now SHALL remain. Intraday/continuous evaluation SHALL NOT be added (**PD-F127-06**). |
| F127-R022 | In the daily market-data workflow, alert lifecycle ordering SHALL be: **expire → evaluate → create/reuse active alerts**; scheduled digests SHALL consume the resulting active set (**PD-F127-07**). |
| F127-R023 | Scheduled Telegram digests SHALL NOT run on non-equity-session days (**PD-F127-08**). |
| F127-R024 | While an F127 alert remains active, it MAY appear in every configured Telegram digest slot (repeated digest). Once-only delivery SHALL NOT be required. `is_sent` is legacy/dead and SHALL NOT be treated as delivery source of truth (**PD-F127-09**). |
| F127-R025 | Empty `notification_schedules` SHALL mean in-app alerts continue and no Telegram digest is sent (**PD-F127-10**). |
| F127-R026 | After expiration, a later evaluation MAY create a new alert instance while the condition remains true (implicit re-arm). False→true transition SHALL NOT be required (**PD-F127-11**). |
| F127-R027 | Expiration mechanisms SHALL include acknowledgement, clear-all, maximum age (100 hours), trading-day refresh, and holding closed (**PD-F127-12**). |
| F127-R028 | When a holding is sold/closed, its active F127 alerts SHALL expire with `holding_closed` (**PD-F127-14**). |
| F127-R029 | A recreated/open-again holding MAY establish a new alert lifecycle; the prior closed instance SHALL NOT be preserved across reopen (**PD-F127-15**). |
| F127-R030 | Admins SHALL NOT receive special cross-portfolio F127 privileges (**PD-F127-17**). |

### SHOULD

| ID | Requirement |
|----|-------------|
| F127-R040 | Feature tests SHOULD cover expire→evaluate daily ordering (**PD-F127-07**). |
| F127-R041 | Feature tests SHOULD cover repeated Telegram digest semantics (**PD-F127-09**). |
| F127-R042 | Contextual help SHOULD describe F127 vs TOS and schedule/digest behaviour (PD-F127-21). |
| F127-R043 | Condition UI SHOULD avoid presenting non-comparable columns as numeric-capable (PD-F127-20). |

### MUST NOT

| ID | Requirement |
|----|-------------|
| F127-R050 | F127 SHALL NOT rebuild a parallel generic notification framework. |
| F127-R051 | F127 SHALL NOT claim ownership of F042/F043. |
| F127-R052 | Hardening SHALL NOT silently change DECIDED semantics beyond the approved register. |

---

## 7. Acceptance criteria

| ID | Criterion | Notes |
|----|-----------|-------|
| F127-AC001 | Policy CRUD only for accessible active portfolio | CURRENT proven |
| F127-AC002 | Disabled policy creates no new alerts | CURRENT |
| F127-AC003 | Match + no active duplicate → one alert with `instance_key` | CURRENT |
| F127-AC004 | Active duplicate → `duplicate_active`, no second active row | CURRENT |
| F127-AC005 | Missing numeric operands → no alert | CURRENT |
| F127-AC006 | Active alerts visible in-app for active portfolio | CURRENT |
| F127-AC007 | Ack requires holding; clear-all expires actives | CURRENT = DECIDED |
| F127-AC008 | Sold holding expires alerts with `holding_closed` | CURRENT = DECIDED |
| F127-AC009 | Digests skip non-session days; empty schedules → no digest; active alerts may repeat each slot | CURRENT = DECIDED |
| F127-AC010 | Daily workflow uses **expire then evaluate** so a still-true condition can yield an active alert in the same cycle after trading-day expiry | **Hardening required** (differs from CURRENT) |
| F127-AC011 | After expire, later eval may recreate while still true | CURRENT = DECIDED |
| F127-AC012 | Sanctum SPA auth unchanged | CURRENT |
| F127-AC013 | No F042/F043 hard dependency in F127 paths | CURRENT = DECIDED |
| F127-AC014 | Foreign user cannot acknowledge another user’s alert | CURRENT tested |
| F127-AC015 | Condition model remains fixed field+op+compare; no DSL | CURRENT = DECIDED |
| F127-AC016 | Universe remains open holdings only | CURRENT = DECIDED |

---

## 8. Determinism / idempotency

| Topic | DECIDED / CURRENT |
|-------|-------------------|
| `instance_key` | `{user_id}-{profile_id}-{stock_id}-{policy_id}` — preserve |
| Active duplicate suppression | MUST (R007) |
| Re-arm | Implicit after expire (PD-F127-11) |
| Telegram | Repeated digest of actives (PD-F127-09) |
| `is_sent` | Legacy/dead — not delivery SoT |
| Daily order | **DECIDED** expire→evaluate (implement in hardening) |
| Concurrency | No invented distributed lock guarantees |

---

## 9. Security

Profile-scoped via `activePortfolio()`. No admin cross-portfolio F127 access. No RBAC (**PD-F127-17**).

---

## 10. V1 / V2 boundary

Preserve SD-009, TOS stack, profile ownership, Telegram transport, daily sync/OHLCV/holdings, cron timezone. F127 hardens around them; does not redesign them.

---

## 11. Dependencies

Hard: V1 Sanctum, profiles, holdings, OHLCV/daily sync, Telegram transport, TradingCalendar.  
Soft: F042/F043 price quality only (**PD-F127-18**).

---

## 12. Open decisions

**None** blocking product behaviour. See [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md) for DEFERRED / NOT_A_POLICY_DECISION items (`is_system`, UX validation, help sync).

---

## 13. Implementation notes (non-normative)

1. Implement **PD-F127-07** ordering change in `DailyMarketDataJob` (and any equivalent path).  
2. Preserve repeated digests, holdings-only universe, level semantics, implicit re-arm.  
3. Add/extend tests for AC010 and related.  
4. Sync contextual help (PD-F127-21).  
5. Optional non-blocker: numeric-capable condition UX (PD-F127-20).  
6. Do not remove `is_sent` unless separately scoped and proven safe.

---

*End of F127 specification.*
