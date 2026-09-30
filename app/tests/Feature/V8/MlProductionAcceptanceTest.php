<?php
namespace Tests\Feature\V8;

use App\Jobs\MlRetrainJob;
use App\Models\Stock;
use App\Models\User;
use App\Models\V8\MlAcceptanceSource;
use App\Models\V8\MlUniverseMembership;
use App\Services\ML\MlAcceptanceBackfillService;
use App\Services\ML\MlAcceptanceCampaignService;
use App\Services\ML\MlAcceptanceSourceService;
use App\Services\ML\MlLifecycleAutomationService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MlProductionAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    private array $sourceIds = [];
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-09-30 12:00:00'));
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 14460]);
        Bus::fake();
    }
    protected function tearDown(): void
    {
        foreach ($this->sourceIds as $id) File::deleteDirectory(storage_path('app/private/ml-acceptance/sources/'.$id));
        parent::tearDown();
    }
    private function admin(): User
    {
        $admin = User::factory()->admin()->create(); $this->defaultPortfolioFor($admin); return $admin;
    }
    private function source(string $contents = "SYMBOL,SERIES,ISIN,TIMESTAMP\nTEST,EQ,INE123,30-SEP-2026\n"): MlAcceptanceSource
    {
        $service = app(MlAcceptanceSourceService::class);
        $source = $service->create(['version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => '2026-09-30',
            'filename' => 'cm30SEP2026bhav.csv', 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)], 1);
        $this->sourceIds[] = $source->id;
        $service->chunk($source, 0, $contents, 1);
        $service->finalize($source, 1); $service->validateQueued($source->id);
        return $source->refresh();
    }
    public function test_report_is_admin_only_read_only_and_explicit_about_unknown_evidence(): void
    {
        $this->getJson('/api/v1/admin/ml/acceptance')->assertUnauthorized();
        $user = User::factory()->create(); $this->defaultPortfolioFor($user);
        $this->actingAs($user)->getJson('/api/v1/admin/ml/acceptance')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/v1/admin/ml/acceptance')->assertOk()
            ->assertJsonPath('data.readiness.ready', false)->assertJsonPath('data.runtime.python_evidence.1m', null)
            ->assertJsonPath('data.horizons.1m.reference_dates', null);
        $this->assertDatabaseCount('stox_ml_acceptance_campaigns', 0);
        $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        Bus::assertNothingDispatched();
    }
    public function test_private_source_seals_without_requiring_master_mapping_and_rejects_mutation(): void
    {
        $source = $this->source(); $this->assertSame('sealed', $source->status);
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
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE); $zip->addFromString('../escape.csv', 'payload'); $zip->close();
        try {
            app(MlAcceptanceSourceService::class)->safeContents($path, 'archive.zip'); $this->fail('Unsafe ZIP accepted');
        } catch (ValidationException) { $this->assertTrue(true); }
        finally { unlink($path); }
    }
    public function test_preview_apply_idempotency_and_cancel_preserve_pit_membership(): void
    {
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123', 'sector' => 'Current sector']);
        $source = $this->source(); $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1); $service->step($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame(0, MlUniverseMembership::query()->count());
        $service->action($run, 'apply', 1); $service->step($run->id); $service->step($run->id);
        $this->assertSame(1, MlUniverseMembership::query()->count());
        $this->assertNull(MlUniverseMembership::query()->first()->sector_snapshot);
        $second = $service->preview([$source->id], 1); $service->step($second->id);
        $service->action($second, 'apply', 1); $service->action($second, 'cancel', 1); $service->step($second->id);
        $this->assertSame('cancelled', $second->refresh()->status);
        $this->assertSame(1, MlUniverseMembership::query()->count());
    }
    public function test_failed_preflight_never_queues_training_or_enables_lifecycle(): void
    {
        $service = app(MlAcceptanceCampaignService::class); $campaign = $service->create('2026-09-30', $this->admin()->id);
        for ($i = 0; $i < 3; $i++) $service->step($campaign->id);
        $this->assertSame('blocked', $campaign->refresh()->status); $this->assertCount(3, $campaign->horizons);
        Bus::assertNotDispatched(MlRetrainJob::class);
        $this->assertDatabaseCount('stox_ml_model_versions', 0); $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        $this->expectException(ValidationException::class); $service->action($campaign, 'start', $campaign->actor_id);
    }
    public function test_lifecycle_refuses_missing_evidence(): void
    {
        config(['ml_lifecycle.enabled' => true]); $actions = app(MlLifecycleAutomationService::class)->tick();
        $this->assertSame('current_production_acceptance_required', $actions[0]['reason']); Bus::assertNothingDispatched();
        $this->expectException(ValidationException::class);
        app(MlLifecycleAutomationService::class)->updateSchedule('1m', true, 'monthly_first_sunday_02:00');
    }
    public function test_sync_queue_is_rejected_before_creating_work(): void
    {
        config(['queue.default' => 'sync']); $this->expectException(ValidationException::class);
        app(MlAcceptanceCampaignService::class)->create('2026-09-30', 1);
    }
    public function test_historical_ttm_and_growth_require_complete_comparable_periods(): void
    {
        $builder = app(MlTrainingDatasetBuilder::class); $method = new \ReflectionMethod($builder, 'historicalMetrics'); $facts = [];
        foreach (['2025-03-31' => 100, '2025-06-30' => 200, '2025-09-30' => 300, '2025-12-31' => 400, '2026-03-31' => 150] as $date => $value) {
            foreach (['revenue' => $value, 'operating_profit' => $value / 10] as $key => $amount)
                $facts[] = ['fact_key' => $key, 'period_end' => $date, 'availability_date' => $date, 'value' => $amount, 'revision_number' => 1];
        }
        $result = $method->invoke($builder, $facts, '2026-04-01');
        $this->assertEquals(50, $result['revenue_growth']); $this->assertEquals(10, $result['operating_margin']);
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
        $this->mock(\App\Services\ML\MlAcceptanceEvidenceService::class)->shouldReceive('preflight')->times(3)
            ->andReturn(['blocking_reasons' => [], 'dataset_sha256' => str_repeat('a', 64)]);
        $service = app(MlAcceptanceCampaignService::class);
        $campaign = $service->create('2026-09-30', $this->admin()->id);
        for ($i = 0; $i < 3; $i++) $service->step($campaign->id);
        $this->assertSame('ready', $campaign->refresh()->status);
        $service->action($campaign, 'start', $campaign->actor_id);
        $runs = \App\Models\V7\MlTrainingRun::query()->get();
        $this->assertCount(3, $runs);
        foreach ($runs as $run) {
            $this->assertSame('manual', $run->configuration['trigger']);
            $this->assertSame($campaign->id, $run->configuration['acceptance']['campaign_id']);
            $this->assertSame('2026-09-30', $run->configuration['acceptance']['cutoff_date']);
            $this->assertSame($run->id, $campaign->refresh()->horizons[$run->horizon]['run_id']);
        }
        $service->action($campaign, 'cancel', $campaign->actor_id);
        $this->assertSame('cancelled', $campaign->refresh()->status);
        $this->assertSame(3, \App\Models\V7\MlTrainingRun::query()->where('status', 'cancelled')->count());
        $this->assertDatabaseCount('stox_ml_lifecycle_schedules', 0);
        $this->assertDatabaseCount('stox_ml_model_versions', 0);
    }

    public function test_stale_and_changed_acceptance_identity_fail_closed(): void
    {
        $runtime = app(\App\Services\ML\MlAcceptanceRuntime::class);
        $campaign = \App\Models\V8\MlAcceptanceCampaign::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
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
        app(\App\Services\Fundamentals\FundamentalDataService::class)->storeFacts($stock, [[
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
            $result = app(\App\Services\ML\MlAcceptanceEvidenceService::class)->coverage(['paths' => $paths], '1m', $directory);
            $this->assertSame(0, $result['feature_coverage']['train']['roe']['present']);
            $this->assertSame(1, $result['feature_coverage']['train']['roe']['missing']);
            $this->assertSame(1, $result['feature_date_coverage']['2026-03-31']['test']['market_breadth_nifty']['present']);
            $this->assertSame('no_training_values', $result['exclusions']['sector']);
            $this->assertNotEmpty($result['pit_evidence_sha256']);
            $this->assertSame([], $result['feature_date_coverage']['2026-03-31']['train']['roe']['fact_periods']);
        } finally { File::deleteDirectory($directory); }
    }

    public function test_upload_duplicate_chunk_hash_mismatch_and_quota(): void
    {
        $service = app(MlAcceptanceSourceService::class);
        $manifest = ['version' => 1, 'source' => 'nse_cash_bhavcopy', 'date' => '2026-09-30',
            'filename' => 'cm30SEP2026bhav.csv', 'bytes' => 4, 'sha256' => str_repeat('0', 64)];
        $source = $service->create($manifest, 1); $this->sourceIds[] = $source->id;
        $service->chunk($source, 0, 'test', 1); $service->chunk($source, 0, 'test', 1);
        $this->assertSame(4, $source->refresh()->received);
        $service->finalize($source, 1); $service->validateQueued($source->id);
        $this->assertSame('failed', $source->refresh()->status);
        for ($i = 0; $i < 4; $i++) { $source = $service->create($manifest, 1); $this->sourceIds[] = $source->id; }
        $this->expectException(ValidationException::class); $service->create($manifest, 1);
    }

    public function test_backfill_below_mapping_floor_retries_durably_and_resumes_after_master_mapping(): void
    {
        $source = $this->source(); $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1);
        for ($i = 0; $i < 3; $i++) $service->step($run->id);
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertSame(3, $run->retry_counts['2026-09-30']);
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $service->action($run, 'resume', 1); $service->step($run->id);
        $this->assertSame('completed', $run->refresh()->status);
        $this->assertSame([], $run->failed_dates);
        $this->assertDatabaseCount('stox_ml_universe_memberships', 0);
    }

    public function test_acceptance_recovery_is_scoped_and_waits_for_job_timeout(): void
    {
        $run = \App\Models\V7\MlTrainingRun::query()->create([
            'horizon' => '1m', 'status' => 'running', 'cutoff_date' => '2026-09-30', 'started_at' => now()->subHours(5),
            'configuration' => ['trigger' => 'manual', 'acceptance' => ['campaign_id' => 'fixture', 'cutoff_date' => '2026-09-30']],
        ]);
        $unrelated = \App\Models\V7\MlTrainingRun::query()->create([
            'horizon' => '3m', 'status' => 'running', 'cutoff_date' => '2026-09-30', 'started_at' => now()->subHours(5), 'updated_at' => now()->subHours(5),
        ]);
        $run->forceFill(['updated_at' => now()->subHour()])->save();
        $recovery = app(\App\Services\ML\MlTrainingRunRecoveryService::class);
        $this->assertSame([], $recovery->recover(null, [$run->id]));
        $run->forceFill(['updated_at' => now()->subHours(5)])->save();
        $actions = $recovery->recover(null, [$run->id]);
        $this->assertSame('requeued_stale_run', $actions[0]['action']);
        $this->assertSame('queued', $run->refresh()->status);
        $this->assertSame('running', $unrelated->refresh()->status);
        Bus::assertDispatched(MlRetrainJob::class, fn ($job) => $job->trainingRunId === $run->id && $job->timeout === 14400);
    }

    public function test_snapshot_conflict_does_not_overwrite_existing_boundary(): void
    {
        Stock::query()->create(['symbol' => 'TEST', 'name' => 'Test', 'exchange' => 'NSE', 'isin' => 'INE123']);
        $source = $this->source(); $service = app(MlAcceptanceBackfillService::class);
        $run = $service->preview([$source->id], 1); $service->step($run->id);
        $service->action($run, 'apply', 1); $service->step($run->id);
        $before = \App\Models\V8\MlUniverseSnapshotBoundary::query()->first()->toArray();
        $changed = $this->source("SYMBOL,SERIES,ISIN,TIMESTAMP,SECTOR\nTEST,EQ,INE123,30-SEP-2026,Historical\n");
        $conflict = $service->preview([$changed->id], 1);
        for ($i = 0; $i < 3; $i++) $service->step($conflict->id);
        $this->assertSame('failed', $conflict->refresh()->status);
        $this->assertSame($before, \App\Models\V8\MlUniverseSnapshotBoundary::query()->first()->toArray());
    }

    public function test_archive_multiple_entries_links_expansion_and_invalid_content_dates_are_rejected(): void
    {
        foreach (['multiple', 'link', 'expansion'] as $kind) {
            $path = tempnam(sys_get_temp_dir(), 'acceptance-zip');
            $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('source.csv', $kind === 'expansion' ? str_repeat('a', 100000) : 'data');
            if ($kind === 'multiple') $zip->addFromString('second.csv', 'data');
            if ($kind === 'link') $zip->setExternalAttributesName('source.csv', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
            $zip->close();
            try { app(MlAcceptanceSourceService::class)->safeContents($path, 'source.zip'); $this->fail($kind.' accepted'); }
            catch (ValidationException) { $this->assertTrue(true); }
            finally { unlink($path); }
        }
        $this->assertSame('failed', $this->source("SYMBOL,SERIES,TIMESTAMP\nTEST,EQ,invalid\n")->status);
    }

    public function test_acceptance_migration_can_rollback_and_reapply_in_isolated_database(): void
    {
        $migration = require database_path('migrations/2026_09_30_000001_create_ml_acceptance_operations.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('stox_ml_acceptance_campaigns'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('stox_ml_universe_snapshot_backfill_runs', 'acceptance'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('stox_ml_acceptance_sources'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('stox_ml_universe_snapshot_backfill_runs', 'acceptance'));
    }

    public function test_complete_local_campaign_cannot_qualify_and_missing_adapter_receipt_fails(): void
    {
        $runtime = app(\App\Services\ML\MlAcceptanceRuntime::class);
        $campaign = \App\Models\V8\MlAcceptanceCampaign::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
            'status' => 'training', 'identity' => $runtime->identity(), 'active_models' => [], 'horizons' => [], 'history' => [],
        ]);
        $artifact = tempnam(sys_get_temp_dir(), 'acceptance-artifact'); file_put_contents($artifact, 'fixture');
        try {
            $horizons = [];
            foreach (['1m', '3m', '6m'] as $horizon) {
                $run = \App\Models\V7\MlTrainingRun::query()->create([
                    'horizon' => $horizon, 'status' => 'completed_rejected', 'cutoff_date' => '2026-09-30',
                    'configuration' => ['acceptance' => ['campaign_id' => $campaign->id],
                        'chronological_validation_grid' => ['windows' => [1]], 'feature_selection' => ['partition_coverage' => ['train' => [1]]]],
                    'baselines' => ['deterministic' => ['comparable' => true]],
                ]);
                \App\Models\V7\MlModelVersion::query()->create([
                    'training_run_id' => $run->id, 'horizon' => $horizon, 'version' => 1, 'status' => 'rejected',
                    'feature_set' => [], 'preprocessing' => [], 'label_definition' => [], 'benchmark_mapping' => [],
                    'artifact_path' => $artifact, 'artifact_sha256' => hash_file('sha256', $artifact),
                    'audit_metadata' => ['adapter_metadata' => ['calibration' => ['method' => 'fixture'], 'challenger' => ['status' => 'fixture'],
                        'execution' => ['adapter' => \App\Services\ML\MlPythonAdapter::class, 'environment' => 'testing', 'adapter_sha256' => $runtime->identity()['adapter_sha256']]]],
                ]);
                $horizons[$horizon] = ['run_id' => $run->id];
            }
            $campaign->forceFill(['horizons' => $horizons])->save();
            app(MlAcceptanceCampaignService::class)->step($campaign->id);
            $this->assertSame('local_complete', $campaign->refresh()->status);
            $this->assertNull($campaign->qualified_at);
            $this->assertFalse(app(MlAcceptanceCampaignService::class)->readiness()['ready']);
            $this->assertSame([], $runtime->activeModels());
            $model = \App\Models\V7\MlModelVersion::query()->first();
            $model->forceFill(['audit_metadata' => []])->save();
            $campaign->forceFill(['status' => 'training'])->save();
            app(MlAcceptanceCampaignService::class)->step($campaign->id);
            $this->assertSame('failed', $campaign->refresh()->status);
        } finally { unlink($artifact); }
    }

    public function test_changed_dataset_blocks_before_python_execution(): void
    {
        $runtime = app(\App\Services\ML\MlAcceptanceRuntime::class);
        $campaign = \App\Models\V8\MlAcceptanceCampaign::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'actor_id' => 1, 'cutoff_date' => '2026-09-30',
            'status' => 'training', 'identity' => $runtime->identity(), 'active_models' => [], 'horizons' => [], 'history' => [],
        ]);
        $run = \App\Models\V7\MlTrainingRun::query()->create([
            'horizon' => '1m', 'status' => 'running', 'cutoff_date' => '2026-09-30',
            'configuration' => ['acceptance' => ['campaign_id' => $campaign->id, 'dataset_sha256' => str_repeat('a', 64)]],
        ]);
        $campaign->forceFill(['horizons' => ['1m' => ['run_id' => $run->id, 'snapshots' => []]]])->save();
        $path = tempnam(sys_get_temp_dir(), 'acceptance-dataset'); file_put_contents($path, 'changed');
        try {
            $this->expectException(ValidationException::class);
            app(MlAcceptanceCampaignService::class)->assertTraining($run, ['paths' => ['train' => $path], 'partitions' => [], 'feature_definitions' => []]);
        } finally { unlink($path); }
    }

    public function test_acceptance_artifacts_are_excluded_from_retention(): void
    {
        config(['ml_lifecycle.retention.max_retained_per_horizon' => 1]);
        $run = \App\Models\V7\MlTrainingRun::query()->create(['horizon' => '1m', 'status' => 'completed_eligible',
            'cutoff_date' => '2026-09-30', 'configuration' => ['acceptance' => ['campaign_id' => 'fixture']]]);
        foreach ([1, 2] as $version) \App\Models\V7\MlModelVersion::query()->create([
            'training_run_id' => $version === 1 ? $run->id : null, 'horizon' => '1m', 'version' => $version, 'status' => 'retained',
            'feature_set' => [], 'preprocessing' => [], 'label_definition' => [], 'benchmark_mapping' => [],
        ]);
        $this->assertSame([], app(\App\Services\ML\MlArtifactRetentionService::class)->pruneHorizon('1m', true));
        $this->assertSame(2, \App\Models\V7\MlModelVersion::query()->where('status', 'retained')->count());
    }

}
