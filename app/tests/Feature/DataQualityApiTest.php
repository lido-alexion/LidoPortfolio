<?php

namespace Tests\Feature;

use App\Models\CorporateAction;
use App\Models\DataQualityIssue;
use App\Models\PortfolioProfile;
use App\Models\PriceAdjustmentFactor;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\CorporateActionPriceRepairService;
use App\Services\DataQualityLegacyCorporateActionMigrationService;
use App\Services\DataQualityCorporateActionSyncService;
use App\Services\DataQualityResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    public function test_authenticated_admin_can_explicitly_accept_corporate_action_without_collected_evidence(): void
    {
        [$stock, $issue] = $this->createBnagrochemIssue();
        $admin = $this->createAdminUser();

        $this->assertSame(0, $issue->evidences()->count());
        $this->actingAs($admin)->postJson("/api/data-quality/issues/{$issue->id}/accept", [
            'applied_ratio' => 10,
            'manual_evidence_override' => true,
            'confirm_manual_evidence_override' => true,
            'manual_source_reference' => 'Owner-verified off-platform circular',
            'manual_justification' => 'Independent review confirmed the split terms.',
        ])->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_ACCEPTED);

        $resolution = $issue->fresh()->resolutions()->latest('id')->firstOrFail();
        $this->assertSame('admin_verified_manual_override', $resolution->metadata['provenance']);
        $this->assertSame($admin->id, $resolution->resolved_by);
        $this->assertSame('Owner-verified off-platform circular', $resolution->metadata['manual_override_context']['source_reference']);
        $this->assertSame(1, PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->where('is_active', true)->count());
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->firstOrFail()->metadata['ohlcv_repair_status']);
        $this->assertSame(0, StockPrice::query()->where('stock_id', $stock->id)->count(), 'Approval must stage F042 only; it must not directly rewrite prices.');
    }

    public function test_manual_evidence_override_requires_explicit_confirmation_and_bounded_ratio(): void
    {
        [, $issue] = $this->createBnagrochemIssue();
        $admin = $this->createAdminUser();

        $this->actingAs($admin)->postJson("/api/data-quality/issues/{$issue->id}/accept", [
            'manual_evidence_override' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['confirm_manual_evidence_override']);

        $this->actingAs($admin)->postJson("/api/data-quality/issues/{$issue->id}/accept", [
            'applied_ratio' => 10001,
            'manual_evidence_override' => true,
            'confirm_manual_evidence_override' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['applied_ratio']);

        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->fresh()->issue_status);
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
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
        $this->actingAs($user)
            ->post("/api/data-quality/issues/{$issue->id}/stage-bnagrochem-f042-evidence", $this->officialBnagrochemEvidenceFiles())
            ->assertForbidden();
        $this->actingAs($user)
            ->post('/api/data-quality/discover-bnagrochem-historical-action', $this->officialBnagrochemEvidenceFiles())
            ->assertForbidden();
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());

        $this->getJson('/api/data-quality/dashboard')->assertUnauthorized();
        $this->postJson("/api/data-quality/issues/{$issue->id}/accept")->assertUnauthorized();
        $this->post('/api/data-quality/discover-bnagrochem-historical-action', $this->officialBnagrochemEvidenceFiles())->assertUnauthorized();
    }

    public function test_completed_repair_rollback_api_requires_admin_and_audit_note(): void
    {
        $admin = $this->createAdminUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        $this->actingAs($admin)->postJson("/api/data-quality/issues/{$issue->id}/rollback-completed-repair", ['notes' => '   '])->assertUnprocessable()->assertJsonValidationErrors(['notes']);
    }

    public function test_non_admin_cannot_call_completed_repair_rollback(): void
    {
        $regular = $this->createRegularUser();
        $issue = $this->createPendingExchangeIssue($this->createDataQualityStock());
        $this->actingAs($regular)->postJson("/api/data-quality/issues/{$issue->id}/rollback-completed-repair", ['notes' => 'x'])->assertForbidden();
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

    public function test_bn_agrochem_staging_rejects_out_of_scope_issue_with_genuine_source_bundle(): void
    {
        [$stock, $issue] = $this->createBnagrochemIssue(9.0);
        $admin = $this->createAdminUser();
        $files = $this->officialBnagrochemEvidenceFiles();

        $this->actingAs($admin)
            ->post("/api/data-quality/issues/{$issue->id}/stage-bnagrochem-f042-evidence", $files)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DATA_QUALITY_SOURCE_EVIDENCE_REQUIRED');

        $this->assertSame(DataQualityIssue::STATUS_PENDING_REVIEW, $issue->fresh()->issue_status);
        $this->assertDatabaseMissing('portfolio_data_quality_issue_evidence', ['issue_id' => $issue->id, 'evidence_key' => 'bnagrochem_listing_circular']);
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
        $this->assertSame(0, StockPrice::query()->where('stock_id', $stock->id)->count());
    }

    public function test_f042_staging_rejects_mismatched_genuine_official_artifact_without_mutation(): void
    {
        [, $issue] = $this->createBnagrochemIssue();
        $admin = $this->createAdminUser();
        $files = $this->officialBnagrochemEvidenceFiles();
        $files['listing_circular'] = new \Illuminate\Http\UploadedFile(
            base_path('tests/Fixtures/bnagrochem-f042-official/CMPT77438.pdf'), 'CMPT77438.pdf', 'application/pdf', null, true,
        );

        $this->actingAs($admin)
            ->post("/api/data-quality/issues/{$issue->id}/stage-bnagrochem-f042-evidence", $files)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DATA_QUALITY_SOURCE_EVIDENCE_INVALID');

        $this->assertSame(0, $issue->evidences()->whereIn('evidence_key', [
            'bnagrochem_listing_circular', 'bnagrochem_clearing_circular', 'bnagrochem_bhavcopy',
        ])->count());
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
    }

    public function test_genuine_f042_bundle_stages_idempotently_then_human_acceptance_only_stages_factor(): void
    {
        [$stock, $issue, $sync] = $this->syncBnagrochemIssueFromFeed();
        $this->assertSame(1, $sync['created']);
        $this->assertSame(1, $sync['synced']);
        $this->assertSame(DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED, $issue->detection_method);
        $this->assertSame('1:10', $issue->evidences()->where('evidence_key', 'exchange_ratio')->value('evidence_value'));
        $price = StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-11-13',
            'open_price' => 159.0, 'high_price' => 162.66, 'low_price' => 158.68,
            'close_price' => 162.66, 'adjusted_close_price' => 162.66, 'volume' => 101471,
            'data_source' => 'official_bhavcopy', 'created_at' => now(),
        ]);
        $admin = $this->createAdminUser();
        $stageUrl = "/api/data-quality/issues/{$issue->id}/stage-bnagrochem-f042-evidence";

        $this->actingAs($admin)->post($stageUrl, $this->officialBnagrochemEvidenceFiles())
            ->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_PENDING_REVIEW);
        $this->actingAs($admin)->post($stageUrl, $this->officialBnagrochemEvidenceFiles())
            ->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_PENDING_REVIEW);
        $this->assertSame(3, $issue->evidences()->whereIn('evidence_key', [
            'bnagrochem_listing_circular', 'bnagrochem_clearing_circular', 'bnagrochem_bhavcopy',
        ])->count());
        $this->assertSame(1, $issue->evidences()->where('evidence_key', 'exchange_ratio')->count());
        $listingEvidence = $issue->evidences()->where('evidence_key', 'bnagrochem_listing_circular')->firstOrFail()->evidence_payload;
        $bhavcopyEvidence = $issue->evidences()->where('evidence_key', 'bnagrochem_bhavcopy')->firstOrFail()->evidence_payload;
        $this->assertSame('c0faa54145565b1650c98748562c81b5444dc63d53b57c39f5e28e07aa8b4532', $listingEvidence['sha256']);
        $this->assertSame('758ec3b60fd492a29ff3ebc6c069269bc326dc5ca662cfba8892f16939841b7b', $bhavcopyEvidence['source_archive_sha256']);
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);

        $accepted = $this->postJson("/api/data-quality/issues/{$issue->id}/accept", [
            'applied_ratio' => 10, 'notes' => 'Human reviewed the exact official CML77412, CMPT77438 and NSE archive evidence.',
        ])->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_ACCEPTED);
        $humanResolution = $issue->resolutions()->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $humanResolution->resolved_by);
        $this->assertFalse((bool) $humanResolution->is_auto);
        $factor = PriceAdjustmentFactor::query()->where('issue_id', $issue->id)->where('is_active', true)->firstOrFail();
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_PENDING, $factor->metadata['ohlcv_repair_status']);
        $this->assertSame('162.6600', number_format((float) $price->fresh()->close_price, 4, '.', ''));

        $repair = app(CorporateActionPriceRepairService::class)->repairPendingFactors(factorId: (int) $factor->id, dryRun: false);
        $this->assertSame(1, $repair['repaired']);
        $this->assertSame('16.2660', number_format((float) $price->fresh()->close_price, 4, '.', ''));
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_COMPLETED, $factor->fresh()->metadata['ohlcv_repair_status']);

        $this->actingAs($admin)->postJson("/api/data-quality/issues/{$issue->id}/rollback-completed-repair", [
            'notes' => 'Restore the exact captured official-fixture before-image.',
        ])->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_REJECTED);
        $this->assertSame('162.6600', number_format((float) $price->fresh()->close_price, 4, '.', ''));
        $this->assertSame(101471, (int) $price->fresh()->volume);
        $this->assertSame(PriceAdjustmentFactor::REPAIR_STATUS_ROLLED_BACK, $factor->fresh()->metadata['ohlcv_repair_status']);

        $this->postJson("/api/data-quality/issues/{$issue->id}/accept", ['notes' => 'stale admin acceptance'])
            ->assertStatus(409)->assertJsonPath('error.code', 'DATA_QUALITY_STALE_RESOLUTION');
    }

    public function test_admin_can_discover_missing_historical_issue_from_exact_bundle_idempotently_and_pending_only(): void
    {
        Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        $admin = $this->createAdminUser();
        $url = '/api/data-quality/discover-bnagrochem-historical-action';

        $this->actingAs($admin)->post($url, $this->officialBnagrochemEvidenceFiles())
            ->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.suggested_ratio', '10.000000');
        $this->actingAs($admin)->post($url, $this->officialBnagrochemEvidenceFiles())
            ->assertOk()->assertJsonPath('data.issue_status', DataQualityIssue::STATUS_PENDING_REVIEW);

        $issue = DataQualityIssue::query()->where('stock_id', 7096)->firstOrFail();
        $this->assertSame(1, DataQualityIssue::query()->where('stock_id', 7096)->count());
        $this->assertSame(1, $issue->evidences()->where('evidence_key', 'exchange_ratio')->count());
        $this->assertSame(3, $issue->evidences()->whereIn('evidence_key', [
            'bnagrochem_listing_circular', 'bnagrochem_clearing_circular', 'bnagrochem_bhavcopy',
        ])->count());
        $this->assertDatabaseMissing('portfolio_price_adjustment_factors', ['issue_id' => $issue->id]);
        $this->assertDatabaseCount('portfolio_price_adjustment_factors', 0);
        $this->assertDatabaseCount('portfolio_stock_prices', 0);
        $auditPayload = $issue->evidences()->where('evidence_key', 'exchange_ratio')->firstOrFail()->evidence_payload;
        $this->assertSame('official_historical_discovery', $auditPayload['source']);
        $this->assertNotEmpty($auditPayload['detection_run_id']);
    }

    public function test_historical_discovery_requires_admin_and_rejects_tampered_bytes_before_issue_creation(): void
    {
        Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        $url = '/api/data-quality/discover-bnagrochem-historical-action';
        $files = $this->officialBnagrochemEvidenceFiles();
        $bytes = file_get_contents(base_path('tests/Fixtures/bnagrochem-f042-official/bhavcopy.zip'));
        $bytes[100] = chr(ord($bytes[100]) ^ 1);
        $files['bhavcopy_archive'] = \Illuminate\Http\UploadedFile::fake()->createWithContent('bhavcopy.zip', $bytes);
        $this->actingAs($this->createAdminUser())->post($url, $files)
            ->assertUnprocessable()->assertJsonPath('error.code', 'DATA_QUALITY_SOURCE_EVIDENCE_INVALID');
        $this->assertDatabaseCount('portfolio_data_quality_issues', 0);
        $this->assertDatabaseCount('portfolio_price_adjustment_factors', 0);
    }

    public function test_historical_discovery_fails_closed_when_duplicate_event_records_exist(): void
    {
        Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        foreach ([DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED, DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP] as $method) {
            DataQualityIssue::query()->create([
                'stock_id' => 7096, 'symbol' => 'BNAGROCHEM', 'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
                'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW, 'detection_method' => $method,
                'detection_source' => 'test', 'suggested_ratio' => 10, 'latest_suggested_ratio' => 10,
                'corporate_action_type' => 'split', 'ex_date' => '2026-11-16', 'exchange_match' => true, 'detected_at' => now(),
            ]);
        }

        $this->actingAs($this->createAdminUser())
            ->post('/api/data-quality/discover-bnagrochem-historical-action', $this->officialBnagrochemEvidenceFiles())
            ->assertStatus(409)->assertJsonPath('error.code', 'DATA_QUALITY_SOURCE_EVIDENCE_CONFLICT');
        $this->assertSame(2, DataQualityIssue::query()->where('stock_id', 7096)->count());
        $this->assertDatabaseCount('portfolio_price_adjustment_factors', 0);
    }

    public function test_historical_discovery_does_not_reopen_resolved_event(): void
    {
        Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        DataQualityIssue::query()->create([
            'stock_id' => 7096, 'symbol' => 'BNAGROCHEM', 'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_REJECTED, 'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'test', 'suggested_ratio' => 10, 'latest_suggested_ratio' => 10,
            'corporate_action_type' => 'split', 'ex_date' => '2026-11-16', 'exchange_match' => true, 'detected_at' => now(),
        ]);

        $this->actingAs($this->createAdminUser())
            ->post('/api/data-quality/discover-bnagrochem-historical-action', $this->officialBnagrochemEvidenceFiles())
            ->assertStatus(409)->assertJsonPath('error.code', 'DATA_QUALITY_STALE_RESOLUTION');
        $this->assertSame(1, DataQualityIssue::query()->where('stock_id', 7096)->count());
        $this->assertDatabaseCount('portfolio_price_adjustment_factors', 0);
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

    /** @return array{0:Stock,1:DataQualityIssue} */
    private function createBnagrochemIssue(float $ratio = 10.0): array
    {
        $stock = Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        $issue = DataQualityIssue::query()->create([
            'stock_id' => $stock->id, 'symbol' => 'BNAGROCHEM',
            'issue_type' => DataQualityIssue::TYPE_CORPORATE_ACTION,
            'issue_status' => DataQualityIssue::STATUS_PENDING_REVIEW,
            'detection_method' => DataQualityIssue::DETECTION_METHOD_EXCHANGE_FEED,
            'detection_source' => 'exchange_feed', 'suggested_ratio' => $ratio,
            'corporate_action_type' => 'face_value_split', 'ex_date' => '2026-11-16',
            'exchange_match' => true, 'detected_at' => now(),
        ]);
        return [$stock, $issue];
    }

    /** @return array{0:Stock,1:DataQualityIssue,2:array<string,mixed>} */
    private function syncBnagrochemIssueFromFeed(): array
    {
        $stock = Stock::query()->forceCreate([
            'id' => 7096, 'symbol' => 'BNAGROCHEM', 'exchange' => 'NSE', 'series' => 'EQ',
            'name' => 'BNAGROCHEM fixture', 'isin' => 'INE00HZ01029', 'is_active' => true,
        ]);
        config(['services.data_quality.corporate_actions_feed_url' => 'https://fixture.invalid/ca.json']);
        Http::fake([
            'https://fixture.invalid/ca.json' => Http::response([[
                'symbol' => 'BNAGROCHEM', 'action_type' => 'face_value_split',
                'ratio' => '1:10', 'ex_date' => '2026-11-16', 'source' => 'test-feed-contract',
            ]], 200),
        ]);
        $sync = app(DataQualityCorporateActionSyncService::class)->syncFromExchangeFeed();
        $issue = DataQualityIssue::query()->where('stock_id', $stock->id)->firstOrFail();
        return [$stock, $issue, $sync];
    }

    /** @return array<string,\Symfony\Component\HttpFoundation\File\UploadedFile> */
    private function officialBnagrochemEvidenceFiles(): array
    {
        $directory = base_path('tests/Fixtures/bnagrochem-f042-official');
        return [
            'listing_circular' => new \Illuminate\Http\UploadedFile($directory.'/CML77412.pdf', 'CML77412.pdf', 'application/pdf', null, true),
            'clearing_circular' => new \Illuminate\Http\UploadedFile($directory.'/CMPT77438.pdf', 'CMPT77438.pdf', 'application/pdf', null, true),
            'bhavcopy_archive' => new \Illuminate\Http\UploadedFile($directory.'/bhavcopy.zip', 'bhavcopy.zip', 'application/zip', null, true),
        ];
    }

}
