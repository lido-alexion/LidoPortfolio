<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
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
use App\Services\Lending\CapitalLoanRepaymentService;
use App\Services\Strategy\PortfolioCapitalAccountingService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CapitalLoanRepaymentServiceTest extends TestCase
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

    public function test_full_repayment_sets_returned_and_zero_outstanding(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $row = app(CapitalLoanRepaymentService::class)->repay($loan, 50_000);

        $loan->refresh();
        $this->assertSame(CapitalLoan::STATUS_RETURNED, $loan->status);
        $this->assertEqualsWithDelta(0.0, (float) $loan->outstanding, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $loan->principal, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $row->amount, 0.0001);
        $this->assertSame((int) $loan->id, (int) $row->loan_id);
        $this->assertSame((int) $loan->capital_request_id, (int) $row->capital_request_id);
        $this->assertSame(1, CapitalLoanReturn::query()->where('loan_id', $loan->id)->count());
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(
            $ledgerBefore,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
        $this->assertSame(
            ['deposit'],
            CashLedgerEntry::query()->where('profile_id', $profile->id)->pluck('entry_type')->unique()->values()->all()
        );
    }

    public function test_partial_repayment_sets_partially_returned(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);

        app(CapitalLoanRepaymentService::class)->repay($loan, 20_000);

        $loan->refresh();
        $this->assertSame(CapitalLoan::STATUS_PARTIALLY_RETURNED, $loan->status);
        $this->assertEqualsWithDelta(30_000.0, (float) $loan->outstanding, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $loan->principal, 0.0001);
    }

    public function test_multiple_repayments_until_fully_returned(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $service = app(CapitalLoanRepaymentService::class);

        $service->repay($loan, 20_000);
        $service->repay($loan->fresh(), 15_000);
        $service->repay($loan->fresh(), 15_000);

        $loan->refresh();
        $this->assertSame(CapitalLoan::STATUS_RETURNED, $loan->status);
        $this->assertEqualsWithDelta(0.0, (float) $loan->outstanding, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $loan->principal, 0.0001);
        $this->assertSame(3, CapitalLoanReturn::query()->where('loan_id', $loan->id)->count());
        $this->assertEqualsWithDelta(
            50_000.0,
            (float) CapitalLoanReturn::query()->where('loan_id', $loan->id)->sum('amount'),
            0.0001
        );
    }

    public function test_amount_greater_than_outstanding_is_rejected(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        app(CapitalLoanRepaymentService::class)->repay($loan, 20_000);

        try {
            app(CapitalLoanRepaymentService::class)->repay($loan->fresh(), 40_000);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertEqualsWithDelta(30_000.0, (float) $loan->fresh()->outstanding, 0.0001);
            $this->assertSame(1, CapitalLoanReturn::query()->where('loan_id', $loan->id)->count());
        }
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);

        try {
            app(CapitalLoanRepaymentService::class)->repay($loan, 0);
            $this->fail('Expected ValidationException for zero');
        } catch (ValidationException $e) {
            $this->assertEqualsWithDelta(50_000.0, (float) $loan->fresh()->outstanding, 0.0001);
        }

        $this->expectException(ValidationException::class);
        app(CapitalLoanRepaymentService::class)->repay($loan->fresh(), -1000);
    }

    public function test_fully_returned_loan_cannot_be_repaid_again(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        app(CapitalLoanRepaymentService::class)->repay($loan, 50_000);

        $this->expectException(ValidationException::class);
        app(CapitalLoanRepaymentService::class)->repay($loan->fresh(), 5_000);
    }

    public function test_principal_remains_unchanged(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        app(CapitalLoanRepaymentService::class)->repay($loan, 12_500);

        $this->assertEqualsWithDelta(50_000.0, (float) $loan->fresh()->principal, 0.0001);
    }

    public function test_lent_and_borrowed_follow_outstanding_not_return_rows(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $before = collect(app(PortfolioCapitalAccountingService::class)->snapshot($profile)['strategies'])
            ->keyBy('strategy_id');

        app(CapitalLoanRepaymentService::class)->repay($loan, 20_000);

        $after = collect(app(PortfolioCapitalAccountingService::class)->snapshot($profile)['strategies'])
            ->keyBy('strategy_id');
        $this->assertEqualsWithDelta(50_000.0, (float) $before[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $before[$borrower->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(30_000.0, (float) $after[$lender->id]['lent_capital'], 0.0001);
        $this->assertEqualsWithDelta(30_000.0, (float) $after[$borrower->id]['borrowed_capital'], 0.0001);
        $this->assertEqualsWithDelta(30_000.0, (float) $loan->fresh()->outstanding, 0.0001);
        $this->assertNotEquals(
            CapitalLoanReturn::query()->where('loan_id', $loan->id)->sum('amount'),
            $after[$lender->id]['lent_capital']
        );
    }

    public function test_repayment_does_not_change_holdings_or_allocation_pct(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $stock = Stock::query()->create([
            'symbol' => 'RP'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Repay Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $holding = Holding::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'strategy_id' => $borrower->id,
            'owner_key' => Holding::ownerKeyFor((int) $borrower->id),
            'quantity' => 10,
            'avg_buy_price' => 100,
            'invested_amount' => 1000,
            'updated_at' => now(),
        ]);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $borrowerPct = (float) $borrower->allocation_pct;
        $lenderPct = (float) $lender->allocation_pct;

        app(CapitalLoanRepaymentService::class)->repay($loan, 10_000);

        $holding->refresh();
        $this->assertSame((int) $borrower->id, (int) $holding->strategy_id);
        $this->assertEqualsWithDelta(10.0, (float) $holding->quantity, 0.0001);
        $this->assertFalse(
            Holding::query()->where('profile_id', $profile->id)->where('strategy_id', $lender->id)->exists()
        );
        $this->assertEqualsWithDelta($borrowerPct, (float) $borrower->fresh()->allocation_pct, 0.0001);
        $this->assertEqualsWithDelta($lenderPct, (float) $lender->fresh()->allocation_pct, 0.0001);
    }

    public function test_lock_prevents_returning_more_than_outstanding(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000);
        $service = app(CapitalLoanRepaymentService::class);
        $service->repay($loan, 50_000);

        try {
            $service->repay($loan->fresh(), 50_000);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(1, CapitalLoanReturn::query()->where('loan_id', $loan->id)->count());
            $this->assertEqualsWithDelta(0.0, (float) $loan->fresh()->outstanding, 0.0001);
            $this->assertEqualsWithDelta(50_000.0, (float) $loan->fresh()->principal, 0.0001);
        }
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Repay', true);
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
            'committed_at' => now(),
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
