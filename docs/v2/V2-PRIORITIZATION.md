# V2 Feature Prioritization

**Date:** 2026-08-10 (closure housekeeping)  
**Program status:** **SD-035 V2 = CLOSED**  
**Authoritative snapshot:** [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md)  
**Historical note:** Scores, rankings, and “first initiative” recommendations below are **historical planning context** from 2026-08-09. They do **not** authorize new work and must not be read as a live backlog of unfinished SD-035 initiatives.

All eleven SD-035 deferred capabilities are **CLOSED**. The prioritization exercise has **concluded**. Do **not** generate a new priority ranking. Do **not** treat F003/F005/F019/F014/F060/F137/F143/F144 (or F042/F043/F127) as candidates for implementation under SD-035.

**V1 baseline:** [Final audit](../audits/2026-08-09-feature-coverage-final/) — 119 capabilities frozen (SD-035)

---

## Purpose (historical)

Originally: recommend priority, sequencing, and grouping for the eleven capabilities deferred from frozen V1. **No new implementation is authorized by this document.** Delivery is complete; see packs + final reconciliation.

---

## Scoring framework (historical)

| Dimension | Range | Meaning |
|-----------|------:|---------|
| Product Value | 1–5 | User impact, workflow importance |
| Strategic Importance | 1–5 | Directional product value |
| Dependency Leverage | 1–5 | Enables other V2 work |
| Readiness | 1–5 | Existing implementation maturity |
| Implementation Complexity | 1–5 | Effort to formalize/harden (lower = easier) |
| Risk | 1–5 | Data/security/ops failure impact |

**Priority Score** = (Product + Strategic + Leverage + Readiness) − (Complexity + Risk)

Scores were comparative planning tools, not absolute truth.

---

## Final implementation state (current)

*Updated 2026-08-10 — all eleven CLOSED. Older “MOSTLY IMPLEMENTED” planning labels are superseded.*

| ID | Feature | Final status | Evidence |
|----|---------|--------------|----------|
| F003 | User invite flow | **CLOSED** (`F003_COMPLIANT_WITH_NON_BLOCKERS`) | Invite pack + runtime |
| F005 | Session management | **CLOSED** (`F005_COMPLETE_WITH_NON_BLOCKERS`) | Session pack + Settings UI + PD-006 |
| F014 | Historical holdings reconstruction | **CLOSED** (`F014_COMPLETE_WITH_NON_BLOCKERS`) | API + dedicated UI + tests |
| F019 | Bulk CSV import | **CLOSED** (`F019_COMPLETE_WITH_NON_BLOCKERS`) | Bulk API + UI + tests |
| F042 | Data quality detection/resolution | **CLOSED** (`F042_COMPLETE_WITH_NON_BLOCKERS`) | DQ Center + hardening |
| F043 | Corporate action price repair | **CLOSED** (`F043_COMPLETE`) | Factor path + single-writer |
| F060 | Shared screener import | **CLOSED** (`F060_COMPLETE_WITH_NON_BLOCKERS`) | Same-user sharing + import |
| F127 | Portfolio alerts (non-TOS) | **CLOSED** (`F127_COMPLETE_WITH_NON_BLOCKERS`) | Alert policies + hardening |
| F137 | Recommendation preview API | **CLOSED** (`F137_COMPLETE_WITH_NON_BLOCKERS`) | Shared decision core + `F137RecommendationPreviewTest` |
| F143 | In-app contextual help | **CLOSED** (`F143_COMPLETE_WITH_NON_BLOCKERS`) | Formalization of shipped help (~44 topics) |
| F144 | Knowledge Board | **CLOSED** (`F144_COMPLETE_WITH_NON_BLOCKERS`) | Formalization of shipped Knowledge Board |

---

## Priority table (historical planning context)

*Do not re-rank. Retained for traceability of how Phase 1–4 sequencing was chosen.*

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

### Qualitative rank adjustments (historical)

| Adjustment | Reason |
|------------|--------|
| F042 ranks above F127 despite similar scores | Foundational for F043 and production data trust; complements V1 F020 |
| F043 ranked last among Phase 2 items | Must follow F042; high financial risk if run without DQ governance |
| F143 ranked high on score but Phase 4 | High readiness but high **maintenance cost**; defer until V1 product stabilizes |
| F144 below F143 | Lower leverage; parallel product domain |
| F060 Phase 3 not Phase 1 | Collaboration benefits from hardened F003/F005 account model |

---

## Per-feature analysis (historical)

*Scoring notes below are planning-era rationale. Final delivery status is in the “Final implementation state” table above and in initiative packs.*

### F003 — User Invite Flow

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Required for multi-user / invite-only registration model already in production posture |
| Strategic | 4 | Platform expansion beyond single-operator demo |
| Leverage | 4 | Enables F060 collaboration and admin user management |
| Complexity | 2 | Already built; V2 = spec + hardening |
| Risk | 3 | Token security, expiry, admin abuse |
| Readiness | 5 | Full flow + tests |

**Historical recommendation:** Phase 1 — bundle with F005 as **Account & Access Management**. **Delivered / CLOSED.**

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

**Historical recommendation:** Phase 1 with F003. **Delivered / CLOSED.**

---

### F014 — Historical Holdings Reconstruction

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | As-of holdings analytics; distinct from V1 snapshots (F015) |
| Strategic | 2 | Portfolio analytics extension |
| Leverage | 2 | Benefits from solid import validation (F019) |
| Complexity | 3 | Ledger replay + valuation edge cases |
| Risk | 3 | Misleading as-of numbers if prices incomplete |
| Readiness | 4 | Service existed; UI followed in delivery |

**Historical recommendation:** Phase 3 after F019. **Delivered / CLOSED.**

---

### F019 — Bulk CSV Import

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Onboarding / migration of transaction history |
| Strategic | 2 | Data platform hygiene |
| Leverage | 2 | Patterns feed F014 confidence |
| Complexity | 2 | UI existed; harden all-or-nothing write |
| Risk | 4 | Ledger corruption on bad import |
| Readiness | 4 | Frontend + write path matured in delivery |

**Historical recommendation:** Phase 3 before F014. **Delivered / CLOSED.**

---

### F042 — Data Quality Detection/Resolution

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Trust in market data |
| Strategic | 5 | Production hardening for TOS pipeline |
| Leverage | 5 | Unlocks F043 safely |
| Complexity | 4 | Detection + governance UX |
| Risk | 4 | Wrong accept/reject decisions |
| Readiness | 4 | DQ Center shipped + hardened |

**Historical recommendation:** Phase 1 parallel track. **Delivered / CLOSED.**

---

### F043 — Corporate Action Price Repair

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Correct adjusted history after CA |
| Strategic | 3 | Completes Market Data Quality |
| Leverage | 3 | Depends on F042 handoff |
| Complexity | 3 | Factor repair + single-writer |
| Risk | 5 | Incorrect price mutations |
| Readiness | 4 | Delivered after F042 |

**Historical recommendation:** Phase 2 after F042. **Delivered / CLOSED.**

---

### F060 — Shared Screener Import

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Reuse screeners across portfolios |
| Strategic | 3 | Collaboration within account |
| Leverage | 2 | Benefits from F003 clarity |
| Complexity | 2 | Import fork already existed |
| Risk | 2 | Scope mapping / AuthZ |
| Readiness | 4 | Hardened same-user sharing |

**Historical recommendation:** Phase 3. **Delivered / CLOSED.**

---

### F127 — Portfolio Alerts (non-TOS)

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 4 | Holding-level alerts beyond TOS |
| Strategic | 3 | Monitoring completeness |
| Leverage | 3 | Uses daily sync |
| Complexity | 3 | Policy evaluation ordering |
| Risk | 3 | Confusion with Telegram TOS digests |
| Readiness | 5 | Framework already rich |

**Historical recommendation:** Phase 2. **Delivered / CLOSED.**

---

### F137 — Recommendation Preview API

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Research UI without persist side effects |
| Strategic | 4 | Recommendation platform clarity |
| Leverage | 3 | Shared decision core |
| Complexity | 3 | Parity with generate path |
| Risk | 2 | Contract churn |
| Readiness | 3→5 | Feature tests added in delivery |

**Historical recommendation:** Phase 3. **Delivered / CLOSED.**

---

### F143 — In-app Contextual Help

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Operator guidance |
| Strategic | 2 | UX polish |
| Leverage | 2 | Low unlock |
| Complexity | 2 | Content governance |
| Risk | 1 | Low |
| Readiness | 5 | Runtime already shipped |

**Historical recommendation:** Phase 4 formalization. **CLOSED** (pack only; no mandatory implementation phase).

---

### F144 — Knowledge Board

| Dimension | Score | Notes |
|-----------|------:|-------|
| Product Value | 3 | Research capture |
| Strategic | 2 | Parallel knowledge product |
| Leverage | 1 | Standalone |
| Complexity | 3 | Rich editor; content lifecycle |
| Risk | 2 | Low |
| Readiness | 5 | Full feature + tests |

**Historical recommendation:** Phase 4 formalization. **CLOSED** (pack only; no mandatory implementation phase).

---

## V2 initiative table (historical grouping — all CLOSED)

| Initiative | Features | Historical priority | Dependencies | Final status |
|------------|----------|---------------------|--------------|--------------|
| **Account & Access** | F003, F005 | High | V1 F004 | **CLOSED** |
| **Market Data Quality** | F042 → F043 | High | V1 F020 | **CLOSED** |
| **Portfolio History & Import** | F019 → F014 | Medium | V1 transactions | **CLOSED** |
| **Monitoring & Alerts** | F127 | Medium | Daily sync | **CLOSED** |
| **Collaboration** | F060 | Medium | F003 | **CLOSED** |
| **Recommendation Platform** | F137 | Medium | V1 pipeline | **CLOSED** |
| **Knowledge & Guidance** | F143, F144 | Low | Product stability | **CLOSED** |

---

## Historical postponement rationale (superseded)

*Planning-era “wait because” notes. All items below are now **CLOSED** as SD-035 initiatives. Residual product polish remains deferred/non-blocking per packs — not unfinished SD-035 work.*

| Feature | Historical wait because | Current |
|---------|-------------------------|---------|
| **F144** | Formal V2 adds maintenance obligation | **CLOSED** (formalization done) |
| **F143** | Content sync cost while V1 evolving | **CLOSED** (formalization done) |
| **F043** | High risk until F042 formalized | **CLOSED** (after F042) |
| **F014** | Premature UI before F019 patterns | **CLOSED** (after F019) |
| **F060** | Low urgency until account initiative | **CLOSED** (after F003) |

---

## Historical recommendation — first V2 initiative (superseded)

### **Market Data Quality (F042)** — tied with **Account & Access (F003 + F005)** as parallel Phase 1 tracks

Planning choice (2026-08-09): start F042 and/or F003+F005; do not start F043 until F042 formalized.

**Outcome:** Both Phase 1 tracks and all later phases **delivered**. Sequencing guidance is **historical only**. There is **no first / next V2 initiative** remaining under SD-035.

See [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md) and [V2-ROADMAP.md](./V2-ROADMAP.md).

---

*See also: [V2-FINAL-RECONCILIATION.md](./V2-FINAL-RECONCILIATION.md), [V2-DEPENDENCIES.md](./V2-DEPENDENCIES.md), [V2-ROADMAP.md](./V2-ROADMAP.md)*
