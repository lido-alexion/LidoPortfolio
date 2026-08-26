<?php

namespace Tests\Feature;

use App\Engines\Evaluation\EvaluationEngine;
use App\Engines\Evaluation\EvaluationParameterResolver;
use App\Engines\Pipeline\DailyDecisionPipeline;
use App\Engines\Strategy\SupportedIndicators;
use App\Models\Candidate;
use App\Models\DiscoveryRun;
use App\Models\EvaluationRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\StrategyConfigurationService;
use App\Services\WatchlistService;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\MarksDailyDatasetPublished;
use Tests\TestCase;

class EvaluationParameterOverrideTest extends TestCase
{
    use MarksDailyDatasetPublished;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_EVALUATION.'.min_bars' => 15,
            TradingOsConfig::KEY_EVALUATION.'.rsi_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.sma_fast' => 20,
            TradingOsConfig::KEY_EVALUATION.'.sma_slow' => 50,
            TradingOsConfig::KEY_EVALUATION.'.atr_period' => 14,
            TradingOsConfig::KEY_EVALUATION.'.volume_sma_period' => 20,
            TradingOsConfig::KEY_NOTIFICATION.'.notify_on_generate' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_screener_hits' => false,
            TradingOsConfig::KEY_DISCOVERY.'.include_patterns' => false,
        ]);
    }

    public function test_period_overrides_are_consumed_by_evaluation_indicators(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);

        $engine = app(EvaluationEngine::class);
        $globals = app(EvaluationParameterResolver::class)->globals();
        $override = array_merge($globals, [
            'rsi_period' => 5,
            'sma_fast' => 8,
            'sma_slow' => 21,
            'atr_period' => 7,
            'volume_sma_period' => 6,
        ]);

        $defaultRun = $engine->run($profile, $discoveryRun, $globals);
        $overrideRun = $engine->run($profile, $discoveryRun, $override);

        $defaultEvidence = $defaultRun['results'][0]->evidence;
        $overrideEvidence = $overrideRun['results'][0]->evidence;

        $this->assertFalse((bool) ($defaultEvidence['skipped'] ?? false));
        $this->assertFalse((bool) ($overrideEvidence['skipped'] ?? false));

        $this->assertSame(14, $defaultEvidence['evaluation_parameters']['rsi_period']);
        $this->assertSame(20, $defaultEvidence['evaluation_parameters']['sma_fast']);
        $this->assertSame(50, $defaultEvidence['evaluation_parameters']['sma_slow']);
        $this->assertSame(14, $defaultEvidence['evaluation_parameters']['atr_period']);
        $this->assertSame(20, $defaultEvidence['evaluation_parameters']['volume_sma_period']);

        $this->assertSame(5, $overrideEvidence['evaluation_parameters']['rsi_period']);
        $this->assertSame(8, $overrideEvidence['evaluation_parameters']['sma_fast']);
        $this->assertSame(21, $overrideEvidence['evaluation_parameters']['sma_slow']);
        $this->assertSame(7, $overrideEvidence['evaluation_parameters']['atr_period']);
        $this->assertSame(6, $overrideEvidence['evaluation_parameters']['volume_sma_period']);

        $this->assertNotEquals(
            $defaultEvidence['indicators']['rsi'],
            $overrideEvidence['indicators']['rsi'],
        );
        $this->assertNotEquals(
            $defaultEvidence['indicators']['sma_fast'],
            $overrideEvidence['indicators']['sma_fast'],
        );
        $this->assertNotEquals(
            $defaultEvidence['indicators']['sma_slow'],
            $overrideEvidence['indicators']['sma_slow'],
        );
        $this->assertNotEquals(
            $defaultEvidence['indicators']['atr'],
            $overrideEvidence['indicators']['atr'],
        );
        $this->assertNotEquals(
            $defaultEvidence['indicators']['volume_ratio'],
            $overrideEvidence['indicators']['volume_ratio'],
        );

        $this->assertSame(
            $defaultRun['run']->stats_json['evaluation_parameters']['rsi_period'],
            $defaultEvidence['evaluation_parameters']['rsi_period'],
        );
    }

    public function test_missing_strategy_config_matches_global_evaluation_behavior(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock();
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $engine = app(EvaluationEngine::class);
        $resolver = app(EvaluationParameterResolver::class);

        $implicit = $engine->run($profile, $discoveryRun);
        $explicitGlobals = $engine->run($profile, $discoveryRun, $resolver->globals());
        $emptyStrategy = $engine->run($profile, $discoveryRun, $resolver->resolve([]));

        $this->assertSame(
            $implicit['results'][0]->evidence['indicators']['rsi'],
            $explicitGlobals['results'][0]->evidence['indicators']['rsi'],
        );
        $this->assertSame(
            $implicit['results'][0]->evidence['indicators']['sma_fast'],
            $emptyStrategy['results'][0]->evidence['indicators']['sma_fast'],
        );
        $this->assertNull($implicit['results'][0]->evidence['evaluation_parameters']['lookback_days']);
        $this->assertNull($implicit['results'][0]->evidence['evaluation_parameters']['benchmark']);
    }

    public function test_lookback_days_is_consumed_by_relative_strength(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock();
        $this->seedRelativeStrengthSeries($stock, niftyCloseFn: fn (int $i, int $n) => 100.0);
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $engine = app(EvaluationEngine::class);
        $globals = app(EvaluationParameterResolver::class)->globals();

        $threeMonth = $engine->run($profile, $discoveryRun, $globals);
        $shortLookback = $engine->run($profile, $discoveryRun, array_merge($globals, [
            'lookback_days' => 10,
            'use_lookback_days' => true,
            'benchmark' => 'NIFTY50',
        ]));

        $this->assertNotNull($threeMonth['results'][0]->evidence['indicators']['relative_strength_3m']);
        $this->assertNotNull($shortLookback['results'][0]->evidence['indicators']['relative_strength_3m']);
        $this->assertNotEquals(
            $threeMonth['results'][0]->evidence['indicators']['relative_strength_3m'],
            $shortLookback['results'][0]->evidence['indicators']['relative_strength_3m'],
        );
        $this->assertSame(10, $shortLookback['results'][0]->evidence['evaluation_parameters']['lookback_days']);
        $this->assertNull($threeMonth['results'][0]->evidence['evaluation_parameters']['lookback_days']);
    }

    public function test_benchmark_is_consumed_by_relative_strength(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock();
        $this->seedRelativeStrengthSeries(
            $stock,
            niftyCloseFn: fn (int $i, int $n) => 100.0 + ($i * 0.4),
            bankCloseFn: fn (int $i, int $n) => 100.0 + ($i * 0.05),
        );
        $discoveryRun = $this->discoveryWithCandidate($profile, $stock);
        $engine = app(EvaluationEngine::class);
        $globals = app(EvaluationParameterResolver::class)->globals();
        $lookback = [
            'lookback_days' => 20,
            'use_lookback_days' => true,
        ];

        $vsNifty = $engine->run($profile, $discoveryRun, array_merge($globals, $lookback, [
            'benchmark' => 'NIFTY50',
        ]));
        $vsBank = $engine->run($profile, $discoveryRun, array_merge($globals, $lookback, [
            'benchmark' => 'NIFTYBANK',
        ]));

        $this->assertSame('NIFTY50', $vsNifty['results'][0]->evidence['evaluation_parameters']['benchmark']);
        $this->assertSame('NIFTYBANK', $vsBank['results'][0]->evidence['evaluation_parameters']['benchmark']);
        $this->assertNotEquals(
            $vsNifty['results'][0]->evidence['indicators']['relative_strength_3m'],
            $vsBank['results'][0]->evidence['indicators']['relative_strength_3m'],
        );
    }

    public function test_pipeline_resolves_strategy_parameters_into_evaluation_run(): void
    {
        [$profile, $stock] = $this->seedEvaluableStock(withWatchlist: true);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);
        $config = is_array($version->config_json) ? $version->config_json : [];
        foreach ($config['indicators'] as &$row) {
            if (($row['key'] ?? '') === SupportedIndicators::MOMENTUM_SCORE) {
                $row['parameters']['rsi_period'] = 6;
            }
        }
        unset($row);
        $version->forceFill(['config_json' => $config])->save();
        $this->markDailyDatasetPublished();

        $result = app(DailyDecisionPipeline::class)->run($profile, [
            'notify' => false,
            'review' => false,
        ]);

        $this->assertSame('completed', $result['pipeline_run']->status);
        $run = EvaluationRun::query()->findOrFail($result['stages']['evaluation']['run_id']);
        $this->assertSame(6, (int) ($run->stats_json['evaluation_parameters']['rsi_period'] ?? 0));
    }

    /**
     * @return array{0: \App\Models\PortfolioProfile, 1: Stock}
     */
    protected function seedEvaluableStock(bool $withWatchlist = false): array
    {
        $user = User::query()->create([
            'name' => 'Eval Param User',
            'email' => 'eval-param-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'P'.strtoupper(Str::random(4)),
            'exchange' => 'NSE',
            'name' => 'Param Test Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        if ($withWatchlist) {
            $watchlist = app(WatchlistService::class)->ensureDefaultWatchlist($profile);
            WatchlistItem::query()->create([
                'profile_id' => $profile->id,
                'watchlist_id' => $watchlist->id,
                'stock_id' => $stock->id,
                'note' => null,
            ]);
        }

        $n = 80;
        for ($i = 0; $i < $n; $i++) {
            $close = 80.0 + ($i * 0.8) + (($i % 7) * 0.35);
            $volume = 20000 + ($i * 900);
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => now()->subDays($n - $i)->toDateString(),
                'open_price' => $close - 0.4,
                'high_price' => $close + 1.2 + ($i % 5) * 0.3,
                'low_price' => $close - 1.1 - ($i % 4) * 0.25,
                'close_price' => $close,
                'volume' => $volume,
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }

        return [$profile, $stock];
    }

    protected function discoveryWithCandidate($profile, Stock $stock): DiscoveryRun
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
            'evidence' => [],
            'created_at' => now(),
        ]);

        return $discoveryRun;
    }

    /**
     * @param  callable(int, int): float  $niftyCloseFn
     * @param  callable(int, int): float|null  $bankCloseFn
     */
    protected function seedRelativeStrengthSeries(
        Stock $stock,
        callable $niftyCloseFn,
        ?callable $bankCloseFn = null,
    ): void {
        $n = 120;
        $nifty = Stock::query()->firstOrCreate(
            ['symbol' => 'NIFTY50', 'exchange' => 'NSE'],
            ['name' => 'Nifty 50', 'is_active' => true, 'is_benchmark' => true],
        );
        $bank = Stock::query()->firstOrCreate(
            ['symbol' => 'NIFTYBANK', 'exchange' => 'NSE'],
            ['name' => 'Nifty Bank', 'is_active' => true, 'is_benchmark' => true],
        );

        StockPrice::query()->where('stock_id', $stock->id)->delete();

        for ($i = 0; $i < $n; $i++) {
            $date = now()->subDays($n - $i)->toDateString();
            $stockClose = $i < ($n - 15) ? 50.0 : 50.0 + (($i - ($n - 15)) * 4.0);
            $this->writeBar($stock->id, $date, $stockClose, 40000);
            $this->writeBar($nifty->id, $date, $niftyCloseFn($i, $n), 100000);
            if ($bankCloseFn !== null) {
                $this->writeBar($bank->id, $date, $bankCloseFn($i, $n), 80000);
            }
        }
    }

    protected function writeBar(int $stockId, string $date, float $close, int $volume): void
    {
        StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close + 0.5,
            'low_price' => $close - 0.5,
            'close_price' => $close,
            'volume' => $volume,
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }
}
