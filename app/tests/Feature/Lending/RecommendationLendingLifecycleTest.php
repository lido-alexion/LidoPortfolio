<?php

namespace Tests\Feature\Lending;

use App\Engines\Recommendation\Allocation\ReturnQualityCapitalAllocator;
use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\CashLedgerEntry;
use App\Models\Holding;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalRequestApprovalService;
use App\Services\Lending\PartialLendingAmountCalculator;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecommendationLendingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withHeaders([
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost',
        ]);
    }

    public function test_partially_funded_creates_correctly_sized_capital_request(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $target = 18000.0;
        $own = 10000.0;
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => $target,
            'allocated_amount' => $own,
            'unfunded_amount' => 8000.0,
            'atomic_reservation' => ReturnQualityCapitalAllocator::atomicAllocation($target),
        ]);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec->fresh());

        $this->assertNotNull($request);
        $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->status);
        $this->assertNull($request->lender_strategy_id);
        $this->assertSame((int) $profile->id, (int) $request->profile_id);
        $this->assertSame((int) $borrower->id, (int) $request->borrower_strategy_id);
        $this->assertSame((int) $rec->id, (int) $request->recommendation_id);
        $this->assertEqualsWithDelta(10000.0, (float) $request->amount, 0.0001);
        $this->assertSame(
            $ledgerBefore,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
    }

    public function test_partial_loan_is_target_minus_own_ceiled_to_5000(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $target = 18000.0;
        $own = 10000.0;
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => $target,
            'allocated_amount' => $own,
            'unfunded_amount' => 8000.0,
            'atomic_reservation' => 20000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);

        $calc = app(PartialLendingAmountCalculator::class);
        $this->assertEqualsWithDelta(8000.0, $calc->remainderFromTargetAndOwn($target, $own), 0.0001);
        $this->assertEqualsWithDelta(10000.0, $calc->calculateForPartialRemainder($target, $own), 0.0001);
        $this->assertEqualsWithDelta(10000.0, (float) $request->amount, 0.0001);
    }

    public function test_partial_loan_does_not_use_atomic_or_od06(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $target = 18000.0;
        $own = 3000.0;
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => $target,
            'allocated_amount' => $own,
            'unfunded_amount' => 15000.0,
            'atomic_reservation' => ReturnQualityCapitalAllocator::atomicAllocation($target),
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $amount = (float) app(RecommendationLendingCoordinator::class)->activeRequestFor($rec)->amount;

        $this->assertEqualsWithDelta(15000.0, $amount, 0.0001);
        $this->assertNotEquals(ReturnQualityCapitalAllocator::atomicAllocation($target) - $own, $amount);
        $this->assertNotEquals(ReturnQualityCapitalAllocator::atomicAllocation(15000.0), $amount);
    }

    public function test_partially_funded_stays_open_while_awaiting_lender(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 8000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->status);
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertEqualsWithDelta(18000.0, $rec->capitalTargetAmount(), 0.0001);
        $this->assertEqualsWithDelta(10000.0, $rec->ownAllocatedAmount(), 0.0001);
    }

    public function test_unfunded_with_eligible_lender_awaits_selection_and_cannot_enter_pending_execution(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 18000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertSame(1, CapitalRequest::query()->where('recommendation_id', $rec->id)->count());
        $this->assertFalse(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));

        $this->expectException(ValidationException::class);
        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );
    }

    public function test_trade_approval_allowed_at_resolved_actual_without_waiting_for_residual_lend(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 8000.0,
        ]);
        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        // §6.0: optional residual capital request may exist, but must not block execute-at-actual.
        $this->assertTrue(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));
        $this->assertNotNull(app(RecommendationLendingCoordinator::class)->activeRequestFor($rec));
        $this->assertEqualsWithDelta(10000.0, (float) $rec->suggestedInvestmentAmount(), 0.0001);

        $updated = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec->fresh(),
            TradingRecommendation::DECISION_APPROVED
        );
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $updated->status);
        $this->assertEqualsWithDelta(10000.0, (float) $updated->reserved_amount, 0.0001);
    }

    public function test_fully_funded_approval_still_enters_pending_execution(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_FUNDED,
            'target_amount' => 10000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 0.0,
        ], 10000.0);

        $updated = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $updated->status);
        $this->assertEqualsWithDelta(10000.0, (float) $updated->reserved_amount, 0.0001);
        $this->assertSame(0, CapitalRequest::query()->where('recommendation_id', $rec->id)->count());
    }

    public function test_committed_loan_makes_recommendation_eligible_but_does_not_execute(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 8000.0,
        ]);
        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();
        $holdingsBefore = Holding::query()->where('profile_id', $profile->id)->count();
        $borrowerPct = (float) $borrower->allocation_pct;
        $snapBefore = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $byBefore = collect($snapBefore['strategies'])->keyBy('strategy_id');

        $loan = app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);
        $rec->refresh();

        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->status);
        $this->assertSame(TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED, $rec->capitalAllocationStatus());
        $this->assertTrue(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));
        $this->assertSame((int) $loan->id, (int) $rec->capitalAllocationMeta()['capital_loan_id']);
        $this->assertSame(
            $ledgerBefore,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
        $this->assertSame($holdingsBefore, Holding::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsWithDelta($borrowerPct, (float) $borrower->fresh()->allocation_pct, 0.0001);

        $snapAfter = app(PortfolioCapitalAccountingService::class)->snapshot($profile);
        $byAfter = collect($snapAfter['strategies'])->keyBy('strategy_id');
        $this->assertEqualsWithDelta(
            (float) $byBefore[$lender->id]['lent_capital'] + 10000.0,
            (float) $byAfter[$lender->id]['lent_capital'],
            0.0001
        );
        $this->assertEqualsWithDelta(
            (float) $byBefore[$borrower->id]['borrowed_capital'] + 10000.0,
            (float) $byAfter[$borrower->id]['borrowed_capital'],
            0.0001
        );
        $this->assertEqualsWithDelta((float) $loan->outstanding, (float) $byAfter[$lender->id]['lent_capital'], 0.0001);
    }

    public function test_duplicate_lending_request_creation_is_idempotent(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 8000.0,
        ]);
        $coordinator = app(RecommendationLendingCoordinator::class);
        $coordinator->syncAfterGenerated($rec);
        $coordinator->syncAfterGenerated($rec->fresh());
        $first = $coordinator->activeRequestFor($rec);
        $second = app(\App\Services\Lending\CapitalRequestService::class)
            ->createRequest($profile, $rec->fresh(), $borrower, 10000.0);

        $this->assertSame(1, CapitalRequest::query()->where('recommendation_id', $rec->id)->count());
        $this->assertSame((int) $first->id, (int) $second->id);
    }

    public function test_sell_approval_is_unchanged(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower, TradingRecommendation::ACTION_EXIT_POSITION, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 0.0,
            'allocated_amount' => 0.0,
        ], null);

        $updated = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );

        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $updated->status);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Lending Life', true);
        $first = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $second = $this->makeStrategy($profile, 'Strategy B');
        app(StrategyRegistrySupport::class)->activate($profile, $second);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 75],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertOk();

        return [$user, $profile, $first->fresh(['activeVersion']), $second->fresh(['activeVersion'])];
    }

    /**
     * @param  array<string, mixed>  $capital
     */
    private function makeBuyRecommendation(
        $profile,
        TradingStrategy $borrower,
        array $capital,
        ?float $suggestedAmount = null,
    ): TradingRecommendation {
        return $this->makeRecommendation(
            $profile,
            $borrower,
            TradingRecommendation::ACTION_OPEN_POSITION,
            $capital,
            $suggestedAmount ?? (float) $capital['allocated_amount']
        );
    }

    /**
     * @param  array<string, mixed>  $capital
     */
    private function makeRecommendation(
        $profile,
        TradingStrategy $borrower,
        string $action,
        array $capital,
        ?float $suggestedAmount,
    ): TradingRecommendation {
        $stock = Stock::query()->create([
            'symbol' => 'LF'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Lifecycle Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $plan = [
            'target_investment_amount' => $capital['target_amount'] ?? 0,
            'suggested_investment_amount' => $suggestedAmount ?? ($capital['allocated_amount'] ?? 0),
            'capital_allocation' => $capital,
        ];

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => $action,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => $suggestedAmount,
            'execution_plan' => $plan,
            'evidence' => ['capital_allocation' => $capital],
            'generated_at' => now(),
        ]);
    }

    private function makeStrategy($profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 100,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }
}
