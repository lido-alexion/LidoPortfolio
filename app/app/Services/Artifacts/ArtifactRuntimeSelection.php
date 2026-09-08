<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBindingRevision;
use App\Models\ReusableArtifactVersion;
use App\Models\TradingStrategyVersion;

final readonly class ArtifactRuntimeSelection
{
    /** @param array<string, mixed> $definition */
    public function __construct(
        public TradingStrategyVersion $legacyStrategyVersion,
        public ReusableArtifactVersion $artifactVersion,
        public ArtifactBindingRevision $bindingRevision,
        public array $definition,
    ) {}
}
