<?php

namespace App\Services\Analytics;

use App\Engines\Market\MarketAnalysisEngine;
use App\Models\PortfolioProfile;

/**
 * SD-031 / SD-032 — Market Analytics façade.
 * Delegates all calculation to MarketAnalysisEngine (single source of truth).
 */
class MarketAnalyticsService
{
    public function __construct(
        protected MarketAnalysisEngine $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?PortfolioProfile $profile = null, bool $useCache = true): array
    {
        return $this->engine->latest(forceRefresh: ! $useCache);
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(bool $forceRefresh = false): array
    {
        return $this->engine->latest($forceRefresh);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $days = 90): array
    {
        return $this->engine->history($days);
    }

    /**
     * @return array<string, mixed>
     */
    public function explainability(): array
    {
        return $this->engine->explainability();
    }

    public function sentiment(): array
    {
        $latest = $this->latest();

        return $latest['sentiment'] ?? ['score' => null, 'label' => null];
    }

    public function phase(): array
    {
        $latest = $this->latest();

        return [
            'market_phase' => $latest['market_phase'] ?? null,
            'rule' => $latest['explainability']['phase_rule'] ?? null,
            'as_of_date' => $latest['as_of_date'] ?? null,
        ];
    }
}
