<?php

namespace App\Services\ML;

use App\Models\User;
use App\Models\V7\MlLifecycleSchedule;
use App\Models\V7\MlTrainingRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * FEAT-056 foundation: schedule tick, per-horizon lock, queued retrain dispatch.
 */
class MlLifecycleAutomationService
{
    /** @var array<string, list<string>> */
    public const SCHEDULE_OPTIONS = [
        '1m' => ['monthly_first_sunday_00:00', 'monthly_first_sunday_01:00', 'monthly_first_sunday_02:00', 'monthly_first_sunday_03:00', 'monthly_first_sunday_04:00', 'monthly_first_sunday_05:00'],
        '3m' => ['monthly_first_sunday_00:00', 'monthly_first_sunday_01:00', 'monthly_first_sunday_02:00', 'monthly_first_sunday_03:00', 'monthly_first_sunday_04:00', 'monthly_first_sunday_05:00'],
        '6m' => ['monthly_first_sunday_00:00', 'monthly_first_sunday_01:00', 'monthly_first_sunday_02:00', 'monthly_first_sunday_03:00', 'monthly_first_sunday_04:00', 'monthly_first_sunday_05:00'],
    ];

    /** @var array<string, string> */
    public const DEFAULT_SCHEDULES = [
        '1m' => 'monthly_first_sunday_02:00',
        '3m' => 'monthly_first_sunday_03:00',
        '6m' => 'monthly_first_sunday_04:00',
    ];

    public function __construct(
        protected MlDriftTriggerEvaluator $driftTriggers,
        protected MlArtifactRetentionService $retention,
        protected MlTrainingRunRecoveryService $recovery,
    ) {}

    public function activeRunForHorizon(string $horizon): ?MlTrainingRun
    {
        $this->assertHorizon($horizon);

        return MlTrainingRun::query()
            ->where('horizon', $horizon)
            ->whereIn('status', ['queued', 'running', 'cancelling'])
            ->latest('id')
            ->first();
    }

    public function scheduleDue(string $horizon, ?Carbon $now = null): bool
    {
        $this->assertHorizon($horizon);
        $settings = $this->scheduleSettings($horizon);
        if (! $settings['enabled']) {
            return false;
        }

        $now ??= now()->timezone(config('ml_lifecycle.timezone', 'Asia/Kolkata'));
        if (! preg_match('/^monthly_first_sunday_(\d{2}):00$/', $settings['schedule'], $matches)) {
            return false;
        }

        return $this->isMonthlyFirstSundaySlot($now, (int) $matches[1], 0);
    }

    /** @return array{horizon:string,enabled:bool,schedule:string,options:list<string>} */
    public function scheduleSettings(string $horizon): array
    {
        $this->assertHorizon($horizon);
        $config = config('ml_lifecycle.horizons.'.$horizon, []);
        $stored = MlLifecycleSchedule::query()->find($horizon);
        $schedule = (string) ($stored?->schedule ?? $config['schedule'] ?? self::DEFAULT_SCHEDULES[$horizon]);
        if (! in_array($schedule, self::SCHEDULE_OPTIONS[$horizon], true)) {
            $schedule = self::DEFAULT_SCHEDULES[$horizon];
        }

        return [
            'horizon' => $horizon,
            'enabled' => (bool) ($stored?->enabled ?? $config['enabled'] ?? false),
            'schedule' => $schedule,
            'options' => self::SCHEDULE_OPTIONS[$horizon],
        ];
    }

    public function updateSchedule(string $horizon, bool $enabled, string $schedule, ?User $actor = null): array
    {
        $this->assertHorizon($horizon);
        if (! in_array($schedule, self::SCHEDULE_OPTIONS[$horizon], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'schedule' => ['The selected schedule is not available for this horizon.'],
            ]);
        }

        MlLifecycleSchedule::query()->updateOrCreate(
            ['horizon' => $horizon],
            ['enabled' => $enabled, 'schedule' => $schedule, 'updated_by' => $actor?->id],
        );

        return $this->scheduleSettings($horizon);
    }

    /**
     * @return list<array{horizon:string,action:string,reason?:string}>
     */
    public function tick(?User $requestedBy = null, ?Carbon $now = null): array
    {
        if (! config('ml_lifecycle.enabled', false)) {
            return [];
        }

        $out = [];
        foreach ($this->recovery->recover($now) as $action) {
            $out[] = $action + ['reason' => 'worker_or_process_restart'];
        }
        foreach (MlScoringService::HORIZONS as $horizon) {
            if ($this->activeRunForHorizon($horizon)) {
                $out[] = ['horizon' => $horizon, 'action' => 'skipped', 'reason' => 'active_run'];

                continue;
            }
            if (! $this->scheduleDue($horizon, $now)) {
                continue;
            }

            app(MlScoringService::class)->queueRetrainRun($horizon, $requestedBy, 'scheduled');
            $out[] = ['horizon' => $horizon, 'action' => 'queued', 'trigger' => 'scheduled'];
            Log::info('ml_lifecycle.scheduled_retrain_queued', ['horizon' => $horizon]);
        }

        foreach (MlScoringService::HORIZONS as $horizon) {
            if ($this->activeRunForHorizon($horizon)) {
                continue;
            }
            $drift = $this->driftTriggers->evaluateHorizon($horizon, $now);
            if ($drift === null) {
                continue;
            }
            app(MlScoringService::class)->queueRetrainRun($horizon, $requestedBy, 'drift', (int) $drift['drift_check_id']);
            $out[] = [
                'horizon' => $horizon,
                'action' => 'queued',
                'trigger' => 'drift',
                'drift_check_id' => $drift['drift_check_id'],
                'warnings' => $drift['warnings'],
            ];
            Log::info('ml_lifecycle.drift_retrain_queued', [
                'horizon' => $horizon,
                'drift_check_id' => $drift['drift_check_id'],
            ]);
        }

        if ($this->retention->isEnabled()) {
            $pruned = $this->retention->pruneAll(dryRun: false);
            if ($pruned !== []) {
                $out[] = ['horizon' => '*', 'action' => 'retention_pruned', 'count' => count($pruned)];
            }
        }

        return $out;
    }

    /**
     * Operator-facing lifecycle gates (FEAT-056) for ML admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function adminStatus(?Carbon $now = null): array
    {
        $now ??= now()->timezone(config('ml_lifecycle.timezone', 'Asia/Kolkata'));
        $horizons = [];
        foreach (MlScoringService::HORIZONS as $horizon) {
            $settings = $this->scheduleSettings($horizon);
            $active = $this->activeRunForHorizon($horizon);
            $horizons[] = [
                'horizon' => $horizon,
                'schedule' => $settings['schedule'],
                'schedule_enabled' => $settings['enabled'],
                'schedule_options' => $settings['options'],
                'schedule_due_now' => $this->scheduleDue($horizon, $now),
                'active_run' => $active ? [
                    'id' => $active->id,
                    'status' => $active->status,
                    'trigger' => is_array($active->configuration) ? ($active->configuration['trigger'] ?? null) : null,
                ] : null,
            ];
        }

        return [
            'enabled' => (bool) config('ml_lifecycle.enabled', false),
            'timezone' => (string) config('ml_lifecycle.timezone', 'Asia/Kolkata'),
            'as_of' => $now->toIso8601String(),
            'horizons' => $horizons,
            'drift_trigger' => [
                'enabled' => (bool) config('ml_lifecycle.drift_trigger.enabled', false),
                'cooldown_hours' => (int) config('ml_lifecycle.drift_trigger.cooldown_hours', 168),
            ],
            'notifications' => [
                'enabled' => (bool) config('ml_lifecycle.notifications.enabled', true),
            ],
            'retention' => [
                'enabled' => $this->retention->isEnabled(),
                'max_retained_per_horizon' => max(1, (int) config('ml_lifecycle.retention.max_retained_per_horizon', 3)),
            ],
        ];
    }

    protected function isMonthlyFirstSundaySlot(Carbon $now, int $hour, int $minute): bool
    {
        if ((int) $now->dayOfWeek !== Carbon::SUNDAY) {
            return false;
        }
        if ($now->day > 7) {
            return false;
        }

        return (int) $now->hour === $hour && (int) $now->minute === $minute;
    }

    protected function assertHorizon(string $horizon): void
    {
        if (! in_array($horizon, MlScoringService::HORIZONS, true)) {
            throw new \InvalidArgumentException('Unsupported ML horizon: '.$horizon);
        }
    }
}
