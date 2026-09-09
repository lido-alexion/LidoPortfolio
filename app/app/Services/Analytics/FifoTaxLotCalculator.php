<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;

final class FifoTaxLotCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $transactions
     * @param  array<int, array<string, mixed>>  $openingLots
     * @return array<string, mixed>
     */
    public function calculate(array $transactions, array $openingLots = [], int $longTermDays = 365): array
    {
        $lots = [];
        $realized = [];
        $limitations = [];

        foreach ($openingLots as $lot) {
            $this->appendLot($lots, $lot, 'opening_tax_lot');
        }

        usort($transactions, fn (array $a, array $b): int => [
            (string) $a['date'], (int) ($a['id'] ?? 0),
        ] <=> [
            (string) $b['date'], (int) ($b['id'] ?? 0),
        ]);

        foreach ($transactions as $transaction) {
            if (($transaction['corporate_action_supported'] ?? true) !== true) {
                $limitations[] = 'unsupported_corporate_action:'.($transaction['id'] ?? 'unknown');
                continue;
            }

            $type = strtolower((string) ($transaction['type'] ?? ''));
            if ($type === 'transfer_in') {
                if (! isset($transaction['acquired_on'], $transaction['cost_basis'])) {
                    $limitations[] = 'missing_transfer_lineage:'.($transaction['id'] ?? 'unknown');
                    continue;
                }
                $this->appendLot($lots, $transaction, 'in_kind_transfer');
                continue;
            }

            if ($type === 'transfer_out') {
                $this->consume($lots, (float) $transaction['quantity'], null, $transaction, $realized, $limitations, $longTermDays);
                continue;
            }

            if ($type === 'buy') {
                $this->appendLot($lots, [
                    ...$transaction,
                    'acquired_on' => $transaction['date'],
                    'cost_basis' => ((float) $transaction['quantity'] * (float) $transaction['price'])
                        + (float) ($transaction['fees'] ?? 0.0),
                ], 'transaction');
                continue;
            }

            if ($type === 'sell') {
                $proceeds = ((float) $transaction['quantity'] * (float) $transaction['price'])
                    - (float) ($transaction['fees'] ?? 0.0);
                $this->consume($lots, (float) $transaction['quantity'], $proceeds, $transaction, $realized, $limitations, $longTermDays);
            }
        }

        return [
            'method' => 'fifo',
            'realized_disposals' => $realized,
            'open_lots' => array_values(array_filter($lots, fn (array $lot): bool => $lot['remaining_quantity'] > 0.0)),
            'realized_gain' => round(array_sum(array_column($realized, 'gain')), 4),
            'completeness' => $limitations === [] ? 'complete' : 'incomplete',
            'limitations' => array_values(array_unique($limitations)),
        ];
    }

    /** @param array<int, array<string, mixed>> $lots */
    private function appendLot(array &$lots, array $input, string $origin): void
    {
        $quantity = (float) $input['quantity'];
        $costBasis = (float) $input['cost_basis'];
        if ($quantity <= 0.0) {
            return;
        }

        $lots[] = [
            'source_id' => $input['id'] ?? null,
            'origin' => $origin,
            'acquired_on' => (string) $input['acquired_on'],
            'original_quantity' => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost' => $costBasis / $quantity,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $lots
     * @param array<int, array<string, mixed>> $realized
     * @param array<int, string> $limitations
     */
    private function consume(array &$lots, float $quantity, ?float $totalProceeds, array $transaction, array &$realized, array &$limitations, int $longTermDays): void
    {
        $remaining = $quantity;
        $unitProceeds = $totalProceeds === null || $quantity <= 0.0 ? null : $totalProceeds / $quantity;

        foreach ($lots as &$lot) {
            if ($remaining <= 0.0000001) {
                break;
            }
            if ($lot['remaining_quantity'] <= 0.0) {
                continue;
            }

            $matched = min($remaining, $lot['remaining_quantity']);
            $lot['remaining_quantity'] -= $matched;
            $remaining -= $matched;

            // Transfer-out consumes FIFO lineage without creating a disposal.
            if ($unitProceeds === null) {
                continue;
            }

            $cost = $matched * $lot['unit_cost'];
            $proceeds = $matched * $unitProceeds;
            $heldDays = CarbonImmutable::parse($lot['acquired_on'])
                ->diffInDays(CarbonImmutable::parse((string) $transaction['date']));
            $realized[] = [
                'disposal_id' => $transaction['id'] ?? null,
                'lot_source_id' => $lot['source_id'],
                'acquired_on' => $lot['acquired_on'],
                'disposed_on' => (string) $transaction['date'],
                'quantity' => round($matched, 4),
                'cost_basis' => round($cost, 4),
                'proceeds' => round($proceeds, 4),
                'gain' => round($proceeds - $cost, 4),
                'term' => $heldDays > $longTermDays ? 'long_term' : 'short_term',
                'holding_days' => $heldDays,
            ];
        }
        unset($lot);

        if ($remaining > 0.0000001) {
            $limitations[] = 'missing_acquisition_lineage:'.($transaction['id'] ?? 'unknown');
        }
    }
}
