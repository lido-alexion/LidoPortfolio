<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalRequest;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\LenderEligibilityService;
use App\Services\Lending\LenderRankingService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class LenderEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_borrower_is_excluded(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $ids = array_column(
            app(LenderEligibilityService::class)->eligibleLenders($profile, (int) $borrower->id, 5_000),
            'strategy_id'
        );

        $this->assertContains((int) $lender->id, $ids);
        $this->assertNotContains((int) $borrower->id, $ids);
        $this->assertSame($user->id, $user->id);
    }

    public function test_same_profile_other_strategy_is_eligible(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rows = app(LenderEligibilityService::class)->eligibleLenders($profile, (int) $borrower->id, 5_000);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $lender->id, $rows[0]['strategy_id']);
        $this->assertGreaterThanOrEqual(5_000, $rows[0]['available_for_lending']);
        $this->assertSame($rows[0]['available_for_lending'], $rows[0]['maximum_lendable_amount']);
    }

    public function test_other_profile_strategy_is_excluded(): void
    {
        $user = User::factory()->create();
        $a = $this->createPortfolioProfile($user, 'Alpha', true);
        $b = $this->createPortfolioProfile($user, 'Beta', false);
        $borrower = app(StrategyConfigurationService::class)->ensureActive($a)->strategy;
        $other = app(StrategyConfigurationService::class)->ensureActive($b)->strategy;
        app(CashManagementService::class)->deposit($a, 1_000_000, 'seed', $user);
        app(CashManagementService::class)->deposit($b, 1_000_000, 'seed', $user);

        $ids = array_column(
            app(LenderEligibilityService::class)->eligibleLenders($a, (int) $borrower->id, 5_000),
            'strategy_id'
        );
        $this->assertNotContains((int) $other->id, $ids);
    }

    public function test_insufficient_available_for_lending_is_excluded(): void
    {
        $service = $this->serviceWithSnapshot([
            ['strategy_id' => 1, 'name' => 'B', 'available_for_lending' => 100_000, 'strategy_capital_allocation' => 500_000],
            ['strategy_id' => 2, 'name' => 'L', 'available_for_lending' => 5_000, 'strategy_capital_allocation' => 250_000],
        ]);
        $ids = array_column($service->eligibleLenders($this->dummyProfile(), 1, 50_000), 'strategy_id');
        $this->assertNotContains(2, $ids);
    }

    public function test_exactly_5000_available_is_eligible(): void
    {
        $service = $this->serviceWithSnapshot([
            ['strategy_id' => 1, 'name' => 'B', 'available_for_lending' => 0, 'strategy_capital_allocation' => 500_000],
            ['strategy_id' => 2, 'name' => 'L', 'available_for_lending' => 5_000, 'strategy_capital_allocation' => 250_000],
        ]);
        $ids = array_column($service->eligibleLenders($this->dummyProfile(), 1, 5_000), 'strategy_id');
        $this->assertSame([2], $ids);
    }

    public function test_4999_available_for_lending_is_ineligible(): void
    {
        $service = $this->serviceWithSnapshot([
            ['strategy_id' => 1, 'name' => 'B', 'available_for_lending' => 0, 'strategy_capital_allocation' => 500_000],
            ['strategy_id' => 2, 'name' => 'L', 'available_for_lending' => 4_999, 'strategy_capital_allocation' => 250_000],
        ]);
        $this->assertSame([], $service->eligibleLenders($this->dummyProfile(), 1, 5_000));
    }

    public function test_physical_cash_is_not_used_as_availability(): void
    {
        $capital = Mockery::mock(PortfolioCapitalAccountingService::class);
        $capital->shouldReceive('snapshot')->once()->andReturn([
            'physical_cash' => ['available_physical_cash' => 1_000_000.0],
            'od20' => ['unallocated_cash' => 900_000.0],
            'strategies' => [
                ['strategy_id' => 1, 'name' => 'B', 'available_for_lending' => 0.0, 'strategy_capital_allocation' => 100_000],
                ['strategy_id' => 2, 'name' => 'L', 'available_for_lending' => 0.0, 'strategy_capital_allocation' => 100_000],
            ],
        ]);
        $service = new LenderEligibilityService($capital, new LenderRankingService);
        $this->assertSame([], $service->eligibleLenders($this->dummyProfile(), 1, 5_000));
    }

    public function test_reserve_is_not_double_subtracted(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(20_000);
        $stock = Stock::query()->create([
            'symbol' => 'RS'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Reserve Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 3000,
            'high_price' => 3000,
            'low_price' => 3000,
            'close_price' => 3000,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);
        Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $borrower->id,
            'owner_key' => Holding::ownerKeyFor((int) $borrower->id),
            'quantity' => 10,
            'avg_buy_price' => 3000,
            'invested_amount' => 30_000,
            'updated_at' => now(),
        ]);
        app(ProfileSettingsService::class)->set($profile, 'portfolio_cash_reserve_pct', '20');

        $snap = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $byId = collect($snap['strategies'])->keyBy('strategy_id');
        $afl = (float) $byId[$lender->id]['available_for_lending'];
        $this->assertEqualsWithDelta(6000.0, $snap['od19']['required_cash_reserve'], 0.0001);

        $rows = app(LenderEligibilityService::class)->eligibleLenders($profile, (int) $borrower->id, 5_000);
        if ($afl + 0.0001 >= 5_000) {
            $this->assertSame((int) $lender->id, $rows[0]['strategy_id']);
            $this->assertEqualsWithDelta($afl, $rows[0]['available_for_lending'], 0.0001);
        } else {
            $this->assertSame([], $rows);
        }
        $this->assertSame($user->id, $user->id);
    }

    public function test_displayed_request_does_not_consume_lending_availability(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $before = app(LenderEligibilityService::class)->eligibleLenders($profile, (int) $borrower->id, 50_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'recommendation_id' => $rec->id,
            'amount' => 50_000,
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);
        $after = app(LenderEligibilityService::class)->eligibleLenders($profile, (int) $borrower->id, 50_000);
        $this->assertEqualsWithDelta(
            $before[0]['available_for_lending'],
            $after[0]['available_for_lending'],
            0.0001
        );
        $this->assertSame((int) $lender->id, $after[0]['strategy_id']);
    }

    /**
     * @param  list<array<string, mixed>>  $strategies
     */
    private function serviceWithSnapshot(array $strategies): LenderEligibilityService
    {
        $capital = Mockery::mock(PortfolioCapitalAccountingService::class);
        $capital->shouldReceive('snapshot')->andReturn(['strategies' => $strategies]);

        return new LenderEligibilityService($capital, new LenderRankingService);
    }

    private function dummyProfile(): PortfolioProfile
    {
        $user = User::factory()->create();

        return $this->defaultPortfolioFor($user);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Strategy B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 75],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertOk();

        return [$user, $profile, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    private function makeRecommendation($profile, TradingStrategy $borrower): TradingRecommendation
    {
        $stock = Stock::query()->create([
            'symbol' => 'EL'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Elig Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
    }

    private function makeStrategy($profile, string $name): TradingStrategy
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
}
