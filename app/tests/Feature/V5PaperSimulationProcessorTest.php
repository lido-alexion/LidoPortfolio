<?php

namespace Tests\Feature;

use App\Models\PaperExecutionEvent;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Simulation\PaperSimulationProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V5PaperSimulationProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_processor_uses_effective_session_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open', 'created_at' => '2026-01-01 10:00:00',
        ])->save();
        app(CashManagementService::class)->deposit($paper, 1000, 'Paper starting cash', $user, '2026-01-01');
        $stock = Stock::query()->create(['symbol' => 'PAPER', 'exchange' => 'NSE', 'name' => 'Paper Fill']);
        StockPrice::query()->create([
            'stock_id' => $stock->id, 'price_date' => '2026-01-02',
            'open_price' => 100, 'high_price' => 110, 'low_price' => 95, 'close_price' => 105,
            'data_source' => 'test', 'created_at' => '2026-01-02 18:00:00',
        ]);
        $recommendation = TradingRecommendation::query()->create([
            'profile_id' => $paper->id, 'security_id' => $stock->id,
            'recommendation_type' => 'OPEN_POSITION', 'status' => 'pending_review',
            'suggested_position_size' => 4, 'confidence' => 80, 'risk_level' => 'medium',
            'generated_at' => '2026-01-01 18:00:00', 'first_eligible_execution_date' => '2026-01-02',
            'evidence' => ['artifact_world' => 'pinned-test'],
        ]);

        $first = app(PaperSimulationProcessor::class)->process($paper->fresh(), '2026-01-02');
        $this->assertSame('current', $first['status']);
        $this->assertSame(1, $first['fills']);
        $event = PaperExecutionEvent::query()->firstOrFail();
        $this->assertSame('2026-01-02', $event->effective_session_date->toDateString());
        $this->assertSame(4.0, (float) $event->executed_quantity);
        $this->assertSame('paper_simulation', $event->evidence['provenance']);
        $this->assertSame('executed', $recommendation->fresh()->status);
        $this->assertSame('2026-01-02', $recommendation->fresh()->executedTransaction->transaction_date->toDateString());
        $this->assertLessThan(600.0, app(CashManagementService::class)->balance($paper));
        $this->assertStringStartsWith('settings-sha256:', $event->evidence['charge_model']['version']);
        $this->assertGreaterThan(0, $event->evidence['charge_model']['total']);

        $second = app(PaperSimulationProcessor::class)->process($paper->fresh(), '2026-01-02');
        $this->assertSame(0, $second['fills']);
        $this->assertSame(1, PaperExecutionEvent::query()->count());
    }

    public function test_missing_session_price_waits_without_advancing_checkpoint(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open', 'created_at' => '2026-01-01 10:00:00',
        ])->save();
        $stock = Stock::query()->create(['symbol' => 'WAIT', 'exchange' => 'NSE', 'name' => 'Waiting']);
        TradingRecommendation::query()->create([
            'profile_id' => $paper->id, 'security_id' => $stock->id,
            'recommendation_type' => 'OPEN_POSITION', 'status' => 'pending_review',
            'suggested_position_size' => 1, 'generated_at' => '2026-01-01 18:00:00',
            'first_eligible_execution_date' => '2026-01-02',
        ]);

        $result = app(PaperSimulationProcessor::class)->process($paper->fresh(), '2026-01-02');
        $this->assertSame('waiting', $result['status']);
        $this->assertSame('2026-01-01', $paper->fresh()->simulation_checkpoint_date->toDateString());
        $this->assertSame('waiting', $paper->fresh()->simulation_state);
        $this->assertSame(0, PaperExecutionEvent::query()->count());
    }

    public function test_partial_affordable_progress_continues_without_duplicate_economics(): void
    {
        $user = User::factory()->create();
        $paper = $this->defaultPortfolioFor($user);
        $paper->forceFill([
            'portfolio_type' => 'paper', 'simulation_state' => 'active',
            'simulation_price_method' => 'next_open', 'created_at' => '2026-01-01 10:00:00',
        ])->save();
        $cash = app(CashManagementService::class);
        $cash->deposit($paper, 250, 'Paper starting cash', $user, '2026-01-01');
        $stock = Stock::query()->create(['symbol' => 'PART', 'exchange' => 'NSE', 'name' => 'Partial']);
        foreach (['2026-01-02', '2026-01-05'] as $date) {
            StockPrice::query()->create([
                'stock_id' => $stock->id, 'price_date' => $date, 'open_price' => 100,
                'high_price' => 105, 'low_price' => 95, 'close_price' => 100,
                'data_source' => 'test', 'created_at' => $date.' 18:00:00',
            ]);
        }
        $recommendation = TradingRecommendation::query()->create([
            'profile_id' => $paper->id, 'security_id' => $stock->id,
            'recommendation_type' => 'OPEN_POSITION', 'status' => 'pending_review',
            'suggested_position_size' => 4, 'generated_at' => '2026-01-01 18:00:00',
            'first_eligible_execution_date' => '2026-01-02',
        ]);

        app(PaperSimulationProcessor::class)->process($paper->fresh(), '2026-01-02');
        $this->assertSame('partial', PaperExecutionEvent::query()->firstOrFail()->status);
        $this->assertSame('pending_execution', $recommendation->fresh()->status);
        $this->assertSame(200.0, (float) $recommendation->fresh()->remaining_target_amount);

        $cash->deposit($paper, 300, 'Investor intervention', $user, '2026-01-03');
        app(PaperSimulationProcessor::class)->process($paper->fresh(), '2026-01-05');
        $this->assertSame('executed', $recommendation->fresh()->status);
        $this->assertSame(400.0, (float) $recommendation->fresh()->external_executed_amount);
        $this->assertSame(0.0, (float) $recommendation->fresh()->remaining_target_amount);
        $this->assertSame(2, PaperExecutionEvent::query()->count());
        $this->assertSame(2.0, $recommendation->fresh()->executedTransaction->quantity + 0);
    }
}
