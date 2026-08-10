# V2 Roadmap (Planning)

**Date:** 2026-08-10 (closure housekeeping)  
**Program status:** **SD-035 V2 = CLOSED**  
**Authoritative snapshot:** [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md)

All **eleven** SD-035 deferred capabilities are formally reconciled and closed. There is **no remaining Phase 1/2/3/4 implementation work under SD-035**. Deferred enhancements and non-blocking polish listed in initiative packs are **not** unfinished SD-035 initiatives.

| Track | Features | Final status |
|-------|----------|--------------|
| Account & Access | F003, F005 | **CLOSED** (`F003_COMPLIANT_WITH_NON_BLOCKERS`; `F005_COMPLETE_WITH_NON_BLOCKERS`) |
| Market Data Quality | F042, F043 | **CLOSED** (`F042_COMPLETE_WITH_NON_BLOCKERS`; `F043_COMPLETE`) |
| Monitoring | F127 | **CLOSED** (`F127_COMPLETE_WITH_NON_BLOCKERS`) |
| Portfolio History & Import | F019, F014 | **CLOSED** (`F019_COMPLETE_WITH_NON_BLOCKERS`; `F014_COMPLETE_WITH_NON_BLOCKERS`) |
| Collaboration | F060 | **CLOSED** (`F060_COMPLETE_WITH_NON_BLOCKERS`) |
| Recommendation Preview | F137 | **CLOSED** (`F137_COMPLETE_WITH_NON_BLOCKERS`) |
| Knowledge & Guidance | F143, F144 | **CLOSED** (`F143_COMPLETE_WITH_NON_BLOCKERS`; `F144_COMPLETE_WITH_NON_BLOCKERS`) |

**V1:** Frozen at 119 capabilities (SD-035) — [final audit](../audits/2026-08-09-feature-coverage-final/)

---

## V2 closure (current)

- **Option A — V2 CLOSED** per [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md).  
- Do **not** start a new SD-035 initiative.  
- Do **not** invent F145+ from residual polish.  
- Future work = maintenance / bugfix / separately specified product phases.  
- Residual items (tests, docs drift, deferred product enhancements such as F014 cash-as-of, F005 admin force-logout, F127 extra channels, F144 sharing) remain **non-blocking / deferred / OOS** as already classified in packs.

---

## Roadmap principles (still valid)

1. **Do not reopen V1 scope** — V1 remains frozen.  
2. **SD-035 V2 work was primarily formalization/hardening** of shipped or near-shipped surfaces.  
3. **Historical dependencies were respected** — F042→F043, F019→F014, F003→F060, V1 pipeline→F137 (all **satisfied**).  
4. **Avoid duplicate infrastructure** — alerts, notifications, auth, DQ paths already exist.  
5. **Do not reopen closed initiatives** for optional non-blockers.

---

## Historical planning — phases (delivery complete)

*The phase structure below is **historical planning context** retained for traceability. Delivery notes reflect final CLOSED status. Do not treat “Phase N work remaining” as current.*

### Phase 1 — Foundational (historical)

#### Track A: Account & Access Management — **DELIVERED / CLOSED**

| Feature | Historical work type | Final status |
|---------|----------------------|--------------|
| **F003** User invite flow | Formalize V2 spec; harden token/expiry; align admin UX | **CLOSED** |
| **F005** Session management | Complete Settings UI; extend `AuthSessionTest`; PD-006 | **CLOSED** |

**Historical unlocks:** F060 collaboration semantics; multi-user ops — **satisfied**.

#### Track B: Market Data Quality — **DELIVERED / CLOSED**

| Feature | Final status |
|---------|--------------|
| **F042** Data quality detection/resolution | **CLOSED** (`F042_COMPLETE_WITH_NON_BLOCKERS`) |
| **F043** Corporate action price repair | **CLOSED** (`F043_COMPLETE`) |

**F043 deferred / non-blocker (not incomplete SD-035):** admin API/UI; scheduled auto-repair; rollback snapshots; dividend/rights/merger; true multi-process concurrency suite (SQLite lock soft).

---

### Phase 2 — Operations & monitoring (historical) — **DELIVERED / CLOSED**

| Feature | Final status |
|---------|--------------|
| **F043** | **CLOSED** (see above) |
| **F127** Portfolio alerts (non-TOS) | **CLOSED** (`F127_COMPLETE_WITH_NON_BLOCKERS`) |

---

### Phase 3 — Portfolio data & platform APIs (historical) — **DELIVERED / CLOSED**

| Feature | Final status |
|---------|--------------|
| **F019** Bulk CSV import | **CLOSED** (`F019_COMPLETE_WITH_NON_BLOCKERS`) |
| **F014** Historical holdings reconstruction | **CLOSED** (`F014_COMPLETE_WITH_NON_BLOCKERS`) |
| **F060** Shared screener import | **CLOSED** (`F060_COMPLETE_WITH_NON_BLOCKERS`) |
| **F137** Recommendation preview API | **CLOSED** (`F137_COMPLETE_WITH_NON_BLOCKERS`) |

---

### Phase 4 — Knowledge & guidance (historical) — **DELIVERED / CLOSED**

| Feature | Final status |
|---------|--------------|
| **F143** In-app contextual help | **CLOSED** (`F143_COMPLETE_WITH_NON_BLOCKERS`) — formalization of pre-shipped runtime |
| **F144** Knowledge Board | **CLOSED** (`F144_COMPLETE_WITH_NON_BLOCKERS`) — formalization of pre-shipped runtime |

---

## Phase summary (final)

| Phase | Features | Delivery note (current) |
|-------|----------|-------------------------|
| **1** | F003, F005, F042 | **ALL CLOSED** |
| **2** | F043, F127 | **ALL CLOSED** |
| **3** | F019, F014, F060, F137 | **ALL CLOSED** |
| **4** | F143, F144 | **ALL CLOSED** |

---

## Next work (not SD-035)

There is **no “next V2 initiative” under SD-035**.

Recommended posture:

1. Maintenance / bugfix / backlog.  
2. Optional documentation hygiene outside initiative packs (already largely addressed by this housekeeping).  
3. Any major new capability → **separate specification exercise**, not an invented continuation of the SD-035 eleven.

Do **not** reopen F042/F043/F127/F003/F005/F019/F014/F060/F137/F143/F144 core behaviour for optional non-blockers.

---

## Explicitly not in this roadmap

| Item | Reason |
|------|--------|
| V1 capability changes | V1 frozen |
| Email/webhook notifications | V1_OUT_OF_SCOPE (SD-009 Telegram-only) |
| F040 hard data publish gates | V1_OUT_OF_SCOPE (PB-001) |
| New capabilities beyond the 11 | Out of SD-035; require new programme if pursued |

---

*See also: [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md), [V2-PRIORITIZATION.md](./V2-PRIORITIZATION.md), [V2-DEPENDENCIES.md](./V2-DEPENDENCIES.md)*
