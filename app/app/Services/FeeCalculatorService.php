<?php

namespace App\Services;

/**
 * Mirrors resources/js/src/utils/feeCalculator.js for one-time DB recalculations.
 * Runtime saves trust the FE-calculated fees value.
 */
class FeeCalculatorService
{
    public const MODE_PERCENTAGE = 'percentage';

    public const MODE_FIXED = 'fixed';

    public static function defaultComponents(): array
    {
        return [
            [
                'id' => 'brokerage',
                'label' => 'Brokerage',
                'value' => '0',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'both',
                'gst_percent' => '18',
            ],
            [
                'id' => 'stt',
                'label' => 'STT/CTT',
                'value' => '0.1',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'both',
                'gst_percent' => '0',
            ],
            [
                'id' => 'txn_nse',
                'label' => 'Transaction charges (NSE)',
                'value' => '0.00307',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'NSE',
                'gst_percent' => '18',
            ],
            [
                'id' => 'txn_bse',
                'label' => 'Transaction charges (BSE)',
                'value' => '0.00375',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'BSE',
                'gst_percent' => '18',
            ],
            [
                'id' => 'sebi',
                'label' => 'SEBI charges',
                'value' => '0.0001',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => true,
                'exchange' => 'both',
                'gst_percent' => '18',
            ],
            [
                'id' => 'stamp',
                'label' => 'Stamp charges',
                'value' => '0.015',
                'mode' => self::MODE_PERCENTAGE,
                'applies_buy' => true,
                'applies_sell' => false,
                'exchange' => 'both',
                'gst_percent' => '0',
            ],
        ];
    }

    public function componentsFromSettings(): array
    {
        $raw = app(SettingsService::class)->get('fee_components');
        if ($raw === null || $raw === '') {
            return self::defaultComponents();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== []
            ? $this->normalizeComponents($decoded)
            : self::defaultComponents();
    }

    public function normalizeComponents(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $exchange = $item['exchange'] ?? 'both';
            if (! in_array($exchange, ['both', 'NSE', 'BSE'], true)) {
                $exchange = 'both';
            }
            $normalized[] = [
                'id' => (string) ($item['id'] ?? 'fee_'.($index + 1)),
                'label' => (string) ($item['label'] ?? 'Fee '.($index + 1)),
                'value' => (string) ($item['value'] ?? '0'),
                'mode' => ($item['mode'] ?? '') === self::MODE_FIXED ? self::MODE_FIXED : self::MODE_PERCENTAGE,
                'applies_buy' => ($item['applies_buy'] ?? true) !== false,
                'applies_sell' => ($item['applies_sell'] ?? true) !== false,
                'exchange' => $exchange,
                'gst_percent' => (string) ($item['gst_percent'] ?? '0'),
            ];
        }

        return $normalized !== [] ? $normalized : self::defaultComponents();
    }

    /**
     * @return array{total: float, breakdown: list<array{id: string, label: string, base: float, gst: float, total: float}>}
     */
    public function calculate(float $quantity, float $price, string $type, string $exchange, ?array $components = null): array
    {
        if ($quantity <= 0 || $price <= 0) {
            return ['total' => 0.0, 'breakdown' => []];
        }

        $components ??= $this->componentsFromSettings();
        $turnover = $quantity * $price;
        $breakdown = [];
        $total = 0.0;

        foreach ($components as $component) {
            if (! $this->componentApplies($component, $type)) {
                continue;
            }
            if (! $this->componentMatchesExchange($component, $exchange)) {
                continue;
            }

            $rate = (float) ($component['value'] ?? 0);
            $base = ($component['mode'] ?? '') === self::MODE_FIXED
                ? $rate
                : $turnover * ($rate / 100);
            $gstRate = (float) ($component['gst_percent'] ?? 0);
            $gst = $base * ($gstRate / 100);
            $lineTotal = $base + $gst;

            $breakdown[] = [
                'id' => $component['id'],
                'label' => $component['label'],
                'base' => $this->roundMoney($base),
                'gst' => $this->roundMoney($gst),
                'total' => $this->roundMoney($lineTotal),
            ];
            $total += $lineTotal;
        }

        return [
            'total' => $this->roundMoney($total),
            'breakdown' => $breakdown,
        ];
    }

    protected function componentApplies(array $component, string $type): bool
    {
        if ($type === 'buy') {
            return (bool) ($component['applies_buy'] ?? false);
        }
        if ($type === 'sell') {
            return (bool) ($component['applies_sell'] ?? false);
        }

        return false;
    }

    protected function componentMatchesExchange(array $component, string $exchange): bool
    {
        $filter = $component['exchange'] ?? 'both';
        if ($filter === 'both') {
            return true;
        }

        return $filter === $exchange;
    }

    protected function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
