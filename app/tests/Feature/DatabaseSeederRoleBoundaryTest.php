<?php

namespace Tests\Feature;

use App\Models\TradingStrategy;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_keeps_admin_portfolioless_and_seeds_investor_runtime(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('email', 'admin@lidoportfolio.local')->sole();
        $investor = User::query()->where('email', 'investor@lidoportfolio.local')->sole();

        $this->assertTrue($admin->is_admin);
        $this->assertSame(0, $admin->portfolios()->count());
        $this->assertFalse($investor->is_admin);
        $profile = $investor->portfolios()->sole();
        $this->assertTrue($profile->is_default);
        $this->assertTrue(TradingStrategy::query()
            ->where('profile_id', $profile->id)
            ->where('is_factory', true)
            ->exists());
    }
}
