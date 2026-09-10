<?php

namespace App\Services\Simulation;

use App\Models\ArtifactBinding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReplayRun;
use App\Models\TradingStrategy;
use App\Services\FeeCalculatorService;
use App\Services\HistoricalHoldingsService;
use App\Services\ProfileSettingsService;
use App\Support\TradingCalendar;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PortfolioReplayService
{
    public function __construct(
        private HistoricalHoldingsService $history,
        private FeeCalculatorService $fees,
        private ProfileSettingsService $profileSettings,
    ) {}

    /** @param array<string, mixed> $input */
    public function readiness(PortfolioProfile $profile, array $input): array
    {
        $mode = $input['starting_mode'];
        $start = $input['period_start'];
        $limitations = [];
        if (! TradingCalendar::isEquitySessionDate(Carbon::parse($start))) {
            $limitations[] = 'period_start_is_not_an_equity_session';
        }
        if ($mode === 'historical_branch') {
            $state = $this->history->asOf($profile, $start);
            if (! $state['completeness']['total_value_complete']) {
                $limitations[] = 'historical_starting_state_not_reconstructable';
            }
            // Holdings and cash alone are not a complete Replay branch. Until the
            // point-in-time reconstruction also covers Strategy ownership,
            // capital allocations, loans/recalls and bridge funding, proceeding
            // would fabricate a materially different Portfolio world.
            $limitations[] = 'historical_strategy_capital_state_not_reconstructable';
        } else {
            $state = [
                'schema_version' => 1,
                'cash_balance' => (float) $input['starting_cash'],
                'holdings' => [],
                'reservations' => [],
                'loans' => [],
                'recalls' => [],
                'recall_bridge_loans' => [],
                'transactions' => [],
                'source' => 'new_simulated_portfolio',
            ];
        }
        $bindings = ArtifactBinding::query()->with('activeRevision')
            ->where('profile_id', $profile->id)->where('status', ArtifactBinding::STATUS_ENABLED)->get();
        if ($bindings->isEmpty()) {
            $limitations[] = 'no_enabled_strategy_artifact_bindings';
        }
        $strategies = TradingStrategy::query()->where('profile_id', $profile->id)
            ->whereIn('reusable_artifact_id', $bindings->pluck('artifact_id'))->get()->keyBy('reusable_artifact_id');
        $pinned = $bindings->map(function (ArtifactBinding $binding) use ($strategies): array {
            $strategy = $strategies->get($binding->artifact_id);

            return [
                'binding_id' => $binding->id, 'binding_revision_id' => $binding->active_revision_id,
                'artifact_id' => $binding->artifact_id, 'artifact_version_id' => $binding->activeRevision?->artifact_version_id,
                'usability_state' => $binding->usability_state,
                'strategy_id' => $strategy?->id,
                'strategy_name' => $strategy?->name,
                'allocation_pct' => $strategy?->allocation_pct !== null ? (float) $strategy->allocation_pct : null,
            ];
        })->values()->all();
        if ($mode === 'new_simulated') {
            $state['strategies'] = collect($pinned)->map(fn (array $row): array => [
                'strategy_id' => $row['strategy_id'],
                'artifact_version_id' => $row['artifact_version_id'],
                'allocation_pct' => $row['allocation_pct'],
                'owned_market_value' => 0.0,
                'reserved' => 0.0,
                'lent' => 0.0,
                'borrowed' => 0.0,
            ])->values()->all();
        }
        if ($bindings->contains(fn (ArtifactBinding $binding) => $binding->usability_state === ArtifactBinding::BLOCKED)) {
            $limitations[] = 'blocked_artifact_binding';
        }

        $chargeComponents = $this->fees->componentsFromSettings();

        return [
            'status' => $limitations === [] ? 'ready' : (array_intersect($limitations, [
                'historical_starting_state_not_reconstructable',
                'historical_strategy_capital_state_not_reconstructable',
                'no_enabled_strategy_artifact_bindings',
                'blocked_artifact_binding',
            ]) ? 'blocked' : 'ready_with_limitations'),
            'requested_period' => ['from' => $start, 'to' => $input['period_end']],
            'starting_state' => $state,
            'pinned_world' => [
                'binding_revisions' => $pinned,
                'charge_model' => [
                    'version' => 'settings-sha256:'.hash('sha256', json_encode($chargeComponents, JSON_THROW_ON_ERROR)),
                    'components' => $chargeComponents,
                ],
                'calendar' => [
                    'market' => 'india_equity',
                    'session_rule' => 'TradingCalendar::isEquitySessionDate',
                    'resolution' => 'daily_eod',
                ],
                'portfolio_economic_settings' => $this->economicSettings($profile),
                'captured_at' => now()->toISOString(),
            ],
            'limitations' => $limitations,
        ];
    }

    /** @return array<string, string|null> */
    private function economicSettings(PortfolioProfile $profile): array
    {
        $keys = [
            'portfolio_cash_reserve_pct',
            'max_lending_pct_of_unused',
            'max_lending_absolute',
        ];

        return collect($keys)->mapWithKeys(function (string $key) use ($profile): array {
            $value = $this->profileSettings->get($profile, $key, '');

            return [$key => $value === null || trim((string) $value) === '' ? null : (string) $value];
        })->all();
    }

    /** @param array<string, mixed> $input */
    public function create(PortfolioProfile $profile, int $userId, array $input): PortfolioReplayRun
    {
        $readiness = $this->readiness($profile, $input);
        if ($readiness['status'] === 'blocked') {
            throw ValidationException::withMessages(['readiness' => $readiness['limitations']]);
        }

        return PortfolioReplayRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'user_id' => $userId, 'profile_id' => $profile->id,
            'starting_mode' => $input['starting_mode'], 'period_start' => $input['period_start'], 'period_end' => $input['period_end'],
            'starting_cash' => $input['starting_mode'] === 'new_simulated' ? $input['starting_cash'] : null,
            'price_method' => $input['price_method'], 'adverse_slippage_percent' => $input['adverse_slippage_percent'] ?? 0,
            'status' => 'queued', 'pinned_world' => $readiness['pinned_world'],
            'starting_state' => $readiness['starting_state'], 'readiness' => $readiness,
        ]);
    }

    public function cancel(PortfolioReplayRun $run): PortfolioReplayRun
    {
        if (! in_array($run->status, ['queued', 'running'], true)) {
            throw ValidationException::withMessages(['run' => 'Only queued or running Replay can be cancelled.']);
        }
        $run->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        return $run->fresh();
    }

    public function delete(PortfolioReplayRun $run, int $actorId): void
    {
        DB::transaction(function () use ($run, $actorId): void {
            DB::table('portfolio_replay_run_tombstones')->insert([
                'run_uuid' => $run->run_uuid, 'user_id' => $run->user_id, 'profile_id' => $run->profile_id,
                'run_type' => 'portfolio_replay', 'final_status' => $run->status,
                'run_created_at' => $run->created_at, 'deleted_by_user_id' => $actorId, 'deleted_at' => now(),
            ]);
            $run->delete();
        });
    }
}
