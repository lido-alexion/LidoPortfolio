# F127 Policy Decisions

**Date:** 2026-08-09  
**Status:** Policies **closed**. Initiative delivery: **COMPLETE** (`F127_COMPLETE_WITH_NON_BLOCKERS`)  
**Spec:** [F127-PORTFOLIO-ALERTS-SPEC.md](./F127-PORTFOLIO-ALERTS-SPEC.md)  
**Boundary:** [F127-BOUNDARY.md](./F127-BOUNDARY.md)  
**Gap matrix:** [F127-IMPLEMENTATION-GAP-MATRIX.md](./F127-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped code behaviour.  
**DECIDED** = approved V2 target (may differ from CURRENT where noted).  
Hardening must implement DECIDED targets; do not treat CURRENT alone as approval when they diverge.

---

## Final policy register

| Decision | Status |
|----------|--------|
| PD-F127-01 Channel set (in-app + Telegram only) | **DECIDED** |
| PD-F127-02 Ownership vs TOS / VIX / ops / screener | **DECIDED** |
| PD-F127-03 Condition model | **DECIDED** — keep fixed model; no free DSL |
| PD-F127-04 Universe | **DECIDED** — current open holdings only |
| PD-F127-05 Level vs edge | **DECIDED** — level only |
| PD-F127-06 Evaluation frequency | **DECIDED** — daily + manual Run now |
| PD-F127-07 Daily lifecycle ordering | **DECIDED** — expire → evaluate (implemented; CURRENT matches) |
| PD-F127-08 Weekend/holiday digests | **DECIDED** — keep skip non-session days |
| PD-F127-09 Telegram digest semantics | **DECIDED** — repeated digest; `is_sent` legacy/dead |
| PD-F127-10 Empty notification schedules | **DECIDED** — in-app only; no digest |
| PD-F127-11 Re-arm | **DECIDED** — implicit after expiration |
| PD-F127-12 Expiration mechanisms | **DECIDED** — keep existing set + 100h max age |
| PD-F127-13 Acknowledgement | **DECIDED** — requires holding still exists |
| PD-F127-14 Sold holdings | **DECIDED** — expire `holding_closed` |
| PD-F127-15 Recreated positions | **DECIDED** — new alert lifecycle |
| PD-F127-16 Multi-portfolio | **DECIDED** — active profile only |
| PD-F127-17 Authorization | **DECIDED** — profile ownership; no admin F127 cross-read; no RBAC |
| PD-F127-18 F042/F043 | **DECIDED** — soft / informational; no gating |
| PD-F127-19 System / built-in policies (`is_system`) | **DEFERRED** |
| PD-F127-20 Non-numeric condition columns | **NOT_A_POLICY_DECISION** |
| PD-F127-21 Help / documentation sync | **NOT_A_POLICY_DECISION** |

---

## Summary table

| Decision | CURRENT (code) | Approved V2 target | Status |
|----------|----------------|--------------------|--------|
| PD-F127-01 | In-app + Telegram | Keep; no email/webhook/SMS | **DECIDED** |
| PD-F127-02 | Separate products, shared transport | Keep separate ownership | **DECIDED** |
| PD-F127-03 | Fixed column + op + compare | **Keep**; no free-form boolean DSL | **DECIDED** |
| PD-F127-04 | Open holdings only | **Keep**; no strategy/index/historical/arbitrary universe | **DECIDED** |
| PD-F127-05 | Level each eval | **Keep** level; no edge/crossing | **DECIDED** |
| PD-F127-06 | Daily sync + manual | **Keep**; no intraday/continuous | **DECIDED** |
| PD-F127-07 | Expire → evaluate → create/reuse → digest consumes actives *(was evaluate → expire pre-hardening)* | **Expire → evaluate → create/reuse → digest consumes actives** | **DECIDED** |
| PD-F127-08 | Skip digests non-session days | **Keep** | **DECIDED** |
| PD-F127-09 | Repeat digest every slot; `is_sent` dead | **Keep repeated digest**; `is_sent` legacy/dead | **DECIDED** |
| PD-F127-10 | Empty schedules → no digest | **Keep** | **DECIDED** |
| PD-F127-11 | Recreate after expire while still true | **Keep** implicit re-arm; no false→true requirement | **DECIDED** |
| PD-F127-12 | Ack, clear-all, 100h, trading-day, holding_closed | **Keep** | **DECIDED** |
| PD-F127-13 | Ack requires still holding | **Keep** | **DECIDED** |
| PD-F127-14 | Expire `holding_closed` on sell | **Keep** | **DECIDED** |
| PD-F127-15 | Rebuy can alert again | **New lifecycle** after closed-and-reopened | **DECIDED** |
| PD-F127-16 | Active profile only | **Keep**; no account-wide aggregation | **DECIDED** |
| PD-F127-17 | Profile scope; no admin F127 cross-read | **Keep**; no RBAC | **DECIDED** |
| PD-F127-18 | No DQ/repair coupling | Soft only; no F042/F043 gating | **DECIDED** |
| PD-F127-19 | `is_system` unused | Do not expand now | **DEFERRED** |
| PD-F127-20 | Non-numeric in registry | UX/validation harden | **NOT_A_POLICY_DECISION** |
| PD-F127-21 | Help exists | Sync during harden | **NOT_A_POLICY_DECISION** |

---

## PD-F127-01 — Channel set

**Status:** **DECIDED**

**Approved target:** In-app + Telegram only. Email/webhook/SMS **OUT_OF_SCOPE** (SD-009 / V2 roadmap).

---

## PD-F127-02 — Ownership vs adjacent products

**Status:** **DECIDED**

**Approved target:** Keep ownership as in [F127-BOUNDARY.md](./F127-BOUNDARY.md). F127 does not absorb TOS / VIX / ops / screener. Same-chat independent messages remain allowed.

---

## PD-F127-03 — Condition model

**Status:** **DECIDED**

**CURRENT:** Fixed left holding field + operator (`gt`/`lt`/`eq`) + comparison (`column` | derived formula | constant).

**Approved target:** **KEEP** this model. Comparison value MAY be a registered derived formula or constant. **Do NOT** introduce a free-form boolean DSL.

---

## PD-F127-04 — Universe

**Status:** **DECIDED**

**CURRENT:** Open portfolio holdings (`quantity > 0`).

**Approved target:** F127 remains limited to **current open portfolio holdings**. No strategy, transaction, index, historical-holding, or arbitrary-stock universe in this initiative.

---

## PD-F127-05 — Level vs edge/crossing

**Status:** **DECIDED**

**CURRENT:** Level semantics each evaluation.

**Approved target:** **KEEP LEVEL** semantics. A policy evaluates whether the **current** value satisfies the condition. **Do NOT** introduce crossing/edge detection in F127.

---

## PD-F127-06 — Evaluation frequency

**Status:** **DECIDED**

**CURRENT:** Daily evaluation in the daily market-data workflow + manual Run now.

**Approved target:** **KEEP**. No intraday/continuous evaluation in F127.

---

## PD-F127-07 — Daily lifecycle ordering

**Status:** **DECIDED** (**implemented**; CURRENT matches approved target)

**Historical (pre-hardening):** In `DailyMarketDataJob`, **evaluate** then **expire** (`expireBeforeTradingDay`). Consequence: an already-active condition may yield `duplicate_active`, then trading-day expiry clears actives → a cycle can end with **no** active alert even when the condition remains true.

**CURRENT / approved:**

1. Expire stale/closed alerts  
2. Evaluate enabled policies against current holdings  
3. Create/reuse active alerts  
4. Scheduled notification consumes the resulting active alert set  

Manual Run now remains **evaluate-only** (no trading-day expiry in that path).

**Implementation note:** Delivered in `DailyMarketDataJob` (2026-08-09): on full sync success when the max portfolio price date advances, call `expireBeforeTradingDay` **before** `evaluateAllProfiles`.

---

## PD-F127-08 — Weekend / holiday notification behaviour

**Status:** **DECIDED**

**CURRENT / approved:** Scheduled Telegram digests do **not** run on non-equity-session days (`TradingCalendar`).

---

## PD-F127-09 — Telegram digest once vs repeat

**Status:** **DECIDED**

**CURRENT / approved:** **KEEP REPEATED DIGEST**. An active F127 alert MAY appear in every configured Telegram notification slot while it remains active. **Do NOT** convert to once-only notification semantics.

**`is_sent`:** Legacy/dead — not the source of truth for F127 delivery state. Do not remove in this initiative unless a separately scoped change proves it safe.

---

## PD-F127-10 — Empty notification schedules

**Status:** **DECIDED**

**CURRENT / approved:** No configured Telegram schedule means in-app alerts continue normally and **no** Telegram digest is sent.

---

## PD-F127-11 — Re-arm

**Status:** **DECIDED**

**CURRENT / approved:** **KEEP** implicit re-arm after expiration. If an alert expires while its condition remains true, a later evaluation MAY create a new alert instance. **Do NOT** require a false→true transition.

**Rationale:** Expiration ends an alert **instance**; it is not proof the underlying condition became false.

---

## PD-F127-12 — Expiration

**Status:** **DECIDED**

**CURRENT / approved:** Keep acknowledgement, clear-all, maximum age (**100 hours**), trading-day refresh, and holding closed.

---

## PD-F127-13 — Acknowledgement

**Status:** **DECIDED**

**CURRENT / approved:** Acknowledgement requires the holding to still exist.

---

## PD-F127-14 — Sold holdings

**Status:** **DECIDED**

**CURRENT / approved:** When a holding is closed/sold, active F127 alerts expire with `holding_closed`.

---

## PD-F127-15 — Recreated positions

**Status:** **DECIDED**

**Approved target:** A later newly created/recreated holding MAY establish a **new** alert lifecycle. Do not preserve the old alert instance across a closed-and-reopened position.

---

## PD-F127-16 — Multi-portfolio

**Status:** **DECIDED**

**CURRENT / approved:** Active profile/portfolio only. No account-wide aggregation.

---

## PD-F127-17 — Authorization

**Status:** **DECIDED**

**CURRENT / approved:** Profile-ownership scoped. Authenticated users manage/read/acknowledge alerts for the active profile they can access. **No** admin cross-portfolio F127 privilege. **No** RBAC.

---

## PD-F127-18 — F042 / F043

**Status:** **DECIDED**

**Approved target:** Soft/informational only. F127 does not directly call F042/F043 services and does not add DQ/repair gating. Consumes resulting portfolio/price data normally.

---

## PD-F127-19 — System / built-in policies (`is_system`)

**Status:** **DEFERRED**

Do not expand built-in/system policy semantics in this initiative.

---

## PD-F127-20 — Non-numeric condition fields

**Status:** **NOT_A_POLICY_DECISION**

UX/validation hardening only; not a product-policy blocker.

---

## PD-F127-21 — Help / documentation

**Status:** **NOT_A_POLICY_DECISION**

Keep contextual help synchronized when hardening is implemented.

---

## Blocking decisions

**None remaining** for product policy. Hardening delivered against DECIDED targets (including PD-F127-07 ordering). Initiative status: **`F127_COMPLETE_WITH_NON_BLOCKERS`**.

---

*End of F127 policy decisions.*  
*Product decisions closed 2026-08-09; hardening delivered → F127_COMPLETE_WITH_NON_BLOCKERS.*
