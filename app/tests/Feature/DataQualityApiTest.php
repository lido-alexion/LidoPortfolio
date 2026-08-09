<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Models\DataQualityIssue;
use App\Models\PortfolioProfile;
use App\Models\PriceAdjustmentFactor;
use App\Models\StockPrice;
use App\Services\DataQualityLegacyCorporateActionMigrationService;
use App\Services\DataQualityResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDataQualityFixtures;
use Tests\TestCase;

class DataQualityApiTest extends TestCase
{
    use CreatesDataQualityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_admin_can_accept_pending_issue(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept", ['notes' => 'Approved'])
            ->assertOk()
            ->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_ACCEPTED);
    }

    public function test_admin_can_reject_pending_issue(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/reject", ['notes' => 'False positive'])
            ->assertOk()
            ->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_REJECTED);
    }

    public function test_non_admin_receives_forbidden(): void
    {
        $user = $this->createRegularUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->actingAs($user)
            ->getJson('/api/data-quality/dashboard')
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept")
            ->assertForbidden();
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->getJson('/api/data-quality/dashboard')->assertUnauthorized();
        $this->postJson("/api/data-quality/issues/{$issue->id}/accept")->assertUnauthorized();
    }

    public function test_stale_accept_on_already_accepted_issue_returns_conflict(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        app(DataQualityResolutionService::class)->accept($issue, null, 'first', $admin->id);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept", ['notes' => 'stale'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DATA_QUALITY_STALE_RESOLUTION');
    }

    public function test_stale_reject_on_already_rejected_issue_returns_conflict(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        app(DataQualityResolutionService::class)->reject($issue, 'first', $admin->id);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/reject", ['notes' => 'stale'])
            ->assertStatus(409);
    }

    public function test_history_re_resolution_is_allowed_with_flag(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        app(DataQualityResolutionService::class)->accept($issue, null, 'initial', $admin->id);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/reject", [
                'notes' => 'History reversal',
                're_resolve' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_REJECTED);
    }

    public function test_history_re_resolution_without_notes_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        app(DataQualityResolutionService::class)->accept($issue, null, 'initial', $admin->id);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/reject", [
                're_resolve' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_history_re_resolution_with_blank_notes_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        app(DataQualityResolutionService::class)->accept($issue, null, 'initial', $admin->id);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept", [
                'notes' => '   ',
                're_resolve' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_pending_accept_does_not_require_notes(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept", [])
            ->assertOk()
            ->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_ACCEPTED);
    }

    public function test_pending_reject_does_not_require_notes(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/reject", [])
            ->assertOk()
            ->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_REJECTED);
    }

    public function test_accept_persists_repair_pending_factor_without_invoking_f043(): void
    {
        $admin = $this->createAdminUser();
        $stock = $this->createDataQualityStock();
        $this->seedGapPrices($stock, 200.0, 100.0);
        $issue = $this->createPendingExchangeIssue($stock);

        $this->actingAs($admin)
            ->postJson("/api/data-quality/issues/{$issue->id}/accept")
            ->assertOk();

        $factor = PriceAdjustmentFactor::query()->pendingOhlcvRepair()->where('issue_id', $issue->id)->first();
        $this->assertNotNull($factor);
        $this->assertSame('100.0000', number_format((float) $stock->prices()->orderByDesc('price_date')->value('open_price'), 4, '.', ''));
    }

    public function test_legacy_corporate_action_migration_dry_run_counts_without_writes(): void
    {
        $admin = $this->createAdminUser();
        $stock = $this->createDataQualityStock();
        $profile = PortfolioProfile::query()->where('user_id', $admin->id)->first();
        CorporateAction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'action_type' => 'split',
            'ratio_from' => 1,
            'ratio_to' => 2,
            'ex_date' => '2026-01-10',
            'applied_at' => now(),
            'created_by' => $admin->id,
        ]);

        $result = app(DataQualityLegacyCorporateActionMigrationService::class)->migrateAppliedActions(true);

        $this->assertSame(1, $result['migrated']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, DataQualityIssue::query()->count());
    }
}
