<?php

namespace App\Services\Artifacts;

use App\Models\ArtifactBindingRevision;
use App\Models\ReusableArtifactVersion;
use App\Models\Screener;

final readonly class ScreenerRuntimeSelection
{
    /** @param array<string, mixed> $definition */
    public function __construct(
        public Screener $legacyScreener,
        public ReusableArtifactVersion $artifactVersion,
        public ArtifactBindingRevision $bindingRevision,
        public array $definition,
    ) {}
}
