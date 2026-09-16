# F143 Implementation Gap Matrix — In-app Contextual Help

**Date:** 2026-08-10  
**Status:** **`F143_COMPLETE_WITH_NON_BLOCKERS`**  
**Related:** [F143-CONTEXTUAL-HELP-SPEC.md](./F143-CONTEXTUAL-HELP-SPEC.md), [F143-BOUNDARY.md](./F143-BOUNDARY.md), [F143-POLICY-DECISIONS.md](./F143-POLICY-DECISIONS.md)

**Legend:** IMPLEMENTED · PARTIAL · MISSING · POLICY_REQUIRED · DOC_GAP · TEST_GAP · PROCESS_GAP · DEFERRED · OUT_OF_SCOPE · NO_GAP

Do **not** mark MISSING merely because a formal V2 pack did not previously exist.

---

## 1. Layer distinction

| Layer | F143 assessment |
|-------|-----------------|
| **Runtime product functionality** | **IMPLEMENTED** |
| **Documentation content** | **IMPLEMENTED** with minor **PARTIAL**/DOC_GAP niches |
| **Doc generation infrastructure** | **IMPLEMENTED** |
| **Governance/process** | **IMPLEMENTED** (cursor rule + delivery pattern); formal pack now exists |
| **Test/validation** | **TEST_GAP** (non-blocker) |

---

## 2. Capability matrix

| # | Item | Class |
|---|------|-------|
| 1 | Topic catalogue SoT | **IMPLEMENTED** |
| 2 | Route matching + specificity | **IMPLEMENTED** |
| 3 | Header contextual open | **IMPLEMENTED** |
| 4 | Static HTML generate on build | **IMPLEMENTED** |
| 5 | Committed `public/docs` artifacts | **IMPLEMENTED** |
| 6 | Unmatched → overview fallback | **IMPLEMENTED** |
| 7 | Related topic links | **IMPLEMENTED** (soft unresolved drop) |
| 8 | Feature-change help sync rule | **IMPLEMENTED** (process) |
| 9 | Formal V2 pack | **IMPLEMENTED** (this delivery) |
| 10 | Blocking product PDs | **NO_GAP** / none **POLICY_REQUIRED** |
| 11 | Automated help/route tests | **TEST_GAP** |
| 12 | Orphan/stale topic detector | **MISSING** (optional) / **NOT** product-required |
| 13 | SPA `components/docs/*` presentation | **PARTIAL** / unused → **DEFERRED** cleanup |
| 14 | `implementation.md` still describes SPA `/documentation` article UI | **DOC_GAP** |
| 15 | Roadmap “43 topics” / Phase 4 “not done” wording | **DOC_GAP** (planning drift; do not rewrite in this task) |
| 16 | Dedicated F043 topic id | **PARTIAL** content elsewhere |
| 17 | Standalone CSV topic id | **PARTIAL** under transactions |
| 18 | Dedicated session topic id | **PARTIAL** under profile/settings |
| 19 | CMS / external docs / AI SoT | **OUT_OF_SCOPE** |
| 20 | F144 Knowledge Board | **OUT_OF_SCOPE** |
| 21 | Reopen closed V2 for help | **OUT_OF_SCOPE** |

---

## 3. Test / validation inventory

| Check | Present? | Class |
|-------|----------|-------|
| PHPUnit/Jest help catalogue tests | **No** | **TEST_GAP** |
| Missing-topic CI | **No** | **TEST_GAP** |
| Broken related-link hard fail | Soft skip | **TEST_GAP** / acceptable CURRENT |
| Generate fails on empty catalogue | **Yes** | **IMPLEMENTED** |
| Build includes generate | **Yes** | **IMPLEMENTED** |
| Route coverage vs React Router map | **No** | **TEST_GAP** |

Do not introduce tests in the specification task.

---

## 4. Documentation / process gaps

| Gap | Class | Action |
|-----|-------|--------|
| Formal F143 pack missing historically | Closed by this pack | — |
| `implementation.md` SPA-era help paragraphs vs static CURRENT | **DOC_GAP** | Optional later cleanup (not this task’s app edits beyond pointer) |
| V2-ROADMAP / DEPENDENCIES / PRIORITIZATION still Phase-4 / “43 topics” | **DOC_GAP** drift | Recorded; historical plans not rewritten here |
| Orphan Doc* React components | **DEFERRED** | Optional delete/wire |

---

## 5. Does F143 need an implementation phase?

| Question | Answer |
|----------|--------|
| Is runtime substantially complete? | **Yes** |
| Are there blocking PDs? | **No** |
| Mandatory greenfield work? | **No** |
| Optional hardening? | Tests, orphan component cleanup, planning-doc count fixes, optional niche topics |

**Recommendation:** Treat F143 as **formally complete with non-blockers**. Do **not** invent an implementation programme. Proceed to **F144** when product prioritizes Knowledge Board — or optionally schedule tiny eng chores outside F143 scope inflation.

---

## 6. Verdict

**`F143_COMPLETE_WITH_NON_BLOCKERS`**
