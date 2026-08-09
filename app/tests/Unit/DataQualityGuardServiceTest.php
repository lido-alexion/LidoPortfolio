<?php

namespace Tests\Unit;

use App\Models\DataQualityIssue;
use App\Services\DataQualityGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityGuardServiceTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    public function test_pending_review_blocks_stock(): void
    {
        $stock = $this->createDataQualityStock();
        $this->createPendingExchangeIssue($stock);

        $guard = app(DataQualityGuardService::class);
        $this->assertTrue($guard->isBlockedStock($stock));
        $this->assertTrue($guard->blockedStockIdMap([$stock->id])[$stock->id] ?? false);
    }

    public function test_accepted_unblocks_stock(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $issue->update([
            'issue_status' => DataQualityIssue::STATUS_ACCEPTED,
            'resolved_at' => now(),
            'auto_resolved' => true,
        ]);

        $guard = app(DataQualityGuardService::class);
        $this->assertFalse($guard->isBlockedStock($stock));
    }

    public function test_rejected_unblocks_stock(): void
    {
        $stock = $this->createDataQualityStock();
        $issue = $this->createPendingExchangeIssue($stock);
        $issue->update([
            'issue_status' => DataQualityIssue::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);

        $this->assertFalse(app(DataQualityGuardService::class)->isBlockedStock($stock));
    }
}
