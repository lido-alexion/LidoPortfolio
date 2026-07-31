<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestRunHit;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Persist completed-run facts and cascade-delete all run artifacts.
 */
class BacktestPersistenceService
{
    /**
     * @param  list<array<string, mixed>>  $transactions
     * @param  list<array<string, mixed>>  $closedTrades
     * @param  array<string, mixed>  $snapshot
     */
    public function persistDayResults(BacktestRun $run, array $transactions, array $closedTrades, array $snapshot): void
    {
        DB::transaction(function () use ($run, $transactions, $closedTrades, $snapshot) {
            foreach ($transactions as $tx) {
                BacktestTransaction::query()->create([
                    'backtest_run_id' => $run->id,
                    'trade_date' => $tx['trade_date'],
                    'stock_id' => $tx['stock_id'],
                    'symbol' => $tx['symbol'],
                    'side' => $tx['side'],
                    'quantity' => $tx['quantity'],
                    'price' => $tx['price'],
                    'value' => $tx['value'],
                    'reason' => $tx['reason'] ?? null,
                    'recommendation' => $tx['recommendation'] ?? null,
                    'meta_json' => $tx['meta_json'] ?? null,
                ]);
            }
            foreach ($closedTrades as $trade) {
                BacktestTrade::query()->create(array_merge($trade, [
                    'backtest_run_id' => $run->id,
                ]));
            }
            BacktestSnapshot::query()->updateOrCreate(
                [
                    'backtest_run_id' => $run->id,
                    'snapshot_date' => $snapshot['snapshot_date'],
                ],
                [
                    'cash' => $snapshot['cash'],
                    'invested_value' => $snapshot['invested_value'],
                    'portfolio_value' => $snapshot['portfolio_value'],
                    'realized_profit' => $snapshot['realized_profit'],
                    'unrealized_profit' => $snapshot['unrealized_profit'],
                    'drawdown_pct' => $snapshot['drawdown_pct'],
                    'holdings_count' => $snapshot['holdings_count'],
                ]
            );
        });
    }

    /**
     * Mark remaining open lots as open trades at end of simulation (for reporting).
     */
    public function persistOpenLotsAsTrades(BacktestRun $run, SimulationContext $ctx, string $asOfDate): void
    {
        $lots = is_array($ctx->get('open_lots', [])) ? $ctx->get('open_lots', []) : [];
        $holdings = $ctx->holdings();
        $portfolio = new PaperPortfolioManager($ctx);
        $stockIds = array_map('intval', array_keys($lots));
        $prices = $portfolio->closesAsOf($stockIds, $asOfDate);

        foreach ($lots as $stockIdKey => $stockLots) {
            if (! is_array($stockLots)) {
                continue;
            }
            $stockId = (int) $stockIdKey;
            $symbol = (string) ($holdings[$stockIdKey]['symbol'] ?? $stockId);
            $mark = $prices[$stockId] ?? null;
            foreach ($stockLots as $lot) {
                $qty = (float) ($lot['qty'] ?? 0);
                $buyPrice = (float) ($lot['price'] ?? 0);
                $buyDate = (string) ($lot['buy_date'] ?? $asOfDate);
                if ($qty < 1) {
                    continue;
                }
                $holdingDays = max(0, (int) ((strtotime($asOfDate) - strtotime($buyDate)) / 86400));
                $pl = $mark !== null ? round(($mark - $buyPrice) * $qty, 4) : null;
                $ret = ($mark !== null && $buyPrice > 0)
                    ? BacktestMath::clampDecimal12_6(round((($mark - $buyPrice) / $buyPrice) * 100.0, 6))
                    : null;
                BacktestTrade::query()->create([
                    'backtest_run_id' => $run->id,
                    'stock_id' => $stockId,
                    'symbol' => $symbol,
                    'buy_date' => $buyDate,
                    'sell_date' => null,
                    'holding_days' => $holdingDays,
                    'buy_price' => $buyPrice,
                    'sell_price' => $mark,
                    'quantity' => $qty,
                    'profit_loss' => $pl,
                    'return_pct' => $ret,
                    'cagr' => null,
                    'exit_reason' => 'open_at_end',
                    'is_open' => true,
                ]);
            }
        }
    }

    public function clearTransientState(BacktestRun $run): void
    {
        BacktestRunHit::query()->where('backtest_run_id', $run->id)->delete();
        $run->forceFill(['context_json' => null])->save();
    }

    public function deleteRun(BacktestRun $run): void
    {
        DB::transaction(function () use ($run) {
            BacktestRunHit::query()->where('backtest_run_id', $run->id)->delete();
            BacktestTransaction::query()->where('backtest_run_id', $run->id)->delete();
            BacktestTrade::query()->where('backtest_run_id', $run->id)->delete();
            BacktestSnapshot::query()->where('backtest_run_id', $run->id)->delete();
            $run->delete();
        });
    }
}
