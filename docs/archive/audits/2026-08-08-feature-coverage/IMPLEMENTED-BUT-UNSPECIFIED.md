# Implemented but Unspecified Features

**Date:** 2026-08-08 (reconciled)  
**Classification:** Secondary attribute `NOT_SPECIFIED` on existing matrix rows — **does not add to the 159 capability count**

Primary matrix: [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)

---

## Purpose

These capabilities are **implemented in the repository** but inadequately documented in current V1 specifications. They appear as a **secondary documentation attribute**, not a separate primary status.

---

## Secondary Classification Table

| Feature | Matrix ID | Primary Status | V1 Scope | Spec Coverage | Action |
|---------|-----------|----------------|----------|---------------|--------|
| User invite flow | F003 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document admin/auth support |
| Password reset | F004 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document admin/auth support |
| Session management | F005 | PARTIALLY_IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document; UI partial |
| Historical holdings reconstruction | F014 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document portfolio analytics |
| Bulk CSV import | F019 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document portfolio feature |
| Corporate actions | F020 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document portfolio domain |
| Data quality detection/resolution | F042 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document ops feature |
| Corporate action price repair | F043 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document ops feature |
| **Screener backtesting** (hit matrix) | F058 | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT SPECIFIED | Add Screener spec section |
| Shared screener import | F060 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Add Screener spec section |
| **Strategy backtesting** (paper simulation) | F093 | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT SPECIFIED | Document as-built capability |
| Portfolio alerts (non-TOS) | F127 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document parallel alert system |
| Recommendation preview API | F137 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Add Recommendation spec section |
| In-app contextual help | F143 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Reference from UI spec |
| Knowledge Board | F144 | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | NOT SPECIFIED | Document or mark optional |

**Total NOT_SPECIFIED secondary attributes: 15**

---

## Additional Implemented Features (no NOT_SPECIFIED flag but related)

These are documented elsewhere or partially specified; listed for completeness only:

| Feature | Matrix ID | Primary Status | V1 Scope | Notes |
|---------|-----------|----------------|----------|-------|
| Indicator Registry (partial) | F048 | PARTIALLY_IMPLEMENTED | V1_OUT_OF_SCOPE | SD-033 Epics 1–2; spec exists |
| Screener registry | F059 | PARTIALLY_IMPLEMENTED | V1_OUT_OF_SCOPE | SD-034 partial |
| Strategy registry | F091 | PARTIALLY_IMPLEMENTED | V1_OUT_OF_SCOPE | SD-034 partial |
| Artifact framework (partial) | F156 | PARTIALLY_IMPLEMENTED | V1_OUT_OF_SCOPE | SD-034 design |
| Stock Explorer analytics | F134 | IMPLEMENTED | V1_REQUIRED | Covered by SD-031 / Analytics spec |
| Market depth snapshots | — | — | — | Not a separate matrix row; sub-feature of market data |

---

## Backtesting (detailed)

See [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md).

| Feature | Primary Status | V1 Scope | Do NOT rebuild |
|---------|----------------|----------|:--------------:|
| Strategy Backtesting | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | Yes |
| Screener Backtesting | IMPLEMENTED | V1_SCOPE_AMBIGUOUS | Yes |

**Product decision pending:** Formal inclusion in V1 scope documentation. Implementation exists regardless.

---

## Cross-Cutting Recommendation

**Do not delete or rewrite these features.** Update specifications to reflect as-built behaviour. Distinguish:

- **TOS V1 pipeline features** (in MVP_SCOPE.md)
- **Parallel portfolio features** (corporate actions, knowledge board, alerts)
- **V1_SCOPE_AMBIGUOUS** (implemented; governance silent)
