<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\PartialLendingAmountCalculator;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Lending\UnfundedLendingAmountCalculator;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Residual UNFUNDED zero-own lending offer (DEP-PARTIAL-ATOMIC).
 */
class UnfundedZeroOwnLendingTest extends TestCase
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

    public function test_zero_own_with_eligible_lender_awaits_lender_selection(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $bridgeBefore = RecallBridgeLoan::query()->count();
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 18000.0,
        ]);
        $targetBefore = $rec->capitalTargetAmount();
        $actualBefore = (float) ($rec->capitalAllocationMeta()['actual_execution_amount'] ?? 0);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertNotSame(TradingRecommendation::ACTION_WATCH, $rec->recommendation_type);
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertSame(TradingRecommendation::ALLOCATION_UNFUNDED, $rec->capitalAllocationMeta()['own_funding_status'] ?? null);
        $this->assertNotNull($request);
        $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->status);
        $this->assertNull($request->lender_strategy_id);
        $this->assertEqualsWithDelta(20000.0, (float) $request->amount, 0.0001);
        $this->assertEqualsWithDelta($targetBefore, $rec->capitalTargetAmount(), 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rec->ownAllocatedAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            $actualBefore,
            (float) ($rec->capitalAllocationMeta()['actual_execution_amount'] ?? 0),
            0.0001
        );
        $this->assertSame($bridgeBefore, RecallBridgeLoan::query()->count());
        $this->assertFalse(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));
    }

    public function test_zero_own_with_no_eligible_lender_remains_unfunded(): void
    {
        [, $profile, $borrower] = $this->oneStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 18000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
        $this->assertSame(TradingRecommendation::ALLOCATION_UNFUNDED, $rec->capitalAllocationStatus());
        $this->assertSame(0, CapitalRequest::query()->where('recommendation_id', $rec->id)->count());
        $this->assertNull(app(RecommendationLendingCoordinator::class)->activeRequestFor($rec));
        $this->assertFalse(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));
    }

    #[DataProvider('gapLoanCases')]
    public function test_zero_own_loan_uses_dep_partial_atomic(float $gap, float $expectedLoan): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => $gap,
            'allocated_amount' => 0.0,
            'unfunded_amount' => $gap,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec->fresh());

        $this->assertNotNull($request);
        $this->assertEqualsWithDelta($expectedLoan, (float) $request->amount, 0.0001);
        $this->assertEqualsWithDelta(
            $expectedLoan,
            (new UnfundedLendingAmountCalculator)->calculateForUnfundedGap($gap),
            0.0001
        );
        $this->assertEqualsWithDelta($gap, $rec->fresh()->capitalTargetAmount(), 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rec->fresh()->ownAllocatedAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            0.0,
            (float) ($rec->fresh()->capitalAllocationMeta()['actual_execution_amount'] ?? 0),
            0.0001
        );
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public static function gapLoanCases(): array
    {
        return [
            [5000.0, 5000.0],
            [3000.0, 5000.0],
            [5001.0, 10000.0],
            [20000.0, 20000.0],
        ];
    }

    public function test_zero_own_path_does_not_alter_partial_own_lending(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $target = 18000.0;
        $own = 10000.0;
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => $target,
            'allocated_amount' => $own,
            'unfunded_amount' => 8000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);

        $calc = app(PartialLendingAmountCalculator::class);
        $this->assertNotNull($request);
        $this->assertEqualsWithDelta(10000.0, (float) $request->amount, 0.0001);
        $this->assertEqualsWithDelta(10000.0, $calc->calculateForPartialRemainder($target, $own), 0.0001);
        $this->assertEqualsWithDelta($own, (float) $rec->ownAllocatedAmount(), 0.0001);
        $this->assertEqualsWithDelta($target, $rec->capitalTargetAmount(), 0.0001);
        $this->assertEqualsWithDelta(
            $own,
            (float) ($rec->capitalAllocationMeta()['actual_execution_amount'] ?? 0),
            0.0001
        );
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertSame(
            TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            $rec->capitalAllocationMeta()['own_funding_status'] ?? null
        );
        $this->assertTrue(app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec));
    }

    public function test_zero_own_path_does_not_skip_recall_before_lending(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));
        $bridgeBefore = RecallBridgeLoan::query()->count();

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 8000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 8000.0,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);
        $meta = $rec->capitalAllocationMeta() ?? [];

        $this->assertEqualsWithDelta(4_000.0, (float) ($meta['actual_execution_amount'] ?? 0), 0.0001);
        $this->assertEqualsWithDelta(4_000.0, (float) ($meta['recalled_amount'] ?? 0), 0.0001);
        $this->assertEqualsWithDelta(4_000.0, (float) $rec->ownAllocatedAmount(), 0.0001);
        $this->assertNotSame(TradingRecommendation::ALLOCATION_UNFUNDED, $rec->capitalAllocationStatus());
        $this->assertNotNull($request);
        $this->assertEqualsWithDelta(5000.0, (float) $request->amount, 0.0001);
        $this->assertNotEquals(10000.0, (float) $request->amount);
        $this->assertSame($bridgeBefore, RecallBridgeLoan::query()->count());
        $this->assertSame(TradingRecommendation::ACTION_OPEN_POSITION, $rec->recommendation_type);
    }

    public function test_zero_own_increase_stays_actionable_not_watch(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower, TradingRecommendation::ACTION_INCREASE_POSITION, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 12000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 12000.0,
        ], 0.0);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec->refresh();

        $this->assertSame(TradingRecommendation::ACTION_INCREASE_POSITION, $rec->recommendation_type);
        $this->assertNotSame(TradingRecommendation::ACTION_WATCH, $rec->recommendation_type);
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertEqualsWithDelta(
            15000.0,
            (float) app(RecommendationLendingCoordinator::class)->activeRequestFor($rec)->amount,
            0.0001
        );
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3?: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Zero Own Lending', true);
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
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy}
     */
    private function oneStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Zero Own Solo', true);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);
        $this->actingAs($user)->withProfileHeader($user, $profile);

        return [$user, $profile, $strategy->fresh(['activeVersion'])];
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
            'symbol' => 'ZO'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Zero Own Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $plan = [
            'target_investment_amount' => $capital['target_amount'] ?? 0,
            'suggested_investment_amount' => $suggestedAmount ?? ($capital['allocated_amount'] ?? 0),
            'this_cycle_amount' => $capital['target_amount'] ?? 0,
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

    private function createLoan(
        PortfolioProfile $profile,
        TradingStrategy $lender,
        TradingStrategy $borrower,
        float $principal,
        $committedAt = null,
    ): CapitalLoan {
        $stock = Stock::query()->create([
            'symbol' => 'ZL'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Zero Own Loan',
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
