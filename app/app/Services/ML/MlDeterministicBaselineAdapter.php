<?php

namespace App\Services\ML;


/**
 * Historical adapter for the deterministic StoX evaluation baseline.
 *
 * This deliberately uses the existing as-of backtest scorer instead of the
 * ML feature fields or a current/live Strategy result.  The returned scores
 * are aligned with the same rows and test dates sent to the ML adapter.
 */
final class MlDeterministicBaselineAdapter
{
    public function __construct(private readonly HistoricalStrategyScoreService $strategyScores) {}

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return $this->strategyScores->definition();
    }

    /** @return list<array{probability:float,score:float,positive_decision:bool,decision_threshold:float,reference_date:string,stock_id:int}> */
    public function evaluate(array $rows): array
    {
        $definition = $this->definition();
        $out = [];
        $testRowsByStock = collect($rows)->filter(fn (array $row): bool => ($row['partition'] ?? null) === 'test')->groupBy('stock_id');
        foreach ($testRowsByStock as $stockId => $stockRows) {
            $out = [...$out, ...$this->evaluateStockRows($stockRows->all(), (int) $stockId, $definition)];
        }

        return $out;
    }

    public function evaluateFile(string $testRowsPath, string $outputPath): int
    {
        $definition = $this->definition();
        $input = fopen($testRowsPath, 'rb');
        $output = fopen($outputPath, 'wb');
        $currentStockId = null;
        $stockRows = [];
        $count = 0;
        try {
            while (($line = fgets($input)) !== false) {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $stockId = (int) ($row['stock_id'] ?? 0);
                if ($currentStockId !== null && $stockId !== $currentStockId) {
                    foreach ($this->evaluateStockRows($stockRows, $currentStockId, $definition) as $baseline) {
                        fwrite($output, json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                        $count++;
                    }
                    $stockRows = [];
                }
                $currentStockId = $stockId;
                $stockRows[] = $row;
            }
            if ($currentStockId !== null) {
                foreach ($this->evaluateStockRows($stockRows, $currentStockId, $definition) as $baseline) {
                    fwrite($output, json_encode($baseline, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
                    $count++;
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        return $count;
    }

    /** @return list<array<string,mixed>> */
    private function evaluateStockRows(array $stockRows, int $stockId, array $definition): array
    {
        $maxDate = collect($stockRows)->max('reference_date');
        $bars = $this->strategyScores->barsForStock($stockId, (string) $maxDate);
        $out = [];
        foreach ($stockRows as $row) {
            $result = $this->strategyScores->score($stockId, (string) $row['reference_date'], $definition, $bars);
            if ($result['factor_scores'] === []) {
                throw new \RuntimeException('Deterministic baseline could not score a test row: insufficient historical bars.');
            }
            $score = max(0.0, min(100.0, (float) $result['score']));
            $out[] = [
                'probability' => $score / 100,
                'score' => $score,
                'positive_decision' => (bool) $result['positive_decision'],
                'decision_threshold' => (float) $result['decision_threshold'],
                'reference_date' => (string) $row['reference_date'],
                'stock_id' => $stockId,
            ];
        }
        return $out;
    }
}
