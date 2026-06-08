<?php

namespace Tests\Feature;

use App\Models\PortfolioSnapshot;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PortfolioSnapshotRebuildService;
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

        $stock = Stock::query()->create([
            'symbol' => 'RB'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Rebuild Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        Transaction::query()->create([
            'user_id' => $user->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 10,
            'transaction_date' => '2026-02-01',
        ]);

        foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $date) {
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

        return [$user, $stock];
    }

    public function test_calculate_portfolio_state_for_historical_date(): void
    {
        [$user, $stock] = $this->createUserWithBuy();
        $service = app(PortfolioSnapshotRebuildService::class);

        $priceIndex = (new \ReflectionClass($service))
            ->getMethod('buildPriceIndex');
        $priceIndex->setAccessible(true);
        $index = $priceIndex->invoke(
            $service,
            [$stock->id],
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-02-03'),
        );

        $state = $service->calculatePortfolioStateForDate(
            $user,
            Carbon::parse('2026-02-02'),
            null,
            $index,
        );

        $this->assertEqualsWithDelta(1010, $state['invested_value'], 0.01);
        $this->assertEqualsWithDelta(1020, $state['portfolio_value'], 0.01);
    }

    public function test_rebuild_writes_trading_day_snapshots(): void
    {
        Carbon::setTestNow('2026-02-03 12:00:00');

        [$user] = $this->createUserWithBuy();
        $service = app(PortfolioSnapshotRebuildService::class);

        $result = $service->rebuildFromDate($user, Carbon::parse('2026-02-01'));

        $this->assertGreaterThanOrEqual(3, $result['snapshots_written']);
        $feb1 = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('snapshot_date', '2026-02-01')
            ->first();
        $this->assertNotNull($feb1);
        $this->assertEqualsWithDelta(1010, (float) $feb1->portfolio_value, 0.01);
        $this->assertEqualsWithDelta(1010, (float) $feb1->invested_value, 0.01);

        $feb2 = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('snapshot_date', '2026-02-02')
            ->first();
        $this->assertNotNull($feb2);
        $this->assertEqualsWithDelta(1020, (float) $feb2->portfolio_value, 0.01);

        Carbon::setTestNow();
    }

    public function test_rebuild_after_transaction_date_change_uses_earliest_date(): void
    {
        Carbon::setTestNow('2026-03-15 12:00:00');

        [$user, $stock] = $this->createUserWithBuy();

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

        Transaction::query()->where('user_id', $user->id)->update([
            'transaction_date' => '2026-01-15',
        ]);

        app(PortfolioSnapshotRebuildService::class)->rebuildAfterTransactionChange(
            $user,
            '2026-02-01',
            '2026-01-15',
        );

        $snapshot = PortfolioSnapshot::query()
            ->where('user_id', $user->id)
            ->whereDate('snapshot_date', '2026-01-15')
            ->first();

        $this->assertNotNull($snapshot);
        $this->assertEqualsWithDelta(900, (float) $snapshot->portfolio_value, 0.01);

        Carbon::setTestNow();
    }

    public function test_weekend_uses_nearest_previous_close(): void
    {
        [$user, $stock] = $this->createUserWithBuy();

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
        $state = $service->calculatePortfolioStateForDate($user, Carbon::parse('2026-02-07'));

        $this->assertEqualsWithDelta(1100, $state['portfolio_value'], 0.01);
    }

    public function test_rebuild_history_api_requires_auth(): void
    {
        $this->postJson('/api/portfolio/rebuild-history')->assertUnauthorized();
    }

    public function test_rebuild_history_api_for_authenticated_user(): void
    {
        Carbon::setTestNow('2026-02-03 12:00:00');
        [$user] = $this->createUserWithBuy();

        $response = $this->actingAs($user)->postJson('/api/portfolio/rebuild-history', [
            'from_date' => '2026-02-01',
        ]);

        $response->assertOk()
            ->assertJsonPath('rebuild.snapshots_written', fn ($v) => $v >= 1);

        Carbon::setTestNow();
    }
}
