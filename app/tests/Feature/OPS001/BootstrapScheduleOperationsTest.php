<?php

namespace Tests\Feature\OPS001;

use App\Models\User;
use App\Models\Stock;
use App\Models\V7\BootstrapPreset;
use App\Models\V7\BootstrapSchedule;
use App\Models\V7\BootstrapScheduleOccurrence;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapLaunchResult;
use App\Services\Fundamentals\BootstrapScheduleOperationsService;
use App\Services\Fundamentals\Scheduling\BootstrapScheduleLaunchAdapter;
use App\Services\Fundamentals\Scheduling\BootstrapEligibleStockResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BootstrapScheduleOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(BootstrapEligibleStockResolver::class, new class implements BootstrapEligibleStockResolver {
            public function eligibleStockIds(array $requestedIds): array
            {
                return Stock::query()->whereIn('id', $requestedIds)->where('is_active', true)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
        });
    }

    protected function tearDown(): void { CarbonImmutable::setTestNow(); parent::tearDown(); }

    public function test_preset_revision_is_snapshotted_and_delete_is_blocked_for_any_schedule(): void
    {
        CarbonImmutable::setTestNow('2026-10-22 08:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'C-PRESET-1', 'exchange' => 'NSE', 'name' => 'Preset Fixture', 'is_active' => true, 'is_benchmark' => false]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $preset = $ops->savePreset(['name' => 'Whole market', 'configuration' => ['scope' => 'all', 'stock_ids' => []]], $admin->id);
        $schedule = $ops->createSchedule($this->scheduleInput(['preset_id' => $preset->id]), $admin->id);
        $ops->savePreset(['name' => 'Whole market', 'configuration' => ['scope' => 'stocks', 'stock_ids' => [$stock->id]]], $admin->id, $preset);
        $this->assertSame(1, $schedule->fresh()->preset_revision);
        $this->assertTrue(\App\Services\Fundamentals\BootstrapRequestContext::same(['scope' => 'all', 'stock_ids' => [], 'force' => false, 'reason' => null], $schedule->fresh()->configuration_snapshot));
        $this->expectException(ValidationException::class);
        $ops->deletePreset($preset->fresh(), $admin->id);
    }

    public function test_configuration_rejects_provider_or_unknown_fields_and_preserves_supported_force_request(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $stock = Stock::query()->create(['symbol' => 'C-PRESET-2', 'exchange' => 'NSE', 'name' => 'Force Fixture', 'is_active' => true, 'is_benchmark' => false]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $preset = $ops->savePreset(['name' => 'Governed rerun', 'configuration' => ['scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => true, 'reason' => 'Source correction']], $admin->id);
        $this->assertSame(['scope' => 'stocks', 'stock_ids' => [$stock->id], 'force' => true, 'reason' => 'Source correction'], $preset->configuration);
        try {
            $ops->savePreset(['name' => 'Unsafe provider', 'configuration' => ['scope' => 'all', 'stock_ids' => [], 'provider' => 'alternate']], $admin->id);
            $this->fail('Unsupported provider/configuration fields must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration', $exception->errors());
        }
        $inactive = Stock::query()->create(['symbol' => 'C-PRESET-3', 'exchange' => 'NSE', 'name' => 'Inactive Fixture', 'is_active' => false, 'is_benchmark' => false]);
        try {
            $ops->savePreset(['name' => 'Inactive target', 'configuration' => ['scope' => 'stocks', 'stock_ids' => [$inactive->id]]], $admin->id);
            $this->fail('Stock IDs must resolve to the exact active eligible set.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.stock_ids', $exception->errors());
        }
    }

    public function test_materialization_is_unique_and_max_count_disables_schedule(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00', 'max_occurrences' => 1]), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $now = CarbonImmutable::now('UTC');
        $this->assertSame(1, $ops->materializeDue($now));
        $this->assertSame(0, $ops->materializeDue($now));
        $this->assertDatabaseCount('stox_bootstrap_schedule_occurrences', 1);
        $this->assertFalse($schedule->fresh()->enabled);
        $this->assertSame(1, $schedule->fresh()->occurrences_created);
    }

    public function test_reenable_skips_all_disabled_periods_without_consuming_the_occurrence_limit(): void
    {
        CarbonImmutable::setTestNow('2026-10-30 09:30:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00', 'max_occurrences' => 5]), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $ops->setEnabled($schedule, false, $admin->id);

        CarbonImmutable::setTestNow('2026-11-05 10:00:00 UTC');
        $reenabled = $ops->setEnabled($schedule->fresh(), true, $admin->id);
        $this->assertSame('2026-11-06 09:30:00', $reenabled->getRawOriginal('next_occurrence_at'));
        $this->assertSame(1, $reenabled->occurrences_created);
        $oldPending = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame('pending', $oldPending->status);
        $this->assertSame(2, $oldPending->schedule_version);
        $this->assertSame(0, $ops->materializeDue(CarbonImmutable::parse('2026-11-05 23:59:59 UTC')));
        $this->assertDatabaseCount('stox_bootstrap_schedule_occurrences', 1);
        $this->assertSame('pending', $oldPending->fresh()->status);
        $this->assertSame(1, $ops->materializeDue(CarbonImmutable::parse('2026-11-06 09:30:00 UTC')));
        $this->assertSame(2, $schedule->fresh()->occurrences_created);
    }

    public function test_end_date_stops_after_last_local_boundary_and_investor_cannot_manage_schedules(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $investor = User::factory()->create(['is_admin' => false]);
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        try {
            $ops->savePreset(['name' => 'Denied', 'configuration' => ['scope' => 'all', 'stock_ids' => []]], $investor->id);
            $this->fail('Investor must be rejected by the server-side service check.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actor', $exception->errors());
        }
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00', 'ends_on_local' => '2026-11-01']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $this->assertFalse($schedule->fresh()->enabled);
        $this->assertNull($schedule->fresh()->next_occurrence_at);
        $this->assertDatabaseCount('stox_bootstrap_schedule_occurrences', 1);
    }

    public function test_admin_read_endpoint_is_protected_and_does_not_mutate(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'admin'])->get('/_ops001_package_c/presets', [\App\Http\Controllers\Api\V1\BootstrapScheduleOperationsController::class, 'presets']);
        $admin = User::factory()->create(['is_admin' => true]);
        $investor = User::factory()->create(['is_admin' => false]);
        $before = [
            'presets' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_presets')->count(),
            'schedules' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_schedules')->count(),
            'occurrences' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_schedule_occurrences')->count(),
        ];
        $this->actingAs($investor)->getJson('/_ops001_package_c/presets')->assertForbidden();
        $this->actingAs($admin)->getJson('/_ops001_package_c/presets')->assertOk();
        $this->assertSame($before, [
            'presets' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_presets')->count(),
            'schedules' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_schedules')->count(),
            'occurrences' => \Illuminate\Support\Facades\DB::table('stox_bootstrap_schedule_occurrences')->count(),
        ]);
    }

    public function test_monthly_clamps_short_months_and_dst_occurrences_keep_local_calendar_time(): void
    {
        $recurrence = app(\App\Services\Fundamentals\Scheduling\BootstrapScheduleRecurrence::class);
        $monthly = ['frequency' => 'monthly', 'interval' => 1, 'monthday' => 31, 'timezone' => 'UTC', 'starts_at_local' => '2026-01-31 09:00:00', 'local_time' => '09:00:00'];
        $feb = $recurrence->next($monthly, CarbonImmutable::parse('2026-01-31 09:00:00', 'UTC'));
        $mar = $recurrence->next($monthly, $feb);
        $this->assertSame('2026-02-28 09:00:00', $feb->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 09:00:00', $mar->format('Y-m-d H:i:s'));
        $daily = ['frequency' => 'daily', 'interval' => 1, 'timezone' => 'America/New_York', 'starts_at_local' => '2026-03-07 02:30:00', 'local_time' => '02:30:00'];
        $first = $recurrence->next($daily);
        $second = $recurrence->next($daily, $first);
        $third = $recurrence->next($daily, $second);
        $this->assertSame('03:30', $second->format('H:i'));
        $this->assertSame('02:30', $third->format('H:i'));
        $this->assertSame('America/New_York', $second->timezoneName);
    }

    public function test_non_utc_dst_receipt_has_unambiguous_local_wall_time_and_matching_utc_instant(): void
    {
        CarbonImmutable::setTestNow('2026-03-07 01:00:00 America/New_York');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['timezone' => 'America/New_York', 'local_time' => '02:30:00', 'starts_at_local' => '2026-03-07 02:30:00', 'max_occurrences' => 3]), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        $ops->materializeDue(CarbonImmutable::parse('2026-03-07 07:30:01 UTC'));
        $ops->materializeDue(CarbonImmutable::parse('2026-03-08 08:00:00 UTC'));
        $page = $ops->occurrences($schedule->fresh());
        $receipt = $page->items()[0];
        $this->assertSame('2026-03-08 03:30:00', $receipt['scheduled_at_local']);
        $this->assertSame('America/New_York', $receipt['timezone']);
        $this->assertSame('2026-03-08T07:30:00Z', $receipt['scheduled_at_utc']);
        $this->assertSame('2026-03-08T07:30:00Z', CarbonImmutable::parse($receipt['scheduled_at_local'], $receipt['timezone'])->utc()->format('Y-m-d\\TH:i:s\\Z'));
    }

    public function test_delivery_failure_keeps_pending_receipt_retry_uses_same_key_and_run_now_does_not_consume_count(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00', 'max_occurrences' => 4]), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $adapter = new class implements BootstrapScheduleLaunchAdapter {
            public array $keys = []; public array $receipts = []; public bool $fail = true;
            public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
            {
                $this->keys[] = $idempotencyKey;
                $this->receipts[] = $receipt;
                if ($this->fail) { $this->fail = false; throw new \RuntimeException('transient adapter error'); }
                $run = new FundamentalBootstrapRun(['trigger' => $receipt['trigger'], 'scope' => $configuration['scope'], 'status' => 'queued', 'created_by_user_id' => $actorAdminId]);
                $run->forceFill(['idempotency_key' => hash('sha256', $actorAdminId.':'.$idempotencyKey), 'trigger_actor' => $actorName,
                    'request_snapshot' => ['scope' => $configuration['scope'], 'stock_ids' => $configuration['stock_ids'], 'force' => $configuration['force'], 'reason' => $configuration['reason'],
                        'requested_by' => ['id' => $actorAdminId, 'name' => $actorName], 'schedule_receipt' => $receipt]])->save();
                return new BootstrapLaunchResult($run, true);
            }
        };
        $this->assertSame(0, $ops->deliverPending($adapter));
        $this->assertSame('pending', BootstrapScheduleOccurrence::query()->sole()->status);
        $this->assertSame(1, $ops->deliverPending($adapter));
        $occurrence = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame('queued', $occurrence->status);
        $this->assertSame($adapter->keys[0], $adapter->keys[1]);
        $this->assertSame('queued', FundamentalBootstrapRun::query()->findOrFail($occurrence->run_id)->status);
        $this->assertSame('scheduled', FundamentalBootstrapRun::query()->findOrFail($occurrence->run_id)->trigger);
        $before = $schedule->fresh()->occurrences_created;
        $beforeNext = $schedule->fresh()->getRawOriginal('next_occurrence_at');
        $runNow = $ops->runNow($schedule->fresh(), $admin->id, $admin->name, 'run-now-key-001', $adapter);
        $this->assertSame($before, $schedule->fresh()->occurrences_created);
        $this->assertSame($beforeNext, $schedule->fresh()->getRawOriginal('next_occurrence_at'));
        $runNowReceipt = $adapter->receipts[2];
        $this->assertSame('manual', $runNowReceipt['trigger']);
        $this->assertSame($admin->id, $runNowReceipt['actor_admin_id']);
        $this->assertSame($schedule->id, $runNowReceipt['schedule_id']);
        $this->assertSame($schedule->generation, $runNowReceipt['schedule_generation']);
        $this->assertSame($schedule->version, $runNowReceipt['schedule_version']);
        $this->assertSame($schedule->configuration_snapshot, $runNowReceipt['configuration_snapshot']);
        $this->assertNull($runNowReceipt['preset_id']);
        $this->assertNull($runNowReceipt['preset_revision']);
        $this->assertNull($runNowReceipt['scheduled_at']);
        $this->assertTrue($runNowReceipt['run_now']);
        $this->assertTrue(\App\Services\Fundamentals\BootstrapRequestContext::same($runNowReceipt, $runNow->run->request_snapshot['schedule_receipt']));
    }

    public function test_mismatched_canonical_receipt_never_marks_occurrence_queued(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $badAdapter = new class implements BootstrapScheduleLaunchAdapter {
            public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
            {
                $run = new FundamentalBootstrapRun(['trigger' => 'scheduled', 'scope' => $configuration['scope'], 'status' => 'queued', 'created_by_user_id' => $actorAdminId]);
                $run->forceFill(['idempotency_key' => 'wrong-key', 'request_snapshot' => ['schedule_receipt' => []]])->save();
                return new BootstrapLaunchResult($run, true);
            }
        };
        $this->assertSame(0, $ops->deliverPending($badAdapter));
        $occurrence = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame('pending', $occurrence->status);
        $this->assertNull($occurrence->run_id);
        $this->assertTrue($occurrence->last_delivery_error !== null);
    }

    public function test_revoked_schedule_actor_transitions_pending_occurrence_to_cannot_start_without_launch(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        User::query()->whereKey($admin->id)->update(['is_admin' => false]);
        $adapter = new class implements BootstrapScheduleLaunchAdapter {
            public int $calls = 0;
            public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
            {
                $this->calls++;
                throw new \RuntimeException('The revoked actor must never reach launch.');
            }
        };
        $this->assertSame(0, $ops->deliverPending($adapter));
        $occurrence = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame(0, $adapter->calls);
        $this->assertSame('cannot_start', $occurrence->status);
        $this->assertSame('actor_not_admin', $occurrence->failure_code);
        $this->assertNotNull($occurrence->failed_at);
        $this->assertNull($occurrence->run_id);
        $this->assertNull($occurrence->last_delivery_error);
        $projection = $ops->occurrences($schedule->fresh())->items()[0];
        $this->assertSame('actor_not_admin', $projection['failure_code']);
        $this->assertNotNull($projection['failed_at']);
        $this->assertFalse($projection['delivery_error']);
        $this->assertArrayNotHasKey('last_delivery_error', $projection);
        $this->assertSame(0, $ops->deliverPending($adapter));
    }

    public function test_canonical_eligibility_validation_marks_cannot_start_without_fabricating_a_run(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $adapter = new class implements BootstrapScheduleLaunchAdapter {
            public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
            {
                throw ValidationException::withMessages(['stock_ids' => 'A selected equity is no longer eligible.']);
            }
        };
        $this->assertSame(0, $ops->deliverPending($adapter));
        $occurrence = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame('cannot_start', $occurrence->status);
        $this->assertSame('eligible_stocks_unavailable', $occurrence->failure_code);
        $this->assertNotNull($occurrence->failed_at);
        $this->assertNull($occurrence->run_id);
        $this->assertNull($occurrence->last_delivery_error);
    }

    public function test_unclassified_canonical_validation_rejection_remains_retryable_pending(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $adapter = new class implements BootstrapScheduleLaunchAdapter {
            public array $keys = [];
            public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
            {
                $this->keys[] = $idempotencyKey;
                throw ValidationException::withMessages(['Idempotency-Key' => 'A key conflict needs receipt reconciliation.']);
            }
        };
        $this->assertSame(0, $ops->deliverPending($adapter));
        $first = BootstrapScheduleOccurrence::query()->sole();
        $this->assertSame('pending', $first->status);
        $this->assertNull($first->failure_code);
        $this->assertNull($first->failed_at);
        $this->assertTrue($first->last_delivery_error !== null);
        $this->assertSame(0, $ops->deliverPending($adapter));
        $this->assertSame($adapter->keys[0], $adapter->keys[1]);
        $this->assertSame('pending', $first->fresh()->status);
    }

    public function test_occurrence_created_before_edit_retains_old_config_and_versions(): void
    {
        CarbonImmutable::setTestNow('2026-11-01 09:00:00 UTC');
        $admin = User::factory()->create(['is_admin' => true]);
        $ops = app(BootstrapScheduleOperationsService::class);
        $schedule = $ops->createSchedule($this->scheduleInput(['starts_at_local' => '2026-11-01 09:30:00']), $admin->id);
        $ops->setEnabled($schedule, true, $admin->id);
        $capturedVersion = $schedule->fresh()->version;
        CarbonImmutable::setTestNow('2026-11-01 09:30:00 UTC');
        $ops->materializeDue(CarbonImmutable::now('UTC'));
        $old = BootstrapScheduleOccurrence::query()->sole();
        $ops->editSchedule($schedule, $this->scheduleInput(['starts_at_local' => '2026-11-02 09:30:00', 'configuration' => ['scope' => 'all', 'stock_ids' => []]]), $admin->id);
        $this->assertSame($capturedVersion, $old->fresh()->schedule_version);
        $this->assertSame('2026-11-01 09:30:00', $old->fresh()->scheduled_at_local->format('Y-m-d H:i:s'));
        $this->assertSame(2, $schedule->fresh()->generation);
        $this->assertSame($capturedVersion + 1, $schedule->fresh()->version);
        $this->assertSame($capturedVersion, $old->fresh()->schedule_version);
    }

    private function scheduleInput(array $overrides = []): array
    {
        return array_replace(['name' => 'Daily whole market', 'configuration' => ['scope' => 'all', 'stock_ids' => []], 'frequency' => 'daily', 'interval' => 1, 'timezone' => 'UTC', 'local_time' => '09:30:00', 'starts_at_local' => '2026-10-23 09:30:00'], $overrides);
    }
}
