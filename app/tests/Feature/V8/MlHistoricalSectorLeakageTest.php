<?php

namespace Tests\Feature\V8;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlFeatureRegistryService;
use App\Services\ML\MlSectorRelativeStrengthService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlHistoricalSectorLeakageTest extends TestCase
{
    use RefreshDatabase;

    public function test_training_features_ignore_current_sector_changes_with_sufficient_price_history(): void
    {
        $stock = Stock::query()->create(['symbol' => 'HISTORY', 'exchange' => 'NSE', 'name' => 'History', 'sector' => 'Today']);
        $peer = Stock::query()->create(['symbol' => 'PEER', 'exchange' => 'NSE', 'name' => 'Peer', 'sector' => 'Today']);
        foreach ([$stock, $peer] as $member) {
            MlUniverseMembership::query()->create([
                'stock_id' => $member->id, 'universe_key' => 'active_eligible_nse',
                'effective_from' => '2020-01-01', 'effective_to' => '2020-12-31',
                'sector_snapshot' => 'Dated', 'source' => 'fixture',
            ]);
            MlUniverseMembership::query()->create([
                'stock_id' => $member->id, 'universe_key' => 'active_eligible_nse',
                'effective_from' => '2021-01-01', 'sector_snapshot' => null, 'source' => 'fixture',
            ]);
            foreach (range(0, 120) as $offset) {
                StockPrice::query()->create([
                    'stock_id' => $member->id, 'price_date' => Carbon::parse('2020-10-01')->addDays($offset)->toDateString(),
                    'data_source' => 'fixture',
                    'close_price' => 100 + $offset * ($member->id === $stock->id ? 2 : 1),
                ]);
            }
        }
        // Resolve a fresh builder for each evaluation so memoized features cannot mask leakage.
        $features = function (string $date) use ($stock): array {
            $builder = app(MlTrainingDatasetBuilder::class);
            $prices = StockPrice::query()->where('stock_id', $stock->id)
                ->whereDate('price_date', '<=', $date)->orderBy('price_date')
                ->get(['close_price', 'price_date'])
                ->mapWithKeys(fn (StockPrice $row): array => [
                    Carbon::parse($row->price_date)->toDateString() => (float) $row->close_price,
                ])->all();

            return (new \ReflectionMethod($builder, 'featuresForPrices'))->invoke(
                $builder, $stock->fresh(), $date, $prices, [], [], array_flip(array_keys($prices)), [],
            );
        };
        $dated = $features('2020-12-31');
        $this->assertSame('Dated', $dated['sector']);
        $this->assertEqualsWithDelta(126 / 156 * 100 - 63 / 128 * 100, $dated['sector_relative_strength_3m'], 0.000001);
        foreach ([['Today', 'Today'], ['Changed stock', 'Today'], ['Changed stock', 'Changed peer']] as [$stockSector, $peerSector]) {
            $stock->update(['sector' => $stockSector]);
            $peer->update(['sector' => $peerSector]);
            $this->assertSame($dated, $features('2020-12-31'));
            $publicDated = app(MlTrainingDatasetBuilder::class)->featuresFor($stock->fresh(), Carbon::parse('2020-12-31'));
            $this->assertSame('Dated', $publicDated['sector']);
            $this->assertSame($dated['sector_relative_strength_3m'], $publicDated['sector_relative_strength_3m']);
            $missing = $features('2021-01-04');
            $this->assertNotNull($missing['price_return_3m']);
            $this->assertSame('__unknown', $missing['sector']);
            $this->assertNull($missing['sector_relative_strength_3m']);
            $public = app(MlTrainingDatasetBuilder::class)->featuresFor($stock->fresh(), Carbon::parse('2021-01-04'));
            $this->assertSame('__unknown', $public['sector']);
            $this->assertNull($public['sector_relative_strength_3m']);
        }
        // Same date and prices, with only dated sector evidence supplied, must produce a value.
        MlUniverseMembership::query()->where('effective_from', '2021-01-01')->update(['sector_snapshot' => 'Dated']);
        $control = $features('2021-01-04');
        $this->assertSame('Dated', $control['sector']);
        $this->assertEqualsWithDelta(126 / 164 * 100 - 63 / 132 * 100, $control['sector_relative_strength_3m'], 0.000001);
    }

    public function test_sector_registry_contract_is_evidence_required_for_every_horizon(): void
    {
        foreach (['1m', '3m', '6m'] as $horizon) {
            $profile = app(MlFeatureRegistryService::class)->resolveFeatureProfile($horizon);
            $this->assertSame('v8-registry-13', $profile['registry_version']);
            foreach (['sector', 'sector_relative_strength_3m'] as $key) {
                $metadata = $profile['feature_metadata'][$key];
                $this->assertSame('challenger', $metadata['tier']);
                $this->assertSame('evidence_required', $metadata['coverage_class']);
                $this->assertTrue($metadata['evidence_required']);
                $this->assertStringContainsString('sector_snapshot', $metadata['data_source']);
            }
        }
    }

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
