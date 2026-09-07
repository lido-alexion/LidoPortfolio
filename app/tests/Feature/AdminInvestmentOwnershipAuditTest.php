<?php

namespace Tests\Feature;

use App\Models\PortfolioProfile;
use App\Models\User;
use App\Services\AdminInvestmentOwnershipAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminInvestmentOwnershipAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_database_is_safe_to_enforce(): void
    {
        User::query()->create([
            'name' => 'Investor',
            'email' => 'investor@example.com',
            'password' => Hash::make('password123'),
        ]);

        $result = app(AdminInvestmentOwnershipAuditService::class)->audit();

        $this->assertTrue($result['safe_to_enforce']);
        $this->assertSame(0, $result['conflicting_admin_accounts']);
        $this->assertSame(0, Artisan::call('portfolio:audit-admin-investment-ownership', ['--json' => true]));
    }

    public function test_reports_admin_profiles_related_records_and_direct_broker_state_without_mutation(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->forceFill(['is_admin' => true])->save();
        $profile = PortfolioProfile::query()->create([
            'user_id' => $admin->id,
            'name' => 'Legacy Admin Portfolio',
            'is_default' => true,
        ]);
        DB::table('portfolio_profile_settings')->insert([
            'profile_id' => $profile->id,
            'setting_key' => 'audit-fixture',
            'setting_value' => 'present',
            'updated_at' => now(),
        ]);
        DB::table('portfolio_broker_connections')->insert([
            'user_id' => $admin->id,
            'provider' => 'kite',
            'access_token' => 'encrypted-fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(AdminInvestmentOwnershipAuditService::class)->audit();

        $this->assertFalse($result['safe_to_enforce']);
        $this->assertSame(1, $result['conflicting_admin_accounts']);
        $this->assertSame($profile->id, $result['conflicts'][0]['profiles'][0]['id']);
        $this->assertSame(1, $result['conflicts'][0]['profile_record_counts']['portfolio_profile_settings']);
        $this->assertSame(1, $result['conflicts'][0]['direct_record_counts']['portfolio_broker_connections']);
        $this->assertSame(1, Artisan::call('portfolio:audit-admin-investment-ownership', ['--json' => true]));
        $this->assertDatabaseHas('portfolio_profiles', ['id' => $profile->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('portfolio_broker_connections', ['user_id' => $admin->id]);
    }
}
