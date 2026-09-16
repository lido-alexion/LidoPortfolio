# Implemented but Unspecified Features

**Date:** 2026-08-09 (final baseline — frozen scope SD-035)  
**Matrix:** [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)

---

## Purpose

Secondary `NOT_SPECIFIED` attribute on **V2/future deferred** rows — informational documentation gaps only. **Not V1 gaps.**

Promoted V1 capabilities (F004, F020, F058, F093) are now documented in `MVP_SCOPE.md` SD-035 sections — no longer NOT_SPECIFIED.

---

## V2/future documentation gaps (11 rows)

| ID | Capability | Primary Status | Notes |
|----|------------|----------------|-------|
| F003 | User invite flow | IMPLEMENTED | Admin onboarding |
| F005 | Session management | PARTIALLY_IMPLEMENTED | Partial settings UI |
| F014 | Historical holdings reconstruction | IMPLEMENTED | No dedicated UI |
| F019 | Bulk CSV import | IMPLEMENTED | Convenience feature |
| F042 | Data quality detection/resolution | IMPLEMENTED | Data Quality Center |
| F043 | Corporate action price repair | IMPLEMENTED | Ops repair (not F020 core) |
| F060 | Shared screener import | IMPLEMENTED | Collaboration |
| F127 | Portfolio alerts (non-TOS) | IMPLEMENTED | Parallel alert system |
| F137 | Recommendation preview API | IMPLEMENTED | Analysis tooling |
| F143 | In-app contextual help | IMPLEMENTED | Documentation UX |
| F144 | Knowledge Board | IMPLEMENTED | Knowledge product |

These are **outside frozen V1 scope**. Document in future specs if product owner prioritizes V2 delivery.

---

## V1 rows with domain-spec depth gaps (informational)

F020, F058, F093 are **V1_REQUIRED** and listed in `MVP_SCOPE.md`, but lack dedicated domain-spec sections beyond governance + `implementation.md`. This is acceptable for frozen V1 — not an implementation gap.

---

## Cross-reference

Backtesting detail: [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md)
