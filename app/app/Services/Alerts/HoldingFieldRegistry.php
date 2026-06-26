<?php

namespace App\Services\Alerts;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Services\HoldingPresentationService;

class HoldingFieldRegistry
{
    public function __construct(
        protected HoldingPresentationService $presentation,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, group: string}>
     */
    public function columnDefinitions(): array
    {
        return [
            ['key' => 'symbol', 'label' => 'Symbol', 'group' => 'Stock'],
            ['key' => 'stock_name', 'label' => 'Stock name', 'group' => 'Stock'],
            ['key' => 'exchange', 'label' => 'Exchange', 'group' => 'Stock'],
            ['key' => 'quantity', 'label' => 'Quantity', 'group' => 'Position'],
            ['key' => 'avg_buy_price', 'label' => 'Avg buy', 'group' => 'Position'],
            ['key' => 'invested_amount', 'label' => 'Invested', 'group' => 'Position'],
            ['key' => 'total_fees', 'label' => 'Fees', 'group' => 'Position'],
            ['key' => 'realized_profit', 'label' => 'Realized P/L', 'group' => 'Position'],
            ['key' => 'xirr', 'label' => 'XIRR', 'group' => 'Performance'],
            ['key' => 'latest_close', 'label' => 'Latest close', 'group' => 'Price'],
            ['key' => 'latest_price_date', 'label' => 'Latest price date', 'group' => 'Price'],
            ['key' => 'highest_close_since_buy', 'label' => 'Highest close since buy', 'group' => 'Price'],
            ['key' => 'highest_close_since_buy_date', 'label' => 'Highest close date', 'group' => 'Price'],
            ['key' => 'trailing_stop_price', 'label' => 'Trailing stop', 'group' => 'Stoploss'],
            ['key' => 'stoploss_percent', 'label' => 'Stoploss %', 'group' => 'Stoploss'],
            ['key' => 'first_buy_date', 'label' => 'First buy date', 'group' => 'Position'],
            ['key' => 'price_row_count', 'label' => 'Price row count', 'group' => 'Price'],
            ['key' => 'market_value', 'label' => 'Market value', 'group' => 'Performance'],
            ['key' => 'gain_loss_amount', 'label' => 'Gain/loss amount', 'group' => 'Performance'],
            ['key' => 'gain_loss_percent', 'label' => 'Gain/loss %', 'group' => 'Performance'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function columnLabels(): array
    {
        $labels = [];
        foreach ($this->columnDefinitions() as $column) {
            $labels[$column['key']] = $column['label'];
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function allowedColumnKeys(): array
    {
        return array_column($this->columnDefinitions(), 'key');
    }

    /**
     * @return array<string, mixed>
     */
    public function flattenHolding(PortfolioProfile $profile, Holding $holding): array
    {
        $enriched = $this->presentation->enrichHolding($profile, $holding);
        $stock = $holding->stock;
        $summary = $enriched['stoploss_summary'] ?? [];
        $latestClose = isset($summary['latest_close']) ? (float) $summary['latest_close'] : null;
        $qty = (float) $holding->quantity;
        $avgBuy = (float) $holding->avg_buy_price;
        $marketValue = $latestClose !== null ? $qty * $latestClose : null;
        $gainLossAmount = $marketValue !== null ? $marketValue - (float) $holding->invested_amount : null;
        $gainLossPercent = ($gainLossAmount !== null && (float) $holding->invested_amount > 0)
            ? ($gainLossAmount / (float) $holding->invested_amount) * 100
            : null;

        return [
            'symbol' => $stock?->symbol,
            'stock_name' => $stock?->name,
            'exchange' => $stock?->exchange,
            'quantity' => $qty,
            'avg_buy_price' => $avgBuy,
            'invested_amount' => (float) $holding->invested_amount,
            'total_fees' => (float) $holding->total_fees,
            'realized_profit' => (float) $holding->realized_profit,
            'xirr' => $enriched['xirr'] ?? null,
            'latest_close' => $latestClose,
            'latest_price_date' => $summary['latest_price_date'] ?? null,
            'highest_close_since_buy' => isset($summary['highest_close_since_buy'])
                ? (float) $summary['highest_close_since_buy']
                : null,
            'highest_close_since_buy_date' => $summary['highest_close_since_buy_date'] ?? null,
            'trailing_stop_price' => isset($summary['trailing_stop_price'])
                ? (float) $summary['trailing_stop_price']
                : null,
            'stoploss_percent' => isset($summary['stoploss_percent'])
                ? (float) $summary['stoploss_percent']
                : null,
            'first_buy_date' => $summary['first_buy_date'] ?? null,
            'price_row_count' => (int) ($summary['price_row_count'] ?? 0),
            'market_value' => $marketValue,
            'gain_loss_amount' => $gainLossAmount,
            'gain_loss_percent' => $gainLossPercent,
        ];
    }

    public function formatValueForDisplay(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (in_array($key, ['latest_price_date', 'highest_close_since_buy_date', 'first_buy_date'], true)) {
            return (string) $value;
        }

        if (is_numeric($value)) {
            $num = (float) $value;
            if (str_contains($key, 'percent') || $key === 'xirr') {
                return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.').'%';
            }
            if (in_array($key, ['quantity', 'price_row_count'], true)) {
                return (string) (int) round($num);
            }

            return rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    public function resolveNumericValue(string $key, array $flat): ?float
    {
        if (! in_array($key, $this->allowedColumnKeys(), true)) {
            return null;
        }

        $value = $flat[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
