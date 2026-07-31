<?php

namespace App\Services\Backtest;

use App\Models\BacktestRun;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\TradingStrategyVersion;
use App\Services\Screener\ScreenerBacktestService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\StrategyConfigurationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Resumable strategy simulation engine with cooperative ~20s time budget per request.
 * Stages: PREPARING → (eligibility precompute) → SIMULATING_DAYS → GENERATING_STATISTICS → GENERATING_REPORT → COMPLETED.
 */
class BacktestSimulationEngine
{
    public function __construct(
        protected StrategyConfigurationService $strategies,
        protected ScreenerBacktestService $screenerBacktest,
        protected EligibilityPrecomputeService $eligibilityPrecompute,
        protected SimulationDayProcessor $dayProcessor,
        protected StatisticsGenerator $statistics,
        protected BacktestPersistenceService $persistence,
        protected TimelineBuilder $timeline,
    ) {}

    /**
     * @param  array{
     *     name?: string,
     *     range_key?: string,
     *     from_date?: string,
     *     to_date?: string,
     *     initial_capital?: float,
     *     notes?: string|null,
     *     tags?: list<string>|null,
     *     session_token?: string,
     *     strategy_version_id?: int|null
     * }  $input
     * @return array{run: array<string, mixed>, continued: bool, completed: bool}
     */
    public function start(PortfolioProfile $profile, array $input): array
    {
        $sessionToken = trim((string) ($input['session_token'] ?? ''));
        if ($sessionToken === '') {
            throw ValidationException::withMessages(['session_token' => 'session_token is required.']);
        }

        $rangeKey = $this->normalizeRangeKey((string) ($input['range_key'] ?? '1y'));
        $to = ! empty($input['to_date'])
            ? Carbon::parse($input['to_date'])->startOfDay()
            : Carbon::now(config('app.timezone'))->startOfDay();
        $from = ! empty($input['from_date'])
            ? Carbon::parse($input['from_date'])->startOfDay()
            : $this->screenerBacktest->fromDateForRange($rangeKey, $to->copy());

        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from_date' => 'from_date must be on or before to_date.']);
        }

        $days = $this->screenerBacktest->weekdayDates($from, $to);
        $dayStrings = array_map(static fn (Carbon $d) => $d->toDateString(), $days);
        if ($dayStrings === []) {
            throw ValidationException::withMessages(['from_date' => 'No trading days in the selected period.']);
        }

        $version = $this->resolveStrategyVersion($profile, isset($input['strategy_version_id']) ? (int) $input['strategy_version_id'] : null);
        $strategy = $version->strategy;
        $config = $version->config_json ?? $this->strategies->defaultConfig();
        $config = $this->strategies->normalizeConfig(is_array($config) ? $config : []);

        $initialCapital = (float) ($input['initial_capital'] ?? 100000);
        if ($initialCapital < 1000) {
            throw ValidationException::withMessages(['initial_capital' => 'Initial capital must be at least 1000.']);
        }

        $entryMeta = $this->pinEntryScreeners($profile, $config);
        $exitMeta = $this->pinExitScreeners($profile, $config);

        if (($entryMeta['mode'] ?? 'unrestricted') !== 'screener_union' || ($entryMeta['screeners'] ?? []) === []) {
            throw ValidationException::withMessages([
                'strategy' => 'Strategy Backtests require at least one enabled eligibility Screener. Configure Eligibility Sources on the Strategy page, then retry.',
            ]);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = ($strategy?->name ?? 'Strategy').' · '.$from->toDateString().' → '.$to->toDateString();
        }

        $tags = $input['tags'] ?? [];
        if (! is_array($tags)) {
            $tags = [];
        }
        $tags = array_values(array_unique(array_filter(array_map(
            static fn ($t) => is_string($t) ? trim($t) : '',
            $tags
        ), static fn ($t) => $t !== '')));

        $ctx = SimulationContext::blank($initialCapital, $dayStrings);
        $ctx->set('config_snapshot', $config);
        $ctx->set('eligibility_restricted', ($entryMeta['mode'] ?? 'unrestricted') === 'screener_union');
        $ctx->set('eligibility', [
            'phase' => 'pending',
            'screeners' => array_merge(
                array_map(static fn ($s) => [
                    'screener_id' => $s['screener_id'],
                    'role' => 'entry',
                    'done' => false,
                ], $entryMeta['screeners']),
                array_map(static fn ($s) => [
                    'screener_id' => $s['screener_id'],
                    'role' => 'exit',
                    'done' => false,
                ], $exitMeta['screeners']),
            ),
        ]);

        $run = BacktestRun::query()->create([
            'profile_id' => $profile->id,
            'user_id' => Auth::id(),
            'strategy_id' => $strategy?->id,
            'strategy_version_id' => $version->id,
            'strategy_name' => $strategy?->name,
            'strategy_version_number' => $version->version,
            'entry_screener_versions_json' => $entryMeta['screeners'],
            'exit_screener_versions_json' => $exitMeta['screeners'],
            'name' => $name,
            'notes' => isset($input['notes']) ? (string) $input['notes'] : null,
            'tags_json' => $tags,
            'range_key' => $rangeKey,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'initial_capital' => $initialCapital,
            'status' => BacktestRun::STATUS_PREPARING,
            'stage' => BacktestRun::STAGE_PREPARING,
            'processed_days' => 0,
            'total_days' => count($dayStrings),
            'progress_pct' => 0,
            'current_date' => null,
            'session_token' => $sessionToken,
            'context_json' => $ctx->toArray(),
            'started_at' => now(),
        ]);

        return $this->resume($run->fresh());
    }

    /**
     * @return array{run: array<string, mixed>, continued: bool, completed: bool}
     */
    public function resume(BacktestRun $run): array
    {
        if ($run->isTerminal()) {
            return [
                'run' => $this->format($run),
                'continued' => false,
                'completed' => $run->status === BacktestRun::STATUS_COMPLETED,
            ];
        }

        $deadline = microtime(true) + SimulationContext::TIME_BUDGET_SECONDS;

        try {
            $ctx = SimulationContext::fromArray($run->context_json);

            if ($run->stage === BacktestRun::STAGE_PREPARING || $run->status === BacktestRun::STATUS_PREPARING) {
                $elig = $this->eligibilityPrecompute->advance($run, $ctx, $deadline);
                $ctx = $elig['context'];
                if (! $elig['done']) {
                    $this->saveProgress($run, $ctx, BacktestRun::STAGE_PREPARING, BacktestRun::STATUS_PREPARING);

                    return [
                        'run' => $this->format($run->fresh()),
                        'continued' => true,
                        'completed' => false,
                    ];
                }
                $run->stage = BacktestRun::STAGE_SIMULATING_DAYS;
                $run->status = BacktestRun::STATUS_RUNNING;
            }

            if ($run->stage === BacktestRun::STAGE_SIMULATING_DAYS) {
                $days = $ctx->tradingDays();
                $cursor = $ctx->dayCursor();
                while ($cursor < count($days) && microtime(true) < $deadline) {
                    $asOf = $days[$cursor];
                    $dayResult = $this->dayProcessor->processDay($run, $ctx, $asOf);
                    $this->persistence->persistDayResults(
                        $run,
                        $dayResult['transactions'],
                        $dayResult['closed_trades'],
                        $dayResult['snapshot'],
                    );
                    $cursor++;
                    $ctx->setDayCursor($cursor);
                    $run->processed_days = $cursor;
                    $run->current_date = $asOf;
                    $run->progress_pct = count($days) > 0
                        ? round(($cursor / count($days)) * 90.0, 4) // leave headroom for stats/report
                        : 90.0;
                }
                $this->saveProgress($run, $ctx, BacktestRun::STAGE_SIMULATING_DAYS, BacktestRun::STATUS_RUNNING);

                if ($cursor < count($days)) {
                    return [
                        'run' => $this->format($run->fresh()),
                        'continued' => true,
                        'completed' => false,
                    ];
                }
                $run->stage = BacktestRun::STAGE_GENERATING_STATISTICS;
            }

            if ($run->stage === BacktestRun::STAGE_GENERATING_STATISTICS) {
                $days = $ctx->tradingDays();
                $lastDate = $days !== [] ? $days[count($days) - 1] : $run->to_date->toDateString();
                $this->persistence->persistOpenLotsAsTrades($run, $ctx, $lastDate);
                $stats = $this->statistics->generate($run, $ctx);
                $run->statistics_json = $stats;
                $run->stage = BacktestRun::STAGE_GENERATING_REPORT;
                $run->progress_pct = 95;
                $this->saveProgress($run, $ctx, BacktestRun::STAGE_GENERATING_REPORT, BacktestRun::STATUS_RUNNING);
            }

            if ($run->stage === BacktestRun::STAGE_GENERATING_REPORT) {
                $started = $run->started_at ? Carbon::parse($run->started_at) : now();
                $run->forceFill([
                    'status' => BacktestRun::STATUS_COMPLETED,
                    'stage' => BacktestRun::STAGE_COMPLETED,
                    'progress_pct' => 100,
                    'completed_at' => now(),
                    'execution_seconds' => max(0, (int) $started->diffInSeconds(now())),
                    'error_message' => null,
                ])->save();
                $this->persistence->clearTransientState($run);

                return [
                    'run' => $this->format($run->fresh()),
                    'continued' => false,
                    'completed' => true,
                ];
            }

            return [
                'run' => $this->format($run->fresh()),
                'continued' => true,
                'completed' => false,
            ];
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => BacktestRun::STATUS_FAILED,
                'stage' => BacktestRun::STAGE_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ])->save();
            $this->persistence->clearTransientState($run);

            return [
                'run' => $this->format($run->fresh()),
                'continued' => false,
                'completed' => false,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function format(BacktestRun $run): array
    {
        $ctx = is_array($run->context_json) ? $run->context_json : [];
        $elig = is_array($ctx['eligibility'] ?? null) ? $ctx['eligibility'] : [];
        $screeners = is_array($elig['screeners'] ?? null) ? $elig['screeners'] : [];
        $eligDone = 0;
        $eligTotal = max(1, count($screeners));
        foreach ($screeners as $s) {
            if (! empty($s['done'])) {
                $eligDone++;
            } elseif (isset($s['stock_total'], $s['stock_cursor']) && (int) $s['stock_total'] > 0) {
                $eligDone += min(1, ((int) $s['stock_cursor']) / (int) $s['stock_total']);
            }
        }

        return [
            'id' => $run->id,
            'name' => $run->name,
            'notes' => $run->notes,
            'tags' => $run->tags_json ?? [],
            'status' => $run->status,
            'stage' => $run->stage,
            'strategy_id' => $run->strategy_id,
            'strategy_name' => $run->strategy_name,
            'strategy_version_id' => $run->strategy_version_id,
            'strategy_version' => $run->strategy_version_number,
            'entry_screener_versions' => $run->entry_screener_versions_json,
            'exit_screener_versions' => $run->exit_screener_versions_json,
            'range_key' => $run->range_key,
            'from_date' => $run->from_date?->toDateString(),
            'to_date' => $run->to_date?->toDateString(),
            'initial_capital' => (float) $run->initial_capital,
            'processed_days' => (int) $run->processed_days,
            'total_days' => (int) $run->total_days,
            'progress_pct' => (float) $run->progress_pct,
            'current_date' => $run->current_date?->toDateString(),
            'eligibility_phase' => $elig['phase'] ?? null,
            'eligibility_progress' => round(($eligDone / $eligTotal) * 100, 2),
            'statistics' => $run->statistics_json,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'execution_seconds' => $run->execution_seconds,
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(BacktestRun $run): array
    {
        $snapshots = BacktestSnapshot::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn (BacktestSnapshot $s) => [
                'date' => $s->snapshot_date->toDateString(),
                'cash' => (float) $s->cash,
                'invested_value' => (float) $s->invested_value,
                'portfolio_value' => (float) $s->portfolio_value,
                'realized_profit' => (float) $s->realized_profit,
                'unrealized_profit' => (float) $s->unrealized_profit,
                'drawdown_pct' => (float) $s->drawdown_pct,
                'holdings_count' => (int) $s->holdings_count,
            ])
            ->all();

        $trades = BacktestTrade::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('buy_date')
            ->get()
            ->map(fn (BacktestTrade $t) => [
                'id' => $t->id,
                'stock_id' => $t->stock_id,
                'symbol' => $t->symbol,
                'buy_date' => $t->buy_date->toDateString(),
                'sell_date' => $t->sell_date?->toDateString(),
                'holding_days' => $t->holding_days,
                'buy_price' => (float) $t->buy_price,
                'sell_price' => $t->sell_price !== null ? (float) $t->sell_price : null,
                'quantity' => (float) $t->quantity,
                'profit_loss' => $t->profit_loss !== null ? (float) $t->profit_loss : null,
                'return_pct' => $t->return_pct !== null ? (float) $t->return_pct : null,
                'cagr' => $t->cagr !== null ? (float) $t->cagr : null,
                'exit_reason' => $t->exit_reason,
                'is_open' => (bool) $t->is_open,
            ])
            ->all();

        $transactions = BacktestTransaction::query()
            ->where('backtest_run_id', $run->id)
            ->orderBy('trade_date')
            ->orderBy('id')
            ->get()
            ->map(fn (BacktestTransaction $tx) => [
                'id' => $tx->id,
                'date' => $tx->trade_date->toDateString(),
                'stock_id' => $tx->stock_id,
                'symbol' => $tx->symbol,
                'side' => $tx->side,
                'quantity' => (float) $tx->quantity,
                'price' => (float) $tx->price,
                'value' => (float) $tx->value,
                'reason' => $tx->reason,
                'recommendation' => $tx->recommendation,
            ])
            ->all();

        return array_merge($this->format($run), [
            'snapshots' => $snapshots,
            'trades' => $trades,
            'transactions' => $transactions,
            'timeline' => $run->status === BacktestRun::STATUS_COMPLETED
                ? $this->timeline->build($run)
                : null,
            'chart' => [
                'initial_capital' => (float) $run->initial_capital,
                'points' => $snapshots,
            ],
        ]);
    }

    /**
     * @param  array{notes?: string|null, tags?: list<string>|null, name?: string|null}  $input
     */
    public function updateMeta(BacktestRun $run, array $input): BacktestRun
    {
        $fill = [];
        if (array_key_exists('notes', $input)) {
            $fill['notes'] = $input['notes'];
        }
        if (array_key_exists('name', $input) && is_string($input['name']) && trim($input['name']) !== '') {
            $fill['name'] = trim($input['name']);
        }
        if (array_key_exists('tags', $input)) {
            $tags = is_array($input['tags']) ? $input['tags'] : [];
            $fill['tags_json'] = array_values(array_unique(array_filter(array_map(
                static fn ($t) => is_string($t) ? trim($t) : '',
                $tags
            ), static fn ($t) => $t !== '')));
        }
        if ($fill !== []) {
            $run->forceFill($fill)->save();
        }

        return $run->fresh();
    }

    public function delete(BacktestRun $run): void
    {
        $this->persistence->deleteRun($run);
    }

    private function saveProgress(BacktestRun $run, SimulationContext $ctx, string $stage, string $status): void
    {
        $run->forceFill([
            'context_json' => $ctx->toArray(),
            'stage' => $stage,
            'status' => $status,
            'processed_days' => $ctx->dayCursor(),
            'progress_pct' => $run->progress_pct,
            'current_date' => $run->current_date,
        ])->save();
    }

    private function resolveStrategyVersion(PortfolioProfile $profile, ?int $versionId): TradingStrategyVersion
    {
        if ($versionId) {
            $version = TradingStrategyVersion::query()
                ->with('strategy')
                ->find($versionId);
            if ($version && (int) $version->strategy?->profile_id === (int) $profile->id) {
                return $version;
            }
        }

        return $this->strategies->ensureActive($profile)->load('strategy');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{mode: string, screeners: list<array<string, mixed>>}
     */
    private function pinEntryScreeners(PortfolioProfile $profile, array $config): array
    {
        $sources = $config['eligibility_sources'] ?? [];
        if (! is_array($sources) || $sources === []) {
            return ['mode' => 'unrestricted', 'screeners' => []];
        }
        $enabled = array_values(array_filter(
            $sources,
            fn ($s) => is_array($s) && ($s['enabled'] ?? true) && (int) ($s['screener_id'] ?? 0) > 0
        ));
        $screeners = [];
        foreach ($enabled as $source) {
            $id = (int) $source['screener_id'];
            $screener = Screener::query()
                ->where('id', $id)
                ->where(function ($q) use ($profile) {
                    $q->where('profile_id', $profile->id)->orWhere('is_shared', true);
                })
                ->first();
            if (! $screener) {
                continue;
            }
            $screeners[] = [
                'screener_id' => $screener->id,
                'name' => $screener->name,
                'slug' => $screener->slug,
                'artifact_version' => $screener->artifact_version ?? null,
                'definition_hash' => $screener->definition_hash ?? null,
            ];
        }

        return [
            'mode' => $screeners === [] ? 'unrestricted' : 'screener_union',
            'screeners' => $screeners,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{screeners: list<array<string, mixed>>}
     */
    private function pinExitScreeners(PortfolioProfile $profile, array $config): array
    {
        $exit = is_array($config['exit_strategy'] ?? null) ? $config['exit_strategy'] : [];
        $rules = is_array($exit['rules'] ?? null) ? $exit['rules'] : [];
        $screeners = [];
        $seen = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ($rule['key'] ?? '') !== 'screener_exit' || ! ($rule['enabled'] ?? false)) {
                continue;
            }
            $id = (int) ($rule['screener_id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $screener = Screener::query()
                ->where('id', $id)
                ->where(function ($q) use ($profile) {
                    $q->where('profile_id', $profile->id)->orWhere('is_shared', true);
                })
                ->first();
            if (! $screener) {
                continue;
            }
            $screeners[] = [
                'screener_id' => $screener->id,
                'name' => $screener->name,
                'slug' => $screener->slug,
                'artifact_version' => $screener->artifact_version ?? null,
                'definition_hash' => $screener->definition_hash ?? null,
            ];
        }

        return ['screeners' => $screeners];
    }

    private function normalizeRangeKey(string $rangeKey): string
    {
        $rangeKey = strtolower(trim($rangeKey));
        $allowed = array_map(
            static fn (array $r) => (string) ($r['id'] ?? ''),
            ScreenerCatalog::BACKTEST_RANGES
        );
        if (! in_array($rangeKey, $allowed, true) && $rangeKey !== 'custom') {
            return '1y';
        }

        return $rangeKey;
    }
}
