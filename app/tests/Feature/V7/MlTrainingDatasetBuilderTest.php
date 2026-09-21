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
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $inactive = Stock::query()->create(['symbol' => 'OLDCO', 'exchange' => 'NSE', 'name' => 'Old Co', 'is_active' => false]);
        $inactiveTwo = Stock::query()->create(['symbol' => 'OLDCO2', 'exchange' => 'NSE', 'name' => 'Old Co 2', 'is_active' => false]);

        app(FundamentalDataService::class)->storeFacts($stock, [
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2025-03-31', 'value' => 10, 'availability_date' => '2025-06-01',
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
        $this->assertTrue($stockRows->filter(fn (array $row): bool => $row['reference_date'] < '2025-06-01')->every(fn (array $row): bool => $row['features']['roe'] === null));
        $this->assertEquals([10.0], $stockRows->filter(fn (array $row): bool => $row['reference_date'] >= '2025-06-01')->pluck('features.roe')->unique()->values()->all());
        $juneRow = $stockRows->firstWhere('reference_date', '2025-06-30');
        $legacyRoe = app(FundamentalDataService::class)->metric($stock, 'roe', 'ttm', Carbon::parse('2025-06-30'));
        $legacyGrowth = app(FundamentalDataService::class)->growthMetric($stock, 'revenue', 'quarterly', Carbon::parse('2025-06-30'));
        $this->assertEquals($legacyRoe['value'], $juneRow['features']['roe']);
        $this->assertEquals($legacyGrowth['value'], $juneRow['features']['revenue_growth_proxy']);
        $this->assertSame($rows->sortBy('reference_date')->pluck('reference_date')->values()->all(), $rows->pluck('reference_date')->values()->all());
        $this->assertSame('2025-08-01', $dataset['partitions']['cutoff_date']);
        $this->assertSame('train', $rows->first()['partition']);
        $this->assertContains('test', $rows->pluck('partition')->unique()->all());
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['stock_id'] === $inactive->id));
        $groups = $rows->groupBy(fn (array $row): string => $row['stock_id'].'|'.substr($row['reference_date'], 0, 7));
        $this->assertTrue($groups->every(fn ($group): bool => $group->count() === 1));
        foreach ($rows as $row) {
            $month = substr($row['reference_date'], 0, 7);
            $lastAvailable = StockPrice::query()
                ->where('stock_id', $row['stock_id'])
                ->whereDate('price_date', '>=', $month.'-01')
                ->whereDate('price_date', '<=', Carbon::parse($month.'-01')->endOfMonth()->toDateString())
                ->whereDate('price_date', '<=', '2025-08-01')
                ->max('price_date');
            $this->assertSame(Carbon::parse($lastAvailable)->toDateString(), $row['reference_date']);
        }
        $this->assertSame('v7-monthly-reference-1', $dataset['feature_definitions']['sampling']['version']);
        $this->assertLessThanOrEqual(6, $dataset['diagnostics']['peak_buffered_rows']);

        $plan = app(MlTrainingDatasetBuilder::class)->plan('1m', Carbon::parse('2025-08-01'));
        $this->assertSame(3, $plan['stock_count']);
        $this->assertSame('monthly', $plan['sampling']['cadence']);
        $this->assertGreaterThan(0, $plan['estimated_reference_rows']);
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
        $this->assertLessThan(30, $queries, 'Dataset construction regressed to per-reference SQL queries.');
        $this->assertLessThanOrEqual(6, $dataset['diagnostics']['peak_buffered_rows']);
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

        $this->assertSame(20, $dataset['diagnostics']['stocks_processed']);
        $this->assertGreaterThan(100, $dataset['diagnostics']['rows_written']);
        $this->assertLessThanOrEqual(20, $dataset['diagnostics']['peak_buffered_rows']);
        $this->assertLessThan(80, $queries);
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

        $this->assertSame('2025-01-31', $dataset['diagnostics']['viable_reference_date_start']);
        $this->assertSame('2026-06-30', $dataset['diagnostics']['viable_reference_date_end']);
        $this->assertSame(18, $dataset['diagnostics']['viable_reference_date_count']);
        $this->assertSame($dataset['diagnostics']['benchmark_start_date'], '2025-01-20');
        $this->assertSame(['train' => 12, 'validation' => 3, 'test' => 3], $dataset['partitions']['row_counts']);
        foreach ($dataset['partitions']['row_counts'] as $partition => $count) {
            $this->assertGreaterThan(0, $count);
            $range = $dataset['diagnostics']['row_date_ranges'][$partition];
            $this->assertGreaterThanOrEqual($dataset['partitions'][$partition.'_start'], $range['start']);
            $this->assertLessThanOrEqual($dataset['partitions'][$partition.'_end'], $range['end']);
        }

        File::deleteDirectory($directory);
    }
}
