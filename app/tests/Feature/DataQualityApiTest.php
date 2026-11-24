<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Exceptions\DomainException;
use App\Models\DataQualityIssue;
use App\Models\PortfolioProfile;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\CorporateActionPriceRepairService;
use App\Services\DataQualityLegacyCorporateActionMigrationService;
use App\Services\DataQualityIssueService;
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

    public function test_bn_agrochem_bundle_with_contradictory_reviewed_bhavcopy_row_cannot_stage_factor(): void
    {
        $stock = Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-11-13',
            'open_price' => 100.0, 'high_price' => 101.0, 'low_price' => 99.0,
            'close_price' => 100.0, 'adjusted_close_price' => 100.0, 'volume' => 1000,
            'data_source' => 'fixture', 'created_at' => now(),
        ]);
        $issue = DataQualityIssue::query()->create([
            'stock_id' => $stock->id, 'symbol' => 'BNAGROCHEM',
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'exchange_feed', 'suggested_ratio' => 10,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
            'exchange_match' => true, 'detected_at' => now(),
        ]);
        app(DataQualityIssueService::class)->attachEvidence($issue, [
            ['evidence_key' => 'bnagrochem_listing_circular', 'evidence_payload' => [
                'document' => 'CML77412', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CML77412.pdf',
                'sha256' => 'c0faa54145565b1650c98748562c81b5444dc63d53b57c39f5e28e07aa8b4532',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'new_isin' => 'INE00HZ01029', 'ex_date' => '2026-11-16',
            ]],
            ['evidence_key' => 'bnagrochem_clearing_circular', 'evidence_payload' => [
                'document' => 'CMPT77438', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CMPT77438.pdf',
                'sha256' => '70d93d11da034e34af5184e63af651b04d826f7935ca375bc0c14bc116e6c454',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'ex_date' => '2026-11-16',
            ]],
            ['evidence_key' => 'bnagrochem_bhavcopy', 'evidence_payload' => [
                'source_url' => 'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261113_F_0000.csv.zip',
                'source_archive_sha256' => '758ec3b60fd492a29ff3ebc6c069269bc326dc5ca662cfba8892f16939841b7b',
                'source_csv_sha256' => '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e',
                'trade_date' => '2026-11-13', 'symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011',
                'instrument_id' => 762608, 'open' => 159.00, 'high' => 162.66, 'low' => 158.68,
                'close' => 162.65, 'volume' => 101471,
            ]],
        ]);

        $admin = $this->createAdminUser();
        try {
            app(DataQualityResolutionService::class)->accept($issue, null, 'Review blocked pending independent official bhavcopy evidence.', $admin->id);
            $this->fail('Unverified bhavcopy payload must not stage an F042 factor.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED', $exception->errorCode());
        }
        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->fresh()->issue_status);
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
        $this->assertSame('100.0000', number_format((float) StockPrice::query()->where('stock_id', 7096)->value('close_price'), 4, '.', ''));
    }

    public function test_reviewed_f042_factor_stages_without_price_writes_and_reversal_is_audited(): void
    {
        $stock = Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        $price = StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-11-13',
            'open_price' => 159.0, 'high_price' => 162.66, 'low_price' => 158.68,
            'close_price' => 162.66, 'adjusted_close_price' => 162.66, 'volume' => 101471,
            'data_source' => 'official_bhavcopy', 'created_at' => now(),
        ]);
        $issue = DataQualityIssue::query()->create([
            'stock_id' => $stock->id, 'symbol' => 'BNAGROCHEM',
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'exchange_feed', 'suggested_ratio' => 10,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
            'exchange_match' => true, 'detected_at' => now(),
        ]);
        app(DataQualityIssueService::class)->attachEvidence($issue, [
            ['evidence_key' => 'bnagrochem_listing_circular', 'evidence_payload' => [
                'document' => 'CML77412', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CML77412.pdf',
                'sha256' => 'c0faa54145565b1650c98748562c81b5444dc63d53b57c39f5e28e07aa8b4532',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'new_isin' => 'INE00HZ01029', 'ex_date' => '2026-11-16',
            ]],
            ['evidence_key' => 'bnagrochem_clearing_circular', 'evidence_payload' => [
                'document' => 'CMPT77438', 'url' => 'https://nsearchives.nseindia.com/content/circulars/CMPT77438.pdf',
                'sha256' => '70d93d11da034e34af5184e63af651b04d826f7935ca375bc0c14bc116e6c454',
                'symbol' => 'BNAGROCHEM', 'old_isin' => 'INE00HZ01011', 'ex_date' => '2026-11-16',
            ]],
            ['evidence_key' => 'bnagrochem_bhavcopy', 'evidence_payload' => [
                'source_url' => 'https://nsearchives.nseindia.com/content/cm/BhavCopy_NSE_CM_0_0_0_20261113_F_0000.csv.zip',
                'source_archive_sha256' => '758ec3b60fd492a29ff3ebc6c069269bc326dc5ca662cfba8892f16939841b7b',
                'source_csv_sha256' => '5736864df9797ba7b0427ec91dc506b5cd07c283edaefa8cf3f5609da4720b9e',
                'trade_date' => '2026-11-13', 'symbol' => 'BNAGROCHEM', 'isin' => 'INE00HZ01011',
                'instrument_id' => 762608, 'open' => 159.00, 'high' => 162.66, 'low' => 158.68,
                'close' => 162.66, 'volume' => 101471,
            ]],
        ]);

        $admin = $this->createAdminUser();
        $accepted = app(DataQualityResolutionService::class)->accept($issue, 10.0, 'Reviewed exact official source bundle.', $admin->id);
        $firstResolution = $accepted->latestResolution;
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->where('is_active', true)->firstOrFail();
        $this->assertSame(10.0, (float) $factor->applied_ratio);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);
        $this->assertSame($price->getKey(), $price->fresh()->getKey());
        $this->assertSame('162.6600', number_format((float) $price->fresh()->close_price, 4, '.', ''));

        try {
            app(DataQualityResolutionService::class)->accept($accepted, 10.0, 'Replay.', $admin->id);
            $this->fail('A repeated acceptance must be rejected as stale.');
        } catch (DomainException $exception) {
            $this->assertSame('DATA_QUALITY_STALE_RESOLUTION', $exception->errorCode());
        }

        $rejected = app(DataQualityResolutionService::class)->reject($accepted, 'Rollback staged F042 factor.', $admin->id, requirePendingReview: false);
        $latestResolution = $rejected->latestResolution;
        $this->assertFalse((bool) $factor->fresh()->is_active);
        $this->assertTrue((bool) $latestResolution->is_reversal);
        $this->assertSame($firstResolution->id, $latestResolution->supersedes_resolution_id);
        $this->assertSame(2, $issue->resolutions()->count());
        $this->assertSame('162.6600', number_format((float) $price->fresh()->close_price, 4, '.', ''));
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
