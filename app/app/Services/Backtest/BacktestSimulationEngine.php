<?php

namespace App\Services\Backtest;

use App\Models\AnalysisPreference;
use App\Models\BacktestRun;
use App\Models\BacktestSnapshot;
use App\Models\BacktestTrade;
use App\Models\BacktestTransaction;
use App\Models\Benchmark;
use App\Models\PortfolioProfile;
use App\Models\Screener;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingStrategyVersion;
use App\Services\Artifacts\ArtifactRuntimeBindingResolver;
use App\Services\FeeCalculatorService;
use App\Services\Screener\ScreenerBacktestService;
use App\Services\Screener\ScreenerCatalog;
use App\Services\StrategyConfigurationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        protected ArtifactRuntimeBindingResolver $artifactRuntime,
        protected FeeCalculatorService $fees,
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
        $runtimeSelection = $this->artifactRuntime->forStrategyVersion($profile, $version);
        if ($strategy?->reusable_artifact_id !== null && $runtimeSelection === null) {
            throw ValidationException::withMessages([
                'strategy' => 'The Strategy immutable artifact binding is unavailable or blocked.',
            ]);
        }
        // The immutable Strategy envelope uses portable Screener slugs. Its exact
        // projected legacy version supplies the Portfolio-local Screener ids and
        // is hash-verified by ArtifactRuntimeBindingResolver above.
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
        $chargeComponents = $this->fees->componentsFromSettings();
        $executionAssumptions = [
            'price_method' => $input['price_method'] ?? 'next_open',
            'adverse_slippage_percent' => (float) ($input['adverse_slippage_percent'] ?? 0),
            'charge_model' => [
                'version' => 'settings-sha256:'.hash('sha256', json_encode($chargeComponents, JSON_THROW_ON_ERROR)),
                'components' => $chargeComponents,
            ],
        ];
        $ctx->set('config_snapshot', $config);
        $ctx->set('execution_assumptions', $executionAssumptions);
        $ctx->set('benchmark_evidence', $this->pinBenchmarkEvidence($profile, $from->toDateString(), $to->toDateString()));
        $ctx->set('reusable_artifact_version_id', $runtimeSelection?->artifactVersion->id);
        $ctx->set('artifact_binding_revision_id', $runtimeSelection?->bindingRevision->id);
        $ctx->set('eligibility_restricted', ($entryMeta['mode'] ?? 'unrestricted') === 'screener_union');
        $ctx->set('eligibility', [
            'phase' => 'pending',
            'screeners' => array_merge(
                array_map(static fn ($s) => [
                    'screener_id' => $s['screener_id'],
                    'role' => 'entry',
                    'definition_snapshot' => $s['definition_snapshot'],
                    'reusable_artifact_version_id' => $s['reusable_artifact_version_id'],
                    'artifact_binding_revision_id' => $s['artifact_binding_revision_id'],
                    'done' => false,
                ], $entryMeta['screeners']),
                array_map(static fn ($s) => [
                    'screener_id' => $s['screener_id'],
                    'role' => 'exit',
                    'definition_snapshot' => $s['definition_snapshot'],
                    'reusable_artifact_version_id' => $s['reusable_artifact_version_id'],
                    'artifact_binding_revision_id' => $s['artifact_binding_revision_id'],
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
            'reusable_artifact_version_id' => $runtimeSelection?->artifactVersion->id,
            'artifact_binding_revision_id' => $runtimeSelection?->bindingRevision->id,
            'entry_screener_versions_json' => $entryMeta['screeners'],
            'exit_screener_versions_json' => $exitMeta['screeners'],
            'name' => $name,
            'notes' => isset($input['notes']) ? (string) $input['notes'] : null,
            'tags_json' => $tags,
            'range_key' => $rangeKey,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'initial_capital' => $initialCapital,
            'execution_assumptions_json' => $executionAssumptions,
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
                    if ($dayResult['waiting'] ?? false) {
                        $run->error_message = 'Waiting for complete execution-price data for '.$asOf.': '.implode(', ', $dayResult['limitations'] ?? []);
                        break;
                    }
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
                    $run->error_message = null;
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
            'reusable_artifact_version_id' => $run->reusable_artifact_version_id,
            'artifact_binding_revision_id' => $run->artifact_binding_revision_id,
            'entry_screener_versions' => $run->entry_screener_versions_json,
            'exit_screener_versions' => $run->exit_screener_versions_json,
            'range_key' => $run->range_key,
            'from_date' => $run->from_date?->toDateString(),
            'to_date' => $run->to_date?->toDateString(),
            'initial_capital' => (float) $run->initial_capital,
            'execution_assumptions' => $run->execution_assumptions_json,
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
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
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
                'benchmark_return_pct' => $t->benchmark_return_pct !== null ? (float) $t->benchmark_return_pct : null,
                'cagr' => $t->cagr !== null ? (float) $t->cagr : null,
                'exit_reason' => $t->exit_reason,
                'is_open' => (bool) $t->is_open,
                'is_success' => $t->is_success,
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

    /** @param Collection<int,BacktestRun> $runs @return array<string,mixed> */
    public function compare(Collection $runs): array
    {
        $rows = $runs->map(fn (BacktestRun $run): array => [
            'id' => $run->id,
            'name' => $run->name,
            'status' => $run->status,
            'strategy_id' => $run->strategy_id,
            'strategy_version_id' => $run->strategy_version_id,
            'strategy_name' => $run->strategy_name,
            'artifact_version_id' => $run->reusable_artifact_version_id,
            'binding_revision_id' => $run->artifact_binding_revision_id,
            'period' => ['from' => $run->from_date?->toDateString(), 'to' => $run->to_date?->toDateString()],
            'initial_capital' => (float) $run->initial_capital,
            'execution_assumptions' => $run->execution_assumptions_json,
            'statistics' => $run->statistics_json,
        ])->values();
        $compatibility = [
            'strategy_version' => $rows->pluck('strategy_version_id')->uniqueStrict()->count() === 1,
            'period' => $rows->pluck('period')->uniqueStrict()->count() === 1,
        ];

        return [
            'compatible' => ! in_array(false, $compatibility, true),
            'compatibility' => $compatibility,
            'assumption_differences' => collect(['initial_capital', 'execution_assumptions'])
                ->filter(fn (string $key): bool => $rows->pluck($key)->uniqueStrict()->count() > 1)->values()->all(),
            'runs' => $rows->all(),
            'ranking' => null,
            'disclosure' => 'Comparison is descriptive only; StoX does not rank or promote a winning Backtest.',
        ];
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

    public function cancel(BacktestRun $run): BacktestRun
    {
        if ($run->isTerminal()) {
            throw ValidationException::withMessages(['run' => 'Only an in-progress Backtest can be cancelled.']);
        }

        $run->forceFill([
            'status' => BacktestRun::STATUS_CANCELLED,
            'stage' => BacktestRun::STAGE_CANCELLED,
            'cancelled_at' => now(),
        ])->save();
        $this->persistence->clearTransientState($run);

        return $run->fresh();
    }

    public function delete(BacktestRun $run, ?int $actorId = null): void
    {
        DB::transaction(function () use ($run, $actorId): void {
            DB::table('portfolio_backtest_run_tombstones')->insert([
                'backtest_run_id' => $run->id,
                'profile_id' => $run->profile_id,
                'strategy_id' => $run->strategy_id,
                'final_status' => $run->status,
                'run_created_at' => $run->created_at,
                'deleted_by_user_id' => $actorId,
                'deleted_at' => now(),
            ]);
            $this->persistence->deleteRun($run);
        });
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
                ->ownedOrSameUserShared($profile)
                ->first();
            if (! $screener) {
                continue;
            }
            $runtimeSelection = $this->artifactRuntime->forScreener($screener);
            if ($screener->reusable_artifact_id !== null && $runtimeSelection === null) {
                throw ValidationException::withMessages([
                    'strategy' => "Eligibility Screener {$screener->name} has no usable immutable binding.",
                ]);
            }
            $definition = $runtimeSelection?->definition ?? (is_array($screener->definition_json)
                ? $screener->definition_json
                : ['root' => $screener->definition_json]);
            $screeners[] = [
                'screener_id' => $screener->id,
                'name' => $screener->name,
                'slug' => $screener->slug,
                'artifact_version' => $screener->artifact_version ?? null,
                'definition_hash' => $screener->definition_hash ?? null,
                'definition_snapshot' => $definition,
                'reusable_artifact_version_id' => $runtimeSelection?->artifactVersion->id,
                'artifact_binding_revision_id' => $runtimeSelection?->bindingRevision->id,
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
                ->ownedOrSameUserShared($profile)
                ->first();
            if (! $screener) {
                continue;
            }
            $runtimeSelection = $this->artifactRuntime->forScreener($screener);
            if ($screener->reusable_artifact_id !== null && $runtimeSelection === null) {
                throw ValidationException::withMessages([
                    'strategy' => "Exit Screener {$screener->name} has no usable immutable binding.",
                ]);
            }
            $definition = $runtimeSelection?->definition ?? (is_array($screener->definition_json)
                ? $screener->definition_json
                : ['root' => $screener->definition_json]);
            $screeners[] = [
                'screener_id' => $screener->id,
                'name' => $screener->name,
                'slug' => $screener->slug,
                'artifact_version' => $screener->artifact_version ?? null,
                'definition_hash' => $screener->definition_hash ?? null,
                'definition_snapshot' => $definition,
                'reusable_artifact_version_id' => $runtimeSelection?->artifactVersion->id,
                'artifact_binding_revision_id' => $runtimeSelection?->bindingRevision->id,
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

    /** @return array<string,mixed> */
    private function pinBenchmarkEvidence(PortfolioProfile $profile, string $from, string $to): array
    {
        $preference = AnalysisPreference::query()->where('user_id', $profile->user_id)
            ->where('scope_key', 'portfolio:'.$profile->id)->first();
        $benchmark = $preference?->primaryBenchmark
            ?? Benchmark::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        if ($benchmark === null) {
            return ['complete' => false, 'limitations' => ['benchmark_not_configured']];
        }
        $stock = Stock::query()->where('symbol', $benchmark->symbol)->first();
        if ($stock === null) {
            return [
                'benchmark_id' => $benchmark->id, 'stable_key' => $benchmark->stable_key,
                'symbol' => $benchmark->symbol, 'complete' => false,
                'limitations' => ['benchmark_security_not_available'],
            ];
        }
        $observations = [];
        foreach (['from' => $from, 'to' => $to] as $key => $date) {
            $row = StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '<=', $date)
                ->orderByDesc('price_date')->orderByDesc('id')->first();
            $value = $row?->adjusted_close_price ?? $row?->close_price;
            $observations[$key] = $row === null || $value === null ? null : [
                'stock_price_id' => $row->id,
                'price_date' => $row->price_date->toDateString(),
                'value' => (float) $value,
                'provider' => $row->provider_source,
                'fingerprint' => hash('sha256', json_encode([
                    $row->id, $row->price_date->toDateString(), (float) $value, $row->provider_source,
                ], JSON_THROW_ON_ERROR)),
            ];
        }
        $complete = $observations['from'] !== null && $observations['to'] !== null
            && $observations['from']['value'] > 0;

        return [
            'benchmark_id' => $benchmark->id, 'stable_key' => $benchmark->stable_key,
            'symbol' => $benchmark->symbol, 'return_type' => $benchmark->return_type,
            'observations' => $observations,
            'return_percent' => $complete
                ? round((($observations['to']['value'] / $observations['from']['value']) - 1) * 100, 6) : null,
            'complete' => $complete,
            'limitations' => $complete ? [] : ['benchmark_period_observations_incomplete'],
        ];
    }
}
