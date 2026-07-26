<?php

namespace App\Services\PatternDetection;

/**
 * Contract for a (grouped) pattern detector registered with
 * PatternDetectionService. A single implementation may cover several
 * related pattern ids (e.g. all single-bar candlestick shapes) as long as
 * each id's detection formula is preserved exactly.
 */
interface PatternDetectorInterface
{
    /**
     * Pattern ids this detector is able to evaluate.
     *
     * @return list<string>
     */
    public function ids(): array;

    /**
     * @param  list<array{date: string, open: float, high: float, low: float, close: float}>  $bars
     */
    public function detect(array $bars, int $endIdx, string $id): bool;
}
