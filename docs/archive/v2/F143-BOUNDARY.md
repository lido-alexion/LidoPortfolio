# F143 Boundary — In-app Contextual Help

**Date:** 2026-08-10  
**Status:** **`F143_COMPLETE_WITH_NON_BLOCKERS`** (runtime shipped; this pack formalizes)  
**Purpose:** Prevent F143 from expanding into a CMS, feature-behaviour redesign, or Knowledge Board (F144).  
**Related:** [F143-CONTEXTUAL-HELP-SPEC.md](./F143-CONTEXTUAL-HELP-SPEC.md), [F143-POLICY-DECISIONS.md](./F143-POLICY-DECISIONS.md), [F143-IMPLEMENTATION-GAP-MATRIX.md](./F143-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Ownership diagram (CURRENT / TARGET)

```text
┌────────────────────────────────────────────────────────────────────┐
│ Feature delivery (any initiative)                                  │
│  Cursor rule: Keep-contextual-help-docs-in-sync                    │
└───────────────────────────────┬────────────────────────────────────┘
                                ▼
┌────────────────────────────────────────────────────────────────────┐
│ Source of truth — appDocumentation.js (+ guide modules/enrichments)│
└───────────────┬─────────────────────────────┬──────────────────────┘
                │                             │
                ▼                             ▼
┌──────────────────────────┐   ┌────────────────────────────────────┐
│ Runtime UX               │   │ Build — generate-static-docs.mjs   │
│ HeaderHelpButton         │   │ → app/public/docs/*.html (commit)  │
│ documentationLinks.js    │   │ → AI guide mirror under specs/     │
│ route → keyword → /docs  │   └────────────────────────────────────┘
└──────────────────────────┘
```

---

## 2. F143 owns

| Owns | Notes |
|------|--------|
| Contextual help **content model** | Topics: id, keyword, aliases, match, summary, overview, controls, concepts, related |
| **Page/route → topic** mapping | `match` + specificity sort in `documentationLinks.js` |
| **Discoverability** | Header `(?)`, `/docs` index, aliases, related links |
| **Static documentation generation** | `generate-static-docs.mjs` as part of `npm run build` |
| **Synchronization / accuracy process** | Cursor rule + feature-delivery help sync (established by closed V2 initiatives) |
| **Fallback when unmatched** | Unmatched routes → `overview` |
| Optional **non-blocker hardening** | Tests, orphan React `components/docs/*` cleanup, planning-doc drift — only if chosen later |

---

## 3. F143 does NOT own

| Does not own | Owner |
|--------------|--------|
| Application feature behaviour / APIs | Feature initiatives |
| F137 recommendation semantics | F137 (CLOSED) |
| F060 screener sharing | F060 (CLOSED) |
| F014 historical holdings | F014 (CLOSED) |
| F019 import / ledger | F019 (CLOSED) |
| Auth / invites / sessions | F003/F005 (CLOSED) |
| Alerts / DQ / CA repair product logic | F127/F042/F043 (CLOSED) |
| Knowledge Board notes product | **F144** (separate) |
| External docs platform / CMS / AI content authoring | OUT_OF_SCOPE |
| Email / webhook / notification channels | Other domains |

Updating help **text** for a closed initiative’s behaviour is **content sync**, not reopening that initiative.

---

## 4. V1 runtime vs F143 formalization

| Layer | Classification |
|-------|----------------|
| Header help + static `/docs` + `appDocumentation.js` | **Existing V1/runtime infrastructure** (shipped; SD-035 deferred from V1 *success criteria*, not “unshipped”) |
| This V2 pack | **Formalizes** ownership, CURRENT behaviour, coverage, gaps |
| Optional eng chores | **May harden** validation/tests/stale prose — not required to call F143 complete |
| Must not redesign | Feature domains; new help CMS; F144 |

---

## 5. Closed-initiative pattern (evidence, not new policy)

Closed packs (F003/F005/F014/F019/F042/F060/F127/F137) routinely updated `appDocumentation.js` as part of delivery. That establishes a **working sync pattern** F143 formalizes — it does not reopen those initiatives.
