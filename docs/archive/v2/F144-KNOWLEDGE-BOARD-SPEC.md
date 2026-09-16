# F144 — Knowledge Board Specification

**Date:** 2026-08-10  
**Status:** **`F144_COMPLETE_WITH_NON_BLOCKERS`**  
**Initiative:** Knowledge & Guidance — **runtime already shipped**  
**Related:** [F144-BOUNDARY.md](./F144-BOUNDARY.md), [F144-POLICY-DECISIONS.md](./F144-POLICY-DECISIONS.md), [F144-IMPLEMENTATION-GAP-MATRIX.md](./F144-IMPLEMENTATION-GAP-MATRIX.md)

**Authority order:** (1) code/tests, (2) this pack, (3) `DOCS.md`, (4) `implementation.md`, (5) older roadmaps.

Do **not** invent MVP requirements that are not CURRENT obligations.  
Do **not** treat “no prior V2 pack” as “feature missing.”

---

## 1. What is F144?

F144 is the **Knowledge Board**: a portfolio-scoped research-notes product (notes, tags, images, editors, pin/archive/export) separate from the trading ledger and recommendation engines.

### User problem

Capture durable research theses, checklists, and commentary in the active portfolio without mixing them into transactions or recommendations.

### What F144 is not

- Not contextual help (F143)  
- Not historical holdings / CSV import (F014/F019)  
- Not screeners (F060) or recommendation preview (F137)  
- Not multi-user collaborative wiki  

---

## 2. CURRENT implementation inventory

### 2.1 Frontend

| Item | Path / note |
|------|-------------|
| Pages | `/knowledge-board`, `/knowledge-board/tags` |
| Components | `components/knowledgeBoard/*` |
| Nav | Knowledge group in `config/navigation.js` |
| Editors | Simple / Formatted (TipTap) / Markdown; autosave |
| Export | Client Plain / Markdown / AI-friendly |
| Manual order | `localStorage` (`knowledgeBoardOrder.js`) |

### 2.2 Backend

| Layer | Artefacts |
|-------|-----------|
| Controllers | `KnowledgeBoardNoteController`, `TagController`, `ImageController` |
| Services | `KnowledgeBoardNoteService`, `TagService`, `ImageService`, `KnowledgeNotePaletteCatalog` |
| Models | `KnowledgeNote`, `KnowledgeTag`, `KnowledgeImage` |
| Auth | `auth:sanctum` + `active.portfolio` |
| Validation | Inline `$request->validate` + service exceptions (no FormRequest classes) |

### 2.3 Data model

| Table | Role |
|-------|------|
| `portfolio_knowledge_notes` | Notes (`profile_id` cascade) |
| `portfolio_knowledge_tags` | Tags; unique `(profile_id, name)` |
| `portfolio_knowledge_note_tag` | Pivot; cascade on note/tag delete |
| `portfolio_knowledge_images` | Profile-scoped blobs by `uuid`; **no `note_id`** |

**No FKs** to stocks, strategies, transactions, or recommendations.

### 2.4 API surface (prefix `/api/knowledge-board`)

Notes: list/create/get/update/delete/duplicate/bulk; query `q`, `archived`, `tag_ids`, `tag_match`, `sort`.  
Tags: list/create/update/delete/merge.  
Images: upload; GET display/full by uuid.  
Palettes: GET `/palettes`.

Foreign resource IDs → **404** (not 403). Bulk ignores foreign note IDs.

### 2.5 Help

Topics `knowledge`, `knowledge-tags` in `appDocumentation.js` + static `/docs`.

---

## 3. CURRENT end-to-end workflow

```text
UI (active profile header)
  → Sanctum session + X-Profile-Id / active.portfolio
  → /api/knowledge-board/notes|tags|images
  → Service scoped by activePortfolio()->id
  → MySQL portfolio_knowledge_* 
  → JSON { data: ... } → UI grid/editor/tags page
```

---

## 4. Security / AuthZ (CURRENT)

| Check | Result |
|-------|--------|
| Auth required | **Yes** |
| Active profile required | **Yes** |
| Cross-profile note/tag/image | **404** after ownership assert |
| Admin bypass | **None** |
| Policies | None (assert helpers) |
| Image isolation tested | **Yes** (`KnowledgeBoardImageTest`) |
| Note/tag isolation tested | **Code yes; dedicated tests SHOULD** |

---

## 5. Data integrity (CURRENT)

| Topic | Behaviour |
|-------|-----------|
| Empty note | Create without usable content → **422** |
| Tag duplicates | Unique + case-insensitive reject **422** |
| Tag delete | Pivot cascades; notes remain |
| Tag merge | Relink then delete source |
| Note delete | Pivot cascades; **images not GC’d** |
| Profile delete | Knowledge rows cascade |
| Title | Optional; derived from content when omitted |

---

## 6. Formal requirements

### MUST (CURRENT obligations)

1. Notes and tags **MUST** be scoped to the **active portfolio** (`profile_id`).  
2. Unauthenticated or no-active-portfolio access **MUST** be denied by middleware.  
3. Direct access to another profile’s note/tag/image **MUST** fail closed (**404**).  
4. Tag names **MUST** be unique per profile (case-insensitive create/update).  
5. Note list **MUST** support search (`q`), archive filter, tag match modes, and documented sorts.  
6. Note/tag CRUD, duplicate, bulk archive/delete, and tag merge **MUST** remain available as shipped.  
7. Image upload/serve **MUST** remain profile-scoped.  
8. F144 **MUST NOT** write ledger/transactions or mutate recommendations/screeners.  
9. F144 **MUST NOT** reopen closed V2 initiatives or absorb F143 platform ownership.

### SHOULD

1. Feature tests **SHOULD** cover note/tag cross-profile denial (parity with images).  
2. Help topics **SHOULD** stay accurate when Knowledge Board UX changes (F143 sync rule).  
3. Optional: image delete/GC; unarchive UX polish; favorite UI — only if product later prioritizes.

### MUST NOT

1. **MUST NOT** require entity FKs to stocks/strategies/transactions for MVP (CURRENT has none).  
2. **MUST NOT** introduce cross-user sharing without a new explicit product decision.  
3. **MUST NOT** invent an implementation programme solely because a formal pack was missing.

---

## 7. Acceptance criteria

### Compliance / regression (primary)

| AC | Criterion | CURRENT |
|----|-----------|---------|
| AC-01 | Auth + active portfolio on all KB APIs | Satisfied |
| AC-02 | Profile isolation (notes/tags/images) | Satisfied in code |
| AC-03 | Note/tag CRUD + merge + duplicate + bulk | Satisfied |
| AC-04 | Search / tag filter / sort | Satisfied |
| AC-05 | Editors + images + palettes + export | Satisfied |
| AC-06 | Help topics present | Satisfied |
| AC-07 | No ledger/screener/rec coupling | Satisfied |
| AC-08 | KnowledgeBoardTest + ImageTest pass | Satisfied |

### Non-blocking hardening (optional)

| AC | Criterion | Class |
|----|-----------|-------|
| AC-H1 | Explicit note/tag foreign-profile feature tests | TEST_GAP SHOULD |
| AC-H2 | Image delete/orphan GC | DEFERRED eng |
| AC-H3 | API_DOCUMENTATION.md title/images drift | DOC_GAP |
| AC-H4 | Unarchive / favorite UI | DEFERRED |

---

## 8. What constitutes F144 completion

Runtime product is **already complete**. This pack completes **V2 formalization**. Remaining items are **non-blockers**.

**No mandatory greenfield implementation phase.**

---

## 9. Readiness / verdict

**`F144_COMPLETE_WITH_NON_BLOCKERS`**

### Confirmation (this session)

- No application / frontend / test / schema / API behaviour changes  
- Closed initiatives not reopened  
- No F144 implementation started  
