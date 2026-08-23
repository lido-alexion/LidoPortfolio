<?php

namespace App\Services\Lending;

use InvalidArgumentException;

/**
 * DEP-RECALL-SIZE — automated lender emits only valid recall amounts.
 */
final class RecallAmountCalculator
{
    public const ATOMIC_BLOCK = 5000.0;

    public const KIND_FULL = 'full';

    public const KIND_PARTIAL = 'partial';

    /**
     * @return array{kind: string, amount: float}
     */
    public function full(float $outstanding): array
    {
        $outstanding = round($outstanding, 4);
        if ($outstanding <= 0) {
            throw new InvalidArgumentException('FULL recall requires positive outstanding.');
        }

        return [
            'kind' => self::KIND_FULL,
            'amount' => $outstanding,
        ];
    }

    /**
     * Partial recall must be a positive multiple of ₹5,000 and ≤ outstanding.
     *
     * @return array{kind: string, amount: float}
     */
    public function partial(float $amount, float $outstanding): array
    {
        $amount = round($amount, 4);
        $outstanding = round($outstanding, 4);
        if ($amount <= 0) {
            throw new InvalidArgumentException('PARTIAL recall amount must be greater than 0.');
        }
        if ($outstanding <= 0) {
            throw new InvalidArgumentException('PARTIAL recall requires positive outstanding.');
        }
        if ($amount > $outstanding + 0.0001) {
            throw new InvalidArgumentException('Recall amount cannot exceed outstanding.');
        }
        if (! $this->isExactAtomicMultiple($amount)) {
            throw new InvalidArgumentException('PARTIAL recall must be in ₹5,000 multiples.');
        }

        return [
            'kind' => self::KIND_PARTIAL,
            'amount' => $amount,
        ];
    }

    /**
     * Size a recall for a capital shortfall: take min(need, outstanding).
     * If that equals outstanding → FULL (any amount). Else → largest ₹5k partial ≤ need.
     *
     * @return array{kind: string, amount: float}|null null if nothing valid can be recalled
     */
    public function forShortfall(float $shortfall, float $outstanding): ?array
    {
        $shortfall = round($shortfall, 4);
        $outstanding = round($outstanding, 4);
        if ($shortfall <= 0 || $outstanding <= 0) {
            return null;
        }

        if ($shortfall + 0.0001 >= $outstanding) {
            return $this->full($outstanding);
        }

        $partial = floor($shortfall / self::ATOMIC_BLOCK) * self::ATOMIC_BLOCK;
        if ($partial < self::ATOMIC_BLOCK) {
            // Need less than one block but not full loan — still take full if outstanding < block?
            // Spec: partial must be 5k multiples. If shortfall < 5k and outstanding > shortfall,
            // automated lender cannot emit invalid partial. Prefer FULL only when intending whole loan.
            // For capital resolution shortfall < 5k with larger outstanding: take nothing from this
            // loan via partial, OR take full if shortfall is close? Spec capital priority says
            // recall own capital. If need is ₹4,000 and outstanding ₹12,000, partial of ₹0 is
            // invalid. Taking FULL would over-recall. Correct: no valid partial → skip loan
            // unless we FULL-recall. Over-recalling contradicts "do not recall entire merely
            // because oldest". So return null for shortfall < 5000 when outstanding > shortfall.
            return null;
        }

        return $this->partial(min($partial, $outstanding), $outstanding);
    }

    public function isExactAtomicMultiple(float $amount): bool
    {
        $amount = round($amount, 4);
        if ($amount <= 0) {
            return false;
        }
        $units = $amount / self::ATOMIC_BLOCK;

        return abs($units - round($units)) < 0.0000001;
    }
}
