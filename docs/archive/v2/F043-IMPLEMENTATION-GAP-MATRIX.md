# F043 — Implementation Gap Matrix

**Capability:** F043 Corporate Action Price Repair  
**Date:** 2026-08-09 (updated after implementation)  
**Authoritative spec:** [F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](./F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md)  
**Status:** `F043_COMPLETE`

---

## Status legend

| Status | Meaning |
|--------|---------|
| NO_GAP | Requirement satisfied by current code + adequate tests |
| IMPLEMENTATION_MISSING | Required behaviour absent |
| IMPLEMENTATION_PARTIAL | Partial behaviour; needs completion |
| TEST_MISSING | Behaviour present or specified; tests inadequate |
| DEFERRED | Explicitly postponed |
| OUT_OF_SCOPE | Not F043 |

---

## Gap matrix

| Requirement | Current Implementation | Status | Code Evidence | Test Evidence | Required Work |
|-------------|------------------------|--------|---------------|---------------|---------------|
| F043-R001 OHLC ÷ divisor | Shared adjuster | NO_GAP | `CorporateActionPriceAdjustmentService` | Adjustment + factor tests | None |
| F043-R002 Volume × multiplier | Shared adjuster | NO_GAP | Same | Same | None |
| F043-R003 Date range `< ex_date` | Shared adjuster | NO_GAP | `rowsQuery` | Factor OHLC tests | None |
| F043-R004 Split/bonus formulas | F020 path | NO_GAP | `factorsForAction` | Adjustment tests | None |
| F043-R005 Dry-run default | CLI/cPanel | NO_GAP | Command + cPanel | Preview non-mutation test | None |
| F043-R006 CA metadata idempotency | F020 path | NO_GAP | Repair service | Existing repair test | None |
| F043-R007 Continuity scan | F020 path | NO_GAP | Repair service | Existing scan test | Optional expand force/ambiguous |
| F043-R008 Discover pending factors | `scanPendingFactors` | NO_GAP | Repair service + model scope | Factor discovery test | None |
| F043-R009 Factor-driven apply | `repairPendingFactors` / `adjustHistoricalPricesByDivisors` | NO_GAP | Repair + adjustment services | Factor apply tests | None |
| F043-R010 Mark factor completed | Metadata writer | NO_GAP | `applyFactorRepair` | Apply/complete test | None |
| F043-R011 Skip completed factors | Idempotent gate | NO_GAP | Inspect/apply | Idempotency test | None |
| F043-R012 No ledger mutation | Factor path clean | NO_GAP | Repair service | Holdings/tx test | None |
| F043-R013 No F042 status change | No DQ resolution calls | NO_GAP | Factor path | Issue status assert | None |
| F043-R014 Multi-factor ordering | Ascending ex_date | NO_GAP | `scanPendingFactors` order | Multi-factor test | None |
| F043-R015 Preview non-mutating | Scan + dry-run + samples | NO_GAP | `inspectFactor` | Preview test | None |
| F043-R016 Audit summary | `metadata.ohlcv_repair` | NO_GAP | `applyFactorRepair` | Apply audit asserts | None |
| F043-R017 CLI + cPanel | Extended | NO_GAP | Command + deploy script | Manual ops | None |
| F043-R018 Admin API | Absent | DEFERRED | — | — | SHOULD / future |
| F043-R019 DB transaction wrap | Per-factor `DB::transaction` | NO_GAP | `applyFactorRepair` | Rollback test | None |
| F043-R020 Concurrent lock | `lockForUpdate` | NO_GAP (MySQL); soft on SQLite | `applyFactorRepair` | Relies on transaction + status | Document SQLite no-op |
| F043-R021 Sample before/after | Preview samples | NO_GAP | `sampleAdjustmentPreview` | Preview findings include samples | None |
| F043-R022 face_value_split | Supported type list | NO_GAP | `SUPPORTED_FACTOR_ACTION_TYPES` | face_value_split test | None |
| F043-R023 processing/failed states | Not added | DEFERRED | pending/completed only | — | Spec COULD |
| F043-R024 Auto-repair schedule | Absent | DEFERRED | — | — | Out of scope |
| F043-R025 Rollback snapshots | Absent | DEFERRED | — | — | Out of scope |
| Pipeline re-block until repaired | Unchanged F042 | OUT_OF_SCOPE | — | — | Do not change F042 |
| DualListed NSE purge | Separate | OUT_OF_SCOPE | — | — | Exclude |
| F043-R026 Single OHLCV writer / F020 delegate | F020 apply skips when `PriceAdjustmentFactor::findActiveOhlcvRepairForEvent`; CA repair `deferred_to_factor` | NO_GAP | `CorporateActionService`, model scope | `CorporateActionOhlcvDelegationTest` | None |

---

## Summary counts (post double-restatement hardening)

| Status | Count |
|--------|-------|
| NO_GAP | 21 |
| DEFERRED | 4 |
| OUT_OF_SCOPE | 2 |
| PARTIAL (concurrency proof) | R020 only |

## Non-blockers

1. Admin REST API / SPA for repair queue (SHOULD / COULD).
2. `lockForUpdate` is a no-op on SQLite; production MySQL + status gates provide application-level duplicate protection (no automated concurrent race test).
3. Pre-existing F020 CA PHPUnit UNIQUE collisions via MetricsUpdate/Yahoo fetch in some suite tests (unrelated to delegation; delegation tests mock metrics).

**Elevated double-restatement risk:** **RESOLVED** by F020 apply delegation + action-typed factor matching.

---

*Related: [F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](./F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md), [F043-F042-BOUNDARY.md](./F043-F042-BOUNDARY.md)*
