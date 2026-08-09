<?php

namespace Tests\Unit;

use App\Models\DataQualityIssue;
use App\Models\Holding;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Transaction;
use App\Services\CorporateActionPriceRepairService;
use App\Services\MetricsUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class CorporateActionFactorPriceRepairTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDataQualityFixtures;

    public function test_pending_factor_is_discovered_and_preview_does_not_mutate(): void
    {
        [$stock, $factor] = $this->seedPendingSplitFactor();

        $before = StockPrice::query()->where('stock_id', $stock->id)->orderBy('price_date')->get()
            ->map(fn (StockPrice $row) => [
                'date' => $row->price_date->toDateString(),
                'close' => (float) $row->close_price,
                'volume' => (int) $row->volume,
            ])->all();

        $service = app(CorporateActionPriceRepairService::class);
        $findings = $service->scanPendingFactors(stockId: $stock->id);

        $this->assertCount(1, $findings);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_PENDING, $findings[0]['status']);
        $this->assertSame($factor->id, $findings[0]['factor_id']);
        $this->assertSame(2, $findings[0]['rows_to_adjust']);
        $this->assertEquals(2.0, $findings[0]['price_divisor']);
        $this->assertEquals(2.0, $findings[0]['volume_multiplier']);
        $this->assertFalse($findings[0]['already_completed']);

        $dry = $service->repairPendingFactors(stockId: $stock->id, dryRun: true);
        $this->assertSame(1, $dry['repaired']);
        $this->assertSame('would_repair', $dry['details'][0]['action']);

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);

        $after = StockPrice::query()->where('stock_id', $stock->id)->orderBy('price_date')->get()
            ->map(fn (StockPrice $row) => [
                'date' => $row->price_date->toDateString(),
                'close' => (float) $row->close_price,
                'volume' => (int) $row->volume,
            ])->all();
        $this->assertSame($before, $after);
    }

    public function test_apply_adjusts_ohlcv_fields_and_marks_factor_completed(): void
    {
        [$stock, $factor, $issue] = $this->seedPendingSplitFactor(withIssue: true);

        $service = app(CorporateActionPriceRepairService::class);
        $result = $service->repairPendingFactors(stockId: $stock->id, dryRun: false);

        $this->assertSame(1, $result['repaired']);
        $this->assertSame('repaired', $result['details'][0]['action']);

        $pre = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->first();
        $this->assertEquals(50.0, (float) $pre->open_price);
        $this->assertEquals(55.0, (float) $pre->high_price);
        $this->assertEquals(45.0, (float) $pre->low_price);
        $this->assertEquals(50.0, (float) $pre->close_price);
        $this->assertEquals(50.0, (float) $pre->adjusted_close_price);
        $this->assertSame(2000, (int) $pre->volume);

        $mid = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-02-10')->first();
        $this->assertEquals(60.0, (float) $mid->close_price);
        $this->assertSame(1600, (int) $mid->volume);

        $ex = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-03-01')->first();
        $this->assertEquals(58.0, (float) $ex->close_price);
        $this->assertSame(2200, (int) $ex->volume);

        $post = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-03-02')->first();
        $this->assertEquals(59.0, (float) $post->close_price);

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->metadata['ohlcv_repair_status']);
        $this->assertSame(2, $factor->metadata['ohlcv_repair']['rows_adjusted']);
        $this->assertSame($issue->id, $factor->metadata['ohlcv_repair']['issue_id']);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair']['previous_status']);

        $issue->refresh();
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->issue_status);
    }

    public function test_uses_stored_divisor_not_recalculated_from_action_type(): void
    {
        $stock = $this->createDataQualityStock('STOR'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);

        // Intentionally odd stored divisors (not recomputed from applied_ratio/action_type).
        $factor = PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id,
            'issue_id' => null,
            'factor_type' => 'corporate_action',
            'action_type' => 'split',
            'effective_ex_date' => '2026-03-01',
            'applied_ratio' => 2.0,
            'price_divisor' => 4.0,
            'volume_multiplier' => 4.0,
            'is_active' => true,
            'applied_at' => now(),
            'metadata' => [
                'source' => 'data_quality_center',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ]);

        app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);

        $this->assertEquals(25.0, (float) StockPrice::query()
            ->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-01-10')
            ->value('close_price'));
        $this->assertSame(4000, (int) StockPrice::query()
            ->where('stock_id', $stock->id)
            ->whereDate('price_date', '2026-01-10')
            ->value('volume'));
    }

    public function test_completed_factor_is_idempotent(): void
    {
        [$stock] = $this->seedPendingSplitFactor();
        $service = app(CorporateActionPriceRepairService::class);

        $service->repairPendingFactors(stockId: $stock->id, dryRun: false);
        $firstClose = (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price');
        $firstVolume = (int) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('volume');

        $second = $service->repairPendingFactors(stockId: $stock->id, dryRun: false);
        $this->assertSame(0, $second['repaired']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_ALREADY_COMPLETED, $second['details'][0]['status']
            ?? CorporateActionPriceRepairService::STATUS_ALREADY_COMPLETED);

        // scanPendingFactors without factor id only returns pending — force inspect via factor id
        $findings = $service->scanPendingFactors(stockId: $stock->id, factorId: PriceAdjustmentFactor::query()->where('stock_id', $stock->id)->value('id'));
        $this->assertSame(CorporateActionPriceRepairService::STATUS_ALREADY_COMPLETED, $findings[0]['status']);

        $this->assertEquals($firstClose, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
        $this->assertSame($firstVolume, (int) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('volume'));
    }

    public function test_multiple_factors_apply_in_ascending_ex_date_order(): void
    {
        $stock = $this->createDataQualityStock('MULTI'.Str::random(2));
        $this->seedOhlcv($stock->id, '2025-06-01', 400, 100);
        $this->seedOhlcv($stock->id, '2026-01-15', 200, 100);
        $this->seedOhlcv($stock->id, '2026-06-01', 100, 100);

        // Older split 1:2 then later split 1:2 — apply oldest first.
        $older = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-01-01',
            'applied_ratio' => 2.0,
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);
        $newer = $this->createPendingFactor($stock, [
            'effective_ex_date' => '2026-05-01',
            'applied_ratio' => 2.0,
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors(stockId: $stock->id, dryRun: false);
        $this->assertSame(2, $result['repaired']);
        $this->assertSame([$older->id, $newer->id], array_column($result['details'], 'factor_id'));

        // 2025-06-01 adjusted by both divisors: 400/2/2 = 100
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2025-06-01')->value('close_price'));
        // 2026-01-15 only newer factor (after older ex): 200/2 = 100
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-15')->value('close_price'));
        // 2026-06-01 after both ex dates: unchanged
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-06-01')->value('close_price'));
    }

    public function test_overlapping_pending_factors_same_ex_date_do_not_mutate(): void
    {
        $stock = $this->createDataQualityStock('OVLP'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 2000);

        $this->createPendingFactor($stock, ['effective_ex_date' => '2026-03-01', 'price_divisor' => 2.0, 'volume_multiplier' => 2.0]);
        $this->createPendingFactor($stock, ['effective_ex_date' => '2026-03-01', 'price_divisor' => 2.0, 'volume_multiplier' => 2.0]);

        $findings = app(CorporateActionPriceRepairService::class)->scanPendingFactors(stockId: $stock->id);
        $this->assertCount(2, $findings);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_AMBIGUOUS, $findings[0]['status']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_AMBIGUOUS, $findings[1]['status']);

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors(stockId: $stock->id, dryRun: false);
        $this->assertSame(0, $result['repaired']);
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
        $this->assertSame(2, PriceAdjustmentFactor::query()->pendingOhlcvRepair()->where('stock_id', $stock->id)->count());
    }

    public function test_unsupported_action_type_is_rejected(): void
    {
        $stock = $this->createDataQualityStock('DIV'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 95, 1000);

        $this->createPendingFactor($stock, [
            'action_type' => 'dividend',
            'price_divisor' => 1.05,
            'volume_multiplier' => 1.05,
        ]);

        $findings = app(CorporateActionPriceRepairService::class)->scanPendingFactors(stockId: $stock->id);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_UNSUPPORTED_ACTION, $findings[0]['status']);

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors(stockId: $stock->id, dryRun: false);
        $this->assertSame(0, $result['repaired']);
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
    }

    public function test_invalid_divisor_leaves_data_and_status_unchanged(): void
    {
        $stock = $this->createDataQualityStock('BAD'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000);
        $this->seedOhlcv($stock->id, '2026-03-01', 50, 1000);

        $factor = $this->createPendingFactor($stock, [
            'price_divisor' => 0,
            'volume_multiplier' => 2.0,
        ]);

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);
        $this->assertSame(0, $result['repaired']);
        $this->assertSame(CorporateActionPriceRepairService::STATUS_INVALID_FACTOR, $result['details'][0]['status']);

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
    }

    public function test_exception_during_apply_rolls_back_and_keeps_pending(): void
    {
        [$stock, $factor] = $this->seedPendingSplitFactor();

        $metrics = Mockery::mock(MetricsUpdateService::class);
        $metrics->shouldReceive('updateStock')->once()->andThrow(new \RuntimeException('metrics boom'));
        $this->app->instance(MetricsUpdateService::class, $metrics);

        $service = $this->app->make(CorporateActionPriceRepairService::class);
        $result = $service->repairPendingFactors(stockId: $stock->id, dryRun: false);

        $this->assertSame(0, $result['repaired']);
        $this->assertSame('failed', $result['details'][0]['action']);

        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);
        $this->assertEquals(100.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
    }

    public function test_does_not_mutate_transactions_or_holdings(): void
    {
        [$stock, $factor] = $this->seedPendingSplitFactor();
        $user = $this->createAdminUser();
        $profile = $this->defaultPortfolioFor($user);

        $tx = Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-05',
            'source' => 'manual',
        ]);
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'realized_profit' => 0,
            'updated_at' => now(),
        ]);

        app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);

        $tx->refresh();
        $holding->refresh();
        $this->assertEquals(10.0, (float) $tx->quantity);
        $this->assertEquals(100.0, (float) $tx->price);
        $this->assertEquals(10.0, (float) $holding->quantity);
        $this->assertEquals(100.0, (float) $holding->avg_buy_price);
    }

    public function test_face_value_split_is_supported_using_stored_divisors(): void
    {
        $stock = $this->createDataQualityStock('FV'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 500);
        $this->seedOhlcv($stock->id, '2026-03-01', 20, 2500);

        $factor = $this->createPendingFactor($stock, [
            'action_type' => 'face_value_split',
            'applied_ratio' => 5.0,
            'price_divisor' => 5.0,
            'volume_multiplier' => 5.0,
        ]);

        app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: $factor->id, dryRun: false);

        $this->assertEquals(20.0, (float) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('close_price'));
        $this->assertSame(2500, (int) StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-10')->value('volume'));
        $factor->refresh();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->metadata['ohlcv_repair_status']);
    }

    /**
     * @return array{0: Stock, 1: PriceAdjustmentFactor, 2?: DataQualityIssue}
     */
    protected function seedPendingSplitFactor(bool $withIssue = false): array
    {
        $stock = $this->createDataQualityStock('F43'.Str::random(3));
        $this->seedOhlcv($stock->id, '2026-01-10', 100, 1000, high: 110, low: 90);
        $this->seedOhlcv($stock->id, '2026-02-10', 120, 800);
        $this->seedOhlcv($stock->id, '2026-03-01', 58, 2200);
        $this->seedOhlcv($stock->id, '2026-03-02', 59, 2100);

        $issue = null;
        if ($withIssue) {
            $issue = $this->createPendingExchangeIssue($stock, [
                'issue_status' => DataQualityIssue::STATUS_ACCEPTED,
                'ex_date' => '2026-03-01',
                'corporate_action_type' => 'split',
                'suggested_ratio' => 2.0,
            ]);
        }

        $factor = $this->createPendingFactor($stock, [
            'issue_id' => $issue?->id,
            'action_type' => 'split',
            'effective_ex_date' => '2026-03-01',
            'applied_ratio' => 2.0,
            'price_divisor' => 2.0,
            'volume_multiplier' => 2.0,
        ]);

        return $withIssue ? [$stock, $factor, $issue] : [$stock, $factor];
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
                'detection_method' => 'exchange_feed',
                'detection_source' => 'exchange_feed',
                'ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_PENDING,
            ],
        ], $overrides));
    }

    protected function seedOhlcv(
        int $stockId,
        string $date,
        float $close,
        int $volume,
        ?float $high = null,
        ?float $low = null,
    ): void {
        StockPrice::query()->create([
            'stock_id' => $stockId,
            'price_date' => $date,
            'open_price' => $close,
            'high_price' => $high ?? $close,
            'low_price' => $low ?? $close,
            'close_price' => $close,
            'adjusted_close_price' => $close,
            'volume' => $volume,
            'provider_source' => 'test',
            'data_source' => 'test',
            'created_at' => now(),
        ]);
    }
}
