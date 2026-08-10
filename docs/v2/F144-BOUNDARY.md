# F144 Boundary — Knowledge Board

**Date:** 2026-08-10  
**Status:** **`F144_COMPLETE_WITH_NON_BLOCKERS`** (runtime shipped; this pack formalizes)  
**Purpose:** Keep Knowledge Board as a **standalone portfolio-scoped research-notes product**. Prevent absorption of ledger, screener, recommendation, or contextual-help domains.  
**Related:** [F144-KNOWLEDGE-BOARD-SPEC.md](./F144-KNOWLEDGE-BOARD-SPEC.md), [F144-POLICY-DECISIONS.md](./F144-POLICY-DECISIONS.md), [F144-IMPLEMENTATION-GAP-MATRIX.md](./F144-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram (CURRENT)

```text
┌────────────────────────────────────────────────────────────────────┐
│ Authenticated user + active portfolio (Sanctum + active.portfolio) │
└───────────────────────────────┬────────────────────────────────────┘
                                ▼
┌────────────────────────────────────────────────────────────────────┐
│ F144 — Knowledge Board                                             │
│  UI: /knowledge-board, /knowledge-board/tags                       │
│  API: /api/knowledge-board/*                                       │
│  Tables: portfolio_knowledge_notes|tags|note_tag|images            │
│  Scope key: profile_id                                             │
└────────────────────────────────────────────────────────────────────┘
         │
         │  does NOT own / must not absorb
         ▼
┌──────────────┬──────────────┬──────────────┬──────────────┐
│ Ledger/CSV   │ Screeners    │ Recs / F137  │ F143 Help    │
│ F014/F019    │ F060         │              │ (docs only)  │
└──────────────┴──────────────┴──────────────┴──────────────┘
```

---

## 2. F144 owns (CURRENT / formalized)

| Owns | Evidence |
|------|----------|
| Portfolio-scoped research **notes** | `KnowledgeNote`, notes API/UI |
| Portfolio-scoped **tags** + merge | `KnowledgeTag`, tags API/UI |
| Note–tag pivot | `portfolio_knowledge_note_tag` |
| Embedded **images** (upload/serve) | `KnowledgeImage`, image API |
| Editors / pin / archive / duplicate / bulk / client export | UI + services |
| Color palettes | `color_palette` + catalogue |
| Help topics `knowledge`, `knowledge-tags` | `appDocumentation.js` |
| AuthZ: active-profile isolation (404 on foreign IDs) | Controllers/services |

---

## 3. V1 / platform owns (not F144 redesign)

| Area | Owner |
|------|--------|
| Sanctum auth, active portfolio middleware | Platform (F005 closed) |
| Portfolio profiles | Portfolio domain |
| Static help generation pipeline | F143 (CLOSED formalization) |

---

## 4. Completed initiatives — consume vs reopen

| Initiative | Relationship |
|------------|--------------|
| **F014 / F019** | **No data coupling.** Knowledge Board is not historical holdings or CSV import. |
| **F060** | **No coupling.** Notes do not share/import screeners. |
| **F137** | **No coupling.** Notes are not recommendations. |
| **F143** | Help **topics** describe Knowledge Board; F144 does not own the help platform. Updating help text ≠ reopening F143. |
| F003/F005/F042/F043/F127 | Unrelated; do not reopen. |

Watchlist appears only as a **help `related` link**, not a schema relationship.

---

## 5. Must NOT absorb / invent as F144

- Transaction/ledger writes or reconstruction  
- Screener/strategy/recommendation entity FKs (unless a future explicit product decision — **not CURRENT**)  
- Contextual-help CMS redesign  
- Multi-user note sharing / collaboration  
- Admin bypass of profile isolation  
- Turning notes into an audit log of trades  

---

## 6. Accidental scope check

| Question | Finding |
|----------|---------|
| Has F144 absorbed another initiative’s core? | **No** |
| Is another feature mislabeled as F144? | **No** — standalone Knowledge nav group |
| Planning “Phase 4 / deferred”? | Governance of **formal V2 pack**, not missing runtime |
