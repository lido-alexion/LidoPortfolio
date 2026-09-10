<?php

namespace App\Services\Simulation;

use App\Models\PortfolioProfile;
use App\Models\StockPrice;
use Illuminate\Validation\ValidationException;

final class SimulationPriceService
{
    /** @return array<string, mixed> */
    public function resolve(
        int $stockId,
        string $sessionDate,
        string $method,
        string $side = 'buy',
        float $adverseSlippagePercent = 0.0,
    ): array {
        if (! in_array($method, PortfolioProfile::SIMULATION_PRICE_METHODS, true)) {
            throw ValidationException::withMessages(['price_method' => 'Unsupported simulation price method.']);
        }
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw ValidationException::withMessages(['side' => 'Simulation side must be buy or sell.']);
        }
        if ($adverseSlippagePercent < 0.0 || $adverseSlippagePercent > 100.0) {
            throw ValidationException::withMessages(['adverse_slippage_percent' => 'Slippage must be between 0 and 100 percent.']);
        }

        // Exact economic session only: a later observation must never leak backward.
        $row = StockPrice::query()->where('stock_id', $stockId)
            ->whereDate('price_date', $sessionDate)->orderByDesc('id')->first();
        if ($row === null) {
            return $this->blocked($stockId, $sessionDate, $method, 'missing_session_ohlc');
        }

        $required = match ($method) {
            'next_open' => ['open_price'],
            'next_close' => ['close_price'],
            'ohlc_average' => ['open_price', 'high_price', 'low_price', 'close_price'],
            'high_low_midpoint' => ['high_price', 'low_price'],
        };
        foreach ($required as $field) {
            if ($row->{$field} === null || (float) $row->{$field} <= 0.0) {
                return $this->blocked($stockId, $sessionDate, $method, 'missing_'.$field);
            }
        }

        $base = match ($method) {
            'next_open' => (float) $row->open_price,
            'next_close' => (float) $row->close_price,
            'ohlc_average' => ((float) $row->open_price + (float) $row->high_price
                + (float) $row->low_price + (float) $row->close_price) / 4.0,
            'high_low_midpoint' => ((float) $row->high_price + (float) $row->low_price) / 2.0,
        };
        $factor = 1.0 + (($side === 'buy' ? 1.0 : -1.0) * $adverseSlippagePercent / 100.0);
        $price = round($base * $factor, 4);
        $ohlc = [
            'open' => (float) $row->open_price,
            'high' => (float) $row->high_price,
            'low' => (float) $row->low_price,
            'close' => (float) $row->close_price,
        ];

        return [
            'status' => 'ready',
            'stock_id' => $stockId,
            'effective_session_date' => $sessionDate,
            'method' => $method,
            'side' => $side,
            'adverse_slippage_percent' => $adverseSlippagePercent,
            'base_price' => round($base, 4),
            'execution_price' => $price,
            'source' => [
                'stock_price_id' => $row->id,
                'provider' => $row->provider_source,
                'recorded_at' => $row->created_at?->toISOString(),
                'ohlc' => $ohlc,
                'fingerprint' => hash('sha256', json_encode([$stockId, $sessionDate, $ohlc, $row->provider_source], JSON_THROW_ON_ERROR)),
            ],
            'limitations' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function blocked(int $stockId, string $sessionDate, string $method, string $reason): array
    {
        return [
            'status' => 'blocked', 'stock_id' => $stockId,
            'effective_session_date' => $sessionDate, 'method' => $method,
            'execution_price' => null, 'source' => null, 'limitations' => [$reason],
        ];
    }
}
