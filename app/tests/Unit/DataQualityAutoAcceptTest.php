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

    public function test_exchange_feed_corporate_action_requires_human_approval_after_threshold(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(16),
        ]);

        $count = app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $this->assertSame(0, $count);
        $fresh = $issue->fresh();
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $fresh->issue_status);
        $this->assertFalse($fresh->auto_resolved);
        $this->assertSame(0, $fresh->resolutions()->count());
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

    public function test_corporate_action_auto_accept_remains_disabled_regardless_of_threshold(): void
    {
        config(['services.data_quality.auto_accept_days' => 7]);
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(8),
        ]);

        $this->assertSame(0, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(null));
    }

    public function test_auto_accept_does_not_create_a_factor_for_corporate_actions(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(30),
        ]);

        $count = app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->first();
        $this->assertSame(0, $count);
        $this->assertNull($factor);
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->fresh()->issue_status);
    }
}
