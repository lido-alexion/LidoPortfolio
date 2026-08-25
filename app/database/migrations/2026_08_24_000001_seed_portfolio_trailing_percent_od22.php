<?php

use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * V3 Phase 1 — OD-22: seed portfolio-level trailing percentage to 15%.
 *
 * Does not copy default_stoploss_percent.
 * Does not read strategy trailing/stop JSON.
 * Idempotent: only inserts the key when missing for a profile.
 */
return new class extends Migration
{
    public const KEY = 'portfolio_trailing_percent';

    public const SEED_VALUE = '15';

    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_profiles')) {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_profile_settings')) {
            return;
        }

        PortfolioProfile::query()->orderBy('id')->pluck('id')->each(function ($profileId) {
            $exists = ProfileSetting::query()
                ->where('profile_id', $profileId)
                ->where('setting_key', self::KEY)
                ->exists();

            if ($exists) {
                return;
            }

            ProfileSetting::setValue((int) $profileId, self::KEY, self::SEED_VALUE);
        });
    }

    public function down(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('portfolio_profile_settings')) {
            return;
        }

        ProfileSetting::query()
            ->where('setting_key', self::KEY)
            ->where('setting_value', self::SEED_VALUE)
            ->delete();
    }
};
