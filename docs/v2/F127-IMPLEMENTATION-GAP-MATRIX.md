# F127 Implementation Gap Matrix

**Date:** 2026-08-09  
**Status:** **READY_FOR_IMPLEMENTATION** — product policies closed; hardening not started  
**Initiative:** Monitoring & Alerts (F127)  
**Related:** [F127-PORTFOLIO-ALERTS-SPEC.md](./F127-PORTFOLIO-ALERTS-SPEC.md), [F127-BOUNDARY.md](./F127-BOUNDARY.md), [F127-POLICY-DECISIONS.md](./F127-POLICY-DECISIONS.md)

### Delivery summary

| Track | Status |
|-------|--------|
| F127 code framework | **Shipped** (CURRENT) |
| F127 formal V2 pack | **Present** |
| F127 product policies | **Closed** (blocking PDs DECIDED) |
| F127 hardening | **Not started** — primary delta: PD-F127-07 expire→evaluate |

### Classification legend

| Label | Meaning |
|-------|---------|
| `NO_GAP` | CURRENT matches DECIDED / preserve |
| `PARTIAL` | Exists; needs formal AC/tests/docs polish |
| `MISSING` | DECIDED behaviour not yet in code |
| `DEFERRED` | Postponed |
| `OUT_OF_SCOPE` | Excluded |
| `NOT_A_POLICY_DECISION` | UX/docs task, not product choice |

---

## Gap register

| ID | Area | Topic | CURRENT | Gap | Priority |
|----|------|-------|---------|-----|----------|
| F127-G001 | Docs | Formal pack | Present | **NO_GAP** | — |
| F127-G002 | Docs | Boundary | Present | **NO_GAP** | — |
| F127-G003 | Policy | Decision register | Closed | **NO_GAP** | — |
| F127-G004 | Lifecycle | Daily order expire→evaluate | Evaluate→expire | **MISSING** (PD-F127-07) | **P0** |
| F127-G005 | Notify | Repeated Telegram digest | Implemented | **NO_GAP** (DECIDED keep); prove AC | P1 |
| F127-G006 | Lifecycle | Implicit re-arm | Implemented | **NO_GAP** (DECIDED keep) | P1 |
| F127-G007 | Model | Holdings-only universe | Implemented | **NO_GAP** | — |
| F127-G008 | Model | Fixed condition + formula | Implemented | **NO_GAP** | — |
| F127-G009 | Product | CRUD / evaluate / Dashboard | Implemented | **NO_GAP** | — |
| F127-G010 | Security | Profile auth; no admin F127 cross-read | Implemented | **NO_GAP** / formal AC | P1 |
| F127-G011 | Tests | V2 ACs especially AC010 | Weak on order | **PARTIAL** | P0 |
| F127-G012 | Tests | Frontend automated UI | None | **DEFERRED** | P3 |
| F127-G013 | UX | `notifications_enabled` UI | Missing toggle | **PARTIAL** (non-blocker) | P2 |
| F127-G014 | UX | Non-numeric condition columns | Registry allows | **PARTIAL** (PD-F127-20) | P2 |
| F127-G015 | Audit | F127 in notification history | TOS only | **DEFERRED** / optional | P3 |
| F127-G016 | Data | F042/F043 soft only | None hard | **NO_GAP** | — |
| F127-G017 | Channels | Email/webhook | Absent | **OUT_OF_SCOPE** | — |
| F127-G018 | Boundary | TOS separation | Separate | **NO_GAP** | — |
| F127-G019 | Schedule | Weekend/holiday skip | Implemented | **NO_GAP** | — |
| F127-G020 | Schedule | Empty schedules = no digest | Implemented | **NO_GAP** | — |
| F127-G021 | Expire | Reasons + 100h | Implemented | **NO_GAP** | — |
| F127-G022 | Ack | Requires holding | Implemented | **NO_GAP** | — |
| F127-G023 | Holdings | `holding_closed` | Implemented | **NO_GAP** | — |
| F127-G024 | Holdings | Recreated = new lifecycle | Implicit | **NO_GAP** / test | P1 |
| F127-G025 | Scope | Active profile only | Implemented | **NO_GAP** | — |
| F127-G026 | Product | `is_system` expansion | Unused | **DEFERRED** | — |
| F127-G027 | Docs | Help sync | Partial | **PARTIAL** (on harden) | P2 |
| F127-G028 | Freq | Daily + manual | Implemented | **NO_GAP** | — |
| F127-G029 | Model | Level only | Implemented | **NO_GAP** | — |
| F127-G030 | Notify | `is_sent` legacy | Dead field | **NO_GAP** (document; do not remove unless separately scoped) | — |

---

## Rollup

| Category | Notes |
|----------|-------|
| P0 hardening | **G004** expire→evaluate; **G011** tests for AC010 |
| Preserve CURRENT = DECIDED | Digests, re-arm, universe, level, auth, expiration set |
| Non-blockers | Help sync, numeric UX, notifications_enabled UI, FE tests |
| Out of scope | Email/webhook, stack redesign, F042/F043 ownership, RBAC |

### Policy status snapshot

| Decision | Status | Code vs DECIDED |
|----------|--------|-----------------|
| PD-F127-01…06, 08–18 | **DECIDED** | Align (mostly already CURRENT) |
| PD-F127-07 | **DECIDED** | **Needs hardening** |
| PD-F127-19 | **DEFERRED** | — |
| PD-F127-20–21 | **NOT_A_POLICY_DECISION** | During harden |

---

## Recommended implementation order

1. **PD-F127-07** — reorder daily workflow to expire → evaluate → create/reuse; scheduled digest unchanged consumer of actives.  
2. Tests for F127-AC010 + regression on dedup / re-arm / digests.  
3. Help sync (boundary, repeated digest, empty schedules, expire→evaluate).  
4. Optional non-blockers: numeric condition UX; `notifications_enabled` visibility.  
5. Compliance audit → close F127.

Do **not** implement once-only Telegram, edge detection, DSL, intraday eval, account-wide inbox, email/webhook, or `is_system` seeds in this initiative.

---

*End of F127 gap matrix.*  
*Policies closed 2026-08-09 → READY_FOR_IMPLEMENTATION.*
