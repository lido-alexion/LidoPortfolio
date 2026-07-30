<?php

namespace App\Services\Indicators;

/**
 * CI / unit helper: Registry dependency graph must be acyclic and complete (SD-033 Epic 2).
 */
final class IndicatorRegistryValidator
{
    /**
     * @return list<string>
     */
    public function validate(IndicatorRegistry $registry): array
    {
        return $registry->validateDependencies();
    }

    public function assertValid(IndicatorRegistry $registry): void
    {
        $issues = $this->validate($registry);
        if ($issues !== []) {
            throw new \RuntimeException('Indicator Registry dependency validation failed: '.implode('; ', $issues));
        }
    }
}
