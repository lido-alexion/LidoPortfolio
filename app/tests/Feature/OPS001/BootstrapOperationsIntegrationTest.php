<?php

namespace Tests\Feature\OPS001;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\BootstrapCoverageGap;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapOperationsService;
use App\Services\Fundamentals\BootstrapRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BootstrapOperationsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/api/v1/admin/fundamentals/bootstrap-operations';

    public function test_gap_launch_is_admin_only_atomic_and_replay_preserves_original_context_after_resolution(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $investor = User::factory()->create(['is_admin' => false]);
        $stock = $this->stock('INTGAP');
        $gap = $this->gap($stock, 'revenue', 'quarterly');
        $body = ['gap_ids' => [$gap->id]];
        $headers = ['Idempotency-Key' => 'integrated-gap-launch-001'];
        $before = FundamentalBootstrapRun::query()->count();
        $this->actingAs($investor)->postJson(self::ROOT.'/runs/from-gaps', $body, $headers)->assertForbidden();
        $this->assertSame($before, FundamentalBootstrapRun::query()->count());

        $created = $this->actingAs($admin)->postJson(self::ROOT.'/runs/from-gaps', $body, $headers)->assertCreated();
        $runId = $created->json('data.id');
        $run = FundamentalBootstrapRun::query()->findOrFail($runId);
        $snapshot = $run->request_snapshot;
        $this->assertSame('gap_driven', $run->trigger);
        $this->assertSame([$stock->id], $snapshot['stock_ids']);
        $this->assertSame([$gap->id], $snapshot['gap_receipt']['gap_ids']);
        $this->assertSame('whole_stock', $snapshot['gap_receipt']['execution_scope']);
        $this->assertSame('revenue', $snapshot['gap_receipt']['metric']);
        $this->assertSame(1, $run->jobs()->count());
        $gap->forceFill(['state' => 'resolved'])->save();

        $this->postJson(self::ROOT.'/runs/from-gaps', $body, $headers)->assertOk()->assertJsonPath('data.id', $runId);
        $this->assertSame($snapshot, $run->fresh()->request_snapshot);
        $this->assertSame($before + 1, FundamentalBootstrapRun::query()->count());
        $other = $this->gap($stock, 'net_profit', 'quarterly');
        $beforeConflict = FundamentalBootstrapRun::query()->count();
        $this->postJson(self::ROOT.'/runs/from-gaps', ['gap_ids' => [$other->id]], $headers)->assertUnprocessable();
        $this->assertSame($beforeConflict, FundamentalBootstrapRun::query()->count());
    }

    public function test_incompatible_gap_selection_rolls_back_without_a_run_or_job(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = $this->stock('INTBAD');
        $a = $this->gap($stock, 'revenue', 'quarterly');
        $b = $this->gap($stock, 'net_profit', 'annual');
        $before = [FundamentalBootstrapRun::query()->count(), FundamentalBootstrapJob::query()->count()];
        $this->actingAs($admin)->postJson(self::ROOT.'/runs/from-gaps', ['gap_ids' => [$a->id, $b->id]],
            ['Idempotency-Key' => 'integrated-gap-invalid-001'])->assertUnprocessable();
        $this->assertSame($before, [FundamentalBootstrapRun::query()->count(), FundamentalBootstrapJob::query()->count()]);
    }

    public function test_scheduled_receipt_is_atomic_idempotent_and_survives_json_key_normalization(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = $this->stock('INTSCHED');
        $ops = app(BootstrapOperationsService::class);
        $config = ['scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => false, 'reason' => null];
        $receipt = $this->receipt($config, $admin);
        $result = $ops->launchWithScheduleReceipt($config, $admin->id, $admin->name, 'integrated-schedule-key-001', $receipt);
        $this->assertTrue($result->created);
        $this->assertSame('scheduled', $result->run->trigger);
        $this->assertTrue(BootstrapRequestContext::same($receipt, $result->run->request_snapshot['schedule_receipt']));
        $reordered = array_reverse($receipt, true);
        $reordered['configuration_snapshot'] = array_reverse($config, true);
        $replay = $ops->launchWithScheduleReceipt($config, $admin->id, $admin->name, 'integrated-schedule-key-001', $reordered);
        $this->assertFalse($replay->created);
        $this->assertSame($result->run->id, $replay->run->id);

        $changed = $receipt;
        $changed['schedule_generation']++;
        try {
            $ops->launchWithScheduleReceipt($config, $admin->id, $admin->name, 'integrated-schedule-key-001', $changed);
            $this->fail('A different schedule generation must not replay another receipt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('Idempotency-Key', $exception->errors());
        }
        $this->assertSame(1, FundamentalBootstrapRun::query()->count());
        $this->assertSame(1, FundamentalBootstrapJob::query()->count());
        $this->assertSame('queued', $result->run->fresh()->status);
    }

    public function test_run_now_keeps_schedule_identity_and_rejects_incomplete_or_forged_context(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = $this->stock('INTNOW');
        $ops = app(BootstrapOperationsService::class);
        $config = ['scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => false, 'reason' => null];
        $receipt = [...$this->receipt($config, $admin), 'trigger' => 'manual', 'scheduled_at' => null, 'run_now' => true];
        $result = $ops->launchWithScheduleReceipt($config, $admin->id, $admin->name, 'integrated-run-now-key-001', $receipt);
        $this->assertSame('manual', $result->run->trigger);
        $this->assertTrue(BootstrapRequestContext::same($receipt, $result->run->request_snapshot['schedule_receipt']));
        $before = FundamentalBootstrapRun::query()->count();
        foreach ([
            [...$receipt, 'actor_admin_id' => $admin->id + 1],
            [...$receipt, 'configuration_sha256' => str_repeat('0', 64)],
            [...$receipt, 'scheduled_at' => '2026-10-22T09:00:00Z'],
            [...$receipt, 'schedule_version' => 0],
        ] as $invalid) {
            try {
                $ops->launchWithScheduleReceipt($config, $admin->id, $admin->name, 'integrated-run-now-invalid-001', $invalid);
                $this->fail('Invalid schedule context must be rejected before creation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('schedule_receipt', $exception->errors());
            }
        }
        $this->assertSame($before, FundamentalBootstrapRun::query()->count());
    }

    public function test_health_uses_global_counts_and_get_does_not_create_work(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $investor = User::factory()->create(['is_admin' => false]);
        $stock = $this->stock('INTHEALTH');
        $ops = app(BootstrapOperationsService::class);
        $a = $ops->launch(['scope' => 'stocks', 'stock_ids' => [$stock->id]], $admin->id, $admin->name, 'integrated-health-key-001')->run;
        $b = $ops->launch(['scope' => 'stocks', 'stock_ids' => [$stock->id]], $admin->id, $admin->name, 'integrated-health-key-002')->run;
        $before = [FundamentalBootstrapRun::query()->count(), FundamentalBootstrapJob::query()->count(), DB::table('stox_bootstrap_schedule_occurrences')->count()];
        $this->actingAs($investor)->getJson(self::ROOT.'/health')->assertForbidden();
        $this->actingAs($admin)->getJson(self::ROOT.'/health')->assertOk()->assertJsonPath('data.queued_count', 2)
            ->assertJsonPath('data.active_run', null)->assertJsonPath('data.next_schedule', null);
        $this->getJson(self::ROOT.'/runs?per_page=1')->assertOk()->assertJsonPath('total', 2);
        $this->assertSame($before, [FundamentalBootstrapRun::query()->count(), FundamentalBootstrapJob::query()->count(), DB::table('stox_bootstrap_schedule_occurrences')->count()]);
        $a->jobs()->update(['status' => 'failed']);
        $a->forceFill(['status' => 'completed', 'failed' => 1, 'queued' => 0])->save();
        $this->getJson(self::ROOT.'/health')->assertOk()->assertJsonPath('data.unresolved_failed_items', 1);
        $b->jobs()->update(['status' => 'complete_good']);
        $b->forceFill(['status' => 'completed', 'failed' => 0, 'queued' => 0])->save();
        $this->getJson(self::ROOT.'/health')->assertOk()->assertJsonPath('data.unresolved_failed_items', 0)
            ->assertJsonPath('data.last_successful_run.id', $b->id);
    }

    public function test_canonical_schedule_commit_recovers_after_actor_revocation_and_cannot_start_fences_late_delivery(): void
    {
        \Carbon\CarbonImmutable::setTestNow('2026-10-22 08:00:00 UTC');
        try {
            $admin = User::factory()->create(['is_admin' => true, 'name' => 'Original Admin']);
            $stock = $this->stock('INTRECOVER');
            $schedules = app(\App\Services\Fundamentals\BootstrapScheduleOperationsService::class);
            $schedule = $schedules->createSchedule([
                'name' => 'Durable schedule recovery', 'configuration' => ['scope' => 'stocks', 'stock_ids' => [$stock->id]],
                'frequency' => 'daily', 'interval' => 1, 'timezone' => 'UTC', 'local_time' => '09:00:00',
                'starts_at_local' => '2026-10-22 09:00:00', 'max_occurrences' => 3,
            ], $admin->id);
            $schedules->setEnabled($schedule, true, $admin->id);
            \Carbon\CarbonImmutable::setTestNow('2026-10-22 09:00:00 UTC');
            $this->assertSame(1, $schedules->materializeDue(\Carbon\CarbonImmutable::now('UTC')));
            $occurrence = \App\Models\V7\BootstrapScheduleOccurrence::query()->sole();
            $config = $occurrence->configuration_snapshot;
            $receipt = [...$this->receipt($config, $admin),
                'scheduled_at' => '2026-10-22T09:00:00Z', 'schedule_id' => (int) $schedule->id,
                'schedule_generation' => (int) $occurrence->generation, 'schedule_version' => (int) $occurrence->schedule_version];
            $adapter = app(\App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter::class);
            $created = $adapter->launch($config, $admin->id, $admin->name, $occurrence->idempotency_key, $receipt);
            $this->assertTrue($created->created);
            $this->assertSame('pending', $occurrence->fresh()->status, 'Model the crash between A commit and C acknowledgement.');
            $admin->forceFill(['is_admin' => false, 'name' => 'Renamed after commit'])->save();
            $this->assertSame(1, $schedules->deliverPending($adapter));
            $this->assertSame('queued', $occurrence->fresh()->status);
            $this->assertSame($created->run->id, $occurrence->fresh()->run_id);
            $this->assertSame('Original Admin', $created->run->fresh()->request_snapshot['requested_by']['name']);
            $this->assertSame(1, FundamentalBootstrapRun::query()->count());

            \Carbon\CarbonImmutable::setTestNow('2026-10-23 09:00:00 UTC');
            $this->assertSame(1, $schedules->materializeDue(\Carbon\CarbonImmutable::now('UTC')));
            $later = \App\Models\V7\BootstrapScheduleOccurrence::query()->latest('id')->firstOrFail();
            $this->assertSame(0, $schedules->deliverPending($adapter));
            $this->assertSame('cannot_start', $later->fresh()->status);
            $this->assertSame('actor_not_admin', $later->fresh()->failure_code);
            $this->assertNull($later->fresh()->run_id);
            $admin->forceFill(['is_admin' => true])->save();
            $laterReceipt = [...$receipt, 'scheduled_at' => '2026-10-23T09:00:00Z'];
            try {
                $adapter->launch($later->configuration_snapshot, $admin->id, $admin->name, $later->idempotency_key, $laterReceipt);
                $this->fail('A late in-flight delivery cannot create work after definitive cannot-start.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('schedule_occurrence', $exception->errors());
            }
            $this->assertSame(1, FundamentalBootstrapRun::query()->count());
        } finally {
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    public function test_real_engine_global_pause_prevents_claims_then_resumes_the_same_managed_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = $this->stock('INTPAUSE');
        $settings = app(\App\Services\Fundamentals\FundamentalDataService::class)->settings();
        $settings->forceFill(['paused' => true, 'request_delay_ms' => 0])->save();
        $run = app(BootstrapOperationsService::class)->launch(
            ['scope' => 'stocks', 'stock_ids' => [$stock->id]], $admin->id, $admin->name, 'integrated-global-pause-001')->run;
        $engine = app(\App\Services\Fundamentals\FundamentalBootstrapService::class);
        $paused = $engine->process($run, 10);
        $this->assertSame('fundamentals_globally_paused', $paused['skipped_reason']);
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertNull($run->fresh()->started_at);
        $this->assertSame(0, (int) $run->jobs()->sole()->attempts);
        $settings->forceFill(['paused' => false])->save();
        // Exclusion after launch gives a provider-free terminal result while exercising the real engine.
        $stock->forceFill(['is_benchmark' => true])->save();
        $engine->process($run->fresh(), 10);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('complete_no_data', $run->jobs()->sole()->status);
        $this->assertSame(1, FundamentalBootstrapRun::query()->count());
    }

    private function stock(string $symbol): Stock
    {
        return Stock::query()->create(['symbol' => $symbol, 'name' => $symbol, 'exchange' => 'NSE', 'is_active' => true, 'is_benchmark' => false]);
    }

    private function gap(Stock $stock, string $metric, string $cadence): BootstrapCoverageGap
    {
        $run = FundamentalBootstrapRun::query()->create(['scope' => 'stocks', 'trigger' => 'manual', 'status' => 'completed']);
        return BootstrapCoverageGap::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'metric_key' => $metric, 'cadence' => $cadence,
            'reason' => 'ingestion_not_attempted', 'state' => 'actionable',
            'identity_hash' => hash('sha256', $stock->id.':'.$metric.':'.$cadence),
        ]);
    }

    private function receipt(array $config, User $admin): array
    {
        return [
            'trigger' => 'scheduled', 'scheduled_at' => '2026-10-22T09:00:00Z', 'schedule_id' => 41,
            'schedule_generation' => 1, 'schedule_version' => 2, 'actor_admin_id' => $admin->id,
            'preset_id' => null, 'preset_revision' => null, 'configuration_snapshot' => $config,
            'configuration_sha256' => hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'run_now' => false,
        ];
    }
}
