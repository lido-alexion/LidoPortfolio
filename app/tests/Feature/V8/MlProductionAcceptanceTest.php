<?php

namespace Tests\Feature\V8;

use App\Jobs\MlRetrainJob;
use App\Models\Stock;
use App\Models\User;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Models\V8\MlAcceptanceCampaign;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseMembership;
use App\Models\V8\MlUniverseSnapshotBoundary;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\MlAcceptanceCampaignService;
use App\Services\ML\MlAcceptanceEvidenceService;
use App\Services\ML\MlAcceptanceRuntime;
use App\Services\ML\MlAcceptanceSourceService;
use App\Services\ML\MlArtifactRetentionService;
use App\Services\ML\MlLifecycleAutomationService;
use App\Services\ML\MlPythonAdapter;
use App\Services\ML\MlTrainingDatasetBuilder;
use App\Services\ML\MlTrainingRunRecoveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MlProductionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $sourceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-30 12:00:00'));
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceIds as $id) {
            File::deleteDirectory(storage_path('app/private/ml-acceptance/sources/'.$id));
        }
        parent::tearDown();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->defaultPortfolioFor($admin);

        return $admin;
    }

    private function source(string $contents = "SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,30-SEP-2026\n", string $date = '2026-09-30'): MlAcceptanceSource
    {
        $service = app(MlAcceptanceSourceService::class);
        $filename = 'cm'.strtoupper(Carbon::parse($date)->format('dMY')).'bhav.csv';
        $source = $service->create(['version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => $date,
            'filename' => $filename, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)], 1);
        $this->sourceIds[] = $source->id;
        $service->chunk($source, 0, $contents, 1);
        $service->finalize($source, 1);
        $service->validateQueued($source->id);

        return $source->refresh();
    }

    public function test_linked_backfill_preview_requires_a_real_source_blocker_and_exact_campaign_dates(): void
    {
        $admin = $this->admin();
        $campaign = MlAcceptanceCampaign::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $admin->id,
            'cutoff_date' => '2026-09-30', 'status' => 'blocked', 'identity' => [], 'active_models' => [],
            'horizons' => ['1m' => ['snapshots' => ['2026-09-01' => ['status' => 'missing_or_unproven']], 'blocking_reasons' => ['snapshot_unproven:2026-09-01']]], 'history' => []]);
        $source = $this->source("SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,01-SEP-2026\n", '2026-09-01');
        $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], $admin->id, $campaign->id);
        $this->assertSame($campaign->id, $run->acceptance['campaign_id']);

        $wrongDate = MlAcceptanceCampaign::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $admin->id,
            'cutoff_date' => '2026-09-30', 'status' => 'blocked', 'identity' => [], 'active_models' => [],
            'horizons' => ['1m' => ['snapshots' => ['2026-09-02' => ['status' => 'missing_or_unproven']], 'blocking_reasons' => ['snapshot_unproven:2026-09-02']]], 'history' => []]);
        try {
            $service->preview([$source->id], $admin->id, $wrongDate->id);
            $this->fail('A source for another campaign date was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('stox_ml_universe_snapshot_backfill_runs', 1);
        }

        $unrelated = MlAcceptanceCampaign::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $admin->id,
            'cutoff_date' => '2026-09-30', 'status' => 'blocked', 'identity' => [], 'active_models' => [],
            'horizons' => ['1m' => ['snapshots' => [], 'blocking_reasons' => ['historical_breadth_unavailable']]], 'history' => []]);
        try {
            $service->preview([$source->id], $admin->id, $unrelated->id);
            $this->fail('A non-source blocker was accepted as a source-remediation campaign.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('stox_ml_universe_snapshot_backfill_runs', 1);
        }

        $unsupported = MlAcceptanceSource::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $admin->id, 'status' => 'sealed',
            'manifest' => ['source' => 'unsupported_source_family', 'date' => '2026-09-01', 'filename' => 'unsupported.csv', 'bytes' => 10, 'sha256' => str_repeat('c', 64)],
            'received' => 10, 'evidence' => [], 'history' => []]);
        try {
            $service->preview([$unsupported->id], $admin->id, $campaign->id);
            $this->fail('An unsupported source family reached backfill preview.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('stox_ml_universe_snapshot_backfill_runs', 1);
        }
    }

    public function test_report_is_admin_only_read_only_and_explicit_about_unknown_evidence(): void
    {
        $this->getJson('/api/v1/admin/ml/acceptance')->assertUnauthorized();
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user)->getJson('/api/v1/admin/ml/acceptance')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/ml/acceptance')->assertOk()
            ->assertJsonPath('data.readiness.ready', false)->assertJsonPath('data.runtime.python_evidence.1m', null)
            ->assertJsonPath('data.horizons.1m.reference_dates', null)
            ->assertJsonPath('data.runtime.queue_configuration_ready', true)
            ->assertJsonPath('data.runtime.worker_evidence.1m', null);
        $this->assertDatabaseCount('stox_ml_acceptance_campaigns', 0);
        $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        Bus::assertNothingDispatched();
    }

    public function test_wizard_read_model_is_admin_only_read_only_and_recovers_server_owned_step_state(): void
    {
        $this->getJson('/api/v1/admin/ml/acceptance/wizard')->assertUnauthorized();
        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $this->actingAs($user)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertOk()
            ->assertJsonPath('data.current_step', 2)
            ->assertJsonCount(7, 'data.steps')
            ->assertJsonPath('data.steps.1.status', 'action_required')
            ->assertJsonPath('data.steps.1.reason_codes.0', 'preflight_not_recorded');
        $this->assertDatabaseCount('stox_ml_acceptance_campaigns', 0);
        $this->assertDatabaseCount('stox_ml_universe_snapshot_backfill_runs', 0);
        Bus::assertNothingDispatched();

        $campaign = MlAcceptanceCampaign::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $admin->id,
            'cutoff_date' => '2026-09-30', 'status' => 'blocked', 'identity' => [], 'active_models' => [],
            'horizons' => ['1m' => ['reference_dates' => ['2026-09-01'], 'snapshots' => ['2026-09-01' => ['status' => 'missing_or_unproven']], 'blocking_reasons' => ['snapshot_unproven:2026-09-01']]], 'history' => []]);
        $backfill = \App\Models\V8\MlUniverseSnapshotBackfillRun::query()->create(['universe_key' => 'active_eligible_nse', 'source' => 'admin_sealed_nse',
            'requested_dates' => ['2026-09-01'], 'processed_dates' => [], 'failed_dates' => [], 'retry_counts' => [], 'status' => 'running',
            'acceptance' => ['mode' => 'preview', 'campaign_id' => $campaign->id, 'cursor' => 0, 'results' => [], 'history' => []]]);
        $payload = $this->actingAs($admin)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertOk()
            ->assertJsonPath('data.campaign.id', $campaign->id)
            ->assertJsonPath('data.backfill.id', $backfill->id)
            ->assertJsonPath('data.backfill.mode', 'preview')
            ->assertJsonPath('data.steps.3.status', 'in_progress')
            ->assertJsonMissingPath('data.backfill.last_error');
        $this->assertStringNotContainsString(storage_path(), $payload->getContent());
        Bus::assertNothingDispatched();
    }

    public function test_wizard_blocks_discovery_when_required_queue_configuration_is_not_ready(): void
    {
        config(['queue.connections.ml-acceptance.driver' => 'sync']);
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertOk()
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonPath('data.steps.0.status', 'blocked')
            ->assertJsonPath('data.steps.0.reason_codes.0', 'queue_configuration_not_ready')
            ->assertJsonPath('data.steps.1.status', 'action_required');
        $this->assertDatabaseCount('stox_ml_acceptance_campaigns', 0);
        Bus::assertNothingDispatched();
    }

    public function test_wizard_step_mapping_covers_ready_running_local_complete_and_non_source_blocked_campaigns(): void
    {
        $admin = $this->admin();
        $id = (string) Str::uuid();
        $horizons = array_fill_keys(['1m', '3m', '6m'], ['snapshots' => [], 'blocking_reasons' => []]);
        $campaign = MlAcceptanceCampaign::query()->create(['id' => $id, 'actor_id' => $admin->id, 'cutoff_date' => '2026-09-30',
            'status' => 'ready', 'identity' => app(MlAcceptanceRuntime::class)->identity(), 'active_models' => [], 'horizons' => $horizons, 'history' => []]);
        $url = '/api/v1/admin/ml/acceptance/wizard';

        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.steps.2.status', 'not_required')
            ->assertJsonPath('data.steps.3.status', 'not_required')
            ->assertJsonPath('data.steps.4.status', 'complete')
            ->assertJsonPath('data.steps.5.status', 'action_required')
            ->assertJsonPath('data.current_step', 6);

        $campaign->forceFill(['status' => 'preflight'])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.steps.1.status', 'in_progress')->assertJsonPath('data.current_step', 2);

        $campaign->forceFill(['status' => 'training'])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.steps.5.status', 'in_progress')->assertJsonPath('data.current_step', 6);

        $campaign->forceFill(['status' => 'local_complete'])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.steps.4.status', 'complete')
            ->assertJsonPath('data.steps.5.status', 'complete')->assertJsonPath('data.steps.6.status', 'action_required')->assertJsonPath('data.current_step', 7);

        $blockedHorizons = array_fill_keys(['1m', '3m', '6m'], ['snapshots' => [], 'blocking_reasons' => ['membership_coverage_incomplete']]);
        $campaign->forceFill(['status' => 'blocked', 'horizons' => $blockedHorizons])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.steps.1.status', 'blocked')
            ->assertJsonPath('data.steps.1.reason_codes.0', 'membership_coverage_incomplete')
            ->assertJsonPath('data.steps.4.status', 'blocked')->assertJsonPath('data.current_step', 2);
    }

    public function test_wizard_binds_backfill_and_sources_to_the_current_campaign_and_reports_family_options(): void
    {
        $admin = $this->admin();
        $oldId = (string) \Illuminate\Support\Str::uuid();
        $oldCampaign = MlAcceptanceCampaign::query()->create(['id' => $oldId, 'actor_id' => $admin->id, 'cutoff_date' => '2026-09-20',
            'status' => 'blocked', 'identity' => [], 'active_models' => [], 'horizons' => ['1m' => ['snapshots' => ['2026-09-01' => ['status' => 'missing_or_unproven']], 'blocking_reasons' => ['snapshot_unproven:2026-09-01']]], 'history' => [],
            'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()]);
        $oldBackfill = \App\Models\V8\MlUniverseSnapshotBackfillRun::query()->create(['universe_key' => 'active_eligible_nse', 'source' => 'admin_sealed_nse',
            'requested_dates' => ['2026-09-01'], 'processed_dates' => [], 'failed_dates' => [], 'retry_counts' => [], 'status' => 'running',
            'acceptance' => ['mode' => 'apply', 'campaign_id' => $oldId, 'cursor' => 0, 'results' => [], 'history' => []]]);

        $currentId = (string) \Illuminate\Support\Str::uuid();
        $currentCampaign = MlAcceptanceCampaign::query()->create(['id' => $currentId, 'actor_id' => $admin->id, 'cutoff_date' => '2026-09-25',
            'status' => 'blocked', 'identity' => [], 'active_models' => [], 'horizons' => ['1m' => ['snapshots' => ['2026-09-02' => ['status' => 'missing_or_unproven']], 'blocking_reasons' => ['snapshot_unproven:2026-09-02']]], 'history' => []]);
        $currentBackfill = \App\Models\V8\MlUniverseSnapshotBackfillRun::query()->create(['universe_key' => 'active_eligible_nse', 'source' => 'admin_sealed_nse',
            'requested_dates' => ['2026-09-02'], 'processed_dates' => ['2026-09-02'], 'failed_dates' => [], 'retry_counts' => [], 'status' => 'completed',
            'acceptance' => ['mode' => 'apply', 'campaign_id' => $currentId, 'cursor' => 1, 'results' => [], 'history' => []]]);
        \App\Models\V8\MlAcceptanceSource::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $admin->id, 'status' => 'sealed',
            'manifest' => ['source' => 'nse_mii_security_file', 'date' => '2026-09-02', 'filename' => 'MII02092026.csv', 'bytes' => 10, 'sha256' => str_repeat('a', 64)], 'received' => 10, 'evidence' => [], 'history' => []]);
        \App\Models\V8\MlAcceptanceSource::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $admin->id, 'status' => 'sealed',
            'manifest' => ['source' => 'unsupported_source_family', 'date' => '2026-09-02', 'filename' => 'other.csv', 'bytes' => 10, 'sha256' => str_repeat('b', 64)], 'received' => 10, 'evidence' => [], 'history' => []]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertOk()
            ->assertJsonPath('data.campaign.id', $currentCampaign->id)
            ->assertJsonPath('data.backfill.id', $currentBackfill->id)
            ->assertJsonPath('data.required_sources.0.date', '2026-09-02')
            ->assertJsonPath('data.required_sources.0.accepted_families.0', 'nse_mii_security_file')
            ->assertJsonPath('data.required_sources.0.accepted_families.1', 'nse_cash_bhavcopy')
            ->assertJsonCount(1, 'data.required_sources.0.sources');
        $this->assertSame('completed', $response->json('data.backfill.status'));
        $this->assertNotSame($oldCampaign->id, $response->json('data.campaign.id'));
        $this->assertNotSame($oldBackfill->id, $response->json('data.backfill.id'));
        Bus::assertNothingDispatched();
    }

    public function test_wizard_endpoint_tracks_sealed_source_preview_apply_and_fresh_preflight_lineage(): void
    {
        $admin = $this->admin();
        $campaign = MlAcceptanceCampaign::query()->create(['id' => (string) Str::uuid(), 'wizard_sequence' => 1, 'actor_id' => $admin->id,
            'cutoff_date' => '2026-09-30', 'status' => 'blocked', 'identity' => [], 'active_models' => [],
            'horizons' => ['1m' => ['snapshots' => ['2026-09-01' => ['status' => 'missing_or_unproven']],
                'blocking_reasons' => ['snapshot_unproven:2026-09-01']]], 'history' => []]);
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $source = $this->source("SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,01-SEP-2026\n", '2026-09-01');
        $url = '/api/v1/admin/ml/acceptance/wizard';

        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('data.current_step', 3)
            ->assertJsonPath('data.steps.2.status', 'action_required')
            ->assertJsonPath('data.required_sources.0.sources.0.id', $source->id);

        $preview = $this->postJson('/api/v1/admin/ml/acceptance/backfills/preview', ['sources' => [$source->id], 'campaign_id' => $campaign->id])
            ->assertAccepted()->json('data');
        $this->assertSame(4, $this->getJson($url)->assertOk()->json('data.current_step'));
        $backfills = app(MlAcceptanceBackfillService::class);
        $backfills->step($preview['id']);
        $previewState = $this->getJson($url)->assertOk();
        $previewState->assertJsonPath('data.backfill.can_apply', true);
        $this->postJson('/api/v1/admin/ml/acceptance/backfills/'.$preview['id'].'/apply')->assertOk();
        $backfills->step($preview['id']);
        $backfills->step($preview['id']);
        $applied = $this->getJson($url)->assertOk()->assertJsonPath('data.steps.3.status', 'complete')->json('data');

        $this->postJson('/api/v1/admin/ml/acceptance/campaigns', [
            'cutoff_date' => '2026-09-30', 'final_preflight' => true, 'remediation_backfill_id' => $preview['id'],
        ])->assertAccepted();
        $fresh = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.campaign.final_preflight', true)
            ->assertJsonPath('data.backfill.id', $preview['id'])
            ->assertJsonPath('data.steps.3.status', 'complete');
        $fresh->assertJsonPath('data.steps.4.status', 'in_progress');
        $this->assertNotSame($campaign->id, $fresh->json('data.campaign.id'));
        $this->assertGreaterThan($campaign->wizard_sequence, MlAcceptanceCampaign::query()->findOrFail($fresh->json('data.campaign.id'))->wizard_sequence);
        $this->assertSame($preview['id'], $applied['backfill']['id']);
        $finalCampaignId = $fresh->json('data.campaign.id');
        $this->mock(MlAcceptanceEvidenceService::class)->shouldReceive('preflight')->times(3)
            ->andReturn(['blocking_reasons' => [], 'dataset_sha256' => str_repeat('c', 64)]);
        $campaigns = app(MlAcceptanceCampaignService::class);
        for ($i = 0; $i < 3; $i++) { $campaigns->step($finalCampaignId); }
        $ready = $this->getJson($url)->assertOk()
            ->assertJsonPath('data.campaign.status', 'ready')
            ->assertJsonPath('data.steps.4.status', 'complete')
            ->assertJsonPath('data.steps.5.status', 'action_required');
        $this->postJson('/api/v1/admin/ml/acceptance/campaigns/'.$finalCampaignId.'/start')->assertOk();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.campaign.status', 'training')
            ->assertJsonPath('data.current_step', 6)
            ->assertJsonPath('data.steps.5.status', 'in_progress')
            ->assertJsonPath('data.steps.6.status', 'action_required');
        $this->assertSame($finalCampaignId, $ready->json('data.campaign.id'));
        $campaignModel = MlAcceptanceCampaign::query()->findOrFail($finalCampaignId);
        foreach ($campaignModel->horizons as $evidence) {
            MlTrainingRun::query()->findOrFail($evidence['run_id'])->forceFill(['status' => 'completed_rejected'])->save();
        }
        $campaigns->step($finalCampaignId);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.campaign.status', 'failed')
            ->assertJsonPath('data.steps.4.status', 'complete')
            ->assertJsonPath('data.steps.5.status', 'failed')
            ->assertJsonPath('data.steps.6.status', 'failed')
            ->assertJsonPath('data.current_step', 6);
        $campaignModel->forceFill(['status' => 'blocked', 'horizons' => [
            '1m' => ['blocking_reasons' => ['canonical_dataset_or_coverage_unavailable']],
            '3m' => ['blocking_reasons' => []], '6m' => ['blocking_reasons' => []],
        ]])->save();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.steps.1.status', 'complete')
            ->assertJsonPath('data.steps.4.status', 'blocked')
            ->assertJsonPath('data.steps.4.reason_codes.0', 'canonical_dataset_or_coverage_unavailable')
            ->assertJsonPath('data.current_step', 5);

        $cancelledId = $this->postJson('/api/v1/admin/ml/acceptance/campaigns', ['cutoff_date' => '2026-09-30'])
            ->assertAccepted()->json('data.id');
        $this->postJson('/api/v1/admin/ml/acceptance/campaigns/'.$cancelledId.'/cancel')->assertOk();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.campaign.id', $cancelledId)
            ->assertJsonPath('data.campaign.status', 'cancelled')
            ->assertJsonPath('data.steps.1.status', 'cancelled')
            ->assertJsonPath('data.current_step', 2);
        Bus::assertDispatched(\App\Jobs\MlAcceptanceJob::class);
        Bus::assertDispatchedTimes(MlRetrainJob::class, 3);
    }

    public function test_wizard_exposes_server_evidence_freshness_without_reconstructing_it(): void
    {
        $admin = $this->admin();
        MlAcceptanceCampaign::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => $admin->id, 'cutoff_date' => '2026-09-30',
            'status' => 'qualified', 'identity' => ['build_id' => 'old-build'], 'active_models' => [], 'horizons' => [], 'history' => [], 'qualified_at' => now()]);

        $this->actingAs($admin)->getJson('/api/v1/admin/ml/acceptance/wizard')->assertOk()
            ->assertJsonPath('data.campaign.status', 'qualified')
            ->assertJsonPath('data.evidence_current', false)
            ->assertJsonPath('data.steps.6.status', 'action_required');
    }

    public function test_source_create_response_starts_at_zero_and_api_upload_can_finalize(): void
    {
        $admin = $this->admin();
        $contents = 'source-bytes';
        $base = '/api/v1/admin/ml/acceptance/sources';
        $created = $this->actingAs($admin)->postJson($base, [
            'version' => 1,
            'source' => 'nse_cash_bhavcopy',
            'date' => '2026-09-29',
            'filename' => 'cm29SEP2026bhav.csv',
            'bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ])->assertCreated()->assertJsonPath('data.received', 0);
        $sourceId = $created->json('data.id');
        $this->sourceIds[] = $sourceId;

        $this->putJson($base.'/'.$sourceId.'/chunks', [
            'offset' => 0,
            'chunk' => base64_encode($contents),
        ])->assertOk()->assertJsonPath('data.received', strlen($contents));
        $this->postJson($base.'/'.$sourceId.'/finalize')
            ->assertOk()->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.received', strlen($contents));
    }

    public function test_private_source_seals_without_requiring_master_mapping_and_rejects_mutation(): void
    {
        $source = $this->source();
        $this->assertSame('sealed', $source->status);
        $this->assertStringStartsWith(storage_path('app/private/'), app(MlAcceptanceSourceService::class)->directory($source));
        $this->expectException(ValidationException::class);
        app(MlAcceptanceSourceService::class)->chunk($source, 0, 'different', 1);
    }

    public function test_date_mismatch_never_seals(): void
    {
        $this->assertSame('failed', $this->source("SYMBOL,SERIES,TIMESTAMP\nTEST,EQ,29-SEP-2026\n")->status);
    }

    public function test_unsafe_archive_is_rejected_without_extraction(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'acceptance-zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('../escape.csv', 'payload');
        $zip->close();
        try {
            app(MlAcceptanceSourceService::class)->safeContents($path, 'archive.zip');
            $this->fail('Unsafe ZIP accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        } finally {
            unlink($path);
        }
    }

    public function test_preview_apply_idempotency_and_cancel_preserve_pit_membership(): void
    {
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123', 'sector' => 'Current sector']);
        $source = $this->source();
        $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1);
        $service->step($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(0, MlUniverseMembership::query()->count());
        $service->action($run, 'apply', 1);
        $service->step($run->id);
        $service->step($run->id);
        $this->assertSame(1, MlUniverseMembership::query()->count());
        $this->assertNull(MlUniverseMembership::query()->first()->sector_snapshot);
        $second = $service->preview([$source->id], 1);
        $service->step($second->id);
        $service->action($second, 'apply', 1);
        $service->action($second, 'cancel', 1);
        $service->step($second->id);
        $this->assertSame('cancelled', $second->refresh()->status);
        $this->assertSame(1, MlUniverseMembership::query()->count());
    }

    public function test_failed_preflight_never_queues_training_or_enables_lifecycle(): void
    {
        $service = app(MlAcceptanceCampaignService::class);
        $campaign = $service->create('2026-09-30', $this->admin()->id);
        for ($i = 0; $i < 3; $i++) {
            $service->step($campaign->id);
        }
        $this->assertSame('blocked', $campaign->refresh()->status);
        $this->assertCount(3, $campaign->horizons);
        Bus::assertNotDispatched(MlRetrainJob::class);
        $this->assertDatabaseCount('stox_ml_model_versions', 0);
        $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        $this->expectException(ValidationException::class);
        $service->action($campaign, 'start', $campaign->actor_id);
    }

    public function test_lifecycle_refuses_missing_evidence(): void
    {
        config(['ml_lifecycle.enabled' => true]);
        $actions = app(MlLifecycleAutomationService::class)->tick();
        $this->assertSame('current_production_acceptance_required', $actions[0]['reason']);
        Bus::assertNothingDispatched();
        $this->expectException(ValidationException::class);
        app(MlLifecycleAutomationService::class)->updateSchedule('1m', true, 'monthly_first_sunday_02:00');
    }

    public function test_sync_queue_is_rejected_before_creating_work(): void
    {
        config(['queue.connections.ml-acceptance.driver' => 'sync']);
        $this->expectException(ValidationException::class);
        app(MlAcceptanceCampaignService::class)->create('2026-09-30', 1);
    }

    public function test_historical_ttm_and_growth_require_complete_comparable_periods(): void
    {
        $builder = app(MlTrainingDatasetBuilder::class);
        $method = new \ReflectionMethod($builder, 'historicalMetrics');
        $facts = [];
        foreach (['2025-03-31' => 100, '2025-06-30' => 200, '2025-09-30' => 300, '2025-12-31' => 400, '2026-03-31' => 150] as $date => $value) {
            foreach (['revenue' => $value, 'operating_profit' => $value / 10] as $key => $amount) {
                $facts[] = ['fact_key' => $key, 'period_end' => $date, 'availability_date' => $date, 'value' => $amount, 'revision_number' => 1];
            }
        }
        $result = $method->invoke($builder, $facts, '2026-04-01');
        $this->assertEquals(50, $result['revenue_growth']);
        $this->assertEquals(10, $result['operating_margin']);
        $short = array_values(array_filter($facts, fn ($f) => $f['period_end'] !== '2025-09-30'));
        $this->assertNull($method->invoke($builder, $short, '2026-04-01')['operating_margin']);
        $this->assertNull($method->invoke($builder, array_slice($facts, -4), '2026-04-01')['revenue_growth']);
        $facts[0]['value'] = -100;
        $this->assertEquals(250, $method->invoke($builder, $facts, '2026-04-01')['revenue_growth']);
        $facts[2]['value'] = null;
        $this->assertNull($method->invoke($builder, $facts, '2026-04-01')['operating_margin']);
    }

    public function test_successful_preflight_links_three_canonical_manual_runs_and_pins_inputs(): void
    {
        $this->mock(MlAcceptanceEvidenceService::class)->shouldReceive('preflight')->times(3)
            ->andReturn(['blocking_reasons' => [], 'dataset_sha256' => str_repeat('a', 64)]);
        $service = app(MlAcceptanceCampaignService::class);
        $campaign = $service->create('2026-09-30', $this->admin()->id);
        for ($i = 0; $i < 3; $i++) {
            $service->step($campaign->id);
        }
        $this->assertSame('ready', $campaign->refresh()->status);
        $service->action($campaign, 'start', $campaign->actor_id);
        $runs = MlTrainingRun::query()->get();
        $this->assertCount(3, $runs);
        Bus::assertDispatchedTimes(MlRetrainJob::class, 3);
        Bus::assertDispatched(MlRetrainJob::class, fn ($job) => $job->connection === 'ml-acceptance' && $job->queue === 'ml-acceptance');
        foreach ($runs as $run) {
            $this->assertSame('manual', $run->configuration['trigger']);
            $this->assertSame($campaign->id, $run->configuration['acceptance']['campaign_id']);
            $this->assertSame('2026-09-30', $run->configuration['acceptance']['cutoff_date']);
            $this->assertSame($run->id, $campaign->refresh()->horizons[$run->horizon]['run_id']);
        }
        $service->action($campaign, 'cancel', $campaign->actor_id);
        $this->assertSame('cancelled', $campaign->refresh()->status);
        $this->assertSame(3, MlTrainingRun::query()->where('status', 'cancelled')->count());
        $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        $this->assertDatabaseCount('stox_ml_model_versions', 0);
    }

    public function test_campaign_cancel_lock_timeout_returns_retryable_busy_response_without_mutation(): void
    {
        $admin = $this->admin();
        $campaign = app(MlAcceptanceCampaignService::class)->create('2026-09-30', $admin->id);
        $history = $campaign->history;
        $lock = \Mockery::mock(\Illuminate\Contracts\Cache\Lock::class);
        $lock->shouldReceive('block')->once()->with(5, \Mockery::type(\Closure::class))
            ->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException());
        Cache::shouldReceive('lock')->once()
            ->with('ml-campaign-'.$campaign->id, MlAcceptanceRuntime::LOCK_SECONDS)
            ->andReturn($lock);

        $this->actingAs($admin)->postJson('/api/v1/admin/ml/acceptance/campaigns/'.$campaign->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'campaign_busy')
            ->assertJsonPath('error.retryable', true)
            ->assertHeader('Retry-After', '5');

        $this->assertSame('preflight', $campaign->refresh()->status);
        $this->assertEqualsCanonicalizing($history, $campaign->history);
    }

    public function test_stale_and_changed_acceptance_identity_fail_closed(): void
    {
        $runtime = app(MlAcceptanceRuntime::class);
        $campaign = MlAcceptanceCampaign::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
            'status' => 'qualified', 'identity' => $runtime->identity(), 'active_models' => [], 'horizons' => [], 'history' => [],
            'qualified_at' => now()->subDays(31),
        ]);
        $service = app(MlAcceptanceCampaignService::class);
        $campaign->forceFill(['identity' => array_reverse($runtime->identity(), true)])->save();
        $this->assertTrue($service->identityMatches($campaign), 'MySQL JSON key ordering must not invalidate an identical build.');
        $this->assertFalse($service->readiness()['ready']);
        $campaign->forceFill(['qualified_at' => now(), 'identity' => array_replace($runtime->identity(), ['registry' => 'obsolete'])])->save();
        $this->assertFalse($service->readiness()['ready']);
    }

    public function test_coverage_uses_actual_feature_values_and_excludes_future_facts(): void
    {
        $stock = Stock::query()->create(['symbol' => 'COVER', 'exchange' => 'NSE', 'name' => 'Coverage']);
        app(FundamentalDataService::class)->storeFacts($stock, [[
            'statement_type' => 'income_statement', 'cadence' => 'quarterly', 'fact_key' => 'net_income',
            'period_end' => '2026-03-31', 'value' => 100, 'availability_date' => '2026-04-15',
        ]]);
        $directory = storage_path('framework/testing/acceptance-coverage-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);
        try {
            $paths = [];
            foreach (['train', 'validation', 'test'] as $partition) {
                $paths[$partition] = $directory.'/'.$partition.'.jsonl';
                File::put($paths[$partition], json_encode(['stock_id' => $stock->id, 'reference_date' => '2026-03-31',
                    'features' => ['roe' => null, 'market_breadth_nifty' => 50, 'sector' => '__unknown']])."\n");
            }
            $result = app(MlAcceptanceEvidenceService::class)->coverage(['paths' => $paths], '1m', $directory);
            $this->assertSame(0, $result['feature_coverage']['train']['roe']['present']);
            $this->assertSame(1, $result['feature_coverage']['train']['roe']['missing']);
            $this->assertSame(1, $result['feature_date_coverage']['2026-03-31']['test']['market_breadth_nifty']['present']);
            $this->assertSame('no_training_values', $result['exclusions']['sector']);
            $this->assertNotEmpty($result['pit_evidence_sha256']);
            $this->assertSame('jsonl-gzip-v1', $result['pit_evidence_format']);
            $journal = $directory.'/pit-evidence.jsonl.gz';
            $content = gzdecode(File::get($journal));
            $this->assertSame(hash_file('sha256', $journal), $result['pit_evidence_sha256']);
            $this->assertSame(hash('sha256', $content), $result['pit_evidence_content_sha256']);
            $rows = array_map(fn ($line) => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
                explode("\n", trim($content)));
            $canonicalRows = array_values(array_filter($rows, fn ($row) => isset($row['reference_date'])));
            $this->assertCount(3, $canonicalRows);
            foreach ($canonicalRows as $row) {
                $this->assertSame(['value_available' => false, 'available_pit_fact_ids' => []], $row['fundamentals']['roe']);
            }
            $this->assertSame([], $result['feature_date_coverage']['2026-03-31']['train']['roe']['fact_periods']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_preflight_reports_the_journal_quota_as_a_specific_safe_blocker(): void
    {
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $source = $this->source();
        $backfills = app(MlAcceptanceBackfillService::class);
        $run = $backfills->preview([$source->id], 1);
        $backfills->step($run->id);
        $backfills->action($run, 'apply', 1);
        $backfills->step($run->id);
        $this->mock(MlTrainingDatasetBuilder::class, function ($mock) {
            $mock->shouldReceive('requiredReferenceDates')->once()->andReturn(['2026-09-30' => true]);
            $mock->shouldReceive('buildStreamed')->once()->andReturn(['paths' => []]);
        });
        $evidence = \Mockery::mock(MlAcceptanceEvidenceService::class)->makePartial();
        $evidence->shouldReceive('coverage')->once()->andThrow(new \App\Exceptions\MlAcceptanceEvidenceQuotaExceeded);
        $campaign = (string) Str::uuid();
        try {
            $result = $evidence->preflight($campaign, '1m', Carbon::parse('2026-09-30'));
            $this->assertSame(['pit_evidence_quota_exceeded'], $result['blocking_reasons']);
            $this->assertNull($result['coverage']);
            $this->assertNull($result['dataset_sha256']);
            $this->assertDirectoryDoesNotExist(storage_path('app/private/ml-acceptance/campaigns/'.$campaign.'/1m/dataset'));
            Bus::assertNotDispatched(MlRetrainJob::class);
        } finally {
            File::deleteDirectory(storage_path('app/private/ml-acceptance/campaigns/'.$campaign));
        }
    }

    public function test_upload_duplicate_chunk_hash_mismatch_and_quota(): void
    {
        $service = app(MlAcceptanceSourceService::class);
        $manifest = ['version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => '2026-09-30',
            'filename' => 'cm30SEP2026bhav.csv', 'bytes' => 4, 'sha256' => str_repeat('0', 64)];
        $source = $service->create($manifest, 1);
        $this->sourceIds[] = $source->id;
        $service->chunk($source, 0, 'test', 1);
        $service->chunk($source, 0, 'test', 1);
        $this->assertSame(4, $source->refresh()->received);
        $service->finalize($source, 1);
        $service->validateQueued($source->id);
        $this->assertSame('failed', $source->refresh()->status);
        for ($i = 0; $i < 4; $i++) {
            $source = $service->create($manifest, 1);
            $this->sourceIds[] = $source->id;
        }
        $this->expectException(ValidationException::class);
        $service->create($manifest, 1);
    }

    public function test_backfill_below_mapping_floor_retries_durably_and_resumes_after_master_mapping(): void
    {
        $source = $this->source();
        $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1);
        for ($i = 0; $i < 3; $i++) {
            $service->step($run->id);
        }
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame(3, $run->retry_counts['2026-09-30']);
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $service->action($run, 'resume', 1);
        $service->step($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame([], $run->failed_dates);
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
    }

    public function test_acceptance_recovery_is_scoped_and_waits_for_job_timeout(): void
    {
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m', 'status' => 'running', 'cutoff_date' => '2026-09-30', 'started_at' => now()->subHours(5),
            'configuration' => ['trigger' => 'manual', 'acceptance' => ['campaign_id' => 'fixture', 'cutoff_date' => '2026-09-30']],
        ]);
        $unrelated = MlTrainingRun::query()->create([
            'horizon' => '3m', 'status' => 'running', 'cutoff_date' => '2026-09-30', 'started_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
        ]);
        $run->forceFill(['updated_at' => now()->subHour()])->save();
        $recovery = app(MlTrainingRunRecoveryService::class);
        $this->assertSame([], $recovery->recover(null, [$run->id]));
        $run->forceFill(['updated_at' => now()->subHours(5)])->save();
        $actions = $recovery->recover(null, [$run->id]);
        $this->assertSame('requeued_stale_run', $actions[0]['action']);
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame('running', $unrelated->refresh()->status);
        Bus::assertDispatched(MlRetrainJob::class, fn ($job) => $job->trainingRunId === $run->id && $job->timeout === 14400 && $job->connection === 'ml-acceptance' && $job->queue === 'ml-acceptance');
    }

    public function test_snapshot_conflict_does_not_overwrite_existing_boundary(): void
    {
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $source = $this->source();
        $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1);
        $service->step($run->id);
        $service->action($run, 'apply', 1);
        $service->step($run->id);
        $before = MlUniverseSnapshotBoundary::query()->first()->toArray();
        $changed = $this->source("SYMBOL,SERIES,ISIN,TIMESTAMP,SECTOR\nTEST,EQ,INE123,30-SEP-2026,Historical\n");
        $conflict = $service->preview([$changed->id], 1);
        for ($i = 0; $i < 3; $i++) {
            $service->step($conflict->id);
        }
        $this->assertSame('failed', $conflict->refresh()->status);
        $this->assertSame($before, MlUniverseSnapshotBoundary::query()->first()->toArray());
    }

    public function test_archive_multiple_entries_links_expansion_and_invalid_content_dates_are_rejected(): void
    {
        foreach (['multiple', 'link', 'expansion'] as $kind) {
            $path = tempnam(sys_get_temp_dir(), 'acceptance-zip');
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('source.csv', $kind === 'expansion' ? str_repeat('a', 100000) : 'data');
            if ($kind === 'multiple') {
                $zip->addFromString('second.csv', 'data');
            }
            if ($kind === 'link') {
                $zip->setExternalAttributesName('source.csv', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
            }
            $zip->close();
            try {
                app(MlAcceptanceSourceService::class)->safeContents($path, 'source.zip');
                $this->fail($kind.' accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            } finally {
                unlink($path);
            }
        }
        $this->assertSame('failed', $this->source("SYMBOL,SERIES,TIMESTAMP\nTEST,EQ,invalid\n")->status);
    }

    public function test_acceptance_migration_can_rollback_and_reapply_in_isolated_database(): void
    {
        $migration = require database_path('migrations/2026_09_30_000001_create_ml_acceptance_operations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('stox_ml_acceptance_campaigns'));
        $this->assertFalse(Schema::hasColumn('stox_ml_universe_snapshot_backfill_runs', 'acceptance'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('stox_ml_acceptance_sources'));
        $this->assertTrue(Schema::hasColumn('stox_ml_universe_snapshot_backfill_runs', 'acceptance'));
    }

    public function test_complete_local_campaign_cannot_qualify_and_missing_adapter_receipt_fails(): void
    {
        $runtime = app(MlAcceptanceRuntime::class);
        $campaign = MlAcceptanceCampaign::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
            'status' => 'training', 'identity' => $runtime->identity(), 'active_models' => [], 'horizons' => [], 'history' => [],
        ]);
        $artifact = tempnam(sys_get_temp_dir(), 'acceptance-artifact');
        file_put_contents($artifact, 'fixture');
        try {
            $horizons = [];
            foreach (['1m', '3m', '6m'] as $horizon) {
                $run = MlTrainingRun::query()->create([
                    'horizon' => $horizon, 'status' => 'completed_rejected', 'cutoff_date' => '2026-09-30',
                    'configuration' => ['acceptance' => ['campaign_id' => $campaign->id],
                        'chronological_validation_grid' => ['windows' => [1]], 'feature_selection' => ['partition_coverage' => ['train' => [1]]]],
                    'baselines' => ['deterministic' => ['comparable' => true]],
                ]);
                MlModelVersion::query()->create([
                    'training_run_id' => $run->id, 'horizon' => $horizon, 'version' => 1, 'status' => 'rejected',
                    'feature_set' => [], 'preprocessing' => [], 'label_definition' => [], 'benchmark_mapping' => [],
                    'artifact_path' => $artifact, 'artifact_sha256' => hash_file('sha256', $artifact),
                    'audit_metadata' => ['adapter_metadata' => ['calibration' => ['method' => 'fixture'], 'challenger' => ['status' => 'fixture'],
                        'execution' => ['adapter' => MlPythonAdapter::class, 'environment' => 'testing', 'adapter_sha256' => $runtime->identity()['adapter_sha256']]]],
                ]);
                $horizons[$horizon] = ['run_id' => $run->id, 'worker_evidence' => [
                    'connection' => MlAcceptanceRuntime::CONNECTION, 'queue' => MlAcceptanceRuntime::QUEUE,
                    'observed_at' => now()->toIso8601String(), 'identity' => $runtime->identity(),
                ]];
            }
            $campaign->forceFill(['horizons' => $horizons])->save();
            app(MlAcceptanceCampaignService::class)->step($campaign->id);
            $this->assertSame('local_complete', $campaign->refresh()->status);
            $this->assertNull($campaign->qualified_at);
            $this->assertFalse(app(MlAcceptanceCampaignService::class)->readiness()['ready']);
            $this->assertSame([], $runtime->activeModels());
            $model = MlModelVersion::query()->first();
            $model->forceFill(['audit_metadata' => []])->save();
            $campaign->forceFill(['status' => 'training'])->save();
            app(MlAcceptanceCampaignService::class)->step($campaign->id);
            $this->assertSame('failed', $campaign->refresh()->status);
        } finally {
            unlink($artifact);
        }
    }

    public function test_changed_dataset_blocks_before_python_execution(): void
    {
        $runtime = app(MlAcceptanceRuntime::class);
        $campaign = MlAcceptanceCampaign::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
            'status' => 'training', 'identity' => $runtime->identity(), 'active_models' => [], 'horizons' => [], 'history' => [],
        ]);
        $run = MlTrainingRun::query()->create([
            'horizon' => '1m', 'status' => 'running', 'cutoff_date' => '2026-09-30',
            'configuration' => ['acceptance' => ['campaign_id' => $campaign->id, 'dataset_sha256' => str_repeat('a', 64)]],
        ]);
        $campaign->forceFill(['horizons' => ['1m' => ['run_id' => $run->id, 'snapshots' => []]]])->save();
        $path = tempnam(sys_get_temp_dir(), 'acceptance-dataset');
        file_put_contents($path, 'changed');
        try {
            $this->expectException(ValidationException::class);
            app(MlAcceptanceCampaignService::class)->assertTraining($run, ['paths' => ['train' => $path], 'partitions' => [], 'feature_definitions' => []]);
        } finally {
            unlink($path);
        }
    }

    public function test_acceptance_artifacts_are_excluded_from_retention(): void
    {
        config(['ml_lifecycle.retention.max_retained_per_horizon' => 1]);
        $run = MlTrainingRun::query()->create(['horizon' => '1m', 'status' => 'completed_eligible',
            'cutoff_date' => '2026-09-30', 'configuration' => ['acceptance' => ['campaign_id' => 'fixture']]]);
        foreach ([1, 2] as $version) {
            MlModelVersion::query()->create([
                'training_run_id' => $version === 1 ? $run->id : null, 'horizon' => '1m', 'version' => $version, 'status' => 'retained',
                'feature_set' => [], 'preprocessing' => [], 'label_definition' => [], 'benchmark_mapping' => [],
            ]);
        }
        $this->assertSame([], app(MlArtifactRetentionService::class)->pruneHorizon('1m', true));
        $this->assertSame(2, MlModelVersion::query()->where('status', 'retained')->count());
    }
}
