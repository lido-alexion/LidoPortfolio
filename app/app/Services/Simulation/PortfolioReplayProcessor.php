<?php

namespace App\Services\Simulation;

use App\Models\PortfolioReplayCheckpoint;
use App\Models\PortfolioReplayRun;
use App\Models\StockPrice;
use App\Support\TradingCalendar;
use Illuminate\Support\Facades\DB;

final class PortfolioReplayProcessor
{
    public function __construct(private ReplayEconomicStateCalculator $economics) {}

    /** @return array<string, mixed> */
    public function process(PortfolioReplayRun $run, int $maxSessions = 5): array
    {
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return ['status' => $run->status, 'processed_sessions' => 0];
        }

        return DB::transaction(function () use ($run, $maxSessions): array {
            $run = PortfolioReplayRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return ['status' => $run->status, 'processed_sessions' => 0];
            }
            if ($run->status === 'queued') {
                $run->forceFill(['status' => 'running', 'started_at' => now()])->save();
            }

            $cursor = $run->checkpoint_date
                ? $run->checkpoint_date->copy()->addDay()
                : $run->period_start->copy();
            $processed = 0;
            while ($cursor->lte($run->period_end) && $processed < max(1, min($maxSessions, 31))) {
                if (! TradingCalendar::isEquitySessionDate($cursor)) {
                    $cursor->addDay();
                    continue;
                }
                $session = $cursor->toDateString();
                if (! PortfolioReplayCheckpoint::query()->where('replay_run_id', $run->id)
                    ->whereDate('effective_session_date', $session)->exists()) {
                    $prior = PortfolioReplayCheckpoint::query()->where('replay_run_id', $run->id)
                        ->orderByDesc('effective_session_date')->first();
                    $state = $prior?->state_after ?? $run->starting_state;
                    $prices = StockPrice::query()->whereDate('price_date', $session)->whereNotNull('close_price')
                        ->pluck('close_price', 'stock_id')->map(fn ($price) => (float) $price)->all();
                    $stateAfter = $this->economics->advance(
                        $state,
                        $run->pinned_world['portfolio_economic_settings'] ?? [],
                        $prices,
                    );
                    PortfolioReplayCheckpoint::query()->create([
                        'replay_run_id' => $run->id, 'effective_session_date' => $session,
                        'processed_at' => now(), 'stage' => 'economic_checkpoint',
                        'state_before' => $state, 'state_after' => $stateAfter,
                        'market_evidence' => $this->marketFingerprint($session),
                        'limitations' => ['strategy_evaluation_and_trade_transition_pending_integration'],
                    ]);
                }
                $run->forceFill(['checkpoint_date' => $session])->save();
                $processed++;
                $cursor->addDay();
            }

            // Do not claim completion until deterministic Strategy evaluation and
            // trade transitions build on the isolated economic state checkpoints.
            return [
                'status' => 'running', 'processed_sessions' => $processed,
                'checkpoint_date' => $run->checkpoint_date?->toDateString(),
                'remaining' => $cursor->lte($run->period_end),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function marketFingerprint(string $session): array
    {
        $rows = StockPrice::query()->whereDate('price_date', $session)->orderBy('stock_id')->orderBy('id')
            ->get(['stock_id', 'open_price', 'high_price', 'low_price', 'close_price', 'provider_source', 'data_source']);
        $digestInput = $rows->map(fn ($row) => [
            (int) $row->stock_id, $row->open_price, $row->high_price, $row->low_price,
            $row->close_price, $row->provider_source,
        ])->all();

        return [
            'session' => $session, 'observation_count' => $rows->count(),
            'sha256' => hash('sha256', json_encode($digestInput, JSON_THROW_ON_ERROR)),
        ];
    }
}
