<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\ReusableArtifactVersion;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorStatus;

final class ArtifactUsabilityEvaluator
{
    public function __construct(private IndicatorRegistry $indicators) {}

    /** @return array{0:string,1:list<string>} */
    public function evaluate(ReusableArtifactVersion $version): array
    {
        $blocked = [];
        $warnings = [];
        $this->inspect($version, [], $blocked, $warnings);
        $reasons = array_values(array_unique([...$blocked, ...$warnings]));

        return match (true) {
            $blocked !== [] => [ArtifactBinding::BLOCKED, $reasons],
            $warnings !== [] => [ArtifactBinding::WARNING, $reasons],
            default => [ArtifactBinding::USABLE, []],
        };
    }

    /**
     * @param  list<int>  $visited
     * @param  list<string>  $blocked
     * @param  list<string>  $warnings
     */
    private function inspect(ReusableArtifactVersion $version, array $visited, array &$blocked, array &$warnings): void
    {
        if (in_array($version->id, $visited, true)) {
            $blocked[] = 'artifact_dependency_cycle:'.$version->id;

            return;
        }
        if ($version->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
            $blocked[] = 'artifact_version_not_published:'.$version->id;

            return;
        }

        $version->loadMissing('dependencies.targetVersion');
        foreach ($version->dependencies as $dependency) {
            if ($dependency->target_artifact_version_id !== null) {
                $target = $dependency->targetVersion;
                if (! $target || $target->status !== ReusableArtifactVersion::STATUS_PUBLISHED) {
                    $blocked[] = 'artifact_dependency_unavailable:'.$dependency->target_artifact_version_id;
                } else {
                    $this->inspect($target, [...$visited, $version->id], $blocked, $warnings);
                }
            }
            if ($dependency->indicator_id !== null) {
                $id = $dependency->indicator_id;
                $indicatorVersion = (string) $dependency->indicator_version;
                $indicator = $this->indicators->findVersion($id, $indicatorVersion);
                if ($indicator === null || $indicator->status === IndicatorStatus::PLANNED) {
                    $blocked[] = 'indicator_dependency_unavailable:'.$id.'@'.$indicatorVersion;
                } elseif (in_array($indicator->status, [
                    IndicatorStatus::STUB,
                    IndicatorStatus::DEPRECATED,
                    IndicatorStatus::RETIRED,
                ], true)) {
                    $warnings[] = 'indicator_dependency_'.$indicator->status.':'.$id.'@'.$indicatorVersion;
                }
            }
        }
    }
}
