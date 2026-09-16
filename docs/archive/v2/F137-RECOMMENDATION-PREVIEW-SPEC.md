# F137 — Recommendation Preview API Specification

**Date:** 2026-08-10  
**Status:** **`F137_COMPLETE_WITH_NON_BLOCKERS`** (policies **DECIDED**; delivery 2026-08-10)  
**Initiative:** Phase 3 — last Phase 3 item (after closed F003/F005/F042/F043/F127/F019/F014/F060)  
**Related:** [F137-BOUNDARY.md](./F137-BOUNDARY.md), [F137-POLICY-DECISIONS.md](./F137-POLICY-DECISIONS.md), [F137-IMPLEMENTATION-GAP-MATRIX.md](./F137-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT (pre-F137 hardening)** = behaviour before this delivery.  
**APPROVED TARGET / DELIVERED** = DECIDED PD-F137-01…17 as implemented.

### Delivered architecture

```text
Full generation
    └── shared decision logic (RecommendationGenerationPipeline)
          ├── decideForSecurity / buildDrafts / rank / allocate / applyCapitalOutcomes
          └── persist/cancel (generate only)

F137 preview
    └── same shared decision logic (decideForSecurity — no persist/cancel/ensureActive)
          ├── execution contract
          └── research metadata
```

**Authoritative API:** `GET /api/v1/analytics/stocks/{stock}/recommendation-preview?strategy_id={id}`  
**Tests:** `tests/Feature/F137RecommendationPreviewTest.php`  
**UI:** `WatchlistResearchPanel` consumes dedicated preview + active strategy id.

---

## 1. Purpose

| | |
|--|--|
| **CURRENT** | SD-031 Watchlist research helper; simplified preview; not treated as execute API |
| **TARGET** | **Execution-grade** recommendation contract (PD-01) for reusable consumers |
| **GAP** | Purpose/contract hardening + exact pipeline match + strategy param + freshness + enum |

### Canonical architecture (DECIDED)

```text
Full generation
    └── shared decision logic
          ├── BUY / SELL / HOLD_POSITION / WATCH
          ├── scores
          ├── eligibility
          └── reasons

F137 preview
    └── same shared decision logic
          ├── execution contract
          └── research metadata
```

F137 packages shared-decision outputs into the public **execution contract** + **research metadata**. It must not introduce a parallel recommendation algorithm (PD-17). See [F137-BOUNDARY.md](./F137-BOUNDARY.md) §1.

---

## 2. Scope

### In scope (TARGET)

| Area | Policy |
|------|--------|
| Authoritative `GET .../recommendation-preview` contract | PD-14, PD-16 |
| Selection: valid/current persisted → else V1 pipeline logic | PD-02, PD-09, PD-17 |
| Explicit strategy belonging to active profile | PD-03, PD-12 |
| Canonical enum + V1 mapping | PD-05 |
| Normalized scores; execution vs research sections | PD-04, PD-06 |
| Conditional eligibility | PD-07 |
| Non-executable missing-data payload | PD-08 |
| Structured errors | PD-13 |
| AuthZ | PD-12 |
| Contract/feature tests; minimal help | AC019, AC020 |
| Watchlist compatibility without Watchlist-only API assumptions | PD-15, PD-16 |

### Out of scope

Recommendation pipeline redesign, strategy/scoring/eligibility engine redesign, execution engine, market-data pipeline, F060 reopen, F143/F144 pull-ahead, closed V2 reopen.

---

## 3. CURRENT implementation inventory

| Layer | Artefact | Behaviour |
|-------|----------|-----------|
| Service | `RecommendationPreviewService::forStock($profile, $stock)` | Open persisted **any** open-list row wins; else simplified score→action |
| Strategy | `getActiveStrategy` only | **No** `strategy_id` request param |
| Eval | `EvaluationProfileService::forStock` | Latest completed eval result for profile/stock |
| Eligibility | `resolve` + `explainForSecurity` | Always; does not gate `available` |
| Pipeline | `RecommendationGenerationPipeline` | Profile-wide generate; **not** called by preview |
| Routes | `GET /api/v1/analytics/stocks/{stock}/recommendation-preview` | Dedicated |
| | `GET .../research` | Bundle; UI uses this |
| Middleware | `auth:sanctum`, `active.portfolio` | |
| UI | `WatchlistResearchPanel` | Research only; no strategy param |
| Tests | — | **None** for preview/research |

### CURRENT control flow

```text
openList persisted for (profile, stock)? ──yes──► source=persisted (no cycle check)
                    │
                    no
                    ▼
         active strategy + eval factors + score()
         thresholds → OPEN_POSITION | WATCH | HOLD_OR_AVOID
         (≠ full pipeline decidePortfolioAction / gates / capital)
```

---

## 4. APPROVED TARGET behaviour

### 4.1 Authoritative API

```http
GET /api/v1/analytics/stocks/{stock}/recommendation-preview?strategy_id={id}
```

(Exact query param name is implementation detail; **selected strategy is required** per PD-03.)

- Auth: Sanctum  
- Active profile required  
- Strategy must belong to active profile → else **404** (not accessible)  
- Stock resolvable → else **404**  
- Watchlist membership not required  

### 4.2 Selection & freshness

1. Resolve **latest completed evaluation cycle** for the profile (= latest completed `EvaluationRun`; no TTL; no invented market_data_cycle column).  
2. If a persisted recommendation exists for profile + stock (+ strategy version as implemented) and is **current** for that cycle → map to F137 execution contract (`source` reflecting persisted).  
3. Else → compute via **same V1 generation decision logic** as `RecommendationGenerationPipeline` for that profile/strategy/stock/cycle → map to F137 canonical recommendation.  
4. Do **not** use the CURRENT simplified threshold-only preview algorithm.

### 4.3 Response shape (conceptual)

```text
{
  available: boolean,
  unavailable_reasons: string[] | objects[],  // when available=false

  // —— Authoritative execution section ——
  recommendation: BUY|SELL|HOLD_POSITION|WATCH | null,  // null if !available
  recommendation_score: number | null,   // strategy overall 0–100
  suggested_allocation_pct: number | null,
  strategy: { id, name, version_label, ... },
  stock_id, symbol,
  source: "persisted" | "calculated",
  evaluation_cycle_id: <EvaluationRun id or equivalent>,
  // freshness / identity fields as needed for consumers

  // —— Research metadata section ——
  confidence: ...,              // document unit (CURRENT eval: 0–1)
  eligibility_sources: [...],   // required vs optional per PD-07
  reason_summary: ...,
  scoring_breakdown: ...,       // optional metadata
  recommendation_id / status: ... when persisted identity is useful metadata
}
```

Exact JSON key nesting (`execution` vs flat with documented sections) is an implementation schema choice; **semantic separation is mandatory** (PD-04).

### 4.4 Missing data (PD-08)

`200`, `available:false`, **no** canonical `recommendation`, `unavailable_reasons[]` populated. Consumers must not execute.

### 4.5 Errors (PD-13)

| Code | When |
|------|------|
| 401 | Unauthenticated |
| 403 | Authenticated but unauthorized (where project uses 403) |
| 404 | Stock/strategy missing or not accessible to active profile |
| 422 | Operation precondition/validation failure (existing envelope style) |
| 200 + available:false | Valid preview; non-executable inputs (PD-08) |

### 4.6 Side effects (PD-10)

Document and preserve V1 semantics: preview path today does not persist recommendations; `ensureActive` may seed; full generate persists. Implementation of PD-17 must not silently expand preview into profile-wide cancel/create unless that is an explicit reuse of existing generate entrypoints already used by preview (it is not). Prefer shared decision functions.

### 4.7 Caching (PD-11)

Optional. Must not return older cycle as current.

### 4.8 Consumers (PD-15/16)

Dedicated route is SoT. Watchlist must remain compatible (may call dedicated route or research bundle that **delegates** to the same contract). No Watchlist-only required fields in core contract.

---

## 5. CURRENT vs TARGET — API

| Topic | CURRENT | TARGET | GAP |
|-------|---------|--------|-----|
| Route | Dedicated exists | Keep dedicated as authoritative | **PARTIAL** — formalize; UI still on research |
| Strategy param | Absent (active only) | Explicit selected strategy | **MISSING** |
| Auth / active profile | Present | Present | Align + tests |
| Status codes | Mostly 200; stock 404 | 401/403/404/422 + 200/available:false | **PARTIAL** |
| Schema | Flat; mixed purpose | Execution + research sections | **PARTIAL** |
| `unavailable_reasons[]` | Absent | Required when unavailable | **MISSING** |
| `available:false` + filler `WATCH` | Yes | Forbidden | **GAP** |

---

## 6. CURRENT vs TARGET — recommendation selection

| Topic | CURRENT | TARGET | GAP |
|-------|---------|--------|-----|
| Persisted precedence | Any open-list row | Only if current for latest completed eval cycle | **GAP** |
| Cycle detection | None | Via `evaluation_result_id` → run vs latest completed run | **MISSING** |
| Fallback | Simplified `score` + thresholds + `HOLD_OR_AVOID` | V1 pipeline decision logic (exact match) | **GAP** (correctness) |
| Single-stock generate | Does not exist | Need shared decision path for one stock without separate algorithm | **MISSING** substrate API; extract/reuse |
| Enum | Pipeline actions / `HOLD_OR_AVOID` / legacy | Map to BUY/SELL/HOLD_POSITION/WATCH | **GAP** |
| Strategy version on persisted | Loaded active strategy for display; not selection filter | Selected strategy ownership + match | **GAP** |

---

## 7. Scores (inventory)

See PD-06 in policy register. Summary:

| Field | CURRENT | TARGET | GAP |
|-------|---------|--------|-----|
| `recommendation_score` | 0–100 strategy overall (evidence) | Execution score 0–100 | Harden/document |
| `confidence` | Eval 0–1 mixed into same UI | Research metadata; document unit | **GAP** docs/normalize presentation |
| `scoring_breakdown` | Preview only | Research metadata | Classify |
| `suggested_allocation_pct` | Present | Execution | Document |
| Eval overall in research | Sibling payload | Not F137 execution section | **NO_GAP** if kept on eval profile |

---

## 8. Eligibility

| | CURRENT | TARGET | GAP |
|--|---------|--------|-----|
| Resolve | Always | Always available as metadata; **required** when mode is eligibility-dependent (`screener_union`) | Gate executable entries when required |
| F060 | Consumed in resolve | Consume only | **NO_GAP** to change F060 |

---

## 9. Security / AuthZ audit (TARGET requirements)

| Check | CURRENT | TARGET | GAP |
|-------|---------|--------|-----|
| Cross-profile strategy | N/A (no strategy param; active only) | Deny / 404 | **MISSING** tests + param AuthZ |
| Strategy ID substitution | N/A | Must belong to active profile | **MISSING** |
| Stock without watchlist | Allowed | Allowed | **NO_GAP** |
| Persisted rec other profile | `forProfile` scopes | Keep | Add tests |
| F060 screener privacy | Inherited | Intact | Do not modify F060 |

---

## 10. Acceptance criteria

| ID | Criterion | CURRENT | TARGET | Classification |
|----|-----------|---------|--------|----------------|
| AC001 | Authenticated access | Sanctum | Sanctum | CURRENT≈; harden tests → **GAP** tests |
| AC002 | Active-profile scope | Middleware | Required | CURRENT≈; **GAP** tests |
| AC003 | Selected strategy belongs to active profile | Active only / no param | Explicit + ownership | **GAP** |
| AC004 | Valid stock resolution | Binding 404 | 404 | CURRENT≈; **GAP** tests |
| AC005 | Dedicated route authoritative | Exists; UI uses research | Dedicated SoT; consumers aligned | **PARTIAL** |
| AC006 | Persisted freshness (cycle) | None | PD-09 | **GAP** |
| AC007 | Calculation fallback via V1 logic | Simplified preview | Pipeline-equivalent | **GAP** |
| AC008 | Exact match full generation | Diverges | Mandatory | **GAP** |
| AC009 | Canonical enum | Non-compliant | BUY/SELL/HOLD_POSITION/WATCH | **GAP** |
| AC010 | Normalized score contract | Undocumented | PD-06 | **GAP** |
| AC011 | Conditional eligibility | Never required | PD-07 | **GAP** |
| AC012 | Missing-input non-executable | Filler WATCH | PD-08 | **GAP** |
| AC013 | Freshness cycle semantics | None | PD-09 | **GAP** |
| AC014 | Side-effect behaviour documented | Partially | Document + preserve | **DOC_GAP** / implement carefully |
| AC015 | Structured error contract | Partial | PD-13 | **GAP** |
| AC016 | Watchlist compatibility | Works on old shape | Must keep working on new contract | **GAP** (FE consumer update in impl phase) |
| AC017 | Reusable API (no Watchlist-only) | Watchlist-shaped usage | General-purpose | **GAP** review |
| AC018 | Contract stability | Undocumented | Documented stable | **DOC** / **GAP** |
| AC019 | Backend feature/contract tests | Missing | Required | **GAP** |
| AC020 | Contextual help/documentation | Watchlist help silent | Accurate minimal notes | **GAP** (not full F143) |

**Do not mark PASS** because CURRENT happens to exist.

---

## 11. V1 / V2 boundary

| Frozen V1 substrate | F137 formalizes | Must not redesign |
|---------------------|-----------------|-------------------|
| Generation pipeline decision logic, strategy math, eligibility engine, persisted lifecycle, market/eval pipelines | Preview API contract, selection/freshness, mapping, AuthZ, tests | New recommendation algorithms, F060, execution engine |

---

## 12. Readiness / verdict

**`F137_COMPLETE_WITH_NON_BLOCKERS`**

All PD-F137-01…17 delivered. See gap matrix for non-blockers.
