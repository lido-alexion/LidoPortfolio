<?php

namespace Tests\Feature;

use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Evaluation\EvaluationFactorContext;
use App\Engines\Evaluation\EvaluationFactorResult;
use App\Engines\Evaluation\EvaluationFactorRule;
use App\Engines\Evaluation\EvaluationFactorRuleSet;
use App\Engines\Market\MarketAnalysisEngine;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvaluationFactorRulesArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_EVALUATION.'.min_bars' => 15,
        ]);
    }

    public function test_default_run_exposes_unchanged_catalogue_keys_and_aliases(): void
    {
        $this->mockMarketOnce('Neutral');
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock, ['patterns' => [['id' => 'hammer']]]);

        $row = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0];
        $evidence = $row->evidence;
        $this->assertFalse((bool) ($evidence['skipped'] ?? false));

        foreach (EvaluationFactorRuleSet::CATALOGUE_KEYS as $key) {
            $this->assertArrayHasKey($key, $evidence['indicator_scores']);
            $this->assertArrayHasKey($key, $evidence['factor_scores']);
        }
        foreach (['momentum', 'trend', 'pattern_bonus', 'volume', 'risk'] as $alias) {
            $this->assertArrayHasKey($alias, $evidence['factor_scores']);
        }
        $this->assertSame('Neutral', $evidence['market_regime']);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['market_regime_score'], 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $evidence['indicator_scores']['sector_strength'], 0.0001);
        $this->assertContains('pattern_present', $row->passed_rules ?? []);
    }

    public function test_equal_weight_mean_of_catalogue_factors(): void
    {
        $this->mockMarketOnce('Bullish');
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);

        $row = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0];
        $scores = $row->evidence['indicator_scores'];
        $expected = round(array_sum($scores) / count($scores), 4);
        $this->assertEqualsWithDelta($expected, (float) $row->score, 0.0001);
        $this->assertCount(8, $scores);
    }

    public function test_removing_sector_strength_omits_only_that_factor(): void
    {
        $this->mockMarketOnce('Neutral');
        $original = app(EvaluationFactorRuleSet::class);
        $this->app->forgetInstance(EvaluationFactorRuleSet::class);
        $this->app->forgetInstance(EvaluationEngine::class);
        $this->app->instance(EvaluationFactorRuleSet::class, new EvaluationFactorRuleSet($original->without('sector_strength')));

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $row = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0];
        $scores = $row->evidence['indicator_scores'];

        $this->assertArrayNotHasKey('sector_strength', $scores);
        $this->assertArrayHasKey('market_regime', $scores);
        $this->assertCount(7, $scores);
        $expected = round(array_sum($scores) / 7, 4);
        $this->assertEqualsWithDelta($expected, (float) $row->score, 0.0001);
    }

    public function test_adding_a_rule_includes_only_that_extra_factor_in_the_mean(): void
    {
        $this->mockMarketOnce('Neutral');
        $original = app(EvaluationFactorRuleSet::class);
        $rules = array_merge($original->all(), [new class implements EvaluationFactorRule
        {
            public function key(): string
            {
                return 'test_extra_factor';
            }

            public function evaluate(EvaluationFactorContext $context): EvaluationFactorResult
            {
                return new EvaluationFactorResult($this->key(), 0.0);
            }
        }]);
        $this->app->forgetInstance(EvaluationFactorRuleSet::class);
        $this->app->forgetInstance(EvaluationEngine::class);
        $this->app->instance(EvaluationFactorRuleSet::class, new EvaluationFactorRuleSet($rules));

        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $row = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0];

        $this->assertArrayHasKey('test_extra_factor', $row->evidence['factor_scores']);
        $this->assertEqualsWithDelta(0.0, (float) $row->evidence['factor_scores']['test_extra_factor'], 0.0001);
        $this->assertArrayNotHasKey('test_extra_factor', $row->evidence['indicator_scores']);
        $catalogue = $row->evidence['indicator_scores'];
        $expected = round((array_sum($catalogue) + 0.0) / 9, 4);
        $this->assertEqualsWithDelta($expected, (float) $row->score, 0.0001);
    }

    public function test_market_analysis_latest_is_called_once_for_multiple_candidates(): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock): void {
            $mock->shouldReceive('latest')->once()->andReturn([
                'available' => true,
                'market_phase' => 'Consolidation',
                'market_regime' => 'Bearish',
                'sentiment' => ['score' => 10, 'label' => 'Fear'],
            ]);
        });

        [$profile, $stockA] = $this->seedEvaluableStock();
        $stockB = Stock::query()->create([
            'symbol' => 'Q'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Second Eval Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $this->seedBars($stockB);
        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        foreach ([$stockA, $stockB] as $stock) {
            Candidate::query()->create([
                'discovery_run_id' => $discoveryRun->id,
                'security_id' => $stock->id,
                'source' => 'test',
                'evidence' => [],
                'created_at' => now(),
            ]);
        }

        $result = app(EvaluationEngine::class)->run($profile, $discoveryRun);
        $this->assertCount(2, $result['results']);
        foreach ($result['results'] as $row) {
            $this->assertSame('Bearish', $row->evidence['market_regime']);
            $this->assertEqualsWithDelta(0.0, (float) $row->evidence['market_regime_score'], 0.0001);
        }
    }

    public function test_pass_fail_tag_order_matches_registered_rule_order(): void
    {
        $this->mockMarketOnce('Neutral');
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $row = app(EvaluationEngine::class)->run($profile, $discoveryRun)['results'][0];

        $tags = array_merge($row->passed_rules ?? [], $row->failed_rules ?? []);
        $positions = [];
        foreach (['uptrend_sma_stack', 'price_above_sma_fast', 'price_below_sma_fast', 'sma_unavailable'] as $tag) {
            $i = array_search($tag, $tags, true);
            if ($i !== false) {
                $positions['trend'] = $i;
            }
        }
        foreach (['rsi_healthy', 'rsi_overbought', 'rsi_oversold', 'rsi_unavailable'] as $tag) {
            $i = array_search($tag, $tags, true);
            if ($i !== false) {
                $positions['momentum'] = $i;
            }
        }
        foreach (['rs_outperform', 'rs_inline', 'rs_underperform', 'rs_unavailable'] as $tag) {
            $i = array_search($tag, $tags, true);
            if ($i !== false) {
                $positions['rs'] = $i;
            }
        }
        $this->assertArrayHasKey('trend', $positions);
        $this->assertArrayHasKey('momentum', $positions);
        $this->assertArrayHasKey('rs', $positions);
        $this->assertLessThan($positions['momentum'], $positions['trend']);
        $this->assertLessThan($positions['rs'], $positions['momentum']);
    }

    protected function mockMarketOnce(string $regime): void
    {
        $this->mock(MarketAnalysisEngine::class, function ($mock) use ($regime): void {
            $mock->shouldReceive('latest')->once()->andReturn([
                'available' => true,
                'market_phase' => 'Consolidation',
                'market_regime' => $regime,
                'sentiment' => ['score' => 61, 'label' => 'Neutral'],
            ]);
        });
    }

    /**
     * @return array{0: \App\Models\PortfolioProfile, 1: Stock}
     */
    protected function seedEvaluableStock(): array
    {
        $user = User::query()->create([
            'name' => 'Eval Rules User',
            'email' => 'eval-rules-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'E'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Eval Rules Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $this->seedBars($stock);

        return [$profile, $stock];
    }

    protected function seedBars(Stock $stock): void
    {
        $n = 80;
        for ($i = 0; $i < $n; $i++) {
            $close = 80.0 + ($i * 0.8) + (($i % 7) * 0.35);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($n - $i)->toDateString(),
                'open_price' => $close - 0.4,
                'high_price' => $close + 1.2,
                'low_price' => $close - 1.1,
                'close_price' => $close,
                'volume' => 20000 + ($i * 900),
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    protected function discoveryWithCandidate($profile, Stock $stock, array $evidence = []): DiscoveryRun
    {
        $discoveryRun = DiscoveryRun::query()->create([
            'profile_id' => $profile->id,
            'dataset_version' => 'test',
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        Candidate::query()->create([
            'discovery_run_id' => $discoveryRun->id,
            'security_id' => $stock->id,
            'source' => 'test',
            'evidence' => $evidence,
            'created_at' => now(),
        ]);

        return $discoveryRun;
    }
}
