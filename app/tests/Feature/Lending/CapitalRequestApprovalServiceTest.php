<?php

namespace Tests\Feature\Lending;

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
use App\Services\Lending\CapitalRequestService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CapitalRequestApprovalServiceTest extends TestCase
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

    public function test_successful_approval_creates_exactly_one_loan(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $recStatus = TradingRecommendation::STATUS_PENDING_REVIEW;
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 50_000);
        $ledgerBefore = CashLedgerEntry::query()->where('profile_id', $profile->id)->count();
        $holdingsBefore = Holding::query()->where('profile_id', $profile->id)->count();
        $cashBefore = app(CashManagementService::class)->balance($profile);

        $loan = app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        $this->assertSame(1, CapitalLoan::query()->where('capital_request_id', $request->id)->count());
        $this->assertEqualsWithDelta(50_000.0, (float) $loan->principal, 0.0001);
        $this->assertEqualsWithDelta(50_000.0, (float) $loan->outstanding, 0.0001);
        $this->assertNotNull($loan->committed_at);
        $this->assertSame(CapitalLoan::STATUS_OUTSTANDING, $loan->status);
        $this->assertSame((int) $borrower->id, (int) $loan->borrower_strategy_id);
        $this->assertSame((int) $lender->id, (int) $loan->lender_strategy_id);

        $request->refresh();
        $this->assertSame(CapitalRequest::STATUS_COMMITTED, $request->status);
        $this->assertSame((int) $lender->id, (int) $request->lender_strategy_id);
        $this->assertSame((int) $user->id, (int) $request->approved_by);
        $this->assertNotNull($request->approved_at);
        $this->assertSame($recStatus, $rec->fresh()->status);
        $this->assertEqualsWithDelta($cashBefore, app(CashManagementService::class)->balance($profile), 0.0001);
        $this->assertSame(
            $ledgerBefore + 2,
            CashLedgerEntry::query()->where('profile_id', $profile->id)->count()
        );
        $this->assertSame($holdingsBefore, Holding::query()->where('profile_id', $profile->id)->count());
        $this->assertEqualsCanonicalizing(
            ['deposit', 'loan'],
            CashLedgerEntry::query()->where('profile_id', $profile->id)->pluck('entry_type')->unique()->values()->all()
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->where('entry_type', CashLedgerEntry::TYPE_LOAN)
                ->sum('amount'),
            0.0001
        );
        $this->assertSame(
            0,
            CashLedgerEntry::query()
                ->where('profile_id', $profile->id)
                ->whereIn('entry_type', [CashLedgerEntry::TYPE_BUY, CashLedgerEntry::TYPE_SELL])
                ->count()
        );
    }

    public function test_api_approve_and_list_lenders(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/requests/'.$request->id.'/lenders')
            ->assertOk()
            ->assertJsonPath('data.lenders.0.strategy_id', $lender->id);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/requests/'.$request->id.'/approve', [
                'lender_strategy_id' => $lender->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.capital_request.status', CapitalRequest::STATUS_COMMITTED)
            ->assertJsonPath('data.loan.lender_strategy_id', $lender->id);
    }

    public function test_insufficient_availability_at_approval_fails_atomically(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 50_000);
        $lender->forceFill(['status' => TradingStrategy::STATUS_ARCHIVED])->save();

        try {
            app(CapitalRequestApprovalService::class)->approve($request, $lender->fresh(), $user);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame(0, CapitalLoan::query()->count());
            $this->assertNotSame(CapitalRequest::STATUS_COMMITTED, $request->fresh()->status);
            $this->assertNull($request->fresh()->lender_strategy_id);
            $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->fresh()->status);
        }
    }

    public function test_stale_lender_fails_and_sets_revalidation_failed(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(12_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 50_000);

        try {
            app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame(0, CapitalLoan::query()->count());
            $this->assertSame(CapitalRequest::STATUS_REVALIDATION_FAILED, $request->fresh()->status);
            $this->assertNull($request->fresh()->lender_strategy_id);
        }
    }

    public function test_duplicate_approval_cannot_create_second_loan(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);
        app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        try {
            app(CapitalRequestApprovalService::class)->approve($request->fresh(), $lender, $user);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame(1, CapitalLoan::query()->where('capital_request_id', $request->id)->count());
        }
    }

    public function test_wrong_profile_lender_is_rejected(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $otherProfile = $this->createPortfolioProfile($user, 'Other', false);
        $otherLender = app(StrategyConfigurationService::class)->ensureActive($otherProfile)->strategy;
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);

        try {
            app(CapitalRequestApprovalService::class)->approve($request, $otherLender, $user);
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertSame(0, CapitalLoan::query()->count());
            $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->fresh()->status);
        }
    }

    public function test_borrower_cannot_be_approved_as_lender(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);

        $this->expectException(ValidationException::class);
        app(CapitalRequestApprovalService::class)->approve($request, $borrower, $user);
    }

    public function test_rejection_creates_no_loan_and_records_user(): void
    {
        [$user, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);

        $updated = app(CapitalRequestApprovalService::class)->reject($request, $user);

        $this->assertSame(CapitalRequest::STATUS_REJECTED, $updated->status);
        $this->assertSame((int) $user->id, (int) $updated->approved_by);
        $this->assertSame(0, CapitalLoan::query()->count());
        $this->assertSame(TradingRecommendation::STATUS_PENDING_REVIEW, $rec->fresh()->status);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/requests/'.$request->id.'/reject')
            ->assertStatus(422);
    }

    public function test_committed_request_cannot_be_rejected_or_approved_again(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 5_000);
        app(CapitalRequestApprovalService::class)->approve($request, $lender, $user);

        try {
            app(CapitalRequestApprovalService::class)->reject($request->fresh(), $user);
            $this->fail('Expected reject to fail');
        } catch (ValidationException) {
            $this->assertSame(1, CapitalLoan::query()->count());
        }

        try {
            app(CapitalRequestApprovalService::class)->approve($request->fresh(), $lender, $user);
            $this->fail('Expected second approve to fail');
        } catch (ValidationException) {
            $this->assertSame(1, CapitalLoan::query()->count());
        }
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

    private function makeRecommendation($profile, TradingStrategy $borrower): TradingRecommendation
    {
        $stock = Stock::query()->create([
            'symbol' => 'AP'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Approve Stock',
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
