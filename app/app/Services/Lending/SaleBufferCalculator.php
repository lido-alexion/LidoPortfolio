<?php

namespace App\Services\Lending;

/**
 * Platform-wide 0.5% sale buffer (V3 §17.1) — not portfolio-configurable.
 *
 * required_settlement_amount = cash still needed for the obligation
 * sale_buffer_amount         = required * 0.5%
 * target_liquidation_value   = required + buffer (gross value to sell)
 * expected_proceeds          = planned fill notional (≤ market value sold)
 * actual_proceeds            = broker proceeds actually realized
 */
final class SaleBufferCalculator
{
    public const BUFFER_RATIO = 0.005;

    /**
     * @return array{
     *   required_settlement_amount: float,
     *   sale_buffer_ratio: float,
     *   sale_buffer_amount: float,
     *   target_liquidation_value: float
     * }
     */
    public function size(float $requiredSettlementAmount): array
    {
        $required = round(max(0.0, $requiredSettlementAmount), 4);
        $buffer = round($required * self::BUFFER_RATIO, 4);
        $target = round($required + $buffer, 4);

        return [
            'required_settlement_amount' => $required,
            'sale_buffer_ratio' => self::BUFFER_RATIO,
            'sale_buffer_amount' => $buffer,
            'target_liquidation_value' => $target,
        ];
    }
}
