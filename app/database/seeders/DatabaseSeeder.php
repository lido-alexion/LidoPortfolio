<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use App\Services\PortfolioProfileService;
use App\Services\RelativeStrengthService;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(SettingsService::class);
        foreach (SettingsService::DEFAULTS as $key => $value) {
            Setting::setValue($key, $value);
        }

        app(RelativeStrengthService::class)->benchmarkStock();

        User::query()->updateOrCreate(
            ['email' => 'admin@lidoportfolio.local'],
            [
                'name' => 'Portfolio Admin',
                'password' => Hash::make('password123'),
                'is_admin' => true,
            ],
        );

        $investor = User::query()->updateOrCreate(
            ['email' => 'investor@lidoportfolio.local'],
            [
                'name' => 'Portfolio Investor',
                'password' => Hash::make('password123'),
                'is_admin' => false,
            ],
        );

        if ($investor->portfolios()->doesntExist()) {
            app(PortfolioProfileService::class)->createDefaultForUser($investor);
        }

        $this->call(FactoryMomentumStrategySeeder::class);
    }
}
