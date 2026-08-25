<?php

namespace Tests\Unit;

use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CorporateActionService;
use App\Services\HoldingsCalculationService;
use App\Services\Risk\OwnershipEpisodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OD-10 — corporate-action quantity follows parent owner (WS1 residual / §34.1).
 */
class CorporateActionOd10OwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_case1_single_owner_bonus_unchanged(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10S');

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 50,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $lots = app(HoldingsCalculationService::class)->recalculateOwnerLotsForProfileStock($profile, $stock);
        $this->assertCount(1, $lots);
        $this->assertSame(Holding::OWNER_UNMANAGED, $lots->first()->owner_key);
        $this->assertSame('200.0000', $lots->first()->quantity);
        $this->assertSame('200.0000', $result['holding']->quantity);

        $bonusTxs = Transaction::query()
            ->where('stock_id', $stock->id)
            ->where('source', Transaction::SOURCE_BONUS)
            ->get();
        $this->assertCount(1, $bonusTxs);
        $this->assertNull($bonusTxs->first()->recommendation_id);
    }

    public function test_case2_two_strategies_bonus_stays_owner_scoped(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10B');
        $strategyA = $this->makeStrategy($profile, 'Strategy A');
        $strategyB = $this->makeStrategy($profile, 'Strategy B');
        $recA = $this->makeRec($profile, $stock, $strategyA->activeVersion);
        $recB = $this->makeRec($profile, $stock, $strategyB->activeVersion);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'recommendation_id' => $recA->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
            'recommendation_id' => $recB->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);

        $preview = app(CorporateActionService::class)->preview($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertTrue($preview['ownership_attributable']);
        $this->assertSame(150.0, $preview['eligible_quantity']);
        $this->assertSame(150.0, $preview['bonus_quantity']);
        $this->assertCount(2, $preview['owner_allocations']);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $lots = app(HoldingsCalculationService::class)
            ->recalculateOwnerLotsForProfileStock($profile, $stock)
            ->keyBy('owner_key');

        $this->assertCount(2, $lots);
        $this->assertFalse($lots->has(Holding::OWNER_UNMANAGED));
        $this->assertSame('200.0000', $lots->get(Holding::ownerKeyFor($strategyA->id))->quantity);
        $this->assertSame('100.0000', $lots->get(Holding::ownerKeyFor($strategyB->id))->quantity);

        $bonusByRec = Transaction::query()
            ->where('stock_id', $stock->id)
            ->where('source', Transaction::SOURCE_BONUS)
            ->get()
            ->keyBy('recommendation_id');

        $this->assertCount(2, $bonusByRec);
        $this->assertEquals(100.0, (float) $bonusByRec->get($recA->id)->quantity);
        $this->assertEquals(50.0, (float) $bonusByRec->get($recB->id)->quantity);
    }

    public function test_case3_two_strategies_split_stays_owner_scoped(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10P');
        $strategyA = $this->makeStrategy($profile, 'Strategy A');
        $strategyB = $this->makeStrategy($profile, 'Strategy B');
        $recA = $this->makeRec($profile, $stock, $strategyA->activeVersion);
        $recB = $this->makeRec($profile, $stock, $strategyB->activeVersion);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'recommendation_id' => $recA->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
            'recommendation_id' => $recB->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $lots = app(HoldingsCalculationService::class)
            ->recalculateOwnerLotsForProfileStock($profile, $stock)
            ->keyBy('owner_key');

        $this->assertCount(2, $lots);
        $this->assertFalse($lots->has(Holding::OWNER_UNMANAGED));
        $this->assertSame('200.0000', $lots->get(Holding::ownerKeyFor($strategyA->id))->quantity);
        $this->assertSame('100.0000', $lots->get(Holding::ownerKeyFor($strategyB->id))->quantity);

        $txA = Transaction::query()->where('recommendation_id', $recA->id)->firstOrFail();
        $txB = Transaction::query()->where('recommendation_id', $recB->id)->firstOrFail();
        $this->assertEquals(200.0, (float) $txA->quantity);
        $this->assertEquals(100.0, (float) $txB->quantity);
        $this->assertEquals(20.0, (float) $txA->price);
        $this->assertEquals(20.0, (float) $txB->price);
    }

    public function test_case4_downstream_holdings_no_artificial_unmanaged_ca_lot(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10H');
        $strategyA = $this->makeStrategy($profile, 'Strategy A');
        $strategyB = $this->makeStrategy($profile, 'Strategy B');

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'recommendation_id' => $this->makeRec($profile, $stock, $strategyA->activeVersion)->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
            'recommendation_id' => $this->makeRec($profile, $stock, $strategyB->activeVersion)->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertCount(2, $result['holdings']);
        $ownerKeys = collect($result['holdings'])->pluck('owner_key')->all();
        $this->assertNotContains(Holding::OWNER_UNMANAGED, $ownerKeys);

        $dbLots = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->get();
        $this->assertCount(2, $dbLots);
        $this->assertSame(0, $dbLots->where('owner_key', Holding::OWNER_UNMANAGED)->count());
    }

    public function test_case5_downstream_risk_isolation_after_bonus(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10R');
        $strategyA = $this->makeStrategy($profile, 'Strategy A');
        $strategyB = $this->makeStrategy($profile, 'Strategy B');
        $recA = $this->makeRec($profile, $stock, $strategyA->activeVersion);
        $recB = $this->makeRec($profile, $stock, $strategyB->activeVersion);

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'recommendation_id' => $recA->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
            'recommendation_id' => $recB->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $lots = app(HoldingsCalculationService::class)
            ->recalculateOwnerLotsForProfileStock($profile, $stock)
            ->keyBy('owner_key');

        $holdingA = $lots->get(Holding::ownerKeyFor($strategyA->id));
        $holdingB = $lots->get(Holding::ownerKeyFor($strategyB->id));

        $episode = app(OwnershipEpisodeService::class);
        $fillsA = $episode->fillsForCurrentEpisode($profile, $holdingA, $stock);
        $fillsB = $episode->fillsForCurrentEpisode($profile, $holdingB, $stock);

        $qtyA = array_sum(array_column($fillsA, 'quantity'));
        $qtyB = array_sum(array_column($fillsB, 'quantity'));

        // Bonus shares (price 0) remain in the owning strategy's episode; no cross-owner bleed.
        $this->assertEqualsWithDelta(200.0, $qtyA, 0.0001);
        $this->assertEqualsWithDelta(100.0, $qtyB, 0.0001);
        $this->assertSame('200.0000', $holdingA->quantity);
        $this->assertSame('100.0000', $holdingB->quantity);
    }

    public function test_case6_ambiguous_ownership_keeps_blended_unmanaged_bonus(): void
    {
        [$profile, $stock] = $this->profileAndStock('OD10X');
        $strategyA = $this->makeStrategy($profile, 'Strategy A');
        $strategyB = $this->makeStrategy($profile, 'Strategy B');

        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 100,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
            'recommendation_id' => $this->makeRec($profile, $stock, $strategyA->activeVersion)->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 50,
            'price' => 40,
            'fees' => 0,
            'transaction_date' => '2026-01-02',
            'recommendation_id' => $this->makeRec($profile, $stock, $strategyB->activeVersion)->id,
            'source' => Transaction::SOURCE_RECOMMENDATION,
        ]);
        // Sell without recommendation while two owners are open → not attributable.
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'sell',
            'quantity' => 10,
            'price' => 45,
            'fees' => 0,
            'transaction_date' => '2026-02-01',
        ]);

        $preview = app(CorporateActionService::class)->preview($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertFalse($preview['ownership_attributable']);
        $this->assertSame(140.0, $preview['eligible_quantity']);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $bonusTxs = Transaction::query()
            ->where('stock_id', $stock->id)
            ->where('source', Transaction::SOURCE_BONUS)
            ->get();
        $this->assertCount(1, $bonusTxs);
        $this->assertNull($bonusTxs->first()->recommendation_id);
        $this->assertEquals(140.0, (float) $bonusTxs->first()->quantity);

        $lots = app(HoldingsCalculationService::class)->recalculateOwnerLotsForProfileStock($profile, $stock);
        $this->assertCount(1, $lots);
        $this->assertSame(Holding::OWNER_UNMANAGED, $lots->first()->owner_key);
        $this->assertSame('280.0000', $lots->first()->quantity);
    }

    /**
     * @return array{0: PortfolioProfile, 1: Stock}
     */
    protected function profileAndStock(string $symbol): array
    {
        $user = User::query()->create([
            'name' => 'OD10 User',
            'email' => 'od10-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol.' Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$profile, $stock];
    }

    protected function makeStrategy(PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => [],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makeRec(
        PortfolioProfile $profile,
        Stock $stock,
        TradingStrategyVersion $version,
    ): TradingRecommendation {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now()->subDays(10),
        ]);
    }
}
