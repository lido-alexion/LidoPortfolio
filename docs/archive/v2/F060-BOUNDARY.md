# F060 Boundary — Shared Screener Import

**Date:** 2026-08-09  
**Status:** **`F060_COMPLETE_WITH_NON_BLOCKERS`** (hardening delivered)  
**Purpose:** Prevent scope bleed between V1 screener CRUD/evaluation, portfolio/profile AuthZ, registry artifacts, Discovery/eligibility consumption, and F060 shared-import product semantics.  
**Related:** [F060-SHARED-SCREENER-IMPORT-SPEC.md](./F060-SHARED-SCREENER-IMPORT-SPEC.md), [F060-POLICY-DECISIONS.md](./F060-POLICY-DECISIONS.md), [F060-IMPLEMENTATION-GAP-MATRIX.md](./F060-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram (DECIDED — implemented)

```text
┌────────────────────────────────────────────────────────────────────┐
│ Authenticated user + active portfolio                              │
└───────────────────────────────┬────────────────────────────────────┘
                                ▼
┌────────────────────────────────────────────────────────────────────┐
│ V1 Screener CRUD — profile_id ownership; owner-only write/delete   │
└───────────────────────────────┬────────────────────────────────────┘
                                │ is_shared=true
                                ▼
┌────────────────────────────────────────────────────────────────────┐
│ F060 — same-user shared surfaces                                   │
│  Classic + Registry list/GET/import                                │
│  Filter: is_shared AND other profile AND profile.user_id = auth    │
│  Exposure: name + definition_json (+ id / minimal registry flags)  │
└───────────────┬─────────────────────────────┬──────────────────────┘
                │ import fork                 │ ownedOrSameUserShared
                ▼                             ▼
┌───────────────────────────┐   ┌────────────────────────────────────┐
│ Local copy on active      │   │ Eligibility / Discovery /          │
│ profile; no sync          │   │ BacktestSimulationEngine pin       │
└───────────────────────────┘   └────────────────────────────────────┘
```

---

## 2. V1 owns / F060 owns / does not own

Unchanged from policy closure: V1 owns model/CRUD/evaluation engines; F060 owns same-user sharing AuthZ, exposure, import fork naming, dual-API alignment. Does **not** own RBAC/tenants/F137/reopening completed initiatives.

---

## 3. Boundary rules (enforced)

1. Same-user only for all shared read/import/resolve paths.  
2. Classic ↔ registry identical AuthZ.  
3. Ownership stays `profile_id`; user via profile→user.  
4. Import is fork-only.  
5. PD-10 / PD-22 remain non-blocking CURRENT.  
6. Do not reopen F003/F005/F019/F014/F042/F043/F127.
