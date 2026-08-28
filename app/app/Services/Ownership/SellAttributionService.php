<?php

namespace App\Services\Ownership;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * V4-SPEC-005 — explicit cross-owner SELL attribution.
 *
 * Unambiguous sells (one open owner, or the transaction already identifies the owner)
 * are attributed. Ambiguous sells are rejected — never proportional, FIFO, largest, or oldest.
 */
final class SellAttributionService
{
    public const MSG_ATTRIBUTION_REQUIRED = 'This sell could affect more than one owner. Specify the Strategy or unmanaged lot.';

    public const MSG_OWNER_INVALID = 'Owner must be unmanaged or strategy:{id}.';

    public const MSG_OWNER_NOT_IN_PORTFOLIO = 'Strategy not found in this portfolio.';

    public const MSG_OWNER_DOES_NOT_HOLD = 'The selected owner does not hold this stock in this portfolio.';

    public const MSG_INSUFFICIENT_OWNER_QTY = 'Sell quantity cannot exceed the selected owner\'s holding quantity.';

    public const MSG_OWNER_MISMATCH = 'The selected owner does not match the recommendation\'s Strategy.';

    public const MSG_EXCEED_HOLDING = 'Sell quantity cannot exceed current holding quantity.';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, float>|null  $openOverride  Virtual open lots (bulk preflight).
     * @return array{owner_key: string, strategy_id: ?int}
     */
    public function resolveForSell(
        PortfolioProfile $profile,
        Stock $stock,
        array $input,
        float $quantity,
        ?Transaction $existing = null,
        ?array $openOverride = null,
    ): array {
        $open = $openOverride ?? $this->openOwnerQuantities($profile, $stock, $existing);
        $totalOpen = array_sum($open);

        if ($quantity > $totalOpen + 0.00001) {
            throw ValidationException::withMessages([
                'quantity' => [self::MSG_EXCEED_HOLDING],
            ]);
        }

        $explicit = $this->explicitOwnerKey($profile, $input);
        $fromRec = $this->ownerKeyFromRecommendation($profile, $input, $existing);

        if ($explicit !== null && $fromRec !== null && $explicit !== $fromRec) {
            throw ValidationException::withMessages([
                'owner_key' => [self::MSG_OWNER_MISMATCH],
            ]);
        }

        $identified = $explicit ?? $fromRec;

        if ($identified === null && $existing !== null && Holding::isValidOwnerKey($existing->owner_key)) {
            $identified = (string) $existing->owner_key;
        }

        if ($identified === null) {
            $eligible = array_keys(array_filter($open, fn (float $qty) => $qty > 0.00001));
            if (count($eligible) === 1) {
                $identified = $eligible[0];
            } elseif (count($eligible) === 0) {
                throw ValidationException::withMessages([
                    'quantity' => [self::MSG_EXCEED_HOLDING],
                ]);
            } else {
                throw ValidationException::withMessages([
                    'owner_key' => [self::MSG_ATTRIBUTION_REQUIRED],
                ]);
            }
        }

        $available = $open[$identified] ?? 0.0;
        if ($available <= 0.00001) {
            throw ValidationException::withMessages([
                'owner_key' => [self::MSG_OWNER_DOES_NOT_HOLD],
            ]);
        }

        if ($quantity > $available + 0.00001) {
            throw ValidationException::withMessages([
                'quantity' => [self::MSG_INSUFFICIENT_OWNER_QTY],
            ]);
        }

        return [
            'owner_key' => $identified,
            'strategy_id' => Holding::strategyIdFromOwnerKey($identified),
        ];
    }

    /**
     * BUY owner is never guessed across strategies: recommendation → that Strategy, else unmanaged.
     *
     * @param  array<string, mixed>  $input
     */
    public function ownerKeyForBuy(PortfolioProfile $profile, array $input): string
    {
        $fromRec = $this->ownerKeyFromRecommendation($profile, $input, null);

        return $fromRec ?? Holding::OWNER_UNMANAGED;
    }

    /**
     * Current open lots, with an in-flight sell's quantity restored to its owner.
     *
     * @return array<string, float>
     */
    public function openOwnerQuantities(
        PortfolioProfile $profile,
        Stock $stock,
        ?Transaction $existing = null,
    ): array {
        $rows = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->get(['owner_key', 'quantity']);

        $open = [];
        foreach ($rows as $row) {
            $key = (string) ($row->owner_key ?: Holding::OWNER_UNMANAGED);
            $open[$key] = ($open[$key] ?? 0.0) + (float) $row->quantity;
        }

        if (
            $existing !== null
            && strtolower((string) $existing->type) === 'sell'
            && (int) $existing->stock_id === (int) $stock->id
            && Holding::isValidOwnerKey($existing->owner_key)
        ) {
            $key = (string) $existing->owner_key;
            $open[$key] = ($open[$key] ?? 0.0) + (float) $existing->quantity;
        }

        return $open;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function explicitOwnerKey(PortfolioProfile $profile, array $input): ?string
    {
        $rawKey = isset($input['owner_key']) ? trim((string) $input['owner_key']) : '';
        $strategyId = $this->optionalPositiveInt($input['strategy_id'] ?? null);

        if ($rawKey === '' && $strategyId === null) {
            return null;
        }

        if ($rawKey !== '') {
            if (! Holding::isValidOwnerKey($rawKey)) {
                throw ValidationException::withMessages([
                    'owner_key' => [self::MSG_OWNER_INVALID],
                ]);
            }
            $fromKey = Holding::strategyIdFromOwnerKey($rawKey);
            if ($strategyId !== null) {
                $expected = Holding::ownerKeyFor($strategyId);
                if ($rawKey !== $expected) {
                    throw ValidationException::withMessages([
                        'owner_key' => [self::MSG_OWNER_MISMATCH],
                    ]);
                }
            }
            if ($fromKey !== null) {
                $this->assertStrategyInProfile($profile, $fromKey);
            }

            return $rawKey;
        }

        $this->assertStrategyInProfile($profile, $strategyId);

        return Holding::ownerKeyFor($strategyId);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function ownerKeyFromRecommendation(
        PortfolioProfile $profile,
        array $input,
        ?Transaction $existing,
    ): ?string {
        $recId = $this->optionalPositiveInt($input['recommendation_id'] ?? null);
        if ($recId === null && $existing?->recommendation_id) {
            $recId = (int) $existing->recommendation_id;
        }
        if ($recId === null) {
            return null;
        }

        $rec = TradingRecommendation::query()
            ->where('profile_id', $profile->id)
            ->where('id', $recId)
            ->first();
        if ($rec === null) {
            return null;
        }

        $strategyId = $rec->owningStrategyId();
        if ($strategyId === null || $strategyId <= 0) {
            return Holding::OWNER_UNMANAGED;
        }

        $this->assertStrategyInProfile($profile, $strategyId);

        return Holding::ownerKeyFor($strategyId);
    }

    protected function assertStrategyInProfile(PortfolioProfile $profile, int $strategyId): void
    {
        $exists = TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('id', $strategyId)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'strategy_id' => [self::MSG_OWNER_NOT_IN_PORTFOLIO],
            ]);
        }
    }

    protected function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
