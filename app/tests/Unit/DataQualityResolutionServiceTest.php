<?php

namespace Tests\Unit;

use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
use App\Models\PriceAdjustmentFactor;
use App\Models\StockPrice;
use App\Services\DataQualityResolutionService;
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
        $issue = $this->createPendingExchangeIssue($stock);
        $service = app(DataQualityResolutionService::class);
        $service->accept($issue, null, 'initial', null);

        $updated = $service->reject($issue->fresh(), 'Reversal in history', null, requirePendingReview: false);

        $this->assertSame(DataQualityIssue::STATUS_REJECTED, $updated->issue_status);
        $this->assertGreaterThan(1, $updated->resolutions()->count());
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
}
