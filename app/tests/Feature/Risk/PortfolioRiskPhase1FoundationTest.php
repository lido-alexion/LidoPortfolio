<?php

namespace Tests\Feature\Risk;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\ProfileSetting;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HoldingsCalculationService;
use App\Services\HoldingPresentationService;
use App\Services\ProfileSettingsService;
use App\Services\Risk\OwnershipEpisodeService;
use App\Services\Risk\PortfolioTrailingStopCalculator;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * V3 Phase 1 — OD-22 seed, ownership isolation, holdings attribution.
 */
class PortfolioRiskPhase1FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_od22_portfolio_trailing_defaults_and_seeds_to_fifteen(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $settings = app(ProfileSettingsService::class);

        $this->assertSame('15', $settings->get($profile, 'portfolio_trailing_percent'));
        $this->assertSame('10', $settings->get($profile, 'default_stoploss_percent'));

        // Migration seed path: explicit row
        ProfileSetting::setValue((int) $profile->id, 'portfolio_trailing_percent', '15');
        $this->assertSame('15', $settings->get($profile, 'portfolio_trailing_percent'));
    }

    public function test_changing_sl_percent_does_not_change_trailing_percent(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $settings = app(ProfileSettingsService::class);

        $settings->set($profile, 'default_stoploss_percent', '25');
        $this->assertSame('25', $settings->get($profile, 'default_stoploss_percent'));
        $this->assertSame('15', $settings->get($profile, 'portfolio_trailing_percent'));
    }

    public function test_strategy_trailing_json_does_not_change_portfolio_trailing_calculation(): void
    {
        $calc = new PortfolioTrailingStopCalculator;
        $fromPortfolio = $calc->trailingStopPrice(100.0, 15.0);

        // Strategy JSON trailing value (e.g. 8%) must not be an input.
        $ignoredStrategyTrailing = 8.0;
        $this->assertEqualsWithDelta(85.0, $fromPortfolio, 0.0001);
        $this->assertNotEquals(
            100.0 * (1 - $ignoredStrategyTrailing / 100),
            $fromPortfolio
        );
    }

    public function test_presentation_trailing_uses_portfolio_trailing_not_stoploss_percent(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(ProfileSettingsService::class)->set($profile, 'default_stoploss_percent', '10');
        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');

        $stock = $this->makeStock('TRL');
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2024-01-10',
            'source' => Transaction::SOURCE_MANUAL,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-01-15',
            'open_price' => 100,
            'high_price' => 200,
            'low_price' => 50,
            'close_price' => 125,
            'adjusted_close_price' => 999,
            'volume' => 100,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $holding = app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);
        $summary = app(HoldingPresentationService::class)->enrichHolding($profile, $holding)['stoploss_summary'];

        $this->assertEqualsWithDelta(125.0, (float) $summary['highest_close_since_buy'], 0.0001);
        // 15% trailing of 125 = 106.25 — not 10% SL (112.5), not adjusted 999
        $this->assertEqualsWithDelta(106.25, (float) $summary['trailing_stop_price'], 0.0001);
        $this->assertEqualsWithDelta(15.0, (float) $summary['portfolio_trailing_percent'], 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $summary['stoploss_percent'], 0.0001);
    }

    public function test_same_symbol_strategies_keep_independent_trailing_peaks(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategyA = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $strategyB = $this->makeStrategy($profile, 'Strategy B');
        $versionA = TradingStrategyVersion::query()->where('strategy_id', $strategyA->id)->orderByDesc('id')->first();
        $versionB = TradingStrategyVersion::query()->where('strategy_id', $strategyB->id)->orderByDesc('id')->first();
        $this->assertNotNull($versionA);
        $this->assertNotNull($versionB);

        $stock = $this->makeStock('ISO');

        $recA = $this->makeRec($profile, $stock, $versionA);
        $recB = $this->makeRec($profile, $stock, $versionB);

        // A buys earlier; B buys later — different ownership episodes
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2024-01-01',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $recA->id,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 150,
            'fees' => 0,
            'transaction_date' => '2024-02-01',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $recB->id,
        ]);

        // Peak while only A is in — close 140 on Jan 15
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-01-15',
            'open_price' => 140,
            'high_price' => 200,
            'low_price' => 10,
            'close_price' => 140,
            'adjusted_close_price' => 500,
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
        // After B enters — close 130 (lower than A's peak)
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2024-02-10',
            'open_price' => 130,
            'high_price' => 130,
            'low_price' => 130,
            'close_price' => 130,
            'adjusted_close_price' => 500,
            'volume' => 1,
            'data_source' => 'test',
            'created_at' => now(),
        ]);

        $lots = app(HoldingsCalculationService::class)->recalculateOwnerLotsForProfileStock($profile, $stock);
        $this->assertCount(2, $lots);

        $holdingA = $lots->firstWhere('strategy_id', $strategyA->id);
        $holdingB = $lots->firstWhere('strategy_id', $strategyB->id);
        $this->assertNotNull($holdingA);
        $this->assertNotNull($holdingB);
        $this->assertSame(Holding::ownerKeyFor((int) $strategyA->id), $holdingA->owner_key);
        $this->assertSame(Holding::ownerKeyFor((int) $strategyB->id), $holdingB->owner_key);

        $episodes = app(OwnershipEpisodeService::class);
        $entryA = $episodes->firstBuyDateForHolding($profile, $holdingA, $stock);
        $entryB = $episodes->firstBuyDateForHolding($profile, $holdingB, $stock);
        $this->assertSame('2024-01-01', $entryA->toDateString());
        $this->assertSame('2024-02-01', $entryB->toDateString());

        $peakA = $episodes->peakRawCloseSinceEntry($stock, $entryA);
        $peakB = $episodes->peakRawCloseSinceEntry($stock, $entryB);
        $this->assertEqualsWithDelta(140.0, $peakA, 0.0001);
        $this->assertEqualsWithDelta(130.0, $peakB, 0.0001);
        $this->assertNotEquals($peakA, $peakB);

        app(ProfileSettingsService::class)->set($profile, 'portfolio_trailing_percent', '15');
        $summaryA = app(HoldingPresentationService::class)->enrichHolding($profile, $holdingA)['stoploss_summary'];
        $summaryB = app(HoldingPresentationService::class)->enrichHolding($profile, $holdingB)['stoploss_summary'];

        $this->assertEqualsWithDelta(140.0 * 0.85, (float) $summaryA['trailing_stop_price'], 0.0001);
        $this->assertEqualsWithDelta(130.0 * 0.85, (float) $summaryB['trailing_stop_price'], 0.0001);
    }

    public function test_recalculation_preserves_owner_attribution_when_transactions_identify_owner(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $version = TradingStrategyVersion::query()->where('strategy_id', $strategy->id)->first();
        $stock = $this->makeStock('OWN');
        $rec = $this->makeRec($profile, $stock, $version);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 20,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2024-03-01',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);
        // Second fill (INCREASE) — OD-13 weighted average updates
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 20,
            'price' => 70,
            'fees' => 0,
            'transaction_date' => '2024-03-15',
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);

        $holding = app(HoldingsCalculationService::class)->recalculateForProfileStock($profile, $stock);
        $this->assertSame((int) $strategy->id, (int) $holding->strategy_id);
        $this->assertSame(Holding::ownerKeyFor((int) $strategy->id), $holding->owner_key);
        $this->assertEqualsWithDelta(40.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(60.0, (float) $holding->avg_buy_price, 0.0001);

        $fills = app(OwnershipEpisodeService::class)->fillsForCurrentEpisode($profile, $holding, $stock);
        $this->assertCount(2, $fills);
    }

    public function test_settings_api_exposes_independent_trailing_percent(): void
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->putJson('/api/settings', [
                'default_stoploss_percent' => '12',
                'portfolio_trailing_percent' => '18',
            ])
            ->assertOk()
            ->assertJsonPath('data.default_stoploss_percent', '12')
            ->assertJsonPath('data.portfolio_trailing_percent', '18');

        $this->assertSame('12', app(ProfileSettingsService::class)->get($profile, 'default_stoploss_percent'));
        $this->assertSame('18', app(ProfileSettingsService::class)->get($profile, 'portfolio_trailing_percent'));
    }

    protected function makeStock(string $prefix): Stock
    {
        return Stock::query()->create([
            'symbol' => $prefix.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Risk Test '.$prefix,
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
            'config_json' => [
                'exit_strategy' => [
                    'rules' => [['key' => 'trailing_stop', 'value' => 8, 'enabled' => true]],
                ],
            ],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makeRec(PortfolioProfile $profile, Stock $stock, TradingStrategyVersion $version): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
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
    }
}
