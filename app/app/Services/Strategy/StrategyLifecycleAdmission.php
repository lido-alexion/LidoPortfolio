<?php

namespace App\Services\Strategy;

use App\Models\TradingStrategy;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Serializes creation of strategy-owned obligations with retirement.
 * Call from inside the writer transaction before locking any child record.
 */
final class StrategyLifecycleAdmission
{
    /**
     * @param list<int|null> $strategyIds
     * @return Collection<int, TradingStrategy>
     */
    public function lockForNewObligation(int $profileId, array $strategyIds): Collection
    {
        return $this->lockOwners(array_map(
            fn ($strategyId): array => ['profile_id' => $profileId, 'strategy_id' => (int) $strategyId],
            $strategyIds,
        ));
    }

    /**
     * Lock owners from one or more profiles in global strategy-ID order.
     *
     * @param list<array{profile_id:int,strategy_id:int}> $owners
     * @return Collection<int, TradingStrategy>
     */
    public function lockOwners(array $owners): Collection
    {
        $byId = [];
        foreach ($owners as $owner) {
            $id = (int) ($owner['strategy_id'] ?? 0);
            $profileId = (int) ($owner['profile_id'] ?? 0);
            if ($id > 0 && $profileId > 0) {
                $byId[$id] = $profileId;
            }
        }
        ksort($byId, SORT_NUMERIC);

        $locked = collect();
        foreach ($byId as $id => $profileId) {
            $strategy = TradingStrategy::query()->whereKey($id)->where('profile_id', $profileId)
                ->lockForUpdate()->first();
            if (! $strategy) {
                throw ValidationException::withMessages([
                    'strategy_id' => ['The strategy owner no longer belongs to this portfolio.'],
                ]);
            }
            if ($strategy->isReadOnly()) {
                throw ValidationException::withMessages([
                    'strategy_id' => ['Archived strategies cannot accept new owned obligations. Restore the strategy before creating a new obligation.'],
                ]);
            }
            $locked->push($strategy);
        }

        return $locked;
    }
}
