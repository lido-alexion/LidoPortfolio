<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
use App\Models\PriceAdjustmentFactor;
use App\Models\StockPrice;
use App\Services\DataQualityResolutionService;
use App\Services\CorporateActionPriceRepairService;
use App\Services\CorporateActionPriceAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityResolutionServiceTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_manual_accept_creates_factor_with_repair_pending_marker(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $adminId = $this->createAdminUser()->id;

        $updated = app(DataQualityResolutionService::class)->accept($issue, null, 'Confirmed split', $adminId);

        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $updated->issue_status);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->first();
        $this->assertNotNull($factor);
        $this->assertTrue($factor->is_active);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status'] ?? null);
    }

    public function test_manual_modified_accept_records_modified_resolution_type(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);

        $updated = app(DataQualityResolutionService::class)->accept($issue, 3.0, 'Adjusted ratio', null);

        $this->assertSame(3.0, (float) $updated->applied_ratio);
        $latest = $updated->resolutions()->latest('resolved_at')->first();
        $this->assertSame('modified_accepted', $latest->resolution_type);
    }

    public function test_reject_deactivates_factors_and_does_not_create_active_factor(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $service->accept($issue, null, 'temp', null);
        $issue = $issue->fresh();

        $updated = $service->reject($issue, 'False positive', null, requirePendingReview: false);

        $this->assertSame(DataQualityIssue::STATUS_REJECTED, $updated->issue_status);
        $this->assertSame(0, PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->where('is_active', true)->count());
    }

    public function test_stale_pending_accept_returns_conflict(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $service->accept($issue, null, 'first', null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Issue is no longer pending review.');
        $service->accept($issue->fresh(), null, 'stale', null, false, true);
    }

    public function test_stale_pending_reject_returns_conflict(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $service->reject($issue, 'done', null);

        $this->expectException(DomainException::class);
        $service->reject($issue->fresh(), 'stale', null, true);
    }

    public function test_history_re_resolution_is_allowed_when_not_requiring_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $service->accept($issue, null, 'initial', null);
        $priceRowsBefore = StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray();

        $updated = $service->reject($issue->fresh(), 'Reversal in history', null, requirePendingReview: false);

        $this->assertSame(DataQualityIssue::STATUS_REJECTED, $updated->issue_status);
        $this->assertGreaterThan(1, $updated->resolutions()->count());
        $reversal = $updated->resolutions()->latest('id')->firstOrFail();
        $this->assertTrue($reversal->is_reversal);
        $this->assertSame($updated->resolutions()->where('id', '<', $reversal->id)->latest('id')->value('id'), $reversal->supersedes_resolution_id);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail()->metadata['ohlcv_repair_status']);
        $this->assertSame($priceRowsBefore, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
    }

    public function test_completed_repair_blocks_metadata_reversal_and_preserves_audit_state(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, null, 'initial', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        $metadata = $factor->metadata;
        $metadata['ohlcv_repair_status'] = PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED;
        $factor->update(['metadata' => $metadata]);
        $resolutionCount = $accepted->resolutions()->count();
        $evidenceCount = $accepted->evidences()->count();

        try {
            $service->reject($accepted->fresh(), 'must fail closed', null, requirePendingReview: false);
            $this->fail('Completed repair metadata reversal must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', $exception->errorCode());
            $this->assertSame(409, $exception->httpStatus());
        }

        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->fresh()->issue_status);
        $this->assertSame($resolutionCount, $issue->fresh()->resolutions()->count());
        $this->assertSame($evidenceCount, $issue->fresh()->evidences()->count());
        $this->assertTrue($factor->fresh()->is_active);
    }

    public function test_completed_repair_blocks_reaccept_after_metadata_rejection(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, null, 'initial', null);
        $rejected = $service->reject($accepted->fresh(), 'historical pending-only reversal fixture', null, requirePendingReview: false);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        $metadata = $factor->metadata;
        $metadata['ohlcv_repair_status'] = PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED;
        $factor->update(['metadata' => $metadata, 'is_active' => false]);
        $resolutionCount = $rejected->resolutions()->count();

        try {
            $service->accept($rejected->fresh(), null, 'must fail closed', null, false, false);
            $this->fail('Reaccept after completed repair must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', $exception->errorCode());
            $this->assertSame(409, $exception->httpStatus());
        }

        $this->assertSame(DataQualityIssue::STATUS_REJECTED, $issue->fresh()->issue_status);
        $this->assertSame($resolutionCount, $issue->fresh()->resolutions()->count());
        $this->assertFalse($factor->fresh()->is_active);
    }

    public function test_accept_does_not_modify_ohlcv(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock);

        app(DataQualityResolutionService::class)->accept($issue, null, 'accept', null);

        $price = StockPrice::query()->where('stock_id', $stock->id)->orderByDesc('price_date')->first();
        $this->assertSame('100.0000', number_format((float) $price->open_price, 4, '.', ''));
    }

    public function test_f042_repair_refuses_factor_divisors_that_differ_from_admin_approved_ratio(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-01-02', 'suggested_ratio' => 2.0]);
        $accepted = app(DataQualityResolutionService::class)->accept($issue, 2.0, 'approved', $this->createAdminUser()->id);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $accepted->id)->firstOrFail();
        $factor->update(['price_divisor' => 3, 'volume_multiplier' => 3]);
        $before = StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray();

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);

        $this->assertSame('invalid_factor', $result['details'][0]['status']);
        $this->assertContains('factor_ratio_divisor_mismatch', $result['details'][0]['errors']);
        $this->assertSame($before, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
    }

    public function test_f042_repair_requires_linked_data_quality_issue_to_remain_approved(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-01-02', 'suggested_ratio' => 2.0]);
        $accepted = app(DataQualityResolutionService::class)->accept($issue, 2.0, 'approved', $this->createAdminUser()->id);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $accepted->id)->firstOrFail();
        $accepted->update(['issue_status' => DataQualityIssue::STATUS_REJECTED]);
        $before = StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray();

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);

        $this->assertSame('invalid_factor', $result['details'][0]['status']);
        $this->assertContains('factor_approval_binding_mismatch', $result['details'][0]['errors']);
        $this->assertSame($before, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
    }

    public function test_completed_repair_can_be_rolled_back_exactly_and_idempotently(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 123.4567, 100.0, '2026-01-01', '2026-02-01');
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-02-01', 'suggested_ratio' => 3.0]);
        app(\App\Services\DataQualityIssueService::class)->attachEvidence($issue, [[
            'evidence_key' => 'raw_receipt', 'evidence_value' => 'receipt-1', 'evidence_payload' => ['sha256' => 'abc123', 'raw' => ['receipt' => 'preserve-me']],
        ]]);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, 3.0, 'stage', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        $before = StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray();
        $unrelated = $this->createDataQualityStock('OTHER');
        $this->seedGapPrices($unrelated, 77.7777, 70.0);
        $unrelatedBefore = StockPrice::query()->where('stock_id', $unrelated->id)->orderBy('id')->get()->toArray();

        $applied = app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        $this->assertSame('repaired', $applied['details'][0]['action']);
        $snapshotCount = \Illuminate\Support\Facades\DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->count();
        $secondApply = app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        $this->assertSame(1, $secondApply['skipped']);
        $this->assertSame($snapshotCount, \Illuminate\Support\Facades\DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->count());
        $this->assertNotSame($before, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
        $updated = $service->rollbackCompletedRepair($accepted, 'exact rollback', null);

        $this->assertSame($before, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
        $this->assertSame($unrelatedBefore, StockPrice::query()->where('stock_id', $unrelated->id)->orderBy('id')->get()->toArray());
        $this->assertSame(DataQualityIssue::STATUS_REJECTED, $updated->issue_status);
        // Native JSON columns may reorder object keys; compare exact persisted values,
        // not the insertion order of a PHP associative array.
        $payloads = $updated->evidences->pluck('evidence_payload')->all();
        $this->assertCount(1, $payloads);
        $this->assertCount(2, $payloads[0]);
        $this->assertSame('abc123', $payloads[0]['sha256']);
        $this->assertSame(['receipt' => 'preserve-me'], $payloads[0]['raw']);
        $factor->refresh();
        $this->assertFalse($factor->is_active);
        $this->assertSame('rolled_back', $factor->metadata['ohlcv_repair_status']);
        $reversal = $updated->resolutions()->latest('id')->firstOrFail();
        $this->assertTrue($reversal->is_reversal);
        $this->assertSame($accepted->latest_resolution_id, $reversal->supersedes_resolution_id);
        $count = $updated->resolutions()->count();
        $service->rollbackCompletedRepair($updated, 'retry', null);
        $this->assertSame($count, $updated->fresh()->resolutions()->count());
    }

    public function test_failed_repair_removes_partial_snapshots_and_rolls_back_prices(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 123.4567, 100.0, '2026-01-01', '2026-02-01');
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-02-01', 'suggested_ratio' => 3.0]);
        $accepted = app(DataQualityResolutionService::class)->accept($issue, 3.0, 'stage', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        $before = StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray();

        $adjuster = \Mockery::mock(CorporateActionPriceAdjustmentService::class)->makePartial();
        $adjuster->shouldReceive('adjustHistoricalPricesByDivisors')->once()->andThrow(new \RuntimeException('simulated repair failure'));
        $this->app->instance(CorporateActionPriceAdjustmentService::class, $adjuster);

        $result = app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        $this->assertSame('failed', $result['details'][0]['action']);
        $this->assertSame('simulated repair failure', $result['details'][0]['reason']);

        $this->assertSame($before, StockPrice::query()->where('stock_id', $stock->id)->orderBy('id')->get()->toArray());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('portfolio_price_adjustment_repair_snapshots')->where('factor_id', $factor->id)->count());
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->fresh()->metadata['ohlcv_repair_status']);
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $accepted->fresh()->issue_status);
    }

    public function test_completed_repair_rollback_fails_closed_on_drift_and_legacy_missing_snapshot(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0, '2026-01-01', '2026-02-01');
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-02-01']);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, null, 'stage', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        StockPrice::query()->where('stock_id', $stock->id)->where('price_date', '<', '2026-02-01')->update(['close_price' => 12.3456]);
        try {
            $service->rollbackCompletedRepair($accepted, 'drift', null);
            $this->fail('Drift must be rejected.');
        } catch (DomainException $e) {
            $this->assertSame('DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', $e->errorCode());
        }
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->fresh()->issue_status);
    }

    public function test_completed_repair_rollback_rejects_later_overlapping_factor_ex_date(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0, '2026-01-01', '2026-02-01');
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-02-01']);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, null, 'stage', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        PriceAdjustmentFactor::query()->create([
            'stock_id' => $stock->id, 'issue_id' => null, 'factor_type' => 'corporate_action',
            'action_type' => 'split', 'effective_ex_date' => '2026-02-10', 'applied_ratio' => 2,
            'price_divisor' => 2, 'volume_multiplier' => 2, 'is_active' => true, 'applied_at' => now(),
            'metadata' => ['ohlcv_repair_status' => PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED],
        ]);

        try {
            $service->rollbackCompletedRepair($accepted, 'overlap', null);
            $this->fail('A later completed factor must prevent overlapping exact rollback.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', $exception->errorCode());
        }

        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->fresh()->metadata['ohlcv_repair_status']);
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->fresh()->issue_status);
    }

    public function test_completed_repair_rollback_rejects_new_pre_ex_date_row(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0, '2026-01-01', '2026-02-01');
        $issue = $this->createPendingExchangeIssue($stock, ['ex_date' => '2026-02-01']);
        $service = app(DataQualityResolutionService::class);
        $accepted = $service->accept($issue, null, 'stage', null);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail();
        app(CorporateActionPriceRepairService::class)->repairPendingFactors($stock->id, $factor->id, false);
        \Illuminate\Support\Facades\DB::table('portfolio_stock_prices')->insert([
            'stock_id' => $stock->id, 'price_date' => '2026-01-15', 'open_price' => 50, 'high_price' => 51,
            'low_price' => 49, 'close_price' => 50, 'volume' => 10, 'provider_source' => 'new-source',
            'data_source' => 'new-source', 'created_at' => now(),
        ]);
        try {
            $service->rollbackCompletedRepair($accepted, 'new row drift', null);
            $this->fail('A newly inserted affected row must prevent exact rollback.');
        } catch (DomainException $e) {
            $this->assertSame('DATA_QUALITY_PRICE_REPAIR_ROLLBACK_REQUIRED', $e->errorCode());
        }
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->fresh()->issue_status);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->fresh()->metadata['ohlcv_repair_status']);
    }
}
