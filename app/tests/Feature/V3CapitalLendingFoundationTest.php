<?php

namespace Tests\Feature;

use App\Models\CapitalLoan;
use App\Models\CapitalLoanReturn;
use App\Models\CapitalRequest;
use App\Models\CashAccount;
use App\Models\CashLedgerEntry;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\StrategyConfigurationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * V3 Workstream 4 Step 1 — capital request / loan / return data foundation.
 */
class V3CapitalLendingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_creates_capital_request_loan_and_return_tables(): void
    {
        $this->assertTrue(Schema::hasTable('portfolio_tos_capital_requests'));
        $this->assertTrue(Schema::hasTable('portfolio_tos_loans'));
        $this->assertTrue(Schema::hasTable('portfolio_tos_loan_returns'));
        $this->assertTrue(Schema::hasColumn('portfolio_tos_loans', 'principal'));
        $this->assertTrue(Schema::hasColumn('portfolio_tos_loans', 'outstanding'));
        $this->assertTrue(Schema::hasColumn('portfolio_tos_loans', 'committed_at'));
        $this->assertTrue(Schema::hasColumn('portfolio_tos_loans', 'min_recall_at'));
        $this->assertFalse(Schema::hasColumn('portfolio_cash_accounts', 'strategy_id'));
        $this->assertFalse(Schema::hasColumn('portfolio_cash_ledger_entries', 'strategy_id'));
        $this->assertFalse(Schema::hasColumn('portfolio_cash_ledger_entries', 'loan_id'));
    }

    public function test_capital_request_references_borrower_strategy_and_recommendation(): void
    {
        [$profile, $borrower, $lender, $rec] = $this->twoStrategiesAndRecommendation();

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'recommendation_id' => $rec->id,
            'amount' => 15000,
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);

        $this->assertSame((int) $borrower->id, (int) $request->borrowerStrategy->id);
        $this->assertSame((int) $rec->id, (int) $request->recommendation->id);
        $this->assertTrue($borrower->capitalRequestsAsBorrower()->whereKey($request->id)->exists());
        $this->assertTrue($rec->capitalRequests()->whereKey($request->id)->exists());
        $this->assertNull($request->lender_strategy_id);
        $this->assertNotSame((int) $borrower->id, (int) $lender->id);
    }

    public function test_loan_references_borrower_lender_and_capital_request(): void
    {
        [$profile, $borrower, $lender, $rec, $user] = $this->twoStrategiesAndRecommendation(true);
        $committedAt = now()->subDays(2);

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 15000,
            'status' => CapitalRequest::STATUS_COMMITTED,
            'approved_at' => $committedAt,
            'approved_by' => $user->id,
        ]);

        $loan = CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => 15000,
            'outstanding' => 15000,
            'committed_at' => $committedAt,
            'min_recall_at' => null,
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);

        $loan->refresh();
        $this->assertSame((int) $borrower->id, (int) $loan->borrowerStrategy->id);
        $this->assertSame((int) $lender->id, (int) $loan->lenderStrategy->id);
        $this->assertSame((int) $request->id, (int) $loan->capitalRequest->id);
        $this->assertEqualsWithDelta(15000.0, (float) $loan->principal, 0.0001);
        $this->assertEqualsWithDelta(15000.0, (float) $loan->outstanding, 0.0001);
        $this->assertSame($committedAt->format('Y-m-d H:i:s'), $loan->committed_at->format('Y-m-d H:i:s'));
        $this->assertNull($loan->min_recall_at);
        $this->assertTrue($request->loan()->whereKey($loan->id)->exists());
        $this->assertTrue($borrower->loansAsBorrower()->whereKey($loan->id)->exists());
        $this->assertTrue($lender->loansAsLender()->whereKey($loan->id)->exists());
        $this->assertSame((int) $user->id, (int) $request->approvedByUser->id);
    }

    public function test_lender_cannot_be_the_same_strategy_as_borrower_on_request(): void
    {
        [$profile, $borrower, , $rec] = $this->twoStrategiesAndRecommendation();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lender strategy must differ from borrower strategy.');

        CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $borrower->id,
            'recommendation_id' => $rec->id,
            'amount' => 5000,
            'status' => CapitalRequest::STATUS_AWAITING_APPROVAL,
        ]);
    }

    public function test_lender_cannot_be_the_same_strategy_as_borrower_on_loan(): void
    {
        [$profile, $borrower, $lender, $rec] = $this->twoStrategiesAndRecommendation();

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 5000,
            'status' => CapitalRequest::STATUS_AWAITING_APPROVAL,
        ]);

        $this->expectException(InvalidArgumentException::class);

        CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $borrower->id,
            'principal' => 5000,
            'outstanding' => 5000,
            'committed_at' => now(),
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);
    }

    public function test_one_capital_request_cannot_have_two_loans(): void
    {
        [$profile, $borrower, $lender, $rec] = $this->twoStrategiesAndRecommendation();

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 10000,
            'status' => CapitalRequest::STATUS_COMMITTED,
        ]);

        CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => 10000,
            'outstanding' => 10000,
            'committed_at' => now(),
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);

        $this->expectException(QueryException::class);

        CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => 5000,
            'outstanding' => 5000,
            'committed_at' => now(),
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);
    }

    public function test_loan_return_references_loan_and_amount(): void
    {
        [$profile, $borrower, $lender, $rec] = $this->twoStrategiesAndRecommendation();
        $returnedAt = now()->subHour();

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 15000,
            'status' => CapitalRequest::STATUS_COMMITTED,
        ]);

        $loan = CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => 15000,
            'outstanding' => 10000,
            'committed_at' => now()->subDays(20),
            'status' => CapitalLoan::STATUS_PARTIALLY_RETURNED,
        ]);

        $return = CapitalLoanReturn::query()->create([
            'loan_id' => $loan->id,
            'capital_request_id' => $request->id,
            'amount' => 5000,
            'returned_at' => $returnedAt,
            'created_at' => now(),
        ]);

        $this->assertSame((int) $loan->id, (int) $return->loan->id);
        $this->assertSame((int) $request->id, (int) $return->capitalRequest->id);
        $this->assertEqualsWithDelta(5000.0, (float) $return->amount, 0.0001);
        $this->assertSame($returnedAt->format('Y-m-d H:i:s'), $return->returned_at->format('Y-m-d H:i:s'));
        $this->assertTrue($loan->returns()->whereKey($return->id)->exists());
        $this->assertTrue($request->returns()->whereKey($return->id)->exists());
    }

    public function test_existing_cash_pool_is_unchanged_by_persisting_loan_rows(): void
    {
        [$profile, $borrower, $lender, $rec] = $this->twoStrategiesAndRecommendation();

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'recommendation_id' => $rec->id,
            'amount' => 5000,
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);

        CapitalLoan::query()->create([
            'profile_id' => $profile->id,
            'capital_request_id' => $request->id,
            'borrower_strategy_id' => $borrower->id,
            'lender_strategy_id' => $lender->id,
            'principal' => 5000,
            'outstanding' => 5000,
            'committed_at' => now(),
            'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);

        $this->assertSame(0, CashAccount::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(0, CashLedgerEntry::query()->where('profile_id', $profile->id)->count());
        $this->assertFalse(Schema::hasColumn('portfolio_cash_accounts', 'strategy_id'));
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->fresh()->status);
    }

    /**
     * @return array{0: PortfolioProfile, 1: TradingStrategy, 2: TradingStrategy, 3: TradingRecommendation, 4?: User}
     */
    protected function twoStrategiesAndRecommendation(bool $withUser = false): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $borrower = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;
        $lender = $this->makeStrategy($profile, 'Lender Strategy');
        $stock = Stock::query()->create([
            'symbol' => 'LN'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Lending Stock',
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

        $out = [$profile, $borrower->fresh(['activeVersion']), $lender, $rec];
        if ($withUser) {
            $out[] = $user;
        }

        return $out;
    }

    protected function makeStrategy(PortfolioProfile $profile, string $name): TradingStrategy
    {
        $strategy = TradingStrategy::query()->create([
            'profile_id' => $profile->id,
            'name' => $name,
            'slug' => Str::slug($name).'_'.Str::lower(Str::random(4)),
            'status' => TradingStrategy::STATUS_ACTIVE,
            'allocation_pct' => 0,
            'is_factory' => false,
        ]);
        $version = TradingStrategyVersion::query()->create([
            'strategy_id' => $strategy->id,
            'version' => 1,
            'version_label' => '1.0',
            'config_json' => ['indicators' => []],
            'status' => TradingStrategyVersion::STATUS_ACTIVE,
        ]);
        $strategy->forceFill(['active_version_id' => $version->id])->save();

        return $strategy->fresh(['activeVersion']);
    }
}
