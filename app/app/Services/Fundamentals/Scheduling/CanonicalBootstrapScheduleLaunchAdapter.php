<?php

namespace App\Services\Fundamentals\Scheduling;

use App\Models\V7\BootstrapSchedule;
use App\Models\V7\BootstrapScheduleOccurrence;
use App\Services\Fundamentals\BootstrapLaunchResult;
use App\Services\Fundamentals\BootstrapOperationsService;
use App\Services\Fundamentals\BootstrapRequestContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Serializes durable occurrence state and canonical run creation using A's control-first lock order. */
final class CanonicalBootstrapScheduleLaunchAdapter implements BootstrapScheduleLaunchAdapter
{
    public function __construct(private BootstrapOperationsService $operations) {}

    public function launch(array $configuration, int $actorAdminId, string $actorName, string $idempotencyKey, array $receipt): BootstrapLaunchResult
    {
        BootstrapRequestContext::validateSchedule($configuration, $actorAdminId, $receipt);
        return DB::transaction(function () use ($configuration, $actorAdminId, $actorName, $idempotencyKey, $receipt): BootstrapLaunchResult {
            DB::table('stox_fund_bootstrap_control')->where('id', 1)->lockForUpdate()->first();
            if ($receipt['trigger'] === 'scheduled') {
                $occurrence = BootstrapScheduleOccurrence::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                $valid = $occurrence && in_array($occurrence->status, ['pending', 'queued'], true)
                    && (int) $occurrence->actor_admin_id === $actorAdminId
                    && (int) $occurrence->schedule_id === $receipt['schedule_id']
                    && (int) $occurrence->generation === $receipt['schedule_generation']
                    && (int) $occurrence->schedule_version === $receipt['schedule_version']
                    && ($occurrence->preset_id === null ? null : (int) $occurrence->preset_id) === $receipt['preset_id']
                    && ($occurrence->preset_revision === null ? null : (int) $occurrence->preset_revision) === $receipt['preset_revision']
                    && BootstrapRequestContext::same($occurrence->configuration_snapshot, $configuration)
                    && CarbonImmutable::parse($occurrence->getRawOriginal('scheduled_at_utc'), 'UTC')->format('Y-m-d\TH:i:s\Z') === $receipt['scheduled_at'];
            } else {
                $schedule = BootstrapSchedule::query()->whereKey($receipt['schedule_id'])->lockForUpdate()->first();
                $valid = $schedule && (int) $schedule->version === $receipt['schedule_version']
                    && (int) $schedule->generation === $receipt['schedule_generation']
                    && ($schedule->preset_id === null ? null : (int) $schedule->preset_id) === $receipt['preset_id']
                    && ($schedule->preset_revision === null ? null : (int) $schedule->preset_revision) === $receipt['preset_revision']
                    && BootstrapRequestContext::same($schedule->configuration_snapshot, $configuration);
            }
            if (! $valid) {
                throw ValidationException::withMessages(['schedule_occurrence' => 'The current durable schedule context does not match this launch. Refresh and review the schedule.']);
            }
            return $this->operations->launchWithScheduleReceipt($configuration, $actorAdminId, $actorName, $idempotencyKey, $receipt);
        });
    }
}
