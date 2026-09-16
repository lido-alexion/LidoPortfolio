# V1 Feature Coverage Summary

**Date:** 2026-08-08 (reconciled)  
**Baseline:** TOS-V1.0-2026-07-25  
**Detail matrix:** [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md)

---

## Accounting / Classification Method

This audit uses **two independent axes** per capability row:

1. **Primary status** — mutually exclusive implementation state (exactly one per row):
   - IMPLEMENTED | PARTIALLY_IMPLEMENTED | SPECIFIED_NOT_IMPLEMENTED | OUT_OF_SCOPE | AMBIGUOUS

2. **V1 scope** — mutually exclusive product scope (exactly one per row):
   - V1_REQUIRED | V1_OUT_OF_SCOPE | V1_SCOPE_AMBIGUOUS

**Secondary attributes** (may combine on the same row; **never** added to row totals):

- `NOT_SPECIFIED` — implemented capability inadequately documented in current specs
- `DEVIATION:*` — behaviour differs from spec (e.g. SD-001, DEV-001)

**Rules:**

- Primary status counts must sum to **159** (total matrix rows).
- V1 scope counts must sum to **159**.
- **Strict V1 coverage** uses only `V1_REQUIRED` rows where primary status is IMPLEMENTED, PARTIALLY_IMPLEMENTED, or SPECIFIED_NOT_IMPLEMENTED.
- `V1_SCOPE_AMBIGUOUS` rows are **excluded** from the strict V1 denominator unless governance explicitly resolves them.
- `V1_OUT_OF_SCOPE` rows are **excluded** from V1 coverage (not counted as gaps).
- **Deviations do not create additional capability rows** — they annotate existing rows (e.g. F001 Sanctum vs JWT).
- **Implemented-but-unspecified** is a documentation secondary attribute, not a primary status.
- **Ambiguous V1 scope ≠ unimplemented** — e.g. Strategy Backtesting is IMPLEMENTED with V1_SCOPE_AMBIGUOUS.

---

## Reconciliation Tables

### Primary status (mutually exclusive)

| Primary Status | Count |
|----------------|------:|
| IMPLEMENTED | 124 |
| PARTIALLY_IMPLEMENTED | 13 |
| SPECIFIED_NOT_IMPLEMENTED | 1 |
| OUT_OF_SCOPE | 21 |
| AMBIGUOUS | 0 |
| **TOTAL** | **159** |

### V1 scope (mutually exclusive)

| V1 Scope | Count |
|----------|------:|
| V1_REQUIRED | 115 |
| V1_OUT_OF_SCOPE | 29 |
| V1_SCOPE_AMBIGUOUS | 15 |
| **TOTAL** | **159** |

### V1-required implementation status (strict coverage)

| V1 Required — Primary Status | Count |
|------------------------------|------:|
| IMPLEMENTED | 110 |
| PARTIALLY_IMPLEMENTED | 5 |
| SPECIFIED_NOT_IMPLEMENTED | 0 |
| **TOTAL V1 REQUIRED** | **115** |

### Secondary attributes (non-exclusive; do not sum to 159)

| Secondary Attribute | Count | Row IDs |
|---------------------|------:|---------|
| NOT_SPECIFIED | 15 | F003–F005, F014, F019, F020, F042, F043, F058, F060, F093, F127, F137, F143, F144 |
| DEVIATION | 6 | F001 (SD-001), F039 (SD-004), F076 (DEV-001), F115 (DEV-004), F142 (DEV-001), F152 (DEV-003) |

---

## Coverage Percentages

| Metric | Numerator | Denominator | Result |
|--------|-----------|-------------|--------|
| **Strict V1 implementation coverage** | IMPLEMENTED_V1 = 110 | V1_REQUIRED with impl status = 115 | **95.7%** |
| Weighted effective (supplementary) | 110 + 0.5×5 = 112.5 | 115 | 97.8% |

**Strict formula:** `IMPLEMENTED_V1 / (IMPLEMENTED_V1 + PARTIALLY_IMPLEMENTED_V1 + SPECIFIED_NOT_IMPLEMENTED_V1)`

**Partial V1-required rows (5):** F015, F098, F148, F149, F155

### Layer coverage (V1_REQUIRED rows only, n=115)

Denominator for each layer = rows where layer is applicable (excludes N/A).

| Layer | YES | PARTIAL | NO | N/A | Formula | Coverage |
|-------|----:|--------:|---:|----:|---------|----------|
| DB | 98 | 0 | 0 | 17 | YES / (YES+PARTIAL+NO) | **100.0%** |
| Backend | 112 | 3 | 0 | 0 | (YES + 0.5×PARTIAL) / total | **98.7%** |
| API | 111 | 2 | 0 | 2 | (YES + 0.5×PARTIAL) / applicable | **99.1%** |
| Frontend | 96 | 11 | 1 | 7 | (YES + 0.5×PARTIAL) / applicable | **94.0%** |
| Jobs | 18 | 1 | 0 | 96 | (YES + 0.5×PARTIAL) / applicable | **97.4%** |
| Tests | 73 | 41 | 1 | 0 | (YES + 0.5×PARTIAL) / total | **81.3%** |

---

## What Changed from Initial Audit

| Issue | Old (incorrect) | New (reconciled) |
|-------|-----------------|------------------|
| Status categories summed to | 170 (overlapping) | 159 (mutually exclusive primary) |
| V1 required count | 129 (V1 Required=YES column) | 115 (governance-aligned V1_REQUIRED) |
| Fully implemented V1 | 98 | 110 |
| Strict V1 coverage | 75.9% | **95.7%** |
| Specified-not-implemented V1 | 4 | 0 |
| Partial V1 | 18 | 5 |
| Implemented-not-specified | 14 (primary category) | 15 (secondary attribute) |
| Deviations | 9 (primary category) | 6 (secondary attribute) |

**Root cause:** The initial report treated `IMPLEMENTED_NOT_SPECIFIED`, `IMPLEMENTED_WITH_DEVIATION`, and `AMBIGUOUS` as primary statuses alongside `IMPLEMENTED`, double-counting rows. V1 Required=YES was also over-inclusive (129 vs 115).

---

## V1_SCOPE_AMBIGUOUS Features (15 rows)

Implemented capabilities not explicitly listed in `MVP_SCOPE.md` included or excluded lists:

| ID | Capability | Primary Status |
|----|------------|----------------|
| F003 | User invite flow | IMPLEMENTED |
| F004 | Password reset | IMPLEMENTED |
| F005 | Session management | PARTIALLY_IMPLEMENTED |
| F014 | Historical holdings reconstruction | IMPLEMENTED |
| F019 | Bulk CSV import | IMPLEMENTED |
| F020 | Corporate actions | IMPLEMENTED |
| F042 | Data quality detection/resolution | IMPLEMENTED |
| F043 | Corporate action price repair | IMPLEMENTED |
| F058 | Screener backtesting (hit matrix) | IMPLEMENTED |
| F060 | Shared screener import | IMPLEMENTED |
| F093 | Strategy backtesting | IMPLEMENTED |
| F127 | Portfolio alerts (non-TOS) | IMPLEMENTED |
| F137 | Recommendation preview API | IMPLEMENTED |
| F143 | In-app contextual help | IMPLEMENTED |
| F144 | Knowledge Board | IMPLEMENTED |

These are **excluded from strict V1 coverage denominator** pending product decision — not counted as gaps.

---

## Backtesting Classification

| Feature | Primary Status | V1 Scope | Spec Coverage |
|---------|----------------|----------|---------------|
| **Strategy Backtesting** (F093) | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT_SPECIFIED |
| **Screener Backtesting** (F058) | IMPLEMENTED | **V1_SCOPE_AMBIGUOUS** | NOT_SPECIFIED |
| Market gates in strategy backtest (F099) | SPECIFIED_NOT_IMPLEMENTED | V1_OUT_OF_SCOPE | N/A (not required V1) |
| Live paper trading (F157) | OUT_OF_SCOPE | V1_OUT_OF_SCOPE | Future live-trading specs |

**Conclusion:** Both backtest systems are **implemented**. Their **V1 product status is ambiguous** — not listed in `MVP_SCOPE.md` — not "missing."

---

## Genuine V1 Implementation Gaps (5 partial rows)

| ID | Capability | Gap |
|----|------------|-----|
| F015 | Portfolio snapshots UI | Backend complete; UI visualization limited |
| F098 | Market gates in live recommendations | Integration depth partial |
| F148 | Optional scheduled pipeline | Off by default; ops wiring incomplete |
| F149 | Run after daily sync hook | Config exists; not fully wired |
| F155 | Broad feature test coverage | 117 PHP tests; no React tests; backtest E2E missing |

**V1-required SPECIFIED_NOT_IMPLEMENTED: 0**

Deferred production hardening (V1_OUT_OF_SCOPE per governance): F040 hard data publish gates (PB-001).

---

## Secondary / Documentation / Deviation Issues (NOT implementation gaps)

| Type | Items |
|------|-------|
| NOT_SPECIFIED (15 rows) | See IMPLEMENTED-BUT-UNSPECIFIED.md |
| DEVIATION (6 rows) | SD-001, SD-004, DEV-001, DEV-003, DEV-004 — see SPECIFICATION-DEVIATIONS.md |
| V1 scope decisions pending | Backtests, parallel portfolio features (corporate actions, knowledge board, etc.) |
| V1_OUT_OF_SCOPE by design | JWT, email, broker, multi-strategy, full indicator registry, etc. (29 rows) |

---

## P0 Blockers

**0** for continuing V1 work or internal testing (`MVP_VERDICT.md`: YES).

---

# Implementation Readiness

## Verdict: **READY_WITH_MINOR_GAPS**

The V1 decision-support workflow is implemented end-to-end. Strict V1 coverage is **95.7%** (110/115 governance-aligned required capabilities fully implemented). Remaining work is 5 partial capabilities, spec documentation, and test depth — not missing pipeline stages.

---

## Recommended Next Steps

1. Resolve V1_SCOPE_AMBIGUOUS items (especially backtests) in governance docs — documentation only
2. Complete 5 partial V1 capabilities (scheduling, market gates, tests)
3. Add spec sections for NOT_SPECIFIED features — do not rebuild them
4. Internal soak via `MVP_TEST_SCRIPT.md`

---

*See also: [BACKTEST-COVERAGE.md](./BACKTEST-COVERAGE.md), [SPECIFIED-BUT-UNIMPLEMENTED.md](./SPECIFIED-BUT-UNIMPLEMENTED.md), [IMPLEMENTED-BUT-UNSPECIFIED.md](./IMPLEMENTED-BUT-UNSPECIFIED.md), [SPECIFICATION-DEVIATIONS.md](./SPECIFICATION-DEVIATIONS.md)*
