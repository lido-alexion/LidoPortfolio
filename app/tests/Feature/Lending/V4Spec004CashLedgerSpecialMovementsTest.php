<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\CashLedgerEntry;
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
use App\Services\Lending\CapitalRequestApprovalService;
use App\Services\Lending\CapitalRequestService;
use App\Services\Lending\RecallBridgeLoanService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallLiquidationService;
use App\Services\Lending\RecallService;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Lending\SpecialCashMovementService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Services\TransactionWriteService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * V4-SPEC-004 — signed LOAN / RECALL / BRIDGE cash-ledger special movements.
 */
class V4Spec004CashLedgerSpecialMovementsTest extends TestCase
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

    public function test_signed_special_types_enter_or_leave_trading_cash(): void
    {
        [$user, $profile] = $this->cashPortfolio(100_000);
        $cash = app(CashManagementService::class);

        $loanIn = $cash->postLoan($profile, 5_000, 'note only');
        $this->assertSame(CashLedgerEntry::TYPE_LOAN, $loanIn->entry_type);
        $this->assertEqualsWithDelta(5_000.0, (float) $loanIn->amount, 0.0001);
        $this->assertEqualsWithDelta(105_000.0, $cash->balance($profile), 0.0001);

        $loanOut = $cash->postLoan($profile, -2_000, 'note only');
        $this->assertEqualsWithDelta(-2_000.0, (float) $loanOut->amount, 0.0001);
        $this->assertEqualsWithDelta(103_000.0, $cash->balance($profile), 0.0001);

        $cash->postRecall($profile, 1_000, null);
        $cash->postBridge($profile, -500, null);
        $this->assertEqualsWithDelta(103_500.0, $cash->balance($profile), 0.0001);
    }

    public function test_zero_and_directional_types_are_rejected(): void
    {
        [, $profile] = $this->cashPortfolio(10_000);
        $cash = app(CashManagementService::class);

        try {
            $cash->postLoan($profile, 0, 'x');
            $this->fail('Expected zero rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        try {
            $cash->postSpecialMovement($profile, 'loan_in', 100);
            $this->fail('Expected directional type rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry_type', $e->errors());
        }

        $this->assertSame(
            ['deposit'],
            CashLedgerEntry::query()->where('profile_id', $profile->id)->pluck('entry_type')->unique()->values()->all()
        );
    }

    public function test_loan_approval_posts_signed_loan_pair_without_changing_physical_cash_or_holdings(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 50_000);
        $cashBefore = app(CashManagementService::class)->balance($profile);
        $holdingsBefore = Holding::query()->where('profile_id', $profile->id)->count();
        $txBefore = Transaction::query()->where('profile_id', $profile->id)->count();

        $loan = app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame($holdingsBefore, Holding::query()->where('profile_id', $profile->id)->count());
        $this->assertSame($txBefore, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(
            0,
            CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->whereIn('entry_type', [CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL])
                ->count()
        );

        $loanRows = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_LOAN)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $loanRows);
        $this->assertEqualsWithDelta(50_000.0, (float) $loanRows[0]->amount, 0.0001);
        $this->assertEqualsWithDelta(-50_000.0, (float) $loanRows[1]->amount, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $loanRows->sum('amount'), 0.0001);
        $this->assertTrue($this->ledgerSumEqualsBalance($profile));

        app(SpecialCashMovementService::class)->postLoanDisbursement($profile, $loan);
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_LOAN)->count());
    }

    public function test_loan_repayment_posts_signed_loan_pair_and_is_idempotent_per_return(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $row = app(CapitalLoanRepaymentService::class)->repay($loan, 20_000);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_LOAN)->count());

        app(SpecialCashMovementService::class)->postLoanRepayment($profile, $loan->fresh(), $row);
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_LOAN)->count());
        $this->assertTrue($this->ledgerSumEqualsBalance($profile));
    }

    public function test_recall_immediate_settlement_posts_recall_not_loan_and_skips_synthetic_trades(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $cashBefore = app(CashManagementService::class)->balance($profile);
        $txBefore = Transaction::query()->where('profile_id', $profile->id)->count();

        $applied = app(RecallImmediateSettlementService::class)->apply($profile, $recall, 20_000, 0, null);

        $this->assertTrue($applied['evaluation']['allows_immediate']);
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $applied['recall']->state);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame($txBefore, Transaction::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_LOAN)->count());
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_RECALL)->count());
        $this->assertEqualsWithDelta(
            0.0,
            (float) CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_RECALL)->sum('amount'),
            0.0001
        );
        $this->assertTrue($this->ledgerSumEqualsBalance($profile));
    }

    public function test_bridge_create_and_repay_post_signed_bridge_pairs(): void
    {
        [, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $bridge = app(RecallBridgeLoanService::class)->create($profile, $recall, $bridgeLender, 5_000, [
            'borrower_own_cash' => 10_000.0,
            'liquidatable_stock_value' => 100_000.0,
            'lender_available_override' => 100_000.0,
        ]);

        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_BRIDGE)->count());

        app(RecallBridgeLoanService::class)->create($profile, $recall, $bridgeLender, 5_000, [
            'borrower_own_cash' => 10_000.0,
            'liquidatable_stock_value' => 100_000.0,
            'lender_available_override' => 100_000.0,
        ]);
        $this->assertSame(2, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_BRIDGE)->count());

        app(RecallBridgeLoanService::class)->repay($bridge, 2_000);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(4, CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_BRIDGE)->count());
        $this->assertEqualsWithDelta(
            0.0,
            (float) CashLedgerEntry::query()->where('entry_type', CashLedgerEntry::TYPE_BRIDGE)->sum('amount'),
            0.0001
        );
        $this->assertTrue($this->ledgerSumEqualsBalance($profile));
    }

    public function test_recall_proceeds_release_posts_positive_recall_not_deposit(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);
        $this->seedHolding($profile, $borrower, 'PRC', 200, 100.0);

        $soldAt = Carbon::parse('2026-08-01 10:00:00');
        $liq = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            10_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            $recall->fresh(),
            null,
            $soldAt,
        );
        $psp = $liq['pending_proceeds'][0];
        $depositBefore = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)
            ->count();
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $released = app(SaleProceedsAvailabilityService::class)->releaseCashIfDue(
            $psp->fresh(),
            $soldAt->copy()->addDays(1),
        );
        $this->assertNotNull($released->cash_released_at);
        $this->assertEqualsWithDelta(
            $cashBefore + (float) $psp->amount,
            app(CashManagementService::class)->balance($profile),
            0.0001
        );
        $this->assertSame(
            $depositBefore,
            CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->where('entry_type', CashLedgerEntry::TYPE_DEPOSIT)
                ->count()
        );
        $inflow = CashLedgerEntry::query()
            ->where('profile_id', $profile->id)
            ->where('entry_type', CashLedgerEntry::TYPE_RECALL)
            ->where('amount', '>', 0)
            ->where('reason', 'like', 'Proceeds from Stock Sale%')
            ->get();
        $this->assertCount(1, $inflow);
        $this->assertEqualsWithDelta((float) $psp->amount, (float) $inflow[0]->amount, 0.0001);

        app(SaleProceedsAvailabilityService::class)->releaseCashIfDue($released->fresh(), $soldAt->copy()->addDays(1));
        $this->assertCount(1, CashLedgerEntry::query()
            ->where('entry_type', CashLedgerEntry::TYPE_RECALL)
            ->where('reason', 'like', 'Proceeds from Stock Sale%')
            ->get());
        $this->assertTrue($this->ledgerSumEqualsBalance($profile));
    }

    public function test_bridge_proceeds_release_posts_positive_bridge(): void
    {
        [, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $this->seedHolding($profile, $borrower, 'BRP', 200, 100.0);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $bridge = app(RecallBridgeLoanService::class)->create($profile, $recall, $bridgeLender, 5_000, [
            'borrower_own_cash' => 2_000.0,
            'liquidatable_stock_value' => 100_000.0,
            'lender_available_override' => 100_000.0,
        ]);

        $soldAt = Carbon::parse('2026-08-02 10:00:00');
        $liq = app(RecallLiquidationService::class)->liquidateForObligation(
            $profile,
            $borrower,
            5_000,
            PendingSaleProceeds::OBLIGATION_BRIDGE,
            $recall,
            $bridge,
            $soldAt,
        );
        $psp = $liq['pending_proceeds'][0];
        $cashBefore = app(CashManagementService::class)->balance($profile);

        app(SaleProceedsAvailabilityService::class)->releaseCashIfDue($psp->fresh(), $soldAt->copy()->addDays(1));
        $this->assertEqualsWithDelta(
            $cashBefore + (float) $psp->amount,
            app(CashManagementService::class)->balance($profile),
            0.0001
        );
        $this->assertTrue(
            CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->where('entry_type', CashLedgerEntry::TYPE_BRIDGE)
                ->where('amount', '>', 0)
                ->where('reason', 'like', 'Proceeds from Stock Sale%')
                ->exists()
        );
    }

    public function test_cash_ledger_api_exposes_loan_entry_type(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);
        app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/cash/ledger')
            ->assertOk()
            ->assertJsonFragment(['entry_type' => 'loan'])
            ->assertJsonFragment(['movement_kind' => 'normal_loan']);
    }

    public function test_direct_capital_loan_insert_does_not_invent_cash_rows(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();
        $this->createLoan($profile, $lender, $borrower, 50_000);
        $this->assertSame(
            $ledgerBefore,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
    }

    private function ledgerSumEqualsBalance(PortfolioProfile $profile): bool
    {
        $sum = round((float) CashLedgerEntry::query()->where('profile_id', $profile->id)->sum('amount'), 4);
        $balance = round(app(CashManagementService::class)->balance($profile), 4);

        return abs($sum - $balance) <= 0.0001;
    }

    /**
     * @return array{0: User, 1: PortfolioProfile}
     */
    private function cashPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        app(CashManagementService::class)->deposit($profile, $cash, 'seed', $user);

        return [$user, $profile];
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
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

    private function makeRecommendation($profile, TradingStrategy $borrower): TradingRecommendation
    {
        $stock = Stock::query()->create([
            'symbol' => 'S4'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Spec004 Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);

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
        $rec = $this->makeRecommendation($profile, $borrower);
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

    private function seedHolding(
        PortfolioProfile $profile,
        TradingStrategy $owner,
        string $prefix,
        float $qty,
        float $price,
    ): Holding {
        $stock = Stock::query()->create([
            'symbol' => $prefix.strtoupper(Str::random(2)),
            'exchange' => 'NSE',
            'name' => $prefix,
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->subDays(100)->toDateString(),
            'close_price' => $price,
            'open_price' => $price,
            'high_price' => $price,
            'low_price' => $price,
            'volume' => 1000,
            'data_source' => 'test',
        ]);
        StockPrice::query()->create([
            'stock_id' => $stock->id,
            'price_date' => now()->toDateString(),
            'close_price' => $price * 0.9,
            'open_price' => $price * 0.9,
            'high_price' => $price * 0.9,
            'low_price' => $price * 0.9,
            'volume' => 1000,
            'data_source' => 'test',
        ]);
        app(TransactionWriteService::class)->create(
            $profile,
            $stock,
            [
                'type' => 'buy',
                'quantity' => $qty,
                'price' => $price,
                'fees' => 0,
                'transaction_date' => now()->subDays(90)->toDateString(),
                'source' => Transaction::SOURCE_OTHER,
            ],
            softFailSnapshots: true,
            user: null,
            applyCash: false,
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
