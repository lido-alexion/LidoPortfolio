<?php

namespace Tests\Feature\Lending;

use App\Engines\Notification\NotificationEngine;
use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\PendingSaleProceeds;
use App\Models\PortfolioProfile;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\TosNotification;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\Lending\CapitalResolutionService;
use App\Services\Lending\ProceedsApplicationService;
use App\Services\Lending\RecallBridgeLoanService;
use App\Services\Lending\RecallImmediateSettlementService;
use App\Services\Lending\RecallService;
use App\Services\Lending\SaleProceedsAvailabilityService;
use App\Services\ProfileSettingsService;
use App\Services\Strategy\StrategyRegistrySupport;
use App\Services\StrategyConfigurationService;
use App\Services\TelegramNotificationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RecallNotificationTest extends TestCase
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

        $telegram = Mockery::mock(TelegramNotificationService::class);
        $telegram->shouldReceive('sendMessageForProfile')->andReturn(true)->byDefault();
        $this->app->instance(TelegramNotificationService::class, $telegram);
    }

    public function test_recall_requested_pending_settlement_and_completed_notifications(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->enableTelegram($profile);
        $loan = $this->createLoan($profile, $lender, $borrower, 20_000, now()->subDays(20));

        $recall = app(RecallService::class)->requestFull($profile, $loan);
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_requested')->count());
        $msg = TosNotification::query()->where('notification_type', 'recall_requested')->first()->payload['message'];
        $this->assertStringContainsString('Recall requested', $msg);
        $this->assertStringNotContainsString('Soft Loan', $msg);

        // Duplicate request path: same idempotency key must not create a second row
        app(NotificationEngine::class)->notifyDomain(
            $profile,
            'recall_requested',
            'recall-'.$recall->id.'-requested-telegram',
            'dup',
        );
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_requested')->count());

        app(RecallImmediateSettlementService::class)->apply($profile, $recall, 0, 0, null);
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_pending_held')->count());
        $pendingMsg = TosNotification::query()->where('notification_type', 'recall_pending_held')->value('payload')['message'];
        $this->assertStringContainsString('Funds are being arranged', $pendingMsg);
        $this->assertStringContainsString('Proceeds from Stock Sale', $pendingMsg);

        $psp = app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
            $profile,
            $borrower,
            20_000,
            20_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            now()->subDays(2),
            $recall->id,
        );
        app(ProceedsApplicationService::class)->applyRow($psp, now());
        $this->assertGreaterThanOrEqual(1, TosNotification::query()->whereIn('notification_type', [
            'recall_settlement',
            'recall_completed',
            'sale_proceeds_applied',
        ])->count());
        $this->assertSame(CapitalRecall::STATE_COMPLETED, $recall->fresh()->state);
        $this->assertTrue(
            TosNotification::query()->where('notification_type', 'recall_completed')->exists()
            || TosNotification::query()->where('notification_type', 'recall_settlement')->exists()
        );
    }

    public function test_bridge_create_partial_and_complete_notifications(): void
    {
        [$user, $profile, $borrower, $lender, $bridgeLender] = $this->threeStrategyPortfolio(1_000_000);
        $this->enableTelegram($profile);
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

        $created = TosNotification::query()->where('notification_type', 'recall_bridge_created')->first();
        $this->assertNotNull($created);
        $this->assertStringContainsString('Recall Bridge Loan', $created->payload['message']);
        $this->assertStringNotContainsString('Soft Loan', $created->payload['message']);

        app(RecallBridgeLoanService::class)->repay($bridge, 3_000);
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_bridge_partial_repay')->count());

        app(RecallBridgeLoanService::class)->repay($bridge->fresh(), 2_000);
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_bridge_completed')->count());
        $this->assertSame(RecallBridgeLoan::STATUS_RETURNED, $bridge->fresh()->status);

        // Idempotent re-notify of same completion key
        app(NotificationEngine::class)->notifyDomain(
            $profile,
            'recall_bridge_completed',
            'bridge-'.$bridge->id.'-repay-5000.0000-telegram',
            'dup',
        );
        $this->assertSame(1, TosNotification::query()->where('notification_type', 'recall_bridge_completed')->count());
    }

    public function test_proceeds_and_partial_capital_resolution_terminology(): void
    {
        [$user, $profile, $borrower, $lender] = $this->twoStrategyPortfolio(1_000_000);
        $this->enableTelegram($profile);
        $loan = $this->createLoan($profile, $lender, $borrower, 4_000, now()->subDays(20));

        $result = app(CapitalResolutionService::class)->resolveForStrategy($profile, $lender, 20_000, [
            'own_available_override' => 15_000,
            'borrower_own_cash_overrides' => [(int) $borrower->id => 4_000],
            'liquidatable_stock_overrides' => [(int) $borrower->id => 0],
        ]);
        $this->assertEqualsWithDelta(19_000.0, $result['actual_available'], 0.0001);
        $partial = TosNotification::query()->where('notification_type', 'capital_resolution_partial')->first();
        $this->assertNotNull($partial);
        $this->assertStringContainsString('Actual execution amount', $partial->payload['message']);
        $this->assertStringContainsString('19,000', $partial->payload['message']);
        $this->assertStringNotContainsString('Return on Stock Sale', $partial->payload['message']);

        // Separate recall still outstanding for proceeds-application notification
        CapitalRecall::query()->where('profile_id', $profile->id)->update([
            'state' => CapitalRecall::STATE_COMPLETED,
            'outstanding_recall_amount' => 0,
            'completed_at' => now()->subDays(10),
        ]);
        $loan2 = $this->createLoan($profile, $lender, $borrower, 10_000, now()->subDays(20));
        $recall2 = app(RecallService::class)->requestFull($profile, $loan2);
        app(RecallImmediateSettlementService::class)->apply($profile, $recall2, 0, 0, null);

        $psp = app(SaleProceedsAvailabilityService::class)->scheduleForObligation(
            $profile,
            $borrower,
            2_500,
            3_000,
            PendingSaleProceeds::OBLIGATION_RECALL,
            now()->subDays(2),
            $recall2->id,
        );
        app(ProceedsApplicationService::class)->applyRow($psp->fresh(), now());
        $applied = TosNotification::query()->where('notification_type', 'sale_proceeds_applied')->first();
        $this->assertNotNull($applied);
        $this->assertStringContainsString('Proceeds from Stock Sale', $applied->payload['message']);
        $this->assertStringContainsString('2,500', $applied->payload['message']);
    }

    private function enableTelegram(PortfolioProfile $profile): void
    {
        $settings = app(ProfileSettingsService::class);
        $settings->set($profile, 'notifications_enabled', 'true');
        $settings->set($profile, 'telegram_bot_token', 'test-token');
        $settings->set($profile, 'telegram_chat_id', '12345');
    }

    /**
     * @return array{0: User, 1: PortfolioProfile, 2: TradingStrategy, 3: TradingStrategy}
     */
    private function twoStrategyPortfolio(float $cash): array
    {
        $user = User::factory()->create();
        $profile = $this->createPortfolioProfile($user, 'Notify', true);
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
            'symbol' => 'RN'.strtoupper(Str::random(3)),
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
