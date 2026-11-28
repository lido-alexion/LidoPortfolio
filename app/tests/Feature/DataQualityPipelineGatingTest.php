<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
use App\Models\PriceAdjustmentFactor;
use App\Services\DataQualityGuardService;
use App\Services\DataQualityResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityPipelineGatingTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_unblocking_after_accept_does_not_imply_ohlcv_repair(): void
    {
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock);
        $reviewer = $this->createAdminUser();
        $guard = app(DataQualityGuardService::class);

        $this->assertTrue($guard->isBlockedStock($stock));

        $accepted = app(DataQualityResolutionService::class)->accept($issue, null, 'governance only', $reviewer->id);

        $this->assertFalse($guard->isBlockedStock($stock->fresh()));
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $accepted->id)->firstOrFail();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status'] ?? null);
        $resolution = $accepted->resolutions()->latest('resolved_at')->firstOrFail();
        $this->assertSame($reviewer->id, $resolution->resolved_by);
        $this->assertTrue((bool) $resolution->metadata['human_approval_required']);
        $this->assertSame('100.0000', number_format((float) $stock->prices()->orderByDesc('price_date')->value('open_price'), 4, '.', ''));
    }

    public function test_auto_accept_keeps_split_and_bonus_actions_blocked_until_human_approval(): void
    {
        foreach (['split', 'bonus'] as $actionType) {
            $stock = $this->createDataQualityStock('DQ'.strtoupper($actionType));
            $issue = $this->createPendingExchangeIssue($stock, [
                'corporate_action_type' => $actionType,
                'detected_at' => now()->subDays(20),
            ]);

            $this->assertSame(0, app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15));
            $this->assertTrue(app(DataQualityGuardService::class)->isBlockedStock($stock->fresh()));
            $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->fresh()->issue_status);
            $this->assertSame(0, $issue->fresh()->resolutions()->count());
            $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
        }
    }
}
