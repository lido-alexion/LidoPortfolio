# F043 — Corporate Action Price Repair

**Capability ID:** F043  
**Status:** V2 — **implemented** (`F043_COMPLETE` after double-restatement hardening)  
**Date:** 2026-08-09  
**Governance:** Deferred from V1 per SD-035 (`MVP_SCOPE.md`)  
**Prerequisites:** F042 complete (`F042_COMPLETE_WITH_NON_BLOCKERS`)  
**Related:** V1 F020 (Corporate Actions), V2 F042 (Data Quality)

### Single OHLCV writer invariant

An OHLCV corporate-action event must have exactly one active repair writer.
When an applicable F042 `PriceAdjustmentFactor` exists for the same stock,
effective/ex-date, and matching action type (`split`↔`split|face_value_split`,
`bonus`↔`bonus`), **F043 is the sole historical OHLCV repair path** for that
event; F020 must preserve its ledger/corporate-action processing without
performing a duplicate historical price restatement. When no such factor
exists, F020 retains its existing OHLCV restatement on apply.

---

## 1. Purpose

F043 performs **controlled historical OHLCV restatement** so price charts and analytics remain continuous after corporate actions that change the quoted share price (primarily stock splits and bonus issues).

F043 is the **mutation / ops-repair layer**. It does **not**:

- apply portfolio ledger changes (F020)
- detect or govern data-quality issues (F042)

It **must** consume the F042 handoff (`PriceAdjustmentFactor` with `ohlcv_repair_status = pending`) in formal V2, while retaining the existing ops path that repairs applied F020 `CorporateAction` rows when OHLCV restatement was missed or incomplete.

---

## 2. Scope

### In scope (V2 F043)

| Area | CURRENT | REQUIRED |
|------|---------|----------|
| Restate OHLCV before ex-date | **Yes** — `CorporateActionPriceAdjustmentService` | Keep |
| Scan applied F020 CAs for unadjusted prices | **Yes** — `CorporateActionPriceRepairService::scan` | Keep (legacy / F020 recovery) |
| Dry-run / apply CLI | **Yes** — Artisan + cPanel | Keep |
| Continuity-based classification | **Yes** — statuses below | Keep for F020 path |
| Consume F042 `pendingOhlcvRepair()` factors | **Yes** — `scanPendingFactors` / `repairPendingFactors` | Done |
| Mark factor `ohlcv_repair_status` after repair | **Yes** — sets `completed` + `metadata.ohlcv_repair` audit | Done |
| Preview summary (counts / divisors) | **Yes** — + optional samples | Done |
| Dedicated admin API/UI | **No** | SHOULD (deferred) |
| Per-row before/after audit store | Summary audit on factor metadata | SHOULD samples in preview; full per-row store deferred |

### Out of scope

| Item | Owner |
|------|-------|
| Portfolio split/bonus apply | **V1 F020** |
| DQ detection / accept / reject | **V2 F042** |
| Creating adjustment factors | **F042** |
| Dividend cash adjustments | Not in current CA model |
| Dual-listed BSE price purge | Separate (`DualListedNseRepairService`) — **not F043** |
| Re-blocking TOS pipelines until repair | F042 policy: unblock on accept — **do not change F042** |

---

## 3. Non-Goals

- Replace F020 user-facing corporate-action UI.
- Auto-invoke repair from F042 accept (boundary prohibits).
- Silently invent corporate-action types beyond split/bonus (and feed `face_value_split` treated as split-like).
- Rebuild OHLCV from vendors as primary repair (F043 restates cached rows).
- Rollback UI that restores original OHLC from a full snapshot store (not present today).

---

## 4. Relationship to F020

| Dimension | V1 F020 | V2 F043 |
|-----------|---------|---------|
| Actor | Portfolio operator | Platform admin / ops |
| Ledger | Mutates transactions / holdings | Does **not** |
| OHLCV | **Does** call `adjustHistoricalPrices` on successful apply (CURRENT code) | Ops **recovery** when that restatement missing/wrong |
| Input | User preview/apply payload | Applied `CorporateAction` **and/or** F042 pending factors |
| Idempotency cue | Writes `CorporateAction.metadata.price_adjustment` | Same metadata + factor status |

**Important correction vs older boundary wording:** F020 **does** mutate `portfolio_stock_prices` on apply via the shared adjustment helper. F043 is **not** the only writer — it is the dedicated **repair tooling** when F020 restatement did not occur or was incomplete.

---

## 5. Relationship to F042

| Dimension | F042 | F043 |
|-----------|------|------|
| Input | Market anomaly | Accepted governance + pending factor |
| OHLCV | Read-only | Write historical bars |
| Factor | Creates `ohlcv_repair_status=pending` | Discovers pending; sets `completed` (REQUIRED) |
| Guard | Blocks only `pending_review` | Does not change guard |

### Authoritative handoff (F042)

```text
F042 accept
  → PriceAdjustmentFactor (active)
  → metadata.ohlcv_repair_status = pending
  → F043 discovers via PriceAdjustmentFactor::pendingOhlcvRepair()
  → F043 mutates OHLCV
  → F043 sets ohlcv_repair_status = completed (REQUIRED)
```

**CURRENT:** F043 consumes pending factors and marks `completed` after successful apply.  
F042 still never invokes F043.

---

## 6. Supported Corporate Actions

| Type | CURRENT F043 / adjuster | F042 factor | F043 V2 |
|------|-------------------------|-------------|---------|
| `split` | **SUPPORTED_BY_CURRENT_IMPLEMENTATION** | Yes | **REQUIRED** |
| `bonus` | **SUPPORTED** (non-split formula) | Yes | **REQUIRED** |
| `face_value_split` (F042 feed) | Treated like split if passed as `split`; F020 model typically `split`/`bonus` | Feed maps to `face_value_split` | **SHOULD** map to split divisor semantics |
| Reverse split | Same math if `ratio_to < ratio_from` | Possible | **SUPPORTED** if ratios valid |
| Rights / dividend / merger | **NOT_APPLICABLE** | Not modeled | **DEFERRED** |

---

## 7. Adjustment Factor Semantics

### F020 / F043 helper (`CorporateActionPriceAdjustmentService`)

```text
ratioFactor = ratio_to / ratio_from

if action_type == 'split':
  price_divisor = ratioFactor
  volume_multiplier = ratioFactor
else:  # bonus (and any non-split)
  price_divisor = 1 + ratioFactor
  volume_multiplier = price_divisor
```

### F042 factor (`DataQualityAdjustmentFactorService`)

```text
applied_ratio = suggested/applied governance ratio
  (exchange feed: to/from; heuristic: closest common split ratio)

if corporate_action_type == 'bonus':
  price_divisor = 1 + applied_ratio
else:
  price_divisor = applied_ratio
volume_multiplier = price_divisor
```

### Alignment note

For a 1:2 split:

- F020: `ratio_from=1`, `ratio_to=2` → divisor `2`
- F042: `applied_ratio=2` → divisor `2`

For a 1:1 bonus:

- F020: `ratio_from=1`, `ratio_to=1` → divisor `1+1=2`
- F042: `applied_ratio=1` → divisor `1+1=2`

**REQUIRED:** F043 factor-driven path must use stored `price_divisor` / `volume_multiplier` on the factor row (or recompute equivalently from `applied_ratio` + `action_type`) — not invent a third formula.

### Direction

Pre-ex prices are **divided** by `price_divisor` (historical quotes restated to post-CA scale).  
Volumes are **multiplied** by `volume_multiplier` (same numeric factor).

### Ex-date

Rows with `price_date < effective_ex_date` are adjusted.  
Ex-date and later bars are **unchanged**.

### Cumulative / ordering

Factors/actions apply to ranges independently. Multiple historical CAs: apply **oldest ex-date first** when batch-repairing so later continuity checks remain meaningful. CURRENT scan orders by `ex_date`, then `id`.

---

## 8. Price Transformation

**REQUIRED** (matches CURRENT helper):

For each OHLCV row with `price_date < ex_date`:

| Field | Transformation |
|-------|----------------|
| `open_price` | `round(value / price_divisor, 4)` if not null |
| `high_price` | same |
| `low_price` | same |
| `close_price` | same |
| `adjusted_close_price` | same |

Illustrative (from unit tests):

| CA | Pre-ex close | Divisor | Post-repair close |
|----|--------------|---------|-------------------|
| Split 1:2 | 100 | 2 | 50 |
| Bonus 1:1 | 100 | 2 | 50 |

Ex-date bar remains at post-event quoted level (e.g. ~50 after 1:2).

---

## 9. Volume Transformation

**REQUIRED** (matches CURRENT helper):

| Field | Transformation |
|-------|----------------|
| `volume` | `(int) round(volume * volume_multiplier)` if not null |

Volume is **adjusted inversely to price** (same multiplier as price divisor) so share-count continuity is preserved in historical bars.

Volume is **not** left unchanged.

---

## 10. Date Range Semantics

| Rule | Value |
|------|-------|
| Effective date | `CorporateAction.ex_date` or factor `effective_ex_date` |
| Inclusive lower | Earliest available OHLCV for stock |
| Exclusive upper | `price_date < ex_date` |
| Ex-date itself | Not adjusted |
| After ex-date | Not adjusted |

---

## 11. Multiple Factors

| Scenario | CURRENT | REQUIRED |
|----------|---------|----------|
| Multiple applied F020 CAs | Scan each independently; repair in ex_date order | Keep; process ascending ex_date |
| Two pending F042 factors same stock | N/A (not consumed) | Process ascending `effective_ex_date`, then `id` |
| Overlapping pending + already repaired | Metadata/`ok` skip | Skip completed factors; do not restate |
| Same CA repaired twice | Soft idempotent via `rows_adjusted > 0` | Keep + factor `completed` |
| Conflicting ratios same ex-date | Ambiguous continuity | Skip / force / ops review — do not silent-merge |

**Do not** combine factors into one synthetic divisor for a single pass unless both map to the same ex-date and ops explicitly forces (COULD / deferred).

---

## 12. Preview

### CURRENT

- `previewAdjustment()` — row count + divisors (no sample OHLC deltas)
- CLI scan table — status classification
- `repair(dryRun:true)` — `would_repair` / `would_mark_metadata_only`

### REQUIRED

Preview SHALL be non-mutating and report at least:

- stock / symbol
- source (F020 CA id and/or F042 factor/issue id)
- action type + ratio/divisor
- ex-date / date range
- rows affected
- expected price_divisor / volume_multiplier
- classification status / warnings

### SHOULD

- Sample before/after values for a small number of dates
- Explicit warnings when pipelines already unblocked (post-F042 accept)

---

## 13. Apply

### CURRENT (F020 CA path)

1. Classify via continuity scan  
2. If repairable (or force+ambiguous): `adjustHistoricalPrices`  
3. Merge into `CorporateAction.metadata.price_adjustment`  
4. `MetricsUpdateService::updateStock`  
5. Dry-run default; `--apply` / `&apply=1` writes  

### REQUIRED additions (F042 factor path)

1. Query `PriceAdjustmentFactor::pendingOhlcvRepair()`  
2. Validate factor (active, pending, divisor > 0, ex_date present, stock present)  
3. Preview then apply (same OHLCV transform)  
4. Set `metadata.ohlcv_repair_status = completed` (and stamp repaired_at)  
5. Optionally link / ensure F020 CA metadata if a matching applied CA exists — **SHOULD**, not blocking  

Apply MUST NOT modify F042 issue status (already accepted).

---

## 14. Idempotency

**MUST:**

1. Re-running apply on a successfully repaired F020 CA (`metadata.price_adjustment.rows_adjusted > 0` / status `ok`) SHALL skip OHLCV mutation.  
2. Re-running apply on a factor with `ohlcv_repair_status = completed` SHALL skip OHLCV mutation.  
3. `adjustHistoricalPrices` alone is **not** mathematically idempotent — callers MUST gate on status/metadata before calling.  
4. Dry-run SHALL never mutate.

---

## 15. Validation & Safety

| Area | CURRENT | REQUIRED |
|------|---------|----------|
| Factor / ratio validity | Divisor ≤ 0 → no row updates | Reject apply if divisor ≤ 0 |
| Date range | `price_date < ex` | Keep |
| Row count | Preview count | Keep; warn if 0 |
| Continuity heuristic | ±25% tolerance | Keep for F020 path |
| Impossible prices | Not checked | SHOULD warn if resulting OHLC ≤ 0 |
| Transactions | Per-row updates (no single all-or-nothing OHLCV txn) | SHOULD wrap stock repair in DB transaction |
| Rollback | None | DEFERRED (no snapshot store) |
| Concurrent repair | None | SHOULD lock factor/CA row |
| Authorization | Token / local artisan | Admin-only if API added |
| Stale factor | N/A | Skip inactive / non-pending |
| Force ambiguous | `--force` | Keep for F020 path |

---

## 16. Concurrency

| Scenario | REQUIRED |
|----------|----------|
| Two ops apply same CA | Second should see `ok` / completed and skip |
| Factor locked while applying | Prefer `lockForUpdate` on factor row |
| Concurrent F042 re-resolve | F042 may deactivate factor — F043 MUST re-check `is_active` + pending before write |

---

## 17. Auditability

### CURRENT

`CorporateAction.metadata.price_adjustment` includes:

- `rows_adjusted`, `price_divisor`, `volume_multiplier`, `adjusted_before_date`
- on repair: `repaired_at`, `repair_source`

No actor user id; no per-row before/after store.

### REQUIRED (minimum)

- source path (F020 CA and/or F042 factor/issue)
- stock id, ex-date
- divisors
- rows_adjusted
- repaired_at
- repair_source / initiator (CLI vs cPanel vs API user id when available)
- factor status transition pending → completed

### SHOULD

- notes
- dry-run vs apply marker in ops logs
- sample before/after closes

---

## 18. Pipeline Interaction

| Factor / issue state | Pipeline (F042 guard) | OHLCV |
|----------------------|----------------------|-------|
| DQ `pending_review` | **Blocked** | Unchanged |
| DQ accepted + factor `pending` | **Unblocked** | May still be wrong |
| Factor `completed` | Unblocked | Restated |
| F043 running | Unblocked (F042 unchanged) | Partial until commit |

**Design issue (document only):** Accept-before-repair window is intentional F042 policy. F043 MUST NOT re-open F042 to re-block stocks. Ops SHOULD run F043 promptly after accept for names that need restatement.

---

## 19. API / UI

| Surface | CURRENT | REQUIRED |
|---------|---------|----------|
| Artisan CLI | Yes | Keep |
| cPanel script | Yes | Keep |
| Admin REST API | No | SHOULD — preview + apply + pending queue |
| Admin UI | No | COULD — Data Quality history link / ops queue |
| Scheduled auto-repair | No | COULD — not default (high risk) |

Authorization: admin (API) / SETUP_TOKEN (cPanel) / console ops.

---

## 20. Data Model

| Structure | Role |
|-----------|------|
| `portfolio_stock_prices` | Mutated OHLCV |
| `portfolio_corporate_actions.metadata.price_adjustment` | F020/F043 repair audit (CURRENT) |
| `portfolio_price_adjustment_factors` | F042 handoff; REQUIRED F043 consumer |
| Factor `metadata.ohlcv_repair_status` | `pending` → `completed` (REQUIRED writer in F043) |

**Schema sufficiency:** Adequate without new tables for V2. Optional repair-run table is FUTURE.

---

## 21. Error Handling

| Case | Behaviour |
|------|-----------|
| No pre-ex rows | `no_prices` / skip |
| Already adjusted + no metadata | Metadata-only mark (CURRENT) |
| Ambiguous continuity | Skip unless `--force` |
| Missing stock | Skip |
| Invalid divisor | No updates / reject |
| Factor inactive | Skip |

---

## 22. Formal Requirements

| Requirement | Priority | Current Implementation | Gap | Evidence |
|-------------|----------|------------------------|-----|----------|
| **F043-R001** Restate pre-ex OHLC fields by dividing by `price_divisor` | MUST | Implemented in `CorporateActionPriceAdjustmentService` | NO_GAP | Adjustment service + unit tests |
| **F043-R002** Adjust volume by multiplying by `volume_multiplier` (= price divisor) | MUST | Implemented | NO_GAP | Same; volume asserts in tests |
| **F043-R003** Adjust only `price_date < ex_date` | MUST | Implemented | NO_GAP | `rowsQuery` |
| **F043-R004** Split vs bonus divisor formulas | MUST | Implemented (`split` vs `1+ratio`) | NO_GAP | `factorsForAction` |
| **F043-R005** Dry-run / scan default; apply opt-in | MUST | CLI/cPanel/service `dryRun` | NO_GAP | Command + deploy script |
| **F043-R006** Soft idempotency via CA `metadata.price_adjustment.rows_adjusted > 0` | MUST | Implemented for F020 path | NO_GAP | `inspectAction` STATUS_OK |
| **F043-R007** Continuity classification for applied F020 CAs | MUST | Implemented | NO_GAP | Repair service statuses |
| **F043-R008** Discover F042 pending factors via `pendingOhlcvRepair()` | MUST | `scanPendingFactors` | NO_GAP | Factor tests |
| **F043-R009** Apply OHLCV repair from pending factor using stored divisors | MUST | `adjustHistoricalPricesByDivisors` | NO_GAP | Factor tests |
| **F043-R010** Mark factor `ohlcv_repair_status=completed` after successful repair | MUST | `applyFactorRepair` | NO_GAP | Factor tests |
| **F043-R011** Skip completed / inactive factors (idempotent) | MUST | Status gates | NO_GAP | Idempotency test |
| **F043-R012** Do not mutate transactions/holdings | MUST | Factor path does not | NO_GAP | Ledger test |
| **F043-R013** Do not change F042 issue status / invoke F042 resolution | MUST | No DQ resolution calls | NO_GAP | Boundary + test |
| **F043-R014** Process multiple repairs ascending by ex-date | MUST | Factor + CA scan order | NO_GAP | Multi-factor test |
| **F043-R015** Preview reports rows + divisors without mutation | MUST | Scan/dry-run + samples | NO_GAP | Preview test |
| **F043-R016** Persist repair audit summary | MUST | Factor `ohlcv_repair` + CA metadata | NO_GAP | Apply audit asserts |
| **F043-R017** Support ops CLI + cPanel entry points | MUST | Extended command/cPanel | NO_GAP | Ops surface |
| **F043-R026** Single OHLCV writer when active F042 factor matches event | MUST | F020 apply delegates; CA repair `deferred_to_factor` | NO_GAP | `CorporateActionOhlcvDelegationTest` |
| **F043-R018** Admin preview/apply API | SHOULD | None | DEFERRED | — |
| **F043-R019** Wrap per-stock apply in DB transaction | SHOULD | Factor `DB::transaction` | NO_GAP | Rollback test |
| **F043-R020** Concurrent lock on factor during apply | SHOULD | `lockForUpdate` (MySQL) | PARTIAL | SQLite no-op; no concurrent test |
| **F043-R021** Sample before/after values in preview | SHOULD | `sampleAdjustmentPreview` | NO_GAP | Preview samples |
| **F043-R022** Map `face_value_split` to split divisor semantics | SHOULD | Supported + stored divisors | NO_GAP | face_value_split test |
| **F043-R023** `processing` / `failed` repair statuses | COULD | Not added | DEFERRED | Model |
| **F043-R024** Scheduled auto-repair after accept | COULD | None | DEFERRED | Boundary forbids F042 invoke |
| **F043-R025** Full OHLCV rollback from snapshots | COULD | None | DEFERRED | No snapshot store |

## 23. Acceptance Criteria

| ID | Criterion |
|----|-----------|
| F043-AC001 | Dry-run / scan never mutates OHLCV |
| F043-AC002 | Split 1:2 restates pre-ex OHLC ÷2 and volume ×2; ex-date unchanged |
| F043-AC003 | Bonus 1:1 restates pre-ex OHLC ÷2 and volume ×2 |
| F043-AC004 | Second apply on repaired F020 CA does not re-divide prices |
| F043-AC005 | Pending F042 factors are discoverable via `pendingOhlcvRepair()` |
| F043-AC006 | Successful factor-driven repair sets `ohlcv_repair_status=completed` |
| F043-AC007 | F043 does not call F042 accept/reject or change issue status |
| F043-AC008 | F043 does not mutate portfolio transactions/holdings |
| F043-AC009 | Preview reports rows_to_adjust and divisors before apply |
| F043-AC010 | Repair processes multiple CAs in ascending ex_date order |
| F043-AC011 | cPanel/CLI default is scan/dry-run; apply requires explicit flag |
| F043-AC012 | Completed factor is skipped on re-run (idempotent) |
| F043-AC013 | When an active matching F042 factor exists, F020 apply skips OHLCV mutation but still applies ledger changes |
| F043-AC014 | F020-then-F043 and F043-then-F020 produce exactly one OHLCV restatement for the event |
| F043-AC015 | F043 CA repair scan reports `deferred_to_factor` and does not mutate OHLCV when a matching factor exists |

---

## 24. Test Requirements

| Area | Priority | CURRENT |
|------|----------|---------|
| Adjustment math split/bonus | MUST | Present |
| Repair dry-run then apply (F020) | MUST | Present |
| Continuity classification | MUST | Partial |
| Force / ambiguous / metadata-only | SHOULD | Still thin |
| F042 factor consumption | MUST | Present (`CorporateActionFactorPriceRepairTest`) |
| Factor completed status | MUST | Present |
| Multi-CA / multi-factor ordering | MUST | Present (factor path) |
| Idempotent second apply | MUST | Present |
| Authorization API (if added) | MUST | N/A until API |
| No ledger mutation | MUST | Present |

---

## 25. Current Implementation Mapping

| Concern | Code |
|---------|------|
| Scan / classify / repair CA | `CorporateActionPriceRepairService` (F020 path) |
| F042 factor scan / preview / apply | `scanPendingFactors`, `repairPendingFactors`, `applyFactorRepair` |
| OHLCV math (ratio or stored divisors) | `CorporateActionPriceAdjustmentService` |
| F020 apply also adjusts prices | `CorporateActionService::apply` |
| CLI | `RepairCorporateActionPricesCommand` (`--factors-only`, `--factor`, `--apply`) |
| Prod ops | `deploy/cpanel-repair-corporate-action-prices.php` |
| F042 pending factors | `PriceAdjustmentFactor::scopePendingOhlcvRepair` (consumed) |
| Tests | `CorporateActionFactorPriceRepairTest`, `CorporateActionPriceRepairServiceTest`, `CorporateActionPriceAdjustmentServiceTest` |

---

## 26. Known Gaps

See [F043-IMPLEMENTATION-GAP-MATRIX.md](./F043-IMPLEMENTATION-GAP-MATRIX.md).

Remaining non-blockers:

1. Admin repair API/UI (SHOULD)  
2. SQLite `lockForUpdate` no-op (production MySQL OK)  
3. Pre-existing F020 CA SQLite fixture collisions (not introduced by F043)

---

## 27. Deferred / Future Enhancements

| Item | Class |
|------|-------|
| Auto-scheduled repair after F042 accept | COULD (high risk) |
| Full rollback from snapshots | DEFERRED |
| Dividend / rights / merger adjustments | DEFERRED |
| Admin SPA repair console | COULD |
| Re-block pipelines until repaired | OUT_OF_SCOPE (would change F042) |
| Unique repair-run table | COULD |

---

*Related: [F043-IMPLEMENTATION-GAP-MATRIX.md](./F043-IMPLEMENTATION-GAP-MATRIX.md), [F043-F042-BOUNDARY.md](./F043-F042-BOUNDARY.md), [F042-F043-BOUNDARY.md](./F042-F043-BOUNDARY.md)*
