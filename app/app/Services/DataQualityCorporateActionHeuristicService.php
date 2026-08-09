<?php

namespace App\Services;

use App\Models\DataQualityIssue;
use App\Models\Stock;
use App\Models\StockPrice;

class DataQualityCorporateActionHeuristicService
{
    /** @var array<int,float> */
    protected array $commonRatios = [2.0, 3.0, 5.0, 10.0, 1.5];

    public function __construct(
        protected DataQualityIssueService $issues,
    ) {}

    /**
     * @return array{scanned:int, flagged:int, detection_run_id:string}
     */
    public function scanAllStocks(float $minGapPercent = 25.0, ?string $detectionRunId = null): array
    {
        $detectionRunId ??= DataQualityIssueService::newDetectionRunId('portfolio:detect-corporate-action-anomalies');

        $stocks = Stock::query()
            ->where('is_active', true)
            ->where('is_benchmark', false)
            ->get(['id', 'symbol']);

        $flagged = 0;
        foreach ($stocks as $stock) {
            if ($this->scanStock($stock, $minGapPercent, $detectionRunId)) {
                $flagged++;
            }
        }

        return [
            'scanned' => $stocks->count(),
            'flagged' => $flagged,
            'detection_run_id' => $detectionRunId,
        ];
    }

    public function scanStock(Stock $stock, float $minGapPercent = 25.0, ?string $detectionRunId = null): bool
    {
        $detectionRunId ??= DataQualityIssueService::newDetectionRunId('portfolio:detect-corporate-action-anomalies');

        $rows = StockPrice::query()
            ->where('stock_id', $stock->id)
            ->orderByDesc('price_date')
            ->limit(2)
            ->get(['price_date', 'open_price', 'close_price', 'volume']);

        if ($rows->count() < 2) {
            return false;
        }

        $current = $rows[0];
        $previous = $rows[1];

        $prevClose = (float) ($previous->close_price ?? 0);
        $currOpen = (float) ($current->open_price ?? 0);
        if ($prevClose <= 0 || $currOpen <= 0) {
            return false;
        }

        $gapRatio = $prevClose / $currOpen;
        $gapPercent = (($currOpen - $prevClose) / $prevClose) * 100;

        if (abs($gapPercent) < $minGapPercent) {
            return false;
        }

        $closestRatio = $this->closestCommonRatio($gapRatio);
        $ratioDistance = $closestRatio > 0 ? abs($gapRatio - $closestRatio) / $closestRatio : 1;
        $confidence = max(0.2, min(0.95, 1.0 - $ratioDistance));

        $previousVolume = (float) ($previous->volume ?? 0);
        $currentVolume = (float) ($current->volume ?? 0);
        $volumeChange = $previousVolume > 0
            ? (($currentVolume - $previousVolume) / $previousVolume) * 100
            : null;

        $this->issues->createOrRefreshPendingIssueForStock(
            $stock,
            DataQualityIssue::TYPE_CORPORATE_ACTION,
            DataQualityIssue::DETECTION_METHOD_HEURISTIC_GAP,
            [
                'detection_source' => 'heuristic',
                'corporate_action_type' => 'split',
                'suggested_ratio' => round($closestRatio, 6),
                'latest_suggested_ratio' => round($closestRatio, 6),
                'ex_date' => $current->price_date->toDateString(),
                'record_date' => null,
                'previous_close' => $prevClose,
                'current_open' => $currOpen,
                'gap_percent' => round($gapPercent, 4),
                'gap_ratio' => round($gapRatio, 6),
                'volume_change_percent' => $volumeChange !== null ? round($volumeChange, 4) : null,
                'detection_payload' => [
                    'reason' => sprintf(
                        'Large overnight gap %.2f%%, ratio %.3f close to split ratio %.2f',
                        $gapPercent,
                        $gapRatio,
                        $closestRatio,
                    ),
                    'common_ratios' => $this->commonRatios,
                ],
                'exchange_match' => false,
                'confidence' => round($confidence, 4),
                'detected_at' => now(),
            ],
            [
                [
                    'evidence_key' => 'gap_ratio',
                    'evidence_label' => 'Overnight gap ratio',
                    'evidence_value' => (string) round($gapRatio, 6),
                    'captured_at' => now(),
                ],
                [
                    'evidence_key' => 'volume_change',
                    'evidence_label' => 'Volume change %',
                    'evidence_value' => $volumeChange !== null ? (string) round($volumeChange, 4) : 'n/a',
                    'captured_at' => now(),
                ],
            ],
            $detectionRunId,
        );

        return true;
    }

    protected function closestCommonRatio(float $gapRatio): float
    {
        $best = 1.0;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($this->commonRatios as $ratio) {
            $distance = abs($gapRatio - $ratio);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $ratio;
            }
        }

        return $best;
    }
}
