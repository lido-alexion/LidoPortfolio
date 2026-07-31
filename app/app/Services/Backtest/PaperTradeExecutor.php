<?php

namespace App\Services\Backtest;

/**
 * Executes virtual BUY/SELL against paper portfolio + open-lot book for trade pairing.
 */
class PaperTradeExecutor
{
    public function __construct(private SimulationContext $ctx) {}

    /**
     * @return array{ok: bool, transaction?: array<string, mixed>, error?: string}
     */
    public function buy(
        string $date,
        int $stockId,
        string $symbol,
        float $qty,
        float $price,
        string $reason,
        string $recommendation,
    ): array {
        $qty = (float) floor($qty);
        if ($qty < 1 || $price <= 0) {
            return ['ok' => false, 'error' => 'invalid_buy'];
        }
        $value = round($qty * $price, 4);
        if ($value > $this->ctx->cash() + 0.0001) {
            // Clamp to affordable whole shares.
            $affordable = (int) floor($this->ctx->cash() / $price);
            if ($affordable < 1) {
                return ['ok' => false, 'error' => 'insufficient_cash'];
            }
            $qty = (float) $affordable;
            $value = round($qty * $price, 4);
        }

        $this->ctx->setCash($this->ctx->cash() - $value);
        $holdings = $this->ctx->holdings();
        $key = (string) $stockId;
        $existing = $holdings[$key] ?? $holdings[$stockId] ?? null;
        if ($existing) {
            $oldQty = (float) $existing['qty'];
            $oldCost = (float) $existing['avg_cost'];
            $newQty = $oldQty + $qty;
            $avg = $newQty > 0 ? (($oldQty * $oldCost) + $value) / $newQty : $price;
            $holdings[$key] = [
                'qty' => $newQty,
                'avg_cost' => round($avg, 6),
                'buy_date' => $existing['buy_date'] ?? $date,
                'symbol' => $symbol,
                'invested' => round(((float) ($existing['invested'] ?? 0)) + $value, 4),
            ];
        } else {
            $holdings[$key] = [
                'qty' => $qty,
                'avg_cost' => round($price, 6),
                'buy_date' => $date,
                'symbol' => $symbol,
                'invested' => $value,
            ];
        }
        $this->ctx->setHoldings($holdings);

        $lots = is_array($this->ctx->get('open_lots', [])) ? $this->ctx->get('open_lots', []) : [];
        $stockLots = is_array($lots[$key] ?? null) ? $lots[$key] : [];
        $stockLots[] = ['qty' => $qty, 'price' => $price, 'buy_date' => $date];
        $lots[$key] = $stockLots;
        $this->ctx->set('open_lots', $lots);

        return [
            'ok' => true,
            'transaction' => [
                'trade_date' => $date,
                'stock_id' => $stockId,
                'symbol' => $symbol,
                'side' => 'BUY',
                'quantity' => $qty,
                'price' => $price,
                'value' => $value,
                'reason' => $reason,
                'recommendation' => $recommendation,
            ],
        ];
    }

    /**
     * @return array{ok: bool, transaction?: array<string, mixed>, closed_trades?: list<array<string, mixed>>, error?: string}
     */
    public function sell(
        string $date,
        int $stockId,
        string $symbol,
        float $qty,
        float $price,
        string $reason,
        string $recommendation,
    ): array {
        $qty = (float) floor($qty);
        if ($qty < 1 || $price <= 0) {
            return ['ok' => false, 'error' => 'invalid_sell'];
        }

        $holdings = $this->ctx->holdings();
        $key = (string) $stockId;
        $existing = $holdings[$key] ?? null;
        if ($existing === null) {
            return ['ok' => false, 'error' => 'not_held'];
        }
        $held = (float) ($existing['qty'] ?? 0);
        if ($held < 1) {
            return ['ok' => false, 'error' => 'not_held'];
        }
        $qty = min($qty, floor($held));
        $value = round($qty * $price, 4);
        $avgCost = (float) ($existing['avg_cost'] ?? 0);
        $costSold = round($qty * $avgCost, 4);
        $realized = round($value - $costSold, 4);

        $this->ctx->setCash($this->ctx->cash() + $value);
        $this->ctx->set('realized_profit', round((float) $this->ctx->get('realized_profit', 0) + $realized, 4));

        $remaining = $held - $qty;
        if ($remaining < 0.0001) {
            unset($holdings[$key]);
        } else {
            $holdings[$key]['qty'] = $remaining;
            $holdings[$key]['invested'] = round(max(0, ((float) ($existing['invested'] ?? 0)) - $costSold), 4);
        }
        $this->ctx->setHoldings($holdings);

        $closedTrades = $this->closeLots($key, $stockId, $symbol, $qty, $price, $date, $reason);

        return [
            'ok' => true,
            'transaction' => [
                'trade_date' => $date,
                'stock_id' => $stockId,
                'symbol' => $symbol,
                'side' => 'SELL',
                'quantity' => $qty,
                'price' => $price,
                'value' => $value,
                'reason' => $reason,
                'recommendation' => $recommendation,
                'meta_json' => ['realized_pl' => $realized],
            ],
            'closed_trades' => $closedTrades,
        ];
    }

    /**
     * FIFO lot close.
     *
     * @return list<array<string, mixed>>
     */
    private function closeLots(
        string $key,
        int $stockId,
        string $symbol,
        float $qty,
        float $sellPrice,
        string $sellDate,
        string $exitReason,
    ): array {
        $lots = is_array($this->ctx->get('open_lots', [])) ? $this->ctx->get('open_lots', []) : [];
        $stockLots = is_array($lots[$key] ?? null) ? $lots[$key] : [];
        $remaining = $qty;
        $closed = [];
        $newLots = [];

        foreach ($stockLots as $lot) {
            if ($remaining < 0.0001) {
                $newLots[] = $lot;

                continue;
            }
            $lotQty = (float) ($lot['qty'] ?? 0);
            $take = min($lotQty, $remaining);
            if ($take > 0) {
                $buyDate = (string) ($lot['buy_date'] ?? $sellDate);
                $buyPrice = (float) ($lot['price'] ?? 0);
                $holdingDays = max(0, (int) ((strtotime($sellDate) - strtotime($buyDate)) / 86400));
                $pl = round(($sellPrice - $buyPrice) * $take, 4);
                $ret = $buyPrice > 0 ? round((($sellPrice - $buyPrice) / $buyPrice) * 100.0, 6) : 0.0;
                $cagr = BacktestMath::cagrPercent($buyPrice, $sellPrice, $holdingDays);
                $closed[] = [
                    'stock_id' => $stockId,
                    'symbol' => $symbol,
                    'buy_date' => $buyDate,
                    'sell_date' => $sellDate,
                    'holding_days' => $holdingDays,
                    'buy_price' => $buyPrice,
                    'sell_price' => $sellPrice,
                    'quantity' => $take,
                    'profit_loss' => $pl,
                    'return_pct' => BacktestMath::clampDecimal12_6($ret),
                    'cagr' => $cagr,
                    'exit_reason' => $exitReason,
                    'is_open' => false,
                ];
                $remaining -= $take;
                $left = $lotQty - $take;
                if ($left > 0.0001) {
                    $newLots[] = ['qty' => $left, 'price' => $buyPrice, 'buy_date' => $buyDate];
                }
            }
        }

        if ($newLots === []) {
            unset($lots[$key]);
        } else {
            $lots[$key] = $newLots;
        }
        $this->ctx->set('open_lots', $lots);

        return $closed;
    }
}
