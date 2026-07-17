<?php

namespace Tests\Concerns;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\PortfolioProfileService;
use App\Services\WatchlistService;

trait CreatesPortfolioProfiles
{
    protected function createPortfolioProfile(User $user, string $name = 'Default', bool $isDefault = true): PortfolioProfile
    {
        $existing = app(PortfolioProfileService::class)->defaultForUser($user);
        if ($existing !== null && $isDefault) {
            return $existing;
        }

        $profile = PortfolioProfile::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_default' => $isDefault && $existing === null,
        ]);

        app(WatchlistService::class)->ensureDefaultWatchlist($profile);

        return $profile;
    }

    protected function defaultPortfolioFor(User $user): PortfolioProfile
    {
        $profile = app(PortfolioProfileService::class)->defaultForUser($user);

        return $profile ?? $this->createPortfolioProfile($user);
    }

    protected function withProfileHeader(User $user, ?PortfolioProfile $profile = null): self
    {
        $profile ??= $this->defaultPortfolioFor($user);

        return $this->withHeader('X-Profile-Id', (string) $profile->id);
    }
}
