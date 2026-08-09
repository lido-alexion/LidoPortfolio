<?php

namespace Tests\Feature;

use App\Models\DataQualityIssue;
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
        $guard = app(DataQualityGuardService::class);

        $this->assertTrue($guard->isBlockedStock($stock));

        app(DataQualityResolutionService::class)->accept($issue, null, 'governance only', null);

        $this->assertFalse($guard->isBlockedStock($stock->fresh()));
        $this->assertSame('100.0000', number_format((float) $stock->prices()->orderByDesc('price_date')->value('open_price'), 4, '.', ''));
    }

    public function test_auto_accepted_issue_unblocks_pipeline(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock, [
            'detected_at' => now()->subDays(20),
        ]);

        app(DataQualityResolutionService::class)->autoAcceptStaleIssues(15);

        $this->assertFalse(app(DataQualityGuardService::class)->isBlockedStock($stock));
        $this->assertSame(DataQualityIssue::STATUS_ACCEPTED, $issue->fresh()->issue_status);
    }
}
