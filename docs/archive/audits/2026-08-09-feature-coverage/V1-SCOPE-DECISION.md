# V1 Scope Decision — 2026-08-09

## Approved V1 additions

The following capabilities, previously recorded as `V1_SCOPE_AMBIGUOUS` in the
[2026-08-09 feature coverage audit](./FEATURE-COVERAGE-MATRIX.md), are **formally
included in V1** by product-owner decision (SD-035):

| ID | Capability |
|----|------------|
| F004 | Password reset |
| F020 | Corporate actions (core split/bonus; not F043 price-repair tooling) |
| F058 | Screener backtesting (hit matrix) |
| F093 | Strategy backtesting (current shipped paper simulation) |

All four were **already implemented** at the time of this decision. No new
implementation was authorized.

---

## Approved V2 deferrals

The following capabilities remain **implemented in the repository** but are
**formally deferred to V2 / future** (SD-035):

| ID | Capability |
|----|------------|
| F003 | User invite flow |
| F005 | Session management (list/revoke) |
| F014 | Historical holdings reconstruction |
| F019 | Bulk CSV import |
| F042 | Data quality detection/resolution |
| F043 | Corporate action price repair |
| F060 | Shared screener import |
| F127 | Portfolio alerts (non-TOS) |
| F137 | Recommendation preview API |
| F143 | In-app contextual help |
| F144 | Knowledge Board |

---

## Decision status

**Approved by product owner** on 2026-08-09.

Authoritative governance updates:

- [`specs/architecture/governance/MVP_SCOPE.md`](../../specs/architecture/governance/MVP_SCOPE.md)
- [`specs/architecture/governance/SPECIFICATION_DECISIONS.md`](../../specs/architecture/governance/SPECIFICATION_DECISIONS.md) — **SD-035**

---

## Relationship to audit

The [2026-08-09 feature coverage audit](./README.md) recorded these 15
capabilities as ambiguous and excluded them from the strict V1 denominator
(115 `V1_REQUIRED` rows at audit time).

This document records the **subsequent product-owner decision** that resolves
that ambiguity. The audit files themselves are **preserved as a historical
pre-decision baseline** — their metrics and `V1_SCOPE_AMBIGUOUS` classifications
were not rewritten.

After SD-035, the formal V1-required capability count is **119** (115 + 4
promotions). All four promoted capabilities were implemented at decision time,
so strict implementation coverage remains **119 / 119 = 100%**.

See also: [V1-SCOPE-RECOMMENDATIONS.md](./V1-SCOPE-RECOMMENDATIONS.md) (pre-decision evidence and rationale).

---

## Scope boundaries (not promoted)

| Promotion | Does **not** include |
|-----------|----------------------|
| F020 | F042 Data Quality Center, F043 price repair, F040 hard publish gates |
| F058 | Benchmark comparison, historical market gates in backtest, intraday simulation, fees/slippage enhancements beyond current code |
| F093 | Market gates in strategy backtest (F099), benchmark comparison, intraday stop-loss, advanced fees/slippage beyond current code |
| F004 | JWT, SSO, advanced RBAC, multi-tenant identity |

---

*Recorded 2026-08-09. Does not modify application code or tests.*
