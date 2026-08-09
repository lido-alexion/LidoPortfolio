# V2 Feature Prioritization

**Date:** 2026-08-09  
**Status:** Planning only — does not modify V1 scope or governance  
**V1 baseline:** [Final audit](../audits/2026-08-09-feature-coverage-final/) — 119 capabilities frozen (SD-035)  
**Deferred V2 backlog:** 11 capabilities (SD-035 § Deferred to V2 / Future)

---

## Purpose

Recommend priority, sequencing, and grouping for the eleven capabilities explicitly deferred from frozen V1. **No implementation authorized by this document.**

---

## Scoring framework

| Dimension | Range | Meaning |
|-----------|------:|---------|
| Product Value | 1–5 | User impact, workflow importance |
| Strategic Importance | 1–5 | Directional product value |
| Dependency Leverage | 1–5 | Enables other V2 work |
| Readiness | 1–5 | Existing implementation maturity |
| Implementation Complexity | 1–5 | Effort to formalize/harden (lower = easier) |
| Risk | 1–5 | Data/security/ops failure impact |

**Priority Score** = (Product + Strategic + Leverage + Readiness) − (Complexity + Risk)

Scores are comparative tools, not absolute truth. Final ranking uses qualitative adjustment for dependencies.

---

## Current implementation state (implemented-but-deferred)

*Updated 2026-08-09 for Market Data Quality delivery. Priority rankings below remain historical planning context; F042/F043 are no longer pending V2 implementation.*

| ID | Feature | State | Evidence |
|----|---------|-------|----------|
| F003 | User invite flow | **FULLY IMPLEMENTED** | `UserInviteService`, `/invite/:token`, `UserInviteTest` |
| F005 | Session management | **MOSTLY IMPLEMENTED** | `SessionManagementService`, API + Settings UI; `AuthSessionTest` |
| F014 | Historical holdings reconstruction | **MOSTLY IMPLEMENTED** | `PortfolioHistoricalHoldingsService`, API; no dedicated UI; unit tests |
| F019 | Bulk CSV import | **MOSTLY IMPLEMENTED** | `BulkTransactionImport.jsx`, `bulkTransactionCsv.js`; JS unit test only |
| F042 | Data quality detection/resolution | **COMPLETE** | DQ Center + hardening; `F042_COMPLETE_WITH_NON_BLOCKERS` |
| F043 | Corporate action price repair | **COMPLETE** | Factor path + F020 single-writer; `F043_COMPLETE` |
| F060 | Shared screener import | **MOSTLY IMPLEMENTED** | `ScreenerSharedTab.jsx`, `POST /api/screeners/shared/{id}/import`; partial tests |
| F127 | Portfolio alerts (non-TOS) | **FULLY IMPLEMENTED** | Alert policies UI, evaluation engine, `AlertPolicyTest` + related suite |
| F137 | Recommendation preview API | **MOSTLY IMPLEMENTED** | `RecommendationPreviewService`, API route, Watchlist panel; **no feature tests** |
| F143 | In-app contextual help | **FULLY IMPLEMENTED** | `appDocumentation.js`, 43 topics, static HTML docs pipeline |
| F144 | Knowledge Board | **FULLY IMPLEMENTED** | Full UI, tags, images, `KnowledgeBoardTest` |

**Retention recommendation:** Do not remove shipped code. V2 work for most items is **formalization, hardening, spec alignment, and test coverage** — not greenfield build.

---

## Priority table

| Rank | ID | Feature | Value | Strat. | Leverage | Cplx. | Risk | Ready. | **Score** | Phase |
|------|----|---------|------:|-------:|---------:|------:|-----:|-------:|----------:|-------|
| 1 | F003 | User invite flow | 4 | 4 | 4 | 2 | 3 | 5 | **12** | 1 |
| 2 | F042 | Data quality detection/resolution | 4 | 5 | 5 | 4 | 4 | 4 | **10** | 1 |
| 3 | F127 | Portfolio alerts (non-TOS) | 4 | 3 | 3 | 3 | 3 | 5 | **9** | 2 |
| 4 | F143 | In-app contextual help | 3 | 2 | 2 | 2 | 1 | 5 | **9** | 4 |
| 5 | F005 | Session management | 3 | 3 | 3 | 2 | 3 | 4 | **8** | 1 |
| 6 | F060 | Shared screener import | 3 | 3 | 2 | 2 | 2 | 4 | **8** | 3 |
| 7 | F137 | Recommendation preview API | 3 | 4 | 3 | 3 | 2 | 3 | **8** | 3 |
| 8 | F144 | Knowledge Board | 3 | 2 | 1 | 3 | 2 | 5 | **6** | 4 |
| 9 | F019 | Bulk CSV import | 3 | 2 | 2 | 2 | 4 | 4 | **5** | 3 |
| 10 | F014 | Historical holdings reconstruction | 3 | 2 | 2 | 3 | 3 | 4 | **5** | 3 |
| 11 | F043 | Corporate action price repair | 3 | 3 | 3 | 3 | 5 | 4 | **5** | 2 |

### Qualitative rank adjustments

| Adjustment | Reason |
|------------|--------|
| F042 ranks above F127 despite similar scores | Foundational for F043 and production data trust; complements V1 F020 |
| F043 ranked last among Phase 2 items | Must follow F042; high financial risk if run without DQ governance |
| F143 ranked high on score but Phase 4 | High readiness but high **maintenance cost**; defer until V1 product stabilizes |
| F144 below F143 | Lower leverage; parallel product domain |
| F060 Phase 3 not Phase 1 | Collaboration benefits from hardened F003/F005 account model |

---

## Per-feature analysis

### F003 — User Invite Flow

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Required for multi-user / invite-only registration model already in production posture |
| Strategic | 4 | Platform expansion beyond single-operator demo |
| Leverage | 4 | Enables F060 collaboration and admin user management |
| Complexity | 2 | Already built; V2 = spec + hardening |
| Risk | 3 | Token security, expiry, admin abuse |
| Readiness | 5 | Full flow + tests |

**Recommendation:** Phase 1 — bundle with F005 as **Account & Access Management**.

---

### F005 — Session Management

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Security hygiene for multi-device users |
| Strategic | 3 | Completes account lifecycle alongside F004 (V1) |
| Leverage | 3 | Shared auth infrastructure with F003 |
| Complexity | 2 | API exists; UI partial in Settings |
| Risk | 3 | Session revocation edge cases |
| Readiness | 4 | `AuthSessionTest` covers list/revoke |

**Recommendation:** Phase 1 with F003 — same initiative.

---

### F014 — Historical Holdings Reconstruction

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | As-of holdings analytics; distinct from V1 snapshots (F015) |
| Strategic | 2 | Portfolio analytics extension |
| Leverage | 2 | Shares transaction/OHLCV infra with F019 |
| Complexity | 3 | Backend complete; needs dedicated UI and validation |
| Risk | 3 | Financial correctness for as-of queries |
| Readiness | 4 | `PortfolioHistoricalHoldingsServiceTest` |

**Recommendation:** Phase 3 after F019 import validation patterns.

---

### F019 — Bulk CSV Import

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Faster ledger onboarding |
| Strategic | 2 | Convenience, not core TOS |
| Leverage | 2 | Shared validation with transaction write path |
| Complexity | 2 | UI exists; needs PHP feature tests + edge cases |
| Risk | 4 | Bad imports corrupt holdings/cash |
| Readiness | 4 | Component + JS parser test |

**Recommendation:** Phase 3 — precede F014 UI if import quality gates are shared.

---

### F042 — Data Quality Detection/Resolution

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Ops trust in OHLCV and corporate-action data |
| Strategic | 5 | Foundational for production hardening |
| Leverage | 5 | Enables F043; extends V1 F020 corporate actions |
| Complexity | 4 | Full admin subsystem already shipped |
| Risk | 4 | Wrong resolution corrupts prices/holdings |
| Readiness | 4 | UI + services + migrations; sparse PHPUnit coverage |

**Recommendation:** Phase 1 — **Market Data Quality** initiative anchor.

---

### F043 — Corporate Action Price Repair

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Ops repair after bad prices |
| Strategic | 3 | Production maintenance |
| Leverage | 3 | Depends on F042 issue/evidence model |
| Complexity | 3 | Services + cpanel scripts exist |
| Risk | 5 | Direct price/holding mutation |
| Readiness | 4 | `CorporateActionPriceRepairServiceTest` |

**Recommendation:** Phase 2 — **after F042**; not standalone first.

---

### F060 — Shared Screener Import

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Cross-portfolio screener reuse |
| Strategic | 3 | Collaboration within single-user multi-portfolio model |
| Leverage | 2 | Benefits from F003 account clarity |
| Complexity | 2 | Import API + tab exist |
| Risk | 2 | Scope/watchlist mapping errors |
| Readiness | 4 | Embedded in screener UI |

**Recommendation:** Phase 3 — after Account & Access formalized.

---

### F127 — Portfolio Alerts (non-TOS)

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Holding-level monitoring beyond TOS Telegram recs |
| Strategic | 3 | Parallel alert product |
| Leverage | 3 | Reuses screener-like formula evaluator |
| Complexity | 3 | Substantial existing implementation |
| Risk | 3 | Alert noise, formula errors |
| Readiness | 5 | Broad test suite |

**Recommendation:** Phase 2 — **harden and spec** rather than rebuild. Distinct from TOS `NotificationEngine` (Telegram recommendation events).

---

### F137 — Recommendation Preview API

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Strategy tuning / research |
| Strategic | 4 | Platform API for analytics consumers |
| Leverage | 3 | Uses evaluation + pipeline components read-only |
| Complexity | 3 | Service exists; needs tests + contract stability |
| Risk | 2 | Read-only preview |
| Readiness | 3 | No PHPUnit feature tests |

**Recommendation:** Phase 3 — after core V2 foundations stable.

---

### F143 — In-App Contextual Help

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Reduces user error, supports onboarding |
| Strategic | 2 | Documentation UX |
| Leverage | 2 | Supports all pages including V1 TOS |
| Complexity | 2 | System exists; ongoing content maintenance |
| Risk | 1 | Low runtime risk |
| Readiness | 5 | 43 topics + static HTML |

**Recommendation:** Phase 4 — defer formal V2 until product churn slows (maintenance burden).

---

### F144 — Knowledge Board

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Research notes separate from trading workflow |
| Strategic | 2 | Parallel Knowledge product area |
| Leverage | 1 | Standalone |
| Complexity | 3 | Rich editor; content lifecycle |
| Risk | 2 | Low |
| Readiness | 5 | Full feature + tests |

**Recommendation:** Phase 4 — lowest strategic leverage; postpone formal V2 scope work.

---

## V2 initiative table

| Initiative | Features | Priority | Dependencies | Main benefit | Main risk |
|------------|----------|----------|--------------|--------------|-----------|
| **Account & Access** | F003, F005 | High | V1 F004 password reset (done) | Multi-user platform readiness | Security/token handling |
| **Market Data Quality** | F042 → F043 | High | V1 F020 corporate actions, OHLCV sync | Production data trust | Incorrect price repairs |
| **Portfolio History & Import** | F019 → F014 | Medium | V1 transactions, snapshots | Bulk onboarding + as-of analytics | Ledger corruption on import |
| **Monitoring & Alerts** | F127 | Medium | Daily price sync job | Holding-level alerts beyond TOS | Duplicate notification confusion with Telegram |
| **Collaboration** | F060 | Medium | F003 account model | Screener reuse across portfolios | Scope mapping errors |
| **Recommendation Platform** | F137 | Medium | V1 pipeline stable | Preview API for research UI | API contract churn |
| **Knowledge & Guidance** | F143, F144 | Low | Product stability | UX and research capture | Documentation drift / maintenance cost |

---

## What NOT to build yet (deliberate postponement)

| Feature | Wait because |
|---------|--------------|
| **F144 Knowledge Board** | Fully shipped; formal V2 adds maintenance obligation without unlocking other V2 work |
| **F143 Contextual help** | Fully shipped; content sync cost high while V1 still evolving |
| **F043 Price repair** | High risk until F042 governance is formalized |
| **F014 Historical holdings UI** | Backend exists; premature UI before F019 import validation patterns |
| **F060 Shared screener import** | Already works; low urgency until multi-user/account initiative complete |

---

## Final recommendation — first V2 initiative

### **Market Data Quality (F042)** — tied with **Account & Access (F003 + F005)** as parallel Phase 1 tracks

If only **one** initiative starts first:

**Choose F042 (Data Quality Center formalization)** when the primary V2 goal is **production hardening** and trust in market data feeding the frozen V1 TOS pipeline.

**Choose F003 + F005 (Account & Access)** when the primary V2 goal is **multi-user deployment** and collaboration prerequisites.

Both can run in parallel with minimal overlap. **Do not start F043 until F042 is formalized.** *(Satisfied — both COMPLETE as of 2026-08-09.)*

---

*See also: [V2-DEPENDENCIES.md](./V2-DEPENDENCIES.md), [V2-ROADMAP.md](./V2-ROADMAP.md)*
