<?php

namespace App\Services\Simulation;

use App\Models\ArtifactBinding;
use App\Models\PortfolioProfile;
use App\Models\PortfolioReplayRun;
use App\Services\FeeCalculatorService;
use App\Services\HistoricalHoldingsService;
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
            $state = ['cash_balance' => (float) $input['starting_cash'], 'holdings' => [], 'source' => 'new_simulated_portfolio'];
        }
        $bindings = ArtifactBinding::query()->with('activeRevision')
            ->where('profile_id', $profile->id)->where('status', ArtifactBinding::STATUS_ENABLED)->get();
        if ($bindings->isEmpty()) {
            $limitations[] = 'no_enabled_strategy_artifact_bindings';
        }
        $pinned = $bindings->map(fn (ArtifactBinding $binding) => [
            'binding_id' => $binding->id, 'binding_revision_id' => $binding->active_revision_id,
            'artifact_id' => $binding->artifact_id, 'artifact_version_id' => $binding->activeRevision?->artifact_version_id,
            'usability_state' => $binding->usability_state,
        ])->values()->all();
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
                'captured_at' => now()->toISOString(),
            ],
            'limitations' => $limitations,
        ];
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
