# F043 Final Compliance Audit

**Date:** 2026-08-09  
**Mode:** Post-implementation compliance audit (read-only)  
**Authoritative sources:** `F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md`, `F043-IMPLEMENTATION-GAP-MATRIX.md`, `F043-F042-BOUNDARY.md`, F042 handoff docs  
**Code inspected:** `CorporateActionPriceAdjustmentService`, `CorporateActionPriceRepairService`, `RepairCorporateActionPricesCommand`, `cpanel-repair-corporate-action-prices.php`, `PriceAdjustmentFactor`, `DataQualityAdjustmentFactorService`, `CorporateActionService` (F020), F043/F042/F020 tests  
**Restrictions observed:** No application code, tests, schema, APIs, frontend, F042, F020, or F043 specification/gap/boundary documents were modified.

**Note on spec §22 table:** The Formal Requirements table inside `F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md` still contains stale “IMPLEMENTATION_MISSING” text for R008–R011 (pre-implementation wording). This audit judges **requirement intent + current code**, not that stale gap column. The gap matrix reflects post-implementation status.

---

## 1. Audit scope

Independent verification that F043:

1. Satisfies approved MUST / AC requirements  
2. Consumes F042 pending factors correctly  
3. Preserves F020/F042 boundaries  
4. Does not introduce silent financial-data corruption in the intended primary paths  

Result vocabulary: **PASS** | **PARTIAL** | **FAIL** | **NOT_APPLICABLE**

---

## 2. Requirement compliance matrix

| Requirement | Priority | Implementation | Test | Result | Evidence |
|-------------|----------|----------------|------|--------|----------|
| F043-R001 OHLC ÷ `price_divisor` | MUST | `buildAdjustedRow` | Adjustment + factor apply tests | **PASS** | `round((float)$value / $priceDivisor, 4)` |
| F043-R002 Volume × multiplier | MUST | Same | Volume asserts | **PASS** | `(int) round(volume * volume_multiplier)` |
| F043-R003 `price_date < ex_date` | MUST | `rowsQuery` | Ex/post unchanged asserts | **PASS** | Predicate `'<'` only |
| F043-R004 Split/bonus formulas (F020 path) | MUST | `factorsForAction` | Adjustment unit tests | **PASS** | split=`ratio`; bonus=`1+ratio` |
| F043-R005 Dry-run default / apply opt-in | MUST | CLI/cPanel `dryRun` / `--apply` | Preview non-mutation | **PASS** | Command + cPanel |
| F043-R006 CA metadata soft idempotency | MUST | `rows_adjusted > 0` → `ok` | Repair service rescan | **PASS** | `inspectAction` |
| F043-R007 Continuity classification | MUST | Status taxonomy | Unadjusted detection test | **PASS** | Scan statuses |
| F043-R008 Discover pending factors | MUST | `scanPendingFactors` + scope | Discovery test | **PASS** | `pendingOhlcvRepair()` |
| F043-R009 Apply using **stored** divisors | MUST | `adjustHistoricalPricesByDivisors` | Stored-divisor test (÷4 not ÷2) | **PASS** | Uses `$factor->price_divisor` / `volume_multiplier` |
| F043-R010 Mark `completed` after success | MUST | `applyFactorRepair` metadata | Apply/complete test | **PASS** | Only if `rows_adjusted >= 1` |
| F043-R011 Skip completed/inactive | MUST | `inspectFactor` gates | Idempotency test | **PASS** | Status skip |
| F043-R012 No tx/holdings mutation | MUST | Factor path writes prices + factor metadata + metrics only | Explicit ledger test | **PASS** | No Transaction/Holding writes in repair service |
| F043-R013 No F042 issue governance | MUST | No resolution service calls | Issue status remains `accepted` | **PASS** | Grep + test |
| F043-R014 Ascending ex-date order | MUST | `orderBy effective_ex_date, id` | Multi-factor numerical test | **PASS** | Cumulative ÷2÷2 |
| F043-R015 Preview without mutation | MUST | Scan/dry-run | Preview test | **PASS** | OHLCV + status unchanged |
| F043-R016 Audit summary | MUST | Factor `metadata.ohlcv_repair` (+ CA metadata on F020 path) | Audit field asserts | **PASS** | Reconstructable summary |
| F043-R017 CLI + cPanel | MUST | Extended entry points | Ops surface present | **PASS** | `--factors-only`, `factor`, `apply` |
| F043-R018 Admin API | SHOULD | Absent | — | **NOT_APPLICABLE** (deferred by spec) | Spec SHOULD |
| F043-R019 DB transaction | SHOULD | `DB::transaction` around factor apply | Rollback test | **PASS** | Implemented beyond SHOULD |
| F043-R020 Concurrent lock | SHOULD | `lockForUpdate` | No real concurrency test | **PARTIAL** | MySQL OK; SQLite no-op; sequential tests only |
| F043-R021 Preview samples | SHOULD | `sampleAdjustmentPreview` | Findings include samples | **PASS** | Same `buildAdjustedRow` |
| F043-R022 `face_value_split` | SHOULD | Supported type list + stored divisors | Dedicated test | **PASS** | |
| F043-R023 processing/failed states | COULD | Not added | — | **NOT_APPLICABLE** | Spec COULD/deferred |
| F043-R024 Auto-schedule | COULD | Absent | — | **NOT_APPLICABLE** | Deferred |
| F043-R025 Rollback snapshots | COULD | Absent | — | **NOT_APPLICABLE** | Deferred |
| F043-AC001 Dry-run no OHLCV mutation | — | Yes | Preview test | **PASS** | |
| F043-AC002 Split 1:2 math + ex unchanged | — | Yes | Factor + adjustment tests | **PASS** | |
| F043-AC003 Bonus 1:1 math | — | Yes | Adjustment bonus test | **PASS** | |
| F043-AC004 F020 second apply idempotent | — | CA metadata gate | Repair rescan → `ok` | **PASS** | |
| F043-AC005 Pending discoverable | — | Scope + scan | Discovery test | **PASS** | |
| F043-AC006 Factor → completed | — | Writer | Apply test | **PASS** | |
| F043-AC007 No F042 status change | — | Yes | Issue status assert | **PASS** | |
| F043-AC008 No ledger mutation | — | Yes | Tx/holding test | **PASS** | |
| F043-AC009 Preview rows/divisors | — | Yes | Preview asserts | **PASS** | |
| F043-AC010 Multi ascending ex_date | — | Factors + CA scan order | Factor cumulative test; CA order-by only | **PARTIAL** | No multi-CA numerical F020 test |
| F043-AC011 Explicit apply flag | — | Yes | Command/cPanel | **PASS** | |
| F043-AC012 Completed factor skip | — | Yes | Idempotency test | **PASS** | |

---

## 3. Factor consumption

### Verified flow

```text
PriceAdjustmentFactor (F042 create)
  metadata.ohlcv_repair_status = pending
  price_divisor / volume_multiplier stored
       ↓
PriceAdjustmentFactor::pendingOhlcvRepair()
  is_active=true AND metadata->ohlcv_repair_status=pending
       ↓
CorporateActionPriceRepairService::scanPendingFactors
       ↓
inspectFactor (validate / ambiguous / unsupported)
       ↓
repairPendingFactors(dryRun=true) → preview only
       ↓
repairPendingFactors(dryRun=false) → applyFactorRepair
       ↓
adjustHistoricalPricesByDivisors(stored divisors)
       ↓
metadata.ohlcv_repair_status = completed (+ ohlcv_repair audit)
```

### Checks

| Check | Result |
|-------|--------|
| Pending only (default scan) | **PASS** — uses `pendingOhlcvRepair()` unless `--factor` id |
| Completed excluded from default scan | **PASS** |
| Inactive skipped | **PASS** — `STATUS_INACTIVE` |
| Unsupported no mutation | **PASS** — e.g. `dividend` |
| Invalid divisor no mutation | **PASS** |
| Stock / issue preserved | **PASS** — audit stores ids; issue status untouched |
| Ex-date = `effective_ex_date` | **PASS** |
| Stored divisor used (not recomputed) | **PASS** — proven by intentional `price_divisor=4` with `applied_ratio=2` |

### F042 source of divisors

`DataQualityAdjustmentFactorService::applyCorporateActionFactor`:

- `applied_ratio` = governance ratio  
- `price_divisor` = `bonus ? 1+applied_ratio : applied_ratio`  
- `volume_multiplier` = `price_divisor`  
- `ohlcv_repair_status` = `pending`

F043 reads stored columns; does not recompute from `applied_ratio`/`action_type` during apply.

---

## 4. Mathematical correctness

| Field | Transform | Result |
|-------|-----------|--------|
| open/high/low/close/adjusted_close | `round(value/price_divisor, 4)` if non-null | **PASS** |
| volume | `(int) round(volume * volume_multiplier)` if non-null | **PASS** |
| null OHLC fields | skipped | **PASS** |
| `price_divisor <= 0` | no updates | **PASS** |
| Zero prices | become `0.0` (valid math) | **PASS** (edge; not specially tested) |

F020 path computes divisors via `factorsForAction` then calls the same `adjustHistoricalPricesByDivisors` — no divergent field transform.

Manual scenario (from tests): pre-ex open=100 high=110 low=90 close=100 vol=1000, divisor=2 → 50/55/45/50 vol=2000; ex-date 58 unchanged.

---

## 5. Date-range semantics

Actual predicate:

```php
->where('stock_id', $stock->id)->where('price_date', '<', $exDate)
```

| Case | Result |
|------|--------|
| `price_date < ex_date` adjusted | **PASS** |
| `price_date == ex_date` excluded | **PASS** (tests) |
| post-ex excluded | **PASS** |
| Multi-factor different ex-dates | **PASS** — each factor only touches its own `< ex` range |

---

## 6. Multiple factors

### Scenario A (implemented test)

- Older factor ex=`2026-01-01`, divisor 2  
- Newer factor ex=`2026-05-01`, divisor 2  
- Bar `2025-06-01` close 400 → after both: `400/2/2 = 100`  
- Bar `2026-01-15` close 200 → only newer applies: `100`  
- Bar `2026-06-01` unchanged  

**PASS** — matches ascending apply + date ranges.

### Scenario B (overlap)

Two pending factors, same stock + same ex-date → both `ambiguous`, apply skipped, prices unchanged. **PASS**.

### Gaps

No automated test that a **completed** earlier factor is skipped while a later pending one still applies in one batch (implied by pending-only default scan + status gates). Behaviour is correct by construction; test coverage **PARTIAL**.

---

## 7. Idempotency

| Case | Behaviour | Result |
|------|-----------|--------|
| Apply pending once | Mutates + `completed` | **PASS** |
| Apply same again | Skip; prices unchanged | **PASS** (test) |
| Restart command after completion | Default scan omits completed | **PASS** |
| Zero rows updated | Leave `pending`; no completed | **PASS** (code) |
| Concurrent second process | Relies on `lockForUpdate` + re-read status | **PARTIAL** (see §9) |

Idempotency is **application-level** (status/metadata), not mathematical. Raw `adjustHistoricalPricesByDivisors` remains non-idempotent if called without gates — callers gate correctly for factor + F020 CA repair paths.

---

## 8. Transaction safety

`applyFactorRepair` wraps in one `DB::transaction`:

1. `lockForUpdate` factor  
2. re-inspect  
3. OHLCV `update`s  
4. factor metadata → `completed`  
5. `MetricsUpdateService::updateStock`

Exception after OHLCV writes (metrics mock throw) → rollback prices + keep `pending` — **proven by test**. **PASS**.

Completion cannot persist without successful commit of the same transaction. **PASS**.

---

## 9. Concurrency

| Environment | Assessment |
|-------------|------------|
| **MySQL production** | `SELECT … FOR UPDATE` on factor row + status re-check prevents two appliers from both seeing `pending` and both mutating — **PASS** (by design) |
| **SQLite tests** | `lockForUpdate` is effectively a no-op; tests are **sequential** — do **not** prove concurrent safety |

**PARTIAL** for R020 / concurrency proof. Residual risk is low on production MySQL if only one factor row is locked; not proven by automated concurrent tests.

---

## 10. Preview safety

| Check | Result |
|-------|--------|
| No OHLCV mutation | **PASS** |
| No completed mark | **PASS** |
| No false completed audit | **PASS** |
| Rows/divisors reported | **PASS** |
| Samples use `buildAdjustedRow` | **PASS** |
| Ambiguous/invalid reported without mutate | **PASS** |

---

## 11. Apply safety

| Check | Result |
|-------|--------|
| Explicit `--apply` / `&apply=1` | **PASS** |
| Unsupported/ambiguous/invalid no mutate | **PASS** |
| Failed → not completed | **PASS** |
| Success → completed only after rows_adjusted ≥ 1 | **PASS** |

---

## 12. Auditability

Successful factor repair writes `metadata.ohlcv_repair` with: previous/resulting status, rows, divisors, adjusted_before_date, affected_date_range, stock_id, issue_id, action_type, repaired_at, repair_source.

Sufficient to reconstruct the repair at summary level. **PASS**.  
No per-row before/after persistence (SHOULD/deferred) — acceptable.

---

## 13. F020 regression

### Isolated F020 path (no F042 factor)

- Shared adjuster still used via `adjustHistoricalPrices` → `factorsForAction` → `byDivisors`  
- Math unit tests **PASS**  
- Ledger apply still in `CorporateActionService` (unchanged ownership)

### `deferred_to_factor` (F043 CA **repair scan** only)

When an **active** pending/completed F042 factor exists for same stock+ex-date, F043’s `inspectAction` returns `deferred_to_factor` and will **not** repair via CA path — prevents F043 double-restatement between CA-recovery and factor paths. **PASS** for that narrow goal.

**No automated test** for `deferred_to_factor` — coverage **PARTIAL**.

### Critical residual: F020 **apply** vs factor path

`CorporateActionService::apply` **always** calls `adjustHistoricalPrices` after ledger apply. It does **not** consult `PriceAdjustmentFactor`.

Therefore:

| Sequence | Risk |
|----------|------|
| F020 apply first (OHLCV restated), later F043 factor apply for same event | **Factor path will restate again** (compound) |
| F043 factor repair first, later F020 apply for same event | **F020 apply will restate again** (compound) |
| F043 CA repair with factor present | Deferred — **safe** |

This is a **cross-path residual risk**, not a failure of isolated MUST R001–R017. It is **not covered** by `deferred_to_factor` (repair-scan only). Documented as elevated non-blocker / ops hazard.

### F020 PHPUnit failures under suite

See §18 — UNIQUE collisions during `MetricsUpdateService` → Yahoo history fetch after F020 apply. Stack originates in `PriceFetchService::storeHistoricalRows`, not in F043 divisor helpers. Classified **pre-existing / F020+metrics**, not F043 factor-path regression.

---

## 14. F042 boundary

| Check | Result |
|-------|--------|
| F043 does not accept/reject/create issues | **PASS** |
| F043 does not alter evidence | **PASS** |
| F043 does not invoke F042 services | **PASS** |
| Issue status unchanged after repair | **PASS** (test) |
| Factor status only via F043 repair | **PASS** |
| Pipeline gating unchanged | **PASS** (no guard edits) |

F042 DataQuality suite: **56/56 pass** with F043 tests included in the same run group.

---

## 15. Ledger / holdings safety

Factor repair service writes: `portfolio_stock_prices`, `portfolio_price_adjustment_factors.metadata`, and metrics via `MetricsUpdateService`.

No writes to transactions, holdings, cash, or reservations in F043 paths. **PASS** (code + test).

---

## 16. Supported action types

| Type | Factor path | Notes |
|------|-------------|-------|
| `split` | Supported | Stored divisors |
| `bonus` | Supported | Stored divisors |
| `face_value_split` | Supported | Treated as allowed type; math from stored divisors |
| Reverse split | Via divisor &lt; 1 if stored/F020 ratios say so | No separate type string required |
| `dividend` / rights / merger | Unsupported → no mutation | **PASS** (dividend test) |

---

## 17. Test-quality assessment

| Area | Genuine proof? | Notes |
|------|----------------|-------|
| Math / date | **Yes** | Concrete before/after |
| Stored divisor | **Yes** | Conflicting ratio vs divisor |
| Preview safety | **Yes** | Snapshot compare |
| Idempotency | **Yes** | Two applies + unchanged values |
| Multi-factor cumulative | **Yes** | Manual expected 100/100/100 |
| Ambiguity | **Yes** | No mutation + still pending |
| Rollback | **Yes** | Metrics throw inside same transaction |
| Concurrency | **No** | Sequential only |
| `deferred_to_factor` | **No** | Untested |
| F020 apply + factor coexistence | **No** | Residual risk untested |
| F020 multi-CA numerical | **Weak** | Order-by only |

Tests that would still pass if broken: none identified for core factor math; concurrency and cross-path coexistence are **unproven**.

---

## 18. Full-suite results

| Setting | Value |
|---------|-------|
| Default CLI `memory_limit` | **128M** (OOM historically) |
| Audit rerun | `php -d memory_limit=512M vendor/bin/phpunit` — **completed** |
| Totals | **601** tests, **592** passed, **5** failed, **4** errors, **6** risky |

| Test | Failure | Root cause | F043-related? | F020-related? | Pre-existing? |
|------|---------|------------|---------------|---------------|---------------|
| `CorporateActionApiTest::test_preview_and_apply_split_via_api` | 500 / UNIQUE stock_prices | `MetricsUpdate` → RS → Yahoo `storeHistoricalRows` insert collide | **No** (indirect metrics) | **Yes** (apply path) | **Yes** — fixture/metrics; not factor path |
| `CorporateActionServiceTest::test_split_scales_*` | UNIQUE stock_prices | Same | **No** | **Yes** | **Yes** |
| `CorporateActionServiceTest::test_split_restates_*` | UNIQUE stock_prices | Same | **No** | **Yes** | **Yes** |
| `CorporateActionServiceTest::test_bonus_uses_*` | UNIQUE stock_prices | Same | **No** | **Yes** | **Yes** |
| `StockPriceHistoryServiceTest::test_growth_*` | null ≠ 10.0 | Analytics fixture | **No** | No | **Yes** |
| `ExplorerAnalyticsTest::test_explore_*` | 100 ≠ 105 | Explorer analytics | **No** | No | **Yes** |
| `TransactionStockResolverTest` ×2 | 422 insufficient cash | Cash seeding | **No** | No | **Yes** |
| `RelativeStrengthServiceTest::test_service_can_be_constructed` | ctor arity | Test not updated for 3 deps | **No** | No | **Yes** |

**F043 targeted + F042 + adjustment/repair:** 56/56 pass.  
**No new F043-specific suite failure** identified at 512M.

---

## 19. Remaining non-blockers

| Item | Spec permit? | Audit view |
|------|--------------|------------|
| Admin API/UI | SHOULD deferred | Permitted |
| Scheduled auto-repair | COULD / boundary | Permitted |
| Rollback snapshots | COULD | Permitted |
| Dividend/rights/merger | DEFERRED | Permitted |
| SQLite lock no-op | Documented | Permitted; MySQL OK |
| Spec §22 stale gap text | Docs debt | Non-code; do not treat as fail |
| **Cross-path double restatement** (F020 apply ↔ factor apply) | Not forbidden by a numbered MUST; not fully mitigated | **Elevated residual risk** — ops must not run both writers for the same event |
| No `deferred_to_factor` test | Test gap | Non-blocker |

---

## 20. F043 readiness / final verdict

Core MUST requirements for the **factor path** and **isolated F020 adjuster math** are implemented and meaningfully tested. F042 governance boundary holds. CLI/cPanel dry-run defaults hold. Transactional factor apply rolls back on failure.

Residual risks (cross-path double restatement; untested concurrency; untested `deferred_to_factor`) prevent an unqualified “fully clean” close, but do not fail the approved MUST matrix for the primary F043 scope.

### Verdict

**F043_COMPLIANT_WITH_NON_BLOCKERS**

### Readiness to close / next initiative

- **Safe to close F043** as V2 capability with documented non-blockers and the elevated dual-path ops caution.  
- **Safe to proceed to the next V2 initiative**, provided ops run F043 factor repair **or** F020 apply OHLCV restatement for a given stock/ex-date event — not both — until any future hardening of F020 apply coordination.

---

## 21. Files created/changed

| File | Action |
|------|--------|
| `docs/v2/F043-FINAL-COMPLIANCE-AUDIT.md` | **Created** (this audit) |
| `DOCS.md` | Indexed under §3.C |

No application, test, schema, F042, F020, or F043 spec/gap/boundary content modified.

---

*End of F043 final compliance audit.*

---

## Addendum — Double-restatement hardening (2026-08-09)

**Risk identified:** F020 `CorporateActionService::apply` always restated OHLCV; F043 factor apply could restate the same stock/ex-date event again.

**Enforcement implemented:**

- `PriceAdjustmentFactor::activeOhlcvRepairForEvent` / `findActiveOhlcvRepairForEvent` (stock + ex-date + action-type family).
- F020 apply skips OHLCV when a matching active pending/completed factor exists; ledger unchanged; metadata records `deferred_to_factor`.
- F043 CA recovery `deferred_to_factor` uses the same action-typed match.
- Tests: `CorporateActionOhlcvDelegationTest` covers F020-only, F020→F043, F043→F020, repeats, stock/date/type mismatch, failed F043 still blocks F020 OHLCV, and explicit `deferred_to_factor` scan/repair.

**Updated verdict after hardening:** **F043_COMPLETE** (non-blockers remaining: admin API deferred; SQLite `lockForUpdate` no-op / no concurrent race suite).

**Spec:** Single OHLCV writer invariant + F043-R026 / AC013–AC015 recorded in `F043-CORPORATE-ACTION-PRICE-REPAIR-SPEC.md`. Stale §22 MISSING wording for R008–R011 corrected.
