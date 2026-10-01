<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V8\MlAcceptanceSource;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MlVerifiedSessionSamplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_holiday_price_rows_are_ignored_but_a_sealed_special_session_is_kept(): void
    {
        $benchmark = Stock::query()->create(['symbol' => 'NIFTY50', 'name' => 'Nifty 50', 'exchange' => 'NSE', 'is_benchmark' => true, 'is_active' => true]);
        $stock = Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'is_benchmark' => false, 'is_active' => true]);
        foreach (Carbon::parse('2022-01-03')->daysUntil(Carbon::parse('2023-07-01')) as $day) {
            if ($day->isWeekend() || in_array($day->toDateString(), ['2023-02-27', '2023-02-28'], true)) {
                continue;
            }
            foreach ([$benchmark, $stock] as $subject) {
                if ($subject->id === $benchmark->id && $day->toDateString() === '2022-08-31') {
                    continue;
                }
                StockPrice::query()->create(['stock_id' => $subject->id, 'price_date' => $day->toDateString(), 'close_price' => 100, 'data_source' => 'test']);
            }
        }
        StockPrice::query()->create(['stock_id' => $stock->id, 'price_date' => '2023-02-25', 'close_price' => 100, 'data_source' => 'test']);

        $builder = app(MlTrainingDatasetBuilder::class);
        $before = $builder->requiredReferenceDates('3m', Carbon::parse('2023-06-30'));
        $this->assertArrayHasKey('2022-08-30', $before);
        $this->assertArrayNotHasKey('2022-08-31', $before);
        $this->assertArrayHasKey('2023-02-24', $before);
        $this->assertArrayNotHasKey('2023-02-25', $before);

        MlAcceptanceSource::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => 1, 'status' => 'sealed',
            'manifest' => ['source' => 'nse_cash_bhavcopy', 'date' => '2023-02-25'],
            'evidence' => ['validated_date' => '2023-02-25'], 'history' => [],
        ]);
        $after = $builder->requiredReferenceDates('3m', Carbon::parse('2023-06-30'));
        $this->assertArrayHasKey('2023-02-25', $after);
        $this->assertArrayNotHasKey('2023-02-24', $after);
    }
}
