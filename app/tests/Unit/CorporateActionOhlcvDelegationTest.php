<?php

namespace Tests\Unit;

use App\Models\CorporateAction;
use App\Models\Holding;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockMetric;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CorporateActionPriceRepairService;
use App\Services\CorporateActionService;
use App\Services\MetricsUpdateService;
use App\Services\PortfolioSnapshotRebuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class CorporateActionOhlcvDelegationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDataQualityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindQuietMetrics();

        $snapshots = Mockery::mock(PortfolioSnapshotRebuildService::class);
        $snapshots->shouldReceive('rebuildAfterTransactionChange')->andReturn([]);
        $this->app->instance(PortfolioSnapshotRebuildService::class, $snapshots);
    }

    protected function bindQuietMetrics(?\Closure $updateStock = null): void
    {
        $metrics = Mockery::mock(MetricsUpdateService::class);
        if ($updateStock !== null) {
            $updateStock($metrics);
        } else {
            $metrics->shouldReceive('updateStock')->andReturnUsing(function (Stock $stock) {
                return StockMetric::query()->firstOrCreate(
                    ['stock_id' => $stock->id],
                    [
                        'highest_close' => 0,
                        'latest_close' => 0,
                        'updated_at' => now(),
                    ],
                );
            });
        }
        $this->app->instance(MetricsUpdateService::class, $metrics);
    }

    public function test_f020_without_factor_adjusts_ohlcv_exactly_once(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('NF'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertFalse((bool) ($result['price_adjustment']['deferred_to_factor'] ?? false));
        $this->assertSame(1, (int) $result['price_adjustment']['rows_adjusted']);
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-03-01'));
        $this->assertEquals(20.0, (float) $result['holding']->quantity);
    }

    public function test_direct_f020_earlier_ex_date_refreshes_overlapping_bnagrochem_proof_atomically(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('BNAGROCHEM');
        $stock->update(['isin' => 'INE00HZ01029']);
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-11-01');
        foreach ([
            ['2026-11-11', 147.55, 100],
            ['2026-11-12', 154.92, 100],
            ['2026-11-13', 162.66, 100],
            ['2026-11-16', 17.08, 1000],
        ] as [$date, $close, $volume]) {
            $this->seedOhlcv($stock->id, $date, $close, $volume);
        }
        $factor = $this->createPendingFactor($stock, [
            'action_type' => 'face_value_split',
            'effective_ex_date' => '2026-11-16',
            'applied_ratio' => 10.0,
            'price_divisor' => 10.0,
            'volume_multiplier' => 10.0,
        ]);
        $this->assertSame(1, app(CorporateActionPriceRepairService::class)
            ->repairPendingFactors(factorId: $factor->id, dryRun: false)['repaired']);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => '2026-11-12',
        ]);

        $this->assertSame(7.3775, $this->closeOn($stock->id, '2026-11-11'));
        $this->assertSame(15.492, $this->closeOn($stock->id, '2026-11-12'));
        $current = app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
            ->historyEvidence((int) $stock->id, '2026-11-16');
        $factor->refresh();
        $this->assertSame($current['rows_sha256'], $factor->metadata['ohlcv_repair']['rows_sha256']);
        $this->assertArrayNotHasKey('_invalidated_proofs', $result['price_adjustment']);
        $action = CorporateAction::query()->where('stock_id', $stock->id)->whereDate('ex_date', '2026-11-12')->firstOrFail();
        $this->assertArrayNotHasKey('_invalidated_proofs', $action->metadata['price_adjustment']);
        $this->assertArrayHasKey('rows_sha256', $action->metadata['price_adjustment']);
        $this->assertEquals(20.0, (float) $result['holding']->quantity);

        $repeated = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => '2026-11-12',
        ]);
        $this->assertTrue($repeated['idempotent']);
        $this->assertSame(7.3775, $this->closeOn($stock->id, '2026-11-11'));
        $this->assertSame(1, CorporateAction::query()->where('stock_id', $stock->id)->whereDate('ex_date', '2026-11-12')->count());
    }

    public function test_direct_f020_stale_overlap_rolls_back_ledger_action_and_price_write(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('BNAGROCHEM');
        $stock->update(['isin' => 'INE00HZ01029']);
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-11-01');
        foreach ([
            ['2026-11-11', 147.55, 100],
            ['2026-11-12', 154.92, 100],
            ['2026-11-13', 162.66, 100],
            ['2026-11-16', 17.08, 1000],
        ] as [$date, $close, $volume]) {
            $this->seedOhlcv($stock->id, $date, $close, $volume);
        }
        $factor = $this->createPendingFactor($stock, [
            'action_type' => 'face_value_split', 'effective_ex_date' => '2026-11-16',
            'applied_ratio' => 10.0, 'price_divisor' => 10.0, 'volume_multiplier' => 10.0,
        ]);
        app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $proofHashBeforeTamper = $factor->fresh()->metadata['ohlcv_repair']['rows_sha256'];
        DB::table('portfolio_stock_prices')->where('stock_id', $stock->id)->whereDate('price_date', '2026-11-11')
            ->update(['close_price' => 14.756]);
        $transactionsBefore = Transaction::query()->where('stock_id', $stock->id)->count();
        $holdingBefore = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->value('quantity');

        try {
            app(CorporateActionService::class)->apply($profile, $stock, [
                'action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => '2026-11-12',
            ]);
            $this->fail('Stale overlapping proof must abort direct F020 apply.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('proof is stale', $exception->getMessage());
        }

        $this->assertDatabaseMissing('portfolio_corporate_actions', [
            'stock_id' => $stock->id, 'ex_date' => '2026-11-12', 'action_type' => 'split',
        ]);
        $this->assertSame($transactionsBefore, Transaction::query()->where('stock_id', $stock->id)->count());
        $this->assertSame((float) $holdingBefore, (float) Holding::query()
            ->where('profile_id', $profile->id)->where('stock_id', $stock->id)->value('quantity'));
        $this->assertSame(14.756, $this->closeOn($stock->id, '2026-11-11'));
        $this->assertSame(15.492, $this->closeOn($stock->id, '2026-11-12'));
        $this->assertSame($proofHashBeforeTamper, $factor->fresh()->metadata['ohlcv_repair']['rows_sha256']);
    }

    public function test_f020_is_stock_global_across_profiles_and_rejects_conflicting_ratios(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('BNAGROCHEM');
        $stock->update(['isin' => 'INE00HZ01029']);
        $secondUser = User::query()->create([
            'name' => 'Second Portfolio',
            'email' => 'deleg-second-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $secondProfile = $this->defaultPortfolioFor($secondUser);
        $conflictUser = User::query()->create([
            'name' => 'Conflicting Portfolio',
            'email' => 'deleg-conflict-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $conflictProfile = $this->defaultPortfolioFor($conflictUser);
        foreach ([$profile, $secondProfile, $conflictProfile] as $owner) {
            $this->seedBuy($owner->id, $stock->id, 10, 100, '2026-11-01');
        }
        $this->seedOhlcv($stock->id, '2026-11-11', 100, 100);
        $this->seedOhlcv($stock->id, '2026-11-12', 50, 200);

        $service = app(CorporateActionService::class);
        $payload = ['action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 2, 'ex_date' => '2026-11-12'];
        $service->apply($profile, $stock, $payload);
        $firstClose = $this->closeOn($stock->id, '2026-11-11');
        $this->assertSame(50.0, $firstClose);
        $secondResult = $service->apply($secondProfile, $stock, $payload);
        $this->assertSame(50.0, $this->closeOn($stock->id, '2026-11-11'));
        $this->assertSame(2, CorporateAction::query()->where('stock_id', $stock->id)
            ->whereDate('ex_date', '2026-11-12')->count());
        $this->assertSame(20.0, (float) Holding::query()->where('profile_id', $secondProfile->id)
            ->where('stock_id', $stock->id)->value('quantity'));
        $this->assertSame(0, (int) ($secondResult['price_adjustment']['rows_adjusted'] ?? -1));
        $this->assertArrayHasKey('repair_note', $secondResult['price_adjustment']);

        $owner = CorporateAction::query()->where('profile_id', $profile->id)->where('stock_id', $stock->id)->firstOrFail();
        $this->assertTrue(app(\App\Services\Bhavcopy\NseHistoricalPriceAdjustmentGate::class)
            ->hasCurrentCorporateActionProof($owner));

        try {
            $service->apply($conflictProfile, $stock, [
                'action_type' => 'split', 'ratio_from' => 1, 'ratio_to' => 3, 'ex_date' => '2026-11-12',
            ]);
            $this->fail('A second portfolio must not apply a conflicting ratio to a stock-global event.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('conflicting ratio', $exception->getMessage());
        }
        $this->assertSame(2, CorporateAction::query()->where('stock_id', $stock->id)
            ->whereDate('ex_date', '2026-11-12')->count());
        $this->assertSame($firstClose, $this->closeOn($stock->id, '2026-11-11'));
    }

    public function test_legacy_f020_repair_has_one_stock_global_restatement_owner(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('LEG'.Str::random(3));
        $secondUser = User::query()->create([
            'name' => 'Legacy Second Portfolio',
            'email' => 'deleg-legacy-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $secondProfile = $this->defaultPortfolioFor($secondUser);
        foreach ([$profile, $secondProfile] as $owner) {
            CorporateAction::query()->create([
                'profile_id' => $owner->id,
                'stock_id' => $stock->id,
                'action_type' => 'split',
                'ratio_from' => 1,
                'ratio_to' => 2,
                'ex_date' => '2026-03-01',
                'applied_at' => now(),
                'metadata' => null,
            ]);
        }
        $this->seedOhlcv($stock->id, '2026-02-27', 100, 100);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 200);

        $repair = app(CorporateActionPriceRepairService::class);
        $actions = CorporateAction::query()->where('stock_id', $stock->id)->orderBy('id')->get();
        $first = $repair->repair(actionId: (int) $actions[0]->id, dryRun: false);
        $this->assertSame(1, $first['repaired']);
        $this->assertSame(50.0, $this->closeOn($stock->id, '2026-02-27'));

        $second = $repair->repair(actionId: (int) $actions[1]->id, dryRun: false);
        $this->assertSame(50.0, $this->closeOn($stock->id, '2026-02-27'));
        $this->assertSame(1, $second['skipped'] + $second['repaired']);
        $owners = CorporateAction::query()->where('stock_id', $stock->id)->get()->filter(
            fn (CorporateAction $action): bool => (int) (($action->metadata ?? [])['price_adjustment']['rows_adjusted'] ?? 0) > 0,
        );
        $this->assertCount(1, $owners);
    }

    public function test_f042_repair_refuses_conflicting_stock_global_f020_ratio(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('F4'.Str::random(3));
        CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 3,
            'ex_date' => '2026-03-01',
            'applied_at' => now(),
            'metadata' => null,
        ]);
        $this->seedOhlcv($stock->id, '2026-02-27', 100, 100);
        $this->seedOhlcv($stock->id, '2026-03-01', 33.333, 300);
        $factor = $this->createPendingFactor($stock, [
            'action_type' => 'split', 'effective_ex_date' => '2026-03-01',
            'applied_ratio' => 2.0, 'price_divisor' => 2.0, 'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionPriceRepairService::class)
            ->repairPendingFactors(factorId: $factor->id, dryRun: false);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_DUPLICATE_STOCK_EVENT, $result['details'][0]['status']);
        $this->assertSame(100.0, $this->closeOn($stock->id, '2026-02-27'));
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->fresh()->metadata['ohlcv_repair_status']);
    }

    public function test_f020_with_pending_factor_preserves_ledger_but_skips_ohlcv(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('DG'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $factor = $this->createPendingFactor($stock, [
            'action_type' => 'split',
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertTrue($result['price_adjustment']['deferred_to_factor']);
        $this->assertSame($factor->id, $result['price_adjustment']['factor_id']);
        $this->assertSame(0, (int) $result['price_adjustment']['rows_adjusted']);
        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));
        $this->assertEquals(20.0, (float) $result['holding']->quantity);
        $this->assertEquals(50.0, (float) $result['holding']->avg_buy_price);

        $tx = Transaction::query()->where('stock_id', $stock->id)->where('type', 'buy')->first();
        $this->assertEquals(20.0, (float) $tx->quantity);
        $this->assertEquals(50.0, (float) $tx->price);
    }

    public function test_f043_applies_factor_ohlcv_exactly_once_after_f020_delegation(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('OR'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $factor = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);
        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));

        $repair = app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertSame(1, $repair['repaired']);
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
        $this->assertSame(2000, $this->volumeOn($stock->id, '2026-01-10'));
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-03-01'));

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->metadata['ohlcv_repair_status']);
    }

    public function test_f043_then_f020_still_adjusts_ohlcv_only_once(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('RF'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $factor = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertTrue($result['price_adjustment']['deferred_to_factor']);
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
        $this->assertEquals(20.0, (float) $result['holding']->quantity);
    }

    public function test_repeated_f020_with_factor_does_not_compound_ohlcv(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('RP'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);
        // Second apply after first split already scaled qty — seed is already mutated ledger;
        // still must not touch OHLCV.
        app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_repeated_f043_does_not_compound_ohlcv(): void
    {
        $stock = $this->createDataQualityStock('ID'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $factor = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $service = app(CorporateActionPriceRepairService::class);
        $service->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $service->repairPendingFactors(factorId: $factor->id, dryRun: false);

        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_factor_for_different_stock_does_not_delegate_f020(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('DS'.Str::random(3));
        $other = $this->createDataQualityStock('OT'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $this->createPendingFactor($other, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertFalse((bool) ($result['price_adjustment']['deferred_to_factor'] ?? false));
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_factor_for_different_ex_date_does_not_delegate_f020(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('DE'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-06-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertFalse((bool) ($result['price_adjustment']['deferred_to_factor'] ?? false));
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_bonus_factor_does_not_delegate_split_f020_on_same_ex_date(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('MX'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $this->createPendingFactor($stock, [
            'action_type' => 'bonus',
            'effective_ex_date' => '2026-03-01',
            'applied_ratio' => 1.0,
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertFalse((bool) ($result['price_adjustment']['deferred_to_factor'] ?? false));
        $this->assertEquals(50.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_deferred_to_factor_blocks_f020_ca_repair_path(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('DF'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 52, 2000);

        $action = CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
            'applied_at' => now(),
            'metadata' => null,
        ]);
        $this->createPendingFactor($stock, [
            'action_type' => 'split',
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $repair = app(CorporateActionPriceRepairService::class);
        $findings = $repair->scan(actionId: $action->id);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_DEFERRED_TO_FACTOR, $findings[0]['status']);

        $result = $repair->repair(actionId: $action->id, dryRun: false);
        $this->assertSame(0, $result['repaired']);
        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    public function test_failed_f043_keeps_pending_and_f020_still_does_not_restate(): void
    {
        [$user, $profile, $stock] = $this->seedPortfolioStock('FL'.Str::random(3));
        $this->seedBuy($profile->id, $stock->id, 10, 100, '2026-01-05');
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);
        $factor = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-03-01',
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        // Force F043 failure after claiming the factor.
        $this->bindQuietMetrics(function ($metrics) {
            $metrics->shouldReceive('updateStock')->once()->andThrow(new \RuntimeException('boom'));
        });

        $failed = $this->app->make(CorporateActionPriceRepairService::class)
            ->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertSame('failed', $failed['details'][0]['action']);

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);
        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));

        // Restore metrics mock for F020 apply.
        $this->bindQuietMetrics();

        $result = $this->app->make(CorporateActionService::class)->apply($profile, $stock, [
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-03-01',
        ]);

        $this->assertTrue($result['price_adjustment']['deferred_to_factor']);
        $this->assertEquals(100.0, $this->closeOn($stock->id, '2026-01-10'));
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile, 2: Stock}
     */
    protected function seedPortfolioStock(string $symbol): array
    {
        $user = User::query()->create([
            'name' => 'Delegation User',
            'email' => 'deleg-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => $symbol,
            'exchange' => 'NSE',
            'name' => $symbol.' Ltd',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        return [$user, $profile, $stock];
    }

    protected function seedBuy(int $profileId, int $stockId, float $qty, float $price, string $date): void
    {
        Transaction::query()->create([
            'profile_id' => $profileId,
            'stock_id' => $stockId,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => $date,
            'source' => 'manual',
        ]);
        Holding::query()->create([
            'profile_id' => $profileId,
            'stock_id' => $stockId,
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);
    }

    protected function createPendingFactor(Stock $stock, array $overrides = []): PriceAdjustmentFactor
    {
        return PriceAdjustmentFactor::query()->create(array_merge([
            'stock_id' => $stock->id,
            'issue_id' => null,
            'factor_type' => 'corporate_action',
            'action_type' => 'split',
            'effective_ex_date' => '2026-03-01',
            'applied_ratio' => 2.0,
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
            'is_active' => true,
            'applied_at' => now(),
            'metadata' => [
                'source' => 'data_quality_center',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ], $overrides));
    }

    protected function seedOhlcv(int $stockId, string $date, float $close, int $volume): void
    {
        StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $close,
            'low_price' => $close,
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'volume' => $volume,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }

    protected function closeOn(int $stockId, string $date): float
    {
        return (float) StockPrice::query()
            ->where('stock_id', $stockId)
            ->whereDate('price_date', $date)
            ->value('close_price');
    }

    protected function volumeOn(int $stockId, string $date): int
    {
        return (int) StockPrice::query()
            ->where('stock_id', $stockId)
            ->whereDate('price_date', $date)
            ->value('volume');
    }
}
