<?php

namespace Tests\Unit;

use App\Models\CapitalRequest;
use App\Models\CashLedgerEntry;
use App\Models\CorporateAction;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CorporateActionService;
use App\Services\HoldingPresentationService;
use App\Services\Ownership\HoldingAdoptionService;
use App\Services\Risk\OwnershipEpisodeService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * V4-SPEC-003 — split/bonus restatement of a Strategy position (qty, cost, avg, stop, target, trailing).
 */
class CorporateActionSpec003RestatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_split_restates_strategy_quantity_cost_avg_stop_target_and_trailing(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $entry = '2026-01-10';
        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 100, 100, $entry, 10_000);
        $this->seedPrices($stock, [
            '2026-01-20' => 100,
            '2026-02-01' => 150,
            '2026-03-01' => 58,
        ]);

        $txCount = Transaction::query()->where('profile_id', $profile->id)->count();
        $cashCount = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();
        $beforeEntry = app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $dest, $stock)
            ?->toDateString();
        $this->assertSame($entry, $beforeEntry);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $holding = Holding::query()->findOrFail($dest->id);
        $this->assertEqualsWithDelta(200.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, (float) $holding->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, (float) $holding->target_amount, 0.0001);
        $this->assertSame((int) $strategy->id, (int) $holding->strategy_id);

        $summary = app(HoldingPresentationService::class)->enrichHolding($profile, $holding)['stoploss_summary'];
        $this->assertEqualsWithDelta(45.0, (float) $summary['stop_loss_price'], 0.0001);
        $this->assertEqualsWithDelta(75.0, (float) $summary['highest_close_since_buy'], 0.0001);
        $this->assertEqualsWithDelta(63.75, (float) $summary['trailing_stop_price'], 0.0001);
        $this->assertSame($entry, $summary['first_buy_date']);
        $this->assertSame('2026-02-01', $summary['highest_close_since_buy_date']);

        $this->assertSame($txCount, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($cashCount, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertFalse($result['idempotent']);
        $this->assertNoOpenIncreaseFromCorporateAction($stock);
        $this->assertSame(0, CapitalRequest::query()->where('profile_id', $profile->id)->count());
        $this->assertFalse(
            app(\App\Services\Entry\StrategyPositionTargetService::class)->isBuyCooldownActive(
                $profile,
                (int) $stock->id,
                (int) $strategy->id,
            )
        );
    }

    public function test_bonus_restates_strategy_quantity_preserving_cost_basis_and_price_levels(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $entry = '2026-01-10';
        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 100, 100, $entry, 10_000);
        $this->seedPrices($stock, [
            '2026-01-20' => 100,
            '2026-02-01' => 150,
            '2026-03-01' => 58,
        ]);
        $txCount = Transaction::query()->where('profile_id', $profile->id)->count();
        $cashCount = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'bonus',
            'ratio_from' => 1,
            'ratio_to' => 1,
            'ex_date' => '2026-03-01',
        ]);

        $holding = Holding::query()->findOrFail($dest->id);
        $this->assertEqualsWithDelta(200.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, (float) $holding->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $holding->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(10_000.0, (float) $holding->target_amount, 0.0001);

        $summary = app(HoldingPresentationService::class)->enrichHolding($profile, $holding)['stoploss_summary'];
        $this->assertEqualsWithDelta(45.0, (float) $summary['stop_loss_price'], 0.0001);
        $this->assertEqualsWithDelta(75.0, (float) $summary['highest_close_since_buy'], 0.0001);
        $this->assertEqualsWithDelta(63.75, (float) $summary['trailing_stop_price'], 0.0001);
        $this->assertSame($entry, $summary['first_buy_date']);
        $this->assertSame('2026-02-01', $summary['highest_close_since_buy_date']);

        $bonus = Transaction::query()
            ->where('profile_id', $profile->id)
            ->where('source', Transaction::SOURCE_BONUS)
            ->get();
        $this->assertCount(1, $bonus);
        $this->assertEqualsWithDelta(0.0, (float) $bonus->first()->price, 0.0001);
        $this->assertSame($txCount + 1, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($cashCount, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, CashLedgerEntry::query()->where('profile_id', $profile->id)->where('entry_type', CashLedgerEntry::TYPE_BUY)->count());
        $this->assertNoOpenIncreaseFromCorporateAction($stock);
    }

    public function test_other_strategy_and_unrelated_stock_remain_untouched(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $other = $this->makeStrategy($profile, 'Other');
        app(StrategyRegistrySupport::class)->activate($profile, $other);
        $otherStock = $this->makeStock('OTHR');

        $this->strategyOwnedHolding($profile, $strategy, $stock, 100, 100, '2026-01-10', 10_000);
        $otherLot = $this->strategyOwnedHolding($profile, $other, $otherStock, 7, 900, '2026-01-11', 6_300);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $otherLot->refresh();
        $this->assertEqualsWithDelta(7.0, (float) $otherLot->quantity, 0.0001);
        $this->assertEqualsWithDelta(900.0, (float) $otherLot->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(6_300.0, (float) $otherLot->target_amount, 0.0001);
        $this->assertSame((int) $other->id, (int) $otherLot->strategy_id);
    }

    public function test_already_applied_split_is_idempotent(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 100, 100, '2026-01-10', 10_000);

        $first = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);
        $this->assertFalse($first['idempotent']);

        $second = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertTrue($second['idempotent']);
        $this->assertSame((int) $first['corporate_action']->id, (int) $second['corporate_action']->id);
        $this->assertSame(1, CorporateAction::query()->where('stock_id', $stock->id)->count());
        $holding = Holding::query()->findOrFail($dest->id);
        $this->assertEqualsWithDelta(200.0, (float) $holding->quantity, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $holding->avg_buy_price, 0.0001);
    }

    public function test_invalid_ratio_is_rejected(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $this->strategyOwnedHolding($profile, $strategy, $stock, 10, 100, '2026-01-10', 1_000);

        $this->expectException(InvalidArgumentException::class);
        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 0,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);
    }

    public function test_api_rejects_invalid_ratio(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $this->strategyOwnedHolding($profile, $strategy, $stock, 10, 100, '2026-01-10', 1_000);

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/corporate-actions', [
                'stock_id' => $stock->id,
                'action_type' => 'split',
                'ratio_from' => 0,
                'ratio_to' => 2,
                'ex_date' => '2026-03-01',
            ])
            ->assertStatus(422);
    }

    public function test_spec001_merged_position_can_be_restated_by_split(): void
    {
        [$user, $profile, $strategy, $stock] = $this->strategyPortfolio();
        $dest = $this->strategyOwnedHolding($profile, $strategy, $stock, 50, 1000, '2026-01-10', 50_000);
        $unmanaged = $this->unmanagedHoldingWithBuy($profile, $stock, 100, 1200, '2026-06-01');
        app(HoldingAdoptionService::class)->adopt($profile, $unmanaged, (int) $strategy->id, $user);

        $merged = Holding::query()->findOrFail($dest->id);
        $this->assertEqualsWithDelta(150.0, (float) $merged->quantity, 0.0001);
        $this->assertEqualsWithDelta(170_000.0, (float) $merged->invested_amount, 0.0001);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-07-01',
        ]);

        $restated = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('owner_key', Holding::ownerKeyFor((int) $strategy->id))
            ->firstOrFail();

        $this->assertSame(1, Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->where('quantity', '>', 0)
            ->count());
        $this->assertEqualsWithDelta(300.0, (float) $restated->quantity, 0.0001);
        $this->assertEqualsWithDelta(170_000.0, (float) $restated->invested_amount, 0.0001);
        $this->assertEqualsWithDelta(566.6667, (float) $restated->avg_buy_price, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $restated->target_amount, 0.0001);
        $this->assertSame('2026-01-10', app(OwnershipEpisodeService::class)
            ->firstBuyDateForHolding($profile, $restated, $stock)
            ?->toDateString());
    }

    protected function assertNoOpenIncreaseFromCorporateAction(Stock $stock): void
    {
        $this->assertSame(
            0,
            TradingRecommendation::query()
                ->where('security_id', $stock->id)
                ->whereIn('recommendation_type', [
                    TradingRecommendation::ACTION_OPEN_POSITION,
                    TradingRecommendation::ACTION_INCREASE_POSITION,
                ])
                ->where(function ($q) {
                    $q->where('evidence->corporate_action_attribution', true)
                        ->orWhere('evidence->holding_adoption', true);
                })
                ->count()
        );
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: TradingStrategy, 3: Stock}
     */
    protected function strategyPortfolio(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $version = app(StrategyConfigurationService::class)->ensureActive($profile);

        return [$user, $profile, $version->strategy, $this->makeStock()];
    }

    protected function strategyOwnedHolding(
        \App\Models\PortfolioProfile $profile,
        TradingStrategy $strategy,
        Stock $stock,
        float $qty,
        float $price,
        string $date,
        float $targetAmount,
    ): Holding {
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_EXECUTED,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => $date.' 10:00:00',
            'executed_at' => $date.' 10:00:00',
        ]);
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => Transaction::SOURCE_RECOMMENDATION,
            'recommendation_id' => $rec->id,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'target_amount' => $targetAmount,
            'filled_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    protected function unmanagedHoldingWithBuy(
        \App\Models\PortfolioProfile $profile,
        Stock $stock,
        float $qty,
        float $price,
        string $date,
    ): Holding {
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => Transaction::SOURCE_MANUAL,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'updated_at' => now(),
        ]);
    }

    protected function makeStrategy(\App\Models\PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 50,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }

    protected function makeStock(string $symbol = ''): Stock
    {
        return Stock::query()->create([
            'symbol' => $symbol !== '' ? $symbol : 'CA'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'CA Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
    }

    /**
     * @param  array<string, float>  $closes
     */
    protected function seedPrices(Stock $stock, array $closes): void
    {
        foreach ($closes as $date => $close) {
            StockPrice::query()->create([
                'stock_id' => $stock->id,
                'price_date' => $date,
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'adjusted_close_price' => $close,
                'volume' => 1000,
                'provider_source' => 'test',
                'data_source' => 'test',
                'created_at' => now(),
            ]);
        }
    }
}
