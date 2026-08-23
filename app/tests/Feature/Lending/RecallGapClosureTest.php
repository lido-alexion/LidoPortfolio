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
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalLoanRepaymentService;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\GoodFaithCapitalLoanRepaymentService;
use App\Services\Lending\RecallBridgeLoanService;
use App\Services\Lending\RecallFulfilmentService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallService;
use App\Services\Lending\RecommendationLendingCoordinator;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * v0.28 gap-closure: live capital resolution, auto bridge lender, fulfilment chain,
 * good-faith normal-loan repay, POST /capital/recalls processing.
 */
class RecallGapClosureTest extends TestCase
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

    public function test_live_coordinator_runs_capital_resolution_before_partial_lending(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->assertNotNull($user);
        // Eligible lent capital for lender strategy
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $rec = $this->makeBuyRecommendation($profile, $lender, [
            'status' => TradingRecommendation::ALLOCATION_PARTIALLY_FUNDED,
            'target_amount' => 20_000,
            'allocated_amount' => 15_000,
            'unfunded_amount' => 5_000,
        ]);

        app(RecommendationLendingCoordinator::class)->syncAfterGenerated($rec);
        $rec = $rec->fresh();

        $evidence = is_array($rec->evidence) ? $rec->evidence : [];
        $this->assertArrayHasKey('capital_resolution', $evidence);
        $this->assertTrue((bool) ($evidence['capital_resolution']['close_at_actual'] ?? false));

        $meta = $rec->capitalAllocationMeta();
        $this->assertNotNull($meta);
        $this->assertArrayHasKey('actual_execution_amount', $meta);
        $this->assertEqualsWithDelta(
            (float) $meta['allocated_amount'],
            (float) $meta['actual_execution_amount'],
            0.0001
        );
        $this->assertLessThanOrEqual(20_000.0001, (float) $meta['actual_execution_amount']);

        // Manual/Semi/Auto unchanged: still pending_review after generation sync
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->status);
    }

    public function test_auto_bridge_lender_settles_15k_20k_or_pending(): void
    {
        // 15k: own 10k + bridge capacity 5k
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $r15 = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $recall,
            10_000,
            5_500,
            null, // auto-select
        );
        $this->assertTrue($r15['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, (float) $r15['recall']->settled_amount, 0.0001);
        $this->assertNotNull($r15['bridge_loan']);
        $this->assertSame((int) $bridge->id, (int) $r15['bridge_loan']->lender_strategy_id);

        // 20k full: own 10k + capacity 10k
        CapitalRecall::query()->whereKey($r15['recall']->id)->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'outstanding_recall_amount' => 0,
            'completed_at' => now()->subDays(20),
        ]);
        $loan2 = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall2 = app(RecallService::class)->requestFull($profile, $loan2);
        $r20 = app(RecallImmediateSettlementService::class)->apply($profile, $recall2, 10_000, 11_000, null);
        $this->assertTrue($r20['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(20_000.0, (float) $r20['recall']->settled_amount, 0.0001);
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $r20['recall']->state);

        // pending: own 10k + capacity 2k
        CapitalRecall::query()->whereKey($r20['recall']->id)->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'completed_at' => now()->subDays(20),
        ]);
        $loan3 = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall3 = app(RecallService::class)->requestFull($profile, $loan3);
        $rPend = app(RecallImmediateSettlementService::class)->apply($profile, $recall3, 10_000, 2_200, null);
        $this->assertFalse($rPend['evaluation']['allows_immediate']);
        $this->assertNull($rPend['bridge_loan']);
        $this->assertContains($rPend['recall']->state, [
            CapitalRecall::STATE_PENDING_HELD,
            CapitalRecall::STATE_LIQUIDATION,
        ]);
    }

    public function test_create_rejects_bridge_exceeding_cushion_or_capacity(): void
    {
        [, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $this->expectException(ValidationException::class);
        app(RecallBridgeLoanService::class)->create($profile, $recall, $bridge, 5_000, [
            'borrower_own_cash' => 10_000.0,
            'liquidatable_stock_value' => 2_200.0, // max bridge ~2k
            'lender_available_override' => 100_000.0,
        ]);
    }

    public function test_fulfilment_chained_and_idempotent_with_scheduler(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHolding($profile, $borrower, 'GAP1', 200, 100.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $immediate = app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);
        $this->assertFalse($immediate['evaluation']['allows_immediate']);
        $this->assertNotNull($immediate['fulfilment']['recall_followup']);
        $this->assertTrue((bool) ($immediate['fulfilment']['recall_followup']['liquidated'] ?? false));

        $second = app(RecallImmediateSettlementService::class)->apply(
            $profile,
            $immediate['recall']->fresh(),
            0,
            0,
            null,
        );
        $this->assertTrue($second['skipped']);

        $job = app(RecallFulfilmentService::class)->processSettlements(now());
        $this->assertIsArray($job['proceeds']);
    }

    public function test_100_percent_immediate_skips_liquidation(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->seedHolding($profile, $borrower, 'FULL', 50, 100.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $qtyBefore = (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity');

        $result = app(RecallImmediateSettlementService::class)->apply($profile, $recall, 10_000, 0, null);
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $result['recall']->state);
        $this->assertNull($result['fulfilment']['recall_followup'] ?? null);
        $this->assertEqualsWithDelta(
            $qtyBefore,
            (float) Holding::query()->where('profile_id', $profile->id)->sum('quantity'),
            0.0001
        );
    }

    public function test_good_faith_normal_loan_repayment(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 12_000, now());

        $full = app(GoodFaithCapitalLoanRepaymentService::class)->repayAvailable(
            $profile,
            $borrower,
            now(),
            15_000,
        );
        $this->assertEqualsWithDelta(12_000.0, $full['repaid_total'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $loan->fresh()->outstanding, 0.0001);

        $loan2 = $this->createLoan($profile, $lender, $borrower, 12_000, now());
        $partial = app(GoodFaithCapitalLoanRepaymentService::class)->repayAvailable(
            $profile,
            $borrower,
            now(),
            7_000,
        );
        $this->assertEqualsWithDelta(7_000.0, $partial['repaid_total'], 0.0001);
        $this->assertEqualsWithDelta(5_000.0, (float) $loan2->fresh()->outstanding, 0.0001);

        // No ₹5k restriction — 7k is valid; idempotent second pass with 0 free cash
        $again = app(GoodFaithCapitalLoanRepaymentService::class)->repayAvailable(
            $profile,
            $borrower,
            now(),
            0,
        );
        $this->assertEqualsWithDelta(0.0, $again['repaid_total'], 0.0001);
        $this->assertEqualsWithDelta(5_000.0, (float) $loan2->fresh()->outstanding, 0.0001);

        // Arbitrary non-5k remainder
        app(CapitalLoanRepaymentService::class)->repay($loan2->fresh(), 5_000);
        $this->assertEqualsWithDelta(0.0, (float) $loan2->fresh()->outstanding, 0.0001);
    }

    public function test_post_capital_recalls_processes_workflow(): void
    {
        [$user, $profile, $borrower, $lender, $bridge] = $this->threeStrategyPortfolio(1_000_000);
        $this->assertNotNull($bridge);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));

        // Force settlement inputs via domain process with known cash/stock (API uses live accounting).
        $processed = app(RecallService::class)->requestAndProcess(
            $profile,
            $loan,
            'full',
            0,
            [
                'borrower_own_cash' => 10_000,
                'liquidatable_stock_value' => 5_500,
            ],
        );
        $this->assertTrue($processed['settlement']['evaluation']['allows_immediate']);
        $this->assertEqualsWithDelta(15_000.0, (float) $processed['recall']->settled_amount, 0.0001);
        $this->assertNotSame(CapitalRecall::STATE_REQUESTED, $processed['recall']->state);
        $this->assertNotNull($processed['settlement']['bridge_loan']);

        CapitalRecall::query()->whereKey($processed['recall']->id)->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'outstanding_recall_amount' => 0,
            'completed_at' => now()->subDays(20),
        ]);
        $loan2 = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));

        $api = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan2->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ]);
        $api->assertCreated();
        $this->assertNotSame(CapitalRecall::STATE_REQUESTED, $api->json('data.state'));
    }

    private function makeBuyRecommendation(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        array $allocation,
    ): TradingRecommendation {
        $stock = Stock::query()->create([
            'symbol' => 'GB'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Gap Buy',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $versionId = $strategy->active_version_id;
        $plan = [
            'target_investment_amount' => $allocation['target_amount'],
            'suggested_investment_amount' => $allocation['allocated_amount'],
            'capital_allocation' => $allocation,
        ];

        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $versionId,
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

    private function seedHolding(
        PortfolioProfile $profile,
        TradingStrategy $strategy,
        string $symbol,
        float $qty,
        float $price,
    ): Holding {
        $stock = Stock::query()->create([
            'symbol' => $symbol.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => $symbol,
            'is_active' => true,
            'is_benchmark' => false,
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
        Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'fees' => 0,
            'transaction_date' => now()->subDays(30)->toDateString(),
            'source' => Transaction::SOURCE_OTHER,
        ]);

        return Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id),
            'quantity' => $qty,
            'avg_buy_price' => $price,
            'invested_amount' => $qty * $price,
        ]);
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Gap', true);
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
            'symbol' => 'GL'.strtoupper(Str::random(3)),
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
