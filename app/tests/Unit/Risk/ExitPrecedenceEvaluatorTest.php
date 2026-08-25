<?php

namespace Tests\Unit\Risk;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfileSettingsService;
use App\Services\Risk\ExitAttribution;
use App\Services\Risk\ExitPrecedenceEvaluator;
use App\Services\StrategyConfigurationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Phase 2 — §13.2 exit precedence (OD-13 / OD-14 / OD-22).
 */
class ExitPrecedenceEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_exit_wins_over_sl_and_trailing(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(fillPrice: 100, qty: 10);
        $this->seedCloses($stock, [
            '2024-01-01' => 100,
            '2024-02-01' => 85, // below SL and trailing
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            [
                'enabled' => true,
                'mode' => 'any',
                'rules' => [
                    ['key' => 'score_exit', 'enabled' => true, 'value' => 20],
                ],
            ],
            ['overall_score' => 10], // strategy exit true
            [],
        );

        $this->assertTrue($result['triggered']);
        $this->assertSame(ExitAttribution::STRATEGY_EXIT, $result['primary_reason']);
        $this->assertContains(ExitAttribution::STOP_LOSS, $result['also_true']);
        $this->assertContains(ExitAttribution::TRAILING_STOP, $result['also_true']);
    }

    public function test_stop_loss_wins_when_strategy_exit_false_and_trailing_true(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(fillPrice: 100, qty: 10);
        $this->seedCloses($stock, [
            '2024-01-01' => 100,
            '2024-01-15' => 120, // peak
            '2024-02-01' => 88,  // below 10% SL (90) and below trailing
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => true, 'mode' => 'any', 'rules' => [
                ['key' => 'score_exit', 'enabled' => true, 'value' => 20],
            ]],
            ['overall_score' => 80], // strategy exit false
            [],
        );

        $this->assertTrue($result['triggered']);
        $this->assertSame(ExitAttribution::STOP_LOSS, $result['primary_reason']);
        $this->assertContains(ExitAttribution::TRAILING_STOP, $result['also_true']);
        $this->assertNotContains(ExitAttribution::STRATEGY_EXIT, $result['also_true']);
    }

    public function test_trailing_wins_over_horizon_when_higher_false(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(
            fillPrice: 100,
            qty: 10,
            buyDate: '2024-01-01',
        );
        $this->seedCloses($stock, [
            '2024-01-01' => 100,
            '2024-01-20' => 130,
            '2024-02-10' => 105, // above SL (90), below trailing 130*0.85=110.5
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => true, 'mode' => 'any', 'rules' => [
                ['key' => 'score_exit', 'enabled' => true, 'value' => 20],
            ]],
            ['overall_score' => 80],
            ['portfolio_rules' => ['horizon_calendar_days' => 30]],
            Carbon::parse('2024-02-10'),
        );

        $this->assertTrue($result['triggered']);
        $this->assertSame(ExitAttribution::TRAILING_STOP, $result['primary_reason']);
        $this->assertContains(ExitAttribution::HORIZON_EXPIRY, $result['also_true']);
    }

    public function test_only_horizon_true_wins(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(
            fillPrice: 100,
            qty: 10,
            buyDate: '2024-01-01',
        );
        $this->seedCloses($stock, [
            '2024-01-01' => 100,
            '2024-02-15' => 105, // above SL and trailing
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => false, 'mode' => 'any', 'rules' => []],
            [],
            ['horizon_calendar_days' => 30],
            Carbon::parse('2024-02-15'),
        );

        $this->assertTrue($result['triggered']);
        $this->assertSame(ExitAttribution::HORIZON_EXPIRY, $result['primary_reason']);
        $this->assertSame([], $result['also_true']);
    }

    public function test_raw_close_triggers_sl_adjusted_close_ignored(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(fillPrice: 100, qty: 10);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-02-01',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 50, // would hit if used
            'close_price' => 95, // above 10% SL of 90 — no hit
            'adjusted_close_price' => 80, // would hit if used
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => false, 'rules' => []],
            [],
            [],
        );

        $this->assertFalse($result['mechanisms'][ExitAttribution::STOP_LOSS]['triggered']);

        StockPrice::query()->where('stock_id', $stock->id)->delete();
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-02-01',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 50,
            'close_price' => 88, // hits SL
            'adjusted_close_price' => 200, // would NOT hit if wrongly used as sole input
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $result2 = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => false, 'rules' => []],
            [],
            [],
        );
        $this->assertTrue($result2['mechanisms'][ExitAttribution::STOP_LOSS]['triggered']);
        $this->assertSame(ExitAttribution::STOP_LOSS, $result2['primary_reason']);
    }

    public function test_trailing_uses_max_raw_close_since_episode_entry(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(
            fillPrice: 100,
            qty: 10,
            buyDate: '2024-01-10',
        );
        // Pre-entry peak must not count
        $this->seedCloses($stock, [
            '2024-01-05' => 200,
            '2024-01-10' => 100,
            '2024-01-20' => 150,
            '2024-02-01' => 120, // trailing stop = 150*0.85 = 127.5 → hit
        ]);
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '50'); // SL not hit

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => false, 'rules' => []],
            [],
            [],
        );

        $this->assertTrue($result['triggered']);
        $this->assertSame(ExitAttribution::TRAILING_STOP, $result['primary_reason']);
        $this->assertEqualsWithDelta(
            150.0,
            (float) $result['mechanisms'][ExitAttribution::TRAILING_STOP]['detail']['peak_raw_close'],
            0.0001
        );
    }

    public function test_weighted_average_fills_update_sl_after_increase(): void
    {
        [$profile, $holding, $stock, $strategy] = $this->seedOwnedHolding(fillPrice: 100, qty: 50, buyDate: '2024-01-01');
        $version = TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->first();
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_INCREASE_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 120,
            'fees' => 0,
            'transaction_date' => '2024-01-15',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);
        $holding->update([
            'quantity' => 100,
            'avg_buy_price' => 110,
            'invested_amount' => 11000,
        ]);

        // Avg cost 110 → 10% SL = 99. Close 98 hits; close 100 does not.
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-02-01',
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 98,
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '50');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding->fresh(),
            $stock,
            ['enabled' => false, 'rules' => []],
            [],
            [],
        );

        $this->assertTrue($result['mechanisms'][ExitAttribution::STOP_LOSS]['triggered']);
        $this->assertEqualsWithDelta(
            110.0,
            (float) $result['mechanisms'][ExitAttribution::STOP_LOSS]['detail']['weighted_average_fill_cost'],
            0.0001
        );
        $this->assertEqualsWithDelta(
            99.0,
            (float) $result['mechanisms'][ExitAttribution::STOP_LOSS]['detail']['stop_price'],
            0.0001
        );
    }

    public function test_same_symbol_owners_have_independent_risk_windows(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategyA = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $strategyB = $this->makeStrategy($profile, 'B');
        $stock = $this->makeStock('IND');

        $holdingA = $this->createOwnedLot($profile, $strategyA, $stock, 10, 100, '2024-01-01');
        $holdingB = $this->createOwnedLot($profile, $strategyB, $stock, 10, 100, '2024-02-01');

        $this->seedCloses($stock, [
            '2024-01-15' => 140, // only A sees this peak
            '2024-02-01' => 100,
            '2024-02-10' => 120,
        ]);
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '50');

        $eval = app(ExitPrecedenceEvaluator::class);
        $a = $eval->evaluate($profile, $holdingA, $stock, ['enabled' => false, 'rules' => []], [], []);
        $b = $eval->evaluate($profile, $holdingB, $stock, ['enabled' => false, 'rules' => []], [], []);

        $this->assertEqualsWithDelta(140.0, (float) $a['mechanisms'][ExitAttribution::TRAILING_STOP]['detail']['peak_raw_close'], 0.0001);
        $this->assertEqualsWithDelta(120.0, (float) $b['mechanisms'][ExitAttribution::TRAILING_STOP]['detail']['peak_raw_close'], 0.0001);
    }

    public function test_no_horizon_configured_never_fires(): void
    {
        [$profile, $holding, $stock] = $this->seedOwnedHolding(buyDate: '2020-01-01');
        $this->seedCloses($stock, ['2024-01-01' => 110]);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '50');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '50');

        $result = app(ExitPrecedenceEvaluator::class)->evaluate(
            $profile,
            $holding,
            $stock,
            ['enabled' => false, 'rules' => []],
            [],
            [], // no horizon
            Carbon::parse('2024-01-01'),
        );

        $this->assertFalse($result['mechanisms'][ExitAttribution::HORIZON_EXPIRY]['triggered']);
        $this->assertFalse($result['triggered']);
    }

    /**
     * @return array{0: PortfolioProfile, 1: Holding, 2: Stock, 3: TradingStrategy}
     */
    protected function seedOwnedHolding(
        float $fillPrice = 100,
        float $qty = 10,
        string $buyDate = '2024-01-01',
    ): array {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $stock = $this->makeStock('EP');
        $holding = $this->createOwnedLot($profile, $strategy, $stock, $qty, $fillPrice, $buyDate);

        return [$profile, $holding, $stock, $strategy];
    }

    protected function createOwnedLot(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        Stock $stock,
        float $qty,
        float $price,
        string $buyDate,
    ): Holding {
        $version = TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->orderByDesc('id')->first();
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $buyDate,
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, float>  $closes
     */
    protected function seedCloses(Stock $stock, array $closes): void
    {
        foreach ($closes as $date => $close) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'volume' => 1,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }

    protected function makeStock(string $prefix): Stock
    {
        return Stock::query()->create([
            'symbol' => $prefix.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Exit Test',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    protected function makeStrategy(PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['exit_strategy' => ['enabled' => false, 'rules' => []]],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }
}
