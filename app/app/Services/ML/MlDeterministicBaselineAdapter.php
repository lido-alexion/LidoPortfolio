<?php

namespace App\Services\ML;

use App\Services\Backtest\AsOfFactorScorer;

/**
 * Historical adapter for the deterministic StoX evaluation baseline.
 *
 * This deliberately uses the existing as-of backtest scorer instead of the
 * ML feature fields or a current/live Strategy result.  The returned scores
 * are aligned with the same rows and test dates sent to the ML adapter.
 */
final class MlDeterministicBaselineAdapter
{
    public function __construct(private readonly AsOfFactorScorer $scorer) {}

    /** @return list<array{probability:float,score:float,reference_date:string,stock_id:int}> */
    public function evaluate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (($row['partition'] ?? null) !== 'test') {
                continue;
            }
            $result = $this->scorer->score((int) $row['stock_id'], (string) $row['reference_date']);
            if ($result['skipped']) {
                throw new \RuntimeException('Deterministic baseline could not score a test row: insufficient historical bars.');
            }
            $score = max(0.0, min(100.0, (float) $result['score']));
            $out[] = [
                'probability' => $score / 100,
                'score' => $score,
                'reference_date' => (string) $row['reference_date'],
                'stock_id' => (int) $row['stock_id'],
            ];
        }

        return $out;
    }
}
