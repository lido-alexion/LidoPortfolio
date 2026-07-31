<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestTrade;
use Carbon\Carbon;

/**
 * Build trade timeline matrix dynamically from persisted Trades (not stored).
 * Rows = stocks, columns = trading days, cells = holding day count coloured by P/L.
 */
class TimelineBuilder
{
    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function build(BacktestRun $run): array
    {
        $from = Carbon::parse($run->from_date->toDateString())->startOfDay();
        $to = Carbon::parse($run->to_date->toDateString())->startOfDay();
        $columns = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($d->isWeekend()) {
                continue;
            }
            $columns[] = $d->toDateString();
        }
        $dateIndex = array_flip($columns);

        $trades = BacktestTrade::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('buy_date')
            ->get();

        /** @var array<string, array<string, mixed>> $bySymbol */
        $bySymbol = [];
        foreach ($trades as $trade) {
            $symbol = (string) $trade->symbol;
            if (! isset($bySymbol[$symbol])) {
                $bySymbol[$symbol] = [
                    'symbol' => $symbol,
                    'stock_id' => (int) $trade->stock_id,
                    'cells' => array_fill(0, count($columns), null),
                ];
            }
            $buy = Carbon::parse($trade->buy_date->toDateString());
            $sell = $trade->sell_date
                ? Carbon::parse($trade->sell_date->toDateString())
                : $to->copy();
            $profitable = $trade->is_open
                ? null
                : ((float) $trade->profit_loss >= 0);
            $dayNum = 0;
            for ($d = $buy->copy(); $d->lte($sell); $d->addDay()) {
                if ($d->isWeekend()) {
                    continue;
                }
                $key = $d->toDateString();
                if (! isset($dateIndex[$key])) {
                    continue;
                }
                // Inclusive buy, exclusive sell date for closed trades (sell day not held).
                if (! $trade->is_open && $trade->sell_date && $key === $trade->sell_date->toDateString()) {
                    break;
                }
                $dayNum++;
                $idx = $dateIndex[$key];
                $bySymbol[$symbol]['cells'][$idx] = [
                    'day' => $dayNum,
                    'profitable' => $profitable,
                    'trade_id' => $trade->id,
                    'return_pct' => $trade->return_pct,
                ];
            }
        }

        $rows = array_values($bySymbol);
        usort($rows, static function (array $a, array $b): int {
            $countA = count(array_filter($a['cells']));
            $countB = count(array_filter($b['cells']));

            return $countB <=> $countA ?: strcmp($a['symbol'], $b['symbol']);
        });

        return [
            'columns' => $columns,
            'rows' => $rows,
            'trade_count' => $trades->count(),
            'stock_count' => count($rows),
        ];
    }
}
