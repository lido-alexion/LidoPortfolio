# F043 — Implementation Gap Matrix

**Capability:** F043 Corporate Action Price Repair  
**Date:** 2026-08-09  
**Mode:** Specification / reconciliation only — no implementation  
**Authoritative spec:** [F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](./F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md)

---

## Status legend

| Status | Meaning |
|--------|---------|
| NO_GAP | Requirement satisfied by current code + adequate tests |
| IMPLEMENTATION_MISSING | Required behaviour absent |
| IMPLEMENTATION_PARTIAL | Partial behaviour; needs completion |
| TEST_MISSING | Behaviour present or specified; tests inadequate |
| SPEC_MISSING | Product semantics still unclear (should be rare after this pass) |
| DEFERRED | Explicitly postponed |
| OUT_OF_SCOPE | Not F043 |

---

## Gap matrix

| Requirement | Current Implementation | Status | Code Evidence | Test Evidence | Required Work |
|-------------|------------------------|--------|---------------|---------------|---------------|
| F043-R001 OHLC ÷ divisor | `buildAdjustedRow` divides OHLC + adj_close | NO_GAP | `CorporateActionPriceAdjustmentService` | `CorporateActionPriceAdjustmentServiceTest` | None |
| F043-R002 Volume × multiplier | Volume multiplied | NO_GAP | Same | Same (volume asserts) | None |
| F043-R003 Date range `< ex_date` | `rowsQuery` | NO_GAP | Same | Ex-date bar unchanged in tests | None |
| F043-R004 Split/bonus formulas | `factorsForAction` | NO_GAP | Same | Split + bonus unit tests | None |
| F043-R005 Dry-run default | CLI/cPanel/service | NO_GAP | `RepairCorporateActionPricesCommand`; `cpanel-repair-corporate-action-prices.php` | Dry-run then apply unit test | Keep when extending factor path |
| F043-R006 CA metadata idempotency | `STATUS_OK` when `rows_adjusted > 0` | NO_GAP | `CorporateActionPriceRepairService::inspectAction` | Second scan → `ok` | Keep; do not rely on raw adjuster alone |
| F043-R007 Continuity scan | Status taxonomy | NO_GAP | Repair service | One unadjusted detection test | Expand force/ambiguous tests |
| F043-R008 Discover pending factors | Scope only | IMPLEMENTATION_MISSING | `PriceAdjustmentFactor::scopePendingOhlcvRepair` | F042 tests create pending; **no F043 consumer test** | Wire repair discovery to scope |
| F043-R009 Factor-driven OHLCV apply | Absent | IMPLEMENTATION_MISSING | No F043 read of factors | None | Apply using `price_divisor` / `volume_multiplier` / `effective_ex_date` |
| F043-R010 Mark factor completed | Constant unused | IMPLEMENTATION_MISSING | `REPAIR_STATUS_COMPLETED` never written | None | Write metadata on success |
| F043-R011 Skip completed factors | Absent | IMPLEMENTATION_MISSING | — | None | Gate before adjust |
| F043-R012 No ledger mutation | Repair path clean | NO_GAP | Repair service | No explicit negative assert | Add assert in F043 tests |
| F043-R013 No F042 status change | No coupling | NO_GAP | Grep: repair ↔ DQ | Boundary audit | Preserve |
| F043-R014 Multi-factor ordering | CA scan by ex_date | IMPLEMENTATION_PARTIAL | `scan()` orderBy | No multi-CA test | Factor queue ascending `effective_ex_date` |
| F043-R015 Preview non-mutating | Preview + dry-run | IMPLEMENTATION_PARTIAL | `previewAdjustment`; dry-run details | Dry-run test | Factor preview + optional samples |
| F043-R016 Audit summary | CA metadata on repair | IMPLEMENTATION_PARTIAL | `price_adjustment` merge | Indirect via apply test | Factor repair audit fields |
| F043-R017 CLI + cPanel | Present | NO_GAP | Command + deploy | Manual / unit via service | Extend for factor queue |
| F043-R018 Admin API | Absent | IMPLEMENTATION_MISSING | No routes | None | Optional SHOULD API |
| F043-R019 DB transaction wrap | Row-by-row updates | IMPLEMENTATION_PARTIAL | `adjustHistoricalPrices` loop | None | SHOULD wrap per apply |
| F043-R020 Concurrent lock | Absent | IMPLEMENTATION_MISSING | — | None | SHOULD `lockForUpdate` |
| F043-R021 Sample before/after | Counts only | IMPLEMENTATION_MISSING | — | None | SHOULD |
| F043-R022 face_value_split | F042 may emit; F020 uses split/bonus | IMPLEMENTATION_PARTIAL | DQ factor `action_type` | None | Map to split semantics |
| F043-R023 processing/failed states | Only pending/completed consts | DEFERRED | Model | — | Optional |
| F043-R024 Auto-repair schedule | Absent | DEFERRED | Boundary forbids F042 invoke | — | Ops-triggered only |
| F043-R025 Rollback snapshots | Absent | DEFERRED | — | — | Future |
| Pipeline re-block until repaired | F042 unblocks on accept | OUT_OF_SCOPE | F042 guard | F042 gating tests | Do **not** change F042 |
| DualListed NSE purge | Separate service | OUT_OF_SCOPE | `DualListedNseRepairService` | — | Exclude from F043 |
| Dividend/rights/merger adjust | Not modeled | DEFERRED | F020 types | — | Out of current CA model |

---

## Summary counts

| Status | Count (approx.) |
|--------|-----------------|
| NO_GAP | 9 |
| IMPLEMENTATION_MISSING | 7 (core: R008–R011, R018, R020–R021) |
| IMPLEMENTATION_PARTIAL | 6 |
| DEFERRED | 3 |
| OUT_OF_SCOPE | 2 |
| TEST_MISSING (cross-cutting) | Multi-CA, force/ambiguous, factor path, explicit no-ledger |

---

## Critical path for implementation

1. **Consume** `PriceAdjustmentFactor::pendingOhlcvRepair()`  
2. **Apply** using stored `price_divisor` / `volume_multiplier` / `effective_ex_date` (reuse adjustment helper; may need divisor-based API overload)  
3. **Mark** `ohlcv_repair_status = completed`  
4. **Preserve** F020 CA scan/repair path (ops recovery)  
5. **Expand tests** for factor path + idempotency + no ledger mutation  
6. **Optionally** admin API / richer preview  

---

## Schema assessment

| Table / field | Sufficient? |
|---------------|-------------|
| `portfolio_stock_prices` | Yes |
| `portfolio_corporate_actions.metadata` | Yes for F020 path |
| `portfolio_price_adjustment_factors` + metadata status | Yes — no migration required for MVP F043 |
| New repair-run table | Not required (COULD) |

---

*Related: [F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md](./F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md), [F043-F042-BOUNDARY.md](./F043-F042-BOUNDARY.md)*
