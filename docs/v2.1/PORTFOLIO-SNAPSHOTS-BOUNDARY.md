# Portfolio Snapshots (F015) — Boundary

**Document:** V2.1 Retrospective BOUNDARY  
**Date:** 2026-08-10  
**Status:** CURRENT  
**Companion:** [`PORTFOLIO-SNAPSHOTS-SPEC.md`](./PORTFOLIO-SNAPSHOTS-SPEC.md)  
**Peer (do not reopen):** [`docs/v2/F014-BOUNDARY.md`](../v2/F014-BOUNDARY.md)

---

## 1. What F015 / Portfolio Snapshots owns

| Owns | Notes |
|------|-------|
| Materialized daily `portfolio_value` / `invested_value` rows | `portfolio_portfolio_snapshots` |
| Rebuild orchestration from ledger + OHLCV | `PortfolioSnapshotRebuildService` |
| Snapshot list + rebuild HTTP APIs | `PortfolioHistoryController` |
| Equity-curve product UI | `/portfolio/snapshots` |
| Post-commit / cron / lazy rebuild triggers for that cache | Soft-fail relative to financial unit |
| Weekend purge within rebuild ranges | TradingCalendar |

---

## 2. What F015 does **not** own

| Does not own | Owner |
|--------------|-------|
| Transaction create / update / delete / bulk CSV | `TransactionWriteService` / F019 |
| Cash deposit/withdraw/adjust / reservations | Cash Management |
| Live holdings table maintenance | `HoldingsCalculationService` |
| Per-stock as-of holdings page/API & warnings[] | **F014** |
| OHLCV ingest / CA price repair | Market data / **F043** (consume repaired prices) |
| Dashboard layout, summary cards, allocation, RS, alerts list | Dashboard presentation (consumes F015 for growth only) |
| Alert policies / Telegram | **F127** |
| Recommendation generation / preview | TOS / **F137** |
| Strategy backtest daily NAV rows | Backtest subsystem (`portfolio_backtest_snapshots`) — **distinct** |
| Market depth / market analytics snapshot tables | Analytics jobs — **distinct** |

---

## 3. F014 Historical Holdings

| | F014 | F015 |
|--|------|------|
| Question | What did I hold **per stock** on date D? | What was **portfolio / invested value** over time? |
| Persistence | None (on-demand) | Materialized daily aggregates |
| SoT | Ledger + prices | Rebuildable cache of aggregates (ledger + prices upstream) |
| Missing prices | Null / incomplete valuation (DECIDED F014) | Silent-zero close for aggregates (CURRENT F015) |
| UI | `/portfolio/historical-holdings` | `/portfolio/snapshots` |

**Rule (already in F014 BOUNDARY PD-14):** Snapshots are **not** F014 SoT. Do not merge products. F015 **reuses** `holdingsAsOf` for valuation days; that shared engine does not make F015 the owner of as-of holdings presentation.

This retrospective **describes** the boundary; it does **not** reopen F014 policies.

---

## 4. TransactionWriteService / ledger

- Ledger is upstream SoT for reconstruction.  
- Financial unit (ledger + holdings + cash) commits **before** snapshot rebuild.  
- Snapshot failure must **not** roll back cash/ledger (soft-fail post-commit).  
- F015 does not own write AuthZ or fee/cash semantics.

---

## 5. F019 Bulk CSV Import

- F019 owns parse, batch_id, validation, commit orchestration.  
- After each committed create, F019 may call `applyPostCommitSideEffects` (includes snapshot rebuild).  
- F015 owns only the rebuild semantics when invoked — not import UX or idempotency.

---

## 6. Cash Management

- Cash balance / available / reserved are **not** stored on snapshot rows.  
- Portfolio growth chart is **equity holdings valuation**, not total liquid wealth.  
- Cash-as-of remains OOS for F014 and is **not** introduced by this F015 pack.

See [`CASH-MANAGEMENT-BOUNDARY.md`](./CASH-MANAGEMENT-BOUNDARY.md) § F015.

---

## 7. F043 repaired prices

- F015 **consumes** `adjusted_close ?? close` from `portfolio_stock_prices`.  
- F043 owns repair correctness; F015 does not implement CA price adjustment.

---

## 8. F127 / F137 / Dashboard

| Peer | Relationship |
|------|----------------|
| F127 | May use live portfolio state; does not own snapshot rebuild |
| F137 | Recommendation preview consumes analytics; does not own F015 |
| Dashboard | **Presents** `portfolio_growth` and may trigger lazy/manual rebuild; does **not** own valuation formulas (those live in `PortfolioSnapshotRebuildService` / live `PortfolioCalculationService`) |

**Dashboard must not become the owner of financial calculations.** Live metrics stay in `PortfolioCalculationService`; historical curve rebuild stays in F015 services.

---

## 9. Deliberately not owned

- Cash-as-of history  
- Per-day holdings JSON persistence  
- Immutable append-only audit NAV (broker-style)  
- Merging Historical Holdings into Snapshots page  
- Backtest / market-depth snapshot schemas  

---

*End of F015 boundary (retrospective).*
