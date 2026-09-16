# Specification Deviations

**Date:** 2026-08-09 (final baseline — frozen scope SD-035)  
**Previous audit:** [2026-08-09 pre-freeze](../2026-08-09-feature-coverage/SPECIFICATION-DEVIATIONS.md)  
**Classification:** Secondary attributes on existing matrix rows — **deviations do not create additional capability rows**

Primary matrix: [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)

---

## How Deviations Relate to the Matrix

| Concept | Role |
|---------|------|
| **Capability row** (F001–F159) | One primary status + one V1 scope |
| **DEVIATION secondary attribute** | Annotates a row where implementation differs from spec |
| **NOT_SPECIFIED secondary attribute** | Annotates a row where specs don't document shipped behaviour |
| **SD register entry** | Governance record explaining why deviation is accepted |

A deviation is **not** counted separately in primary status totals.

---

## Matrix Rows with DEVIATION Secondary Attribute (6)

| Matrix ID | Capability | Primary Status | V1 Scope | Deviation | Spec says | Implementation does | Action |
|-----------|------------|----------------|----------|-----------|-----------|---------------------|--------|
| F001 | Sanctum session login/logout | IMPLEMENTED | V1_REQUIRED | SD-001 | JWT Bearer auth | Sanctum cookie/session | Keep; spec/doc note |
| F039 | Dataset status / soft version | IMPLEMENTED | V1_REQUIRED | SD-004 | Hard publish gates | Soft `dataset_version` | PB-001 before production |
| F076 | Evaluations UI | IMPLEMENTED | V1_REQUIRED | DEV-001 | Dedicated `/evaluations` page | Redirects to `/candidates` | Spec update |
| F115 | Orders API | IMPLEMENTED | V1_REQUIRED | DEV-004 | Orders page + lifecycle | API exists; Transactions primary UX | Spec update |
| F142 | TOS nav pages | IMPLEMENTED | V1_REQUIRED | DEV-001 | Separate Evaluations nav | Evaluations merged into Candidates | Spec update |
| F152 | Engine layer | IMPLEMENTED | V1_REQUIRED | DEV-003 | Interface-driven engines | Concrete classes wrap Services | Document only |

---

## Accepted SD Register Entries (Governance — Not Separate Rows)

These explain deviations across the codebase. Each maps to existing capability rows or architectural decisions:

| SD | Topic | Maps to | Type |
|----|-------|---------|------|
| SD-001 | Sanctum vs JWT | F001 (+ F008 OUT_OF_SCOPE) | Secondary DEVIATION + excluded alt |
| SD-002 | `portfolio_*` schema | All DB-backed rows | Architecture note |
| SD-003 | No trading_sessions | F041 OUT_OF_SCOPE | Deferred |
| SD-004 | Soft dataset version | F039 DEVIATION; F040 OUT_OF_SCOPE | Accepted + deferred hard gate |
| SD-009 | Telegram only | F121–F124; F125–F126 OUT_OF_SCOPE | Accepted |
| SD-021 | Transaction traceability | F114 | Positive alignment |
| SD-022 | Informational recs | F102, F124 | Positive alignment |
| SD-023 | Five-stage model | F100, F103 | Positive alignment |
| SD-025 | Approval ≠ execution | F105, F111–F116 | Positive alignment |
| SD-026 | Cash + allocation | F021–F032, F109 | Positive alignment |
| SD-027–030 | Strategy config | F081–F090 | Positive deviation (added to V1) |
| SD-031 | Analytics ownership | F133–F136 | Positive alignment |
| SD-032 | Market Analysis Engine | F094–F098 | Positive deviation (added to V1) |
| SD-033 | Indicator Registry partial | F048 PARTIAL, V1_OUT_OF_SCOPE | Partial delivery |
| SD-034 | Artifact Framework partial | F059, F091, F156 | Partial delivery |

---

## DEV Entries (Material Differences Not in SD Register)

### DEV-001 — Evaluations UI merged into Candidates

| Field | Detail |
|-------|--------|
| **Matrix rows** | F076, F142 (secondary DEVIATION) |
| **Primary status** | IMPLEMENTED (functionality exists via Candidates page) |
| **Not** | SPECIFIED_NOT_IMPLEMENTED |
| **Action** | Spec update only |

### DEV-003 — Engine architecture (concrete vs interfaces)

| Field | Detail |
|-------|--------|
| **Matrix row** | F152 (secondary DEVIATION) |
| **Primary status** | IMPLEMENTED |
| **Action** | Document only; no V1 code change |

### DEV-004 — Orders API without Orders page

| Field | Detail |
|-------|--------|
| **Matrix row** | F115 (secondary DEVIATION) |
| **Primary status** | IMPLEMENTED |
| **Action** | Spec update only |

### DEV-007 — Strategy backtesting not in V1 scope docs

| Field | Detail |
|-------|--------|
| **Matrix row** | F093 (secondary NOT_SPECIFIED, not DEVIATION) |
| **Primary status** | IMPLEMENTED |
| **V1 scope** | V1_SCOPE_AMBIGUOUS |
| **Not a deviation** | Documentation gap; implementation matches as-built intent |
| **Action** | Spec update; do not rebuild |

### DEV-008 — Market gates disabled in strategy backtest

| Field | Detail |
|-------|--------|
| **Matrix row** | F099 |
| **Primary status** | SPECIFIED_NOT_IMPLEMENTED |
| **V1 scope** | V1_OUT_OF_SCOPE |
| **Not a V1 gap** | Explicitly not required for V1 backtest |

### DEV-009 — Notification skip when unconfigured

| Field | Detail |
|-------|--------|
| **Matrix row** | F122 |
| **Primary status** | IMPLEMENTED |
| **Type** | UX/ops concern (MVP_VERDICT risk #3), not spec deviation row |
| **Action** | Stronger empty-state warning (P2) |

---

## Deviations vs Missing Capabilities

| Entry | Is it a missing V1 capability? |
|-------|:-----------------------------:|
| SD-001 Sanctum vs JWT | No — F001 IMPLEMENTED |
| SD-004 soft vs hard gates | F040 OUT_OF_SCOPE for V1; F039 IMPLEMENTED with DEVIATION |
| SD-025 approval vs execution | No — F105 IMPLEMENTED by design |
| DEV-001 Evaluations redirect | No — F076 IMPLEMENTED with DEVIATION |
| DEV-007 Strategy backtesting | No — F093 IMPLEMENTED, NOT_SPECIFIED |

---

## Summary

| Category | Count | Counted in primary totals? |
|----------|------:|:--------------------------:|
| DEVIATION secondary attributes | 6 | No |
| NOT_SPECIFIED secondary attributes | 15 | No |
| SD register entries | 15+ | No (governance annotations) |
| V1-required partial gaps | 5 | Yes (primary PARTIALLY_IMPLEMENTED) |

---

*Cross-reference: [FEATURE-COVERAGE-SUMMARY.md](./FEATURE-COVERAGE-SUMMARY.md), [IMPLEMENTED-BUT-UNSPECIFIED.md](./IMPLEMENTED-BUT-UNSPECIFIED.md)*
