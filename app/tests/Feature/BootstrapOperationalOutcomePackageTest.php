<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\User;
use App\Models\V7\BootstrapOutcomeReceipt;
use App\Models\V7\BootstrapScheduleOccurrence;
use App\Models\V7\FundamentalBootstrapJob;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapOperationalOutcomeService;
use App\Services\Fundamentals\BootstrapWarningCategory;
use App\Services\Fundamentals\FundamentalBootstrapService;
use App\Models\NotificationSource;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BootstrapOperationalOutcomePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_terminal_event_is_deduped_and_legacy_run_is_ignored(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->withArgs(function ($recipients, array $event) use ($admin): bool {
            return $recipients->contains('id', $admin->id)
                && $event['audience'] === 'admin'
                && $event['external_info_delivery'] === false
                && ($event['primary_action']['route'] ?? null) === '/settings/fundamentals#bootstrap-operations'
                && $event['notification_type'] === 'ops001.run_completed_with_failures';
        })->andReturn(new NotificationSource());
        $this->app->instance(NotificationPublisher::class, $publisher);

        $managed = $this->outcomeRun(['status' => 'completed', 'completed' => 2, 'failed' => 1]);
        $service = app(BootstrapOperationalOutcomeService::class);
        $this->assertTrue($service->notifyRunOutcome($managed));
        $this->assertFalse($service->notifyRunOutcome($managed));
        $legacy = $this->outcomeRun(['request_snapshot' => ['origin' => 'legacy'], 'status' => 'failed']);
        $this->assertFalse($service->notifyRunOutcome($legacy));
        $cancelled = $this->outcomeRun(['status' => 'cancelled', 'completed' => 2, 'failed' => 3]);
        $this->assertFalse($service->notifyRunOutcome($cancelled));
        $this->assertDatabaseCount('stox_bootstrap_outcome_receipts', 1);
    }

    public function test_notification_failure_rolls_back_receipt_and_can_be_retried(): void
    {
        User::factory()->create(['is_admin' => true]);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->twice()->andReturnUsing(function (): NotificationSource {
            static $attempt = 0;
            if (++$attempt === 1) throw new RuntimeException('canonical event rejected');
            return new NotificationSource();
        });
        $this->app->instance(NotificationPublisher::class, $publisher);
        $run = $this->outcomeRun(['status' => 'failed', 'failed' => 1]);
        $service = app(BootstrapOperationalOutcomeService::class);

        try { $service->notifyRunOutcome($run); $this->fail('Expected publisher rejection.'); }
        catch (RuntimeException $error) { $this->assertSame('canonical event rejected', $error->getMessage()); }

        $this->assertDatabaseCount('stox_bootstrap_outcome_receipts', 0);
        $this->assertTrue($service->notifyRunOutcome($run));
        $this->assertNotNull(BootstrapOutcomeReceipt::query()->firstOrFail()->accepted_at);
    }

    public function test_schedule_and_persisted_quality_warning_windows_are_thresholded_and_idempotent(): void
    {
        User::factory()->create(['is_admin' => true]);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->times(3)->withArgs(fn ($recipients, array $event) => $event['external_info_delivery'] === false)->andReturn(new NotificationSource());
        $this->app->instance(NotificationPublisher::class, $publisher);
        $service = app(BootstrapOperationalOutcomeService::class);

        $due = now('UTC')->subHours(2)->startOfMinute();
        $actor = User::query()->where('is_admin', true)->firstOrFail();
        $configuration = ['scope' => 'all', 'stock_ids' => [], 'force' => false, 'reason' => null];
        $this->schedule(7001, (int) $actor->id, $configuration, 3, 2);
        $occurrenceKey = $this->occurrenceKey(7001, 3, $due);
        $receipt = $this->scheduleReceipt(7001, 3, 2, (int) $actor->id, $due, $configuration);
        $run = $this->outcomeRun([
            'trigger' => 'scheduled', 'created_by_user_id' => $actor->id,
            'idempotency_key' => hash('sha256', $actor->id.':'.$occurrenceKey),
            'status' => 'running', 'started_at' => $due->copy()->addHour(),
            'request_snapshot' => [
                'managed_version' => FundamentalBootstrapService::OPS_MANAGED_VERSION,
                'origin' => 'admin_fundamentals_bootstrap_operations', 'trigger' => 'scheduled',
                'schedule_receipt' => $receipt,
            ],
        ]);
        $occurrenceId = $this->occurrence(7001, $run->id, $due, 'queued', (int) $actor->id, $configuration, 3, 2);
        $storedConfiguration = BootstrapScheduleOccurrence::query()->findOrFail($occurrenceId)->configuration_snapshot;
        $receipt['configuration_sha256'] = hash('sha256', json_encode($storedConfiguration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $run->forceFill(['request_snapshot' => [...$run->request_snapshot, 'schedule_receipt' => $receipt]])->save();

        $this->assertTrue($service->notifyScheduledOccurrence($occurrenceId, 'started'));
        $this->assertFalse($service->notifyScheduledOccurrence($occurrenceId, 'started'));
        $this->assertFalse($service->notifyScheduledOccurrence(999999, 'started'));
        $pendingId = $this->occurrence(7002, null, $due, 'pending', (int) $actor->id, $configuration);
        $this->assertFalse($service->notifyScheduledOccurrence($pendingId, 'cannot_start'), 'Transient pending delivery is not a definitive cannot-start outcome.');

        $qualityRun = $this->outcomeRun();
        $this->addWarnings($qualityRun, 3, BootstrapWarningCategory::ANOMALY_REVIEW);
        $this->assertTrue($service->notifyRepeatedQualityWarningOccurrences($qualityRun, BootstrapWarningCategory::ANOMALY_REVIEW));
        $this->addWarnings($qualityRun, 2, BootstrapWarningCategory::ANOMALY_REVIEW);
        $this->assertFalse($service->notifyRepeatedQualityWarningOccurrences($qualityRun, BootstrapWarningCategory::ANOMALY_REVIEW));

        $laterRun = $this->outcomeRun();
        $this->addWarnings($laterRun, 3, BootstrapWarningCategory::ANOMALY_REVIEW);
        $this->assertTrue($service->notifyRepeatedQualityWarningOccurrences($laterRun, BootstrapWarningCategory::ANOMALY_REVIEW));
        $legacy = $this->outcomeRun(['request_snapshot' => ['origin' => 'legacy']]);
        $this->addWarnings($legacy, 3, BootstrapWarningCategory::ANOMALY_REVIEW);
        $this->assertFalse($service->notifyRepeatedQualityWarningOccurrences($legacy, BootstrapWarningCategory::ANOMALY_REVIEW));
        $this->assertDatabaseCount('stox_bootstrap_outcome_receipts', 3);
    }

    public function test_only_durable_definitive_cannot_start_occurrences_notify(): void
    {
        $operator = User::factory()->create(['is_admin' => true]);
        $actor = User::factory()->create(['is_admin' => true]);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->withArgs(fn ($recipients, array $event) =>
            $event['notification_type'] === 'ops001.scheduled_run_cannot_start'
            && $event['context']['failure_code'] === 'actor_not_admin'
            && $event['external_info_delivery'] === false
        )->andReturn(new NotificationSource());
        $this->app->instance(NotificationPublisher::class, $publisher);

        $due = now('UTC')->subHour()->startOfMinute();
        $configuration = ['scope' => 'all', 'stock_ids' => [], 'force' => false, 'reason' => null];
        $this->schedule(7101, (int) $actor->id, $configuration);
        $actor->forceFill(['is_admin' => false])->save();
        $this->assertFalse($actor->fresh()->is_admin, 'The persisted actor must actually be revoked before testing cannot-start.');
        $definitiveId = $this->occurrence(7101, null, $due, 'cannot_start', (int) $actor->id, $configuration, 1, 1, 'actor_not_admin');
        $service = app(BootstrapOperationalOutcomeService::class);
        $this->assertTrue($service->notifyScheduledOccurrence($definitiveId, 'cannot_start'));
        $this->assertFalse($service->notifyScheduledOccurrence($definitiveId, 'cannot_start'));

        $pendingId = $this->occurrence(7102, null, $due, 'pending', (int) $operator->id, $configuration);
        $this->assertFalse($service->notifyScheduledOccurrence($pendingId, 'cannot_start'));
        $unknownFailureId = $this->occurrence(7103, null, $due, 'cannot_start', (int) $operator->id, $configuration, 1, 1, 'RuntimeException');
        $this->assertFalse($service->notifyScheduledOccurrence($unknownFailureId, 'cannot_start'));
        $wrongActorStateId = $this->occurrence(7104, null, $due, 'cannot_start', (int) $operator->id, $configuration, 1, 1, 'actor_not_admin');
        $this->assertFalse($service->notifyScheduledOccurrence($wrongActorStateId, 'cannot_start'));
        $wrongIdentityId = $this->occurrence(7105, null, $due, 'cannot_start', (int) $operator->id, $configuration, 1, 1, 'configuration_invalid', false);
        $this->assertFalse($service->notifyScheduledOccurrence($wrongIdentityId, 'cannot_start'));
        $linkedRun = $this->outcomeRun();
        $linkedRunId = $this->occurrence(7106, (int) $linkedRun->id, $due, 'cannot_start', (int) $operator->id, $configuration, 1, 1, 'configuration_invalid');
        $this->assertFalse($service->notifyScheduledOccurrence($linkedRunId, 'cannot_start'), 'Definitive cannot-start state must have no linked run.');
        $this->assertFalse($service->notifyScheduledOccurrence(999999, 'cannot_start'));
        $this->assertDatabaseCount('stox_bootstrap_outcome_receipts', 1);
    }

    public function test_quality_warning_publish_retry_keeps_one_stable_run_window_receipt(): void
    {
        User::factory()->create(['is_admin' => true]);
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->twice()->andReturnUsing(function (): NotificationSource {
            static $attempt = 0;
            if (++$attempt === 1) throw new RuntimeException('canonical event rejected');
            return new NotificationSource();
        });
        $this->app->instance(NotificationPublisher::class, $publisher);
        $run = $this->outcomeRun();
        $this->addWarnings($run, 3, BootstrapWarningCategory::MISSING_PROVENANCE);
        $service = app(BootstrapOperationalOutcomeService::class);

        try {
            $service->notifyRepeatedQualityWarningOccurrences($run, BootstrapWarningCategory::MISSING_PROVENANCE);
            $this->fail('Expected canonical publication failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('canonical event rejected', $error->getMessage());
        }
        $this->addWarnings($run, 2, BootstrapWarningCategory::MISSING_PROVENANCE);
        $this->assertTrue($service->notifyRepeatedQualityWarningOccurrences($run, BootstrapWarningCategory::MISSING_PROVENANCE));
        $this->assertFalse($service->notifyRepeatedQualityWarningOccurrences($run, BootstrapWarningCategory::MISSING_PROVENANCE));
        $this->assertDatabaseCount('stox_bootstrap_outcome_receipts', 1);
        $this->assertNotNull(BootstrapOutcomeReceipt::query()->firstOrFail()->accepted_at);
    }

    public function test_admin_export_is_private_scoped_and_formula_safe_for_multiline_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $symbol = "\t=CMD()\nline,\"quoted\"";
        $this->assertLessThanOrEqual(255, strlen($symbol), 'The fixture must fit portfolio_stocks.symbol without truncation.');
        $stock = Stock::query()->create(['symbol' => $symbol, 'name' => 'Example']);
        $run = $this->outcomeRun(['status' => 'completed', 'completed' => 1]);
        FundamentalBootstrapJob::query()->create([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'status' => 'failed',
            'last_error' => 'Bearer secret and raw provider detail', 'quarterly_status' => 'failed',
        ]);
        $priorRun = $this->outcomeRun();
        DB::table('stox_fund_bootstrap_quality_warnings')->insert([
            'run_id' => $run->id, 'stock_id' => $stock->id, 'category' => '=HYPERLINK(1)|facts_rejected_by_quality_gate',
            'identity_hash' => hash('sha256', 'malicious-warning-'.$run->id), 'summary' => 'safe fixture', 'state' => 'unresolved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gapId = DB::table('stox_fund_bootstrap_coverage_gaps')->insertGetId([
            'run_id' => $priorRun->id, 'stock_id' => $stock->id, 'metric_key' => 'revenue', 'cadence' => 'quarterly',
            'reason' => 'raw-gap-category', 'state' => 'actionable', 'identity_hash' => hash('sha256', 'multirun-gap-'.$stock->id),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stox_fund_bootstrap_gap_observations')->insert([
            'gap_id' => $gapId, 'run_id' => $priorRun->id, 'observed_at' => now(), 'observed_reason' => 'source_unavailable', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stox_fund_bootstrap_gap_observations')->insert([
            'gap_id' => $gapId, 'run_id' => $run->id, 'observed_at' => now(), 'observed_reason' => '=HYPERLINK("evil")|source_unavailable', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/api/v1/admin/fundamentals/bootstrap-operations/runs/'.$run->id.'/export.csv?failed_only=1');
        $response->assertOk();
        $response->assertHeader('cache-control', 'private, no-store, max-age=0');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('run_summary', $csv);
        $rows = $this->parseCsvRows($csv);
        $items = array_values(array_filter($rows, fn (array $row): bool => ($row[0] ?? null) === 'item'));
        $this->assertCount(1, $items, 'failed_only must return only the one matching item row.');
        $this->assertSame("'".$symbol, $items[0][8], 'fgetcsv must recover the exact formula-escaped multiline cell.');
        $this->assertStringContainsString('Bootstrap item failed; inspect the protected operational logs.', $csv);
        $this->assertStringContainsString('unknown_quality_warning', $csv);
        $this->assertStringContainsString('evidence_unknown', $csv);
        $this->assertStringNotContainsString('=HYPERLINK', $csv);
        $this->assertStringNotContainsString('facts_rejected_by_quality_gate', $csv, 'A delimiter inside an unknown raw category must not inject an allowed category.');
        $this->assertStringNotContainsString('source_unavailable', $csv, 'A delimiter inside an unknown raw reason must not inject an allowed category.');
        $this->assertStringNotContainsString('raw-gap-category', $csv);
        $this->assertStringNotContainsString('Bearer secret', $csv);
        $this->assertStringNotContainsString('checkpoint', strtolower($csv));
        $this->assertSame(0, BootstrapOutcomeReceipt::query()->count(), 'A GET export must not create outcome receipts.');
    }

    public function test_export_endpoint_is_admin_only_and_current_managed_scope_only(): void
    {
        $run = $this->outcomeRun(['status' => 'completed']);
        $path = '/api/v1/admin/fundamentals/bootstrap-operations/runs/'.$run->id.'/export.csv';
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get($path)->assertForbidden();

        $legacy = $this->outcomeRun(['request_snapshot' => ['managed_version' => 0, 'origin' => 'legacy']]);
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/api/v1/admin/fundamentals/bootstrap-operations/runs/'.$legacy->id.'/export.csv')->assertNotFound();
    }

    public function test_export_stream_chunks_large_item_scope_without_cross_run_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = $this->outcomeRun(['requested' => 260, 'completed' => 260]);
        for ($index = 0; $index < 260; $index++) {
            $stock = Stock::query()->create(['symbol' => 'OPS'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'name' => 'Stock '.$index]);
            FundamentalBootstrapJob::query()->create(['run_id' => $run->id, 'stock_id' => $stock->id, 'status' => 'complete_good']);
        }
        $otherRun = $this->outcomeRun();
        $otherStock = Stock::query()->create(['symbol' => 'UNRELATED', 'name' => 'Unrelated']);
        FundamentalBootstrapJob::query()->create(['run_id' => $otherRun->id, 'stock_id' => $otherStock->id, 'status' => 'failed']);

        $response = $this->actingAs($admin)->get('/api/v1/admin/fundamentals/bootstrap-operations/runs/'.$run->id.'/export.csv');
        $csv = $response->assertOk()->streamedContent();
        $this->assertStringContainsString('OPS259', $csv);
        $this->assertStringNotContainsString('UNRELATED', $csv);
    }

    private function outcomeRun(array $overrides = []): FundamentalBootstrapRun
    {
        return FundamentalBootstrapRun::query()->create(array_merge([
            'trigger' => 'manual', 'scope' => 'stocks', 'status' => 'completed', 'requested' => 1,
            'queued' => 0, 'running' => 0, 'completed' => 1, 'failed' => 0,
            'request_snapshot' => [
                'managed_version' => FundamentalBootstrapService::OPS_MANAGED_VERSION,
                'origin' => 'admin_fundamentals_bootstrap_operations', 'trigger' => 'manual',
            ],
        ], $overrides));
    }

    private function addWarnings(FundamentalBootstrapRun $run, int $count, string $category): void
    {
        static $stockSequence = 0;
        for ($index = 0; $index < $count; $index++) {
            $stock = Stock::query()->create(['symbol' => 'WARN'.str_pad((string) (++$stockSequence), 8, '0', STR_PAD_LEFT), 'name' => 'Warning stock']);
            DB::table('stox_fund_bootstrap_quality_warnings')->insert([
                'run_id' => $run->id, 'stock_id' => $stock->id, 'category' => $category,
                'identity_hash' => hash('sha256', implode(':', [$run->id, $stock->id, $category])),
                'summary' => 'Normalized test warning', 'state' => 'unresolved', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function schedule(int $scheduleId, int $actorId, array $configuration, int $generation = 1, int $version = 1): void
    {
        DB::table('stox_bootstrap_schedules')->insert([
            'id' => $scheduleId, 'name' => 'Test schedule '.$scheduleId, 'configuration_snapshot' => json_encode($configuration),
            'version' => $version, 'generation' => $generation, 'frequency' => 'daily', 'interval' => 1,
            'local_time' => '09:00:00', 'timezone' => 'UTC', 'starts_at_local' => now('UTC')->toDateTimeString(),
            'enabled' => true, 'created_by_user_id' => $actorId, 'updated_by_user_id' => $actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function occurrence(
        int $scheduleId,
        ?int $runId,
        \Illuminate\Support\Carbon $due,
        string $status,
        int $actorId,
        array $configuration,
        int $generation = 1,
        int $version = 1,
        ?string $failureCode = null,
        bool $validIdentity = true,
    ): int {
        if (! DB::table('stox_bootstrap_schedules')->where('id', $scheduleId)->exists()) {
            $this->schedule($scheduleId, $actorId, $configuration, $generation, $version);
        }
        $identity = $this->occurrenceKey($scheduleId, $generation, $due);
        return BootstrapScheduleOccurrence::query()->create([
            'schedule_id' => $scheduleId, 'generation' => $generation, 'schedule_version' => $version,
            'scheduled_at_utc' => $due->toDateTimeString(), 'scheduled_at_local' => $due->toDateTimeString(),
            'timezone' => 'UTC', 'configuration_snapshot' => $configuration, 'actor_admin_id' => $actorId,
            'status' => $status, 'idempotency_key' => $validIdentity ? $identity : hash('sha256', 'invalid-occurrence-identity-'.$scheduleId),
            'run_id' => $runId, 'delivered_at' => $status === 'queued' ? now() : null,
            'failure_code' => $failureCode, 'failed_at' => $status === 'cannot_start' ? now() : null,
        ])->id;
    }

    private function occurrenceKey(int $scheduleId, int $generation, \Illuminate\Support\Carbon $due): string
    {
        $identity = implode(':', [$scheduleId, $generation, $due->copy()->utc()->format('Ymd\\THis.u\\Z')]);
        return hash('sha256', 'bootstrap-schedule:'.$identity);
    }

    private function scheduleReceipt(int $scheduleId, int $generation, int $version, int $actorId, \Illuminate\Support\Carbon $due, array $configuration): array
    {
        return [
            'trigger' => 'scheduled', 'scheduled_at' => $due->copy()->utc()->format('Y-m-d\\TH:i:s\\Z'),
            'schedule_id' => $scheduleId, 'schedule_generation' => $generation, 'schedule_version' => $version,
            'actor_admin_id' => $actorId, 'preset_id' => null, 'preset_revision' => null,
            'configuration_snapshot' => $configuration,
            'configuration_sha256' => hash('sha256', json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'run_now' => false,
        ];
    }

    private function parseCsvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = $row;
        fclose($stream);
        return $rows;
    }
}
