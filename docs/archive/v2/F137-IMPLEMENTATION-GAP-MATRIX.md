# F137 Implementation Gap Matrix — Recommendation Preview API

**Date:** 2026-08-10  
**Status:** **`F137_COMPLETE_WITH_NON_BLOCKERS`**  
**Policies:** PD-F137-01…17 **DECIDED** — delivered  
**Related:** [F137-RECOMMENDATION-PREVIEW-SPEC.md](./F137-RECOMMENDATION-PREVIEW-SPEC.md), [F137-BOUNDARY.md](./F137-BOUNDARY.md), [F137-POLICY-DECISIONS.md](./F137-POLICY-DECISIONS.md)

---

## Delivery summary

| Area | Status |
|------|--------|
| Shared decision core (`decideForSecurity` + `applyCapitalOutcomes`) | **IMPLEMENTED** |
| Persisted-if-current (evaluation cycle) | **IMPLEMENTED** |
| Explicit `strategy_id` + ownership AuthZ | **IMPLEMENTED** |
| Canonical enum + mapping | **IMPLEMENTED** |
| Execution + research sections | **IMPLEMENTED** |
| `available:false` + `unavailable_reasons[]` (no filler WATCH) | **IMPLEMENTED** |
| Read-only (no persist/cancel/`ensureActive` on F137 path) | **IMPLEMENTED** |
| Watchlist consumer | **IMPLEMENTED** |
| PHP contract tests (`F137RecommendationPreviewTest`) | **IMPLEMENTED** |
| Watchlist help | **IMPLEMENTED** |

---

## Remaining non-blockers

| Item | Class | Notes |
|------|-------|-------|
| Dedicated FE component tests | **DEFERRED** | No FE test framework introduced |
| Flat top-level field aliases alongside `execution`/`research` | **NO_GAP** / transitional | Same values; nested sections are authoritative |
| `max_concurrent` truncates persist batch only | **NO_GAP** | Preview still applies capital demotion; intentional persist-only limit |
| Research route requires `strategy_id` | **NO_GAP** | Aligns with PD-03; dedicated route remains authoritative |
| Full-suite unrelated failures (CA UNIQUE, Explorer, RS ctor, growth calc) | **OUT_OF_SCOPE** | Pre-existing / unrelated to F137 |

---

## Capability matrix (post-delivery)

| # | Item | Class |
|---|------|-------|
| 1 | Dedicated preview route | **IMPLEMENTED** |
| 2 | Explicit owned `strategy_id` | **IMPLEMENTED** |
| 3 | Execution vs research sections | **IMPLEMENTED** |
| 4 | Canonical BUY/SELL/HOLD_POSITION/WATCH | **IMPLEMENTED** |
| 5 | Cycle-current persisted precedence | **IMPLEMENTED** |
| 6 | Calculate via shared V1 decision | **IMPLEMENTED** |
| 7 | Non-executable missing data | **IMPLEMENTED** |
| 8 | Conditional eligibility metadata + skip reasons | **IMPLEMENTED** |
| 9 | Score/confidence contract | **IMPLEMENTED** |
| 10 | Structured errors | **IMPLEMENTED** |
| 11 | PHP feature tests | **IMPLEMENTED** |
| 12 | Pipeline redesign / F060 / F143 | **OUT_OF_SCOPE** |
