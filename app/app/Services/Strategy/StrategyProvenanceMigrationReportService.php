<?php

namespace App\Services\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FEAT-064 WP-10 — migration gate counts before NOT NULL / FK enforcement on provenance columns.
 */
class StrategyProvenanceMigrationReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $entities = [];

        if (Schema::hasTable('portfolio_tos_recommendations') && Schema::hasColumn('portfolio_tos_recommendations', 'strategy_version_id')) {
            $entities[] = $this->summarize(
                key: 'trading_recommendations.strategy_version',
                table: 'portfolio_tos_recommendations',
                column: 'strategy_version_id',
                parentTable: 'portfolio_tos_strategy_versions',
            );
        }

        if (Schema::hasTable('portfolio_screener_runs') && Schema::hasColumn('portfolio_screener_runs', 'screener_version_id')) {
            $entities[] = $this->summarize(
                key: 'screener_runs.screener_version',
                table: 'portfolio_screener_runs',
                column: 'screener_version_id',
                parentTable: 'portfolio_screener_versions',
                mismatchJoin: [
                    'parent_key' => 'portfolio_screener_versions.id',
                    'child_fk' => 'portfolio_screener_runs.screener_version_id',
                    'match_column' => 'screener_id',
                    'child_column' => 'portfolio_screener_runs.screener_id',
                    'parent_column' => 'portfolio_screener_versions.screener_id',
                ],
            );
        }

        if (Schema::hasTable('portfolio_tos_strategy_screeners') && Schema::hasColumn('portfolio_tos_strategy_screeners', 'screener_version_id')) {
            $entities[] = $this->summarize(
                key: 'strategy_screeners.screener_version',
                table: 'portfolio_tos_strategy_screeners',
                column: 'screener_version_id',
                parentTable: 'portfolio_screener_versions',
                mismatchJoin: [
                    'parent_key' => 'portfolio_screener_versions.id',
                    'child_fk' => 'portfolio_tos_strategy_screeners.screener_version_id',
                    'match_column' => 'screener_id',
                    'child_column' => 'portfolio_tos_strategy_screeners.screener_id',
                    'parent_column' => 'portfolio_screener_versions.screener_id',
                ],
            );
        }

        if (Schema::hasTable('portfolio_screener_backtests') && Schema::hasColumn('portfolio_screener_backtests', 'screener_version_id')) {
            $entities[] = $this->summarize(
                key: 'screener_backtests.screener_version',
                table: 'portfolio_screener_backtests',
                column: 'screener_version_id',
                parentTable: 'portfolio_screener_versions',
                mismatchJoin: [
                    'parent_key' => 'portfolio_screener_versions.id',
                    'child_fk' => 'portfolio_screener_backtests.screener_version_id',
                    'match_column' => 'screener_id',
                    'child_column' => 'portfolio_screener_backtests.screener_id',
                    'parent_column' => 'portfolio_screener_versions.screener_id',
                ],
            );
        }

        if (Schema::hasTable('portfolio_backtest_runs') && Schema::hasColumn('portfolio_backtest_runs', 'strategy_version_id')) {
            $entities[] = $this->summarize(
                key: 'backtest_runs.strategy_version',
                table: 'portfolio_backtest_runs',
                column: 'strategy_version_id',
                parentTable: 'portfolio_tos_strategy_versions',
            );
        }

        $totals = [
            'unresolved' => (int) array_sum(array_column($entities, 'unresolved')),
            'orphaned' => (int) array_sum(array_column($entities, 'orphaned')),
            'mismatched' => (int) array_sum(array_column($entities, 'mismatched')),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'entities' => $entities,
            'gate' => [
                'not_null_enforcement_recommended' => $totals['unresolved'] === 0
                    && $totals['orphaned'] === 0
                    && $totals['mismatched'] === 0,
                'totals' => $totals,
                'notes' => 'Counts are informational; do not backfill provenance solely to clear the gate.',
            ],
        ];
    }

    /**
     * @param  array<string, string>|null  $mismatchJoin
     * @return array<string, mixed>
     */
    protected function summarize(
        string $key,
        string $table,
        string $column,
        string $parentTable,
        ?array $mismatchJoin = null,
    ): array {
        $total = (int) DB::table($table)->count();
        $unresolved = (int) DB::table($table)->whereNull($column)->count();

        $orphaned = (int) DB::table($table.' as child')
            ->whereNotNull('child.'.$column)
            ->whereNotExists(function ($query) use ($parentTable, $column): void {
                $query->selectRaw('1')
                    ->from($parentTable.' as parent')
                    ->whereColumn('parent.id', 'child.'.$column);
            })
            ->count();

        $mismatched = 0;
        if ($mismatchJoin !== null) {
            $mismatched = (int) DB::table($table.' as child')
                ->join($parentTable.' as parent', 'parent.id', '=', 'child.'.$column)
                ->whereNotNull('child.'.$column)
                ->whereColumn('child.'.$this->columnNameFromQualified($mismatchJoin['child_column']), '!=', 'parent.'.$this->columnNameFromQualified($mismatchJoin['parent_column']))
                ->count();
        }

        $resolved = max(0, $total - $unresolved - $orphaned - $mismatched);

        return [
            'key' => $key,
            'table' => $table,
            'column' => $column,
            'total' => $total,
            'resolved' => $resolved,
            'unresolved' => $unresolved,
            'orphaned' => $orphaned,
            'mismatched' => $mismatched,
        ];
    }

    protected function columnNameFromQualified(string $qualified): string
    {
        $parts = explode('.', $qualified);

        return (string) end($parts);
    }
}
