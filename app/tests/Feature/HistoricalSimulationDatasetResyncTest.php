<?php

namespace Tests\Feature;

use App\Models\DatasetVersion;
use App\Models\PortfolioReplayCheckpoint;
use App\Models\PortfolioReplayRun;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\DailyMarketSyncService;
use App\Services\Simulation\PortfolioReplayProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalSimulationDatasetResyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_result_is_immutable_but_equivalent_rerun_reads_corrected_physical_data(): void
    {
        $user = \App\Models\User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'RESYNC', 'exchange' => 'NSE', 'name' => 'Resync Fixture']);
        $this->price($stock, 100);

        $sync = app(DailyMarketSyncService::class);
        $sync->recordSuccessfulSyncAt(Carbon::parse('2026-08-27 14:00:00', 'Asia/Kolkata'));
        $datasetA = DatasetVersion::query()->latest('id')->firstOrFail();

        $runA = $this->createRun($user->id, $profile->id, $stock->id, 'dataset-a');
        app(PortfolioReplayProcessor::class)->process($runA, 5);
        $baseline = PortfolioReplayCheckpoint::query()->where('replay_run_id', $runA->id)->firstOrFail();
        $baselineValue = (float) $baseline->state_after['valuation']['market_value'];
        $baselineFingerprint = $baseline->market_evidence['sha256'];

        // This is the physical effect of a normal corrective upsert. Dataset A
        // remains an immutable attribution row; it does not own the bar.
        StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-02')->update(['close_price' => 120, 'open_price' => 120]);
        $sync->recordSuccessfulSyncAt(Carbon::parse('2026-08-28 14:00:00', 'Asia/Kolkata'));
        $datasetB = DatasetVersion::query()->latest('id')->firstOrFail();

        $this->assertNotSame($datasetA->version_key, $datasetB->version_key);
        $this->assertSame($datasetA->version_key, DatasetVersion::query()->findOrFail($datasetA->id)->version_key);
        $storedA = PortfolioReplayCheckpoint::query()->where('replay_run_id', $runA->id)->firstOrFail();
        $this->assertSame($baselineValue, (float) $storedA->state_after['valuation']['market_value']);
        $this->assertSame($baselineFingerprint, $storedA->market_evidence['sha256']);

        $runB = $this->createRun($user->id, $profile->id, $stock->id, 'dataset-b');
        app(PortfolioReplayProcessor::class)->process($runB, 5);
        $storedB = PortfolioReplayCheckpoint::query()->where('replay_run_id', $runB->id)->firstOrFail();

        $this->assertSame(120.0, (float) $storedB->state_after['valuation']['market_value']);
        $this->assertNotSame($baselineFingerprint, $storedB->market_evidence['sha256']);
        $this->assertNull($runA->fresh()->pinned_world['dataset_version'] ?? null);
    }

    public function test_correction_after_replay_period_does_not_change_replay_result(): void
    {
        $user = \App\Models\User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'FUTURE', 'exchange' => 'NSE', 'name' => 'Future Bar']);
        $this->price($stock, 100);
        StockPrice::query()->create(['stock_id' => $stock->id, 'price_date' => '2026-01-03', 'open_price' => 200, 'high_price' => 200, 'low_price' => 200, 'close_price' => 200, 'data_source' => 'test']);

        $run = $this->createRun($user->id, $profile->id, $stock->id, 'future-bar');
        app(PortfolioReplayProcessor::class)->process($run, 5);
        $before = PortfolioReplayCheckpoint::query()->where('replay_run_id', $run->id)->firstOrFail()->state_after['valuation']['market_value'];
        StockPrice::query()->where('stock_id', $stock->id)->whereDate('price_date', '2026-01-03')->update(['close_price' => 900]);
        $after = PortfolioReplayCheckpoint::query()->where('replay_run_id', $run->id)->firstOrFail()->state_after['valuation']['market_value'];

        $this->assertSame((float) $before, (float) $after);
        $this->assertSame(100.0, (float) $after);
    }

    private function price(Stock $stock, float $close): void
    {
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-02', 'open_price' => $close,
            'high_price' => $close, 'low_price' => $close, 'close_price' => $close, 'data_source' => 'test',
        ]);
    }

    private function createRun(int $userId, int $profileId, int $stockId, string $label): PortfolioReplayRun
    {
        $state = [
            'schema_version' => 1, 'cash_balance' => 0, 'holdings' => [[
                'stock_id' => $stockId, 'strategy_id' => null, 'owner_key' => 'unmanaged', 'quantity' => 1,
                'avg_buy_price' => 100, 'invested_amount' => 100,
            ]], 'strategies' => [], 'reservations' => [], 'loans' => [], 'recalls' => [],
            'recall_bridge_loans' => [], 'transactions' => [], 'pending_recommendations' => [],
        ];
        return PortfolioReplayRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'user_id' => $userId, 'profile_id' => $profileId,
            'starting_mode' => 'historical_branch', 'period_start' => '2026-01-02', 'period_end' => '2026-01-02',
            'price_method' => 'next_open', 'adverse_slippage_percent' => 0, 'status' => 'queued',
            'pinned_world' => ['binding_revisions' => [], 'portfolio_economic_settings' => [], 'fixture' => $label],
            'starting_state' => $state, 'readiness' => ['status' => 'ready'],
        ]);
    }
}
