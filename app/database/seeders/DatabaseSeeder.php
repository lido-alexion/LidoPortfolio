<?php

namespace Database\Seeders;

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
            \App\Models\Setting::setValue($key, $value);
        }

        app(RelativeStrengthService::class)->benchmarkStock();

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@lidoportfolio.local'],
            [
                'name' => 'Portfolio Admin',
                'password' => Hash::make('password123'),
            ],
        );

        User::query()
            ->where('email', 'admin@lidoportfolio.local')
            ->update(['is_admin' => true]);

        if ($admin->portfolios()->doesntExist()) {
            app(PortfolioProfileService::class)->createDefaultForUser($admin);
        }

        $this->call(FactoryMomentumStrategySeeder::class);
    }
}
