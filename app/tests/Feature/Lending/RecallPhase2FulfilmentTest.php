<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\Holding;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalLoanRepaymentService;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\GoodFaithBridgeRepaymentService;
use App\Services\Lending\ProceedsApplicationService;
use App\Services\Lending\RecallFulfilmentService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallLiquidationService;
use App\Services\Lending\RecallService;
use App\Services\Lending\SaleBufferCalculator;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Lending\WeakestPositionRanker;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecallPhase2FulfilmentTest extends TestCase
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

    public function test_75_percent_immediate_settlement_leaves_remainder(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan75 = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $r75 = app(RecallService::class)->requestFull($profile, $loan75);
        $a75 = app(RecallImmediateSettlementService::class)->apply($profile, $r75, 15_000, 0, null);
        $this->assertTrue($a75['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, (float) $a75['recall']->settled_amount, 0.0001);
        $this->assertEqualsWithDelta(5_000.0, (float) $a75['recall']->outstanding_recall_amount, 0.0001);
    }

    public function test_90_percent_immediate_settlement_leaves_remainder(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan90 = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $r90 = app(RecallService::class)->requestFull($profile, $loan90);
        $a90 = app(RecallImmediateSettlementService::class)->apply($profile, $r90, 9_000, 0, null);
        $this->assertTrue($a90['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(9_000.0, (float) $a90['recall']->settled_amount, 0.0001);
        $this->assertEqualsWithDelta(1_000.0, (float) $a90['recall']->outstanding_recall_amount, 0.0001);
    }

    public function test_multiple_proceeds_batches_and_no_unnecessary_liquidation(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'BAT1', 80, 100.0, returnPct: -10.0);
        $this->seedHoldingWithHistory($profile, $borrower, 'BAT2', 80, 100.0, returnPct: -20.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 12_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        // Leave at requested → pending without auto-liquidation so multi-batch can be asserted explicitly.
        app(RecallService::class)->markPendingHeld($recall);

        $soldAt = now();
        $first = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            6_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            $recall->fresh(),
            null,
            $soldAt,
        );
        $this->assertNotEmpty($first['pending_proceeds']);

        // Second batch for remaining gap
        $second = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            6_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            $recall->fresh(),
            null,
            $soldAt,
        );
        $this->assertNotEmpty($second['pending_proceeds']);

        foreach (array_merge($first['pending_proceeds'], $second['pending_proceeds']) as $psp) {
            app(ProceedsApplicationService::class)->applyRow($psp, $soldAt->copy()->addDays(1));
        }
        $this->assertLessThanOrEqual(0.0001, (float) $recall->fresh()->outstanding_recall_amount);

        // No unnecessary liquidation when already complete
        $qty = (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity');
        $noop = app(RecallFulfilmentService::class)->continueRecallFulfilment($profile, $recall->fresh(), $soldAt);
        $this->assertFalse($noop['liquidated']);
        $this->assertEqualsWithDelta(
            $qty,
            (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity'),
            0.0001
        );
    }

    public function test_sale_buffer_is_half_percent_platform_wide(): void
    {
        $sized = (new SaleBufferCalculator)->size(10_000);
        $this->assertEqualsWithDelta(50.0, $sized['sale_buffer_amount'], 0.0001);
        $this->assertEqualsWithDelta(10_050.0, $sized['target_liquidation_value'], 0.0001);
        $this->assertSame(0.005, $sized['sale_buffer_ratio']);
    }

    public function test_weakest_position_ranks_lowest_window_return_first(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $weak = $this->seedHoldingWithHistory($profile, $borrower, 'WEAK', 100, 100.0, returnPct: -20.0);
        $strong = $this->seedHoldingWithHistory($profile, $borrower, 'STRG', 100, 100.0, returnPct: 10.0);

        $ranked = app(WeakestPositionRanker::class)->rankBorrowerPositions($profile, $borrower);
        $this->assertGreaterThanOrEqual(2, count($ranked));
        $this->assertSame((int) $weak->stock_id, (int) $ranked[0]['holding']->stock_id);
        $this->assertLessThan($ranked[1]['window_return_pct'], $ranked[0]['window_return_pct']);
        $this->assertSame((int) $strong->stock_id, (int) $strong->stock_id);
    }

    public function test_below_75_pending_held_then_liquidation_and_delayed_proceeds(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'LIQ1', 200, 100.0, returnPct: -5.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $immediate = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            10_000,
            2_200, // bridge max ~2k → total 12k < 15k
            null,
        );
        $this->assertFalse($immediate['evaluation']['allows_immediate']);
        $this->assertNotSame(CapitalRecall::STATE_REQUESTED, $immediate['recall']->state);
        // Immediate fulfilment is chained from apply.
        $this->assertNotNull($immediate['fulfilment']);
        $this->assertTrue((bool) ($immediate['fulfilment']['recall_followup']['liquidated'] ?? false));

        $cashBefore = app(CashManagementService::class)->balance($profile);
        /** @var PendingSaleProceeds $psp */
        $psp = $immediate['fulfilment']['recall_followup']['liquidation']['pending_proceeds'][0];
        $this->assertSame(PendingSaleProceeds::STATUS_PENDING, $psp->status);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);

        $soldAt = \Illuminate\Support\Carbon::parse($psp->sold_at);

        // Idempotent re-entry after sync fulfilment
        $again = app(RecallFulfilmentService::class)->continueRecallFulfilment($profile, $immediate['recall']->fresh(), $soldAt);
        $this->assertFalse($again['liquidated']);

        // Same day: not available
        $applied = app(ProceedsApplicationService::class)->applyRow($psp, $soldAt);
        $this->assertSame(PendingSaleProceeds::STATUS_PENDING, $applied['row']->status);

        // After settlement delay
        $asOf = $soldAt->copy()->addDays(1);
        $applied = app(ProceedsApplicationService::class)->applyRow($psp->fresh(), $asOf);
        $this->assertSame(PendingSaleProceeds::STATUS_APPLIED, $applied['row']->status);
        $this->assertGreaterThan(0.0, $applied['applied_to_recall']);
        $this->assertGreaterThan($cashBefore, app(CashManagementService::class)->balance($profile));
    }

    public function test_partial_immediate_then_liquidation_for_remainder(): void
    {
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'REM1', 300, 100.0, returnPct: -8.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $immediate = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            10_000,
            8_800, // eligible bridge 8k → settle 18k
            $bridge,
        );
        $this->assertTrue($immediate['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(18_000.0, (float) $immediate['recall']->settled_amount, 0.0001);
        $this->assertEqualsWithDelta(2_000.0, (float) $immediate['recall']->outstanding_recall_amount, 0.0001);
        $this->assertSame((int) $recall->id, (int) $immediate['recall']->id);
        $this->assertNotNull($immediate['fulfilment']);
        $this->assertNotNull($immediate['fulfilment']['bridge_followup']);
        $this->assertNotNull($immediate['fulfilment']['recall_followup']);

        // Scheduler / second pass must be safe (idempotent).
        $follow = app(RecallFulfilmentService::class)->afterImmediateSettlement($profile, $immediate, now());
        $this->assertNotNull($follow['bridge_followup']);
        $this->assertNotNull($follow['recall_followup']);
    }

    public function test_100_percent_immediate_no_liquidation(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'NOLI', 50, 100.0, returnPct: -1.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $immediate = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            20_000,
            0,
            null,
        );
        $this->assertTrue($immediate['evaluation']['allows_immediate']);
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $immediate['recall']->state);

        $qtyBefore = (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity');
        $follow = app(RecallFulfilmentService::class)->afterImmediateSettlement($profile, $immediate, now());
        $this->assertNull($follow['recall_followup']);
        $this->assertEqualsWithDelta(
            $qtyBefore,
            (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity'),
            0.0001
        );
    }

    public function test_bridge_repayment_in_arbitrary_installments(): void
    {
        [, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $bridge = app(\App\Services\Lending\RecallBridgeLoanService::class)
            ->create($profile, $recall, $bridgeLender, 5_000, [
                'borrower_own_cash' => 10_000.0,
                'liquidatable_stock_value' => 100_000.0,
                'lender_available_override' => 100_000.0,
            ]);

        app(\App\Services\Lending\RecallBridgeLoanService::class)->repay($bridge, 3_000);
        $this->assertEqualsWithDelta(2_000.0, (float) $bridge->fresh()->outstanding, 0.0001);
        app(\App\Services\Lending\RecallBridgeLoanService::class)->repay($bridge->fresh(), 2_000);
        $this->assertEqualsWithDelta(0.0, (float) $bridge->fresh()->outstanding, 0.0001);
        $this->assertSame(RecallBridgeLoan::STATUS_RETURNED, $bridge->fresh()->status);
    }

    public function test_actual_proceeds_lower_than_expected_leaves_remainder(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'HAIR', 200, 100.0, returnPct: -12.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);

        $soldAt = now();
        $liq = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            10_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            $recall->fresh(),
            null,
            $soldAt,
            0.06, // 6% haircut on actual vs expected
        );
        $this->assertNotEmpty($liq['pending_proceeds']);
        $psp = $liq['pending_proceeds'][0];
        $this->assertLessThan((float) $psp->expected_amount, (float) $psp->amount);

        app(ProceedsApplicationService::class)->applyRow($psp, $soldAt->copy()->addDays(1));
        $this->assertGreaterThan(0.0, (float) $recall->fresh()->outstanding_recall_amount);
    }

    public function test_excess_proceeds_retained_by_borrower(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'EXCS', 100, 100.0, returnPct: -15.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 5_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);

        $soldAt = now();
        // Force a larger sale than required by requesting buffer-sized liquidation on 5k
        $liq = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            5_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            $recall->fresh(),
            null,
            $soldAt,
        );
        $psp = $liq['pending_proceeds'][0];
        // Inflate actual amount above remaining recall obligation
        app(SaleProceedsAvailabilityService::class)->setActualProceeds($psp, 5_300);

        $cashBeforeRelease = app(CashManagementService::class)->balance($profile);
        $result = app(ProceedsApplicationService::class)->applyRow($psp->fresh(), $soldAt->copy()->addDays(1));
        $this->assertEqualsWithDelta(5_000.0, $result['applied_to_recall'], 0.0001);
        $this->assertEqualsWithDelta(300.0, $result['excess_retained'], 0.0001);
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $recall->fresh()->state);
        // Full actual proceeds hit cash; excess stays in pool as borrower capital
        $this->assertEqualsWithDelta(
            $cashBeforeRelease + 5_300.0,
            app(CashManagementService::class)->balance($profile),
            0.0001
        );
    }

    public function test_idempotent_settlement_job(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHoldingWithHistory($profile, $borrower, 'IDEM', 150, 100.0, returnPct: -7.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 8_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);
        $soldAt = Carbon::parse('2026-08-01 10:00:00');
        app(RecallFulfilmentService::class)->continueRecallFulfilment($profile, $recall->fresh(), $soldAt);

        $asOf = $soldAt->copy()->addDays(1);
        $first = app(RecallFulfilmentService::class)->processSettlements($asOf);
        $loanOut1 = (float) $loan->fresh()->outstanding;
        $second = app(RecallFulfilmentService::class)->processSettlements($asOf);
        $this->assertEqualsWithDelta($loanOut1, (float) $loan->fresh()->outstanding, 0.0001);
        $this->assertSame(0, $second['proceeds']['processed']);
        $this->assertSame($first['proceeds']['processed'] >= 0, true);
    }

    public function test_good_faith_bridge_repay_uses_available_capital(): void
    {
        [, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $bridge = app(\App\Services\Lending\RecallBridgeLoanService::class)
            ->create($profile, $recall, $bridgeLender, 5_000, [
                'borrower_own_cash' => 5_000.0,
                'liquidatable_stock_value' => 100_000.0,
                'lender_available_override' => 100_000.0,
            ]);

        $result = app(GoodFaithBridgeRepaymentService::class)->repayAvailable(
            $profile,
            $borrower,
            now(),
            3_000,
        );
        $this->assertEqualsWithDelta(3_000.0, $result['repaid_total'], 0.0001);
        $this->assertEqualsWithDelta(2_000.0, (float) $bridge->fresh()->outstanding, 0.0001);
    }

    public function test_capital_resolution_still_closes_at_actual(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));
        $result = app(CapitalResolutionService::class)->resolveForStrategy($profile, $lender, 20_000, [
            'own_available_override' => 15_000,
            'borrower_own_cash_overrides' => [(int) $borrower->id => 4_000],
            'liquidatable_stock_overrides' => [(int) $borrower->id => 0],
        ]);
        $this->assertTrue($result['close_at_actual']);
        $this->assertFalse($result['hold_for_remainder']);
        $this->assertEqualsWithDelta(19_000.0, $result['actual_available'], 0.0001);
    }

    public function test_normal_repayment_regression_and_artisan_command(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 12_000);
        app(CapitalLoanRepaymentService::class)->repay($loan, 12_000);
        $this->assertEqualsWithDelta(0.0, (float) $loan->fresh()->outstanding, 0.0001);

        $this->artisan('portfolio:process-recall-settlements')
            ->assertSuccessful();
    }

    public function test_schedule_registers_recall_settlements(): void
    {
        $this->refreshApplication();
        $this->artisan('migrate', ['--force' => true]);
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $found = false;
        foreach ($schedule->events() as $event) {
            $blob = ($event->command ?? '').' '.($event->description ?? '').' '.($event->mutexName ?? '');
            if (str_contains($blob, 'process-recall-settlements') || str_contains($blob, 'recall-settlements')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'portfolio:process-recall-settlements schedule not registered');
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'R2', true);
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

    private function seedHoldingWithHistory(
        PortfolioProfile $profile,
        TradingStrategy $owner,
        string $symbolPrefix,
        float $qty,
        float $price,
        float $returnPct,
    ): Holding {
        $stock = Stock::query()->create([
            'symbol' => $symbolPrefix.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => $symbolPrefix,
            'is_active' => true,
            'is_benchmark' => false,
        ]);

        $startPrice = $price / (1 + $returnPct / 100.0);
        $startDate = now()->subDays(100);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => $startDate->toDateString(),
            'close_price' => $startPrice,
            'open_price' => $startPrice,
            'high_price' => $startPrice,
            'low_price' => $startPrice,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'close_price' => $price,
            'open_price' => $price,
            'high_price' => $price,
            'low_price' => $price,
            'volume' => 1000,
            'data_source' => 'test',
            'provider_source' => 'test',
        ]);

        app(TransactionWriteService::class)->create(
            $profile,
            $stock,
            [
                'type' => 'buy',
                'quantity' => $qty,
                'price' => $price,
                'fees' => 0,
                'transaction_date' => $startDate->toDateString(),
                'notes' => 'seed',
            ],
            softFailSnapshots: true,
            user: null,
            applyCash: true,
        );

        $holding = Holding::query()
            ->where('profile_id', $profile->id)
            ->where('stock_id', $stock->id)
            ->firstOrFail();
        $holding->forceFill([
            'strategy_id' => $owner->id,
            'owner_key' => Holding::ownerKeyFor((int) $owner->id),
        ])->save();

        return $holding->fresh();
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
            'config_json' => [
                'indicators' => [],
                'weakest_position_window_days' => 90,
            ],
            'status' => TradingStrategyVersion::STATUS_DRAFT,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }
}
