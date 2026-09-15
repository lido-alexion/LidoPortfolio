<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveInvalidAdminPortfolioCommand extends Command
{
    protected $signature = 'portfolio:remove-invalid-admin-portfolio
        {profile_id : Invalid Admin-owned portfolio profile id}
        {--dry-run : Report what would be removed without changing data}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Remove an obsolete Admin-owned default Portfolio after refusing economically meaningful state';

    /** @var list<string> */
    private array $economicProfileTables = [
        'portfolio_transactions',
        'portfolio_holdings',
        'portfolio_cash_ledger_entries',
        'portfolio_tos_recommendations',
        'portfolio_tos_orders',
        'portfolio_tos_execution_decisions',
        'portfolio_tos_capital_requests',
        'portfolio_tos_loans',
        'portfolio_tos_capital_recalls',
        'portfolio_tos_recall_bridge_loans',
        'portfolio_tos_pending_sale_proceeds',
        'portfolio_tos_position_protections',
        'portfolio_reconciliation_runs',
        'portfolio_opening_tax_lots',
        'portfolio_paper_execution_events',
        'portfolio_paper_simulation_events',
    ];

    /** @var list<string> */
    private array $directAdminEconomicTables = [
        'portfolio_broker_connections',
        'portfolio_execution_batches',
    ];

    /** @var array<string, list<string>> */
    private array $dependentTables = [
        'portfolio_screener_versions' => ['screener_id'],
        'portfolio_screener_runs' => ['screener_id'],
        'portfolio_screener_backtests' => ['screener_id'],
        'portfolio_screener_backtest_days' => ['screener_id'],
        'portfolio_screener_backtest_hits' => ['screener_id'],
        'portfolio_tos_strategy_screeners' => ['screener_id', 'strategy_version_id'],
        'portfolio_tos_strategy_versions' => ['strategy_id'],
        'portfolio_tos_candidates' => ['discovery_run_id'],
        'portfolio_tos_evaluation_results' => ['evaluation_run_id'],
        'portfolio_tos_review_metrics' => ['report_id'],
        'portfolio_watchlist_items' => ['watchlist_id'],
        'portfolio_watchlist_pattern_scans' => ['watchlist_id'],
    ];

    public function handle(): int
    {
        $profileId = (int) $this->argument('profile_id');
        $dryRun = (bool) $this->option('dry-run');

        $profile = DB::table('portfolio_profiles')
            ->join('portfolio_users', 'portfolio_users.id', '=', 'portfolio_profiles.user_id')
            ->where('portfolio_profiles.id', $profileId)
            ->first([
                'portfolio_profiles.id',
                'portfolio_profiles.user_id',
                'portfolio_profiles.name',
                'portfolio_profiles.is_default',
                'portfolio_profiles.deleted_at',
                'portfolio_users.email',
                'portfolio_users.is_admin',
            ]);

        if (! $profile) {
            return $this->finish(['ok' => false, 'error' => 'Portfolio profile not found.'], self::FAILURE);
        }

        if (! (bool) $profile->is_admin) {
            return $this->finish(['ok' => false, 'error' => 'Refusing to remove a non-Admin-owned Portfolio.'], self::FAILURE);
        }

        $inventory = $this->inventory($profileId, (int) $profile->user_id);
        $economicFindings = $this->economicFindings($profileId, (int) $profile->user_id, $inventory);

        $result = [
            'ok' => $economicFindings === [],
            'dry_run' => $dryRun,
            'profile' => [
                'id' => (int) $profile->id,
                'user_id' => (int) $profile->user_id,
                'admin_email' => $profile->email,
                'name' => $profile->name,
                'is_default' => (bool) $profile->is_default,
                'deleted_at' => $profile->deleted_at,
            ],
            'classification' => $economicFindings === [] ? 'DISPOSABLE_LEGACY_DEFAULT' : 'ECONOMICALLY_MEANINGFUL_OR_AMBIGUOUS',
            'economic_findings' => $economicFindings,
            'inventory' => $inventory,
            'deleted' => [],
        ];

        if ($economicFindings !== []) {
            return $this->finish($result, self::FAILURE);
        }

        if (! $dryRun) {
            $result['deleted'] = DB::transaction(fn (): array => $this->deleteGraph($profileId));
            $result['inventory_after'] = $this->inventory($profileId, (int) $profile->user_id);
        }

        return $this->finish($result, self::SUCCESS);
    }

    /**
     * @return array<string, int>
     */
    private function inventory(int $profileId, int $userId): array
    {
        $counts = [];

        foreach ($this->ids($profileId) as $table => $ids) {
            $counts[$table.'_ids'] = count($ids);
        }

        foreach ($this->profileTables() as $table) {
            $count = DB::table($table)->where('profile_id', $profileId)->count();
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        foreach ($this->dependentCounts($profileId) as $table => $count) {
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        $counts['portfolio_profiles'] = DB::table('portfolio_profiles')->where('id', $profileId)->count();

        foreach ($this->directAdminEconomicTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                $count = DB::table($table)->where('user_id', $userId)->count();
                if ($count > 0) {
                    $counts[$table.'_for_admin_user'] = $count;
                }
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function economicFindings(int $profileId, int $userId, array $inventory): array
    {
        $findings = [];

        foreach ($this->economicProfileTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'profile_id')) {
                continue;
            }
            $count = DB::table($table)->where('profile_id', $profileId)->count();
            if ($count > 0) {
                $findings[] = "{$table}:{$count}";
            }
        }

        if (Schema::hasTable('portfolio_cash_accounts')) {
            $nonZero = DB::table('portfolio_cash_accounts')
                ->where('profile_id', $profileId)
                ->whereRaw('CAST(balance AS DECIMAL(20,4)) <> 0')
                ->count();
            if ($nonZero > 0) {
                $findings[] = "portfolio_cash_accounts_non_zero:{$nonZero}";
            }
        }

        foreach ($this->directAdminEconomicTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            $count = DB::table($table)->where('user_id', $userId)->count();
            if ($count > 0) {
                $findings[] = "{$table}_for_admin_user:{$count}";
            }
        }

        return $findings;
    }

    /**
     * @return array<string, int>
     */
    private function deleteGraph(int $profileId): array
    {
        $deleted = [];
        $ids = $this->ids($profileId);

        $this->deleteWhereIn($deleted, 'portfolio_tos_strategy_screeners', 'strategy_version_id', $ids['portfolio_tos_strategy_versions']);
        $this->deleteWhereIn($deleted, 'portfolio_tos_strategy_screeners', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_tos_strategy_versions', 'strategy_id', $ids['portfolio_tos_strategies']);
        $this->deleteWhereIn($deleted, 'portfolio_screener_versions', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_screener_runs', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_screener_backtest_hits', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_screener_backtest_days', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_screener_backtests', 'screener_id', $ids['portfolio_screeners']);
        $this->deleteWhereIn($deleted, 'portfolio_tos_candidates', 'discovery_run_id', $ids['portfolio_tos_discovery_runs']);
        $this->deleteWhereIn($deleted, 'portfolio_tos_evaluation_results', 'evaluation_run_id', $ids['portfolio_tos_evaluation_runs']);
        $this->deleteWhereIn($deleted, 'portfolio_tos_review_metrics', 'report_id', $ids['portfolio_tos_review_reports']);
        $this->deleteWhereIn($deleted, 'portfolio_watchlist_items', 'watchlist_id', $ids['portfolio_watchlists']);
        $this->deleteWhereIn($deleted, 'portfolio_watchlist_pattern_scans', 'watchlist_id', $ids['portfolio_watchlists']);

        foreach ($this->profileTables() as $table) {
            $this->deleteWhere($deleted, $table, 'profile_id', $profileId);
        }

        $this->deleteWhere($deleted, 'portfolio_profiles', 'id', $profileId);

        ksort($deleted);

        return $deleted;
    }

    /**
     * @return array<string, list<int>>
     */
    private function ids(int $profileId): array
    {
        $ids = [
            'portfolio_screeners' => [],
            'portfolio_tos_strategies' => [],
            'portfolio_tos_strategy_versions' => [],
            'portfolio_tos_discovery_runs' => [],
            'portfolio_tos_evaluation_runs' => [],
            'portfolio_tos_review_reports' => [],
            'portfolio_watchlists' => [],
        ];

        foreach (['portfolio_screeners', 'portfolio_tos_strategies', 'portfolio_tos_discovery_runs', 'portfolio_tos_evaluation_runs', 'portfolio_tos_review_reports', 'portfolio_watchlists'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'profile_id')) {
                $ids[$table] = DB::table($table)->where('profile_id', $profileId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
        }

        if (Schema::hasTable('portfolio_tos_strategy_versions') && $ids['portfolio_tos_strategies'] !== []) {
            $ids['portfolio_tos_strategy_versions'] = DB::table('portfolio_tos_strategy_versions')
                ->whereIn('strategy_id', $ids['portfolio_tos_strategies'])
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function profileTables(): array
    {
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter(fn ($name): bool => is_string($name)
                && $name !== 'portfolio_profiles'
                && Schema::hasColumn($name, 'profile_id'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function dependentCounts(int $profileId): array
    {
        $ids = $this->ids($profileId);
        $counts = [];

        foreach ($this->dependentTables as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $matchingIds = [];
            foreach ($columns as $column) {
                $source = match ($column) {
                    'screener_id' => $ids['portfolio_screeners'],
                    'strategy_id' => $ids['portfolio_tos_strategies'],
                    'strategy_version_id' => $ids['portfolio_tos_strategy_versions'],
                    'discovery_run_id' => $ids['portfolio_tos_discovery_runs'],
                    'evaluation_run_id' => $ids['portfolio_tos_evaluation_runs'],
                    'report_id' => $ids['portfolio_tos_review_reports'],
                    'watchlist_id' => $ids['portfolio_watchlists'],
                    default => [],
                };
                if ($source !== [] && Schema::hasColumn($table, $column)) {
                    DB::table($table)
                        ->whereIn($column, $source)
                        ->pluck('id')
                        ->each(function ($id) use (&$matchingIds): void {
                            $matchingIds[(int) $id] = true;
                        });
                }
            }
            $counts[$table] = count($matchingIds);
        }

        return $counts;
    }

    /**
     * @param array<string, int> $deleted
     */
    private function deleteWhereIn(array &$deleted, string $table, string $column, array $ids): void
    {
        if ($ids === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $count = DB::table($table)->whereIn($column, $ids)->delete();
        $deleted[$table] = ($deleted[$table] ?? 0) + $count;
    }

    /**
     * @param array<string, int> $deleted
     */
    private function deleteWhere(array &$deleted, string $table, string $column, int $id): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $count = DB::table($table)->where($column, $id)->delete();
        $deleted[$table] = ($deleted[$table] ?? 0) + $count;
    }

    private function finish(array $result, int $status): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($status === self::SUCCESS) {
            $this->info($result['dry_run'] ? 'Dry run passed. No data changed.' : 'Invalid Admin-owned Portfolio removed.');
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($result['error'] ?? 'Refusing to remove Portfolio.');
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $status;
    }
}
