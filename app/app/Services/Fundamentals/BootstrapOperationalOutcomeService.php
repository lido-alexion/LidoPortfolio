<?php

namespace App\Services\Fundamentals;

use App\Models\User;
use App\Models\V7\BootstrapOutcomeReceipt;
use App\Models\V7\BootstrapQualityWarning;
use App\Models\V7\BootstrapScheduleOccurrence;
use App\Models\V7\FundamentalBootstrapRun;
use App\Services\Fundamentals\BootstrapWarningCategory;
use App\Services\Notification\GenericNotificationPublisher;
use App\Services\Notification\NotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Publishes bounded OPS001 outcomes into COMM001's canonical in-app event stream. */
final class BootstrapOperationalOutcomeService
{
    public const MATERIAL_DELAY_SECONDS = 3600;
    public const REPEATED_FAILURE_THRESHOLD = 3;

    public function __construct(private GenericNotificationPublisher $notifications) {}

    public function notifyRunOutcome(FundamentalBootstrapRun $run): bool
    {
        $run = $this->managedRun($run->id);
        if (! $run || ! in_array($run->status, ['completed', 'failed'], true)) return false;

        $failed = (int) $run->failed;
        if ($run->status === 'failed') {
            return $this->publish('run:'.$run->id.':failed', 'run_failed', $run->id, null,
                'Bootstrap run failed', 'A managed fundamentals bootstrap run failed. Review its Admin run details.', ['run_id' => $run->id]);
        }
        if ($failed > 0) {
            return $this->publish('run:'.$run->id.':completed_with_failures', 'run_completed_with_failures', $run->id, null,
                'Bootstrap run completed with failures', 'A managed fundamentals bootstrap run completed with failed items. Review its Admin run details.', ['run_id' => $run->id, 'failed_count' => $failed]);
        }
        if ($run->status === 'completed' && ($run->request_snapshot['trigger'] ?? $run->trigger) === 'manual') {
            return $this->publish('run:'.$run->id.':manual_success', 'manual_run_completed', $run->id, null,
                'Bootstrap run completed', 'Your manually started fundamentals bootstrap run completed.', ['run_id' => $run->id]);
        }

        return false;
    }

    /** Delay means the persisted managed run started at least one hour after its persisted occurrence due time. */
    public function notifyScheduledOccurrence(int $occurrenceId, string $outcome): bool
    {
        if ($occurrenceId < 1) return false;
        $occurrence = BootstrapScheduleOccurrence::query()->whereKey($occurrenceId)->first();
        if (! $occurrence) return false;
        if ($outcome === 'cannot_start') {
            if (! $this->isDefinitiveCannotStart($occurrence)) return false;
            return $this->publish('occurrence:'.$occurrence->id.':cannot_start', 'scheduled_run_cannot_start', null, (int) $occurrence->id,
                'Scheduled bootstrap could not start', 'A scheduled fundamentals bootstrap occurrence could not start. Review its Admin schedule history.',
                ['occurrence_id' => (int) $occurrence->id, 'failure_code' => $occurrence->failure_code]);
        }
        if ($outcome !== 'started' || $occurrence->status !== 'queued' || ! $occurrence->run_id || ! $occurrence->delivered_at) return false;
        $run = $this->managedRun((int) $occurrence->run_id);
        $snapshot = $run?->request_snapshot ?? [];
        $scheduleReceipt = $snapshot['schedule_receipt'] ?? null;
        if (! $run || ! is_array($scheduleReceipt) || ! $this->scheduleReceiptMatches($occurrence, $run, $scheduleReceipt)
            || ! $run->started_at) return false;
        $scheduledTimestamp = $this->utcTimestamp((string) $occurrence->getRawOriginal('scheduled_at_utc'));
        $startedTimestamp = $this->utcTimestamp((string) $run->getRawOriginal('started_at'));
        $snapshotTimestamp = $this->utcTimestamp((string) ($scheduleReceipt['scheduled_at'] ?? ''));
        if ($scheduledTimestamp === null || $startedTimestamp === null || $snapshotTimestamp === null || $snapshotTimestamp !== $scheduledTimestamp) return false;
        $delay = $startedTimestamp - $scheduledTimestamp;
        if ($delay < self::MATERIAL_DELAY_SECONDS) return false;

        return $this->publish('occurrence:'.$occurrenceId.':material_delay', 'scheduled_run_materially_delayed', null, $occurrenceId,
            'Scheduled bootstrap was materially delayed', 'A scheduled fundamentals bootstrap occurrence started at least one hour after its due time.', ['occurrence_id' => $occurrenceId, 'delay_seconds' => $delay]);
    }

    private function isDefinitiveCannotStart(BootstrapScheduleOccurrence $occurrence): bool
    {
        $failureCodes = ['actor_unavailable', 'actor_not_admin', 'configuration_invalid', 'eligible_stocks_unavailable'];
        if ($occurrence->status !== 'cannot_start' || ! $occurrence->failed_at || $occurrence->run_id !== null
            || ! in_array($occurrence->failure_code, $failureCodes, true)
            || (int) $occurrence->schedule_id < 1 || (int) $occurrence->generation < 1 || (int) $occurrence->schedule_version < 1
            || (int) $occurrence->actor_admin_id < 1 || ! is_array($occurrence->configuration_snapshot)) return false;

        if (! $this->boundedOccurrenceConfiguration($occurrence->configuration_snapshot)) return false;

        $schedule = DB::table('stox_bootstrap_schedules')->where('id', $occurrence->schedule_id)->first();
        if (! $schedule || (int) $schedule->created_by_user_id !== (int) $occurrence->actor_admin_id) return false;
        $actor = User::query()->find((int) $occurrence->actor_admin_id);
        if (($occurrence->failure_code === 'actor_unavailable' && $actor !== null)
            || ($occurrence->failure_code === 'actor_not_admin' && (! $actor || $actor->is_admin))
            || (in_array($occurrence->failure_code, ['configuration_invalid', 'eligible_stocks_unavailable'], true) && (! $actor || ! $actor->is_admin))) return false;

        return $this->occurrenceIdentityMatches($occurrence);
    }

    /** Validate C's immutable receipt against the persisted occurrence and linked managed run. */
    private function scheduleReceiptMatches(BootstrapScheduleOccurrence $occurrence, FundamentalBootstrapRun $run, array $receipt): bool
    {
        $expectedKeys = ['trigger', 'scheduled_at', 'schedule_id', 'schedule_generation', 'schedule_version', 'actor_admin_id',
            'preset_id', 'preset_revision', 'configuration_snapshot', 'configuration_sha256', 'run_now'];
        $keys = array_keys($receipt);
        sort($keys);
        sort($expectedKeys);
        if ($keys !== $expectedKeys || ($receipt['trigger'] ?? null) !== 'scheduled' || ($receipt['run_now'] ?? null) !== false
            || (int) ($receipt['schedule_id'] ?? 0) !== (int) $occurrence->schedule_id
            || (int) ($receipt['schedule_generation'] ?? 0) !== (int) $occurrence->generation
            || (int) ($receipt['schedule_version'] ?? 0) !== (int) $occurrence->schedule_version
            || (int) ($receipt['actor_admin_id'] ?? 0) !== (int) $occurrence->actor_admin_id
            || $this->nullableInt($receipt['preset_id'] ?? null) !== $this->nullableInt($occurrence->preset_id)
            || $this->nullableInt($receipt['preset_revision'] ?? null) !== $this->nullableInt($occurrence->preset_revision)
            || ! BootstrapRequestContext::same($receipt['configuration_snapshot'] ?? null, $occurrence->configuration_snapshot)
            || ! is_string($receipt['configuration_sha256'] ?? null)
            || ! $this->boundedOccurrenceConfiguration($occurrence->configuration_snapshot)
            || ! $this->occurrenceIdentityMatches($occurrence)) return false;

        $configurationHash = hash('sha256', json_encode($occurrence->configuration_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! hash_equals($configurationHash, $receipt['configuration_sha256'])) return false;
        if ((int) $run->created_by_user_id !== (int) $occurrence->actor_admin_id
            || $run->trigger !== (($occurrence->configuration_snapshot['force'] ?? false) ? 'forced_rerun' : 'scheduled')
            || $run->idempotency_key !== hash('sha256', $occurrence->actor_admin_id.':'.$occurrence->idempotency_key)) return false;

        $schedule = DB::table('stox_bootstrap_schedules')->where('id', $occurrence->schedule_id)->first();
        if (! $schedule || (int) $schedule->created_by_user_id !== (int) $occurrence->actor_admin_id) return false;
        return true;
    }

    private function boundedOccurrenceConfiguration(mixed $snapshot): bool
    {
        return is_array($snapshot)
            && array_diff(array_keys($snapshot), ['scope', 'stock_ids', 'force', 'reason']) === []
            && strlen(json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '') <= 65536;
    }

    private function occurrenceIdentityMatches(BootstrapScheduleOccurrence $occurrence): bool
    {
        $dueTimestamp = $this->utcTimestamp((string) $occurrence->getRawOriginal('scheduled_at_utc'));
        if ($dueTimestamp === null) return false;
        $identity = implode(':', [(int) $occurrence->schedule_id, (int) $occurrence->generation,
            CarbonImmutable::createFromTimestampUTC($dueTimestamp)->format('Ymd\\THis.u\\Z')]);
        $expectedKey = hash('sha256', 'bootstrap-schedule:'.$identity);
        return is_string($occurrence->idempotency_key) && hash_equals($expectedKey, $occurrence->idempotency_key);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function utcTimestamp(string $raw): ?int
    {
        if ($raw === '') return null;
        try {
            return CarbonImmutable::parse($raw, 'UTC')->utc()->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Three persisted warnings in one managed run/category form one implementation-default window. */
    public function notifyRepeatedQualityWarningOccurrences(FundamentalBootstrapRun $candidateRun, string $category): bool
    {
        $run = $this->managedRun($candidateRun->id);
        $allowed = [BootstrapWarningCategory::REJECTED_FACTS, BootstrapWarningCategory::ANOMALY_REVIEW, BootstrapWarningCategory::MISSING_PROVENANCE];
        if (! $run || ! in_array($run->status, ['completed', 'failed'], true) || ! in_array($category, $allowed, true)) return false;
        $count = BootstrapQualityWarning::query()->where('run_id', $run->id)->where('category', $category)->count();
        if ($count < self::REPEATED_FAILURE_THRESHOLD) return false;

        // The run/category key is a stable receipt for this evidence window:
        // adding a fourth/fifth row cannot create another event. A later run is
        // a distinct durable window and can produce its own event.
        return $this->publish('run:'.$run->id.':quality:'.$category, 'repeated_quality_failures', $run->id, null,
            'Repeated fundamentals quality warnings', 'Repeated fundamentals quality warnings were recorded for this run. Review normalized Admin diagnostics.', ['run_id' => $run->id, 'category' => $category, 'warning_count' => $count]);
    }

    public function isManagedRun(FundamentalBootstrapRun $run): bool
    {
        $snapshot = $run->request_snapshot ?? [];
        return (int) ($snapshot['managed_version'] ?? 0) === FundamentalBootstrapService::OPS_MANAGED_VERSION
            && ($snapshot['origin'] ?? null) === 'admin_fundamentals_bootstrap_operations';
    }

    private function managedRun(int $runId): ?FundamentalBootstrapRun
    {
        if ($runId < 1) return null;
        return FundamentalBootstrapRun::query()->opsManaged()->whereKey($runId)->first();
    }

    private function publish(string $eventKey, string $type, ?int $runId, ?int $occurrenceId, string $title, string $message, array $context): bool
    {
        return DB::transaction(function () use ($eventKey, $type, $runId, $occurrenceId, $title, $message, $context): bool {
            $inserted = DB::table('stox_bootstrap_outcome_receipts')->insertOrIgnore([
                'event_key' => $eventKey, 'event_type' => $type, 'run_id' => $runId,
                'occurrence_id' => $occurrenceId, 'accepted_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted !== 1) return false;

            $admins = User::query()->where('is_admin', true)->orderBy('id')->get();
            if ($admins->isEmpty()) throw new \RuntimeException('No Admin recipients are available for the canonical outcome event.');
            $this->notifications->publish($admins, new NotificationEvent(
                type: 'ops001.'.$type,
                audience: 'admin',
                severity: 'action_required',
                title: $title,
                message: $message,
                context: $context,
                primaryAction: ['label' => 'Open bootstrap operations', 'route' => '/settings/fundamentals#bootstrap-operations'],
                externalInfoDelivery: false,
            ));
            BootstrapOutcomeReceipt::query()->where('event_key', $eventKey)->update(['accepted_at' => now(), 'updated_at' => now()]);
            return true;
        }, 3);
    }
}
