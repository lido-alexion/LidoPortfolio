# F137 Boundary — Recommendation Preview API

**Date:** 2026-08-10  
**Status:** **`F137_COMPLETE_WITH_NON_BLOCKERS`** (policies **DECIDED**; delivery complete)  
**Purpose:** Keep F137 as a **contract + selection semantics** layer over frozen V1 recommendation substrate. Prevent pipeline redesign and closed-initiative reopen.  
**Related:** [F137-RECOMMENDATION-PREVIEW-SPEC.md](./F137-RECOMMENDATION-PREVIEW-SPEC.md), [F137-POLICY-DECISIONS.md](./F137-POLICY-DECISIONS.md), [F137-IMPLEMENTATION-GAP-MATRIX.md](./F137-IMPLEMENTATION-GAP-MATRIX.md)

---

## 1. Canonical architecture (DECIDED — PD-17 / PD-04 / PD-10)

Full generation and F137 preview **share one decision core**. F137 does **not** own a second algorithm.

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

| Layer | Owns | Must not |
|-------|------|----------|
| **Shared decision logic** (V1) | Canonical action, scores, eligibility application, reasons | Be duplicated inside F137 |
| **Full generation** | Batch/profile run, persist/cancel lifecycle, notifications | Be redesigned by F137 |
| **F137 preview** | HTTP contract, AuthZ, freshness/selection, package into execution + research sections | Invent separate recommendation logic |

Persisted-if-current (PD-02/09) may return a prior **output** of that same logic when still valid for the latest completed evaluation cycle; otherwise preview **re-invokes** shared decision logic (without adopting full-generation persist side effects — PD-10).

### Request-path diagram (TARGET)

```text
┌────────────────────────────────────────────────────────────────────┐
│ Auth user + active portfolio + selected strategy (owned) + stock   │
└───────────────────────────────┬────────────────────────────────────┘
                                ▼
┌────────────────────────────────────────────────────────────────────┐
│ F137 — GET /api/v1/analytics/stocks/{stock}/recommendation-preview │
│  • AuthZ · freshness · persisted-if-current OR shared decision     │
│  • Package → execution contract + research metadata                │
└───────────────┬─────────────────────────┬──────────────────────────┘
                │                         │
                ▼                         ▼
┌──────────────────────────┐   ┌────────────────────────────────────┐
│ V1 Persisted             │   │ Shared decision logic (V1)         │
│ TradingRecommendation    │   │ ← also used by full generation     │
│ (prior output if current)│   │ action / scores / eligibility /    │
└──────────────────────────┘   │ reasons (+ gates / holdings ctx)   │
                               └────────────────────────────────────┘
                eligibility resolve may use F060 same-user rules (consume only)
```

Research bundle (`/research`) and Watchlist UI are **consumers** of the F137 contract (or must not diverge). Dedicated preview route is authoritative (PD-16).

---

## 2. F137 owns

| Owns | Notes |
|------|--------|
| Recommendation-preview **API contract** | Route kept; response semantics DECIDED |
| Preview **selection** semantics | Valid/current persisted vs calculate (PD-02/09) |
| **Execution** contract fields | Canonical recommendation + execution scores/sizing |
| **Research metadata** contract | Eligibility explain, reasons, breakdowns — not execution |
| **Authorization** for this API | Active profile + strategy ownership + stock resolution |
| **API contract / feature tests** | AC019 |
| **Minimal** consumer compatibility & help notes | Watchlist first consumer; not full F143 |

---

## 3. V1 remains owner of (frozen substrate)

| Area | Owner |
|------|--------|
| **Shared decision logic** (action, scores, eligibility application, reasons) | V1 Recommendation Engine / pipeline internals — **reused by F137** |
| Recommendation **generation** (batch persist/cancel/notify) | `RecommendationGenerationPipeline` / Recommendation Engine |
| Strategy algorithms & scoring math | Strategy / `StrategyConfigurationService` |
| Eligibility engine | `StrategyEligibilityService` |
| Persisted recommendation lifecycle / statuses / review / execution | Recommendations domain |
| Execution / pending-execution / ledger apply | Execution paths |
| Market-data / OHLCV / evaluation run production | Market + Evaluation Engine |
| Stock Analytics metrics | `StockAnalyticsService` (SD-031 sibling) |

F137 **MUST NOT** redesign these. PD-17 requires **reuse** of shared decision logic for exact match; F137 only packages outputs into execution + research contracts.

---

## 4. Dependencies

| Dependency | Rule |
|------------|------|
| V1 recommendation pipeline | Frozen substrate; exact canonical match |
| F060 (CLOSED) | Eligibility may resolve same-user shared screeners; **do not** change F060 |
| SD-031 | Watchlist research IA; F137 hardens preview contract |
| Closed F003/F005/F042/F043/F127/F019/F014 | Do not reopen |
| F143 / F144 | Do not pull ahead of F137 delivery |

---

## 5. Must NOT absorb

- New recommendation algorithm separate from V1 generation  
- Strategy / scoring / eligibility redesign  
- Trading automation / broker execution  
- Notification channels (F127)  
- Screener sharing redesign (F060)  
- Historical holdings / CSV import  
- Auth platform redesign (F003/F005)  
- Full contextual-help platform (F143) / Knowledge Board (F144)

---

## 6. Side-effect boundary (PD-10)

| Layer | Side effects |
|-------|----------------|
| F137 preview (TARGET) | Must not invent a new rule; preserve CURRENT preview vs generate distinction — preview today does **not** persist/cancel recommendation sets; full generate **does** |
| V1 `ensureActive` | May seed strategy — existing V1 behaviour |
| V1 full generate | Cancel + persist — not the F137 default path |
| Research analytics cache | Existing Stock Analytics behaviour if research bundle used |

Exact-match (PD-17) is about **canonical recommendation equality**, not mandatory invocation of profile-wide persist.

---

## 7. Security boundary

- No cross-profile strategy access or ID substitution  
- No other-profile persisted recommendation leakage  
- Stock need not be on watchlist  
- F060 same-user screener semantics remain intact  

---

## 8. Implementer rules

1. Prefer shared V1 decision functions over copying threshold preview.  
2. Map V1 actions → F137 enum; do not rewrite V1 storage as part of mapping alone.  
3. Freshness = evaluation cycle, not TTL.  
4. Separate execution vs research metadata in docs and tests.  
5. Do not reopen closed V2 initiatives.  
