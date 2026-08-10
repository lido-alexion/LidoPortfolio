# F143 Policy Decisions — In-app Contextual Help

**Date:** 2026-08-10  
**Status:** **CLOSED for blocking product decisions** — no `DECISION_REQUIRED` items  
**Readiness / verdict:** **`F143_COMPLETE_WITH_NON_BLOCKERS`**  
**Spec:** [F143-CONTEXTUAL-HELP-SPEC.md](./F143-CONTEXTUAL-HELP-SPEC.md)  
**Boundary:** [F143-BOUNDARY.md](./F143-BOUNDARY.md)  
**Gap matrix:** [F143-IMPLEMENTATION-GAP-MATRIX.md](./F143-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = shipped runtime + process evidence.  
Do **not** invent product decisions. Most F143 questions are process/engineering, not PO blockers.

Closed initiatives must not be reopened.

---

## How to read

| Status | Meaning |
|--------|---------|
| **DECIDED** | Established by CURRENT product behaviour or explicit prior pattern (not newly invented) |
| **DECISION_REQUIRED** | Blocking product choice — **none for F143** |
| **DEFERRED** | Optional later |
| **OUT_OF_SCOPE** | Explicitly not F143 |
| **NOT_A_POLICY_DECISION** | Engineering/process/governance quality |

---

## Final register (summary)

| ID | Topic | Status |
|----|-------|--------|
| PD-F143-01 Source of truth | `appDocumentation.js` (+ enrichments/guide modules) | **DECIDED** (CURRENT) |
| PD-F143-02 Runtime delivery | Static `/docs/{keyword}.html` via header `(?)` | **DECIDED** (CURRENT) |
| PD-F143-03 Generated docs ownership | Build regenerates; artifacts committed under `app/public/docs/` | **DECIDED** (CURRENT) |
| PD-F143-04 Required coverage | Not every route needs a dedicated topic; unmatched → `overview` | **DECIDED** (CURRENT) |
| PD-F143-05 Feature sync expectation | Feature add/change/delete updates help in same session | **DECIDED** (cursor rule + V2 delivery pattern) |
| PD-F143-06 Missing help UX | Open overview (no hard error) | **DECIDED** (CURRENT) |
| PD-F143-07 Profile/user-specific help content | Global informational | **DECIDED** (CURRENT) |
| PD-F143-08 Versioned help CMS | None | **OUT_OF_SCOPE** |
| PD-F143-09 External docs platform | — | **OUT_OF_SCOPE** |
| PD-F143-10 AI-authored help as SoT | — | **OUT_OF_SCOPE** (AI guide is generated *from* topics) |
| PD-F143-11 F144 Knowledge Board | Separate initiative | **OUT_OF_SCOPE** |
| PD-F143-12 Automated orphan/route validation | — | **NOT_A_POLICY_DECISION** (optional eng) |
| PD-F143-13 Automated help tests | — | **NOT_A_POLICY_DECISION** (TEST_GAP non-blocker) |
| PD-F143-14 Orphan React `components/docs/*` | Unused presentation layer | **NOT_A_POLICY_DECISION** / **DEFERRED** cleanup |
| PD-F143-15 Dedicated topic per closed V2 feature | Prefer accurate coverage under existing topics | **NOT_A_POLICY_DECISION** |
| PD-F143-16 Missing help blocks feature “complete” | Process expectation via cursor rule; not a separate product gate object | **NOT_A_POLICY_DECISION** |
| PD-F143-17 Stale topic handling | Regenerate wipes `public/docs`; content accuracy is process | **NOT_A_POLICY_DECISION** |
| PD-F143-18 implementation.md SPA prose drift | Historical paragraphs vs static CURRENT | **DOC_GAP** / **NOT_A_POLICY_DECISION** |

**Blocking product decisions remaining:** **none.**

---

## Notes on classifications

### Why almost nothing is DECISION_REQUIRED

F143’s runtime model is already live and consistent: one source file, route matching, static HTML, overview fallback, global content. Changing any of those would be a **redesign**, not an unanswered PO question for this pack.

### Established sync (PD-05)

`.cursor/rules/Keep-contextual-help-docs-in-sync.mdc` + closed V2 initiatives updating help during delivery constitute an **existing process**. Formalizing it is not inventing a new product rule.

### Content gaps vs policy

Absence of a standalone “F043 price repair” or “CSV import” **topic id** is not automatically a product decision. Coverage may live under `data-quality-center` / `transactions`. Treat residual accuracy as **DOC_GAP** non-blockers, not POLICY_REQUIRED.
