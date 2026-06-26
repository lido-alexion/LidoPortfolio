<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PortfolioSnapshotRebuildService;
use App\Services\PriceFetchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortfolioSnapshotRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithBuy(): array
    {
        $user = User::query()->create([
            'name' => 'Rebuild User',
            'email' => 'rebuild-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'RB'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Rebuild Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 10,
            'transaction_date' => '2026-02-02',
        ]);

        foreach (['2026-02-02', '2026-02-03', '2026-02-04'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => 100,
                'high_price' => 105,
                'low_price' => 99,
                'close_price' => 100 + (int) substr($date, -1),
                'volume' => 1000,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
        }

        return [$user, $profile, $stock];
    }

    public function test_calculate_portfolio_state_for_historical_date(): void
    {
        [$user, $profile, $stock] = $this->createUserWithBuy();
        $service = app(PortfolioSnapshotRebuildService::class);

        $priceIndex = (new \ReflectionClass($service))
            ->getMethod('buildPriceIndex');
        $priceIndex->setAccessible(true);
        $index = $priceIndex->invoke(
            $service,
            [$stock->id],
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-02-04'),
        );

        $state = $service->calculatePortfolioStateForDate($profile,
            Carbon::parse('2026-02-02'),
            null,
            $index,
        );

        $this->assertEqualsWithDelta(1000, $state['invested_value'], 0.01);
        $this->assertEqualsWithDelta(1020, $state['portfolio_value'], 0.01);
    }

    public function test_rebuild_writes_trading_day_snapshots(): void
    {
        Carbon::setTestNow('2026-02-04 12:00:00');

        [$user, $profile] = $this->createUserWithBuy();
        $service = app(PortfolioSnapshotRebuildService::class);

        $result = $service->rebuildFromDate($profile, Carbon::parse('2026-02-02'));

        $this->assertGreaterThanOrEqual(3, $result['snapshots_written']);
        $feb2 = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereDate('snapshot_date', '2026-02-02')
            ->first();
        $this->assertNotNull($feb2);
        $this->assertEqualsWithDelta(1020, (float) $feb2->portfolio_value, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $feb2->invested_value, 0.01);

        $feb3 = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereDate('snapshot_date', '2026-02-03')
            ->first();
        $this->assertNotNull($feb3);
        $this->assertEqualsWithDelta(1030, (float) $feb3->portfolio_value, 0.01);

        Carbon::setTestNow();
    }

    public function test_rebuild_after_transaction_date_change_uses_earliest_date(): void
    {
        Carbon::setTestNow('2026-03-15 12:00:00');

        [$user, $profile, $stock] = $this->createUserWithBuy();

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-01-15',
            'open_price' => 90,
            'high_price' => 95,
            'low_price' => 88,
            'close_price' => 90,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        Transaction::query()->where('profile_id', $profile->id)->update([
            'transaction_date' => '2026-01-15',
        ]);

        app(PortfolioSnapshotRebuildService::class)->rebuildAfterTransactionChange($profile,
            '2026-02-01',
            '2026-01-15',
        );

        $snapshot = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereDate('snapshot_date', '2026-01-15')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEqualsWithDelta(900, (float) $snapshot->portfolio_value, 0.01);

        Carbon::setTestNow();
    }

    public function test_weekend_uses_nearest_previous_close(): void
    {
        [$user, $profile, $stock] = $this->createUserWithBuy();

        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => '2026-02-06',
            'open_price' => 110,
            'high_price' => 112,
            'low_price' => 108,
            'close_price' => 110,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        $service = app(PortfolioSnapshotRebuildService::class);
        $state = $service->calculatePortfolioStateForDate($profile, Carbon::parse('2026-02-07'));

        $this->assertEqualsWithDelta(1100, $state['portfolio_value'], 0.01);
    }

    public function test_rebuild_skips_weekend_snapshot_dates(): void
    {
        Carbon::setTestNow('2024-07-08 12:00:00');

        $user = User::query()->create([
            'name' => 'Weekend Rebuild User',
            'email' => 'weekend-rebuild-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        $stock = Stock::query()->create([
            'symbol' => 'WK'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Weekend Rebuild Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2024-07-01',
        ]);

        foreach ([
            ['2024-07-01', 100],
            ['2024-07-02', 100],
            ['2024-07-03', 100],
            ['2024-07-04', 100],
            ['2024-07-05', 102],
            ['2024-07-06', 50],
            ['2024-07-08', 104],
        ] as [$date, $close]) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'volume' => 1000,
                'data_source' => 'test',
                'provider_source' => 'test',
            ]);
        }

        PortfolioSnapshot::query()->create([
            'profile_id' => $profile->id,
            'snapshot_date' => '2024-07-06',
            'portfolio_value' => 500,
            'invested_value' => 500,
            'created_at' => now(),
        ]);

        $service = app(PortfolioSnapshotRebuildService::class);
        $service->rebuildFromDate($profile, Carbon::parse('2024-07-01'));

        $this->assertNull(
            PortfolioSnapshot::query()
                ->where('profile_id', $profile->id)
                ->whereDate('snapshot_date', '2024-07-06')
                ->first(),
        );

        $friday = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereDate('snapshot_date', '2024-07-05')
            ->first();
        $monday = PortfolioSnapshot::query()
            ->where('profile_id', $profile->id)
            ->whereDate('snapshot_date', '2024-07-08')
            ->first();

        $this->assertNotNull($friday);
        $this->assertNotNull($monday);
        $this->assertEqualsWithDelta(1020, (float) $friday->portfolio_value, 0.01);
        $this->assertEqualsWithDelta(1040, (float) $monday->portfolio_value, 0.01);
        $this->assertEqualsWithDelta(
            (float) $friday->invested_value,
            (float) $monday->invested_value,
            0.01,
        );

        Carbon::setTestNow();
    }

    public function test_price_fetch_skips_weekend_rows(): void
    {
        $stock = Stock::query()->create([
            'symbol' => 'WK'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Weekend Skip',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $stored = app(PriceFetchService::class)->storeHistoricalRows($stock, [
            [
                'price_date' => '2024-07-05',
                'open_price' => 100,
                'high_price' => 100,
                'low_price' => 100,
                'close_price' => 100,
                'volume' => 1,
            ],
            [
                'price_date' => '2024-07-06',
                'open_price' => 50,
                'high_price' => 50,
                'low_price' => 50,
                'close_price' => 50,
                'volume' => 1,
            ],
        ], 'test');

        $this->assertSame(1, $stored);
        $this->assertDatabaseMissing('portfolio_stock_prices', [
            'stock_id' => $stock->id,
            'price_date' => '2024-07-06',
        ]);
    }

    public function test_rebuild_history_api_requires_auth(): void
    {
        $this->postJson('/api/portfolio/rebuild-history')->assertUnauthorized();
    }

    public function test_rebuild_history_api_for_authenticated_user(): void
    {
        Carbon::setTestNow('2026-02-04 12:00:00');
        [$user, $profile] = $this->createUserWithBuy();

        $response = $this->actingAs($user)->postJson('/api/portfolio/rebuild-history', [
            'from_date' => '2026-02-01',
        ]);

        $response->assertOk()
            ->assertJsonPath('rebuild.snapshots_written', fn ($v) => $v >= 1);

        Carbon::setTestNow();
    }
}


