<?php

namespace App\Services\ML;

use App\Models\User;
use App\Models\V7\MlDriftCheck;
use App\Models\V7\MlModelVersion;
use App\Models\V7\MlTrainingRun;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Support\Collection;

/**
 * FEAT-056 §18 — action-oriented ML lifecycle notifications (not routine stage noise).
 */
class MlLifecycleNotificationService
{
    public function __construct(
        protected NotificationPublisher $publisher,
    ) {}

    public function notifyTrainingFailed(MlTrainingRun $run): void
    {
        if (! $this->enabled()) {
            return;
        }

        $message = sprintf(
            '%s horizon training run #%d failed: %s',
            $run->horizon,
            $run->id,
            (string) ($run->failure['message'] ?? 'see Admin ML runs'),
        );

        $this->publishEvent(
            'ml.lifecycle.training_failed',
            'action_required',
            'ML training failed',
            $message,
            [
                'horizon' => $run->horizon,
                'run_id' => $run->id,
            ],
            ['label' => 'Open ML admin', 'route' => '/settings/ml-scoring'],
        );
    }

    public function notifyEligibleCandidate(MlModelVersion $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->publishCondition(
            'ml-lifecycle:eligible:'.$model->horizon,
            'action_required',
            'ML candidate ready for promotion',
            sprintf(
                '%s horizon model v%d passed promotion thresholds and awaits explicit Admin promotion.',
                $model->horizon,
                $model->version,
            ),
            [
                'horizon' => $model->horizon,
                'model_id' => $model->id,
                'version' => $model->version,
            ],
            ['label' => 'Review promotion', 'route' => '/settings/ml-scoring'],
        );
    }

    public function notifyDriftWarning(MlDriftCheck $check, MlModelVersion $model): void
    {
        if (! $this->enabled() || $check->status !== 'warning') {
            return;
        }

        $warnings = is_array($check->warnings) ? implode(', ', $check->warnings) : '';

        $this->publishCondition(
            'ml-lifecycle:drift:'.$model->horizon.':'.$model->id,
            'action_required',
            'ML drift warning on active model',
            sprintf(
                '%s horizon active model v%d drift check (%dm window): %s',
                $model->horizon,
                $model->version,
                $check->window_months,
                $warnings !== '' ? $warnings : 'review drift metrics',
            ),
            [
                'horizon' => $model->horizon,
                'model_id' => $model->id,
                'drift_check_id' => $check->id,
            ],
            ['label' => 'Open ML admin', 'route' => '/settings/ml-scoring'],
        );
    }

    public function notifyPromoted(MlModelVersion $model, ?User $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->resolveCondition('ml-lifecycle:eligible:'.$model->horizon);

        $this->publishEvent(
            'ml.lifecycle.promoted',
            'info',
            'ML model promoted',
            sprintf('%s horizon model v%d is now active.', $model->horizon, $model->version),
            [
                'horizon' => $model->horizon,
                'model_id' => $model->id,
                'promoted_by' => $actor?->id,
            ],
            ['label' => 'Open ML admin', 'route' => '/settings/ml-scoring'],
        );
    }

    public function notifyRollback(MlModelVersion $model, ?User $actor): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->publishEvent(
            'ml.lifecycle.rollback',
            'info',
            'ML model rolled back',
            sprintf('%s horizon active model is now v%d (manual rollback).', $model->horizon, $model->version),
            [
                'horizon' => $model->horizon,
                'model_id' => $model->id,
                'promoted_by' => $actor?->id,
            ],
            ['label' => 'Open ML admin', 'route' => '/settings/ml-scoring'],
        );
    }

    protected function enabled(): bool
    {
        return (bool) config('ml_lifecycle.notifications.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $primaryAction
     */
    protected function publishEvent(
        string $type,
        string $severity,
        string $title,
        string $message,
        array $context,
        array $primaryAction,
    ): void {
        $recipients = $this->adminRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        $this->publisher->publishEvent($recipients, [
            'notification_type' => $type,
            'audience' => 'admin',
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'primary_action' => $primaryAction,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, string>  $primaryAction
     */
    protected function publishCondition(
        string $conditionKey,
        string $severity,
        string $title,
        string $message,
        array $context,
        array $primaryAction,
    ): void {
        $recipients = $this->adminRecipients();
        if ($recipients->isEmpty()) {
            return;
        }

        $this->publisher->publishCondition($conditionKey, $recipients, [
            'notification_type' => 'ml.lifecycle.condition',
            'audience' => 'admin',
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'primary_action' => $primaryAction,
        ]);
    }

    protected function resolveCondition(string $conditionKey): void
    {
        $this->publisher->resolveCondition($conditionKey);
    }

    /** @return Collection<int, User> */
    protected function adminRecipients(): Collection
    {
        return User::query()->where('is_admin', true)->get();
    }
}
