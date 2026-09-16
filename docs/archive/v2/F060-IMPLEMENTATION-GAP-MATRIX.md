# F060 Implementation Gap Matrix — Shared Screener Import

**Date:** 2026-08-09  
**Status:** **`F060_COMPLETE_WITH_NON_BLOCKERS`**  
**Related:** [F060-SHARED-SCREENER-IMPORT-SPEC.md](./F060-SHARED-SCREENER-IMPORT-SPEC.md), [F060-BOUNDARY.md](./F060-BOUNDARY.md), [F060-POLICY-DECISIONS.md](./F060-POLICY-DECISIONS.md)

Compliance measured against **DECIDED**. Hardening delivered 2026-08-09.

---

## Legend

| Status | Meaning |
|--------|---------|
| **COMPLIANT** | Matches DECIDED |
| **NON_BLOCKER** | Residual open PD or polish; does not fail MUST |
| **OUT_OF_SCOPE** | Not F060 |

---

## 1. Product / AuthZ (post-hardening)

| ID | Item | Status |
|----|------|--------|
| G-004/005 | Classic shared list/import same-user | **COMPLIANT** |
| G-006 | Registry shared list/GET/import same-user | **COMPLIANT** |
| G-011 | Name suffix `(1)`, `(2)`, … | **COMPLIANT** |
| G-012 | Classic owner-only write/delete | **COMPLIANT** |
| G-020/021/031 | Cross-user denial | **COMPLIANT** |
| G-022/017 | Private denial | **COMPLIANT** |
| G-025/030 | Registry GET + classic parity | **COMPLIANT** |
| G-026/027 | Shared field contract | **COMPLIANT** |
| G-028/029 | Eligibility + Discovery same-user | **COMPLIANT** |
| G-030 | No admin bypass | **COMPLIANT** |
| G-040–045 | Fork / lifecycle | **COMPLIANT** |
| G-009/010 | Import remap | **NON_BLOCKER** (PD-10) |
| PD-22 | 404 vs 403 | **NON_BLOCKER** |

Also hardened: `BacktestSimulationEngine` entry/exit screener pin (same scope).

---

## 2. Tests

| ID | Status |
|----|--------|
| T-001…T-012 AuthZ / import / naming / eligibility / Discovery | **COMPLIANT** (`F060SharedScreenerAuthzTest` + registry/shared updates) |
| T-013 FE e2e | **NON_BLOCKER** / deferred |

---

## 3. Documentation

| ID | Status |
|----|--------|
| Help / guide / registry static docs | **COMPLIANT** (same-account wording) |
| F060 pack indexes | **COMPLIANT** |

---

## 4. Non-blockers

1. PD-10 import remap — preserve CURRENT until separately decided.  
2. PD-22 error code shape — preserve 404.  
3. No dedicated frontend e2e for Shared tab.

---

## 5. Readiness / delivery

| Gate | State |
|------|-------|
| DECIDED policies implemented | **Yes** |
| Cross-user SECURITY_HARDENING closed | **Yes** |
| **F060_COMPLETE_WITH_NON_BLOCKERS** | **Yes** |
