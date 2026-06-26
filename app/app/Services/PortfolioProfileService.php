<?php

namespace App\Services;

use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function deleteForUser(User $user, PortfolioProfile $profile, ?int $activeProfileId = null): void
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'portfolio' => ['Portfolio not found.'],
            ]);
        }

        $count = PortfolioProfile::query()->where('user_id', $user->id)->count();

        if ($count <= 1) {
            throw ValidationException::withMessages([
                'portfolio' => ['Cannot delete your only portfolio.'],
            ]);
        }

        if ($profile->is_default) {
            throw ValidationException::withMessages([
                'portfolio' => ['Cannot delete your default portfolio. Set another portfolio as default first.'],
            ]);
        }

        if ($activeProfileId !== null && (int) $activeProfileId === (int) $profile->id) {
            throw ValidationException::withMessages([
                'portfolio' => ['Cannot delete the portfolio that is active in this tab. Switch to another portfolio first.'],
            ]);
        }

        DB::transaction(function () use ($profile) {
            $profileId = $profile->id;

            ProfileSetting::query()
                ->where('profile_id', $profileId)
                ->pluck('setting_key')
                ->each(fn (string $key) => Cache::forget("profile_setting.{$profileId}.{$key}"));

            $profile->transactions()->delete();
            $profile->holdings()->delete();
            $profile->portfolioSnapshots()->delete();
            $profile->alerts()->delete();
            $profile->settings()->delete();

            $profile->delete();
        });
    }
}
