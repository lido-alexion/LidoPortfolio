# F143 — In-app Contextual Help Specification

**Date:** 2026-08-10  
**Status:** **`F143_COMPLETE_WITH_NON_BLOCKERS`**  
**Initiative:** Knowledge & Guidance (historical Phase 4 in V2 planning) — **runtime already shipped**  
**Related:** [F143-BOUNDARY.md](./F143-BOUNDARY.md), [F143-POLICY-DECISIONS.md](./F143-POLICY-DECISIONS.md), [F143-IMPLEMENTATION-GAP-MATRIX.md](./F143-IMPLEMENTATION-GAP-MATRIX.md)

**Authority order for this pack:** (1) application code/tests, (2) this pack, (3) `DOCS.md`, (4) `implementation.md`, (5) older roadmap/planning docs.

Do **not** invent requirements because they are technically possible.  
Do **not** treat “no prior V2 pack” as “feature missing.”

---

## 1. What is F143?

F143 is the **in-app contextual help / documentation UX layer**: route-aware help entry, a structured topic catalogue, and crawlable static HTML documentation generated from that catalogue.

### User problem

Users need accurate, screen-relevant guidance without leaving the product for an external wiki. Operators and agents need a single place to keep help synchronized when features change.

### What F143 is not

- Not a Knowledge Board (F144)  
- Not feature business logic  
- Not a CMS / multi-tenant help system  
- Not a substitute for `DOCS.md` / architecture specs (different audience)

---

## 2. CURRENT end-to-end behaviour (evidence)

### 2.1 Source of truth

| Artefact | Role |
|----------|------|
| `app/resources/js/src/data/appDocumentation.js` | Primary topic catalogue (~**44** topics) + enrichments |
| `tradingArtifactGuides.js` / authoring contract / runtime semantics modules | Additional guide topics |
| `.cursor/rules/Keep-contextual-help-docs-in-sync.mdc` | Agent/process rule: update help on feature change |

Topic fields (CURRENT): `id`, `keyword`, `aliases?`, `title`, `routeLabel`, `match(pathname)`, `summary`, `overview`, `controls[]`, `concepts[]`, `related?`.

### 2.2 Runtime UX

```text
User clicks header (?) 
  → HeaderHelpButton
  → openDocumentationForPath(pathname)
  → resolveDocKeywordFromPath (specificity-sorted match)
  → if none match → keyword "overview"
  → window.open(/docs/{keyword}.html)
```

| Fact | Evidence |
|------|----------|
| Entry control | `HeaderHelpButton.jsx` in `AppHeader` (auth + guest) |
| Mapping | `documentationLinks.js` |
| Delivery | **Static HTML** under `app/public/docs/` — not a live SPA article renderer |
| Legacy route | `DocumentationPage.jsx` redirects `/documentation?q=` → `/docs/...` |
| Search helpers | Exist in `documentationLinks.js`; **no primary SPA search UI** currently wired as the main experience |

### 2.3 Generation pipeline

| Fact | Evidence |
|------|----------|
| Script | `app/scripts/generate-static-docs.mjs` |
| npm | `docs:static`; **`build` runs generate then Vite** |
| Output | Wipes/rewrites `app/public/docs/`; index + per-keyword HTML + alias redirects |
| AI pack | `stox-trading-artifacts-ai-guide.md` (+ mirror under `specs/architecture/domains/`) |
| Committed? | **Yes** — generated files are in the repo for deploy/crawl without server-side generate |
| Validation | Empty catalogue throws; soft missing AI-guide refs; unresolved related links dropped |

### 2.4 Scoping

Help **content** is **global informational** (not per-profile authored). Prose may *describe* portfolio-scoped product behaviour.

### 2.5 Missing / unmatched pages

Unmatched pathname → **`overview`**. No hard failure UI.

---

## 3. Already shipped

| Capability | Status |
|------------|--------|
| Topic model + enrichments | **SHIPPED** |
| Route matching + specificity | **SHIPPED** |
| Header contextual open | **SHIPPED** |
| Static HTML generation + commit | **SHIPPED** |
| Index / aliases / related links | **SHIPPED** |
| Feature-delivery sync pattern | **SHIPPED** (process) |
| Coverage for major V1/V2 surfaces | **SHIPPED** (see §5) |

Historical V2 planning called F143 “Phase 4 / defer formalization” because of **maintenance cost while product churned**, not because runtime was absent (`V2-PRIORITIZATION.md`: **FULLY IMPLEMENTED**, readiness **5**).

---

## 4. What constitutes a topic

A **contextual-help topic** is one catalogue entry with a stable `keyword` (HTML slug), human title/summary/overview, optional controls/concepts, optional related keywords, and usually a `match` function for one or more routes (guide-only topics may use `match: () => false`).

---

## 5. Coverage inventory (major surfaces)

| Surface | Topic(s) | Coverage | Notes |
|---------|----------|----------|-------|
| Overview / how help works | `overview` | Present | Fallback topic |
| User Invite (F003) | `users` (alias invites) | Present | Synced in F003 delivery |
| Session management (F005) | `profile`, `settings` | Present | No dedicated `session` id — content under Profile/Settings |
| Data Quality (F042) | `data-quality-center`, `corporate-action-history` | Present | |
| CA wizards (F020) | `corporate-action` | Present | |
| CA price repair (F043) | (via DQ / historical notes) | **PARTIAL** | No dedicated F043 ops topic id |
| Portfolio Alerts (F127) | `alert-policies`, `notifications`, `admin-alerts` | Present | Distinguishes policy vs ops vs Telegram |
| Bulk CSV (F019) | `transactions` control | Present | No standalone CSV topic |
| Historical Holdings (F014) | `historical-holdings` | Present | |
| Shared screeners (F060) | `screener`, `screener-registry` | Present | Same-user sharing documented in topics |
| Recommendation Preview (F137) | `watchlist`, `recommendations` | Present | Preview vs Generate documented |
| Watchlist | `watchlist` | Present | |
| Transactions / pending | `transactions`, `pending-execution` | Present | |
| Settings / profile / portfolios | `settings`, `profile`, `portfolios` | Present | |
| Strategy / screeners / discovery / TOS flow | multiple | Present | Including registries & guides |

**Drift note:** Planning docs still say “43 topics”; runtime README / catalogue is **44**.

---

## 6. Synchronization guarantees (CURRENT)

| Guarantee | Level |
|-----------|-------|
| Agent must update help on feature change | **Process** (cursor rule) |
| Closed V2 initiatives update help in delivery | **Established pattern** |
| Static HTML regenerated on `npm run build` | **Build** |
| CI test that topics match routes | **Absent** |
| Automatic stale-content detection vs UI | **Absent** |

---

## 7. Explicitly out of scope

- Redesigning product features to “fit” help  
- F144 Knowledge Board  
- External documentation CMS  
- Per-user authored help  
- Making missing help a hard runtime error  
- Reopening closed V2 initiatives to “fix help”  

---

## 8. Acceptance criteria

### 8.1 Already satisfied (CURRENT)

| AC | Criterion | Evidence |
|----|-----------|----------|
| AC-01 | Catalogue SoT exists and is non-empty | `appDocumentation.js` |
| AC-02 | Route→keyword resolution with fallback | `documentationLinks.js` |
| AC-03 | Header opens docs for current path | `HeaderHelpButton` |
| AC-04 | Static docs generated in production build | `package.json` build script |
| AC-05 | Docs crawlable without SPA JS | `app/public/docs/*.html` |
| AC-06 | Feature sync rule documented for agents | cursor rule |
| AC-07 | Major shipped surfaces have discoverable help | §5 inventory |

### 8.2 Potential hardening (non-blocking; optional)

| AC | Criterion | Class |
|----|-----------|-------|
| AC-H1 | Automated smoke: every `match` route family has a topic / every related resolves | TEST_GAP |
| AC-H2 | Remove or wire orphan `components/docs/*` SPA presentation | DEFERRED eng |
| AC-H3 | Align stale `implementation.md` SPA prose with static CURRENT | DOC_GAP |
| AC-H4 | Dedicated F043 topic if operators need ops-specific help | optional DOC |

### 8.3 Out of scope ACs

- CMS, AI SoT, profile-specific articles, F144 absorption  

---

## 9. What constitutes F143 completion

F143 is **complete for V2 formalization** when:

1. Runtime help system remains as CURRENT (catalogue + header + static generate).  
2. This pack documents ownership, CURRENT behaviour, coverage, and gaps.  
3. Remaining items are explicitly **non-blockers** (tests, orphan components, planning drift, optional content).  

**No separate greenfield implementation phase is required** unless product later chooses optional hardening (AC-H*).

---

## 10. Readiness / verdict

**`F143_COMPLETE_WITH_NON_BLOCKERS`**

Implementation phase for F143: **not justified** as a mandatory next step. Optional eng/doc chores only.

### Confirmation (this pack session)

- No application code / tests / schema / frontend / generated docs / help content modified  
- Closed V2 initiatives not reopened  
- F144 subsequently **CLOSED** (`F144_COMPLETE_WITH_NON_BLOCKERS`; formalization pack) — do not treat older “not started” notes as current 
