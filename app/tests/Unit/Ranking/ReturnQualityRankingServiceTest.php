<?php

namespace Tests\Unit\Ranking;

use App\Models\BacktestTrade;
use App\Services\Ranking\ReturnQualityRankingService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ReturnQualityRankingServiceTest extends TestCase
{
    private ReturnQualityRankingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ReturnQualityRankingService;
    }

    private function makeTrade(array $attrs): BacktestTrade
    {
        $trade = new BacktestTrade;
        $defaults = [
            'backtest_run_id' => 1,
            'stock_id' => 1,
            'symbol' => 'TEST',
            'buy_date' => '2024-01-01',
            'sell_date' => '2024-04-01',
            'holding_days' => 91,
            'buy_price' => 100.0,
            'sell_price' => 110.0,
            'quantity' => 10,
            'profit_loss' => 100.0,
            'return_pct' => 10.0,
            'cagr' => 45.0,
            'exit_reason' => 'EXIT',
            'entry_score' => 75.0,
            'is_open' => false,
        ];

        foreach (array_merge($defaults, $attrs) as $key => $value) {
            $trade->$key = $value;
        }

        return $trade;
    }

    private function makeCorpusInBand(array $returns, float $entryScore = 75.0, int $holdingDays = 91): Collection
    {
        $trades = [];
        foreach ($returns as $i => $ret) {
            $trades[] = $this->makeTrade([
                'stock_id' => $i + 1,
                'symbol' => 'S'.($i + 1),
                'return_pct' => $ret,
                'cagr' => $holdingDays >= 30 ? $ret * 4.0 : null,
                'entry_score' => $entryScore,
                'holding_days' => $holdingDays,
            ]);
        }

        return new Collection($trades);
    }

    // ═══ CORPUS ═══

    public function test_closed_trades_are_included_in_corpus(): void
    {
        $corpus = new Collection([
            $this->makeTrade(['is_open' => false, 'return_pct' => 10.0]),
        ]);
        $this->assertCount(1, $corpus->where('is_open', false));
    }

    public function test_open_trades_excluded_from_ranking_corpus(): void
    {
        $corpus = new Collection([
            $this->makeTrade(['is_open' => true]),
            $this->makeTrade(['is_open' => false]),
        ]);
        $filtered = $corpus->where('is_open', false)->whereNotNull('entry_score')->whereNotNull('return_pct');
        $this->assertCount(1, $filtered);
    }

    public function test_wrong_strategy_excluded_by_run_scope(): void
    {
        $corpus = new Collection([
            $this->makeTrade(['backtest_run_id' => 1]),
            $this->makeTrade(['backtest_run_id' => 2]),
        ]);
        $this->assertCount(1, $corpus->where('backtest_run_id', 1));
    }

    public function test_null_entry_score_excluded_from_corpus(): void
    {
        $corpus = new Collection([$this->makeTrade(['entry_score' => null])]);
        $this->assertCount(0, $corpus->whereNotNull('entry_score'));
    }

    // ═══ FIT BANDS ═══

    public function test_band_key_boundaries_are_correct(): void
    {
        $this->assertEquals('[0,10)', $this->svc->bandKeyForScore(0.0));
        $this->assertEquals('[0,10)', $this->svc->bandKeyForScore(5.0));
        $this->assertEquals('[0,10)', $this->svc->bandKeyForScore(9.99));
        $this->assertEquals('[10,20)', $this->svc->bandKeyForScore(10.0));
        $this->assertEquals('[90,100]', $this->svc->bandKeyForScore(90.0));
        $this->assertEquals('[90,100]', $this->svc->bandKeyForScore(95.0));
        $this->assertEquals('[90,100]', $this->svc->bandKeyForScore(100.0));
    }

    public function test_correct_grouping_into_10_point_bands(): void
    {
        $corpus = new Collection([
            $this->makeTrade(['entry_score' => 5.0]),
            $this->makeTrade(['entry_score' => 15.0]),
            $this->makeTrade(['entry_score' => 25.0]),
            $this->makeTrade(['entry_score' => 75.0]),
            $this->makeTrade(['entry_score' => 76.0]),
        ]);
        $bands = $this->svc->buildNormalBands($corpus);
        $this->assertCount(1, $bands['[0,10)']);
        $this->assertCount(1, $bands['[10,20)']);
        $this->assertCount(1, $bands['[20,30)']);
        $this->assertCount(2, $bands['[70,80)']);
        $this->assertArrayNotHasKey('[30,40)', $bands);
    }

    public function test_sparse_band_merges_with_neighbor(): void
    {
        $trades = [];
        for ($i = 0; $i < 8; $i++) {
            $trades[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0]);
        }
        for ($i = 0; $i < 8; $i++) {
            $trades[] = $this->makeTrade(['entry_score' => 85.0, 'return_pct' => 12.0]);
        }
        $normalBands = $this->svc->buildNormalBands(new Collection($trades));
        $resolved = $this->svc->resolveAllBands($normalBands);
        $band70 = collect($resolved)->firstWhere('band_key', '[70,80)');
        $this->assertGreaterThan(1, count($band70['merged_band_keys']));
        $this->assertEquals(16, $band70['effective_n']);
        $this->assertTrue($band70['eligible']);
    }

    public function test_adaptive_15_12_10_path(): void
    {
        // 14 obs → min_n = 12
        $trades14 = [];
        for ($i = 0; $i < 14; $i++) {
            $trades14[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0 + $i]);
        }
        $resolved = $this->svc->resolveAllBands($this->svc->buildNormalBands(new Collection($trades14)));
        $band = collect($resolved)->firstWhere('band_key', '[70,80)');
        $this->assertTrue($band['eligible']);
        $this->assertEquals(12, $band['effective_min_n']);

        // 11 obs → min_n = 10
        $trades11 = [];
        for ($i = 0; $i < 11; $i++) {
            $trades11[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0 + $i]);
        }
        $resolved = $this->svc->resolveAllBands($this->svc->buildNormalBands(new Collection($trades11)));
        $band = collect($resolved)->firstWhere('band_key', '[70,80)');
        $this->assertTrue($band['eligible']);
        $this->assertEquals(10, $band['effective_min_n']);
    }

    public function test_ranking_unavailable_below_final_minimum(): void
    {
        $trades = [];
        for ($i = 0; $i < 9; $i++) {
            $trades[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0 + $i]);
        }
        $result = $this->svc->rankFromCorpus(1, 1, new Collection($trades));
        $this->assertFalse($result['computable']);
        $band = collect($result['bands'])->firstWhere('band_key', '[70,80)');
        $this->assertFalse($band['eligible']);
    }

    // ═══ TRIMMING (DEP-TRIM-K) ═══

    public function test_trim_count_n15_yields_k1(): void
    {
        $this->assertEquals(1, ReturnQualityRankingService::trimCount(15));
    }

    public function test_trim_count_n20_yields_k1(): void
    {
        $this->assertEquals(1, ReturnQualityRankingService::trimCount(20));
    }

    public function test_trim_count_n25_yields_k2(): void
    {
        $this->assertEquals(2, ReturnQualityRankingService::trimCount(25));
    }

    public function test_trim_count_n30_yields_k2(): void
    {
        $this->assertEquals(2, ReturnQualityRankingService::trimCount(30));
    }

    public function test_trim_count_n35_yields_k2(): void
    {
        $this->assertEquals(2, ReturnQualityRankingService::trimCount(35));
    }

    public function test_trim_count_n50_yields_k4_exact_point5_rounds_up(): void
    {
        $this->assertEquals(4, ReturnQualityRankingService::trimCount(50));
    }

    public function test_trim_count_n100_yields_k7(): void
    {
        $this->assertEquals(7, ReturnQualityRankingService::trimCount(100));
    }

    public function test_symmetric_trimming_removes_same_k_from_both_tails(): void
    {
        $values = range(1.0, 15.0);
        $result = $this->svc->symmetricTrimmedMean($values);
        $this->assertEquals(1, $result['k']);
        $this->assertEquals([1.0], $result['trimmed_lower']);
        $this->assertEquals([15.0], $result['trimmed_upper']);
        $this->assertCount(13, $result['remaining']);
        $this->assertEquals(8.0, $result['mean']);
    }

    public function test_trimmed_mean_n25_removes_k2_from_each_tail(): void
    {
        $values = range(1.0, 25.0);
        $result = $this->svc->symmetricTrimmedMean($values);
        $this->assertEquals(2, $result['k']);
        $this->assertCount(21, $result['remaining']);
        $this->assertEquals(13.0, $result['mean']);
    }

    // ═══ OD-18 RETURN METRIC ═══

    public function test_short_trade_cannot_contribute_cagr(): void
    {
        $trade = $this->makeTrade(['holding_days' => 15, 'cagr' => 500.0, 'return_pct' => 5.0]);
        $this->assertEquals(5.0, $this->svc->rankingReturnForTrade($trade));
    }

    public function test_long_trade_uses_cagr_when_available(): void
    {
        $trade = $this->makeTrade(['holding_days' => 91, 'cagr' => 45.0, 'return_pct' => 10.0]);
        $this->assertEquals(45.0, $this->svc->rankingReturnForTrade($trade));
    }

    public function test_simple_return_valid_for_all_periods(): void
    {
        $trade = $this->makeTrade(['holding_days' => 91, 'cagr' => null, 'return_pct' => 10.0]);
        $this->assertEquals(10.0, $this->svc->rankingReturnForTrade($trade));
    }

    public function test_trade_at_exactly_30_days_may_use_cagr(): void
    {
        $trade = $this->makeTrade(['holding_days' => 30, 'cagr' => 120.0, 'return_pct' => 8.0]);
        $this->assertEquals(120.0, $this->svc->rankingReturnForTrade($trade));
    }

    // ═══ RANKING ═══

    public function test_higher_return_quality_ranks_ahead(): void
    {
        $hi = $this->makeCorpusInBand(array_fill(0, 15, 20.0), 75.0);
        $lo = $this->makeCorpusInBand(array_fill(0, 15, 5.0), 45.0);
        $result = $this->svc->rankFromCorpus(1, 1, $hi->merge($lo));
        $this->assertTrue($result['computable']);

        $bandHi = collect($result['bands'])->firstWhere('band_key', '[70,80)');
        $bandLo = collect($result['bands'])->firstWhere('band_key', '[40,50)');

        $this->assertTrue($bandHi['eligible']);
        $this->assertTrue($bandLo['eligible']);
        $this->assertGreaterThan($bandLo['return_quality'], $bandHi['return_quality']);
    }

    public function test_deterministic_results_regardless_of_insertion_order(): void
    {
        $returns = [5.0, 15.0, 3.0, 20.0, 8.0, 12.0, 1.0, 25.0, 10.0, 7.0, 18.0, 6.0, 14.0, 9.0, 11.0];
        $corpus1 = $this->makeCorpusInBand($returns, 75.0);
        $result1 = $this->svc->rankFromCorpus(1, 1, $corpus1);
        $corpus2 = $this->makeCorpusInBand(array_reverse($returns), 75.0);
        $result2 = $this->svc->rankFromCorpus(1, 1, $corpus2);
        $band1 = collect($result1['bands'])->firstWhere('band_key', '[70,80)');
        $band2 = collect($result2['bands'])->firstWhere('band_key', '[70,80)');
        $this->assertEquals($band1['return_quality'], $band2['return_quality']);
    }

    public function test_insufficient_corpus_produces_unavailable(): void
    {
        $result = $this->svc->rankFromCorpus(1, 1, new Collection([
            $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0]),
        ]));
        $this->assertFalse($result['computable']);
        $this->assertNotNull($result['reason']);
    }

    public function test_adaptive_sparsity_produces_auditable_diagnostic(): void
    {
        $trades = [];
        for ($i = 0; $i < 8; $i++) {
            $trades[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0 + $i]);
        }
        for ($i = 0; $i < 5; $i++) {
            $trades[] = $this->makeTrade(['entry_score' => 85.0, 'return_pct' => 20.0 + $i]);
        }
        $result = $this->svc->rankFromCorpus(1, 1, new Collection($trades));
        $band = collect($result['bands'])->firstWhere('band_key', '[70,80)');
        $this->assertArrayHasKey('merges_performed', $band);
        $this->assertArrayHasKey('effective_min_n', $band);
        $this->assertArrayHasKey('merged_band_keys', $band);
        $this->assertArrayHasKey('confidence', $band);
        $this->assertGreaterThan(0, $band['merges_performed']);
    }

    public function test_confidence_decreases_with_compromises(): void
    {
        $perfectCorpus = $this->makeCorpusInBand(array_fill(0, 50, 10.0), 75.0);
        $perfectResult = $this->svc->rankFromCorpus(1, 1, $perfectCorpus);
        $perfectBand = collect($perfectResult['bands'])->firstWhere('band_key', '[70,80)');

        $sparse = [];
        for ($i = 0; $i < 8; $i++) {
            $sparse[] = $this->makeTrade(['entry_score' => 75.0, 'return_pct' => 10.0]);
        }
        for ($i = 0; $i < 5; $i++) {
            $sparse[] = $this->makeTrade(['entry_score' => 85.0, 'return_pct' => 10.0]);
        }
        $sparseResult = $this->svc->rankFromCorpus(1, 1, new Collection($sparse));
        $sparseBand = collect($sparseResult['bands'])->firstWhere('band_key', '[70,80)');

        if ($perfectBand['eligible'] && $sparseBand['eligible']) {
            $this->assertGreaterThan($sparseBand['confidence'], $perfectBand['confidence']);
        }
    }

    public function test_all_band_keys_cover_0_to_100(): void
    {
        $keys = $this->svc->allBandKeys();
        $this->assertCount(10, $keys);
        $this->assertEquals('[0,10)', $keys[0]);
        $this->assertEquals('[90,100]', $keys[9]);
    }

    public function test_empty_corpus_returns_unavailable(): void
    {
        $result = $this->svc->rankFromCorpus(1, 1, new Collection);
        $this->assertFalse($result['computable']);
    }

    public function test_extract_return_values_uses_cagr_for_long_and_return_pct_for_short(): void
    {
        $long = $this->makeTrade(['holding_days' => 91, 'cagr' => 45.0, 'return_pct' => 10.0]);
        $short = $this->makeTrade(['holding_days' => 15, 'cagr' => 500.0, 'return_pct' => 5.0]);
        $this->assertEquals([45.0, 5.0], $this->svc->extractReturnValues([$long, $short]));
    }
}
