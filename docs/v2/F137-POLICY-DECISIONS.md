# F137 Policy Decisions — Recommendation Preview API

**Date:** 2026-08-10  
**Status:** **`F137_COMPLETE_WITH_NON_BLOCKERS`** (PD-F137-01…17 **DECIDED** + delivered)  
**Readiness:** Implementation complete with documented non-blockers  
**Spec:** [F137-RECOMMENDATION-PREVIEW-SPEC.md](./F137-RECOMMENDATION-PREVIEW-SPEC.md)  
**Boundary:** [F137-BOUNDARY.md](./F137-BOUNDARY.md)  
**Gap matrix:** [F137-IMPLEMENTATION-GAP-MATRIX.md](./F137-IMPLEMENTATION-GAP-MATRIX.md)

**CURRENT** = observed shipped behaviour.  
**DECIDED** = approved V2 target (this register).  
Closed initiatives (F003/F005/F042/F043/F127/F019/F014/F060) must not be reopened.

---

## How to read this register

| Status | Meaning |
|--------|---------|
| **DECIDED** | Product owner closed; implementation must match |
| **NOT_A_POLICY_DECISION** | Engineering/docs quality (not product) |
| **OUT_OF_SCOPE** | Not owned by F137 |

---

## Final policy register (summary)

| Decision | Status |
|----------|--------|
| PD-F137-01 Purpose | **DECIDED** — execution-grade contract |
| PD-F137-02 Persisted vs calculated | **DECIDED** — valid/current persisted wins; else V1 pipeline calculate |
| PD-F137-03 Scope | **DECIDED** — active profile + selected strategy (owned) + stock; watchlist not required |
| PD-F137-04 Public field set | **DECIDED** — execution section + research metadata section |
| PD-F137-05 Recommendation enum | **DECIDED** — `BUY` \| `SELL` \| `HOLD_POSITION` \| `WATCH` |
| PD-F137-06 Scores | **DECIDED** — normalize into fixed F137 contract |
| PD-F137-07 Eligibility | **DECIDED** — required when strategy logic depends on eligibility; else metadata |
| PD-F137-08 Missing data | **DECIDED** — non-executable: `200` + `available:false` + no canonical rec + `unavailable_reasons[]` |
| PD-F137-09 Freshness | **DECIDED** — market-data/evaluation-cycle based; no TTL |
| PD-F137-10 Side effects | **DECIDED** — preserve existing V1 side-effect semantics (document; do not invent) |
| PD-F137-11 Performance / caching | **DECIDED** — cache allowed, not required; must not serve stale cycle |
| PD-F137-12 Authorization | **DECIDED** — auth + active profile + strategy ownership + valid stock |
| PD-F137-13 Error contract | **DECIDED** — structured 401/403/404/422 + `200`/`available:false` |
| PD-F137-14 Versioning | **DECIDED** — keep `/recommendation-preview`; implicit stability |
| PD-F137-15 Consumers | **DECIDED** — general-purpose reusable API |
| PD-F137-16 Dedicated route | **DECIDED** — dedicated route is authoritative |
| PD-F137-17 Exact match with generation | **DECIDED** — same canonical recommendation as V1 pipeline for same inputs/cycle |
| PD-F137-18 F060 eligibility consumption | **NOT_A_POLICY_DECISION** |
| PD-F137-19 Help / docs accuracy | **NOT_A_POLICY_DECISION** |
| PD-F137-20 PHP feature / contract tests | **NOT_A_POLICY_DECISION** |

---

## Summary table (CURRENT vs DECIDED)

| Decision | CURRENT | DECIDED target | Status |
|----------|---------|----------------|--------|
| PD-01 | Research-leaning preview; not execute API | Execution-grade authoritative contract | **DECIDED** |
| PD-02 | Any open persisted rec wins | Valid/current persisted only; else pipeline calculate | **DECIDED** |
| PD-03 | Active strategy only; no strategy param | Explicit selected strategy belonging to active profile | **DECIDED** |
| PD-04 | Flat loose payload | Execution section + research metadata section | **DECIDED** |
| PD-05 | Pipeline actions + `HOLD_OR_AVOID` + legacy | Canonical `BUY`/`SELL`/`HOLD_POSITION`/`WATCH` + V1 mapping | **DECIDED** |
| PD-06 | Undocumented mix of scores | Normalized F137 score contract | **DECIDED** |
| PD-07 | Always computed; never gates `available` | Conditional requirement per strategy eligibility dependency | **DECIDED** |
| PD-08 | `available:false` but may still emit `WATCH` | Non-executable: no canonical recommendation | **DECIDED** |
| PD-09 | No cycle freshness check | Stale if newer completed evaluation cycle exists | **DECIDED** |
| PD-10 | Preview mostly read-only; `ensureActive` may seed; research may cache analytics | Document & preserve V1 semantics; do not invent new rule | **DECIDED** |
| PD-11 | No preview cache | Cache optional; never stale-as-current | **DECIDED** |
| PD-12 | Sanctum + active portfolio; stock global; no strategy ownership param | + selected strategy must belong to active profile | **DECIDED** |
| PD-13 | Mostly 200 + stock 404 | 401/403/404/422 + 200/`available:false` | **DECIDED** |
| PD-14 | `/api/v1/.../recommendation-preview` | Keep route; document as stable contract | **DECIDED** |
| PD-15 | Watchlist-first; dedicated unused by UI | Reusable API; no Watchlist-only assumptions | **DECIDED** |
| PD-16 | Both routes; UI uses research | Dedicated preview route authoritative | **DECIDED** |
| PD-17 | Simplified threshold preview ≠ pipeline | Exact canonical match with full V1 generation logic | **DECIDED** |

---

## PD-F137-01 — Purpose

**Status:** **DECIDED**

F137 is an **execution-grade recommendation contract**. The canonical recommendation is authoritative enough for execution-oriented consumers. It is not merely informational research output (research metadata may still accompany it — PD-04).

---

## PD-F137-02 — Persisted vs calculated

**Status:** **DECIDED**

Precedence:

1. **Valid/current** persisted recommendation for `(active profile, selected strategy, stock)` → return mapped execution contract from that row.  
2. Otherwise → **calculate** using the **authoritative V1 recommendation-generation logic** (PD-17), not a separate simplified scorer.

“Valid/current” = PD-09 (evaluation-cycle freshness). **No TTL.**

---

## PD-F137-03 — Scope

**Status:** **DECIDED**

- Active profile  
- **Explicitly selected strategy** that **belongs to** the active profile  
- Stock (valid / resolvable)  
- Watchlist membership is **NOT** required  

---

## PD-F137-04 — Public field set

**Status:** **DECIDED**

Response has two conceptual sections:

1. **Authoritative execution section** — fields execution consumers may rely on.  
2. **Research metadata section** — explanatory (e.g. eligibility sources, reasons, breakdowns).  

Research metadata **MUST NOT** be treated as the execution contract.

---

## PD-F137-05 — Recommendation enum

**Status:** **DECIDED**

Canonical F137 values only:

- `BUY`
- `SELL`
- `HOLD_POSITION`
- `WATCH`

Do **not** expose `HOLD_OR_AVOID`.

### Required V1 → F137 mapping (do not silently change V1 storage semantics)

V1 pipeline / DB continue to use portfolio actions. F137 **maps** for the public contract:

| V1 `recommendation_type` / pipeline action | F137 canonical |
|--------------------------------------------|----------------|
| `OPEN_POSITION` | `BUY` |
| `INCREASE_POSITION` | `BUY` |
| `EXIT_POSITION` | `SELL` |
| `REDUCE_POSITION` | `SELL` |
| `HOLD_POSITION` | `HOLD_POSITION` |
| `WATCH` | `WATCH` |
| Legacy `BUY` | `BUY` |
| Legacy `SELL` | `SELL` |
| Legacy `HOLD` | `HOLD_POSITION` |
| Preview-only `HOLD_OR_AVOID` | **Forbidden** in F137 response |

V1 rows are not rewritten by this mapping alone.

---

## PD-F137-06 — Scores

**Status:** **DECIDED**

Normalize into a fixed F137 contract. Classify each exposed score as execution, research metadata, or internal-only.

### Inventory (CURRENT exposure → TARGET classification)

| Score / field | CURRENT meaning / evidence | Range evidence | TARGET role |
|---------------|----------------------------|----------------|-------------|
| `recommendation_score` / strategy overall | Weighted strategy score from `StrategyConfigurationService::score` or persisted `strategy_score` | Clamped **0–100** in `score()` | **Authoritative execution score** (strategy overall) |
| `confidence` | Eval / persisted confidence | Evaluation engine uses **0–1** ratio (`passed/(passed+failed)`); not the same unit as strategy score | **Research metadata** unless implementation normalizes; **clarification**: do not invent a second scale — document unit as 0–1 when sourced from evaluation |
| `scoring_breakdown` | Per-indicator weight/contribution (preview path only) | Contribution relative to weights; overall 0–100 | **Research metadata** |
| `suggested_allocation_pct` | Capital band / persisted allocation | Strategy capital bands (% of portfolio) | **Authoritative execution** (sizing hint) |
| `overall_evaluation_score` | On evaluation-profile / research sibling payload — **not** inside preview service payload today | Evaluation result score (separate from strategy overall) | **Not** part of dedicated F137 preview execution section; remains Evaluation Profile / research bundle |
| Factor scores (momentum, trend, …) | Inputs to strategy score | Typically 0–100 style inputs when present | **Internal** to pipeline / research only if exposed |

Do **not** invent ranges beyond evidence above. Implementation must document normalized execution fields explicitly in the response schema.

---

## PD-F137-07 — Eligibility

**Status:** **DECIDED**

Eligibility is **required** when the selected strategy’s recommendation logic **depends** on eligibility; otherwise optional research metadata.

### How F137 determines dependency (from V1)

`StrategyEligibilityService::resolve` modes from strategy `config.eligibility_sources`:

| Mode | When | Dependency |
|------|------|------------|
| `unrestricted` | empty / no enabled sources | Eligibility **not** required for recommendation logic |
| `unrestricted_pending_screener_runs` | sources configured but no recent completed runs | Generation treats as unrestricted for entry filter — eligibility **not** hard-required for produce |
| `screener_union` | ≥1 recent completed run | Generation **depends** on eligibility for new entries (skip / demote OPEN/INCREASE) — eligibility **required** for executable entry recommendations |

When required and eligibility cannot be resolved for that dependency → non-executable path (PD-08).

F060 same-user screener visibility remains consumed; do not reopen F060.

---

## PD-F137-08 — Missing data

**Status:** **DECIDED**

When required inputs are unavailable/incomplete:

- HTTP **200**
- `available: false`
- **No** canonical execution recommendation (do not emit `WATCH`/`BUY`/etc. as filler)
- Structured `unavailable_reasons[]`
- Consumers **MUST** treat as non-executable

---

## PD-F137-09 — Freshness

**Status:** **DECIDED**

A recommendation is current relative to the **latest completed market-data/evaluation cycle**.

**Implementation clarification (no new product decision):** V1 has no `market_data_cycle` column. F137 freshness key is the profile’s **latest completed `EvaluationRun`** (status `completed`). A persisted recommendation is **stale** when:

- it has no resolvable evaluation cycle (e.g. null `evaluation_result_id`), **or**
- its linked `EvaluationResult.evaluation_run_id` is **not** that latest completed run for the profile (for the selected strategy context as implemented).

If newer completed cycle than persisted → recalculate (PD-02/17). **No time-based TTL.**

---

## PD-F137-10 — Side effects

**Status:** **DECIDED**

Preserve existing V1 side-effect semantics. Do not invent a new F137-only “must be read-only” rule that contradicts V1.

### Documented CURRENT side effects

| Path | Side effects |
|------|----------------|
| `EvaluationProfileService::forStock` | Read-only |
| `StrategyEligibilityService::resolve` / explain | Read-only queries |
| `getActiveStrategy` → `ensureActive` | **May write** — seed factory strategy / related screener rows if none active |
| `RecommendationPreviewService` | Does not create/update recommendation rows |
| Research bundle `StockAnalyticsService` | **May write** analytics cache |
| Full `RecommendationGenerationPipeline::run` | **Writes** — cancel stale open recs; persist new recommendations; may notify |

**Implementation risk:** PD-17 requires same decision logic as generation; naïvely calling full `generate()` would expand preview into profile-wide persist/cancel. Target: **same canonical recommendation value** via shared V1 decision logic **without** adopting full pipeline persist side effects unless those already occur on the preview path (they do not today). Exact engineering approach is implementation; product rule is match + preserve existing preview-vs-pipeline side-effect distinction.

---

## PD-F137-11 — Performance / caching

**Status:** **DECIDED**

Caching allowed but not required. Stale data from an older completed evaluation cycle **MUST NOT** be returned as current. Correctness/freshness wins. No mandatory cache architecture.

---

## PD-F137-12 — Authorization

**Status:** **DECIDED**

- Authenticated user  
- Active profile  
- Selected strategy **belongs to** active profile (no cross-profile strategy access / substitution)  
- Stock valid / accessibly resolvable  
- Stock need **not** be on a watchlist  

---

## PD-F137-13 — Error contract

**Status:** **DECIDED**

| Status | Use |
|--------|-----|
| `401` | Unauthenticated |
| `403` | Authenticated but unauthorized |
| `404` | Stock or strategy does not exist / is not accessible |
| `422` | Valid-shaped request but cannot be processed as a preview operation (precondition/validation — align with existing `ApiEnvelope` / `EVALUATION_PRECONDITION`-style usage) |
| `200` + `available:false` | Preview operation succeeded; payload is a **valid non-executable** preview (PD-08 data unavailability) |

### Distinction (project convention)

- **`200` / `available:false`:** Soft incompleteness of market/eval/eligibility **inputs** after AuthZ and resource resolution succeed (PD-08).  
- **`422`:** Request/precondition failure for the operation itself (missing required `strategy_id`, invalid parameter shape, explicit API preconditions), consistent with other v1 Trading OS 422 usages.  
- **`404`:** Prefer for cross-profile strategy or unknown stock/strategy (**not accessible**), per PD-13 wording — avoid leaking existence via 403 where project already uses 404 for not-found artifacts.

Do not blindly invent new status usages beyond this DECIDED matrix during implementation without matching existing envelope patterns.

---

## PD-F137-14 — Versioning / stability

**Status:** **DECIDED**

Keep `GET /api/v1/analytics/stocks/{stock}/recommendation-preview`. Documented F137 response is the stable contract. Breaking changes follow normal governance. No new route version required.

---

## PD-F137-15 — Supported consumers

**Status:** **DECIDED**

General-purpose reusable API. Watchlist Research is the **first** consumer. Must **not** encode Watchlist-specific assumptions into the core contract.

---

## PD-F137-16 — Dedicated route

**Status:** **DECIDED**

Dedicated recommendation-preview route is **authoritative**. Watchlist Research consumes that contract (directly or via equivalent payload that must not diverge). Research bundle may remain a convenience composite but must not define a second recommendation semantics.

---

## PD-F137-17 — Exact match with full generation

**Status:** **DECIDED**

For identical profile + strategy + stock + market-data/evaluation cycle, F137 **MUST** produce the **same canonical recommendation** as the full V1 recommendation-generation pipeline.

F137 **MUST NOT** create a separate recommendation algorithm. Divergence is a **correctness defect**.

Pipeline redesign remains **OUT_OF_SCOPE**; F137 **reuses** V1 decision logic.

**Canonical shape:**

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

---

## PD-F137-18 / 19 / 20

**NOT_A_POLICY_DECISION** — F060 consume-only; help accuracy; PHP contract tests. See gap matrix.
