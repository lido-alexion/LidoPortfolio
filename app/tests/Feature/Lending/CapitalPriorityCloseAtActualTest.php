<?php

namespace Tests\Feature\Lending;

use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalRequestApprovalService;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DEP-CAPITAL-PRIORITY §6.0 — live coordinator path: execute at actual funded amount.
 */
class CapitalPriorityCloseAtActualTest extends TestCase
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

    public function test_scenario1_20k_request_15k_own_4k_recall_executes_19k_without_borrow(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);

        $coord = app(RecommendationLendingCoordinator::class);
        $coord->syncAfterGenerated($rec);
        $rec->refresh();

        $meta = $rec->capitalAllocationMeta();
        $this->assertTrue((bool) ($meta['close_at_actual'] ?? false));
        $this->assertEqualsWithDelta(19_000.0, (float) $meta['actual_execution_amount'], 0.0001);
        $this->assertEqualsWithDelta(19_000.0, (float) $rec->suggestedInvestmentAmount(), 0.0001);
        $this->assertEqualsWithDelta(1_000.0, (float) ($meta['unfunded_amount'] ?? 0), 0.0001);

        $this->assertTrue($coord->canEnterPendingExecution($rec));
        $this->assertTrue($coord->isExecutableAtResolvedActual($rec));

        // Residual capital request may exist for optional top-up — must not block.
        $request = $coord->activeRequestFor($rec);
        $this->assertNotNull($request);

        $updated = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec->fresh(),
            TradingRecommendation::DECISION_APPROVED
        );
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $updated->status);
        $this->assertEqualsWithDelta(19_000.0, (float) $updated->reserved_amount, 0.0001);

        // Without loan commitment, executable stays 19k (not silently 20k).
        $this->assertEqualsWithDelta(19_000.0, (float) $updated->fresh()->suggestedInvestmentAmount(), 0.0001);
        $this->assertNotSame(
            CapitalRequest::STATUS_COMMITTED,
            $coord->activeRequestFor($updated)->status
        );
    }

    public function test_scenario2_own_only_15k_of_20k_executable_without_waiting(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);

        $coord = app(RecommendationLendingCoordinator::class);
        $coord->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertEqualsWithDelta(15_000.0, (float) $rec->capitalAllocationMeta()['actual_execution_amount'], 0.0001);
        $this->assertTrue($coord->canEnterPendingExecution($rec));

        $updated = app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec->fresh(),
            TradingRecommendation::DECISION_APPROVED
        );
        $this->assertEqualsWithDelta(15_000.0, (float) $updated->reserved_amount, 0.0001);
    }

    public function test_scenario3_own_plus_full_recall_funds_20k(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 5_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertEqualsWithDelta(20_000.0, (float) $rec->capitalAllocationMeta()['actual_execution_amount'], 0.0001);
        $this->assertSame(TradingRecommendation::ALLOCATION_FUNDED, $rec->capitalAllocationStatus());
        $this->assertNull(app(RecommendationLendingCoordinator::class)->activeRequestFor($rec));
    }

    public function test_scenario4_and_5_borrow_obtained_vs_not_obtained(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);

        $coord = app(RecommendationLendingCoordinator::class);
        $coord->syncAfterGenerated($rec);
        $rec->refresh();
        $this->assertEqualsWithDelta(19_000.0, (float) $rec->suggestedInvestmentAmount(), 0.0001);

        // Scenario 5: potential request exists, no commitment → still 19k.
        $request = $coord->activeRequestFor($rec);
        $this->assertNotNull($request);
        $this->assertEqualsWithDelta(19_000.0, (float) $rec->capitalAllocationMeta()['actual_execution_amount'], 0.0001);

        // Scenario 4: commit residual loan → executable rises only to requested (20k), not 19k+5k.
        $loan = app(CapitalRequestApprovalService::class)->approve($request, $borrower, $user);
        $this->assertGreaterThan(0, (float) $loan->principal);
        $rec->refresh();
        $this->assertSame(TradingRecommendation::ALLOCATION_CAPITAL_COMMITTED, $rec->capitalAllocationStatus());
        $this->assertEqualsWithDelta(20_000.0, (float) $rec->capitalAllocationMeta()['actual_execution_amount'], 0.0001);
        $this->assertEqualsWithDelta(20_000.0, (float) $rec->suggestedInvestmentAmount(), 0.0001);
        $this->assertEqualsWithDelta(20_000.0, (float) $rec->capitalAllocationMeta()['intended_execution_amount'], 0.0001);
        // ₹5k atomic loan for ₹1k need → excess is not invested.
        $this->assertEqualsWithDelta(
            max(0.0, (float) $loan->principal - 1_000.0),
            (float) $rec->capitalAllocationMeta()['excess_borrowed_amount'],
            0.0001
        );
    }

    public function test_scenario6_executable_never_exceeds_requested_after_commit(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);
        $coord = app(RecommendationLendingCoordinator::class);
        $coord->syncAfterGenerated($rec);
        $request = $coord->activeRequestFor($rec->fresh());
        app(CapitalRequestApprovalService::class)->approve($request, $borrower, $user);
        $rec->refresh();

        $this->assertLessThanOrEqual(20_000.0001, (float) $rec->suggestedInvestmentAmount());
        $this->assertLessThanOrEqual(20_000.0001, (float) $rec->capitalAllocationMeta()['actual_execution_amount']);
    }

    public function test_sync_idempotent_does_not_inflate_19k_to_20k(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);
        $coord = app(RecommendationLendingCoordinator::class);
        $coord->syncAfterGenerated($rec);
        $first = (float) $rec->fresh()->capitalAllocationMeta()['actual_execution_amount'];
        $coord->syncAfterGenerated($rec->fresh());
        $second = (float) $rec->fresh()->capitalAllocationMeta()['actual_execution_amount'];

        $this->assertEqualsWithDelta(19_000.0, $first, 0.0001);
        $this->assertEqualsWithDelta(19_000.0, $second, 0.0001);
        $this->assertSame(1, CapitalRequest::query()->where('recommendation_id', $rec->id)->count());
    }

    private function makeBuyRecommendation(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        array $allocation,
    ): TradingRecommendation {
        $stock = Stock::query()->create([
            'symbol' => 'CP'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Priority',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $plan = [
            'target_investment_amount' => $allocation['target_amount'],
            'suggested_investment_amount' => $allocation['allocated_amount'],
            'capital_allocation' => $allocation,
        ];

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $strategy->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => $allocation['allocated_amount'],
            'execution_plan' => $plan,
            'evidence' => ['capital_allocation' => $allocation],
            'generated_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'CapPrio', true);
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

    private function createLoan(
        PortfolioProfile $profile,
        TradingStrategy $lender,
        TradingStrategy $borrower,
        float $principal,
        $committedAt = null,
    ): CapitalLoan {
        $stock = Stock::query()->create([
            'symbol' => 'CL'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Loan',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
        ]);
        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => $principal,
            'status' => CapitalRequest::STATUS_COMMITTED,
            'approved_at' => now(),
        ]);

        return CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => $principal,
            'outstanding' => $principal,
            'committed_at' => $committedAt ?? now(),
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);
    }

    private function makeStrategy($profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'status' => TradingStrategy::STATUS_DRAFT,
            'allocation_pct' => 0,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'config_json' => ['recommended_minimum_holdings' => 1],
            'is_active' => true,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }
}
