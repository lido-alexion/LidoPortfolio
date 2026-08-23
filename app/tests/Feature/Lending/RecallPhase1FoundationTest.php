<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalLoanRepaymentService;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\RecallEligibilityService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallPeriodResolver;
use App\Services\Lending\RecallService;
use App\Services\Lending\RecallBridgeLoanService;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class RecallPhase1FoundationTest extends TestCase
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

    public function test_dynamic_eligibility_uses_current_period_without_restarting_clock(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, now()->subDays(10));
        $eligibility = app(RecallEligibilityService::class);
        $periods = app(RecallPeriodResolver::class);

        $this->assertFalse($eligibility->isLoanEligible($loan, $profile, now()));
        $periods->setPortfolioOverride($profile, 7);
        $this->assertTrue($eligibility->isLoanEligible($loan, $profile, now()));
        $this->assertTrue(
            Carbon::parse($loan->committed_at)->equalTo(Carbon::parse($loan->fresh()->committed_at))
        );
    }

    public function test_default_period_is_14_days(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, now()->subDays(13));
        $eligibility = app(RecallEligibilityService::class);
        $this->assertFalse($eligibility->isLoanEligible($loan, $profile, now()));
        $loan13 = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(14));
        // second loan needs different capital request — createLoan always creates new
        $this->assertTrue($eligibility->isLoanEligible($loan13, $profile, now()));
        $this->assertSame(14, app(RecallPeriodResolver::class)->effectivePeriodDays($profile));
    }

    public function test_one_active_recall_and_cannot_cancel(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loanA = $this->createLoan($profile, $lender, $borrower, 50_000, now()->subDays(20));
        $loanB = $this->createLoan($profile, $lender, $borrower, 25_000, now()->subDays(20));
        $svc = app(RecallService::class);

        $recall = $svc->requestFull($profile, $loanA);
        $this->assertSame(CapitalRecall::STATE_REQUESTED, $recall->state);

        try {
            $svc->requestFull($profile, $loanB);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertTrue(true);
        }

        $this->expectException(LogicException::class);
        $svc->cancel($recall);
    }

    public function test_follow_up_cooldown_is_floor_period_over_two(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        app(RecallPeriodResolver::class)->setPortfolioOverride($profile, 14);
        $this->assertSame(7, app(RecallPeriodResolver::class)->followUpCooldownDays($profile));

        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, now()->subDays(30));
        $svc = app(RecallService::class);
        $recall = $svc->requestFull($profile, $loan);
        $svc->markCompleted($recall, now()->subDays(3));

        $eligibility = app(RecallEligibilityService::class);
        $this->assertFalse($eligibility->isFollowUpCooldownElapsed($profile, now()));
        $this->assertTrue($eligibility->isFollowUpCooldownElapsed($profile, now()->addDays(4)));
    }

    public function test_immediate_apply_settles_and_creates_bridge_when_needed(): void
    {
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $result = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            10_000,
            5_500,
            $bridge,
        );

        $this->assertTrue($result['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, (float) $result['recall']->settled_amount, 0.0001);
        $this->assertEqualsWithDelta(5_000.0, (float) $result['recall']->outstanding_recall_amount, 0.0001);
        $this->assertContains($result['recall']->state, [
            CapitalRecall::STATE_PENDING_HELD,
            CapitalRecall::STATE_LIQUIDATION,
        ]);
        $this->assertNotNull($result['bridge_loan']);
        $this->assertNotNull($result['fulfilment']);
        $this->assertEqualsWithDelta(5_000.0, (float) $result['bridge_loan']->outstanding, 0.0001);
        $this->assertEqualsWithDelta(5_000.0, (float) $loan->fresh()->outstanding, 0.0001);
    }

    public function test_below_threshold_goes_pending_held_without_bridge(): void
    {
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $result = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            10_000,
            2_200,
            $bridge,
        );

        $this->assertFalse($result['evaluation']['allows_immediate']);
        $this->assertContains($result['recall']->state, [
            CapitalRecall::STATE_PENDING_HELD,
            CapitalRecall::STATE_LIQUIDATION,
        ]);
        $this->assertNull($result['bridge_loan']);
        $this->assertNotNull($result['fulfilment']);
        $this->assertEqualsWithDelta(20_000.0, (float) $loan->fresh()->outstanding, 0.0001);
        $this->assertSame(0, RecallBridgeLoan::query()->count());
    }

    public function test_bridge_cannot_be_recalled_and_repay_any_amount(): void
    {
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $bridgeLoan = app(RecallBridgeLoanService::class)->create(
            $profile,
            $recall,
            $bridge,
            4_321.5,
            [
                'borrower_own_cash' => 0.0,
                'liquidatable_stock_value' => 100_000.0,
                'lender_available_override' => 100_000.0,
            ],
        );

        app(RecallBridgeLoanService::class)->repay($bridgeLoan, 1_321.5);
        $this->assertEqualsWithDelta(3_000.0, (float) $bridgeLoan->fresh()->outstanding, 0.0001);

        $this->expectException(LogicException::class);
        app(RecallBridgeLoanService::class)->recall($bridgeLoan->fresh());
    }

    public function test_capital_resolution_own_then_recall_closes_at_actual(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        // Lender has lent 4k eligible for full recall
        $loan = $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $result = app(CapitalResolutionService::class)->resolveForStrategy(
            $profile,
            $lender,
            20_000,
            [
                // Force own available for the resolving (lender) strategy
                // strategy_available_capital comes from accounting — override borrower cash for settlement
                'borrower_own_cash_overrides' => [
                    (int) $borrower->id => 4_000,
                ],
                'liquidatable_stock_overrides' => [
                    (int) $borrower->id => 0,
                ],
            ],
        );

        // Without forcing lender own capital, accounting depends on allocations.
        // Assert structural guarantees:
        $this->assertTrue($result['close_at_actual']);
        $this->assertFalse($result['hold_for_remainder']);
        $this->assertEqualsWithDelta(
            $result['own_used'] + $result['recalled_amount'],
            $result['actual_available'],
            0.0001
        );
        $this->assertLessThanOrEqual($result['required_amount'] + 0.0001, $result['actual_available']);
    }

    public function test_capital_resolution_example_15k_own_plus_4k_recall(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->assertNotNull($user);
        $loan = $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));
        $result = app(CapitalResolutionService::class)->resolveForStrategy($profile, $lender, 20_000, [
            'own_available_override' => 15_000,
            'borrower_own_cash_overrides' => [(int) $borrower->id => 4_000],
            'liquidatable_stock_overrides' => [(int) $borrower->id => 0],
        ]);

        $this->assertTrue($result['close_at_actual']);
        $this->assertFalse($result['hold_for_remainder']);
        $this->assertNotEmpty($result['recalls']);
        $this->assertSame((int) $loan->id, (int) $result['recalls'][0]['loan_id']);
        $this->assertTrue($result['recalls'][0]['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, $result['own_used'], 0.0001);
        $this->assertEqualsWithDelta(4_000.0, $result['recalled_amount'], 0.0001);
        $this->assertEqualsWithDelta(19_000.0, $result['actual_available'], 0.0001);
        $this->assertEqualsWithDelta(1_000.0, $result['borrow_shortfall'], 0.0001);
    }

    public function test_normal_repayment_regression_12000(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 12_000);
        app(CapitalLoanRepaymentService::class)->repay($loan, 12_000);
        $this->assertEqualsWithDelta(0.0, (float) $loan->fresh()->outstanding, 0.0001);
        $this->assertSame(CapitalLoan::STATUS_RETURNED, $loan->fresh()->status);

        $loan2 = $this->createLoan($profile, $lender, $borrower, 12_000);
        $this->expectException(ValidationException::class);
        app(CapitalLoanRepaymentService::class)->repay($loan2, 15_000);
    }

    public function test_sale_proceeds_not_available_until_delay(): void
    {
        [, $profile, , $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $soldAt = now();
        $row = app(SaleProceedsAvailabilityService::class)->schedule(
            $profile,
            $borrower,
            10_000,
            $soldAt,
        );
        $svc = app(SaleProceedsAvailabilityService::class);
        $this->assertFalse($svc->isPhysicallyAvailable($row, $soldAt->copy()->addHours(12)));
        $this->assertTrue($svc->isPhysicallyAvailable($row, $soldAt->copy()->addDays(1)));
        $refreshed = $svc->refreshStatus($row, $soldAt->copy()->addDays(1));
        $this->assertSame(PendingSaleProceeds::STATUS_AVAILABLE, $refreshed->status);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Recall', true);
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
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy, 4: TradingStrategy}
     */
    private function threeStrategyPortfolio(float $cash): array
    {
        [$user, $profile, $first, $second] = $this->twoStrategyPortfolio($cash);
        $third = $this->makeStrategy($profile, 'Strategy C');
        app(StrategyRegistrySupport::class)->activate($profile, $third);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/allocations', [
                'allocations' => [
                    ['strategy_id' => $first->id, 'allocation_pct' => 50],
                    ['strategy_id' => $second->id, 'allocation_pct' => 25],
                    ['strategy_id' => $third->id, 'allocation_pct' => 25],
                ],
            ])
            ->assertOk();

        return [$user, $profile, $first->fresh(), $second->fresh(), $third->fresh(['activeVersion'])];
    }

    private function createLoan(
        PortfolioProfile $profile,
        TradingStrategy $lender,
        TradingStrategy $borrower,
        float $principal,
        $committedAt = null,
    ): CapitalLoan {
        $stock = Stock::query()->create([
            'symbol' => 'RL'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Loan Stock',
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
