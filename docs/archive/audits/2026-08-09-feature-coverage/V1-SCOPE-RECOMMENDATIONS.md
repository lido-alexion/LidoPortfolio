# V1 Scope Recommendations — 2026-08-09

## Purpose

This document provides **evidence-based product-scope recommendations** for the 15 capabilities currently classified as `V1_SCOPE_AMBIGUOUS` in the [post-implementation feature coverage audit](./FEATURE-COVERAGE-MATRIX.md).

**This is a recommendation only.** It does **not** modify:

- `MVP_SCOPE.md`
- `SPECIFICATION_DECISIONS.md`
- Any active specification under `specs/`
- Application code or tests

Formal V1/V2 classification requires explicit product-owner approval and a separate governance update task.

**Method:** Review of current governance, active specs, implementation evidence, and `implementation.md`. Implementation existence alone is **not** treated as proof of V1 scope. Absence from `MVP_SCOPE.md` alone is **not** treated as proof of V2 scope.

**Ambiguous list verification:** All 15 IDs below match the current matrix rows with `V1 Scope = V1_SCOPE_AMBIGUOUS` (verified 2026-08-09). No discrepancies.

---

## Executive Recommendation

| Recommendation | Count | Share |
|----------------|------:|------:|
| **RECOMMEND_V1** | 1 | 6.7% |
| **RECOMMEND_V2** | 11 | 73.3% |
| **RECOMMEND_KEEP_AMBIGUOUS** | 3 | 20.0% |
| **Total** | **15** | 100% |

**Summary:** Most ambiguous capabilities are **parallel portfolio platform, operations, or secondary product features** rather than the core TOS decision-support workflow defined in `MVP_SCOPE.md`. The strongest V1 case is **screener backtesting (F058)** as validation tooling for V1-required screener/strategy eligibility (SD-030). The strongest unresolved cases are **password reset (F004)**, **strategy backtesting (F093)**, and **corporate actions (F020)** — where shipped product depth conflicts with governance silence.

---

## Decision Matrix

| ID | Capability | Current Status | Current Scope | Recommendation | Confidence | Reason |
|----|------------|----------------|---------------|----------------|------------|--------|
| F003 | User invite flow | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Admin onboarding; not in MVP TOS workflow or success criteria |
| F004 | Password reset | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_KEEP_AMBIGUOUS | MEDIUM | Baseline web-app expectation vs not listed in MVP; governance silent |
| F005 | Session management (list/revoke) | PARTIALLY_IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Admin security feature; partial UI; outside TOS pipeline |
| F014 | Historical holdings reconstruction | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | MEDIUM | Portfolio analytics substrate; no dedicated UI; not required for demo pipeline |
| F019 | Bulk CSV import | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Data-entry convenience; legacy portfolio feature |
| F020 | Corporate actions | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_KEEP_AMBIGUOUS | MEDIUM | Full portfolio subsystem; affects ledger accuracy but absent from MVP TOS scope |
| F042 | Data quality detection/resolution | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Admin ops subsystem; complements deferred hard gates (F040 OUT_OF_SCOPE) |
| F043 | Corporate action price repair | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Ops repair tooling; not user-facing TOS workflow |
| F058 | Screener backtesting (hit matrix) | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V1 | MEDIUM | Validates screener rules used for V1 strategy eligibility (SD-030); embedded in screener authoring |
| F060 | Shared screener import | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Collaboration convenience; not required for single-portfolio strategy setup |
| F093 | Strategy backtesting | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_KEEP_AMBIGUOUS | MEDIUM | Major shipped Trading nav feature vs SD-027 "future backtesting" language |
| F127 | Portfolio alerts (non-TOS) | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Parallel alert system distinct from V1 Telegram TOS notifications |
| F137 | Recommendation preview API | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | MEDIUM | Strategy/analysis tooling; not part of live pipeline or MVP success criteria |
| F143 | In-app contextual help docs | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | MEDIUM | Documentation UX layer; supports V1 pages but is not a decision-support capability |
| F144 | Knowledge Board | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | RECOMMEND_V2 | HIGH | Separate Knowledge product area; not TOS workflow |

---

## Recommended V1

### F058 — Screener Backtesting (hit matrix)

- **Recommendation:** RECOMMEND_V1
- **Why it belongs in V1:** Strategy configuration (V1-required via SD-027/SD-029/SD-030) depends on screener definitions for eligibility. Screener backtesting validates those rules historically before they drive live recommendations.
- **Evidence (documented fact):** `MVP_SCOPE.md` includes Strategy eligibility via Screeners (SD-030); screener backtest is embedded in the screener editor (`ScreenerBacktestService`, editor UI).
- **Evidence (implementation):** Full API + UI + feature tests; documented in `implementation.md` and [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md).
- **Evidence (inference):** Excluding screener backtest from formal V1 while requiring screeners for strategy creates an undocumented gap in the strategy authoring workflow.
- **User workflow supported:** Strategy author validates entry/exit screeners → configures strategy → pipeline uses those screeners for eligibility.
- **Scope impact:** Low governance change — documents an existing extension of V1-required screener capability; does not add a new pipeline stage.

---

## Recommended V2

### F003 — User Invite Flow

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Multi-user admin onboarding; MVP demo assumes a configured environment (E6); not part of TOS decision-support stages.
- **Evidence:** `UserInviteService`, `/invite/:token`; not in `MVP_SCOPE.md` included or excluded lists.
- **User workflow:** Admin invites users — platform administration, not trading decisions.
- **Scope impact:** Explicitly classify as future/platform scope in governance.

### F005 — Session Management

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Admin session list/revoke; partially implemented settings UI; security enhancement beyond MVP TOS.
- **Evidence:** `SessionManagementService.php`; matrix notes partial frontend.
- **User workflow:** Admin security hygiene.
- **Scope impact:** Minor; already partial.

### F014 — Historical Holdings Reconstruction

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Backend analytics capability with partial/no dedicated UI; distinct from V1-required portfolio snapshots (F015) which reads materialized snapshot rows.
- **Evidence:** `PortfolioHistoricalHoldingsService`; `MVP_SCOPE.md` Review dashboard mentions snapshot counts, not historical reconstruction API.
- **User workflow:** As-of holdings analysis — portfolio analytics, not core TOS pipeline.
- **Scope impact:** Document as portfolio analytics extension.

### F019 — Bulk CSV Import

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Transaction data-entry convenience; legacy portfolio feature.
- **Evidence:** `BulkTransactionImport.jsx`; not referenced in MVP success criteria.
- **User workflow:** Bulk ledger import — operational convenience.
- **Scope impact:** None on TOS pipeline.

### F042 — Data Quality Detection/Resolution

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Admin Data Quality Center for market-data anomaly governance; aligns with deferred hard publish gates (SD-004 / F040 OUT_OF_SCOPE), not clarified MVP workflow.
- **Evidence:** `DataQualityGuardService`, admin UI; `implementation.md` § Data Quality Center.
- **User workflow:** Ops/admin data hygiene.
- **Scope impact:** Production hardening domain, not demo TOS.

### F043 — Corporate Action Price Repair

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Ops repair scripts and services; maintenance tooling adjacent to F042/F020.
- **Evidence:** Repair services, deploy scripts; partial frontend exposure.
- **User workflow:** Admin repair after bad price/corporate-action data.
- **Scope impact:** Ops-only.

### F060 — Shared Screener Import

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Collaboration feature (import shared screener copy); not needed for factory strategy + single-portfolio demo path.
- **Evidence:** `ScreenerSharedTab.jsx`, `POST /api/screeners/shared/{id}/import`; SD-030 covers screener reuse conceptually but not cross-portfolio sharing.
- **User workflow:** Import another portfolio's screener definition.
- **Scope impact:** Collaboration enhancement.

### F127 — Portfolio Alerts (non-TOS)

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Parallel alert-policy system evaluated after price sync; distinct from V1 Telegram notification engine (SD-009, F121–F124 V1_REQUIRED).
- **Evidence:** `AlertPolicyService`, Settings → alert policies; matrix explicitly labels "non-TOS".
- **User workflow:** Custom holding alerts — portfolio monitoring, not recommendation pipeline.
- **Scope impact:** Clear separation from TOS notifications.

### F137 — Recommendation Preview API

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Preview/simulation API for strategy tuning; not part of live `DailyDecisionPipeline` or MVP success criteria.
- **Evidence:** `RecommendationPreviewService`; partial frontend; no feature tests.
- **User workflow:** "What would recommendations look like?" before running pipeline — analysis tooling.
- **Scope impact:** Strategy research tool, not production pipeline stage.

### F143 — In-app Contextual Help

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Documentation/discoverability layer (`appDocumentation.js`, `/documentation`); improves UX across all pages but is not itself a decision-support capability.
- **Evidence:** 43+ static doc topics; public docs reachable without login per `implementation.md`.
- **User workflow:** In-app help and documentation navigation.
- **Scope impact:** Product documentation infrastructure — classify separately from TOS engines.

### F144 — Knowledge Board

- **Recommendation:** RECOMMEND_V2
- **Why defer:** Separate Knowledge product domain (notes, tags, board UI); sidebar "Knowledge" group distinct from Trading TOS nav.
- **Evidence:** `KnowledgeBoardPage.jsx`; extensive `implementation.md` section; not in MVP included features.
- **User workflow:** Personal/team knowledge capture — not market → recommendation → execution.
- **Scope impact:** Parallel product module.

---

## Keep Ambiguous

### F004 — Password Reset

- **Recommendation:** RECOMMEND_KEEP_AMBIGUOUS
- **Why unresolved:** Any multi-user web product implicitly needs password recovery, but `MVP_SCOPE.md` lists only JWT as an Auth exclusion — password reset is neither included nor excluded. Demo path (E6) may use admin-created accounts without self-service reset.
- **Evidence:** `PasswordResetLinkService`; public reset flow documented in `implementation.md`.
- **User workflow:** Self-service account recovery.
- **Scope impact:** If classified V1, adds baseline auth expectation; if V2, acceptable for controlled internal testing only.
- **Product-owner question:** Is self-service password reset required for your V1 release posture, or is admin-only account management sufficient?

### F020 — Corporate Actions

- **Recommendation:** RECOMMEND_KEEP_AMBIGUOUS
- **Why unresolved:** Full implemented subsystem (splits, bonus, sync, UI) materially affects Indian equity ledger accuracy and therefore position-aware recommendations — yet `MVP_SCOPE.md` is silent and lists only "Legacy pages retained" for holdings/transactions.
- **Evidence:** `CorporateActionPage.jsx`, sync commands, tests; `implementation.md` corporate-action + data-quality sections.
- **User workflow:** Apply splits/bonus → holdings update → recommendations see correct quantities.
- **Scope impact:** Classifying V2 accepts demo-simplified portfolios; classifying V1 acknowledges production portfolio completeness.
- **Product-owner question:** Is corporate-action handling part of your intended V1 product for real portfolios, or explicitly a parallel portfolio module?

### F093 — Strategy Backtesting

- **Recommendation:** RECOMMEND_KEEP_AMBIGUOUS
- **Why unresolved:** Tension between (a) major intentional product investment — sidebar **Backtests**, full simulation engine, dedicated UI/API, `implementation.md` documentation — and (b) governance language treating backtesting as a **future extension** (SD-027 Future Extensions: "backtests against version snapshots"; absent from MVP included features and success criteria).
- **Evidence:** `BacktestSimulationEngine`, `/backtests`, [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md); unit tests only at API layer.
- **User workflow:** Historical paper simulation of strategy → validates config before live use; **not required** for pipeline to produce live recommendations.
- **Scope impact:** Classifying V1 formalizes a large shipped subsystem; classifying V2 matches current governance silence despite nav prominence.
- **Product-owner question:** Should strategy backtesting be documented as part of V1 strategy authoring/research, or explicitly deferred to V2 despite being shipped?

---

## Special Analysis: Backtesting

### F058 — Screener Backtesting

| Dimension | Assessment |
|-----------|------------|
| **Implementation** | IMPLEMENTED — `ScreenerBacktestService`, editor-embedded hit matrix, resumable chunks, feature tests |
| **Relationship to V1 workflow** | Indirect but structurally important — screeners define strategy eligibility (SD-030); backtest validates screener rules before live use |
| **Relationship to Discovery/Evaluation** | Supports screener authoring quality; does not replace Discovery runs or Evaluation scoring |
| **MVP_SCOPE alignment** | Screeners retained as legacy; live screener runs implied; historical matrix backtest not named |
| **Intentionality** | **High** — substantial engineering (Jul 2026 stock-major redesign, persisted results, editor UI) |
| **Recommendation** | **RECOMMEND_V1** (MEDIUM confidence) — best classified as part of V1 screener/strategy configuration tooling |

**Distinction from F093:** Screener backtest is eligibility validation (binary hit matrix), not trade simulation. It is closer to the V1 screener/strategy configuration domain than strategy backtest is to the live decision pipeline.

### F093 — Strategy Backtesting

| Dimension | Assessment |
|-----------|------------|
| **Implementation** | IMPLEMENTED — full paper portfolio simulation, equity curve, statistics, resumable engine, Trading nav page |
| **Relationship to V1 workflow** | Parallel research path — simulates what the strategy *would have done* historically; live pipeline does not depend on it |
| **Relationship to Discovery/Evaluation/Strategy** | Reuses strategy config, screeners, scoring, allocation — but as isolated simulation, not live TOS stages |
| **MVP_SCOPE alignment** | Not in included features; SD-027 lists backtesting as future extension |
| **Intentionality** | **Very high** — dedicated engine, migrations, UI, sidebar placement, contextual help topic |
| **Recommendation** | **RECOMMEND_KEEP_AMBIGUOUS** (MEDIUM confidence) — product-owner must decide whether shipped prominence overrides governance silence |

**Known limitations (not scope gaps):** No historical market gates in simulation (F099 OUT_OF_SCOPE); no benchmark comparison; API feature tests absent; fees/slippage partial.

**Do not rebuild.** Both backtest systems are functional; this analysis addresses **product classification only**.

---

## Grouped Scope View

### A. Core TOS / Trading Workflow

| ID | Capability | Recommendation |
|----|------------|----------------|
| F058 | Screener backtesting | RECOMMEND_V1 |
| F093 | Strategy backtesting | RECOMMEND_KEEP_AMBIGUOUS |
| F137 | Recommendation preview API | RECOMMEND_V2 |

*Note:* Only F058 is recommended for formal V1. F093 is the primary unresolved TOS-adjacent research tool.

### B. Portfolio Operations

| ID | Capability | Recommendation |
|----|------------|----------------|
| F014 | Historical holdings reconstruction | RECOMMEND_V2 |
| F019 | Bulk CSV import | RECOMMEND_V2 |
| F020 | Corporate actions | RECOMMEND_KEEP_AMBIGUOUS |
| F043 | Corporate action price repair | RECOMMEND_V2 |

*Note:* F020 is the key unresolved item for real-portfolio completeness.

### C. Platform Administration

| ID | Capability | Recommendation |
|----|------------|----------------|
| F003 | User invite flow | RECOMMEND_V2 |
| F004 | Password reset | RECOMMEND_KEEP_AMBIGUOUS |
| F005 | Session management | RECOMMEND_V2 |

### D. Data / Operations

| ID | Capability | Recommendation |
|----|------------|----------------|
| F042 | Data quality detection/resolution | RECOMMEND_V2 |

### E. Secondary Product Features

| ID | Capability | Recommendation |
|----|------------|----------------|
| F060 | Shared screener import | RECOMMEND_V2 |
| F127 | Portfolio alerts (non-TOS) | RECOMMEND_V2 |
| F143 | In-app contextual help | RECOMMEND_V2 |
| F144 | Knowledge Board | RECOMMEND_V2 |

---

## Product Decision Required

The following require **explicit product-owner decisions** before governance can be updated:

| Priority | ID | Capability | Question |
|----------|-----|------------|----------|
| **P1** | F093 | Strategy backtesting | Formal V1 (shipped strategy research tool) or V2/future (per SD-027 extension language)? |
| **P1** | F020 | Corporate actions | Formal V1 (real portfolio ledger completeness) or V2 (parallel portfolio module)? |
| **P2** | F004 | Password reset | Required for V1 release posture or acceptable as admin-managed accounts only? |
| **P2** | F058 | Screener backtesting | Accept RECOMMEND_V1, or override to V2/ambiguous? |

All other features have a **HIGH** or **MEDIUM** confidence recommendation that does not require escalation unless the product owner disagrees.

---

## What This Document Does Not Change

- Strict V1 implementation coverage remains **115/115 (100%)** for `V1_REQUIRED` rows.
- V1 completion verdict remains **V1_IMPLEMENTATION_COMPLETE_WITH_NON_BLOCKERS**.
- The 15 ambiguous rows remain `V1_SCOPE_AMBIGUOUS` until governance is explicitly updated.
- No capability matrix primary statuses were altered (except the supplementary weighted metric fix in the matrix header).

---

*Generated 2026-08-09 as part of post-implementation audit cleanup. Complements [FEATURE-COVERAGE-SUMMARY.md](./FEATURE-COVERAGE-SUMMARY.md) and [IMPLEMENTED-BUT-UNSPECIFIED.md](./IMPLEMENTED-BUT-UNSPECIFIED.md).*
