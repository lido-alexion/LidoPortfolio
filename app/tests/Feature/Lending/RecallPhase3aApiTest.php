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
use App\Services\Lending\RecallBridgeLoanService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallPeriodResolver;
use App\Services\Lending\RecallService;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecallPhase3aApiTest extends TestCase
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

    public function test_create_full_and_partial_recall_via_api(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 50_000, now()->subDays(20));

        $full = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ]);
        $full->assertCreated();
        $full->assertJsonPath('data.kind', 'full');
        $full->assertJsonPath('data.recall_amount', 50000);
        // Gap-closure: POST runs settlement workflow — must not remain stuck at requested.
        $this->assertNotSame(CapitalRecall::STATE_REQUESTED, $full->json('data.state'));
        $this->assertContains($full->json('data.state'), [
            CapitalRecall::STATE_COMPLETED,
            CapitalRecall::STATE_PENDING_HELD,
            CapitalRecall::STATE_LIQUIDATION,
            CapitalRecall::STATE_SETTLEMENT,
            CapitalRecall::STATE_IMMEDIATE_SETTLEMENT,
        ]);

        // complete to allow another
        CapitalRecall::query()->whereKey($full->json('data.id'))->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'outstanding_recall_amount' => 0,
            'completed_at' => now()->subDays(10),
        ]);
        app(RecallPeriodResolver::class)->setPortfolioOverride($profile, 14);

        $loan2 = $this->createLoan($profile, $lender, $borrower, 25_000, now()->subDays(20));
        $partial = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan2->id,
                'kind' => 'partial',
                'amount' => 10_000,
                'lender_strategy_id' => $lender->id,
            ]);
        $partial->assertCreated();
        $partial->assertJsonPath('data.kind', 'partial');
        $partial->assertJsonPath('data.recall_amount', 10000);
    }

    public function test_invalid_partial_and_over_outstanding_rejected(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 12_000, now()->subDays(20));

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan->id,
                'kind' => 'partial',
                'amount' => 7_000,
                'lender_strategy_id' => $lender->id,
            ])
            ->assertStatus(422);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan->id,
                'kind' => 'partial',
                'amount' => 15_000,
                'lender_strategy_id' => $lender->id,
            ])
            ->assertStatus(422);
    }

    public function test_eligibility_active_and_follow_up_cooldown(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(5));

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ])
            ->assertStatus(422);

        $loanOk = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $first = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loanOk->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ]);
        $first->assertCreated();

        $loanB = $this->createLoan($profile, $lender, $borrower, 15_000, now()->subDays(20));
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loanB->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ])
            ->assertStatus(422);

        CapitalRecall::query()->whereKey($first->json('data.id'))->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'outstanding_recall_amount' => 0,
            'completed_at' => now()->subDays(1),
        ]);
        // before cooldown (floor(14/2)=7)
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loanB->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ])
            ->assertStatus(422);

        CapitalRecall::query()->whereKey($first->json('data.id'))->update([
            'completed_at' => now()->subDays(8),
        ]);
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loanB->id,
                'kind' => 'full',
                'lender_strategy_id' => $lender->id,
            ])
            ->assertCreated();
    }

    public function test_borrower_cannot_request_as_lender_and_bridge_create_forbidden(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/recalls', [
                'loan_id' => $loan->id,
                'kind' => 'full',
                'lender_strategy_id' => $borrower->id,
            ])
            ->assertStatus(403);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/bridge-loans', [
                'principal' => 5000,
            ])
            ->assertStatus(405);
    }

    public function test_unauthorized_user_cannot_access_other_portfolio_recalls(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);

        $other = User::factory()->create();
        $otherProfile = $this->createPortfolioProfile($other, 'Other', true);

        $this->actingAs($other)->withProfileHeader($other, $otherProfile)
            ->getJson('/api/v1/capital/recalls/'.$recall->id)
            ->assertStatus(404);
    }

    public function test_list_and_detail_states_and_period_config(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);
        $this->assertContains($recall->fresh()->state, [
            CapitalRecall::STATE_PENDING_HELD,
            CapitalRecall::STATE_LIQUIDATION,
        ]);

        $state = $recall->fresh()->state;
        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/recalls?state='.$state)
            ->assertOk()
            ->assertJsonPath('data.0.state', $state);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/recalls/'.$recall->id)
            ->assertOk()
            ->assertJsonPath('data.id', $recall->id)
            ->assertJsonStructure(['data' => [
                'recall_amount', 'settled_amount', 'outstanding_recall_amount',
                'bridge_loans', 'liquidation', 'pending_sale_proceeds',
            ]]);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/recall-period')
            ->assertOk()
            ->assertJsonPath('data.effective_period_days', 14)
            ->assertJsonPath('data.min_recall_at_is_authoritative', false);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->putJson('/api/v1/capital/recall-period', ['portfolio_recall_period_days' => 7])
            ->assertOk()
            ->assertJsonPath('data.effective_period_days', 7);
    }

    public function test_bridge_and_proceeds_read_apis_and_mark_available_forbidden(): void
    {
        [$user, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));
        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $bridge = app(RecallBridgeLoanService::class)->create(
            $profile,
            $recall,
            $bridgeLender,
            5_000,
            [
                'borrower_own_cash' => 10_000.0,
                'liquidatable_stock_value' => 100_000.0,
                'lender_available_override' => 100_000.0,
            ],
        );
        app(RecallBridgeLoanService::class)->repay($bridge, 2_000);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/bridge-loans/'.$bridge->id)
            ->assertOk()
            ->assertJsonPath('data.principal', 5000)
            ->assertJsonPath('data.outstanding', 3000)
            ->assertJsonPath('data.label', 'Recall Bridge Loan');

        $psp = app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
            $profile,
            $borrower,
            4_700,
            5_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            now(),
            $recall->id,
        );

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/capital/pending-sale-proceeds/'.$psp->id)
            ->assertOk()
            ->assertJsonPath('data.label', 'Proceeds from Stock Sale')
            ->assertJsonPath('data.actual_proceeds_amount', 4700)
            ->assertJsonPath('data.expected_amount', 5000);

        $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/pending-sale-proceeds/'.$psp->id.'/mark-available')
            ->assertStatus(405);
    }

    public function test_capital_resolution_api_reports_actual_amount(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(2_000_000);
        $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $stock = Stock::query()->create([
            'symbol' => 'CR'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'CR',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        $rec = TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $lender->active_version_id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => TradingRecommendation::STATUS_PENDING_REVIEW,
            'priority' => 1,
            'strategy_score' => 80,
            'confidence' => 0.8,
            'risk_level' => 'medium',
            'generated_at' => now(),
            'suggested_allocation_amount' => 20_000,
            'evidence' => [
                'capital_allocation' => [
                    'target_amount' => 20_000,
                    'allocated_amount' => 15_000,
                    'status' => 'partial',
                ],
            ],
        ]);

        // Persist the v0.28 example outcome for UI contract (own 15k + recall 4k → execute 19k).
        app(\App\Services\Lending\CapitalResolutionStatusService::class)->attachSnapshot($rec, [
            'required_amount' => 20_000,
            'own_available' => 15_000,
            'own_used' => 15_000,
            'recalled_amount' => 4_000,
            'borrow_shortfall' => 1_000,
            'actual_available' => 19_000,
            'recalls' => [],
        ]);

        $status = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->getJson('/api/v1/recommendations/'.$rec->id.'/capital-resolution');
        $status->assertOk();
        $status->assertJsonPath('data.requested_investment_amount', 20000);
        $status->assertJsonPath('data.own_capital_used', 15000);
        $status->assertJsonPath('data.recalled_capital_received', 4000);
        $status->assertJsonPath('data.actual_execution_amount', 19000);
        $status->assertJsonPath('data.unresolved_amount', 1000);
        $this->assertTrue((bool) $status->json('data.close_at_actual'));
        $this->assertFalse((bool) $status->json('data.hold_for_remainder'));

        $resolve = $this->actingAs($user)->withProfileHeader($user, $profile)
            ->postJson('/api/v1/capital/resolve', [
                'strategy_id' => $lender->id,
                'required_amount' => 20_000,
                'recommendation_id' => $rec->id,
            ]);
        $resolve->assertOk();
        $this->assertTrue((bool) $resolve->json('data.resolution.close_at_actual'));
        $this->assertLessThanOrEqual(
            20_000.0,
            (float) $resolve->json('data.resolution.actual_available')
        );
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'R3A', true);
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
            'symbol' => 'RA'.strtoupper(Str::random(3)),
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
