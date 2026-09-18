<?php

namespace Tests\Feature;

use App\Models\RecommendationReservationEvent;
use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use App\Models\CapitalRequest;
use App\Models\CapitalRecall;
use App\Models\RecallBridgeLoan;
use App\Models\RecallBridgeLoanReturn;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Services\Simulation\HistoricalReplayStateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalReplayTemporalStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_events_reconstruct_lifecycle_at_branch_boundary_not_current_row_state(): void
    {
        $user = \App\Models\User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'TEMP', 'exchange' => 'NSE', 'name' => 'Temporal']);

        $activeAtBoundary = $this->recommendation($profile->id, $stock->id, 'converted');
        $releasedBeforeBoundary = $this->recommendation($profile->id, $stock->id, 'released');
        $convertedAfterBoundary = $this->recommendation($profile->id, $stock->id, 'converted');
        foreach ([$activeAtBoundary, $releasedBeforeBoundary, $convertedAfterBoundary] as $recommendation) {
            RecommendationReservationEvent::query()->create([
                'profile_id' => $profile->id, 'recommendation_id' => $recommendation->id,
                'state' => RecommendationReservationEvent::STATE_RESERVED, 'amount' => 100,
                'occurred_at' => '2026-01-01 10:00:00', 'created_at' => '2026-01-01 10:00:00',
            ]);
        }
        RecommendationReservationEvent::query()->create([
            'profile_id' => $profile->id, 'recommendation_id' => $activeAtBoundary->id,
            'state' => RecommendationReservationEvent::STATE_CONVERTED, 'amount' => 100,
            'occurred_at' => '2026-01-04 10:00:00', 'created_at' => '2026-01-04 10:00:00',
        ]);
        RecommendationReservationEvent::query()->create([
            'profile_id' => $profile->id, 'recommendation_id' => $releasedBeforeBoundary->id,
            'state' => RecommendationReservationEvent::STATE_RELEASED, 'amount' => 100,
            'occurred_at' => '2026-01-02 10:00:00', 'created_at' => '2026-01-02 10:00:00',
        ]);

        $built = app(HistoricalReplayStateBuilder::class)->build($profile, '2026-01-03');
        $ids = collect($built['state']['reservations'])->pluck('recommendation_id')->all();

        $this->assertContains($activeAtBoundary->id, $ids);
        $this->assertNotContains($releasedBeforeBoundary->id, $ids);
        $this->assertContains($convertedAfterBoundary->id, $ids);
    }

    public function test_loan_and_bridge_balances_use_returns_effective_at_boundary(): void
    {
        $user = \App\Models\User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'LOAN', 'exchange' => 'NSE', 'name' => 'Loan']);
        $borrower = TradingStrategy::query()->create(['profile_id' => $profile->id, 'name' => 'Borrower', 'slug' => 'borrower', 'status' => TradingStrategy::STATUS_ACTIVE, 'allocation_pct' => 50]);
        $lender = TradingStrategy::query()->create(['profile_id' => $profile->id, 'name' => 'Lender', 'slug' => 'lender', 'status' => TradingStrategy::STATUS_ACTIVE, 'allocation_pct' => 50]);
        $recommendation = $this->recommendation($profile->id, $stock->id, 'none');
        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id, 'borrower_strategy_id' => $borrower->id, 'lender_strategy_id' => $lender->id,
            'recommendation_id' => $recommendation->id, 'amount' => 10000, 'status' => CapitalRequest::STATUS_COMMITTED,
            'approved_at' => '2026-01-01 09:00:00',
        ]);
        $loan = CapitalLoan::query()->create([
            'profile_id' => $profile->id, 'capital_request_id' => $request->id, 'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id, 'principal' => 10000, 'outstanding' => 0,
            'committed_at' => '2026-01-01 10:00:00', 'status' => CapitalLoan::STATUS_RETURNED,
        ]);
        CapitalLoanReturn::query()->create(['loan_id' => $loan->id, 'capital_request_id' => $request->id, 'amount' => 10000, 'returned_at' => '2026-01-07 10:00:00', 'created_at' => '2026-01-07 10:00:00']);
        $recall = CapitalRecall::query()->create([
            'profile_id' => $profile->id, 'loan_id' => $loan->id, 'lender_strategy_id' => $lender->id,
            'borrower_strategy_id' => $borrower->id, 'kind' => CapitalRecall::KIND_FULL, 'recall_amount' => 5000,
            'outstanding_recall_amount' => 0, 'state' => CapitalRecall::STATE_REQUESTED, 'requested_at' => '2026-01-01 11:00:00',
        ]);
        $bridge = RecallBridgeLoan::query()->create([
            'profile_id' => $profile->id, 'capital_recall_id' => $recall->id, 'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id, 'principal' => 5000, 'outstanding' => 0,
            'committed_at' => '2026-01-01 12:00:00', 'status' => RecallBridgeLoan::STATUS_RETURNED,
        ]);
        RecallBridgeLoanReturn::query()->create(['bridge_loan_id' => $bridge->id, 'amount' => 5000, 'returned_at' => '2026-01-07 12:00:00', 'created_at' => '2026-01-07 12:00:00']);

        $built = app(HistoricalReplayStateBuilder::class)->build($profile, '2026-01-03');
        $this->assertSame(10000.0, (float) $built['state']['loans'][0]['outstanding']);
        $this->assertSame(CapitalLoan::STATUS_OUTSTANDING, $built['state']['loans'][0]['status']);
        $this->assertSame(5000.0, (float) $built['state']['recall_bridge_loans'][0]['outstanding']);
        $this->assertSame(RecallBridgeLoan::STATUS_OUTSTANDING, $built['state']['recall_bridge_loans'][0]['status']);
    }

    private function recommendation(int $profileId, int $stockId, string $currentReservationState): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profileId, 'security_id' => $stockId,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'suggested_position_size' => 1, 'suggested_allocation_amount' => 100,
            'reserved_amount' => 0, 'reservation_status' => $currentReservationState,
            'approved_at' => '2026-01-01 09:00:00', 'generated_at' => '2026-01-01 08:00:00',
        ]);
    }
}
