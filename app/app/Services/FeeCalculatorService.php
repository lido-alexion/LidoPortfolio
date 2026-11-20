<?php

namespace App\Services;

/**
 * Mirrors resources/js/src/utils/feeCalculator.js for one-time DB recalculations.
 * Runtime saves calculate fees authoritatively on the backend.
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
                // Zerodha's published client charge currently includes the NSE
                // ₹2.97/lakh exchange levy plus ₹0.10/lakh IPFT.
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
            [
                'id' => 'dp_zerodha', 'label' => 'Depository participant (DP)', 'value' => '13',
                'mode' => self::MODE_FIXED, 'applies_buy' => false, 'applies_sell' => true,
                'exchange' => 'both', 'gst_percent' => '18', 'aggregation' => 'isin_day_sell',
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
                'aggregation' => ($item['aggregation'] ?? null) === 'isin_day_sell' ? 'isin_day_sell' : null,
            ];
        }

        return $normalized !== [] ? $normalized : self::defaultComponents();
    }

    /**
     * @return array{total: float, breakdown: list<array{id: string, label: string, base: float, gst: float, total: float}>}
     */
    public function calculate(float $quantity, float $price, string $type, string $exchange, ?array $components = null, ?string $tradeDate = null): array
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

            $rate = $this->rateForDate($component, $tradeDate);
            $base = ($component['mode'] ?? '') === self::MODE_FIXED
                ? $rate
                : $turnover * ($rate / 100);
            if (in_array(($component['id'] ?? null), ['stt', 'stamp'], true)) {
                $base = floor($base + 0.5);
            }
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

    /**
     * Calculate a complete trade-date batch. Invoice-level percentage charges are
     * rounded once per side/exchange/component, then allocated to rows by largest
     * remainder so row cents add back to the source invoice amount.
     *
     * @param list<array{id?: int|string, quantity: float|string, price: float|string, type: string, execution_exchange?: string, transaction_date?: string}> $rows
     * @return list<array{total: float, breakdown: list<array{id: string, label: string, base: float, gst: float, total: float}>}>
     */
    public function calculateGrouped(array $rows, ?array $components = null): array
    {
        $components ??= $this->componentsFromSettings();
        $results = array_fill(0, count($rows), ['total' => 0.0, 'breakdown' => []]);
        $groups = [];

        foreach ($rows as $index => $row) {
            $quantity = (float) ($row['quantity'] ?? 0);
            $price = (float) ($row['price'] ?? 0);
            $type = strtolower((string) ($row['type'] ?? ''));
            $exchange = (string) ($row['execution_exchange'] ?? 'NSE');
            if ($quantity <= 0 || $price <= 0 || ! in_array($type, ['buy', 'sell'], true)) continue;
            $date = (string) ($row['transaction_date'] ?? 'undated');
            $turnover = $quantity * $price;

            foreach ($components as $component) {
                if (! $this->componentApplies($component, $type) || ! $this->componentMatchesExchange($component, $exchange)) continue;
                if (($component['aggregation'] ?? null) === 'isin_day_sell') continue;
                // Fixed and explicitly grouped fees are handled by the dedicated DP path.
                if (($component['mode'] ?? '') !== self::MODE_PERCENTAGE) {
                    $one = $this->calculate($quantity, $price, $type, $exchange, [$component], $date);
                    $results[$index]['breakdown'] = [...$results[$index]['breakdown'], ...$one['breakdown']];
                    $results[$index]['total'] += $one['total'];
                    continue;
                }
                $componentId = (string) ($component['id'] ?? '');
                $aggregationSide = $componentId === 'stt' ? 'delivery' : ($componentId === 'sebi' ? 'all' : $type);
                $aggregationExchange = in_array($componentId, ['stt', 'stamp', 'sebi'], true) ? 'all' : $exchange;
                $key = implode('|', [$date, $aggregationSide, $aggregationExchange, $componentId]);
                $groups[$key]['component'] = $component;
                $groups[$key]['date'] = $date;
                $groups[$key]['indices'][] = $index;
                $groups[$key]['turnovers'][] = $turnover;
            }
        }

        foreach ($groups as $group) {
            $component = $group['component'];
            $sumTurnover = array_sum($group['turnovers']);
            $baseRaw = $sumTurnover * ($this->rateForDate($component, $group['date']) / 100);
            // STT and stamp duty appear in notes as whole-rupee amounts; other
            // levies are invoice-cent amounts.
            $baseInvoice = in_array($component['id'] ?? '', ['stt', 'stamp'], true)
                ? (float) floor($baseRaw + 0.5) : $this->roundMoney($baseRaw);
            $gstInvoice = $this->roundMoney($baseInvoice * ((float) ($component['gst_percent'] ?? 0) / 100));
            $baseParts = $this->allocateCents($baseInvoice, $group['turnovers']);
            $gstParts = $this->allocateCents($gstInvoice, $group['turnovers']);
            foreach ($group['indices'] as $position => $index) {
                $base = $baseParts[$position];
                $gst = $gstParts[$position];
                $line = ['id' => $component['id'], 'label' => $component['label'], 'base' => $base, 'gst' => $gst, 'total' => $this->roundMoney($base + $gst)];
                $results[$index]['breakdown'][] = $line;
                $results[$index]['total'] += $line['total'];
            }
        }

        foreach ($results as &$result) $result['total'] = $this->roundMoney($result['total']);
        unset($result);
        return $results;
    }

    private function rateForDate(array $component, ?string $tradeDate): float
    {
        if (($component['id'] ?? null) === 'txn_nse' && $tradeDate !== null && (string) ($component['value'] ?? '') === '0.00307') {
            // Zerodha's published client rate currently includes ₹2.97/lakh
            // NSE charges plus ₹0.10/lakh IPFT. Before Oct 2024 its rate used
            // monthly slabs; ₹3.22/lakh is the published base slab, and exact
            // lower tiers require the dated broker invoice.
            return $tradeDate >= '2024-10-01' ? 0.00307 : 0.00322;
        }
        return (float) ($component['value'] ?? 0);
    }

    /** Largest-remainder cent allocation, stable in input order for ties. */
    private function allocateCents(float $amount, array $weights): array
    {
        $cents = (int) round($amount * 100);
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0 || $weights === []) return array_fill(0, count($weights), 0.0);
        $exact = array_map(fn ($weight) => $cents * $weight / $weightTotal, $weights);
        $allocated = array_map('intval', array_map('floor', $exact));
        $remainders = array_map(fn ($value, $index) => ['index' => $index, 'value' => $value - floor($value)], $exact, array_keys($exact));
        usort($remainders, fn ($a, $b) => $b['value'] <=> $a['value'] ?: $a['index'] <=> $b['index']);
        for ($left = $cents - array_sum($allocated), $i = 0; $left > 0; $left--, $i++) $allocated[$remainders[$i % count($remainders)]['index']]++;
        return array_map(fn ($value) => $value / 100, $allocated);
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
