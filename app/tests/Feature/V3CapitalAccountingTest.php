<?php

namespace Tests\Feature;

use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Workstream 2 — cash / strategy capital / OD-19 / OD-20 / OD-21 / OD-24.
 */
class V3CapitalAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_od24_seven_lakh_fifty_thousand_over_five(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(750_000);
        $this->setRecommendedMinimumHoldings($strategy, 5);

        $data = $this->capitalJson($user, $profile);
        $row = $data['strategies'][0];

        $this->assertEqualsWithDelta(750000.0, $row['strategy_capital_allocation'], 0.0001);
        $this->assertSame(150000, $row['minimum_retained_capital']);
        $this->assertFalse($row['minimum_retained_capital_is_physical_cash']);
        $this->assertSame(1, $data['physical_cash']['cash_account_count']);
        $this->assertSame(0, $data['physical_cash']['strategy_physical_cash_accounts']);
        $this->assertFalse(Schema::hasColumn('portfolio_cash_accounts', 'strategy_id'));
        $this->assertSame(
            0,
            CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->whereNotIn('entry_type', CashLedgerEntry::TYPES)
                ->count()
        );
        $this->assertSame(
            ['deposit'],
            CashLedgerEntry::query()->where('profile_id', $profile->id)->pluck('entry_type')->unique()->values()->all()
        );
    }

    public function test_od24_non_integer_seven_lakh_fifty_thousand_over_seven(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(750_000);
        $this->setRecommendedMinimumHoldings($strategy, 7);

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertSame(107143, $row['minimum_retained_capital']);
    }

    public function test_od24_half_rupee_rounds_upward(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(750_001);
        $this->setRecommendedMinimumHoldings($strategy, 2);

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(375000.5, $row['strategy_capital_allocation'] / 2, 0.0001);
        $this->assertSame(375001, $row['minimum_retained_capital']);
    }

    public function test_od24_unset_recommended_minimum_holdings_is_null(): void
    {
        [$user, $profile] = $this->cashOnlyPortfolio(750_000);

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertNull($row['recommended_minimum_holdings']);
        $this->assertNull($row['minimum_retained_capital']);
    }

    public function test_multi_strategy_shares_one_physical_cash_pool(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Strategy B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        app(CashManagementService::class)->deposit($profile, 1_000_000, 'seed', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 75],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertOk();

        $this->setRecommendedMinimumHoldings($first->fresh(), 5);
        $this->setRecommendedMinimumHoldings($second->fresh(), 5);

        $data = $this->capitalJson($user, $profile);
        $byId = collect($data['strategies'])->keyBy('strategy_id');

        $this->assertSame(1, CashAccount::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(1, $data['physical_cash']['cash_account_count']);
        $this->assertSame(0, $data['physical_cash']['strategy_physical_cash_accounts']);
        $this->assertEqualsWithDelta(1_000_000.0, $data['physical_cash']['total_cash'], 0.0001);
        $this->assertEqualsWithDelta(1_000_000.0, $data['physical_cash']['available_physical_cash'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $data['od19']['required_cash_reserve'], 0.0001);
        $this->assertTrue($data['allocation_pct_sum_is_100']);
        $this->assertEqualsWithDelta(750000.0, $byId[$first->id]['strategy_capital_allocation'], 0.0001);
        $this->assertEqualsWithDelta(250000.0, $byId[$second->id]['strategy_capital_allocation'], 0.0001);
        $this->assertSame(150000, $byId[$first->id]['minimum_retained_capital']);
        $this->assertSame(50000, $byId[$second->id]['minimum_retained_capital']);
        $this->assertEqualsWithDelta(0.0, $data['od20']['unallocated_cash'], 0.0001);
        $this->assertTrue($data['od20']['is_presentation_only']);
        $this->assertFalse($data['od20']['is_ledger_bucket']);
    }

    public function test_put_allocations_rejects_sum_not_100_without_normalizing(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Strategy B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 60],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertStatus(422);

        $this->assertEqualsWithDelta(100.0, (float) $first->fresh()->allocation_pct, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $second->fresh()->allocation_pct, 0.0001);

        $data = $this->capitalJson($user, $profile);
        $this->assertEqualsWithDelta(200.0, $data['allocation_pct_sum'], 0.0001);
        $this->assertFalse($data['allocation_pct_sum_is_100']);
    }

    public function test_score_band_allocation_pct_is_not_strategy_capital_policy(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(1_000_000);
        $version = $strategy->activeVersion;
        $config = is_array($version->config_json) ? $version->config_json : [];
        $config['capital_allocation']['score_bands'] = [
            ['min_score' => 90, 'max_score' => 100, 'allocation_pct' => 10],
        ];
        $version->forceFill(['config_json' => $config])->save();
        $strategy->forceFill(['allocation_pct' => 100])->save();

        $row = $this->capitalJson($user, $profile)['strategies'][0];
        $this->assertEqualsWithDelta(100.0, $row['allocation_pct'], 0.0001);
        $this->assertEqualsWithDelta(1_000_000.0, $row['strategy_capital_allocation'], 0.0001);
        $this->assertNotEquals(10.0, $row['allocation_pct']);
    }

    public function test_unmanaged_holdings_are_excluded_from_investable_capital_split(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(100_000);
        $stock = $this->makeStock();
        $this->price($stock, 50);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 50,
            'invested_amount' => 500,
            'owner_key' => Holding::OWNER_UNMANAGED,
            'updated_at' => now(),
        ]);

        $data = $this->capitalJson($user, $profile);
        $this->assertEqualsWithDelta(500.0, $data['unmanaged_market_value'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $data['strategy_owned_market_value'], 0.0001);
        $this->assertEqualsWithDelta(100_000.0, $data['investable_capital'], 0.0001);
        $this->assertEqualsWithDelta(100_000.0, $data['strategies'][0]['strategy_capital_allocation'], 0.0001);
        $this->assertSame((int) $strategy->id, $data['strategies'][0]['strategy_id']);
    }

    public function test_od19_reserve_uses_max_of_invested_and_notional_not_percent_of_cash(): void
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio(1_000_000);
        $stock = $this->makeStock();
        $this->price($stock, 200);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1_000,
            'updated_at' => now(),
        ]);

        app(ProfileSettingsService::class)->set($profile, 'portfolio_cash_reserve_pct', '20');

        $data = $this->capitalJson($user, $profile);
        $this->assertEqualsWithDelta(1000.0, $data['od19']['total_invested_amount'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, $data['od19']['current_notional_portfolio_value'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, $data['od19']['reserve_base'], 0.0001);
        $this->assertEqualsWithDelta(400.0, $data['od19']['required_cash_reserve'], 0.0001);
        $this->assertEqualsWithDelta(1_000_000.0, $data['physical_cash']['available_physical_cash'], 0.0001);
        $this->assertNotEquals(200_000.0, $data['od19']['required_cash_reserve']);
    }

    public function test_withdrawal_succeeds_when_cash_stays_above_reserve(): void
    {
        [$user, $profile] = $this->portfolioWithReserveBase($cash = 5_000, $invested = 10_000, $reservePct = 20);
        $required = 2_000;

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/withdraw', ['amount' => 1_000, 'reason' => 'case-a'])
            ->assertCreated();

        $cashSvc = app(CashManagementService::class);
        $this->assertEqualsWithDelta(4_000.0, $cashSvc->balance($profile), 0.0001);
        $this->assertGreaterThanOrEqual($required, $cashSvc->balance($profile));
        $this->assertFalse($this->capitalJson($user, $profile)['od19']['reserve_shortfall_exists']);
    }

    public function test_withdrawal_still_succeeds_when_cash_falls_below_reserve(): void
    {
        [$user, $profile] = $this->portfolioWithReserveBase(5_000, 10_000, 20);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/withdraw', ['amount' => 4_000, 'reason' => 'case-b'])
            ->assertCreated();

        $this->assertEqualsWithDelta(1_000.0, app(CashManagementService::class)->balance($profile), 0.0001);
        $data = $this->capitalJson($user, $profile);
        $this->assertTrue($data['od19']['reserve_shortfall_exists']);
        $this->assertEqualsWithDelta(1_000.0, $data['od19']['reserve_shortfall'], 0.0001);
        $this->assertFalse($data['od21']['withdrawals_blocked_by_reserve']);
    }

    public function test_dashboard_warns_on_reserve_shortfall(): void
    {
        [$user, $profile] = $this->portfolioWithReserveBase(5_000, 10_000, 20);
        app(CashManagementService::class)->withdraw($profile, 4_000, 'case-c', $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('reserve_shortfall_exists', true)
            ->assertJsonPath(
                'reserve_shortfall_warning',
                'Portfolio cash reserve is below the required level. Replenish portfolio/broker cash.'
            );
    }

    public function test_reserve_shortfall_does_not_terminate_recommendations(): void
    {
        [$user, $profile, $strategy] = $this->portfolioWithReserveBase(5_000, 10_000, 20);
        $stockId = (int) Holding::query()->where('profile_id', $profile->id)->value('stock_id');
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stockId,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/cash/withdraw', ['amount' => 4_000, 'reason' => 'case-d'])
            ->assertCreated();

        $rec->refresh();
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->status);
        $this->assertNotSame(TradingRecommendation::STATUS_EXPIRED, $rec->status);
        $this->assertNotSame(TradingRecommendation::STATUS_CANCELLED, $rec->status);
        $this->assertTrue($this->capitalJson($user, $profile)['od19']['reserve_shortfall_exists']);
    }

    public function test_settings_can_persist_portfolio_cash_reserve_pct(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/settings', ['portfolio_cash_reserve_pct' => 12.5])
            ->assertOk();

        $this->assertSame(
            '12.5',
            app(ProfileSettingsService::class)->get($profile, PortfolioCapitalAccountingService::RESERVE_PCT_SETTING)
        );
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: TradingStrategy}
     */
    protected function cashOnlyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $strategy->forceFill(['allocation_pct' => 100])->save();
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);

        return [$user, $profile, $strategy->fresh(['activeVersion'])];
    }

    /**
     * Holding invested/notional create a non-zero OD-19 base. Default: invested 10_000, close = avg so notional = invested.
     *
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: TradingStrategy}
     */
    protected function portfolioWithReserveBase(float $cash, float $invested, float $reservePct): array
    {
        [$user, $profile, $strategy] = $this->cashOnlyPortfolio($cash);
        $stock = $this->makeStock();
        $qty = 10;
        $price = $invested / $qty;
        $this->price($stock, $price);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $invested,
            'updated_at' => now(),
        ]);
        app(ProfileSettingsService::class)->set(
            $profile,
            PortfolioCapitalAccountingService::RESERVE_PCT_SETTING,
            (string) $reservePct
        );

        return [$user, $profile, $strategy->fresh(['activeVersion'])];
    }

    protected function setRecommendedMinimumHoldings(TradingStrategy $strategy, int $count): void
    {
        $version = $strategy->activeVersion
            ?? TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        $config = is_array($version?->config_json) ? $version->config_json : [];
        $config['recommended_minimum_holdings'] = $count;
        $version->forceFill(['config_json' => $config])->save();
    }

    protected function capitalJson(User $user, \App\Models\PortfolioProfile $profile): array
    {
        return $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital')
            ->assertOk()
            ->json('data');
    }

    protected function makeStrategy(\App\Models\PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 100,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makeStock(): Stock
    {
        return Stock::query()->create([
            'symbol' => 'CA'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Capital Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function price(Stock $stock, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);
    }
}
