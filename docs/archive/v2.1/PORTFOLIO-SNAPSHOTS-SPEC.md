# Portfolio Snapshots (F015) — Retrospective CURRENT Specification

**Document:** V2.1 Retrospective CURRENT Spec  
**Location:** `docs/v2.1/PORTFOLIO-SNAPSHOTS-SPEC.md`  
**Date:** 2026-08-10  
**Status:** CURRENT (runtime formalization — not a new feature)  
**Product label:** F015 (already shipped; no new F-number)  
**Related:** [`implementation.md`](../../implementation.md) § Historical snapshot rebuild; F014 [`docs/v2/F014-BOUNDARY.md`](../v2/F014-BOUNDARY.md); WS-C [`WS-C-SHADOW-FEATURE-INVENTORY.md`](./WS-C-SHADOW-FEATURE-INVENTORY.md); Cash [`CASH-MANAGEMENT-BOUNDARY.md`](./CASH-MANAGEMENT-BOUNDARY.md)

**Do not reopen F014 or F015 as unfinished initiatives. Do not start V3.**

---

## 1. Purpose

Formalize the **CURRENT** Portfolio Snapshots implementation so operators, Dashboard consumers, and future hardening share one description of:

- materialized daily portfolio / invested value history  
- rebuild triggers and algorithms  
- relationship to the transaction ledger and F014 Historical Holdings  
- what Dashboard consumes vs what Snapshots own  

This documents **what ships today**, not a redesign.

---

## 2. Current capability

| Area | Status |
|------|--------|
| Per-profile daily snapshot rows (`portfolio_value`, `invested_value`) | **IMPLEMENTED** |
| Rebuild from ledger + OHLCV (`PortfolioSnapshotRebuildService`) | **IMPLEMENTED** |
| Post-commit rebuild after transaction create/update/delete | **IMPLEMENTED** (skipped on create/update during PHPUnit) |
| Manual rebuild API + UI | **IMPLEMENTED** |
| Daily cron “today” refresh via `storeSnapshot` | **IMPLEMENTED** |
| Dashboard growth series (latest 365 days) + lazy rebuild | **IMPLEMENTED** |
| Dedicated page `/portfolio/snapshots` + chart/table | **IMPLEMENTED** |
| Per-holding detail persisted on snapshot rows | **NOT IMPLEMENTED** (computed in-memory only during rebuild) |
| Cash / cash-as-of in snapshot valuation | **NOT IMPLEMENTED** |
| Append-only immutable history (belief-time) | **NOT IMPLEMENTED** — rebuildable cache with `updateOrCreate` |
| Retention / archival purge policy | **NOT IMPLEMENTED** / **NOT FOUND** |
| Separate F015 V2 feature pack (pre this pass) | Was documentation gap → addressed by this pack |

---

## 3. User workflows (CURRENT)

### 3.1 View Portfolio Snapshots page

1. Operator opens **Portfolio → Portfolio Snapshots** (`/portfolio/snapshots`).  
2. UI calls `GET /api/portfolio/snapshots` with range (90/180/365 days or All `limit≤2000`).  
3. Chart and table show backend `portfolio_value` / `invested_value`.  
4. Unrealized P/L and day-over-day change are **display derivatives** (not stored columns).

### 3.2 Manual rebuild

1. Confirm dialog on Snapshots page or Dashboard growth card.  
2. `POST /api/portfolio/rebuild-history` (optional `from_date` / `to_date`).  
3. Without `from_date`: from earliest transaction date → today.  
4. Response includes `rebuild.snapshots_written`, missing-price warning count, duration, etc.  
5. UI refreshes snapshots and notifies Dashboard refresh.

### 3.3 Automatic rebuild after ledger change

1. Transaction create / update / delete / corporate-action apply / F019 post-commit side effects.  
2. After financial unit commits, `rebuildAfterTransactionChange(profile, previousDate, newDate)`.  
3. Rebuild from `min(dates)` → today (or today-only if no dates).  
4. Soft-fail by default on create/update path (`softFailSnapshots=true`) so snapshot errors do not undo ledger/cash.  
5. Create/update post-commit side effects are **skipped when `app()->runningUnitTests()`**; delete always attempts rebuild (even in tests).

### 3.4 Daily market sync

1. `DailyMarketDataJob` / `portfolio:daily-sync` after price sync.  
2. For each `PortfolioProfile`: `PortfolioCalculationService::storeSnapshot` → `rebuildDateRange(today, today)`.  
3. Refreshes **today’s** row; not the sole source of full history.

### 3.5 Dashboard growth consumption

1. `GET /api/dashboard` → `portfolio_growth`: latest **365** snapshot rows ascending.  
2. If empty but profile has transactions → one-time lazy `rebuildFromDate(earliest)` then re-fetch.  
3. Summary cards (`portfolio_value`, `invested_value`, XIRR, allocation, etc.) come from **live** `PortfolioCalculationService::calculateForProfile` (holdings table + latest closes) — **not** from reading the latest snapshot as SoT.  
4. `daily_change` compares today vs yesterday **snapshot** rows when both exist.

### 3.6 Explicitly NOT FOUND / NOT IMPLEMENTED

| Workflow | Status |
|----------|--------|
| Edit a single historical snapshot row in UI | **NOT IMPLEMENTED** |
| Export snapshot CSV | **NOT IMPLEMENTED** |
| Cash included in portfolio_value history | **NOT IMPLEMENTED** |
| Belief-time / as-of-before-CA snapshot series | **NOT IMPLEMENTED** (rebuild uses ledger as known today) |
| Point-in-time holdings drilldown from a snapshot row | **NOT IMPLEMENTED** (use F014 as-of page) |

---

## 4. Snapshot lifecycle

```text
Ledger write (CRUD / F019 / F020 CA / TOS fill)
        │
        ▼
Financial unit commits (ledger + holdings + cash)
        │
        ▼
Post-commit (best-effort): rebuildAfterTransactionChange
        │
        ├─ ensureHistoricalPrices (gap-fill OHLCV)
        ├─ purgeWeekendSnapshots in range
        ├─ resolveTradingDates (weekdays with prices + today if session)
        └─ for each trading day D:
              holdingsAsOf(ledger ≤ D)          ← shared reconstruction engine
              × close (adjusted_close ?? close) on/before D
              → updateOrCreate snapshot(profile, D)

Daily cron: rebuildDateRange(today, today)
Manual / Dashboard lazy: rebuildFromDate / rebuildDateRange

Consumers: GET /portfolio/snapshots · dashboard portfolio_growth · daily_change
```

**Automatic + manual.** **Rebuild-based** (not incremental delta-only). **Materialized cache** (authoritative for the equity-curve product surface; regenerable from ledger + prices).

---

## 5. Balance / valuation calculation (CURRENT)

For trading day **D**:

1. **Holdings(D)** — `PortfolioHistoricalHoldingsService::holdingsAsOf` — replay transactions with `transaction_date ≤ D` (order date, id); fee-exclusive remaining cost basis.  
2. **portfolio_value(D)** — `Σ quantity(D) × close_on_or_before(D)` where close = `adjusted_close_price ?? close_price`.  
3. **invested_value(D)** — `Σ remaining_cost_basis(D)` for open holdings.  
4. **unrealized_profit(D)** — computed as `portfolio_value − invested_value` during rebuild but **not persisted**.  
5. Missing / non-positive close → log warning, treat close as **0** for that holding (silent-zero for aggregates). Weekend price rows skipped via `TradingCalendar`.

**Cash balance is not part of portfolio_value.** Snapshots are equity (holdings market value) history only.

---

## 6. What is stored

Table: `portfolio_portfolio_snapshots`

| Column | Meaning |
|--------|---------|
| `profile_id` | Owning portfolio (unique with `snapshot_date`) |
| `snapshot_date` | Calendar date of the equity-session snapshot |
| `portfolio_value` | Aggregated market value (decimal 18,4) |
| `invested_value` | Aggregated remaining cost basis (decimal 18,4) |
| `created_at` | Last write time (`updateOrCreate` refreshes) |

**Not stored:** per-stock qty/close/market_value; unrealized; cash; realized P&L; warnings array.

---

## 7. Source of truth (CURRENT)

| Concern | Source of truth |
|---------|-----------------|
| Holdings reconstruction / transaction history | **`portfolio_transactions` ledger** |
| Live holdings qty / avg cost | **`portfolio_holdings`** (derived; maintained by write path) |
| Daily equity-curve display (F015) | **Materialized `portfolio_portfolio_snapshots`** — **rebuildable derived cache**, not an independent ledger |
| Per-stock as-of cross-section (F014) | **On-demand** from ledger (+ prices); **must not** use snapshot rows as SoT |
| Live Dashboard headline value / invested / XIRR | **Live calculation** from holdings + quotes — not “latest snapshot wins” |
| Cash | **Cash Management** account/ledger — out of F015 valuation |

**Philosophy (CURRENT, from runtime + `implementation.md`):**  
Snapshots answer: *“What was my portfolio worth on date D given all transactions known today?”* — not immutable belief-time history.

---

## 8. Rebuild behaviour

| Mode | Entry | Range |
|------|-------|-------|
| After tx change | `rebuildAfterTransactionChange` | `min(old,new date)` → today |
| Full / manual | `rebuildFromDate` / API without `from_date` | Earliest tx → today |
| Partial manual | API `from_date` (+ optional `to_date`) | Explicit range |
| Daily cron | `storeSnapshot` | today → today |
| Dashboard lazy | Empty growth + txs exist | Earliest tx → today |

**Duplicates:** `updateOrCreate` on `(profile_id, snapshot_date)`.  
**Weekends:** purged in rebuild range; not written as snapshot dates.  
**Empty portfolio (no stock ids):** still may write today’s date from `resolveTradingDates`.

---

## 9. APIs (CURRENT)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/portfolio/snapshots` | List rows (`from_date`, `to_date`, `limit` 1–2000, default 365); profile-scoped; ascending in response |
| POST | `/api/portfolio/rebuild-history` | Manual rebuild |

Auth: authenticated user + `activePortfolio()`.

---

## 10. Frontend (CURRENT)

| Surface | Role |
|---------|------|
| `PortfolioSnapshotsPage.jsx` | Full history UI |
| `PortfolioSnapshotGrowthChart.jsx` | Chart component |
| `DashboardPage.jsx` | Growth chart; View snapshots; Rebuild history |
| Help topic `portfolio-snapshots` in `appDocumentation.js` | Contextual help |
| Public HTML stubs | `public/docs/portfolio-snapshots.html`, `snapshots.html` |

---

## 11. Error behaviour

| Case | CURRENT |
|------|---------|
| Missing historical close | Warning log; close treated as 0; `missing_price_warnings` incremented |
| OHLCV gap fill incomplete | Provider warning log; rebuild continues |
| Snapshot rebuild throws after create/update | Soft-fail default — financial unit already committed |
| Rebuild API unauthenticated | 401 |
| Cross-profile read | Scoped to `activePortfolio()` only |

---

## 12. Precision / rounding

Persisted values rounded to **4 decimal places** during calculation; API returns decimal strings for values.

---

## 13. Profile / AuthZ

Snapshots belong to `profile_id`. Portfolio delete removes profile-scoped data (including snapshots — covered by portfolio delete tests). Cross-user isolation via active portfolio middleware / ownership.

---

## 14. Current limitations

1. Silent-zero missing prices can understate historical portfolio_value vs F014’s null/incomplete presentation.  
2. Holdings detail computed on rebuild is discarded (not queryable later without recompute / F014).  
3. No cash in curve — total wealth ≠ portfolio_value + cash.  
4. Create/update skip rebuild in PHPUnit; delete does not — test asymmetry.  
5. F019 may invoke post-commit side effects per imported row (multiple rebuilds) — performance debt.  
6. No retention policy for old rows.  
7. Formal V2.1 pack was missing before this document (help + F014 boundary existed).

---

## 15. Explicit out of scope (CURRENT product / this pack)

- Redesigning Dashboard analytics ownership  
- Adding cash-as-of to snapshots  
- Merging F014 and F015 UIs  
- Belief-time CA history  
- New migrations / schema columns  
- Broker portfolio NAV import  

---

## 16. Test coverage summary

| Area | Coverage |
|------|----------|
| State calculation / rebuild writes | `PortfolioSnapshotRebuildTest` |
| Weekend skip / nearest close | Same |
| Rebuild API auth + happy path | Same |
| GET snapshots filters / profile scope / limit | `PortfolioSnapshotApiTest` |
| Dashboard lazy rebuild | `DashboardGrowthTest` |
| Holdings engine shared with F014 | `PortfolioHistoricalHoldingsServiceTest` / `HistoricalHoldingsTest` |
| Soft-fail / PHPUnit skip of post-commit | Indirect / **TEST GAP** for production soft-fail assert |
| Cross-user snapshot leak | Profile scoping tested; dedicated cross-user rebuild **TEST GAP** thin |

See GAP matrix.

---

## Runtime inventory (quick reference)

| Layer | Paths |
|-------|-------|
| UI | `PortfolioSnapshotsPage.jsx`, `PortfolioSnapshotGrowthChart.jsx`, Dashboard growth |
| API | `PortfolioHistoryController` — snapshots + rebuild-history |
| Service | `PortfolioSnapshotRebuildService`, `PortfolioCalculationService::storeSnapshot` |
| Shared engine | `PortfolioHistoricalHoldingsService::holdingsAsOf` |
| Model / table | `PortfolioSnapshot` → `portfolio_portfolio_snapshots` |
| Triggers | `TransactionWriteService`, `CorporateActionService`, `BulkTransactionImportService` post-commit, `DailyMarketDataJob` |
| Tests | `PortfolioSnapshotApiTest`, `PortfolioSnapshotRebuildTest`, `DashboardGrowthTest` |
