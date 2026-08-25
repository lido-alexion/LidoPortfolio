<?php

namespace App\Services\Entry;

/**
 * OD-12 / §12.3 — whole-share quantity from amount ÷ latest raw close (FLOOR).
 */
final class WholeShareQuantityCalculator
{
    /**
     * @return array{quantity: int, notional: float, residual: float}
     */
    public function fromAmount(float $intendedAmount, float $latestRawClose): array
    {
        if ($intendedAmount <= 0 || $latestRawClose <= 0) {
            return ['quantity' => 0, 'notional' => 0.0, 'residual' => max(0.0, $intendedAmount)];
        }

        $qty = (int) floor($intendedAmount / $latestRawClose);
        if ($qty < 1) {
            return ['quantity' => 0, 'notional' => 0.0, 'residual' => round($intendedAmount, 4)];
        }

        $notional = round($qty * $latestRawClose, 4);
        $residual = round(max(0.0, $intendedAmount - $notional), 4);

        return [
            'quantity' => $qty,
            'notional' => $notional,
            'residual' => $residual,
        ];
    }
}
