<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlTrainingDatasetBuilder;
use App\Services\ML\MlSectorRelativeStrengthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlHistoricalSectorLeakageTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_categorical_sector_never_reads_current_stock_master(): void
    {
        $stock = Stock::query()->create(['symbol' => 'LEAK', 'exchange' => 'NSE', 'name' => 'Leak Co', 'sector' => 'Current Sector']);
        MlUniverseMembership::query()->create([
            'stock_id' => $stock->id, 'universe_key' => 'active_eligible_nse',
            'effective_from' => '2020-01-01', 'effective_to' => '2020-12-31',
            'sector_snapshot' => 'Historical Sector', 'source' => 'test', 'snapshot_key' => 'test:2020',
        ]);
        $builder = app(MlTrainingDatasetBuilder::class);
        $method = new \ReflectionMethod($builder, 'historicalSector');
        $method->setAccessible(true);

        $this->assertSame('Historical Sector', $method->invoke($builder, $stock, '2020-06-01'));
        $this->assertSame('__unknown', $method->invoke($builder, $stock, '2021-06-01'));
        $this->assertNull(app(MlSectorRelativeStrengthService::class)->relativeStrength3m($stock, '2021-06-01'));
    }
}
