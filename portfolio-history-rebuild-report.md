# Portfolio History Rebuild — Implementation Report

**Date:** May 2026  
**Status:** Complete

## Problem (before)

- Snapshots were written only when `portfolio:daily-sync` ran.
- Backdated transaction edits did not rebuild historical portfolio state.
- Deleted transactions left stale snapshot rows.
- Dashboard growth chart reflected sparse cron points, not true holdings history.

## Solution

Transaction-aware **materialized snapshots**: rebuildable cache derived from the transaction ledger + historical OHLCV.

## Architecture

```
Transaction CRUD
    → PortfolioSnapshotRebuildService::rebuildAfterTransactionChange()
        → ensure OHLCV (StockPriceHistoryService)
        → for each trading day D in [affected_start, today]:
              PortfolioHistoricalHoldingsService::holdingsAsOf(D)
              × close on/before D
              → updateOrCreate portfolio_portfolio_snapshots
```

## Formulas

| Metric | Formula |
|--------|---------|
| Holdings on D | Replay all txs with `transaction_date <= D` |
| `portfolio_value(D)` | Σ `qty(D) × close(D)` |
| `invested_value(D)` | Σ open cost basis after avg-cost rules |
| Close on D | Latest `portfolio_stock_prices.close` where `price_date <= D` |

## Historical correction handling

| Event | Rebuild from |
|-------|----------------|
| New buy/sell | `transaction_date` |
| Edit date | `min(old_date, new_date)` |
| Delete | deleted `transaction_date` |

Snapshots are **updated in place** (`updateOrCreate`) — not append-only.

## Performance optimizations

- Rebuild only `affected_start → today`, not full account lifetime unless manual API omits `from_date`.
- Preload OHLCV into per-stock price index for the range (avoid N× queries per day).
- Trading-day-only rows (distinct price dates + today).
- Daily cron writes **today only** via `rebuildDateRange(today, today)`.
- Queue-compatible: rebuild runs synchronously today; can be wrapped in a job later.

## Edge cases handled

- Partial sells and multiple buys (avg cost + invested amount).
- Weekends/holidays (prior close on or before D).
- Missing OHLCV: fetch providers first; log warning if still missing (market value for that symbol = 0 for that day).
- Future-dated txs excluded from historical D.
- Dashboard chart up to **365** snapshot points.

## Known limitations

- Rebuild is **synchronous** on transaction save (may add latency on large histories; consider queued rebuild for very long ranges).
- Trading-day-only snapshots (no explicit row on calendar days without a price row for any held symbol).
- Symbols with no price data after fetch still log warnings and contribute 0 to market value for that day.
- Graph is “as-of today’s transaction ledger,” not point-in-time knowledge.

## Files touched

| Area | Files |
|------|--------|
| Services | `PortfolioSnapshotRebuildService.php`, `PortfolioHistoricalHoldingsService.php` |
| Controller | `TransactionController.php`, `PortfolioHistoryController.php` |
| Routes | `POST /api/portfolio/rebuild-history` |
| Frontend | `portfolioEvents.js`, `DashboardPage.jsx`, `TransactionsPage.jsx` |
| Tests | `PortfolioSnapshotRebuildTest.php`, `PortfolioHistoricalHoldingsServiceTest.php |
| Docs | `implementation.md`, this report |

## Verification

```bash
cd backend
php artisan test --filter=Portfolio
php artisan test
```
