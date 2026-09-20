<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlTrainingDatasetBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_is_chronological_and_does_not_label_past_the_cutoff(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'exchange' => 'NSE', 'name' => 'NIFTY 50', 'is_benchmark' => true]);
        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);

        app(FundamentalDataService::class)->storeFacts($stock, [
            [
                'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
                'period_end' => '2025-03-31', 'value' => 10, 'availability_date' => '2025-06-01',
            ],
            [
                'statement_type' => 'balance_sheet', 'cadence' => 'quarterly', 'fact_key' => 'equity',
                'period_end' => '2025-03-31', 'value' => 100, 'availability_date' => '2025-06-01',
            ],
        ], Carbon::parse('2025-06-01'));

        foreach ([$benchmark, $stock] as $subject) {
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

        $dataset = app(MlTrainingDatasetBuilder::class)->build('3m', Carbon::parse('2025-08-01'));
        $rows = collect($dataset['rows']);

        $this->assertNotEmpty($rows);
        $this->assertTrue($rows->every(fn (array $row): bool => $row['reference_date'] <= '2025-08-01'));
        $this->assertTrue($rows->every(fn (array $row): bool => $row['label_end'] <= '2025-08-01'));
        $this->assertTrue($rows->filter(fn (array $row): bool => $row['reference_date'] < '2025-06-01')->every(fn (array $row): bool => $row['features']['roe'] === null));
        $this->assertTrue($rows->filter(fn (array $row): bool => $row['reference_date'] >= '2025-06-01')->every(fn (array $row): bool => $row['features']['roe'] === 10.0));
        $this->assertSame($rows->sortBy('reference_date')->pluck('reference_date')->values()->all(), $rows->pluck('reference_date')->values()->all());
        $this->assertSame('2025-08-01', $dataset['partitions']['cutoff_date']);
        $this->assertSame('train', $rows->first()['partition']);
        $this->assertContains('test', $rows->pluck('partition')->unique()->all());
    }
}
