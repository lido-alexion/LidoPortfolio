<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Support\Facades\DB;

final class ArtifactBindingUsabilityService
{
    public function __construct(
        private ArtifactUsabilityEvaluator $evaluator,
        private NotificationPublisher $notifications,
    ) {}

    public function refresh(ArtifactBinding $binding): ArtifactBinding
    {
        return DB::transaction(function () use ($binding) {
            $locked = ArtifactBinding::query()
                ->with('activeRevision.artifactVersion.dependencies.targetVersion', 'profile.user', 'artifact')
                ->lockForUpdate()
                ->findOrFail($binding->id);
            $version = $locked->activeRevision?->artifactVersion;
            [$state, $reasons] = $version
                ? $this->evaluator->evaluate($version)
                : [ArtifactBinding::BLOCKED, ['binding_active_revision_missing']];

            if ($locked->usability_state !== $state || $locked->usability_reasons_json !== $reasons) {
                $locked->forceFill([
                    'usability_state' => $state,
                    'usability_reasons_json' => $reasons,
                ])->save();
            }

            $conditionKey = 'artifact-binding:blocked:'.$locked->binding_uuid;
            if ($locked->status === ArtifactBinding::STATUS_ENABLED && $state === ArtifactBinding::BLOCKED) {
                $this->notifications->publishCondition($conditionKey, [$locked->profile->user], [
                    'notification_type' => 'artifact.binding_blocked',
                    'audience' => 'investor',
                    'severity' => 'action_required',
                    'title' => 'Trading artifact needs attention',
                    'message' => 'An enabled trading artifact is blocked because a pinned dependency is unavailable.',
                    'context' => [
                        'profile_id' => $locked->profile_id,
                        'binding_uuid' => $locked->binding_uuid,
                        'artifact_uuid' => $locked->artifact->artifact_uuid,
                        'artifact_version_id' => $version?->id,
                        'reasons' => $reasons,
                    ],
                    'primary_action' => ['label' => 'Review Portfolio', 'route' => '/dashboard'],
                ]);
            } else {
                $this->notifications->resolveCondition($conditionKey);
            }

            return $locked->fresh(['activeRevision.artifactVersion', 'profile', 'artifact']);
        });
    }

    /** @return array{checked:int,usable:int,warning:int,blocked:int} */
    public function refreshAll(): array
    {
        $counts = ['checked' => 0, 'usable' => 0, 'warning' => 0, 'blocked' => 0];
        ArtifactBinding::query()->whereNotNull('active_revision_id')->orderBy('id')->eachById(function (ArtifactBinding $binding) use (&$counts): void {
            $fresh = $this->refresh($binding);
            $counts['checked']++;
            $counts[$fresh->usability_state]++;
        });

        return $counts;
    }
}
