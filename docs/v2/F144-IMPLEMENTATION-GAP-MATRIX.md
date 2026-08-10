# F144 Implementation Gap Matrix — Knowledge Board

**Date:** 2026-08-10  
**Status:** **`F144_COMPLETE_WITH_NON_BLOCKERS`**  
**Related:** [F144-KNOWLEDGE-BOARD-SPEC.md](./F144-KNOWLEDGE-BOARD-SPEC.md), [F144-BOUNDARY.md](./F144-BOUNDARY.md), [F144-POLICY-DECISIONS.md](./F144-POLICY-DECISIONS.md)

**Legend:** IMPLEMENTED · PARTIAL · MISSING · POLICY_REQUIRED · DOC_GAP · TEST_GAP · DEFERRED · OUT_OF_SCOPE · NO_GAP

Do not mark MISSING merely because a formal V2 pack did not previously exist.

---

## 1. Capability matrix

| # | Item | Class |
|---|------|-------|
| 1 | Notes CRUD (profile-scoped) | **IMPLEMENTED** |
| 2 | Tags CRUD + merge + unique names | **IMPLEMENTED** |
| 3 | Search / tag filter / sort | **IMPLEMENTED** |
| 4 | Pin / archive / duplicate / bulk | **IMPLEMENTED** |
| 5 | Images upload + serve | **IMPLEMENTED** |
| 6 | Editors + palettes + client export | **IMPLEMENTED** |
| 7 | Auth + active.portfolio | **IMPLEMENTED** |
| 8 | Cross-profile deny (code) | **IMPLEMENTED** |
| 9 | Cross-profile note/tag tests | **TEST_GAP** (SHOULD) |
| 10 | Cross-profile image tests | **IMPLEMENTED** |
| 11 | Happy-path KnowledgeBoardTest | **IMPLEMENTED** |
| 12 | Server pagination | **NO_GAP** vs CURRENT (full list DECIDED) |
| 13 | Entity links to stocks/strategies/txns | **OUT_OF_SCOPE** |
| 14 | Sharing / collaboration | **OUT_OF_SCOPE** |
| 15 | Image delete / orphan GC | **PARTIAL** / **DEFERRED** |
| 16 | `is_favorite` UI | **DEFERRED** (API exists) |
| 17 | Unarchive UX polish | **PARTIAL** / **DEFERRED** |
| 18 | Formal V2 pack | **IMPLEMENTED** (this delivery) |
| 19 | Blocking product PDs | **NO_GAP** |
| 20 | API_DOCUMENTATION.md drift | **DOC_GAP** |
| 21 | Roadmap Phase-4 “formal only” wording | **DOC_GAP** (planning drift; not rewritten here) |
| 22 | Absorb F014/F019/F060/F137/F143 | **OUT_OF_SCOPE** / **NO_GAP** |

---

## 2. Test coverage inventory

| Area | Coverage | Priority if hardening |
|------|----------|------------------------|
| Note/tag CRUD, search, merge, duplicate, bulk archive | `KnowledgeBoardTest` | — |
| Derived title, palettes, archived query bool | `KnowledgeBoardTest` | — |
| Image upload/fetch + foreign profile 404 | `KnowledgeBoardImageTest` | — |
| Note/tag foreign profile 404 | **Absent** | **SHOULD** / MUST for AuthZ parity |
| Tag delete pivot behaviour | Indirect | optional |
| Image orphan after note delete | **Absent** | optional |
| Frontend component tests | **Absent** (no FE harness required) | optional |

---

## 3. Security / integrity residual

| Item | Class |
|------|-------|
| Profile isolation in code | **IMPLEMENTED** |
| Note/tag AuthZ test depth | **TEST_GAP** |
| Orphan images | **PARTIAL** lifecycle |
| Admin bypass | **NO_GAP** (none by design) |

---

## 4. Does F144 need an implementation phase?

| Question | Answer |
|----------|--------|
| Runtime substantially complete? | **Yes** |
| Blocking PDs? | **No** |
| Mandatory greenfield work? | **No** |
| Optional hardening? | AuthZ tests; image GC; docs drift; UX polish |

**Recommendation:** Treat F144 as **formally complete with non-blockers**. Do not invent a large implementation programme.

---

## 5. Verdict

**`F144_COMPLETE_WITH_NON_BLOCKERS`**
