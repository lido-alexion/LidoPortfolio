<?php

namespace Tests\Feature\Lending;

use App\Engines\Recommendation\RecommendationLifecycleService;
use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
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
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationLendingExecutionTest extends TestCase
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

    public function test_fully_funded_buy_executes_as_before(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_FUNDED,
            'target_amount' => 10000.0,
            'allocated_amount' => 10000.0,
            'unfunded_amount' => 0.0,
        ], 10000.0);
        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );

        $fill = $this->postFill($user, $profile, $rec, 100, 100);

        $fill->assertCreated();
        $this->assertSame('executed', $fill->json('tos.recommendation_status'));
        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $rec->security_id)->first();
        $this->assertNotNull($holding);
        $this->assertSame((int) $borrower->id, (int) $holding->strategy_id);
        $this->assertEqualsWithDelta(100.0, (float) $holding->quantity, 0.0001);
        $this->assertSame(0, CapitalLoan::query()->where('profile_id', $profile->id)->count());
    }

    public function test_partially_funded_committed_loan_can_execute_for_borrower(): void
    {
        [$user, $profile, $borrower, $lender] = $this->committedPartial($own = 10000.0, $target = 18000.0);
        $rec = TradingRecommendation::query()->where('profile_id', $profile->id)->first();
        $borrowerPct = (float) $borrower->allocation_pct;
        $lenderPct = (float) $lender->allocation_pct;
        $cashBefore = app(CashManagementService::class)->balance($profile);
        $buyEntriesBefore = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_BUY)
            ->count();
        $depositEntries = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)
            ->count();

        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec->fresh(),
            TradingRecommendation::DECISION_APPROVED
        );
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);

        $fill = $this->postFill($user, $profile, $rec, 180, 100);
        $fill->assertCreated();
        $rec->refresh();

        $this->assertSame(TradingRecommendation::STATUS_EXECUTED, $rec->status);
        $this->assertEqualsWithDelta(18000.0, (float) $rec->executed_amount, 0.0001);
        $this->assertEqualsWithDelta(18000.0, (float) $rec->capitalTargetAmount(), 0.0001);
        $this->assertEqualsWithDelta(10000.0, (float) $rec->ownAllocatedAmount(), 0.0001);
        $this->assertEqualsWithDelta(18000.0, (float) $rec->capitalAllocationMeta()['intended_execution_amount'], 0.0001);
        $this->assertEqualsWithDelta(10000.0, (float) $rec->capitalAllocationMeta()['borrowed_amount'], 0.0001);
        $this->assertEqualsWithDelta(2000.0, (float) $rec->capitalAllocationMeta()['excess_borrowed_amount'], 0.0001);

        $holding = Holding::query()->where('profile_id', $profile->id)->where('stock_id', $rec->security_id)->get();
        $this->assertCount(1, $holding);
        $this->assertSame((int) $borrower->id, (int) $holding[0]->strategy_id);
        $this->assertNotSame((int) $lender->id, (int) $holding[0]->strategy_id);
        $this->assertFalse(
            Holding::query()->where('profile_id', $profile->id)->where('strategy_id', $lender->id)->exists()
        );
        $this->assertEqualsWithDelta($borrowerPct, (float) $borrower->fresh()->allocation_pct, 0.0001);
        $this->assertEqualsWithDelta($lenderPct, (float) $lender->fresh()->allocation_pct, 0.0001);

        $loan = CapitalLoan::query()->where('profile_id', $profile->id)->first();
        $this->assertNotNull($loan);
        $this->assertSame(CapitalLoan::STATUS_OUTSTANDING, $loan->status);
        $this->assertEqualsWithDelta(10000.0, (float) $loan->outstanding, 0.0001);
        $this->assertSame((int) $rec->id, (int) $loan->capitalRequest->recommendation_id);
        $this->assertSame((int) $rec->executed_transaction_id, (int) $rec->capitalAllocationMeta()['execution_transaction_id']);
        $this->assertSame((int) $loan->id, (int) $rec->capitalAllocationMeta()['capital_loan_id']);
        $this->assertSame(0, CapitalLoanReturn::query()->count());

        $this->assertEqualsWithDelta($cashBefore - 18000.0, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(
            $buyEntriesBefore + 1,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->where('entry_type', CashLedgerEntry::TYPE_BUY)->count()
        );
        $this->assertSame(
            $depositEntries,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)->count()
        );
    }

    public function test_unfunded_cannot_execute(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_UNFUNDED,
            'target_amount' => 18000.0,
            'allocated_amount' => 0.0,
            'unfunded_amount' => 18000.0,
        ], 0.0);
        $rec->forceFill(['status' => TradingRecommendation::STATUS_PENDING_EXECUTION])->save();

        $this->postFill($user, $profile, $rec, 180, 100)->assertStatus(422);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $this->assertFalse(Holding::query()->where('stock_id', $rec->security_id)->exists());
    }

    public function test_awaiting_lender_can_execute_at_resolved_actual(): void
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
        $this->assertSame(
            TradingRecommendation::ALLOCATION_AWAITING_LENDER_SELECTION,
            $rec->capitalAllocationStatus()
        );
        $this->assertTrue(
            app(RecommendationLendingCoordinator::class)->canEnterPendingExecution($rec)
        );

        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec->fresh(),
            TradingRecommendation::DECISION_APPROVED
        );
        // Execute at actual funded ₹10k (not original ₹18k target).
        $this->postFill($user, $profile, $rec, 100, 100)->assertCreated();
        $this->assertEqualsWithDelta(10000.0, (float) $rec->fresh()->executed_amount, 0.0001);
    }

    public function test_same_recommendation_and_loan_cannot_execute_twice(): void
    {
        [$user, $profile] = $this->committedPartial(10000.0, 18000.0);
        $rec = TradingRecommendation::query()->where('profile_id', $profile->id)->first();
        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );
        $this->postFill($user, $profile, $rec, 180, 100)->assertCreated();
        $this->postFill($user, $profile, $rec, 180, 100)->assertStatus(422);
        $this->assertSame(1, CapitalLoan::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(
            1,
            \App\Models\Transaction::query()->where('recommendation_id', $rec->id)->count()
        );
    }

    public function test_failed_fill_does_not_return_or_discard_loan(): void
    {
        [$user, $profile] = $this->committedPartial(10000.0, 18000.0);
        $rec = TradingRecommendation::query()->where('profile_id', $profile->id)->first();
        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );
        // Release reservation so cash can be drained below the fill notional.
        app(RecommendationLifecycleService::class)->releaseReservation($rec->fresh());
        $balance = app(CashManagementService::class)->balance($profile);
        app(CashManagementService::class)->withdraw($profile, max(0.0, $balance - 100.0), 'drain', $user);

        $this->postFill($user, $profile, $rec, 180, 100)->assertStatus(422);

        $loan = CapitalLoan::query()->where('profile_id', $profile->id)->first();
        $this->assertSame(CapitalLoan::STATUS_OUTSTANDING, $loan->status);
        $this->assertEqualsWithDelta(10000.0, (float) $loan->outstanding, 0.0001);
        $this->assertSame(0, CapitalLoanReturn::query()->count());
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $rec->fresh()->status);
        $this->assertFalse(Holding::query()->where('stock_id', $rec->security_id)->exists());
    }

    public function test_sell_execution_remains_unchanged(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $stock = Stock::query()->create([
            'symbol' => 'SL'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Sell Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $open = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'execution_plan' => ['suggested_quantity' => 10],
            'generated_at' => now(),
        ]);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/transactions', [
                'stock_id' => $stock->id,
                'type' => 'buy',
                'quantity' => 10,
                'price' => 50,
                'fees' => 0,
                'transaction_date' => now()->toDateString(),
                'recommendation_id' => $open->id,
            ])
            ->assertCreated();

        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_EXIT_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 20,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'execution_plan' => ['suggested_quantity' => 10],
            'generated_at' => now(),
        ]);
        app(RecommendationLifecycleService::class)->recordReview(
            $profile,
            $user,
            $rec,
            TradingRecommendation::DECISION_APPROVED
        );

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/transactions', [
                'stock_id' => $stock->id,
                'type' => 'sell',
                'quantity' => 10,
                'price' => 55,
                'fees' => 0,
                'transaction_date' => now()->toDateString(),
                'recommendation_id' => $rec->id,
            ])
            ->assertCreated()
            ->assertJsonPath('tos.recommendation_status', 'executed');
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function committedPartial(float $own, float $target): array
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeBuyRecommendation($profile, $borrower, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => $target,
            'allocated_amount' => $own,
            'unfunded_amount' => $target - $own,
        ]);
        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $request = app(RecommendationLendingCoordinator::class)->activeRequestFor($rec);
        app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        return [$user, $profile, $borrower->fresh(), $lender->fresh()];
    }

    private function postFill($user, $profile, TradingRecommendation $rec, float $qty, float $price)
    {
        return $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/transactions', [
                'stock_id' => $rec->security_id,
                'type' => 'buy',
                'quantity' => $qty,
                'price' => $price,
                'fees' => 0,
                'transaction_date' => now()->toDateString(),
                'recommendation_id' => $rec->id,
            ]);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Lending Exec', true);
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
        $stock = Stock::query()->create([
            'symbol' => 'EX'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Exec Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $suggestedAmount ??= (float) $capital['allocated_amount'];
        $plan = [
            'target_investment_amount' => $capital['target_amount'] ?? 0,
            'suggested_investment_amount' => $suggestedAmount,
            'suggested_quantity' => $suggestedAmount > 0 ? $suggestedAmount / 100 : 0,
            'capital_allocation' => $capital,
        ];

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $borrower->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'suggested_allocation_amount' => $suggestedAmount,
            'reference_price' => 100,
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
