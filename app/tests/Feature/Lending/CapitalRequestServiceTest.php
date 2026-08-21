<?php

namespace Tests\Feature\Lending;

use App\Models\CapitalRequest;
use App\Models\PortfolioProfile;
use App\Models\Stock;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalRequestService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CapitalRequestServiceTest extends TestCase
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

    public function test_creates_displayed_request_without_selecting_a_lender(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);

        $request = app(CapitalRequestService::class)->createRequest($profile, $rec, $borrower, 15_000);

        $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->status);
        $this->assertNull($request->lender_strategy_id);
        $this->assertSame((int) $borrower->id, (int) $request->borrower_strategy_id);
        $this->assertSame((int) $rec->id, (int) $request->recommendation_id);
        $this->assertEqualsWithDelta(15_000.0, (float) $request->amount, 0.0001);
        $this->assertNotSame((int) $lender->id, (int) $request->borrower_strategy_id);
        $this->assertSame($user->id, $user->id);
    }

    public function test_wrong_profile_recommendation_is_rejected(): void
    {
        $user = User::factory()->create();
        $a = $this->createPortfolioProfile($user, 'A', true);
        $b = $this->createPortfolioProfile($user, 'B', false);
        $borrower = app(StrategyConfigurationService::class)->ensureActive($a)->strategy;
        $other = app(StrategyConfigurationService::class)->ensureActive($b)->strategy;
        $rec = $this->makeRecommendation($b, $other);

        $this->expectException(ValidationException::class);
        app(CapitalRequestService::class)->createRequest($a, $rec, $borrower, 5_000);
    }

    public function test_wrong_profile_borrower_is_rejected(): void
    {
        $user = User::factory()->create();
        $a = $this->createPortfolioProfile($user, 'A', true);
        $b = $this->createPortfolioProfile($user, 'B', false);
        $borrowerA = app(StrategyConfigurationService::class)->ensureActive($a)->strategy;
        $borrowerB = app(StrategyConfigurationService::class)->ensureActive($b)->strategy;
        $rec = $this->makeRecommendation($a, $borrowerA);

        $this->expectException(ValidationException::class);
        app(CapitalRequestService::class)->createRequest($a, $rec, $borrowerB, 5_000);
    }

    public function test_borrower_must_own_the_recommendation(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);

        $this->expectException(ValidationException::class);
        app(CapitalRequestService::class)->createRequest($profile, $rec, $lender, 5_000);
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        [, $profile, $borrower] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $service = app(CapitalRequestService::class);

        foreach ([0, -1, 3000, 4999, 5001, 7500] as $amount) {
            try {
                $service->createRequest($profile, $rec, $borrower, (float) $amount);
                $this->fail('Expected validation failure for amount '.$amount);
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_eligible_lenders_does_not_assign_a_lender(): void
    {
        [, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $rec = $this->makeRecommendation($profile, $borrower);
        $service = app(CapitalRequestService::class);
        $request = $service->createRequest($profile, $rec, $borrower, 5_000);
        $lenders = $service->eligibleLenders($request);

        $this->assertNull($request->fresh()->lender_strategy_id);
        $this->assertSame(CapitalRequest::STATUS_DISPLAYED, $request->fresh()->status);
        $this->assertSame((int) $lender->id, $lenders[0]['strategy_id']);
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
            'symbol' => 'CR'.strtoupper(Str::random(3)),
            'exchange' => 'NSE',
            'name' => 'Request Stock',
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
