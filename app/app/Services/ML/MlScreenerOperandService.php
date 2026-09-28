<?php

namespace App\Services\ML;

use App\Models\Stock;
use App\Models\V7\MlPrediction;
use Carbon\Carbon;

/**
 * FEAT-057 — optional ML prediction scores as screener operands (filter-only).
 *
 * Queries predictions directly to avoid a DI cycle:
 * ScreenerEvaluationService → this service → MlScoringService → … → ScreenerEvaluationService.
 */
class MlScreenerOperandService
{
    /** @var array<string, string> indicator_id => horizon */
    public const OPERANDS = [
        'ml_success_score_1m' => '1m',
        'ml_success_score_3m' => '3m',
        'ml_success_score_6m' => '6m',
    ];

    public function supports(string $indicatorId): bool
    {
        return isset(self::OPERANDS[$indicatorId]);
    }

    public function valueForIndicator(Stock $stock, string $indicatorId, Carbon $asOf): ?float
    {
        $horizon = self::OPERANDS[$indicatorId] ?? null;
        if ($horizon === null) {
            return null;
        }

        $prediction = MlPrediction::query()
            ->where('stock_id', $stock->id)
            ->where('horizon', $horizon)
            ->where('shadow', false)
            ->where('as_of', '<=', $asOf)
            ->orderByDesc('as_of')
            ->first();

        if ($prediction === null || $prediction->score === null) {
            return null;
        }

        return (float) $prediction->score;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalogRows(): array
    {
        $labels = [
            'ml_success_score_1m' => 'ML success score (1m horizon)',
            'ml_success_score_3m' => 'ML success score (3m horizon)',
            'ml_success_score_6m' => 'ML success score (6m horizon)',
        ];

        $rows = [];
        foreach (self::OPERANDS as $id => $horizon) {
            $rows[] = [
                'id' => $id,
                'label' => $labels[$id] ?? $id,
                'params' => [],
                'min_bars' => 1,
                'needs_volume' => false,
                'category' => 'ml',
                'meta' => ['horizon' => $horizon, 'unit' => 'probability_0_1'],
            ];
        }

        return $rows;
    }
}
