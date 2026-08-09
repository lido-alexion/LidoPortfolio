# F042 / F043 / F020 Boundary Document

**Date:** 2026-08-09  
**Purpose:** Prevent scope bleed between V1 corporate actions, V2 data-quality governance, and V2 price repair.  
**Status:** Planning — no code changes authorized by this document.

---

## 1. Overview

Three related capabilities touch corporate actions and market data, but serve **different actors**, **mutate different data**, and **must not be merged** during V2 implementation.

```text
┌─────────────────────────────────────────────────────────────────┐
│                        MARKET DATA (OHLCV)                       │
└───────────────────────────────┬─────────────────────────────────┘
                                │
         ┌──────────────────────┼──────────────────────┐
         │                      │                      │
         ▼                      ▼                      ▼
   ┌───────────┐          ┌───────────┐          ┌───────────┐
   │ V1 F020   │          │ V2 F042   │          │ V2 F043   │
   │ Corporate │          │ Data      │          │ CA Price  │
   │ Actions   │          │ Quality   │          │ Repair    │
   └─────┬─────┘          └─────┬─────┘          └─────┬─────┘
         │                      │                      │
         ▼                      ▼                      ▼
   Portfolio ledger      Issue governance         OHLCV history
   (transactions,        (detect, review,         correction
    holdings)             gate pipelines)         (mutate prices)
```

---

## 2. F020 — V1 Corporate Actions (frozen)

### Responsibility

**Apply known corporate actions to portfolio correctness** — split and bonus preview/apply affecting holdings and transactions within a portfolio profile.

### Primary code

| Layer | Artifact |
|-------|----------|
| Service | `CorporateActionService` |
| Model | `CorporateAction` |
| API | Corporate actions endpoints (preview/apply) |
| UI | `CorporateActionPage.jsx` |
| Write path | `TransactionWriteService` (bonus creates; split rescales) |

### Data mutated

| Data | Mutated? |
|------|----------|
| `portfolio_transactions` | **Yes** |
| Holdings / cost basis | **Yes** |
| `portfolio_corporate_actions` | **Yes** |
| `portfolio_stock_prices` (systematic repair) | **No** (not F020 scope) |
| DQ issue tables | **No** (except optional legacy migration **into** F042 audit) |

### Actor

Portfolio operator (profile-scoped).

### V1 scope note (SD-035)

F020 includes **core split/bonus handling and ledger correctness**. It explicitly **does not** include F043 price-repair tooling.

---

## 3. F042 — V2 Data Quality Detection / Resolution

### Responsibility

**Detect suspected corporate-action-related market-data anomalies, retain evidence, govern admin resolution, and block analytics pipelines until resolved.**

F042 is the **governance layer** — not the ledger-apply layer (F020) and not the OHLCV-repair layer (F043).

### Primary code

| Layer | Artifact |
|-------|----------|
| Detection | `DataQualityCorporateActionSyncService`, `DataQualityCorporateActionHeuristicService` |
| Issues | `DataQualityIssueService`, models `DataQualityIssue*` |
| Resolution | `DataQualityResolutionService`, `DataQualityAdjustmentFactorService` |
| Guard | `DataQualityGuardService` |
| API/UI | `DataQualityController`, Data Quality Center pages |
| Ops | Scheduled commands, `cpanel-data-quality-center.php` |

### Data mutated

| Data | Mutated? |
|------|----------|
| `portfolio_data_quality_issues` | **Yes** |
| `portfolio_data_quality_issue_evidence` | **Yes** (append) |
| `portfolio_data_quality_issue_resolutions` | **Yes** (append) |
| `portfolio_price_adjustment_factors` | **Yes** (metadata on accept) |
| `portfolio_stock_prices` | **No** |
| `portfolio_transactions` | **No** |
| Holdings | **No** |

### Actor

Platform admin only.

### What F042 resolution means today

| Action | Effect |
|--------|--------|
| **Accept** | Issue → `accepted`; append resolution audit; create active adjustment-factor row |
| **Reject** | Issue → `rejected`; deactivate factors; append audit |
| **Auto-accept** | Same as accept after 15-day pending window |
| **Re-resolve** | Supersede prior resolution via new audit row |

Accept **does not** repair OHLCV or apply corporate actions to portfolios.

---

## 4. F043 — V2 Corporate Action Price Repair

### Responsibility

**Operational repair of misadjusted OHLCV price history** after corporate actions have been applied — scan for suspected unadjusted/already-adjusted bars and mutate `portfolio_stock_prices` when explicitly applied.

### Primary code

| Layer | Artifact |
|-------|----------|
| Service | `CorporateActionPriceRepairService` |
| Helper | `CorporateActionPriceAdjustmentService` |
| Command | Repair artisan command(s) |
| Deploy | `deploy/cpanel-repair-corporate-action-prices.php` |
| Tests | `CorporateActionPriceRepairServiceTest`, `CorporateActionPriceAdjustmentServiceTest` |

### Data mutated

| Data | Mutated? |
|------|----------|
| `portfolio_stock_prices` | **Yes** (repair apply path) |
| DQ issue tables | **No** (today) |
| Portfolio transactions | **No** |
| Adjustment factors | **No** (today — does not read F042 factors) |

### Actor

Platform admin / ops (CLI or cpanel).

### Dependency on F042

| Relationship | Status |
|--------------|--------|
| F043 should run **after** F042 governance is formal | **Recommended** (V2 roadmap) |
| F043 reads F042 issues today | **No** |
| F043 reads adjustment factors today | **No** |
| Shared table `portfolio_price_adjustment_factors` | **Potential future handoff** — written by F042, not consumed yet |

---

## 5. Shared infrastructure

| Infrastructure | Owner | Used by |
|----------------|-------|---------|
| `portfolio_stocks` | V1 | F042 detection, F043 repair |
| `portfolio_stock_prices` | V1 OHLCV sync | F042 read (detect), F043 write (repair) |
| `portfolio_corporate_actions` | F020 | F043 scan input; F042 legacy migration source |
| `portfolio_price_adjustment_factors` | **Shared (metadata)** | F042 writes; F043 **should** read in future |
| `DataQualityGuardService` | F042 | V1 pipeline engines (read-only consumer) |
| Daily sync schedule | V1 | Precedes F042 detection jobs |

---

## 6. Prohibited overlap

During V2 implementation, **do not**:

| Anti-pattern | Why |
|--------------|-----|
| Add OHLCV mutation to F042 accept/reject | That is F043; bypasses repair safeguards |
| Move F020 preview/apply into Data Quality Center | F020 is V1 user workflow |
| Use F043 repair as substitute for F042 issue tracking | Loses audit/evidence/gating |
| Auto-apply F020 corporate actions from F042 accept | Accept records governance only today |
| Expand F042 guard to block portfolio trading UI | Guard targets analytics pipelines only |
| Duplicate F043 scan logic inside F042 heuristic | Different purposes: **detect unknown CA** vs **repair known applied CA** |

---

## 7. Examples — what belongs where

| Scenario | F020 | F042 | F043 |
|----------|:----:|:----:|:----:|
| User applies 1:2 split to holdings | **✓** | | |
| Exchange feed reports upcoming split; admin reviews | | **✓** | |
| Overnight 50% gap detected; stock blocked from screener | | **✓** | |
| Admin accepts detected split ratio in Data Quality Center | | **✓** | |
| Accepted ratio stored as adjustment factor metadata | | **✓** | |
| OHLCV bars before ex-date still show unadjusted prices | | | **✓** |
| Ops runs repair scan after F020 apply | | | **✓** |
| Repair command back-adjusts historical close prices | | | **✓** |
| Legacy manual CA migrated into DQ audit history | | **✓** | |
| Evaluation scores 0 for pending_review stock | | **✓** | |

---

## 8. Current boundary problems (document only — no refactor yet)

| Problem | Description | Recommended V2 resolution |
|---------|-------------|----------------------------|
| **Unused adjustment factors** | F042 accept writes factors nothing reads | F043 implementation should consume accepted factors as repair input |
| **Parallel CA paths** | F020 manual apply vs F042 detected CA not linked | Document ops playbook: F020 for portfolio; F042 for market-data governance |
| **Auto-accept without repair** | Auto-accept creates factors but no price fix | F043 should not assume prices fixed; ops run repair separately |
| **Naming collision** | "Corporate Action History" page is F042 audit, not F020 | UI copy/spec clarification only |
| **`createOrRefreshPendingIssueForStock` misnomer** | Does not refresh evidence | Rename or implement in F042 hardening |

---

## 9. Recommended handoff (future F043 work — not started)

When F043 is implemented as formal V2:

```text
F042 accept (ratio + ex-date + stock)
        │
        ▼
portfolio_price_adjustment_factors (active)
        │
        ▼
F043 repair job reads factor + CorporateAction record
        │
        ▼
CorporateActionPriceAdjustmentService mutates OHLCV
        │
        ▼
(Optional) F043 writes repair audit linked to F042 issue id
```

This handoff is **not implemented today**.

---

## 10. Governance references

| Document | Relevance |
|----------|-----------|
| `MVP_SCOPE.md` | F020 V1; F042/F043 deferred |
| SD-035 | Scope freeze decision |
| `docs/v2/V2-ROADMAP.md` | F042 before F043 |
| `docs/v2/F042-DATA-QUALITY-SPEC.md` | F042 formal requirements |
| `implementation.md` § Data Quality Center | Living behaviour notes |

---

*Related: [F042-DATA-QUALITY-SPEC.md](./F042-DATA-QUALITY-SPEC.md), [F042-IMPLEMENTATION-GAP-MATRIX.md](./F042-IMPLEMENTATION-GAP-MATRIX.md)*
