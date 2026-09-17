<?php

namespace Tests\Feature;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HoldingsOwnershipPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_holdings_payload_exposes_named_strategy_owners_and_keeps_sibling_rows_separate(): void
    {
        [$user, $profile, $strategyA, $strategyB] = $this->strategies();
        $stock = $this->stock();

        $this->buy($profile, $stock, 10, 100, Holding::ownerKeyFor((int) $strategyA->id));
        $this->buy($profile, $stock, 5, 120, Holding::ownerKeyFor((int) $strategyB->id));
        $this->buy($profile, $stock, 2, 90, null);

        $rows = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/holdings')
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);
        $this->assertSame($strategyA->name, collect($rows)->firstWhere('strategy_id', $strategyA->id)['strategy_name']);
        $this->assertSame($strategyB->name, collect($rows)->firstWhere('strategy_id', $strategyB->id)['strategy_name']);
        $this->assertTrue(collect($rows)->firstWhere('is_unmanaged', true)['is_unmanaged']);
        $this->assertSame('unmanaged', collect($rows)->firstWhere('is_unmanaged', true)['owner_key']);
        $this->assertSame($strategyA->id, collect($rows)->firstWhere('strategy_id', $strategyA->id)['strategy_id']);
    }

    public function test_archived_strategy_metadata_preserves_historical_owner_identity(): void
    {
        [$user, $profile, $strategy] = $this->strategies();
        $archived = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Archived Momentum',
            'slug' => 'archived_momentum_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_ARCHIVED,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $archived->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_SUPERSEDED,
        ]);
        $archived->forceFill(['active_version_id' => $version->id])->save();
        $stock = $this->stock();
        $this->buy($profile, $stock, 3, 100, Holding::ownerKeyFor((int) $archived->id));

        $row = collect($this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/holdings')
            ->assertOk()
            ->json('data'))->firstWhere('strategy_id', $archived->id);

        $this->assertSame('Archived Momentum', $row['strategy_name']);
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $row['strategy_status']);
        $this->assertFalse($row['is_unmanaged']);
    }

    /** @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy} */
    private function strategies(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $a = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $b = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Value Strategy',
            'slug' => 'value_strategy_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 50,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $b->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => $a->activeVersion?->config_json ?? ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $b->forceFill(['active_version_id' => $version->id])->save();
        $b = app(StrategyRegistrySupport::class)->activate($profile, $b);

        return [$user, $profile, $a->fresh(['activeVersion']), $b->fresh(['activeVersion'])];
    }

    private function stock(): Stock
    {
        $stock = Stock::query()->create([
            'symbol' => 'OWN'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Ownership Test Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'open_price' => 100,
            'high_price' => 100,
            'low_price' => 100,
            'close_price' => 100,
            'volume' => 100,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        return $stock;
    }

    private function buy(PortfolioProfile $profile, Stock $stock, float $quantity, float $price, ?string $ownerKey): void
    {
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $quantity,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->toDateString(),
            'source' => Transaction::SOURCE_MANUAL,
            'owner_key' => $ownerKey,
        ]);
    }
}
