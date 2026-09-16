# Specified but Unimplemented Features

**Date:** 2026-08-08 (reconciled)  
**Source:** Derived from [FEATURE-COVERAGE-MATRIX.md](./FEATURE-COVERAGE-MATRIX.md) primary status + V1 scope columns

---

## V1-Required Gaps (from matrix)

Rows where `V1 Scope = V1_REQUIRED` and primary status is not fully IMPLEMENTED:

### PARTIALLY_IMPLEMENTED (5 rows) — genuine V1 gaps

| ID | Capability | Primary Status | Gap type | Evidence |
|----|------------|----------------|----------|----------|
| F015 | Portfolio snapshots UI | PARTIALLY_IMPLEMENTED | UI GAP | `portfolio_portfolio_snapshots`; Dashboard limited |
| F098 | Market gates in live recommendations | PARTIALLY_IMPLEMENTED | CODE GAP | `MarketAnalyticsService`; integration depth varies |
| F148 | Optional scheduled pipeline | PARTIALLY_IMPLEMENTED | CODE GAP | `TRADING_OS_PIPELINE_SCHEDULE=true`; off by default |
| F149 | Run after daily sync hook | PARTIALLY_IMPLEMENTED | CODE GAP | Config stub; not fully wired in `routes/console.php` |
| F155 | Broad feature test coverage | PARTIALLY_IMPLEMENTED | TEST GAP | 117 PHP tests; no React tests; backtest E2E missing |

### SPECIFIED_NOT_IMPLEMENTED (0 rows)

**No V1-required capability has primary status SPECIFIED_NOT_IMPLEMENTED.**

The initial audit incorrectly listed 4 items here by mixing partial implementation, out-of-scope deferrals, and secondary categories:

| Initial claim | Reconciled classification |
|---------------|---------------------------|
| Hard data publish gates (F040) | V1_OUT_OF_SCOPE (PB-001 deferred per SD-004) — not a V1 gap |
| Run-after-daily-sync (F149) | V1_REQUIRED + PARTIALLY_IMPLEMENTED |
| Dedicated Evaluations page (F076) | V1_REQUIRED + IMPLEMENTED + DEVIATION:DEV-001 (redirect works) |
| Cash dedicated tests | Not a separate row — cash rows F021–F032 are IMPLEMENTED; test gap is part of F155 |

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
| A — V1 partial gaps | 5 | V1_REQUIRED + PARTIALLY_IMPLEMENTED |
| B — V1 fully missing | 0 | V1_REQUIRED + SPECIFIED_NOT_IMPLEMENTED |
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
