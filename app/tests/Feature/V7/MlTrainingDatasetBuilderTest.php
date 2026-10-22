<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MlTrainingDatasetBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_is_chronological_and_does_not_label_past_the_cutoff(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS', 'sector' => 'Current master sector']);
        $inactive = Stock::query()->create(['symbol' => 'OLDCO', 'exchange' => 'NSE', 'name' => 'Old Co', 'is_active' => false]);
        $inactiveTwo = Stock::query()->create(['symbol' => 'OLDCO2', 'exchange' => 'NSE', 'name' => 'Old Co 2', 'is_active' => false]);

        app(FundamentalDataService::class)->storeFacts($stock, [
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2025-03-31', 'value' => 2.5, 'availability_date' => '2025-06-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2024-12-31', 'value' => 2.5, 'availability_date' => '2025-03-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2024-09-30', 'value' => 2.5, 'availability_date' => '2024-12-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2024-06-30', 'value' => 2.5, 'availability_date' => '2024-09-01',
            ],
            [
                'statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'equity',
                'period_end' => '2025-03-31', 'value' => 100, 'availability_date' => '2025-06-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
                'period_end' => '2025-03-31', 'value' => 120, 'availability_date' => '2025-06-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
                'period_end' => '2024-12-31', 'value' => 100, 'availability_date' => '2025-03-01',
            ],
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'revenue',
                'period_end' => '2024-03-31', 'value' => 100, 'availability_date' => '2024-06-01',
            ],
        ], Carbon::parse('2025-06-01'));

        foreach ([$benchmark, $stock, $inactive, $inactiveTwo] as $subject) {
            for ($day = 0; $day < 220; $day++) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse('2025-01-01')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $dataset = app(MlTrainingDatasetBuilder::class)->build('1m', Carbon::parse('2025-08-01'));
        $rows = collect($dataset['rows']);

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['reference_date'] <= '2025-08-01'));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['label_end'] <= '2025-08-01'));
        $stockRows = $rows->filter(fn (array $row): bool => $row['stock_id'] === $stock->id);
        $this->assertNotEmpty($stockRows);
        $this->assertTrue($stockRows->every(fn (array $row): bool => $row['features']['sector'] === '__unknown'));
        $this->assertTrue($stockRows->every(fn (array $row): bool => $row['features']['sector_relative_strength_3m'] === null));
        $this->assertTrue($stockRows->filter(fn (array $row): bool => $row['reference_date'] < '2025-06-01')->every(fn (array $row): bool => $row['features']['roe'] === null));
        $this->assertEquals([10.0], $stockRows->filter(fn (array $row): bool => $row['reference_date'] >= '2025-06-01')->pluck('features.roe')->unique()->values()->all());
        $juneRow = $stockRows->first(fn (array $row): bool => $row['reference_date'] >= '2025-06-01');
        $this->assertNotNull($juneRow);
        $legacyRoe = app(FundamentalDataService::class)->metric($stock, 'roe', 'ttm', Carbon::parse('2025-06-30'));
        $legacyGrowth = app(FundamentalDataService::class)->growthMetric($stock, 'revenue', 'quarterly', Carbon::parse('2025-06-30'));
        $this->assertEquals($legacyRoe['value'], $juneRow['features']['roe']);
        $this->assertEquals($legacyGrowth['value'], $juneRow['features']['revenue_growth_proxy']);
        $this->assertSame($rows->sortBy('reference_date')->pluck('reference_date')->values()->all(), $rows->pluck('reference_date')->values()->all());
        $this->assertSame('2025-08-01', $dataset['partitions']['cutoff_date']);
        $this->assertSame('train', $rows->first()['partition']);
        $this->assertContains('test', $rows->pluck('partition')->unique()->all());
        $this->assertFalse($rows->contains(fn (array $row): bool => $row['stock_id'] === $inactive->id));
        $groups = $rows->groupBy(fn (array $row): string => $row['stock_id'].'|'.Carbon::parse($row['reference_date'])->format('o-W'));
        $this->assertTrue($groups->every(fn ($group): bool => $group->count() === 1));
        foreach ($rows as $row) {
            $week = Carbon::parse($row['reference_date'])->format('o-W');
            $lastAvailable = StockPrice::query()
                ->where('stock_id', $row['stock_id'])
                ->get(['price_date'])
                ->filter(fn (StockPrice $price): bool => Carbon::parse($price->price_date)->format('o-W') === $week)
                ->max('price_date');
            $this->assertSame(Carbon::parse($lastAvailable)->toDateString(), $row['reference_date']);
        }
        $this->assertSame('v8-horizon-reference-1', $dataset['feature_definitions']['sampling']['version']);
        $this->assertSame('weekly', $dataset['feature_definitions']['sampling']['cadence']);
        $profile = $dataset['feature_definitions']['resolved_feature_profile'];
        $this->assertSame('1m', $profile['horizon']);
        $this->assertSame($profile['feature_set_id'], $profile['feature_set_version']);
        $this->assertSame($profile['feature_keys'], array_keys($profile['feature_versions']));
        $this->assertSame('v8-preprocessing-1', $profile['preprocessing']['version']);
        $this->assertSame($dataset['diagnostics']['rows_written'], $dataset['diagnostics']['context_coverage']['written_rows']);
        $this->assertGreaterThan(0, $dataset['diagnostics']['context_coverage']['market_breadth_missing']);
        $this->assertGreaterThan(0, $dataset['diagnostics']['context_coverage']['sector_relative_missing']);
        $this->assertSame(0.0, $dataset['diagnostics']['membership_coverage']['coverage_percentage']);
        $this->assertNotEmpty($dataset['diagnostics']['membership_coverage']['missing_dates']);
        $this->assertLessThanOrEqual(50, $dataset['diagnostics']['peak_buffered_rows']);

        $plan = app(MlTrainingDatasetBuilder::class)->plan('1m', Carbon::parse('2025-08-01'));
        $this->assertSame(1, $plan['stock_count']);
        $this->assertSame('weekly', $plan['sampling']['cadence']);
        $this->assertGreaterThan(0, $plan['estimated_reference_rows']);
    }

    public function test_sampling_policy_is_horizon_aware(): void
    {
        $builder = app(MlTrainingDatasetBuilder::class);

        $this->assertSame(['version' => 'v8-horizon-reference-1', 'cadence' => 'weekly', 'anchor' => 'last_available_trading_day', 'horizon_days' => 21], $builder->samplingPolicy('1m'));
        $this->assertSame('monthly', $builder->samplingPolicy('3m')['cadence']);
        $this->assertSame('monthly', $builder->samplingPolicy('6m')['cadence']);
    }

    public function test_build_uses_bounded_stock_level_queries_instead_of_per_row_fundamental_queries(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        foreach (['AAA', 'BBB', 'CCC'] as $symbol) {
            $stock = Stock::query()->create(['symbol' => $symbol, 'exchange' => 'NSE', 'name' => $symbol]);
            foreach (range(0, 220) as $day) {
                StockPrice::query()->create([
                    'stock_id' => $stock->id,
                    'price_date' => Carbon::parse('2025-01-01')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }
        foreach (range(0, 220) as $day) {
            StockPrice::query()->create([
                'stock_id' => $benchmark->id,
                'price_date' => Carbon::parse('2025-01-01')->addDays($day)->toDateString(),
                'close_price' => 100 + $day,
                'adjusted_close_price' => 100 + $day,
                'data_source' => 'test',
            ]);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void { $queries++; });
        $directory = storage_path('framework/testing/ml-stream-'.bin2hex(random_bytes(4)));
        $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed('1m', Carbon::parse('2025-08-01'), $directory);

        $this->assertGreaterThan(0, $dataset['diagnostics']['rows_written']);
        $this->assertLessThan(91, $queries, 'Dataset construction regressed to unbounded per-reference SQL queries.');
        $this->assertLessThanOrEqual(50, $dataset['diagnostics']['peak_buffered_rows']);
        $this->assertSame($dataset['diagnostics']['rows_written'], array_sum($dataset['partitions']['row_counts']));
        File::deleteDirectory($directory);
    }

    public function test_multi_stock_stream_keeps_peak_buffer_to_one_stock(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $issuers = [];
        for ($stockNumber = 1; $stockNumber <= 20; $stockNumber++) {
            $issuers[] = Stock::query()->create([
                'symbol' => 'SCALE'.$stockNumber,
                'exchange' => 'NSE',
                'name' => 'Scale '.$stockNumber,
                'is_active' => $stockNumber !== 20,
            ]);
        }
        foreach ([$benchmark, ...$issuers] as $subject) {
            foreach (range(0, 399) as $day) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse('2020-01-01')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $directory = storage_path('framework/testing/ml-scale-'.bin2hex(random_bytes(4)));
        $queries = 0;
        DB::listen(static function () use (&$queries): void { $queries++; });
        $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed('1m', Carbon::parse('2021-12-31'), $directory);

        $this->assertSame(19, $dataset['diagnostics']['stocks_processed']);
        $this->assertGreaterThan(100, $dataset['diagnostics']['rows_written']);
        $this->assertLessThanOrEqual(50, $dataset['diagnostics']['peak_buffered_rows']);
        $this->assertLessThan(1200, $queries, 'Weekly horizon context remains bounded by the sampled date universe.');
        $this->assertGreaterThan(0, $dataset['diagnostics']['temporary_dataset_bytes']);
        File::deleteDirectory($directory);
    }

    public function test_partition_dates_ignore_issuer_history_before_benchmark_history(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $issuer = Stock::query()->create(['symbol' => 'LONGHISTORY', 'exchange' => 'NSE', 'name' => 'Long History']);

        foreach ([[$issuer, '2020-01-01', 2400], [$benchmark, '2025-01-20', 620]] as [$subject, $start, $days]) {
            for ($day = 0; $day < $days; $day++) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse($start)->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $directory = storage_path('framework/testing/ml-benchmark-boundary-'.bin2hex(random_bytes(4)));
        $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed('1m', Carbon::parse('2026-09-01'), $directory);

        $this->assertSame('2025-03-30', $dataset['diagnostics']['viable_reference_date_start']);
        $this->assertGreaterThan($dataset['diagnostics']['viable_reference_date_start'], $dataset['diagnostics']['viable_reference_date_end']);
        $this->assertGreaterThan(30, $dataset['diagnostics']['viable_reference_date_count']);
        $this->assertSame($dataset['diagnostics']['benchmark_start_date'], '2025-01-20');
        $this->assertSame(array_sum($dataset['partitions']['row_counts']), $dataset['diagnostics']['rows_written']);
        foreach ($dataset['partitions']['row_counts'] as $partition => $count) {
            $this->assertGreaterThan(0, $count);
            $range = $dataset['diagnostics']['row_date_ranges'][$partition];
            $this->assertGreaterThanOrEqual($dataset['partitions'][$partition.'_start'], $range['start']);
            $this->assertLessThanOrEqual($dataset['partitions'][$partition.'_end'], $range['end']);
        }

        File::deleteDirectory($directory);
    }

    public function test_partition_row_date_ranges_use_extrema_not_jsonl_write_order(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $laterIssuer = Stock::query()->create(['symbol' => 'LATER', 'exchange' => 'NSE', 'name' => 'Later Issuer']);
        $earlierIssuer = Stock::query()->create(['symbol' => 'EARLIER', 'exchange' => 'NSE', 'name' => 'Earlier Issuer']);

        foreach ([[$benchmark, '2025-01-20', 620], [$laterIssuer, '2025-04-01', 500], [$earlierIssuer, '2025-01-20', 620]] as [$subject, $start, $days]) {
            for ($day = 0; $day < $days; $day++) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse($start)->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $directory = storage_path('framework/testing/ml-range-extrema-'.bin2hex(random_bytes(4)));
        $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed('1m', Carbon::parse('2026-09-01'), $directory);
        $path = $dataset['paths']['train'];
        $dates = [];
        $physicalFirst = null;
        $handle = fopen($path, 'rb');
        while (($line = fgets($handle)) !== false) {
            $date = json_decode($line, true, 64, JSON_THROW_ON_ERROR)['reference_date'];
            $physicalFirst ??= $date;
            $dates[] = $date;
        }
        fclose($handle);

        $this->assertNotSame($physicalFirst, min($dates), 'The fixture must exercise non-chronological JSONL write order.');
        $this->assertSame(min($dates), $dataset['diagnostics']['row_date_ranges']['train']['start']);
        $this->assertSame(max($dates), $dataset['diagnostics']['row_date_ranges']['train']['end']);
        $this->assertSame(array_sum($dataset['partitions']['row_counts']), $dataset['diagnostics']['rows_written']);
        foreach ($dataset['partitions']['row_counts'] as $partition => $count) {
            $this->assertGreaterThan(0, $count);
        }

        File::deleteDirectory($directory);
    }

    public function test_label_horizon_separation_is_enforced_for_all_supported_horizons(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $issuer = Stock::query()->create(['symbol' => 'HORIZON', 'exchange' => 'NSE', 'name' => 'Horizon Issuer']);
        foreach ([$benchmark, $issuer] as $subject) {
            foreach (range(0, 1800) as $day) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse('2020-01-01')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        foreach (['1m', '3m', '6m'] as $horizon) {
            $directory = storage_path('framework/testing/ml-horizon-separation-'.str_replace('m', '', $horizon).'-'.bin2hex(random_bytes(4)));
            $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed($horizon, Carbon::parse('2024-12-31'), $directory);
            $partitionMonths = [];
            foreach ($dataset['paths'] as $partition => $path) {
                $this->assertGreaterThan(0, $dataset['partitions']['row_counts'][$partition]);
                $handle = fopen($path, 'rb');
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    if ($partition === 'train') {
                        $this->assertLessThan($dataset['partitions']['validation_start'], $row['label_end']);
                    }
                    if ($partition === 'validation') {
                        $this->assertLessThan($dataset['partitions']['test_start'], $row['label_end']);
                    }
                    $month = substr($row['reference_date'], 0, 7);
                    if (isset($partitionMonths[$month])) {
                        $this->assertSame($partition, $partitionMonths[$month]);
                    }
                    $partitionMonths[$month] = $partition;
                }
                fclose($handle);
            }
            $this->assertSame('chronological_monthly_sampling_buckets', $dataset['partitions']['split_basis']);
            $this->assertSame('v8-label-window-purge-1', $dataset['partitions']['purge_embargo']['version']);
            $this->assertSame(['1m' => 21, '3m' => 63, '6m' => 126][$horizon], $dataset['partitions']['purge_embargo']['horizon_observations']);
            $this->assertSame($dataset['partitions']['purge_embargo']['horizon_observations'], $dataset['partitions']['purge_embargo']['embargo_observations']);
            $this->assertLessThan($dataset['partitions']['validation_start'], $dataset['diagnostics']['train_max_label_end']);
            $this->assertLessThan($dataset['partitions']['test_start'], $dataset['diagnostics']['validation_max_label_end']);
            if ($horizon !== '1m') {
                $this->assertGreaterThan(0, $dataset['diagnostics']['train_purged_for_label_overlap']);
                $this->assertGreaterThan(0, $dataset['diagnostics']['validation_purged_for_label_overlap']);
            }
            File::deleteDirectory($directory);
        }
    }

    public function test_three_month_partitioning_adjusts_bucket_boundaries_before_purging(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $issuer = Stock::query()->create(['symbol' => 'THREEMONTH', 'exchange' => 'NSE', 'name' => 'Three Month Issuer']);
        foreach ([$benchmark, $issuer] as $subject) {
            foreach (range(0, 700) as $day) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse('2025-01-20')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $directory = storage_path('framework/testing/ml-horizon-aware-'.bin2hex(random_bytes(4)));
        $secondDirectory = storage_path('framework/testing/ml-horizon-aware-repeat-'.bin2hex(random_bytes(4)));
        $dataset = app(MlTrainingDatasetBuilder::class)->buildStreamed('3m', Carbon::parse('2026-09-30'), $directory);
        $repeat = app(MlTrainingDatasetBuilder::class)->buildStreamed('3m', Carbon::parse('2026-09-30'), $secondDirectory);
        $this->assertSame($dataset['partitions'], $repeat['partitions']);
        foreach (['train', 'validation', 'test'] as $partition) {
            $this->assertSame(hash_file('sha256', $dataset['paths'][$partition]), hash_file('sha256', $repeat['paths'][$partition]));
        }

        foreach (['train', 'validation', 'test'] as $partition) {
            $this->assertGreaterThan(0, $dataset['partitions']['row_counts'][$partition]);
        }
        $this->assertGreaterThan(0, $dataset['partitions']['horizon_aware']['boundary_adjustment_score']);
        $this->assertLessThan($dataset['partitions']['validation_start'], $dataset['diagnostics']['train_max_label_end']);
        $this->assertLessThan($dataset['partitions']['test_start'], $dataset['diagnostics']['validation_max_label_end']);

        File::deleteDirectory($directory);
        File::deleteDirectory($secondDirectory);
    }

    public function test_partition_metadata_matches_brute_force_reference_for_edge_cases_and_all_horizons(): void
    {
        $builder = app(MlTrainingDatasetBuilder::class);
        $partitionMetadata = new \ReflectionMethod($builder, 'partitionMetadata');
        $partitionMetadata->setAccessible(true);
        $cutoff = Carbon::parse('2030-12-31');

        $complexRows = $this->partitionRows([
            '2020-01-01', '2020-02-01', '2020-04-01', '2020-05-01',
            '2020-07-01', '2020-08-01', '2020-09-01', '2020-11-01',
            '2021-01-01', '2021-02-01', '2021-04-01', '2021-05-01',
        ], [1, 4, 2, 5, 1, 3, 5, 1, 3, 2, 6, 2], [7, 15, 2, 25, 3, 6, 1, 20, 4, 13, 2, 10]);
        $complexRows = array_reverse($complexRows, true);

        $tieRows = [];
        foreach ([80, 63, 122, 103, 59, 147, 78, 115, 118, 91, 120, 8] as $index => $offset) {
            $date = Carbon::parse('2020-01-01')->addMonthsNoOverflow($index)->toDateString();
            $tieRows[$date] = Carbon::parse($date)->addDays($offset)->toDateString();
        }

        $cases = [
            'varied bucket sizes, missing months, shuffled dates and nonmonotonic labels' => [
                'rows' => $complexRows,
                'tie_count' => null,
            ],
            'equal best-score candidates retain the earliest boundary pair' => [
                'rows' => $tieRows,
                'tie_count' => 2,
                'expected_train_end' => '2020-06',
                'expected_validation_end' => '2020-10',
            ],
            'label end equal to next partition start is not usable' => [
                'rows' => [
                    '2020-01-01' => '2020-02-01',
                    '2020-02-01' => '2020-02-15',
                    '2020-03-01' => '2020-03-15',
                ],
                'exception' => 'Insufficient horizon-aware chronological buckets for %d-observation labels: no train/validation/test allocation retains a usable row after label separation.',
            ],
            'no allocation can survive long overlapping labels' => [
                'rows' => [
                    '2020-01-01' => '2021-03-01',
                    '2020-02-01' => '2021-03-01',
                    '2020-03-01' => '2021-03-01',
                    '2020-04-01' => '2021-03-01',
                    '2020-05-01' => '2021-03-01',
                    '2020-06-01' => '2021-03-01',
                ],
                'exception' => 'Insufficient horizon-aware chronological buckets for %d-observation labels: no train/validation/test allocation retains a usable row after label separation.',
            ],
        ];

        foreach (['1m' => 21, '3m' => 63, '6m' => 126] as $horizon => $horizonDays) {
            foreach ($cases as $caseName => $case) {
                $reference = $this->bruteForcePartitionMetadata($case['rows'], $cutoff, $horizonDays);
                if (isset($case['exception'])) {
                    $expectedException = sprintf($case['exception'], $horizonDays);
                    $this->assertSame($expectedException, $reference['exception_message'] ?? null);
                    $actualException = null;
                    try {
                        $partitionMetadata->invoke($builder, $case['rows'], $cutoff, $horizonDays);
                    } catch (\RuntimeException $exception) {
                        $actualException = $exception;
                    }
                    $this->assertNotNull($actualException, "{$caseName} should have no valid partition allocation at {$horizon}.");
                    $this->assertSame($expectedException, $actualException->getMessage());
                    continue;
                }

                $actual = $partitionMetadata->invoke($builder, $case['rows'], $cutoff, $horizonDays);
                $this->assertSame($reference['metadata'], $actual, "Metadata differed for {$caseName} at {$horizon}.");
                if (isset($case['tie_count'])) {
                    $this->assertSame($case['tie_count'], $reference['best_score_tie_count']);
                    $this->assertSame($case['expected_train_end'], $actual['horizon_aware']['selected_train_end_bucket']);
                    $this->assertSame($case['expected_validation_end'], $actual['horizon_aware']['selected_validation_end_bucket']);
                }
            }
        }
    }

    /** @return array<string,string> */
    private function partitionRows(array $months, array $bucketSizes, array $baseOffsets): array
    {
        $rows = [];
        foreach ($months as $index => $month) {
            for ($day = 0; $day < $bucketSizes[$index]; $day++) {
                $date = Carbon::parse($month)->addDays($day)->toDateString();
                $offset = $baseOffsets[$index] + (($day * 11 + $index * 7) % 9);
                $rows[$date] = Carbon::parse($date)->addDays($offset)->toDateString();
            }
        }

        return $rows;
    }

    /**
     * Independent oracle: enumerate row-level usable candidates and sort all
     * candidates by score and boundary indices. It does not use prefix or
     * rolling minima like the production implementation.
     *
     * @param array<string,string> $referenceDates
     * @return array{metadata?:array<string,mixed>,best_score_tie_count:int,exception_message?:string}
     */
    private function bruteForcePartitionMetadata(array $referenceDates, Carbon $cutoff, int $horizonDays): array
    {
        $buckets = [];
        foreach ($referenceDates as $date => $labelEnd) {
            $month = substr($date, 0, 7);
            $buckets[$month]['dates'][] = $date;
            $buckets[$month]['rows'][] = ['reference_date' => $date, 'label_end' => $labelEnd];
        }
        ksort($buckets);
        foreach ($buckets as &$bucket) {
            sort($bucket['dates']);
        }
        unset($bucket);

        $months = array_keys($buckets);
        $count = count($months);
        if ($count < 3) {
            throw new \RuntimeException('Insufficient chronological sampling buckets.');
        }

        $nominalTrainEnd = max(0, min($count - 3, (int) floor($count * 0.70) - 1));
        $nominalValidationEnd = min($count - 2, max($nominalTrainEnd + 1, (int) floor($count * 0.85) - 1));
        $candidates = [];
        for ($trainEnd = 0; $trainEnd < $count - 2; $trainEnd++) {
            for ($validationEnd = $trainEnd + 1; $validationEnd < $count - 1; $validationEnd++) {
                $validationStart = $buckets[$months[$trainEnd + 1]]['dates'][0];
                $testStart = $buckets[$months[$validationEnd + 1]]['dates'][0];
                $trainUsable = false;
                for ($monthIndex = 0; $monthIndex <= $trainEnd && ! $trainUsable; $monthIndex++) {
                    foreach ($buckets[$months[$monthIndex]]['rows'] as $row) {
                        if ($row['label_end'] < $validationStart) {
                            $trainUsable = true;
                            break;
                        }
                    }
                }
                $validationUsable = false;
                for ($monthIndex = $trainEnd + 1; $monthIndex <= $validationEnd && ! $validationUsable; $monthIndex++) {
                    foreach ($buckets[$months[$monthIndex]]['rows'] as $row) {
                        if ($row['label_end'] < $testStart) {
                            $validationUsable = true;
                            break;
                        }
                    }
                }
                if ($trainUsable && $validationUsable) {
                    $candidates[] = [
                        'score' => abs($trainEnd - $nominalTrainEnd) + abs($validationEnd - $nominalValidationEnd),
                        'train_end_index' => $trainEnd,
                        'validation_end_index' => $validationEnd,
                    ];
                }
            }
        }
        usort($candidates, static function (array $left, array $right): int {
            return ($left['score'] <=> $right['score'])
                ?: ($left['train_end_index'] <=> $right['train_end_index'])
                ?: ($left['validation_end_index'] <=> $right['validation_end_index']);
        });
        if ($candidates === []) {
            return [
                'best_score_tie_count' => 0,
                'exception_message' => "Insufficient horizon-aware chronological buckets for {$horizonDays}-observation labels: no train/validation/test allocation retains a usable row after label separation.",
            ];
        }

        $best = $candidates[0];
        $tieCount = count(array_filter($candidates, static fn (array $candidate): bool => $candidate['score'] === $best['score']));
        $trainEndIndex = $best['train_end_index'];
        $validationEndIndex = $best['validation_end_index'];
        $trainEndMonth = $months[$trainEndIndex];
        $validationStartMonth = $months[$trainEndIndex + 1];
        $validationEndMonth = $months[$validationEndIndex];
        $testStartMonth = $months[$validationEndIndex + 1];
        $bucketPartitions = [];
        foreach ($months as $index => $month) {
            $bucketPartitions[$month] = $index <= $trainEndIndex ? 'train' : ($index <= $validationEndIndex ? 'validation' : 'test');
        }
        $firstDate = static function (string $month) use ($buckets): string {
            return $buckets[$month]['dates'][0];
        };
        $lastDate = static function (string $month) use ($buckets): string {
            return $buckets[$month]['dates'][array_key_last($buckets[$month]['dates'])];
        };

        return [
            'metadata' => [
                'train_start' => $firstDate($months[0]),
                'train_end' => $lastDate($trainEndMonth),
                'validation_start' => $firstDate($validationStartMonth),
                'validation_end' => $lastDate($validationEndMonth),
                'test_start' => $firstDate($testStartMonth),
                'test_end' => $lastDate($months[array_key_last($months)]),
                'cutoff_date' => $cutoff->toDateString(),
                'split_basis' => 'chronological_monthly_sampling_buckets',
                'sampling_buckets' => $bucketPartitions,
                'sampling' => [
                    'version' => 'v8-horizon-reference-1',
                    'cadence' => $horizonDays === 21 ? 'weekly' : 'monthly',
                    'anchor' => 'last_available_trading_day',
                    'horizon_days' => $horizonDays,
                ],
                'purge_embargo' => [
                    'version' => 'v8-label-window-purge-1',
                    'horizon_observations' => $horizonDays,
                    'rule' => 'exclude_rows_whose_forward_label_window_reaches_or_crosses_next_partition_start',
                    'train_boundary' => $validationStartMonth,
                    'validation_boundary' => $testStartMonth,
                    'embargo_observations' => $horizonDays,
                ],
                'horizon_aware' => [
                    'label_observations' => $horizonDays,
                    'nominal_train_end_bucket' => $months[$nominalTrainEnd],
                    'nominal_validation_end_bucket' => $months[$nominalValidationEnd],
                    'selected_train_end_bucket' => $trainEndMonth,
                    'selected_validation_end_bucket' => $validationEndMonth,
                    'boundary_adjustment_score' => $best['score'],
                ],
            ],
            'best_score_tie_count' => $tieCount,
        ];
    }

    public function test_horizon_aware_partitioning_reports_insufficient_history_precisely(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $issuer = Stock::query()->create(['symbol' => 'SHORT', 'exchange' => 'NSE', 'name' => 'Short History']);
        foreach ([$benchmark, $issuer] as $subject) {
            foreach (range(0, 260) as $day) {
                StockPrice::query()->create([
                    'stock_id' => $subject->id,
                    'price_date' => Carbon::parse('2025-01-20')->addDays($day)->toDateString(),
                    'close_price' => 100 + $day,
                    'adjusted_close_price' => 100 + $day,
                    'data_source' => 'test',
                ]);
            }
        }

        $directory = storage_path('framework/testing/ml-horizon-insufficient-'.bin2hex(random_bytes(4)));
        try {
            $this->expectExceptionMessage('63-observation labels');
            app(MlTrainingDatasetBuilder::class)->buildStreamed('3m', Carbon::parse('2025-12-31'), $directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
