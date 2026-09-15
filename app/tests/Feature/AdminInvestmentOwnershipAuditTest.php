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

    public function test_removes_disposable_admin_default_portfolio_graph(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-default@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->forceFill(['is_admin' => true])->save();
        $profile = PortfolioProfile::query()->create([
            'user_id' => $admin->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        DB::table('portfolio_cash_accounts')->insert([
            'profile_id' => $profile->id,
            'balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('portfolio_watchlists')->insert([
            'profile_id' => $profile->id,
            'name' => 'My Watchlist',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $screenerId = DB::table('portfolio_screeners')->insertGetId([
            'profile_id' => $profile->id,
            'name' => 'Minervini Trend Template',
            'slug' => 'minervini_trend_template',
            'definition_json' => json_encode(['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]]),
            'artifact_status' => 'active',
            'scope' => 'all_equities',
            'is_enabled' => true,
            'is_shared' => false,
            'is_factory' => true,
            'factory_key' => 'minervini_trend_template',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('portfolio_screener_versions')->insert([
            'screener_id' => $screenerId,
            'version' => 1,
            'definition_json' => json_encode(['root' => ['type' => 'group', 'op' => 'AND', 'children' => []]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $strategyId = DB::table('portfolio_tos_strategies')->insertGetId([
            'profile_id' => $profile->id,
            'name' => 'Minervini Strategy',
            'slug' => 'momentum_strategy',
            'status' => 'active',
            'is_factory' => true,
            'factory_key' => 'momentum_factory',
            'allocation_pct' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $strategyVersionId = DB::table('portfolio_tos_strategy_versions')->insertGetId([
            'strategy_id' => $strategyId,
            'version' => 1,
            'config_json' => json_encode(['eligibility_sources' => []]),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('portfolio_tos_strategy_screeners')->insert([
            'strategy_version_id' => $strategyVersionId,
            'screener_id' => $screenerId,
            'enabled' => true,
            'priority' => 1,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = DB::table('portfolio_tos_review_reports')->insertGetId([
            'profile_id' => $profile->id,
            'period_start' => now()->subDay()->toDateString(),
            'period_end' => now()->toDateString(),
            'status' => 'completed',
            'summary_json' => json_encode(['portfolio_value' => 0]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('portfolio_tos_review_metrics')->insert([
            'report_id' => $reportId,
            'metric_name' => 'portfolio_value',
            'metric_value' => 0,
            'created_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('portfolio:remove-invalid-admin-portfolio', [
            'profile_id' => $profile->id,
            '--dry-run' => true,
            '--json' => true,
        ]));
        $this->assertDatabaseHas('portfolio_profiles', ['id' => $profile->id]);

        $this->assertSame(0, Artisan::call('portfolio:remove-invalid-admin-portfolio', [
            'profile_id' => $profile->id,
            '--json' => true,
        ]));

        $this->assertDatabaseMissing('portfolio_profiles', ['id' => $profile->id]);
        $this->assertSame(0, DB::table('portfolio_tos_review_metrics')->where('report_id', $reportId)->count());
        $this->assertTrue(app(AdminInvestmentOwnershipAuditService::class)->audit()['safe_to_enforce']);
    }

    public function test_refuses_admin_portfolio_with_economic_activity(): void
    {
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-economic@example.com',
            'password' => Hash::make('password123'),
        ]);
        $admin->forceFill(['is_admin' => true])->save();
        $profile = PortfolioProfile::query()->create([
            'user_id' => $admin->id,
            'name' => 'Default',
            'is_default' => true,
        ]);
        DB::table('portfolio_cash_accounts')->insert([
            'profile_id' => $profile->id,
            'balance' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('portfolio:remove-invalid-admin-portfolio', [
            'profile_id' => $profile->id,
            '--json' => true,
        ]));

        $this->assertDatabaseHas('portfolio_profiles', ['id' => $profile->id]);
    }
}
