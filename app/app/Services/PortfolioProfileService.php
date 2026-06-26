<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PortfolioProfileService
{
    public function createDefaultForUser(User $user, string $name = 'Default'): PortfolioProfile
    {
        $hasDefault = PortfolioProfile::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->exists();

        return PortfolioProfile::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_default' => ! $hasDefault,
        ]);
    }

    public function defaultForUser(User $user): ?PortfolioProfile
    {
        return PortfolioProfile::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->first()
            ?? PortfolioProfile::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->first();
    }

    /**
     * @return Collection<int, PortfolioProfile>
     */
    public function listForUser(User $user): Collection
    {
        return PortfolioProfile::query()
            ->where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function setDefault(User $user, PortfolioProfile $profile): PortfolioProfile
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'portfolio' => ['Portfolio not found.'],
            ]);
        }

        PortfolioProfile::query()
            ->where('user_id', $user->id)
            ->update(['is_default' => false]);

        $profile->update(['is_default' => true]);

        return $profile->fresh();
    }
}
