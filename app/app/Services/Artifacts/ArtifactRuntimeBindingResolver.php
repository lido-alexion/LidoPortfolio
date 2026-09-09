<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBinding;
use App\Models\PortfolioProfile;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;

final class ArtifactRuntimeBindingResolver
{
    public function forScreener(Screener $screener): ?ScreenerRuntimeSelection
    {
        if ($screener->reusable_artifact_id === null) {
            return null;
        }

        $binding = ArtifactBinding::query()
            ->where('profile_id', $screener->profile_id)
            ->where('artifact_id', $screener->reusable_artifact_id)
            ->where('status', ArtifactBinding::STATUS_ENABLED)
            ->whereIn('usability_state', [ArtifactBinding::USABLE, ArtifactBinding::WARNING])
            ->with('activeRevision.artifactVersion.artifact')
            ->first();
        $revision = $binding?->activeRevision;
        $version = $revision?->artifactVersion;
        $definition = $version?->content_json['definition'] ?? null;
        if (! $revision
            || ! $version
            || $version->status !== ReusableArtifactVersion::STATUS_PUBLISHED
            || $version->artifact->artifact_type !== ArtifactType::SCREENER
            || ! is_array($definition)
            || ! is_array($definition['root'] ?? null)) {
            return null;
        }

        return new ScreenerRuntimeSelection($screener, $version, $revision, $definition);
    }

    public function forStrategyVersion(
        PortfolioProfile $profile,
        TradingStrategyVersion $legacyVersion,
    ): ?ArtifactRuntimeSelection {
        $strategy = TradingStrategy::query()->find($legacyVersion->strategy_id);
        if (! $strategy
            || (int) $strategy->profile_id !== (int) $profile->id
            || $strategy->reusable_artifact_id === null) {
            return null;
        }

        $binding = ArtifactBinding::query()
            ->where('profile_id', $profile->id)
            ->where('artifact_id', $strategy->reusable_artifact_id)
            ->where('status', ArtifactBinding::STATUS_ENABLED)
            ->whereIn('usability_state', [ArtifactBinding::USABLE, ArtifactBinding::WARNING])
            ->with('activeRevision.artifactVersion.artifact')
            ->first();
        $revision = $binding?->activeRevision;
        $version = $revision?->artifactVersion;
        $definition = $version?->content_json['definition'] ?? null;
        if (! $revision
            || ! $version
            || $version->status !== ReusableArtifactVersion::STATUS_PUBLISHED
            || $version->artifact->artifact_type !== ArtifactType::STRATEGY
            || $legacyVersion->definition_hash !== $version->definition_hash
            || ! is_array($definition)) {
            return null;
        }

        return new ArtifactRuntimeSelection($legacyVersion, $version, $revision, $definition);
    }
}
