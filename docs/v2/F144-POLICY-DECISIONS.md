# F144 Policy Decisions — Knowledge Board

**Date:** 2026-08-10  
**Status:** **CLOSED for blocking product decisions** — no `DECISION_REQUIRED`  
**Verdict:** **`F144_COMPLETE_WITH_NON_BLOCKERS`**  
**Spec:** [F144-KNOWLEDGE-BOARD-SPEC.md](./F144-KNOWLEDGE-BOARD-SPEC.md)  
**Boundary:** [F144-BOUNDARY.md](./F144-BOUNDARY.md)  
**Gap matrix:** [F144-IMPLEMENTATION-GAP-MATRIX.md](./F144-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = shipped implementation + tests.  
Do **not** invent product decisions. Prefer CURRENT over stale planning.

Closed initiatives must not be reopened.

---

## Classification key

| Status | Meaning |
|--------|---------|
| **DECIDED** | Established by CURRENT product behaviour (or explicit prior pattern) |
| **DECISION_REQUIRED** | Blocking PO choice — **none** |
| **DEFERRED** | Optional future |
| **OUT_OF_SCOPE** | Not F144 |
| **NOT_A_POLICY_DECISION** | Engineering/test/docs quality |

---

## Final register

| ID | Topic | Status | CURRENT / notes |
|----|-------|--------|-----------------|
| PD-F144-01 Scope | Profile-scoped Knowledge Board | **DECIDED** | Rows use `profile_id`; middleware `active.portfolio` |
| PD-F144-02 Note ownership | Active portfolio | **DECIDED** | Cross-profile → 404 |
| PD-F144-03 Tag ownership | Active portfolio | **DECIDED** | Unique `(profile_id, name)`; case-insensitive service check |
| PD-F144-04 Entity linking (stocks/strategies/txns) | Not in schema | **OUT_OF_SCOPE** (CURRENT = standalone) |
| PD-F144-05 Sharing / collaboration | Absent | **OUT_OF_SCOPE** |
| PD-F144-06 Edit/delete | Owner portfolio CRUD | **DECIDED** | Soft archive + hard delete of notes; tag delete strips pivot |
| PD-F144-07 Tag naming | Unique per profile, CI | **DECIDED** |
| PD-F144-08 Duplicate notes | Explicit duplicate action | **DECIDED** | Clears pin/archive on copy |
| PD-F144-09 Search | `q` over title/html/tag names | **DECIDED** |
| PD-F144-10 Tag filter | any / all / exclude | **DECIDED** |
| PD-F144-11 Sort | Server sorts + client manual order | **DECIDED** | Manual in `localStorage` |
| PD-F144-12 Pagination | None (full list) | **DECIDED** (CURRENT) | Server paging = optional eng later, not blocking PD |
| PD-F144-13 Retention | No TTL | **DECIDED** | Profile cascade deletes knowledge rows |
| PD-F144-14 Export | Client clipboard formats | **DECIDED** |
| PD-F144-15 Rich text / images | TipTap + upload | **DECIDED** |
| PD-F144-16 Attachments beyond images | Absent | **OUT_OF_SCOPE** |
| PD-F144-17 Audit history of note edits | Absent | **OUT_OF_SCOPE** |
| PD-F144-18 AI integration | Export “AI-friendly” only | **OUT_OF_SCOPE** as generation/SoT |
| PD-F144-19 `is_favorite` | API/DB; UI hidden | **NOT_A_POLICY_DECISION** / **DEFERRED** UI |
| PD-F144-20 Image orphan GC / delete API | Incomplete lifecycle | **NOT_A_POLICY_DECISION** (eng non-blocker) |
| PD-F144-21 Unarchive UX | API supports; UI thin | **NOT_A_POLICY_DECISION** |
| PD-F144-22 Cross-profile note AuthZ tests | Code asserts; tests partial | **NOT_A_POLICY_DECISION** (TEST_GAP) |
| PD-F144-23 Admin bypass | None | **DECIDED** |
| PD-F144-24 F143 help platform | Separate | **OUT_OF_SCOPE** |

**Blocking `DECISION_REQUIRED` remaining:** **none.**

---

## Planning vs CURRENT (not new PDs)

| Planning claim | Classification |
|----------------|----------------|
| F144 “fully implemented” | **current truth** |
| Phase 4 / V1 deferred | **stale planning context** for *formalization*, not runtime absence |
| “F144 not started” in older F143 notes | Meant formal pack — **documentation gap** relative to runtime |
