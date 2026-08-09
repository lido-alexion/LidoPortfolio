<?php

namespace Tests\Unit;

use App\Models\DataQualityIssue;
use App\Models\PriceAdjustmentFactor;
use App\Services\DataQualityResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityAutoAcceptTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.data_quality.auto_accept_days' => 15]);
    }

    public function test_eligible_exchange_feed_issue_after_threshold_is_auto_accepted(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(16),
        ]);

        $count = app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $this->assertSame(1, $count);
        $fresh = $issue->fresh();
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $fresh->issue_status);
        $this->assertTrue($fresh->auto_resolved);
        $latest = $fresh->resolutions()->latest('resolved_at')->first();
        $this->assertSame('auto_accepted', $latest->resolution_type);
        $this->assertNotNull($latest->metadata['auto_accept_policy'] ?? null);
    }

    public function test_exchange_feed_issue_before_threshold_remains_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(5),
        ]);

        $count = app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $this->assertSame(0, $count);
        $this->assertSame(1, DataQualityIssue::query()->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)->count());
    }

    public function test_heuristic_issue_after_threshold_remains_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingHeuristicIssue($stock, [
            'detected_at' => now()->subDays(20),
        ]);

        $count = app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $this->assertSame(0, $count);
        $this->assertSame(1, DataQualityIssue::query()->where('issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)->count());
    }

    public function test_exchange_match_false_remains_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(20),
            'exchange_match' => false,
        ]);

        $this->assertSame(0, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15));
    }

    public function test_missing_ratio_remains_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(20),
            'suggested_ratio' => null,
        ]);

        $this->assertSame(0, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15));
    }

    public function test_low_confidence_remains_pending(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(20),
            'confidence' => 0.75,
        ]);

        $this->assertSame(0, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15));
    }

    public function test_configurable_threshold_is_honoured(): void
    {
        config(['services.data_quality.auto_accept_days' => 7]);
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(8),
        ]);

        $this->assertSame(1, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(null));
    }

    public function test_auto_accept_creates_repair_pending_factor_without_ohlcv_mutation(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(30),
        ]);

        app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->first();
        $this->assertNotNull($factor);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status'] ?? null);
    }
}
