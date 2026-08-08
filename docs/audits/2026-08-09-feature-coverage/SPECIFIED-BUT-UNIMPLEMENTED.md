# Specified but Unimplemented Features

**Date:** 2026-08-09 (post-implementation)  
**Source:** Derived from [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md) primary status + V1 scope columns  
**Previous audit:** [2026-08-08](../2026-08-08-feature-coverage/SPECIFIED-BUT-UNIMPLEMENTED.md)

---

## V1-Required Gaps (from matrix)

Rows where `V1 Scope = V1_REQUIRED` and primary status is not fully IMPLEMENTED:

### PARTIALLY_IMPLEMENTED (0 rows)

**No V1-required capability has primary status PARTIALLY_IMPLEMENTED.**

The five gaps identified in the 2026-08-08 audit (F015, F098, F148, F149, F155) were independently re-verified and upgraded to IMPLEMENTED. See [FEATURE-COVERAGE-SUMMARY.md](./FEATURE-COVERAGE-SUMMARY.md) § Five Previously Partial V1 Gaps.

### SPECIFIED_NOT_IMPLEMENTED (0 rows)

**No V1-required capability has primary status SPECIFIED_NOT_IMPLEMENTED.**

---

## Non-V1 SPECIFIED_NOT_IMPLEMENTED (1 row)

| ID | Capability | V1 Scope | Notes |
|----|------------|----------|-------|
| F099 | Market gates in strategy backtest | V1_OUT_OF_SCOPE | Explicitly disabled in `SimulationDayProcessor`; not required for V1 |

---

## V1_OUT_OF_SCOPE — Explicitly Not V1 (29 rows)

Do **not** implement for V1. Primary status varies (some partially built for future):

| ID | Capability | Primary Status |
|----|------------|----------------|
| F008 | JWT Bearer tokens | OUT_OF_SCOPE |
| F009 | Fine-grained RBAC matrix | OUT_OF_SCOPE |
| F033 | Alternate capital allocators | OUT_OF_SCOPE |
| F040 | Hard publish/validation gates | OUT_OF_SCOPE |
| F041 | Trading calendar product | OUT_OF_SCOPE |
| F048 | Unified Indicator Registry (full) | PARTIALLY_IMPLEMENTED |
| F049 | Liquidity/Tradability calculators | PARTIALLY_IMPLEMENTED |
| F050 | Strategy-param → Evaluation wiring | OUT_OF_SCOPE |
| F059 | Screener registry (import/export) | PARTIALLY_IMPLEMENTED |
| F068 | Standalone signal entity/API | OUT_OF_SCOPE |
| F069 | Full-universe mandatory scan | OUT_OF_SCOPE |
| F070 | Dedicated Discovery Engine spec doc | OUT_OF_SCOPE |
| F077 | Market regime factor in evaluation | PARTIALLY_IMPLEMENTED |
| F078 | Sector strength factor | PARTIALLY_IMPLEMENTED |
| F080 | ML scoring / pluggable rules | OUT_OF_SCOPE |
| F091 | Strategy registry (portable JSON) | PARTIALLY_IMPLEMENTED |
| F092 | Multi-strategy isolation | OUT_OF_SCOPE |
| F099 | Market gates in strategy backtest | SPECIFIED_NOT_IMPLEMENTED |
| F117 | Broker automation (Zerodha) | OUT_OF_SCOPE |
| F118 | GTT / stop-target orders | OUT_OF_SCOPE |
| F119 | Partial fills | OUT_OF_SCOPE |
| F125 | Email channel | OUT_OF_SCOPE |
| F126 | Webhook channel | OUT_OF_SCOPE |
| F132 | Full attribution / tax reporting | OUT_OF_SCOPE |
| F145 | Mandatory TypeScript / TanStack Query | OUT_OF_SCOPE |
| F151 | OpenAPI generation | OUT_OF_SCOPE |
| F153 | Repository/DTO refactor | OUT_OF_SCOPE |
| F156 | Trading Artifact Framework (full) | PARTIALLY_IMPLEMENTED |
| F157 | Live paper trading mode | OUT_OF_SCOPE |

---

## V1_SCOPE_AMBIGUOUS — Not counted as V1 gaps (15 rows)

Implemented capabilities absent from MVP_SCOPE.md included/excluded lists. See [IMPLEMENTED-BUT-UNSPECIFIED.md](./IMPLEMENTED-BUT-UNSPECIFIED.md).

**Not unimplemented** — product scope decision pending.

---

## Classification Summary

| Class | Count | Matrix filter |
|-------|------:|---------------|
| A — V1 partial gaps | **0** | V1_REQUIRED + PARTIALLY_IMPLEMENTED |
| B — V1 fully missing | **0** | V1_REQUIRED + SPECIFIED_NOT_IMPLEMENTED |
| C — V1 scope ambiguous (implemented) | 15 | V1_SCOPE_AMBIGUOUS |
| D — V1 out of scope | 29 | V1_OUT_OF_SCOPE |

---

## Deferred Production Hardening (not V1 demo blockers)

| Item | ID | Governance |
|------|-----|------------|
| Hard data publish gates | F040 | SD-004 deferred; PB-001 Critical backlog |
| Position review depth | (F128–F131 area) | MVP_VERDICT risk #2; review engine shallow vs full spec |

These are **V1_OUT_OF_SCOPE or quality depth**, not missing workflow stages.

---

## What NOT to Build (false gaps from engine specs alone)

Without `MVP_SCOPE.md` + `SPECIFICATION_DECISIONS.md`, ~29 rows appear "missing." All are **V1_OUT_OF_SCOPE** by governance decision.
